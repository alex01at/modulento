<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

/**
 * The scheduler for hosts without shell access: where the hosting panel
 * cannot run bin/cron.php, it (or an external cron service) calls
 * /cron/<token> instead. The token is created by the installer; calling
 * the URL more often than needed is harmless, since the scheduler runs
 * each task only when its interval has elapsed and never twice at once.
 */
final class CronController extends Controller
{
    public function run(array $params): void
    {
        $token = $this->app->config['app']['cron_token'];

        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');

        if ($token === '' || !hash_equals($token, $params['token'])) {
            http_response_code(404);
            echo '404';
            return;
        }

        @set_time_limit(0);
        ignore_user_abort(true);

        $failed = array_filter($this->app->scheduler->runDue($this->app), fn (string $result) => $result !== 'ok');

        // Task names and errors stay in the log and on the admin page.
        if ($failed !== []) {
            http_response_code(500);
        }
        echo $failed === [] ? 'ok' : 'failed';
    }
}
