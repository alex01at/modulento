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
several languages, themes, extensions, scheduled tasks and self-update. The
extension `freelancer` adds services with packages as the first kind of offer.
Orders run as a state machine with history, messages, attachments and
deadlines; finished orders can be reviewed by their buyer. Payment
is settled between buyer and provider for now; a payment service plugs in as
an extension.

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
- What the core ships (`default`, `admin`, `example`, `freelancer`) cannot be
  replaced by a package.
- An extension written for another interface version is refused.
- The previous folder is kept under `var/updates/backups/packages/`.

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
var/          cache, logs, uploads, update backups - never reachable from the web
```

## Themes

A theme is a folder below `themes/` with `theme.json` (`id` equal to the
folder name, `name`, `version`), `templates/` and `assets/`. The site theme is
chosen under **Administration → Themes**.

- `default` is the complete site theme. Another site theme only contains what
  it changes; every template or asset it leaves out comes from `default`.
- `admin` renders the administration and is separate on purpose: a broken
  site theme cannot lock anyone out.
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
| `layout/base.twig` | blocks `title`, `head`, `content` |
| `home.twig` | - |
| `page.twig` | `page`: `title`, `body` (cleaned HTML, output with `raw`), `meta_description`, `locale`, `role` |
| `error.twig` | `status`, `message_key` |
| `auth/login.twig` | `can_resend_verification` |
| `auth/register.twig` | `errors`, `email`, `min_length`, `legal` (terms and privacy pages to accept, as `title`/`url`); keep the hidden `website` field |
| `auth/forgot.twig` | - |
| `auth/reset.twig` | `errors`, `token`, `min_length` |
| `account/index.twig` | `locales`, `min_length`, `is_last_admin`, `provider_status` |
| `account/provider.twig` | `provider` (stored or typed values, `texts` by language), `status`, `status_note`, `public_path`, `certified`, `errors`, `locales`, `countries`, `approval_required` |
| `offer/index.twig` | `offers` (cards), `total`, `categories` (tree), `category`, `search`, `sort`, `sorts`, `page`, `pages` |
| `offer/_cards.twig` | `offers`: `title`, `summary`, `path`, `price_from`, `currency`, `thumb`, `provider_name`, `provider_path` |
| `offer/show.twig` | `offer` (`title`, `summary`, `description` as plain text, `images`, `price_from`, `category`, `provider_*`, `is_own`), `type_template`, `type_data` |
| `account/offers.twig` | `offers`, `types`, `provider_status` |
| `account/offer_edit.twig` | `offer`, `type` (`id`, `label_key`, `template`), `type_data`, `texts`, `category_id`, `categories`, `locales`, `errors`, `approval_required`, `images_available`, `max_images`, `currency` |
| `order/new.twig` | `offer`, `flow_template`, `flow_data`, `note`, `payment_methods`, `terms`, `errors`, `file_limits`; keep the button's wording; forms with a file field need `enctype="multipart/form-data"` |
| `order/index.twig` | `role` (`buyer` or `provider`), `orders`, `page`, `pages` |
| `order/show.twig` | `order` (summary, `items`, `events` and `messages` each with `files`, payment), `role`, `actions` (with `note` and `files`), `can_mark_paid`, `counterpart`, `flow_template`, `flow_data`, `file_limits` |
| `review/_rating.twig` | `rating` (`count`, `average`): stars with a text alternative |
| `review/_list.twig` | `reviews`: `author` (empty for "a buyer"), `rating`, `body`, `locale`, `created_at`, `reply`; keep the note on where reviews come from |
| `provider/index.twig` | `providers`, `page`, `pages` |
| `provider/show.twig` | `provider`: `name`, `path`, `type`, `headline`, `description` (plain text), `city`, `country`, `legal` (only for a business); block `offers` for extensions |
| `emails/*.txt.twig` | blocks `subject` and `body`; plain text, not HTML-escaped |

E-mails are theme templates too: `verify_email`, `reset_password`,
`already_registered`, `change_email`, `password_changed`, `provider_approved`,
`provider_rejected`, `provider_suspended`, `account_blocked`, `offer_published`,
`offer_rejected`, `offer_contact`, `order_update`, `order_message`, `review_new`,
`review_reply`, `review_hidden`. With `APP_ENV="dev"`
nothing is sent; mails are appended to `var/log/mail.log`.

Available in every template:

| | |
|---|---|
| `trans(key, {placeholders})` | Text in the visitor's language |
| `url(path)` | Address of a path in the current language - use it for every link and form target |
| `locale()`, `locale_urls()`, `locale_name(code)` | Current language; the current page in every language (`locale`, `name`, `url`, `absolute_url`, `current`) |
| `current_path()` | Path of the current page without its language prefix, e.g. to mark the active menu entry |
| `page_links('header' \| 'footer' \| role)` | Published pages for a menu, as `title`/`url` |
| `latest_offers(limit)` | The newest public offers as cards |
| `categories()` | The category tree with `name`, `path`, `children` and `offer_count` |
| `top_providers(limit)` | Public providers, best rated first, as shown on `provider/show.twig` |
| `registration_open()` | Whether new accounts can be created |
| `theme_asset(path)`, `admin_asset(path)`, `ext_asset(id, path)` | URL of a file in an `assets/` folder, with cache busting |
| `csrf_field()`, `csrf_token()` | Required in every `POST` form or AJAX call |
| `can(permission)` | Whether the logged-in account has a permission |
| `money(cents, currency)` | Formatted amount |
| `account`, `site_name`, `flashes`, `admin_menu` | Globals |

Assets are served from the theme folder itself (`/assets/theme/...`), so a
theme works by upload alone - no symlink, no copy step, no build. The content
security policy allows scripts and styles from the site's own origin only: no
inline `<script>`, no inline `style`, no external hosts.

## Writing an extension

Copy `extensions/example/`. An extension is a folder whose name is its id:

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
| `adminMenu(labelKey, path, permission)` | An entry in the administration menu |
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
  tables, which reference `offer (id)` with `ON DELETE CASCADE`; `save()`
  returns the lowest price for listings
- `detailTemplate()` / `detailData()` - its part of the public offer page

`extensions/freelancer` is the reference: packages with price, delivery time
and revisions, extras, and requirements, each with a text per language.

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

`Orders::apply()` is the only way an order changes state. It checks state,
actor and guard, changes the state in one conditional update (two requests at
once cannot both succeed), writes the history, and `OrderNotifier` mails the
other side in their language. `extensions/freelancer/src/ServiceFlow.php` is
the reference: accept or decline, deliver, revisions, acceptance, mutual
cancellation, expiry and automatic acceptance.

Attachments (`$app->orderFiles`) are stored in `var/uploads/orders/` under
random names without extension, limited to a list of file types, and handed
out only to the order's two parties and to administrators - always as a
download, never displayed. The size limit is 20 MB per file or the server's
`upload_max_filesize` / `post_max_size`, whichever is lower.

Reviews (`$app->reviews`) belong to the core: one per order, by its buyer,
with one public reply by the provider. Offers and providers carry the number
and sum of their published ratings, so lists show and sort by them. An
administrator can hide a review with a reason, which the author receives.

A `PaymentMethod` decides how an order is paid. The core ships `core.offline`
(buyer and provider settle it themselves; the provider confirms the receipt).
A payment service implements the same interface, sends the buyer to pay from
`begin()` and reports the result with `Orders::markPaid()`.

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

## License

GPL-3.0-or-later, see `LICENSE`.
