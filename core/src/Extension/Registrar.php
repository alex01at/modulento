<?php

declare(strict_types=1);

namespace Modulento\Core\Extension;

use Closure;
use LogicException;
use Modulento\Core\App;
use Modulento\Core\Catalogue\OfferType;
use Modulento\Core\Order\OrderFlow;
use Modulento\Core\Order\PaymentMethod;
use Modulento\Core\Support\Router;

/**
 * What an extension can announce to the core. Templates (templates/),
 * language files (lang/) and migrations (migrations/) are picked up from
 * the extension folder by convention and need no call here.
 */
final class Registrar
{
    public function __construct(private App $app, public readonly Manifest $manifest)
    {
    }

    /**
     * @param Closure(Router): void $define receives the router; every route
     *        states its access level (Router::PUBLIC, Router::AUTH or a
     *        permission name), login-only if omitted
     */
    public function routes(Closure $define): void
    {
        $define($this->app->router);
    }

    /** @param Closure(App): void $handler run by bin/cron.php, never by a page view */
    public function task(string $name, int $everyMinutes, Closure $handler): void
    {
        $this->app->scheduler->register($this->prefixed($name, 'Task'), $everyMinutes, $handler);
    }

    /**
     * @param class-string $eventClass
     * @param Closure(object, App): void $listener
     */
    public function listen(string $eventClass, Closure $listener): void
    {
        $app = $this->app;
        $this->app->events->listen($eventClass, static fn (object $event) => $listener($event, $app));
    }

    public function permission(string $name, string $labelKey): void
    {
        $this->app->addPermission($this->prefixed($name, 'Permission'), $labelKey);
    }

    /** A kind of offer this extension adds to the catalogue. Its id starts with the extension id. */
    public function offerType(OfferType $type): void
    {
        $this->prefixed($type->id(), 'Offer type');
        $this->app->offers->registerType($type);
    }

    /** How offers of one of this extension's types are ordered and carried out. */
    public function orderFlow(OrderFlow $flow): void
    {
        $this->prefixed($flow->id(), 'Order flow');
        $this->app->orders->registerFlow($flow);
    }

    /** A way to pay for orders, e.g. a payment service. */
    public function paymentMethod(PaymentMethod $method): void
    {
        $this->prefixed($method->id(), 'Payment method');
        $this->app->orders->registerPaymentMethod($method);
    }

    /** An entry in the site's main menu, e.g. "Blog". The path is one of this extension's public routes. */
    public function navigation(string $labelKey, string $path): void
    {
        $this->app->addNavigation($labelKey, $path);
    }

    /**
     * A section on the home page, e.g. the latest posts. The template is
     * one of this extension's ("@id/home.twig"), gets no variables and
     * fetches what it shows itself; a theme may override it like any other.
     */
    public function homeSection(string $template): void
    {
        $this->app->addHomeSection($template);
    }

    public function adminMenu(string $labelKey, string $path, string $permission): void
    {
        $this->app->addAdminMenu($labelKey, $path, $permission);
    }

    private function prefixed(string $name, string $kind): string
    {
        if (!str_starts_with($name, $this->manifest->id . '.')) {
            throw new LogicException("{$kind} \"{$name}\" must start with \"{$this->manifest->id}.\"");
        }

        return $name;
    }
}
