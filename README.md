# Modulento

A self-hosted core for transaction platforms. It knows accounts, roles,
content, extensions and scheduled tasks - but no business model of its own.
An extension turns an installation into whatever it needs to be: a
freelancer marketplace, an auction site, a shop, a restaurant ordering
system. Several can run side by side.

Plain PHP, no framework, no build step: PHP ≥ 8.3, Twig, MariaDB, vanilla
JS/CSS. Three rules shape everything:

- **No shell needed.** Installing, updating, enabling extensions, switching
  themes - all in the browser. The scripts in `bin/` are a convenience,
  never a requirement.
- **Templates are not part of the core.** The core hands data to templates;
  every page is rendered by a theme, swappable without touching PHP.
- **Every text exists per language**, from the interface down to e-mails
  and content pages - nothing visible is hardcoded.

Orders run as a state machine with history, messages and deadlines. Buyers
pay the provider directly - bank transfer, PayPal or Stripe, or whatever
the two agree - the platform never holds the money and takes no fee.

Two extensions show what the core carries, each in a repository of its own:
[`freelancer`](https://github.com/alex01at/modulento-ext-freelancer) (services
with packages) and [`auction`](https://github.com/alex01at/modulento-ext-auction)
(lots sold to the highest bidder) - installed from **Administration → Packages**,
no shell involved.

## Installing

Take `modulento-<version>.zip` from the [releases](https://github.com/alex01at/modulento/releases) and
unpack it onto a PHP 8.3+ host with a MySQL/MariaDB database - `vendor/` is
already in the package, Composer is not needed. Point the domain at
`public/`, open the site, and the installer takes it from there.

Full instructions, day-to-day running and everything an operator might ask
live in **the handbook**, shipped with every installation at `/handbook`,
in every language it offers.

## Building a theme or an extension

A theme only ever touches Twig and static assets; an extension registers
itself through one small interface - routes, menu entries, permissions,
its own tables - without ever editing a core file. The full reference,
with a minimal example extension to read alongside it
(`extensions/example`), lives at `/admin/docs` on a running installation.

## License

GPL-3.0-or-later, see `LICENSE`.
