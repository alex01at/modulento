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

For operators, **the handbook** (`/handbook`, in every offered language) covers
the same ground as this section and the next in a connected, non-technical
read - installation through day-to-day running. It ships with the core, as
Twig files under `themes/default/templates/handbook/<locale>/`, one chapter
per file, listed in `Modulento\Core\Support\Handbook::CHAPTERS`. It is a
different document from `/admin/docs` (admin-only): the handbook is for running
an installation, `/admin/docs` is the developer reference for building a theme
or an extension.

## Installing

**What you need:** PHP 8.3 or newer with PDO and the usual extensions for
images and HTTP, a MySQL or MariaDB database, and an address that points at
the site over HTTPS. Payments are optional; without them the site runs, and
nothing can be bought.

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

### After the installation

- **Theme:** **Administration → Themes** chooses the look. The standard theme
  is there from the start; more come from the Packages page.
- **Extensions:** **Administration → Packages** installs an extension from its
  repository (or from an uploaded zip). Only one extension is active at a time:
  enabling one switches the others off, their data stays.
- **Payments:** **Administration → Payment methods** switches Stripe and bank
  transfer on, with the platform's Stripe keys. Each provider sets up its own
  payment account under **Account → Payments**.
- **Subscriptions** (optional module, under **Administration → Modules**): plans
  under **Administration → Subscriptions**, the bank account and the seller's
  details for the invoices there too. Nothing is sold before these are filled
  in. Stripe needs the webhook `/webhooks/stripe` to receive the subscription
  events of the platform account (see "Subscriptions" below).

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

## Administration guide

Everything an operator does happens under **Administration** (`/admin`). The
menu has sections; which entries an account sees depends on its roles (see
"Roles and permissions" below). The header layout (**Profile settings →
Layout of the administration**) moves the same menu into a bar on top.

Every page of the administration works the same way: the form on it is sent
as a whole, a flash message above the content says what happened, and where a
list is long it has pages. A row or a card with a link goes to its detail page.

### Dashboard

The start page shows one card per area the account may manage: the number of
items, and what waits for a decision. The **Updates** card shows how many
components have a newer release (see "Updates"). Below the cards are all areas
as links.

### Content

- **Pages** (`core.pages.manage`): content pages such as the imprint, terms,
  privacy policy, withdrawal and report forms. A page has a language per text,
  a status (draft or published), a place in the header or footer, and can be
  one of the legal roles. A legal role exists only once.
- **Media library** (`core.media.manage`): pictures for the site. Upload JPEG,
  PNG or WebP up to 8 MB. Each picture is saved again (metadata is removed,
  pictures larger than 2400 pixels are scaled down) and gets a fixed address
  `/media/library/<name>`. The pictures can be inserted into page texts with the
  picker next to the text field.
- **Home page** (`core.settings.manage`): the blocks of the start page in their
  order: title area, text, latest offers, top providers, picture, link list.
  Blocks can be moved, hidden, removed and added. Texts are edited in the
  language the header shows; the other languages are kept.
- **Offer page** (`core.settings.manage`): which parts of an offer's page show
  and in which order: pictures, description, the details of the offer's type,
  reviews, questions to the provider, and free text blocks. Title, provider,
  price and rating stay on top.
- **Design** (`core.settings.manage`): accent colour, background, text colour,
  font (four system font families) and corner radius. These values are written
  to a small stylesheet; the templates stay untouched. The colours apply to the
  light colour scheme. The logo and the site icon are under **Themes → Branding**.

### Marketplace

- **Offers** (`core.offers.manage`): approve or reject offers from providers.
  Approval can be switched off under **Settings**, then new offers are
  published at once.
- **Categories** (`core.categories.manage`): the category tree offers are
  filed under, in every language.
- **Providers** (`core.providers.manage`): approve, reject or suspend provider
  profiles. Changes to the legal details of an approved provider are flagged
  for review while the profile stays public. A verified identity (see
  **Accounts** below) and earned badges (**Settings → Catalogue**) show next
  to the provider's name, both here and on the public profile.
- **Orders** (`core.orders.manage`): every order with its state history,
  messages, attachments and payments. Orders can be moved on by hand where the
  order flow allows it.
- **Withdrawals** (`core.orders.manage`): the withdrawal declarations of
  buyers. Each is passed on to the provider and ticked off as handled.
- **Payment methods** (`core.settings.manage`): what the platform itself needs
  for bank transfer, PayPal and Stripe. Each provider sets up their own account
  details; the platform never holds the money. Test the real payment services
  with your own sandbox credentials before enabling them.
- **Billing profile**: a buyer's own company name, website, VAT/tax number and
  billing address, under **Account → Settings → Profile**. Entirely optional,
  separate from the provider profile, and not wired into anything else yet -
  it is the account's own record, not an automatic invoice.

### Moderation

- **Reviews** (`core.reviews.manage`): hide a review with a reason; the author
  is told.
- **Account ratings** (`core.reviews.manage`, part of the `reviews` module):
  hide a direct rating of one account by another with a reason; the rater is
  told.
- **Reports** (`core.reports.manage`): notices about content, decided with a
  reason. Reporters and the affected provider are told the outcome.
- **Messages** (`core.messages.manage`): every order message and every offer
  question/reply, in one place (two tabs) - not only reachable per order or
  per offer. Dismiss a flag (content stays as it is) or hide a message (its
  content is replaced by a notice for the two parties, never for an
  administrator); showing it again undoes that.
- **Word filter** (`core.settings.manage`): the words that messages, questions
  to providers and reviews may not contain. For reviews and direct account
  ratings a match still refuses the text outright. For order messages and
  offer questions/replies it no longer refuses anything - the message is
  always delivered, only flagged for the **Messages** page above to decide.
  The list ships with
  the core (`core/data/badwords`) and applies until an administrator saves a
  list of their own. A saved list replaces the shipped one; **Restore the
  default list** brings it back. A word matches spellings with special
  characters (`f*ck`, `sh!t`), stretched letters (`fuuuck`) and capitals. Words
  of up to four letters only match as a whole word.

### People

- **Accounts** (`core.accounts.manage`): search, block and unblock, mark as
  verified, send a password reset, change roles, delete. The page of an account
  shows its log.
- **Identity verification** (`core.accounts.manage`, on the account's own
  page): an account can upload an ID or another official document under
  **Account → Settings → Security**; verify or reject it here, with a note
  told to the account on rejection. A verified identity shows as a blue
  checkmark next to the account's provider profile, if it has one. Documents
  are stored outside the web root and are never public - only this page
  offers a download, and only to an administrator.
- **Profile badges** (`core.settings.manage`, thresholds under **Settings →
  Catalogue**): "Top rated" and "Fast responder" are awarded automatically on
  a provider's public profile once its rating average/count, respectively its
  average first-response time, cross the thresholds set there. Nothing here
  lets an administrator define a new kind of badge.
- **Create account**: an account for someone, with or without a password. Without
  one, the person gets an e-mail with a link to set their own password.
- **Sign in as this user** (`core.accounts.impersonate`): shows the website as
  that person sees it, to find out what they see. A bar stays at the top of
  every page until **End and back to the administration** is clicked. While
  signed in as someone else, the password, the e-mail address and the account
  itself cannot be changed or deleted. Administrators cannot be signed in as,
  nor one's own account, nor a blocked one. Every creation and every sign-in is
  kept in the account's log.
- **Roles** (`core.roles.manage`): a role is a set of permissions. Permissions
  are grouped like the menu. Three presets are offered: editor, moderator and
  support. Handing out roles hands out permissions, so changing roles needs
  this permission on its own. The administrator role cannot be changed or
  deleted.

### System

- **Settings** (`core.settings.manage`): site name, sender address, whether
  registration is open, the default description, the currency, approval of
  providers and offers, the languages that are offered and the default language,
  and how often a signed-in browser asks for new messages (0 switches it off).
- **Modules** (`core.settings.manage`): optional functions of the core that can
  be switched off: reviews, the contact form on offers, the withdrawal form,
  reporting, profile pictures and "stay signed in". A module that is off has no
  routes, menu entries or links; its data stays.
- **Themes** (`core.themes.manage`): which theme the site uses, and branding.
  The administration's own theme cannot be replaced.
- **Packages** (`core.packages.manage`): install extensions and themes from a
  repository (`owner/name` or its GitHub address), or from a zip file that is
  uploaded. See "Packages".
- **Extensions** (`core.extensions.manage`): switch installed extensions on and
  off. Their migrations run when they are switched on.
- **Tasks** (`core.tasks.view`): scheduled tasks and when they last ran. They
  need a trigger every minute, see "Operations".
- **Updates** (`core.update.manage`): every component in one list: the core,
  each extension and each theme, with installed and newest version. A check asks
  each source once; the result is kept until the next check. See "Updates".
- **Documentation** (`core.admin.access`): how themes and extensions are built.

### Roles and permissions

Every permission is granted through a role. An account has any number of roles;
the permissions of all of them count. The first administrator is created by the
setup and holds every permission (`*`). Without `core.admin.access` an account
never sees the administration at all, whatever else it may do.

| Permission | What it allows |
|---|---|
| `core.admin.access` | to open the administration at all |
| `core.pages.manage` | content pages |
| `core.media.manage` | the media library |
| `core.offers.manage`, `core.categories.manage`, `core.providers.manage` | the catalogue |
| `core.orders.manage` | orders and withdrawals |
| `core.reviews.manage`, `core.reports.manage` | moderation |
| `core.accounts.manage`, `core.accounts.impersonate` | people; signing in as someone |
| `core.roles.manage` | roles |
| `core.settings.manage` | settings, modules, payments, design, home page, offer page, word filter |
| `core.themes.manage`, `core.packages.manage`, `core.extensions.manage` | system |
| `core.update.manage`, `core.tasks.view` | updates and tasks |

### Updates

**Administration → Updates** is the one place for updates. It lists the core,
each installed extension and each installed theme with the installed and the
newest version. **Check** asks every source once and keeps the answers; the
dashboard and this page read them, so opening a page never asks GitHub.
**Update** installs the newest release of one component. The core has its own
migration step, which runs by itself when an update is applied.

Packages installed from a zip file have no repository to update from; upload
the new zip to update them.

The update itself verifies the checksum of the release, makes a backup of the
current files under `var/updates/backups/`, applies the new files and runs the
migrations. A release from a repository is only installed when the server
provides everything the release needs (its `requires` line names the oldest
core it runs on).

### Packages

An extension adds a feature, a theme changes the look. Both are installed
under **Administration → Packages**:

1. **By repository:** type `owner/name` or the address of the repository on
   GitHub. Only repositories that match `PACKAGE_SOURCES` in `.env` can be
   installed; without that setting, the owner of the core's repository.
2. **From a zip:** upload the zip file of a release. It is checked (it must be
   a package with its manifest) and installed. It is not updated automatically.

A package keeps the repository it first came from; another repository cannot
take its name. Switching an extension on or off, and choosing the active theme,
happen on the same page.

### Operations

**What to keep safe.** Back up together: the database, the folder `var/uploads`
(offer pictures, avatars, branding, media library, order files), the file
`var/secret.key` (it decrypts the stored payment credentials; without it those
have to be entered again), and the file `.env` (database access and the update
settings). A backup of the database alone is not enough to restore the site.

**Scheduled tasks.** Closing auctions, removing unverified accounts and the like
run through a trigger every minute. The administration
shows two ways: a scheduled task in the hosting panel that runs
`php bin/cron.php`, or a secret address (`/cron/<CRON_TOKEN>`) for panels that
can only call addresses. Without a trigger the site works, but time-based
things do not happen.

**Logs.** Errors are written to `var/log/php-error.log` and never shown to
visitors. In development (`APP_ENV="dev"` in `.env`) errors are also shown in
the browser, and the Twig cache is off. Use production (`prod`) on the live site.

**Messages.** Signed-in browsers ask for new messages every few seconds (the
interval is in the settings). This is a deliberate choice for shared hosting:
it needs no permanent connection. Messages stay on their pages either way.

**Languages.** The languages offered are chosen under **Settings**. A language
gets its interface texts from `core/lang/<code>.php`; a site-specific wording
goes into `lang/<code>.php` of the installation and overrides the core.

### Security

- Every form and every AJAX call carries a token (CSRF). Forms that are not
  sent with one are refused.
- The content security policy allows scripts and styles from the site's own
  origin only. There is no inline script and no inline style, so a text can
  never run code.
- Texts that visitors write are cleaned on the way in (HTML is limited to
  simple formatting and links to the site or to secure addresses). Pictures in
  texts must come from the media library.
- Passwords are hashed. Actions that could be abused are rate-limited: sending
  messages and questions, withdrawal and report forms, placing orders, starting
  payments, connecting payment accounts, and resending verification e-mails.
- Signing in as someone else is limited (see "People") and logged.
- The payment credentials of providers are encrypted with the key in
  `var/secret.key`.

### Troubleshooting

- **A page shows "500 - Internal error":** read `var/log/php-error.log`. The
  first line of the newest entry names the file and the line.
- **The update fails:** check the Updates page for a message, then the log.
  An update that was cut off is named on the page as a stale attempt. That notice
  is information only: it does not block the next attempt.
- **Scheduled tasks do not run:** the trigger is missing or points to the wrong
  path. The Tasks page shows when each task last ran.
- **E-mails do not arrive:** check the sender address under Settings and the
  mail log. In development, mails are written to `var/log/mail.log`.
- **A theme looks broken after a change:** the site theme and the administration
  are separate. A broken site theme cannot lock anyone out; switch back to the
  default theme under **Themes**.
- **Signed in as someone else and cannot find the way back:** the bar at the top
  of every page has the button **End and back to the administration**.

## Developing the core

### Layout of the code

```
core/src/        Modulento\Core: App (wires every service), Kernel (routes, menu, permissions)
core/src/Controller/  one class per area; actions take $params and echo via render()/redirect()
core/src/Support/     Auth, Session, Settings, Router, Mailer, Updater, UpdateChecks, Design, BadWords, ...
core/src/<Area>/      domain services: Account, Catalogue, Order, Review, Media, Content, Provider, ...
core/migrations/      NNN_name.sql, applied in order (Migrator); each is written once, never edited after a release
core/lang/            de.php and en.php: every key in both, the test checks parity
core/data/            shipped data (the word list)
themes/default/       the site theme: templates and assets
themes/admin/         the administration: templates and assets, separate on purpose
extensions/           extensions in their own repositories, symlinked for development
tests/run.php         one plain-PHP test file: check('description', condition)
```

### Recipes

**A new permission and menu entry.** Register the permission in `Kernel::registerCore`
(`$app->addPermission(name, labelKey)`), its route with that permission as the
third argument of `$router->get/post`, and the menu entry with
`$app->addAdminMenu(labelKey, path, permission, group)`. The group is one of
`content`, `marketplace`, `moderation`, `people`, `system`, `more`. The role editor
lists the permission automatically, grouped like the menu. Add its label and hint
(`<label>.hint`) in both language files.

**A new controller action.** Give it a route in `Kernel`, check the permission there
(not in the action), and redirect after a POST; the flash message is set with
`Session::flash('success'|'error', $this->trans(key))`. A test calls the route
through `request()` or `$post()`. The test suite checks that every `$this->method()`
a controller calls exists.

**A new setting.** Store it with `$app->settings->set(name, value)` (strings only;
JSON for lists), read it with `get(name, default)`, `has(name)`, and remove it with
`forget(name)`. Keep the name in the `core.` namespace and document its default.

**A new block type for the home page.** Add the type to `HomeLayout::TYPES`, its text
fields to `TEXT_FIELDS`, the fallback wording in `HomeLayout::fallback()`, and the
cleaning rule in `HomeLayout::clean()`. The theme draws it with
`themes/default/templates/home/_<type>.twig`, receiving `block` (`texts`, `count`,
`media`, `links`). The editor shows the type automatically.

**A new part of the offer page.** Add it to `OfferLayout::TYPES` (and to
`BUILT_IN` if it always exists), its partial `offer/_block_<type>.twig` (it receives
`block` and the whole offer context), and its label `core.offer_page.type.<type>`.

**A new Twig function.** Register it in `Support/View.php` next to the others, with a
comment; document it in the table "Available in every template" of this README.

**A new migration.** The next number, plain SQL, InnoDB and utf8mb4 as the others.
Test databases are built by hand in `tests/run.php` (SQLite): add the table there too,
or the new code fails in the test run.

**Texts.** Every visible text goes through `trans()`; add the key to `de.php` and
`en.php` in the same change. `php -r` over `array_keys` of both files is the quick
parity check; the test suite does it as well.

**Tests.** Add a `check()` line in the block of the feature, placed where the data it
needs still exists (the tests run in order; accounts and offers are deleted later in
the file). Compare counts, not absolute numbers, when earlier checks have written
data. A check that cannot fail is worse than none.

### First setup of subscriptions, step by step

1. **Administration → Modules:** check that "Subscriptions" is on.
2. **Administration → Subscriptions → Details for the invoices:** name, address,
   VAT ID, VAT rate. Without these nothing is sold.
3. The same page, **Bank account for transfers:** IBAN (and BIC) of the account
   the buyers pay into.
4. Under **Payment methods:** switch on bank transfer and/or Stripe, and give the
   platform's Stripe keys if Stripe is used.
5. Create a plan: name, key, price, currency, period, the features it includes.
   A feature key can be typed in by hand.
6. Test it yourself first: order a plan with a test account, confirm the transfer
   under **Open transfers**, and check the invoice under **Invoices** and in the
   account's **My subscription**.

### Offers for providers

A provider opens **Account → My offers → New offer** and chooses the type. The
wizard asks in turn for the languages, the category, title and short text, the
description, the type's own fields, and then shows a review. The offer is saved
as a draft; pictures are added on its edit page, and **submit** sends it for review.

### Open points

Known and not done yet, in order of how much they matter before selling:

- Real Stripe subscriptions and the bank transfer have not been run against a live
  account; the tests use a stand-in for Stripe.
- No feature is gated by a subscription plan yet; the mechanism is there
  (`feature:<key>` routes, `feature()` in templates), the choice of features is not.
- Editing an existing offer is still one page; the six-step wizard is for new offers.
- Freelancer packages have been checked up to the form, not up to the save through
  the wizard.
- The offer header says "ab" (from) for auction lots; for a lot it is the current price.

### Rules that come from earlier mistakes

- A value that is not a plain string (an array in a form) is refused or ignored, never
  written into HTML or SQL unchecked.
- A text a visitor writes is cleaned on the way in, and the cleaned version is what is
  shown; the list of words is checked on the way in as well.
- Cache busting: a file under `public/assets/` that changes gets a new `?v=` on every
  reference, or returning visitors keep the old copy for a year.
- Templates in themes only. The core passes data; the markup is the theme's.
- Interface version 1 is frozen: extensions depend on it (see "Interface version 1").
  Adding is fine, changing or removing is a major version.

## Packages

Only one extension is active at a time. Enabling one (under **Administration →
Extensions**, or on the Packages page) switches the others off; their tables and
data stay. A theme is chosen the same way: one at a time.

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
| `review/_rating.twig` | `rating` (`count`, `average`): stars with a text alternative - reused for an account's direct-rating average too |
| `review/_list.twig` | `reviews`: `author` (empty for "a buyer"), `rating`, `body`, `locale`, `created_at`, `reply`; keep the note on where reviews come from |
| `review/_account_rating_list.twig` | `account_ratings`: `author` (empty for "an account"), `rating`, `body`, `locale`, `created_at`, `reply`; optional `can_reply_to` (rating ids still open for a reply) |
| `account/ratings.twig` | the logged-in account's own "about me": `account_ratings` (every status, including hidden with its reason), `can_reply_to`, `page`, `pages` |
| `provider/index.twig` | `providers`, `page`, `pages` |
| `provider/show.twig` | `provider`: `name`, `path`, `type`, `headline`, `description` (plain text), `city`, `country`, `legal` (only for a business); block `offers` for extensions |
| `emails/*.txt.twig` | blocks `subject` and `body`; plain text, not HTML-escaped |

E-mails are theme templates too: `verify_email`, `reset_password`,
`already_registered`, `change_email`, `password_changed`, `provider_approved`,
`provider_rejected`, `provider_suspended`, `account_blocked`, `offer_published`,
`offer_rejected`, `offer_contact`, `order_update`, `order_message`, `order_paid`, `review_new`,
`review_reply`, `review_hidden`, `account_rating_new`, `account_rating_reply`, `account_rating_hidden`,
`withdrawal_receipt`, `withdrawal_provider`,
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
| `module(id)` | Whether an optional function of the core is on: `reviews`, `contact`, `withdrawal`, `reports`, `avatars`, `remember_login`, `subscriptions`, `inbox`, `notifications` - a theme hides what belongs to a module that is off |
| `has_catalogue()` | Whether an extension adds a kind of offer; without one, hide the links to offers and providers |
| `nav_links()` | Entries extensions add to the main menu, as `title`/`url`/`path` |
| `account_links()` | Entries extensions add to the logged-in account's own navigation (`account/_nav.twig`), as `title`/`url` |
| `home_sections()` | What extensions want shown on the home page: `{% for section in home_sections() %}{% include section.template with section.data %}{% endfor %}` |
| `categories()` | The category tree with `name`, `path`, `children` and `offer_count` |
| `top_providers(limit)` | Public providers, best rated first, as shown on `provider/show.twig` |
| `extension_blocks` (only on `provider/show.twig`) | What `providerSection()` added for this provider, already resolved for it (unlike `home_sections()`, this needs the specific provider, so it is a template variable, not a global function): `{% for block in extension_blocks %}{% include block.template with block.data %}{% endfor %}` |
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

### Editing pages in place

An administrator with the settings permission sees a page's blocks as tools when
the page is opened with `?edit=1` (a link at the top of the home page and the
offer page). The tools come from the administration theme (`inline/_bar.twig`,
`inline/_block.twig`); the site theme only has to draw its blocks in a loop, so
that **any theme** can take part. Every control is a small form that sends the
page's own administration action and comes back to the page, so it works without
scripts, on a phone too. The site's own text is still edited in the text fields
of the block.

On the home page and on the offer page the texts can be changed where they stand: a click on a text
makes it editable, leaving it saves it, Escape undoes. A text is editable when its element carries
`data-field="<name>"` inside the block (`data-html` for rich text). Between the blocks a "+" offers
the kinds of block to insert at that place.

Rich texts get a small toolbar while they are edited (paragraph, headings, bold, italic,
lists, quote, link). A text block can carry a button (`button_label`, `button_url`), which
is the usual way to make a text a call to action. A block can be duplicated, and moved by dragging its handle (⠿); on a phone the arrows do the same. A content page
is edited the same way: its title and body (`data-field`), on its own page with `?edit=1`. Both work without scripts too, through the forms.

A theme that wants editing in place does three things in each page it shows:

```twig
{% include '@admin/inline/_bar.twig' with {page_path: '/admin/home', add_types: ['hero', 'text', 'offers', 'providers', 'image', 'links']} only %}
{% for block in home_blocks(edit_mode()) %}
    {% if edit_mode() %}
        {% include '@admin/inline/_block.twig' with {block: block, page_path: '/admin/home', fields: block_fields('home')[block.type] ?? [], label_key: 'core.home.type.' ~ block.type, deletable: true} only %}
    {% endif %}
    {% if block.enabled %}
        {% include 'home/_' ~ block.type ~ '.twig' with {block: block} %}
    {% endif %}
{% endfor %}
```

1. It loops the blocks with `home_blocks(edit_mode())` (or `offer_blocks(...)`), so
   hidden blocks are there while editing and only then.
2. While `edit_mode()` is true, it includes `@admin/inline/_block.twig` (tools, the
   block with its partial, and the "+") instead of the partial alone.
3. Otherwise it draws a block only when `block.enabled`.
4. Its partials mark their texts with `data-field` (see above).

The layout loads the editor's script with `{% if edit_mode() %}<script src="{{ admin_asset('editor.js') }}" defer></script>{% endif %}`
(the default theme does this in `layout/base.twig`). A theme that does none of
this keeps working; it just cannot be edited in place, and the forms under
**Administration → Home page** and **Offer page** still work.

The themes shipped with the core and their status: `default` and Indigo (its own
repository, from 0.2.6 on) have the block loop on the home page; the offer page
is drawn by the default theme and is editable with every theme. The pencil in
the header (for accounts that may edit, on the home page and on an offer) is in
the account menu partial; the rules for it and for the editing tools live in
the core's stylesheet (`/design/<name>.css`), so every theme shows them alike.

**Widgets.** A widget is a ready-made block that is inserted from the "+" between
the blocks. The core brings `cta`, `note`, `benefits`, `faq` and `more` (on the home
page; the offer page offers the text ones). Any block can be saved as a widget of
one's own under "Als Widget speichern" (a name of up to 60 characters, at most 50
own widgets). Own widgets are listed in the page's editor, where they can be
removed; the list is stored in `core.widgets`. The shipped texts are in
`core/src/Content/Widgets.php` and their labels are the `core.widget.*` keys.

**A theme's own start page.** A site that has no start page of its own shows
what its theme brings in `home-layout.json` (the same structure as the stored
layout: blocks with `id`, `type`, `enabled`, `texts` per language and `settings`).
The Indigo theme ships its start page this way. Once an administrator saves the
page, the stored one applies.

**A browser test.** `tests/browser/scenario.py` drives a headless Chrome through the
editing and sign-in flows (see its header). It is not part of `tests/run.php`; it
needs Chrome and a throwaway installation, because it changes data.

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
in the header. With the `inbox` module on, an envelope link
(`<a class="inbox-link">`, right before the account menu) leads to
`/account/messages` and carries the `data-unread` badge - the markup
`unread.js` polls (see "Available in every template", `poll_seconds()`).
With the `notifications` module on, a second such link (bell, same
`inbox-link` class) leads to `/account/notifications`, polling its own
`data-unread` badge against `/account/notifications/unread` -
`[data-unread]` elements are handled generically, so a theme can place as
many as it wants.

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
| `providerSection(template, data)` | A template of the extension included on a provider's public profile, e.g. a freelancer's skills and portfolio; `data` is a `Closure(array $provider, App $app): array` that gets the provider row and supplies the template's variables. Return `[]` to show nothing for a provider who has not filled anything in |
| `accountLink(labelKey, path)` | An entry in the logged-in account's own navigation (`account/_nav.twig`), e.g. a link to a profile page the extension adds |
| `listen(EventClass, fn ($event, App $app) => ...)` | React to a core or extension event |
| `task(name, everyMinutes, fn (App $app) => ...)` | Scheduled work, run by `bin/cron.php` |
| `offerType(OfferType)` | A kind of offer for the catalogue, see below |
| `orderFlow(OrderFlow)` | How offers of a type are ordered and carried out, see below |
| `paymentMethod(PaymentMethod)` | A way to pay, e.g. a payment service |
| `module(id, labelKey)` | An optional feature of the extension, switched off and on under Administration → Modules exactly like a core module; `labelKey` needs both `<labelKey>.name` and `<labelKey>.description` in the extension's own `lang/` files. Only ever listed while the extension itself is active, since this call is what adds it |
| `moduleEnabled(id)` | Whether a module - this extension's own, another's, or a core one - is currently switched on; call right after `module()` to decide what else to register (routes, menu entries, …) for that feature |

Core services an extension uses instead of SQL on core tables, all on the
`App` object: `accounts` (find, create, change accounts), `tokens` (one-time
links), `mailer` (`send(to, '@<id>/emails/x.txt.twig', data, locale)`),
`notifications` (`create(accountId, type, messageKey, params, link)` - an
in-app notice behind the header's bell, next to whatever e-mail the same
moment already sends; a no-op while the `notifications` module is off, so
nothing needs to check that itself), `settings`, `locales`, `pages`,
`providers`, `offers`, `categories`, `offerImages`, `orders`, `roles`,
`auth`, `events`, and `url()`.

### Creating an offer

A new offer is made in six steps: the languages (the own one is always in), the
category, title and short text, the description, the fields of the offer's type,
and a review that saves the offer as a draft. The steps keep their answers in the
session until the review saves them through the same save as the form of an offer;
the form for editing an existing offer stays one page.

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

Account ratings (`$app->accountRatings`) are a second, independent rating:
one account's opinion of another account directly, not of one order. They
live in their own table (`account_rating`, one row per rater/rated pair) and
their own columns on `account` (`rating_count`, `rating_sum`), separate from
a review's order and provider. Who may rate whom is decided by
`AccountRatings::canRate()` alone, not by a database constraint: today it
requires a real order between the two accounts, in either direction (buyer
and the account behind a provider profile), which is why the order page is
the only place that offers the form. A context with its own notion of a
genuine encounter - a dating extension, say - can call `create()` directly
after checking eligibility its own way, without any change to this table or
service. One public reply, hiding with a reason, GDPR export/anonymise and
mail notifications all work exactly as they do for reviews.

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
content, profile pictures, "stay logged in" and subscriptions. A module that is off has no
routes, menu entries or links; its data stays and is back when it is switched
on again (`$app->modules->enabled('reviews')`, in templates `module('reviews')`).
The withdrawal and the report form are legal duties in many cases - switch
them off only where they do not apply. An active extension can list an
optional feature of its own here too (`Registrar::module()`, see "Writing an
extension") - it appears and disappears with the extension itself.

**Subscriptions** (the module "Subscriptions") sell plans to accounts: a plan
has a price, a period and a list of features, and an account has one plan at a
time. An extension asks `$app->subscriptions->allows($accountId, 'feature')`
before it grants a feature that is sold. Switched off, every feature is open to
everyone; switched on, a feature needs a plan that lists it. So an extension
checks only features that are sold, and only once the module is in use. The
operator creates plans and grants them under **Administration → Subscriptions**
(in the marketplace group, shown only while the module is on). A plan granted by
hand has no end, or an end date that counts to its last day. A plan is changed
there, with the features it includes; a plan that has had accounts can only be
switched off, not deleted. Customers see the active plans on **/subscriptions**
and their own on **/account/subscription** (in the account menu). Customers pay for
a plan on **/subscriptions**: by bank transfer to the operator's account (set under
**Administration → Subscriptions → Bank account for transfers**; the operator then
confirms the money under "Open transfers", which gives the plan for its months), or
by Stripe as a subscription on the platform's own Stripe account (the keys are the
ones of **Payment methods**). Stripe's notifications for subscriptions are
`checkout.session.completed` (mode subscription), `invoice.paid`,
`invoice.payment_failed` and `customer.subscription.deleted`; they must reach the
same `/webhooks/stripe` endpoint as platform events, not only Connect events.
Orders of providers keep their own payment flow; only subscriptions are paid to the
operator.

Before a plan can be sold, the operator enters the seller's details under the same
page (name, address, VAT ID, VAT rate in percent, a note such as the small business
rule). A buyer gives a billing address on the checkout page, which is kept for the
next orders. Every payment gets a numbered invoice (`RE-<year>-<number>`), without
gaps per year, with the VAT taken out of the gross price; it is mailed as a link
and shown under **My subscription** and in the administration. The details and the
VAT rate of an invoice stay as they were when it was issued. A transfer subscription
that ends within a week gets one reminder mail (task `core.subscription-reminders`);
a renewal ordered before the end starts when the current plan ends.

Invoices are a tool, not legal advice: which details an invoice must show, and
whether the VAT rate applies, is for the operator to check with a tax adviser.

An extension declares the features it sells in its `register()` with
`$registrar->subscriptionFeature('visitenkarte.logo', 'visitenkarte.feature.logo')`,
where the last argument is a language key. The operator then ticks the features
per plan. A feature no extension has declared can still be typed in by hand.

A route is unlocked by a feature with the access `feature:<key>`, e.g.
`$router->post('/visitenkarte/logo', $handler, 'feature:visitenkarte.logo')`: an
account whose plan does not list the feature gets a refusal, a visitor none. In a
template, `{% if feature('visitenkarte.logo') %}` shows the control only where it
works. The route check is the one that counts; a hidden control alone protects
nothing. Switched off, the module leaves every feature open.

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
- the `Registrar` methods `routes`, `permission`, `adminMenu`, `navigation`, `homeSection`,
  `providerSection`, `accountLink`, `listen`, `task`, `offerType`, `orderFlow`, `paymentMethod`,
  `module`, `moduleEnabled`, and its `manifest`
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
