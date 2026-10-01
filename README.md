# Modulento

Self-hosted core for transaction platforms. The core knows accounts, roles,
extensions and scheduled tasks, but no business model; an extension turns an
installation into a freelancer marketplace, a restaurant ordering system, an
auction site and so on.

Plain PHP without a framework and without a build step: PHP ≥ 8.3, Twig,
MariaDB through PDO, vanilla JS/CSS.

**Status: stage 1 of 7 (core skeleton).** There is no catalogue, order or
payment code yet.

Two rules shape everything:

- **No shell needed.** Installing, updating, enabling extensions, switching
  themes and running database updates all happen in the browser; files get
  onto the server by upload. The command line scripts in `bin/` are a
  convenience, never a requirement.
- **Templates are not part of the core.** The core hands data to templates;
  every page is rendered by a theme. See "Themes" below.

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
   `bin/cron.php`, or - where the panel can only call addresses - a secret
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
  extension's new migrations.

## Layout

```
core/         src/ (Modulento\Core), lang/, migrations/, install/ - no templates
extensions/   one folder per extension
themes/       default/ (site), admin/ (administration), further site themes
public/       web root: index.php only
bin/          migrate.php, cron.php, create-admin.php
.github/      CI and release workflows
var/          cache, logs, update backups - never reachable from the web
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

| Template name | Looked up in |
|---|---|
| `home.twig` | active site theme, then `themes/default` |
| `@admin/x.twig` | `themes/admin` |
| `@<ext>/x.twig` | `themes/<theme>/extensions/<ext>/`, then the extension's `templates/` |

Templates a site theme can provide: `layout/base.twig` (blocks `title`,
`head`, `content`), `home.twig`, `error.twig` (`status`, `message_key`),
`auth/login.twig`.

Available in every template:

| | |
|---|---|
| `trans(key, {placeholders})` | Text in the visitor's language |
| `theme_asset(path)`, `admin_asset(path)`, `ext_asset(id, path)` | URL of a file in an `assets/` folder, with cache busting |
| `csrf_field()`, `csrf_token()` | Required in every `POST` form or AJAX call |
| `can(permission)` | Whether the logged-in account has a permission |
| `money(cents, currency)` | Formatted amount |
| `account`, `locale`, `site_name`, `flashes`, `admin_menu` | Globals |

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
