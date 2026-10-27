<?php

declare(strict_types=1);

namespace Modulento\Core\Extension;

use Closure;
use LogicException;
use Modulento\Core\App;
use Modulento\Core\Catalogue\OfferType;
use Modulento\Core\Cookie\ConsentProvider;
use Modulento\Core\Order\OrderFlow;
use Modulento\Core\Order\PaymentMethod;
use Modulento\Core\Subscription\SubscriptionProvider;
use Modulento\Core\Support\Router;

/**
 * What an extension can announce to the core. Templates (templates/),
 * language files (lang/) and migrations (migrations/) are picked up from
 * the extension folder by convention and need no call here.
 */
final class Registrar
{
    public function __construct(public readonly App $app, public readonly Manifest $manifest)
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

    /**
     * How this offer type takes part in Administration → Demo data: given a
     * provider and, where one exists, a category, creates one demo offer
     * and returns its id (already published), or null if it could not.
     *
     * @param Closure(App, int, ?int): ?int $generate
     */
    public function demoOffers(string $offerTypeId, Closure $generate): void
    {
        $this->prefixed($offerTypeId, 'Offer type');
        $this->app->addDemoOfferGenerator($offerTypeId, $generate);
    }

    /** A feature the extension sells with a subscription plan; the name is a language key. */
    public function subscriptionFeature(string $key, string $labelKey): void
    {
        $this->app->subscriptions->declareFeature($key, $labelKey);
    }

    /**
     * How this extension answers every question the core (and other
     * extensions, like a feature sold through subscriptionFeature()) asks
     * Subscriptions - the other half of a billing addon (modulento-ext-abos).
     * Only one provider is ever registered; a second call replaces the first.
     */
    public function subscriptionProvider(SubscriptionProvider $provider): void
    {
        $this->app->subscriptions->registerProvider($provider);
    }

    /** @param Closure(array): void $handler every verified Stripe webhook notification, whether or not it is about a subscription */
    public function stripeWebhook(Closure $handler): void
    {
        $this->app->payments->onStripeEvent($handler);
    }

    /**
     * A template an extension wants shown on the administration's own
     * account detail page - e.g. a billing addon's plan-assignment form.
     * The template is one of this extension's ("@id/admin_account.twig");
     * a theme may override it like any other. $data gets the raw account
     * row and runs only when that page is shown.
     *
     * @param Closure(array<string, mixed>, App): array<string, mixed> $data
     */
    public function adminAccountSection(string $template, Closure $data): void
    {
        $this->app->addAdminAccountSection($template, $data);
    }

    /** An entry in the site's main menu, e.g. "Blog". The path is one of this extension's public routes. */
    public function navigation(string $labelKey, string $path): void
    {
        $this->app->addNavigation($labelKey, $path);
    }

    /** An entry in the site's footer, e.g. a link to "FAQ". The path is one of this extension's public routes. */
    public function footerLink(string $labelKey, string $path): void
    {
        $this->app->addFooterLink($labelKey, $path);
    }

    /**
     * A part of this extension's own configuration an administrator built
     * by hand (a page, a set of blocks, whatever this extension keeps) that
     * can be exported to a file and imported again - its own, or into
     * another installation. $export returns whatever is JSON-serialisable;
     * $import gets exactly that back and applies it, the same way saving
     * it by hand already does (reuse that method - it already cleans up
     * anything handed to it).
     *
     * @param Closure(App): mixed $export
     * @param Closure(mixed, App): void $import
     */
    public function exportable(string $key, string $labelKey, Closure $export, Closure $import): void
    {
        $this->app->addExportable($this->prefixed($key, 'Exportable'), $labelKey, $export, $import);
    }

    /**
     * An optional feature of this extension, switched off and on under
     * Administration → Modules exactly like a core module - call
     * $app->modules->enabled($id) in register() itself (to decide whether
     * to add this feature's routes/menu entries/listeners at all) and
     * wherever else it matters. Only ever listed while this extension is
     * active, since nothing calls this otherwise. The id starts with the
     * extension id; $labelKey needs both "<labelKey>.name" and
     * "<labelKey>.description" in this extension's own lang files.
     */
    public function module(string $id, string $labelKey): void
    {
        $this->app->modules->register($this->prefixed($id, 'Module'), $labelKey);
    }

    /** Whether a module - this extension's own, another's, or a core one - is currently switched on. */
    public function moduleEnabled(string $id): bool
    {
        return $this->app->modules->enabled($id);
    }

    /**
     * A section on the home page, e.g. the latest posts. The template is
     * one of this extension's ("@id/home.twig"); a theme may override it
     * like any other. $data supplies its variables and runs only when the
     * home page is shown.
     *
     * @param Closure(App): array<string, mixed>|null $data
     */
    public function homeSection(string $template, ?Closure $data = null): void
    {
        $this->app->addHomeSection($template, $data);
    }

    /**
     * A new kind of block an admin can add anywhere a page is built from
     * blocks (the home page, or any other page), next to the built-in ones
     * (hero, text, categories, ...). Shows up in the "+" menu between
     * blocks, with its own fields and its own template - the block editor
     * itself needs no change for it.
     *
     * @param string $id unique, must start with this extension's id ("fields.list")
     * @param string $labelKey shown in the "+" menu and as the block's own heading
     * @param string[] $textFields plain-text fields of the block (shown as simple inputs, like "heading" on the built-in types - no picture, count or background settings)
     * @param string $template renders the block, both on the real page and live in the admin editor; receives "block" (id, type, enabled, texts: array<locale, array<field, text>>) plus whatever $data returns
     * @param Closure(App, array): array<string, mixed>|null $data anything beyond the block's own texts (e.g. a live list fetched from the database); run fresh every time the block is shown, gets the block itself (same shape as "block" above)
     */
    public function pageBlockType(string $id, string $labelKey, array $textFields, string $template, ?Closure $data = null): void
    {
        $this->app->addPageBlockType($this->prefixed($id, 'Page block type'), $labelKey, $textFields, $template, $data);
    }

    /**
     * A section on a provider's public profile, e.g. a freelancer's skills
     * and portfolio. The template is one of this extension's
     * ("@id/provider.twig"); a theme may override it like any other. $data
     * gets the raw provider row and runs only when that provider's page is
     * shown; return an empty array to show nothing for a provider who has
     * not filled anything in.
     *
     * @param Closure(array<string, mixed>, App): array<string, mixed> $data
     */
    public function providerSection(string $template, Closure $data): void
    {
        $this->app->addProviderSection($template, $data);
    }

    /**
     * A template an extension wants shown on an offer's public page - e.g. a
     * bookmark star. The template is one of this extension's
     * ("@id/offer.twig"); a theme may override it like any other. $data
     * gets the raw offer row and runs only when that offer's page is shown.
     *
     * @param Closure(array<string, mixed>, App): array<string, mixed> $data
     */
    public function offerSection(string $template, Closure $data): void
    {
        $this->app->addOfferSection($template, $data);
    }

    /**
     * A section on the logged-in account's own dashboard (/account), e.g. a
     * digital card extension's status panel. The template is one of this
     * extension's ("@id/dashboard.twig"); a theme may override it like any
     * other. $data gets the account row and runs only when the dashboard is
     * shown; return an empty array to show nothing for an account that has
     * not used this extension's feature at all.
     *
     * @param Closure(array<string, mixed>, App): array<string, mixed> $data
     */
    public function dashboardSection(string $template, Closure $data): void
    {
        $this->app->addDashboardSection($template, $data);
    }

    /** An entry in the logged-in account's own navigation, e.g. a profile page the extension adds. */
    public function accountLink(string $labelKey, string $path): void
    {
        $this->app->addAccountLink($labelKey, $path);
    }

    /**
     * A template an extension wants shown on the order form, between what
     * the offer type itself asks for and the choice of payment method -
     * e.g. a coupon addon's code field. The template is one of this
     * extension's ("@id/checkout.twig"); a theme may override it like any
     * other. $data gets the offer and whatever was submitted before (so a
     * typed code survives a validation error).
     *
     * @param Closure(array<string, mixed>, array<string, mixed>, App): array<string, mixed> $data
     */
    public function checkoutSection(string $template, Closure $data): void
    {
        $this->app->addCheckoutSection($template, $data);
    }

    /**
     * A way to adjust an order's line items before they are totalled - e.g.
     * a coupon addon applying a discount code. Runs in registration order,
     * each getting the previous one's result; a line's unit price is
     * unsigned, so a discount must reduce it (prorated across lines) rather
     * than add a negative one - see Order\Orders::create().
     *
     * @param Closure(array<int, array{label: string, quantity: int, unit_price: int}>, array<string, mixed>, array<string, mixed>, App): array<int, array{label: string, quantity: int, unit_price: int}> $modifier items, offer, raw POST input, app => the adjusted items
     */
    public function orderDiscount(Closure $modifier): void
    {
        $this->app->addOrderDiscount($modifier);
    }

    /**
     * A category of cookie an extension may set once consent is given - e.g.
     * an anti-spam addon's external CAPTCHA. The name is a language key;
     * $descriptionKey explains in one sentence what it is for and who sets
     * it. Declaring a category costs nothing on its own: check
     * $app->consent->granted($id) before actually loading whatever needs it.
     */
    public function cookieCategory(string $id, string $labelKey, string $descriptionKey): void
    {
        $this->app->consent->declareCategory($this->prefixed($id, 'Cookie category'), $labelKey, $descriptionKey);
    }

    /**
     * How this extension answers whether a visitor has agreed to a category
     * of cookie - the other half of a cookie-consent addon
     * (modulento-ext-cookie). Only one provider is ever registered; a second
     * call replaces the first.
     */
    public function consentProvider(ConsentProvider $provider): void
    {
        $this->app->consent->registerProvider($provider);
    }

    /**
     * A banner an extension wants shown on every page - e.g. a cookie-consent
     * addon's own banner. The template is one of this extension's
     * ("@id/banner.twig"); a theme may override it like any other. $data
     * runs on every page view; return null (or omit $data) for nothing to
     * show right now.
     *
     * @param Closure(App): array<string, mixed>|null $data
     */
    public function cookieBanner(string $template, ?Closure $data = null): void
    {
        $this->app->addCookieBanner($template, $data);
    }

    /**
     * A way to refuse a registration with this extension's own reason - e.g.
     * an anti-spam addon rejecting a disposable email address or a failed
     * CAPTCHA answer. Runs after the core's own checks and before the
     * account is created; refuse by throwing a RuntimeException whose
     * message is a language key - see core's RegistrationController::register().
     *
     * @param Closure(array<string, mixed>, App): void $check gets $_POST and the app; throws to refuse
     */
    public function registrationGuard(Closure $check): void
    {
        $this->app->addRegistrationGuard($check);
    }

    /**
     * A template an extension wants shown on the registration form, between
     * the password fields and the terms checkbox - e.g. a CAPTCHA question.
     * The template is one of this extension's ("@id/register.twig"); a
     * theme may override it like any other. $data gets the app and runs
     * every time the form is shown; return null (or omit $data) to show
     * nothing extra.
     *
     * @param Closure(App): array<string, mixed>|null $data
     */
    public function registrationSection(string $template, ?Closure $data = null): void
    {
        $this->app->addRegistrationSection($template, $data);
    }

    /**
     * @param string $group the section of the menu: "content", "marketplace",
     *        "moderation", "people", "system"; anything else, or nothing,
     *        puts the entry under "more"
     */
    public function adminMenu(string $labelKey, string $path, string $permission, string $group = 'more'): void
    {
        $this->app->addAdminMenu($labelKey, $path, $permission, $group);
    }

    private function prefixed(string $name, string $kind): string
    {
        if (!str_starts_with($name, $this->manifest->id . '.')) {
            throw new LogicException("{$kind} \"{$name}\" must start with \"{$this->manifest->id}.\"");
        }

        return $name;
    }
}
