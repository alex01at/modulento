<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use Closure;
use Modulento\Core\App;

/**
 * Every route declares who may call it, and dispatch() enforces that
 * together with the CSRF check BEFORE the handler runs. A controller never
 * has to remember a guard of its own, and forgetting the access argument
 * yields a login-only route rather than a public one.
 */
final class Router
{
    /** Anyone, including anonymous visitors. Must be requested explicitly. */
    public const PUBLIC = 'public';
    /** Any logged-in account. The default. */
    public const AUTH = 'auth';

    /** @var array<int, array{method: string, regex: string, handler: array|Closure, access: string, csrfExempt: bool}> */
    private array $routes = [];

    public function __construct(private App $app)
    {
    }

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param string $access Router::PUBLIC, Router::AUTH or a permission name
     */
    public function get(string $path, array|Closure $handler, string $access = self::AUTH): void
    {
        $this->add('GET', $path, $handler, $access, false);
    }

    /**
     * $csrfExempt should only ever be true for a non-browser endpoint that
     * can never carry a session-bound token (an external webhook that is
     * authenticated its own way).
     *
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param string $access Router::PUBLIC, Router::AUTH or a permission name
     */
    public function post(string $path, array|Closure $handler, string $access = self::AUTH, bool $csrfExempt = false): void
    {
        $this->add('POST', $path, $handler, $access, $csrfExempt);
    }

    private function add(string $method, string $path, array|Closure $handler, string $access, bool $csrfExempt): void
    {
        // {name} matches one path segment, {name*} the rest of the path.
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\*\}#', '(?P<$1>.+)', $path);
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $regex);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'access' => $access,
            'csrfExempt' => $csrfExempt,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        $path = rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/');
        if ($path === '') {
            $path = '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method || !preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            if ($method === 'POST' && !$route['csrfExempt']
                && !Csrf::verify($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
                $this->rejectCsrf();
                return;
            }

            if (!$this->allowed($route['access'], $method)) {
                return;
            }

            $params = array_filter($matches, fn ($key) => is_string($key), ARRAY_FILTER_USE_KEY);
            $handler = $route['handler'];

            if ($handler instanceof Closure) {
                $handler($params, $this->app);
            } else {
                [$class, $action] = $handler;
                (new $class($this->app))->$action($params);
            }
            return;
        }

        $this->fail(404, 'core.error.not_found');
    }

    private function allowed(string $access, string $method): bool
    {
        if ($access === self::PUBLIC) {
            return true;
        }

        $auth = $this->app->auth;

        if (!$auth->check()) {
            if ($method === 'GET' && !$this->isAjax()) {
                // Without the language prefix: after logging in, the
                // account's own language decides the address.
                $query = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
                Session::set('login_return_to', $this->app->path . ($query ? '?' . $query : ''));
                header('Location: ' . $this->app->url('/login'));
                return false;
            }
            $this->fail(403, 'core.error.forbidden');
            return false;
        }

        if ($access !== self::AUTH && !$auth->can($access)) {
            $this->fail(403, 'core.error.forbidden');
            return false;
        }

        return true;
    }

    /**
     * An expired or missing token is routinely a legitimate case (a session
     * that ended in another tab) rather than an attack, so a normal form
     * gets a redirect with a message, while an AJAX request gets a bare 403
     * since there is no page to go back to.
     */
    private function rejectCsrf(): void
    {
        // A request larger than the server's post_max_size arrives with no
        // fields at all, so the token is missing too. Saying "security
        // check failed" would send people looking in the wrong place.
        $tooLarge = $_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
            && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data');
        $messageKey = $tooLarge ? 'core.error.upload_too_large' : 'core.error.csrf';

        if ($this->isAjax()) {
            $this->fail($tooLarge ? 413 : 403, $messageKey);
            return;
        }

        Session::flash('error', $this->app->translator->trans($messageKey));
        header('Location: ' . $this->sameOriginReferer());
    }

    private function sameOriginReferer(): string
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $host = parse_url($referer, PHP_URL_HOST);

        if ($host === null || $host !== ($_SERVER['SERVER_NAME'] ?? null)) {
            return '/';
        }

        $query = parse_url($referer, PHP_URL_QUERY);

        return (parse_url($referer, PHP_URL_PATH) ?: '/') . ($query ? '?' . $query : '');
    }

    private function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    private function fail(int $status, string $messageKey): void
    {
        http_response_code($status);

        if ($this->isAjax()) {
            echo $status . ' - ' . $this->app->translator->trans($messageKey);
            return;
        }

        echo $this->app->view()->render('error.twig', ['status' => $status, 'message_key' => $messageKey]);
    }
}
