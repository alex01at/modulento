# Modulento

Self-hosted core for transaction platforms. The core knows accounts, roles,
extensions and scheduled tasks, but no business model; an extension turns an
installation into a freelancer marketplace, a restaurant ordering system, an
auction site and so on.

Plain PHP without a framework and without a build step: PHP ≥ 8.3, Twig,
MariaDB through PDO, vanilla JS/CSS.

**Status: early.** The core has accounts (registration with e-mail
confirmation, password reset, own data export and deletion), provider
profiles and a catalogue of offers with approval, categories, pictures and
search, roles and account administration, content pages and legal texts,
several languages, themes, extensions, scheduled tasks and self-update. Two
extensions in repositories of their own show what the core carries:
[`freelancer`](https://github.com/alex01at/modulento-ext-freelancer) adds
services with packages as the first kind of offer,
[`auction`](https://github.com/alex01at/modulento-ext-auction) adds lots that
are sold to the highest bidder. Both are installed under
**Administration → Packages**.
Orders run as a state machine with history, messages, attachments and
deadlines; finished orders can be reviewed by their buyer. Buyers pay the
provider directly - by bank transfer, PayPal or Stripe, or as the two agree;
the platform never holds the money and takes no fee. See "Payment methods".

Two rules shape everything:

- **No shell needed.** Installing, updating, enabling extensions, switching
  themes and running database updates all happen in the browser; files get
  onto the server by upload. The command line scripts in `bin/` are a
  convenience, never a requirement.
- **Templates are not part of the core.** The core hands data to templates;
  every page is rendered by a theme. See "Themes" below.
- **Every text exists per language.** Interface texts, e-mails and content
  pages; nothing visible is written into code or templates. See "Languages".

## Installing

1. Take `modulento-<version>.zip` from the releases and upload its unpacked
   content. It already contains `vendor/`, so Composer is not needed.
2. Point the domain's document root at the `public/` folder. Where the
   hosting panel does not allow that, upload into the document root as it
   is: the `.htaccess` in the top folder passes every request on to
   `public/` (Apache with mod_rewrite).
3. Create an empty MySQL/MariaDB database in the hosting panel.
4. Open the site. The setup page checks the requirements, asks for the
   database, a site name and the first administration account, sets up the
   database and writes `.env`. After that it is no longer reachable.
5. Scheduled tasks need a trigger every minute. **Administration → Tasks**
   shows both ways: a scheduled task in the hosting panel that runs
   `php bin/cron.php`, or - where the panel can only call addresses - a secret
   URL.

## Developing

```
composer install
php -S 127.0.0.1:8098 -t public public/index.php     # opens the setup page
php tests/run.php
```

`cp .env.example .env`, `php bin/migrate.php` and
`php bin/create-admin.php you@example.com` do the same as the setup page from
the command line. Set `APP_ENV="dev"` while developing.

The tests drive the core through the extensions `freelancer` and `auction`,
which are not part of this repository. Clone them into `extensions/`, or
link working copies you have elsewhere; both folders are ignored by git here:

```
git clone https://github.com/alex01at/modulento-ext-freelancer.git extensions/freelancer
git clone https://github.com/alex01at/modulento-ext-auction.git extensions/auction
```

`php tests/run.php` stops with this hint while one of them is missing. CI
fetches both from their `main` branch, so a change to the interface shows
up there.

## Releases and updates

A release is made by pushing a version tag:

```
git tag v0.1.0 && git push origin v0.1.0
```

`.github/workflows/release.yml` then runs the checks, builds
`modulento-<version>.zip` (committed files plus production dependencies and a
`VERSION` file) and publishes it with its SHA-256 checksum as a GitHub Release.

An installation made from such a package finds new releases under
**Administration → Updates**. An update verifies the checksum, backs up the
current files to `var/updates/backups/`, copies the new files and runs the
migrations of the core and of every enabled extension. It only adds and
overwrites files, so `.env`, `var/`, themes and third-party extensions stay.
The database is not backed up - export it before updating.

- `UPDATE_REPO` in `.env` names the repository to update from; empty switches
  updates off.
- While that repository is private, `UPDATE_TOKEN` needs a fine-grained GitHub
  token with read-only "Contents" access to it.
- A git working copy is never updated this way; use `git pull`,
  `composer install` and `php bin/migrate.php` there.
- An extension or theme is installed or replaced by uploading its folder.
  "Run pending database updates" on the Updates page then applies an
  extension's new migrations. Or as a package, see below.

## Packages

Extensions and themes can live in repositories of their own and be installed
and updated under **Administration → Packages**: enter `owner/name`, and the
newest release of that GitHub repository is downloaded, checked against its
SHA-256, unpacked into `extensions/<id>/` or `themes/<id>/` and remembered
with its source. "Check for updates" then shows newer releases.

A package is a release tagged `v<version>` with two assets, `<name>.zip` and
`<name>.zip.sha256`. The zip holds the folder's content with `extension.json`
or `theme.json` at the top, and the version in that file equals the tag.
The Indigo theme's repository has a workflow that builds exactly this.

Installing a package runs someone else's code on the server, so:

- Only repositories matching `PACKAGE_SOURCES` in `.env` can be installed
  from (comma-separated patterns such as `owner/*`). Without the setting,
  every repository of the owner of `UPDATE_REPO` is allowed.
- A package keeps the repository it first came from; another repository
  cannot take over its name.
- What the core ships (`default`, `admin`, `example`) cannot be replaced by a
  package.
- An extension written for another interface version is refused.
- A package can name the oldest core it runs on with `"requires": "0.11.0"`
  in `extension.json` or `theme.json`; an older core refuses it and says so.
- The previous folder is kept under `var/updates/backups/packages/`.

The page lists the project's own packages that are not installed yet -
`modulento-ext-freelancer`, `modulento-ext-auction` and
`modulento-theme-indigo` of the owner of `UPDATE_REPO` - each with a button.

Earlier versions of the core brought `freelancer` and `auction` along. A core
update leaves these folders, their tables and their enabled state as they
are. They then appear on the page as "Present, but not a package"; "Take
over as package" installs the newest release of their repository in their
place and from then on they are updated like any other package.

## Payment methods

Money always goes from the buyer straight to the provider. The platform has
no account it passes through and keeps no commission; it only records that
an order was paid. Four ways to pay ship with the core:

| Id | What happens | Who confirms the payment |
|---|---|---|
| `core.offline` | Buyer and provider settle it between themselves | the provider, on the order page |
| `core.transfer` | The buyer sees the provider's bank details on the order page, with "Order <number>" as payment reference | the provider, on the order page |
| `core.paypal` | The buyer pays at PayPal into the provider's PayPal account | PayPal, when the buyer returns |
| `core.stripe` | The buyer pays on a Stripe Checkout page into the provider's Stripe account | Stripe, by webhook and when the buyer returns |

**The operator** chooses under **Administration → Payment methods** which of
them the site allows (permission `core.settings.manage`). `core.offline` is
on, the other three are off until switched on; at least one stays allowed.
Bank transfer and PayPal need nothing else from the platform. Stripe does:

1. A Stripe account of the platform with **Connect** activated. Providers
   get connected accounts of the type "Standard"; payments are direct
   charges on those accounts without an application fee.
2. Its secret API key (`sk_...`) goes into the page.
3. The page shows the webhook address, `<APP_URL>/webhooks/stripe`. Create it
   in the Stripe dashboard as a webhook that listens to events **on connected
   accounts**, for the event `checkout.session.completed` (and, where
   payment methods that complete later are switched on,
   `checkout.session.async_payment_succeeded`), and enter its signing secret
   (`whsec_...`). Without the webhook a payment is recorded only when the
   buyer returns to the site after paying.

Stored keys are never shown again, only their last four characters; an empty
field keeps what is stored. `APP_URL` has to be the site's real HTTPS
address: the services send buyers and providers back to it.

**A provider** sets up under **My account → Payment methods** what buyers
are offered: bank details (the IBAN is checked), the client ID and secret of
the provider's *own* PayPal app (developer.paypal.com → "Apps & Credentials",
"Live"; "Check connection" tells whether PayPal accepts them; a test mode
uses the sandbox), and "Connect with Stripe", which creates the connected
account and leads to Stripe's own pages to complete it. An order form offers
exactly the methods that are allowed *and* set up by the offer's provider.
While an order is unpaid its buyer can pay it from the order page ("Pay
now", also after breaking off) or choose another of the available methods.
An order an extension creates itself starts with `core.offline`; where that
is switched off, the buyer is asked to choose on the order page.

What the site stores and checks:

- API keys - the platform's Stripe keys, the providers' PayPal secrets - are
  stored encrypted (libsodium). The key for that is the file
  **`var/secret.key`**, created on first need, never in the database.
  **It belongs to every backup: without it the stored keys cannot be read
  and have to be entered again.** `var/` is not reachable from the web. On
  a PHP without the `sodium` extension PayPal and Stripe cannot be switched
  on, and the page says so.
- A payment is recorded as paid only if the service reports it as completed
  with the order's amount and currency, for the session or PayPal order that
  was stored when the payment was started. Webhooks need a valid signature
  not older than five minutes. Reported twice, a payment is recorded once.
  Buyer and provider are told by e-mail.
- Buyers are sent to the services by redirect; no script of a payment
  service runs on the site. The content security policy lets forms lead to
  `checkout.stripe.com`, `connect.stripe.com`, `www.paypal.com` and
  `www.sandbox.paypal.com`, and an address a service returns is only
  followed if it is on one of these hosts.
- Amounts are sent as integer minor units to Stripe and with two decimals
  to PayPal. Currencies without decimals (JPY) or with three are not
  supported.

**Refunds are not part of the platform.** A provider refunds in the own
Stripe or PayPal account, or by bank transfer; the order's payment state
does not change by that. The wording around payments is a starting point,
not legal or tax advice.

## Languages

- A language is a file `core/lang/<code>.php` (two-letter code), plus one per
  extension in its `lang/` folder. Which of them the site offers, and which is
  the default, is set under **Administration → Settings**.
- The language is part of the address: the default language has none
  (`/login`), every other its code (`/en/login`). Each language version of a
  page can be linked and indexed; pages announce each other with `hreflang`.
- A text missing in a language is shown in the default language, then in
  English, so an unfinished language pack is usable.
- `lang/<code>.php` in the installation is read last and may reword any text
  or complete a language. Updates never touch that folder.
- Content pages have one text and one address per language
  (**Administration → Pages**). A language without its own text shows the
  default language's.
- An account remembers its language: after logging in it lands there, and
  mails sent on its behalf use it.

In code and templates: never write a path as a literal. `App::url($path)`
and the Twig function `url()` add the language; `Controller::redirect()` does
it for redirects. Texts come from `trans()`; new keys go into every language
file of the folder (`php tests/run.php` checks that `de` and `en` match).

## Layout

```
core/         src/ (Modulento\Core), lang/, migrations/, install/ - no templates
extensions/   one folder per extension
lang/         this installation's own wording, see "Languages"
themes/       default/ (site), admin/ (administration), installed themes
public/       web root: index.php only
bin/          migrate.php, cron.php, create-admin.php
.github/      CI and release workflows
var/          cache, logs, uploads, update backups, secret.key - never reachable from the web
```

## Themes

A theme is a folder below `themes/` with `theme.json` (`id` equal to the
folder name, `name`, `version`), `templates/` and `assets/`. The site theme is
chosen under **Administration → Themes**.

- `default` is the complete site theme. Another site theme only contains what
  it changes; every template or asset it leaves out comes from `default`.
- `admin` renders the administration and is separate on purpose: a broken
  site theme cannot lock anyone out. It has two frames, the sidebar
  (`layout_sidebar.twig`) and the header with its mega menu
  (`layout_header.twig`), both on `layout_base.twig`; `layout.twig` picks one
  for the administrator's choice (`admin_layout()`), so pages keep extending
  `@admin/layout.twig`. A section's entries are shown as tiles by
  `@admin/section.twig`.
- A theme overrides an extension's templates by placing files in
  `themes/<theme>/extensions/<extension id>/`.
- A theme can bring texts of its own (a slogan, the steps on its home page) in
  `themes/<theme>/lang/<code>.php`, with keys starting `theme.`.
- [Indigo](https://github.com/alex01at/modulento-theme-indigo) is a second site
  theme in a repository of its own and shows how little a theme needs: a
  layout, a home page, two card partials, a stylesheet and its font. Every
  other page comes from `default` and only looks different.

| Template name | Looked up in |
|---|---|
| `home.twig` | active site theme, then `themes/default` |
| `@admin/x.twig` | `themes/admin` |
| `@<ext>/x.twig` | `themes/<theme>/extensions/<ext>/`, then the extension's `templates/` |

Templates of the site theme, with the variables they receive:

| Template | Variables |
|---|---|
| `layout/base.twig` | blocks `title`, `head`, `content`; see "The layout" below for what it has to carry |
| `layout/_account_menu.twig` | - ; the logged-in account's menu for the header, included by the layout |
| `home.twig` | - |
| `page.twig` | `page`: `title`, `body` (cleaned HTML, output with `raw`), `meta_description`, `locale`, `role` |
| `error.twig` | `status`, `message_key` |
| `auth/login.twig` | `can_resend_verification`; keep the checkbox `remember` |
| `auth/register.twig` | `errors`, `email`, `min_length`, `legal` (terms and privacy pages to accept, as `title`/`url`); keep the hidden `website` field |
| `auth/verify.twig` | `token`; a form that posts to `/verify-email/<token>` - the address is confirmed by the button, not by opening the link |
| `auth/forgot.twig` | - |
| `auth/reset.twig` | `errors`, `token`, `min_length` |
| `account/_nav.twig` | - ; the account's pages, included on top of each; `provider_status()` says whether the account has a provider profile |
| `account/dashboard.twig` | `name`, `avatar`, `recent` (offer cards viewed last, from the session), `purchases` (`open`, `closed`, `recent`), `provider` (null or `name`, `status`, `path`, `rating`, `offers`, `offers_public`, `sales` like `purchases`, `types`); the page behind "Dashboard" |
| `account/index.twig` | the settings: `avatar` (path of the profile picture or null), `locales`, `min_length`, `is_last_admin`, `provider_status`, `devices` (where the account stays logged in: `created_at`, `last_used_at`, `browser`, `current`), `remember_days` |
| `account/provider.twig` | `provider` (stored or typed values, `texts` by language), `status`, `status_note`, `public_path`, `certified`, `errors`, `locales`, `countries`, `approval_required` |
| `offer/index.twig` | `offers` (cards), `total`, `categories` (tree), `category`, `search`, `sort`, `sorts`, `page`, `pages` |
| `offer/_cards.twig` | `offers`: `title`, `summary`, `path`, `price_from`, `currency`, `thumb`, `provider_name`, `provider_path` |
| `offer/show.twig` | `offer` (`title`, `summary`, `description` as plain text, `images`, `price_from`, `category`, `provider_*`, `is_own`), `type_template`, `type_data` |
| `account/payments.twig` | `transfer` (`ready`, `holder`, `iban`, `bic`, `bank`), `paypal` (`status`: null, `unverified` or `ready`; `client_id`, `sandbox`, `has_secret`), `stripe` (`platform_ready`, `status`: null, `pending` or `ready`; `account`) - each null if the operator does not allow the method -, `offline`, `errors`; forms post to `/account/payments/transfer`, `/paypal`, `/paypal/check`, `/stripe/connect`, `/stripe/status` and `/<method>/delete`; keep the note on refunds; a secret is never passed to the template |
| `account/offers.twig` | `offers`, `types`, `provider_status` |
| `account/offer_edit.twig` | `offer`, `type` (`id`, `label_key`, `template`), `type_data`, `texts`, `category_id`, `categories`, `locales`, `errors`, `approval_required`, `images_available`, `max_images`, `currency` |
| `order/new.twig` | `offer`, `flow_template`, `flow_data`, `note`, `payment_methods` (the ones available for this provider; may be empty), `payment_method` (the one to preselect, or null), `terms`, `errors`, `file_limits`; keep the button's wording; forms with a file field need `enctype="multipart/form-data"` |
| `order/index.twig` | `role` (`buyer` or `provider`), `orders`, `page`, `pages` |
| `order/show.twig` | `order` (summary, `items`, `events` and `messages` each with `files`, payment), `role`, `actions` (with `note` and `files`), `can_mark_paid`, `payment` (`paid_at`; for the buyer of an unpaid order: `needs_choice`, `can_pay_now` - a form posting to `/orders/<id>/pay` -, `transfer` with `holder`, `iban`, `bic`, `bank`, `reference`, and `choices` - other methods, posted as `payment_method` to `/orders/<id>/payment-method`; for the provider: `attempts` with `label_key`, `method`, `reference`, `status`, `amount`, `currency`, `at`), `counterpart`, `flow_template`, `flow_data`, `file_limits` |
| `withdrawal/form.twig` | `name`, `email`, `order_number`, `order_choice`, `statement`, `orders` (the logged-in buyer's own: `number`, `title`, `created_at`), `limits`, `errors`; keep the hidden `website` field and the note on what the form does |
| `withdrawal/review.twig` | `name`, `email`, `order_number`, `statement`, `errors`; a form that posts to `/withdrawal/confirm` - keep the button's wording |
| `withdrawal/done.twig` | `email`, `received_at` (UTC) |
| `report/form.twig` | `url`, `category`, `explanation`, `name`, `email`, `categories`, `limits`, `errors`; keep the hidden `website` field, the statement of good faith and the note on what happens with a notice |
| `report/done.twig` | `email`, `received_at` (UTC) |
| `review/_rating.twig` | `rating` (`count`, `average`): stars with a text alternative |
| `review/_list.twig` | `reviews`: `author` (empty for "a buyer"), `rating`, `body`, `locale`, `created_at`, `reply`; keep the note on where reviews come from |
| `provider/index.twig` | `providers`, `page`, `pages` |
| `provider/show.twig` | `provider`: `name`, `path`, `type`, `headline`, `description` (plain text), `city`, `country`, `legal` (only for a business); block `offers` for extensions |
| `emails/*.txt.twig` | blocks `subject` and `body`; plain text, not HTML-escaped |

E-mails are theme templates too: `verify_email`, `reset_password`,
`already_registered`, `change_email`, `password_changed`, `provider_approved`,
`provider_rejected`, `provider_suspended`, `account_blocked`, `offer_published`,
`offer_rejected`, `offer_contact`, `order_update`, `order_message`, `order_paid`, `review_new`,
`review_reply`, `review_hidden`, `withdrawal_receipt`, `withdrawal_provider`,
`withdrawal_platform`, `report_receipt`, `report_platform`, `report_decision`. With `APP_ENV="dev"`
nothing is sent; mails are appended to `var/log/mail.log`.

Available in every template:

| | |
|---|---|
| `trans(key, {placeholders})` | Text in the visitor's language |
| `url(path)` | Address of a path in the current language - use it for every link and form target |
| `locale()`, `locale_urls()`, `locale_name(code)` | Current language; the current page in every language (`locale`, `name`, `url`, `absolute_url`, `current`) |
| `current_path()` | Path of the current page without its language prefix, e.g. to mark the active menu entry |
| `page_links('header' \| 'footer' \| role)` | Published pages for a menu, as `title`/`url`/`role`; `'footer'` ends with the links to the withdrawal form and the report form (`role` is `withdrawal` and `report`) |
| `latest_offers(limit)` | The newest public offers as cards |
| `editor()` | The editor's wording as JSON: `<textarea … data-editor="{{ editor() }}">` turns an HTML field of the administration into a simple editor (`themes/admin/assets/editor.js`); without JavaScript it stays a text field, and the server cleans the HTML either way |
| `module(id)` | Whether an optional function of the core is on: `reviews`, `contact`, `withdrawal`, `reports`, `avatars`, `remember_login` - a theme hides what belongs to a module that is off |
| `has_catalogue()` | Whether an extension adds a kind of offer; without one, hide the links to offers and providers |
| `nav_links()` | Entries extensions add to the main menu, as `title`/`url`/`path` |
| `home_sections()` | What extensions want shown on the home page: `{% for section in home_sections() %}{% include section.template with section.data %}{% endfor %}` |
| `categories()` | The category tree with `name`, `path`, `children` and `offer_count` |
| `top_providers(limit)` | Public providers, best rated first, as shown on `provider/show.twig` |
| `registration_open()` | Whether new accounts can be created |
| `provider_status()` | Status of the logged-in account's provider profile, or null without one |
| `account_avatar()` | Path of the logged-in account's profile picture, or null without one (or without an account) |
| `color_scheme()` | `auto`, `light` or `dark`: what the logged-in account has chosen; `auto` for visitors |
| `admin_layout()` | `sidebar` or `header`: the frame of the administration that an administrator chose under Profile settings; `sidebar` for everyone else. Used by `themes/admin` only |
| `site_logo('light' \| 'dark')` | Path of the site's own logo (Administration → Themes), or null without one; `'dark'` falls back to the light logo if no separate dark one was uploaded |
| `site_favicon()` | Path of the site's own favicon, or null for the browser's default |
| `meta_description()` | The default description (Administration → Settings), for pages that have none of their own |
| `theme_asset(path)`, `admin_asset(path)`, `ext_asset(id, path)` | URL of a file in an `assets/` folder, with cache busting |
| `csrf_field()`, `csrf_token()` | Required in every `POST` form or AJAX call |
| `can(permission)` | Whether the logged-in account has a permission |
| `money(cents, currency)` | Formatted amount |
| `account`, `site_name`, `site_url`, `flashes`, `admin_menu` | Globals - `site_url` is the bare address of the site, for the rare absolute link to something that is not a page |

Assets are served from the theme folder itself (`/assets/theme/...`), so a
theme works by upload alone - no symlink, no copy step, no build. The content
security policy allows scripts and styles from the site's own origin only: no
inline `<script>`, no inline `style`, no external hosts.

### The layout

A theme that brings its own `layout/base.twig` takes over three things from
the default one. Indigo shows all of them.

**The account menu.** A logged-in account finds "Dashboard" (`/account`) in
the header and next to it its picture, which opens a menu with the profile
settings and the logout form. `{% include 'layout/_account_menu.twig' %}`
brings the markup: a `<details class="account-menu">` whose `<summary>` holds
the picture from `account_avatar()` or the initial letter
(`.account-menu-picture`), and `.account-menu-items` with the links. It works
without scripts; `account-menu.js` closes it on Escape and on a click
elsewhere. The theme's stylesheet places the menu. "My offers" (with a
provider profile) and "Administration" (with `can('core.admin.access')`) stay
in the header.

**The colour scheme.** An account chooses "automatic", "light" or "dark" in
its settings; visitors are always "automatic". The layout writes a fixed
choice onto the root element and nothing for "automatic":

```twig
{% set scheme = color_scheme() %}
<html lang="{{ locale() }}"{% if scheme != 'auto' %} data-theme="{{ scheme }}"{% endif %}>
```

The stylesheet defines its colours as variables with the light values, and
replaces them in two places with the same dark values - for a device that
asks for dark unless the account chose light, and for an account that chose
dark:

```css
:root { color-scheme: light; --ground: #fff; --text: #1c2430; }
@media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) { color-scheme: dark; --ground: #14181f; --text: #e6e9ee; }
}
:root[data-theme="dark"] { color-scheme: dark; --ground: #14181f; --text: #e6e9ee; }
```

`color-scheme` makes form fields and scrollbars follow. A colour written out
anywhere else in the stylesheet stays the same in both schemes, so check
text on filled buttons, borders, placeholders and messages in both. The
administration follows the same choice. A theme without dark values simply
stays light.

**"Show password".** Every password field gets a button that shows what was
typed. `password-toggle.js` adds it, so without scripts there is none and no
template needs markup for it; the layout loads the file and hands it the
wording:

```twig
<script src="{{ theme_asset('password-toggle.js') }}" defer data-show="{{ trans('core.password.show') }}" data-hide="{{ trans('core.password.hide') }}"></script>
```

The script wraps each `input[type="password"]` in `.password-field` and
appends a `button.password-toggle` (`type="button"`, `aria-pressed`); the
field's `autocomplete` stays, and it is sent as a password field. Both
scripts come from `default` unless the theme brings files of the same name.
The setup page loads its own copy from `core/install/`, which has to stay
identical.

**The logo, favicon and meta tags.** `site_logo()` and `site_favicon()`
answer null until an administrator uploads one (Administration → Themes →
Branding); every theme should fall back to `site_name` where there is no
logo, and skip the `<link rel="icon">` where there is no favicon. The default
theme's header shows the light logo by itself and the dark one as well,
switched by the same CSS the colour scheme already uses:

```twig
<img class="site-logo site-logo-light" src="{{ logoLight }}" alt="{{ site_name }}">
<img class="site-logo site-logo-dark" src="{{ logoDark }}" alt="{{ site_name }}">
```

```css
.site-logo-dark { display: none; }
@media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) .site-logo-light { display: none; }
    :root:not([data-theme="light"]) .site-logo-dark { display: block; }
}
:root[data-theme="dark"] .site-logo-light { display: none; }
:root[data-theme="dark"] .site-logo-dark { display: block; }
```

The layout also carries the description, Open Graph and Twitter tags every
page needs, built once and reused so a page sets both the plain description
and `og:description` by overriding a single block:

```twig
{% set page_description %}{% block meta_description %}{{ meta_description() }}{% endblock %}{% endset %}
{% if page_description %}<meta name="description" content="{{ page_description }}">{% endif %}
```

A page overrides `meta_description` (`offer/show.twig` uses the offer's
summary) and, for `og:image`, `meta_image` (the offer's first picture,
falling back to `site_logo('light')`); both default to the site-wide values
when a page does not set them.

## Writing an extension

Copy `extensions/example/`, the one extension the core ships. An extension is
a folder whose name is its id, in this repository or - to be installed as a
package - in one of its own:

```
extensions/<id>/
  extension.json      id, name, version, api, namespace
  src/Extension.php   implements Modulento\Core\Extension\Extension
  templates/          rendered as "@<id>/file.twig"; a theme can override them
  assets/             served at /assets/ext/<id>/..., see ext_asset()
  lang/de.php en.php  every key starts with "<id>."
  migrations/*.sql    run when the extension is enabled; tables are x_<id>_<name>
```

`register()` announces everything through the `Registrar`:

| Call | Purpose |
|---|---|
| `routes(fn (Router $r) => ...)` | Routes. Each states its access: `Router::PUBLIC`, `Router::AUTH` (default) or a permission name |
| `permission(name, labelKey)` | A permission that roles can be given |
| `adminMenu(labelKey, path, permission, group)` | An entry in the administration menu, in one of its sections: `content`, `marketplace`, `moderation`, `people`, `system`, or `more` (the default) |
| `navigation(labelKey, path)` | An entry in the site's main menu |
| `homeSection(template, data)` | A template of the extension included on the home page; `data` is an optional `Closure(App): array` that supplies its variables |
| `listen(EventClass, fn ($event, App $app) => ...)` | React to a core or extension event |
| `task(name, everyMinutes, fn (App $app) => ...)` | Scheduled work, run by `bin/cron.php` |
| `offerType(OfferType)` | A kind of offer for the catalogue, see below |
| `orderFlow(OrderFlow)` | How offers of a type are ordered and carried out, see below |
| `paymentMethod(PaymentMethod)` | A way to pay, e.g. a payment service |

Core services an extension uses instead of SQL on core tables, all on the
`App` object: `accounts` (find, create, change accounts), `tokens` (one-time
links), `mailer` (`send(to, '@<id>/emails/x.txt.twig', data, locale)`),
`settings`, `locales`, `pages`, `providers`, `offers`, `categories`,
`offerImages`, `orders`, `roles`, `auth`, `events`, and `url()`.

### Offer types

The core owns what every offer has: provider, category, status and approval,
title and text per language with an address per language, pictures, search and
the pages around it. An extension adds a kind of offer by registering a class
that implements `Modulento\Core\Catalogue\OfferType`:

- `formTemplate()` / `formData()` - its fields inside the core's offer form
- `validate()` / `save()` - checking and storing them in the extension's own
  tables, which reference `offer (id)` with `ON DELETE CASCADE`; `validate()`
  receives the offer's id (null for a new one), so a type can keep what must
  not change any more; `save()` returns the lowest price for listings
- `detailTemplate()` / `detailData()` - its part of the public offer page

[`modulento-ext-freelancer`](https://github.com/alex01at/modulento-ext-freelancer)
is the reference: packages with price, delivery time and revisions, extras,
and requirements, each with a text per language.
[`modulento-ext-auction`](https://github.com/alex01at/modulento-ext-auction)
is the second one, built to prove that the interface carries something quite
different: a lot with a starting price that runs for a number of days from
the moment its offer is published.

### Order flows

An extension makes its offers orderable by registering an
`Modulento\Core\Order\OrderFlow`. The flow describes; the core executes:

- `states()` and `transitions()` - a transition names the states it starts
  in, its target, who may apply it (`buyer`, `provider`, `admin`, `system`),
  whether a note is asked, and optionally `by` (`counterparty` or `initiator`)
  to tie an answer to the side that did not, or did, cause the current state.
  The target `Orders::PREVIOUS` leads back to the state before the current one.
- a transition with `'files' => true` accepts attachments (a delivery);
  messages and the order form always do
- a final state with `'reviewable' => true` lets the buyer review the order
  (it was carried out, not declined or cancelled)
- `deadline()` - what the scheduler applies if nobody acts in time
- `allows()` - a further condition, e.g. revisions left
- `build()` - turns the buyer's choices into items and a total, **reading
  prices from the stored offer, never from the request**
- form and detail templates with their data
- `checkout()` - false if buyers do not order through the order form because
  the extension creates the orders itself with `Orders::create()`; the form's
  methods are then never called. The initial state may name with `'entered'`
  how history and e-mails call the order's creation ("Sold" instead of
  "Ordered")

`Orders::apply()` is the only way an order changes state. It checks state,
actor and guard, changes the state in one conditional update (two requests at
once cannot both succeed), writes the history, and `OrderNotifier` mails the
other side in their language. `src/ServiceFlow.php` in `modulento-ext-freelancer`
is the reference: accept or decline, deliver, revisions, acceptance, mutual
cancellation, expiry and automatic acceptance.

`modulento-ext-auction` shows the other way to an order. A bid is accepted by one
conditional update on the lot (of two bids at the same moment exactly one
wins), a bid in the last two minutes extends the end, and the task
`auction.close` turns the highest bid of each lot whose time is up into an
order - `SaleFlow` then covers handover, confirmation and cancellation. It
listens to `OfferStatusChanged` to start the clock, and to `AccountExport` and
`AccountDeleted` for the bids of an account.

Attachments (`$app->orderFiles`) are stored in `var/uploads/orders/` under
random names without extension, limited to a list of file types, and handed
out only to the order's two parties and to administrators - always as a
download, never displayed. The size limit is 20 MB per file or the server's
`upload_max_filesize` / `post_max_size`, whichever is lower.

Reviews (`$app->reviews`) belong to the core: one per order, by its buyer,
with one public reply by the provider. Offers and providers carry the number
and sum of their published ratings, so lists show and sort by them. An
administrator can hide a review with a reason, which the author receives.

The withdrawal form (`/withdrawal`, linked in the footer of every page and on
the buyer's order page) lets a buyer declare a withdrawal without logging in:
name, order number and the address for the acknowledgement, checked on a
second page and sent with "Confirm withdrawal". The sender receives an
acknowledgement of receipt by e-mail with the declaration and the time it
arrived. A declaration is assigned to an order only if the number fits the
buyer (the logged-in account, or the address of the buyer's account); it is
then passed on to the provider and shown among the order's messages, otherwise
it goes to the administrators. The answer is the same either way, and no order
changes state: whether a right of withdrawal exists is the provider's to
decide. **Administration → Withdrawals** lists every declaration
(`$app->withdrawals`); one that could not be assigned to an order waits there
until an administrator has passed it on and ticked it off. The wording is a
starting point, not legal advice.

The report form (`/report`, linked in the footer and on every offer and
provider page) takes notices about content someone considers illegal: the
address of the content, a category, the reasons, name and e-mail address and a
statement of good faith. It is protected like the registration (hidden field,
time trap, rate limits). The sender gets a confirmation of receipt, the
administrators a mail. Under **Administration → Notices** (permission
`core.reports.manage`) an administrator decides each notice with reasons,
which the sender receives together with the ways to object. Where the
reported address is an offer or a provider of the site, the list links to it,
and the provider is told about action taken - with the reasons, not with the
sender's name. The footer link carries the address of the page it is on. Acting on the
content itself - pausing an offer, suspending a provider, hiding a review -
happens where that content is administered. Again: a starting point, not
legal advice.

"Stay logged in" on the login form keeps a device logged in after its browser
was closed, for every kind of account. The cookie `remember` holds a random
selector and a random secret (`HttpOnly`, `SameSite=Lax`, `Secure` over
HTTPS); the table `account_login_token` holds the selector, the SHA-256 of
the secret, a fingerprint of the password hash and a shortened browser name -
nothing that logs anyone in. Each time a device is logged in again its secret
is replaced; the one before stays valid for 30 seconds, for the other tabs a
browser opens at once. A secret older than that can only come from a copied
cookie, so it logs out every remembered device of the account. A token ends
30 days after its last use, with logging out on that device, with "Log out
everywhere" in the settings, and for all devices when the password is changed
or reset, the e-mail address changes, or the account is blocked or deleted.
Changing password or address and deleting the account ask for the password
whichever way the device was logged in.

**Administration → Modules** switches optional functions of the core on and
off: reviews, the contact form on offers, the withdrawal form, reporting
content, profile pictures and "stay logged in". A module that is off has no
routes, menu entries or links; its data stays and is back when it is switched
on again (`$app->modules->enabled('reviews')`, in templates `module('reviews')`).
The withdrawal and the report form are legal duties in many cases - switch
them off only where they do not apply.

**Administration → Media library** keeps the pictures for the site: JPEG, PNG
or WebP up to 8 MB, saved again on upload (metadata is removed, pictures larger
than 2400 pixels are scaled down). Each picture has a fixed address
`/media/library/<name>` to put on a page. Who may use it is the permission
"Manage the media library" (`core.media.manage`), listed with the roles.

The administration's dashboard shows the installed version and what the last
update check found; that check asks the release server and only runs on the
Updates page. The settings pages of accounts and administration are split
into tabs: every tab sits in the same form, so saving keeps all of them.

Visitors' texts are checked against a short list of common swear words and
insults (`core/data/badwords/de.txt` and `en.txt`, one word per line). Messages
to providers, order messages and reviews that contain one are refused with a
note; the list is a starting point, not a moderation system.

Questions to a provider are a thread per offer (**offer page**, the contact
section, and the offer's provider sees every thread there). The e-mail to the
provider stays; the provider's answer goes back to the visitor by e-mail, and
the conversation is also on the offer page.

Signed-in accounts see how many messages wait for them: the number on their
picture is asked for every few seconds (**Administration → Settings → Ask for
new messages**, 0 switches it off). "Read" means the conversation was opened
after the newest message in it; the messages stay reachable on their pages
without the polling.

The word list is kept under **Administration → Word filter**, one word per
line. Until someone saves a list of their own, the lists that ship with the
core apply.

**Administration → Packages** installs an extension or theme from a repository
given as `owner/name` or as its GitHub address, or from a zip file that is
uploaded (checked like a package from GitHub, not updated automatically).

**Administration → Updates** lists every component in one place: the core,
each extension and each theme, with the installed and the newest version. A
check asks each source once and keeps the answer; the dashboard counts what
has a newer release. The packages page only shows what is installed.

**Administration → Home page** arranges the blocks of the start page: title
area, text, latest offers, top providers, a picture from the media library and
a link list. Each block can be moved, hidden or removed, and has its texts in
every language (a missing text falls back to the default language). The blocks
are drawn by the theme's `home/_<type>.twig` partials; a theme that brings its
own `home.twig` decides for itself whether it shows them.

**Administration → Design** sets the accent colour, the background, the text
colour, the font and the corner radius without touching a theme. The values
are written to a small stylesheet (`/design/<name>.css`, the name changes with
the values) that the default theme loads after its own. The colours apply to
the light colour scheme; the dark one stays the theme's. The logo is under
Themes → Branding.

**Administration → Offer page** sets which parts an offer's page shows and in
which order: pictures, description, the details of the offer's type,
reviews, the questions to the provider, and free text blocks (texts in every
language, the header's language is the one edited). Title, provider, price and
rating stay on top; hidden parts are kept and come back when shown again.
Partials in `offer/_block_<type>.twig` draw them.

**Administration → Accounts → Create account** makes an account for someone:
with a password, or without one, in which case the person gets an e-mail to set
their own. **Sign in as this user** (permission "Sign in as a user",
`core.accounts.impersonate`) shows the website as that person sees it. A banner
stays on every page until the way back is taken; the password, the address and
the account itself cannot be changed while signed in as someone else. An
administrator cannot be signed in as, nor an account of their own or a blocked
one. Every creation and sign-in is kept in the account's log (`admin_log`). This works for every offer type,
the freelancer and auction extensions included.

A page's text can show a picture from the media library: the picker next to
its text field inserts it at the cursor. Only pictures of the library are kept
by the sanitizer.

Behind a reverse proxy or CDN, list its addresses as `TRUSTED_PROXIES` in
`.env` ("10.0.0.0/8, 2001:db8::/32"). Only then is the visitor's address taken
from `X-Forwarded-For`; without it all visitors would share one limit for
logins and registrations. IPv6 visitors are counted by their /64 network.

A `PaymentMethod` decides how an order is paid. The core ships four (see
"Payment methods"); an extension can still register its own with
`paymentMethod()`: it sends the buyer to pay from `begin()` and reports the
result with `Orders::markPaid()`. Such a method appears under
**Administration → Payment methods**, allowed until the operator switches it
off, and is offered for every provider. A method that each provider has to
set up implements `Modulento\Core\Order\ProviderPaymentMethod` in addition
(`availableFor()`): it is then only offered for providers it is available
for, the buyer gets "Pay now" on the order page, and `begin()` may be called
again while the order is unpaid. If `begin()` throws, the order stands
unpaid and the buyer is told on the order page.

Visibility is the core's business: an offer is public while it is published,
its provider approved and the account active, and offers of a disabled
extension are hidden, not lost. `ProviderStatusChanged` and
`OfferStatusChanged` tell an extension when that changes. Amounts are integer
minor units; `Money::parse()` reads what people type, `money()` formats.

An extension is multilingual from its first line: texts in `lang/de.php` and
`lang/en.php`, links through `url()`, and content its users type stored per
language where it is shown to others (see `page_translation` for the
pattern).

Events to listen to: `AccountRegistered`, `AccountLoggedIn`, `AccountDeleted`,
`AccountExport`, `ProviderStatusChanged`, `OfferStatusChanged` and
`OrderStateChanged`. An extension that stores personal data per account
references `account (id)` with `ON DELETE CASCADE`, so deleting an account
removes it, and adds its part to the data export in an `AccountExport`
listener (see `extensions/example`).

Timestamps are created in PHP with `Clock::now()` and passed as parameters,
not taken from the database's `NOW()`.

Rules, which also bind first-party extensions:

1. Core tables are only used through core classes, never by SQL. A foreign
   key to a core primary key is the one allowed reference.
2. Core files are never edited. Use events, own templates or a theme.
3. The router checks access and the CSRF token before a handler runs. A
   `POST` form needs `{{ csrf_field() }}`; an AJAX call sends the token as
   `_csrf` or as `X-CSRF-Token` header.
4. `App::API_VERSION` is the version of this interface. An extension states
   the version it was written for in `extension.json` and is not loaded on a
   mismatch.

### Interface version 1

Extensions are released apart from the core, so what they build on is fixed.
Version 1 consists of:

- `Modulento\Core\Extension\Extension` with `register(Registrar)`, and
  `extension.json` with `id`, `name`, `version`, `api`, `namespace`
- the `Registrar` methods `routes`, `permission`, `adminMenu`, `navigation`, `homeSection`, `listen`,
  `task`, `offerType`, `orderFlow`, `paymentMethod`, and its `manifest`
- the interfaces `Modulento\Core\Catalogue\OfferType`,
  `Modulento\Core\Order\OrderFlow` and `Modulento\Core\Order\PaymentMethod`,
  including the keys of the arrays they return (states, transitions,
  deadlines); `Modulento\Core\Order\ProviderPaymentMethod` is an optional
  addition to the last one
- the events in `Modulento\Core\Event`: `AccountRegistered`,
  `AccountLoggedIn`, `AccountDeleted`, `AccountExport`,
  `ProviderStatusChanged`, `OfferStatusChanged`, `OrderStateChanged`, with
  their public properties
- `Orders::create()`, `Orders::apply()`, `Orders::markPaid()` and
  `Orders::PREVIOUS`
- the router's access levels (`Router::PUBLIC`, `Router::AUTH`, a permission
  name) and the CSRF check before every `POST` handler
- the services on `App` named above, as far as this document describes them
- the Twig functions and globals listed under "Themes", the template
  namespace `@<id>/`, and the blocks and variables of the site templates an
  extension's templates extend or are included in
- the naming rules: tables `x_<id>_<name>`, language keys, permissions, task
  names and offer type or flow ids starting with `<id>.`, assets below
  `/assets/ext/<id>/`

The rule: a change that can break an extension written for version 1 - a
method removed or renamed, a parameter added without a default, a method
added to one of these interfaces, a different meaning of an existing value -
raises `App::API_VERSION`, and extensions for the old version are no longer
loaded until they are released for the new one. Additions that existing
extensions do not notice - a new service, event, Twig function, optional
array key or optional parameter - do not. Everything not listed here is
internal and may change with any release.

## License

GPL-3.0-or-later, see `LICENSE`.
