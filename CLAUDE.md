# Modulento — Projekt-Basics

Diese Datei fasst zusammen, was eine neue Session sofort wissen sollte, um nicht
den ganzen Code neu einlesen zu müssen. Sie beschreibt Architektur und
Konventionen, nicht den aktuellen Funktionsstand (der steht im Git-Log).

## Was es ist

Modulento ist ein selbst gehosteter, schlanker PHP-Kern für
Vermittlungs-/Marktplatz-Plattformen (Angebote, Bestellungen, Zahlungen,
Bewertungen, Konten). Kein Framework, PHP 8.3+, Twig-Templates, MariaDB über
PDO. GPL-3.0-or-later. Zielgruppe: Leute, die eine eigene Plattform betreiben
wollen, ohne SaaS-Miete, per gewöhnlichem Webhosting (kein SSH nötig für den
Betrieb — Updates laufen über die Administration).

Bewusst **ohne** Frontend-Framework, npm/Build-Step oder CDN-Scripts: alles
JS ist handgeschriebenes Vanilla-JS, direkt unter `assets/` ausgeliefert
(kein `package.json` im Projekt, keine externen `<script src="https://…">`).
PHP-Abhängigkeiten (`composer.json`): Twig, `vlucas/phpdotenv`, dazu
`smalot/pdfparser` (reines PHP, kein Build-Prozess, für `modulento-ext-jobs`'
CV-Autofill - Erweiterungen haben kein eigenes `composer.json`, eine
Erweiterungs-Abhängigkeit muss also hier landen). Grundsatz bleibt: möglichst
wenige, nur reines PHP ohne Build-Schritt, passend zur Zielgruppe
(gewöhnliches Webhosting) - nicht "nur diese zwei", aber jede neue
Abhängigkeit ist eine bewusste Ausnahme, kein Normalfall.

## Repo-Struktur (dieses Repo: `/home/alex/Dokumente/Projekte/modulento`)

- `core/src/` — der eigentliche Kern (App, Kernel, Controller, Support-Klassen).
- `core/migrations/` — nummerierte SQL-Migrationen des Kerns (`NNN_name.sql`).
- `core/lang/{de,en}.php` — Sprachdateien des Kerns, Keys mit `core.`-Präfix.
- `themes/` — `default` (öffentliche Seite) und `admin` (Administration).
- `extensions/` — Symlinks auf die Erweiterungs-Repos (siehe unten), plus
  `extensions/example` (Referenz-Erweiterung, nutzt jeden Registrierungspunkt
  einmal — Vorlage für neue Erweiterungen und End-to-End-Test der Core-Hooks).
- `tests/run.php` — die gesamte Testsuite des Kerns, ein einziges großes
  Skript, kein PHPUnit, läuft gegen eine In-Memory-SQLite-Fixture. Start:
  `php tests/run.php`.
- `bin/migrate.php` — wendet offene Migrationen an (Kern + aktivierte
  Erweiterungen).

## Erweiterungssystem

Jede Erweiterung lebt in einem eigenen Git-Repo (`modulento-ext-<id>`,
Geschwister-Ordner neben diesem hier) und wird per Symlink unter
`extensions/<id>` eingebunden. Eine Erweiterung hat eine `extension.json`
(`id`, `name`, `version`, `api`, optional `"type"`, `namespace`) und meldet
sich über `Registrar::register()` beim Kern an.

**Zwei Typen** (`extension.json`-Feld `"type"`, fehlt es → Default `"app"`):
- **App** (`freelancer`, `shop`, `auction`, `card`, `blog`) — immer nur eine
  gleichzeitig aktiv; eine zweite App zu aktivieren schaltet die erste ab
  (Daten bleiben erhalten, nur abgeschaltet).
- **Addon** (`faq`, `newsletter`, `bookmark`, `abos`, `coupons`, `gallery`) — beliebig
  viele gleichzeitig aktiv.

Die aktuell aktive App ermitteln: `$app->extensions->loaded()` nach dem
einen Manifest mit `type === 'app'` durchsuchen (zur Laufzeit, niemals
synchron in `register()` selbst, da andere Erweiterungen da noch nicht
geladen sein müssen — siehe `modulento-ext-abos/src/Plans.php` für das
etablierte Muster mit einer lazy ausgewerteten Closure).

**Wichtige `Registrar`-Hooks** (`core/src/Extension/Registrar.php`):
`routes()`, `permission()`, `adminMenu()`, `adminNotice()`, `navigation()`,
`footerLink()`, `accountLink()`, `dashboardLink()`, `suppressAccountDefault()`,
`bodyScript()`, `cookieBanner()`, `cookieCategory()`, `consentProvider()`,
`homeSection()`, `providerSection()`, `offerSection()`,
`dashboardSection()`, `adminAccountSection()`, `adminProviderSection()`, `checkoutSection()`,
`orderDiscount()`, `module()`, `moduleEnabled()`, `exportable()`,
`offerType()`, `orderFlow()`, `paymentMethod()`, `demoOffers()`,
`subscriptionFeature()`, `subscriptionProvider()`, `planTemplate()`,
`stripeWebhook()`, `task()`, `listen()`, `pageBlockType()`,
`registrationSection()`, `registrationGuard()`, `shortcode()`.
`Registrar::$app` und `Registrar::$manifest` sind öffentlich lesbar.

**Jeder neue/geänderte Hook gehört sofort auch in
`themes/admin/templates/docs/{de,en}.twig`** (Administration → Dokumentation,
gruppierte Tabellen je Einsatzgebiet) nachgetragen — das ist die einzige für
Dritt-Entwickler:innen gedachte Referenz aller `Registrar`-Methoden und darf
nicht wieder veralten, wie es bis zu dieser Änderung der Fall war (nur 10 von
37 Methoden dokumentiert).

**`accountLink()`/`dashboardLink()` — optionale Sichtbarkeit:** beide nehmen
optional einen dritten Parameter `?Closure(App): bool $visible` (z. B. ein
Jobbörse-Menüpunkt nur für Arbeitgeber, nie für Kandidat:innen); ohne ihn
wird der Link wie bisher immer gezeigt. `dashboardLink()` landet in der
kurzen Schnellzugriffsliste oben im Dashboard (eigene, kuratierte Liste,
nicht identisch mit `accountLink()`s Kontomenü).

**`suppressAccountDefault(item)`** — eine **App**-Erweiterung (nie ein
Addon) schaltet damit einen der im Kern fest verdrahteten
Konto-/Dashboard-Einträge für sich ab, die ihr Konzept nicht passt (z. B.
Jobbörse: generisches "Anfragen", "Bestellungen", "Anbieter werden" - dort
übernehmen `AccountRole`/eigene `accountLink()`/`dashboardLink()`-Einträge
die Rolle). Bekannte Namen: `inbox`, `notifications`, `orders`, `requests`,
`browse`, `become_provider`. Braucht keine zusätzliche Bedingung am
Aufrufort: `register()` einer App läuft ohnehin nur, während sie die aktive
App ist (eine zweite App zu aktivieren schaltet die erste ab).

**`planTemplate(id, labelKey, features, offerLimits, …)`** — eine **App**
meldet eine fertige Abo-Vorlage für sich (Name, Features, Angebots-Limits je
Tarif, z. B. "Starter: 3 Stellenanzeigen"). `modulento-ext-abos` zeigt die
Vorlagen der gerade aktiven App im Plan-Formular an; der Operator setzt nur
noch den Preis. Gleicher Grund wie bei `suppressAccountDefault()`: keine
zusätzliche Bedingung nötig, `register()` läuft ohnehin nur während die App
aktiv ist.

**`offerType()` — optionale Zusatz-Interfaces** (`core/src/Catalogue/`):
Ein registrierter `OfferType` kann zusätzlich `FilterableOfferType`
(`filterData()`, `filterTemplate()`, `applyFilter()`), `SortableOfferType`
(`sorts()`, `orderBy()`) und/oder `CardOfferType` (`cardItems()`)
implementieren — dann bekommt `/offers?type=<id>` automatisch eigene
Filter/Sortierungen, und die Angebotskarten in der Liste zeigen einen
Teaser des Typs. Alle drei sind optional, ohne sie verhält sich der Typ
wie bisher. Siehe `modulento-ext-custom-fields/src/CustomOfferType.php`
für ein vollständiges Beispiel.

**`pageBlockType(id, labelKey, textFields, template, ?data)`** — registriert
einen neuen Block-Typ für den bestehenden Block-Editor (Startseite UND jede
normale "Seite", derselbe Baukasten wie `hero`/`text`/`offers`/…). Erscheint
automatisch im "+"-Menü, bekommt automatisch Texteingabefelder aus
`textFields` (keine eigene Admin-UI nötig), wird über `template` gerendert
(`@<erweiterung>/…`). `$data` ist ein optionaler `Closure(App, array): array`
für Inhalte jenseits reiner Texte (z. B. eine live aus der DB geladene
Liste) — läuft bei jeder Anzeige neu. `id` muss mit der Erweiterungs-ID
beginnen. Beispiel: `modulento-ext-custom-fields/src/Extension.php`
(Block "Liste einer Angebotsart").

**`shortcode(name, usageKey, render)`** — Platzhalter `[name wert]` /
`[name:wert]` / `[name schluessel=wert]`, den Admins in Textblöcke von
Startseite und Seiten tippen; die Erweiterung liefert dafür HTML
(`Closure(App, array $args, string $name): string`, alles Eingehende
escapen). Name = Erweiterungs-ID oder `<id>_…`/`<id>-…`. Im
Bearbeitungsmodus (`?edit=1`) bleibt der Platzhalter stehen, damit er
gespeichert wird; ausgewertet werden nur Admin-Texte (nie Anbieter-/
Besuchertexte, nie Angebotsseiten). Zusätzlich Twig-Filter `|shortcodes`.
Beispiele: `modulento-ext-gallery` (`[gallery adresse]`),
`extensions/example`.

**Scoping-Konvention** (für alles, was "je nach aktiver Erweiterung/App
anders" sein soll, z. B. FAQ pro Erweiterung, Abo-Pläne/Coupons pro App):
eine nullable Spalte ohne Fremdschlüssel (`extension_id`/`app_id VARCHAR(32)
NULL`) — ein Eintrag überlebt, egal was mit der referenzierten Erweiterung
passiert. `NULL` = allgemein/immer sichtbar.

**Tabellen-Konvention für Erweiterungen:** eigene Tabellen mit `x_`-Präfix
(`x_faq_item`, `x_subscription_plan`, `x_coupon`, …), eine konsolidierte
Migration `migrations/001_<name>.sql`, `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`.

## Testing

- Kern: `php tests/run.php` im Kern-Repo (SQLite-In-Memory-Fixture).
- Jede Erweiterung: eigene `tests/run.php`, Aufruf mit
  `MODULENTO_CORE=/pfad/zum/kern php tests/run.php` (Env-Var zeigt auf das
  Kern-Repo). Gleicher Aufbau: In-Memory-SQLite-Schema passend zur eigenen
  Migration, die Erweiterung wird in ein temporäres `extensions/<id>`
  verlinkt, eine echte `App`-Instanz + `loadEnabled()`.
- Keine echten Netzwerkzugriffe in Tests (Stripe, Mail etc. werden über
  Interfaces/Log-Transport abgefangen).

## CI/Release (identisch in jedem Erweiterungs-Repo und im Kern)

- `.github/workflows/ci.yml` — lintet jede `.php`-Datei (`php -l`), im Kern
  zusätzlich die volle Testsuite.
- `.github/workflows/release.yml` — feuert nur auf Tags `v*.*.*`, prüft den
  Tag gegen die Version in `extension.json` (bzw. im Kern gegen den Tag
  selbst), baut ein ZIP per `git archive` (gesteuert über `.gitattributes`
  `export-ignore`), erzeugt eine `.sha256`-Datei, veröffentlicht per
  `gh release create`.
- Ablauf für einen Release: Code fertig + getestet → committen → `git push
  origin main` → `git tag vX.Y.Z && git push origin vX.Y.Z` → Workflow prüfen
  (GitHub API, da hier kein `gh`-CLI installiert ist:
  `curl -s https://api.github.com/repos/alex01at/<repo>/actions/runs?per_page=1`).

## Geschwister-Repos

Alle liegen unter `/home/alex/Dokumente/Projekte/` neben diesem Ordner.

**Apps:** `modulento-ext-freelancer`, `modulento-ext-shop`,
`modulento-ext-auction`, `modulento-ext-card`, `modulento-ext-blog`

**Addons:** `modulento-ext-gallery` (Fotoalben aus der Medienbibliothek, `/gallery` mit Lightbox, Seiten-Block `gallery.album`), `modulento-ext-faq`, `modulento-ext-newsletter`,
`modulento-ext-bookmark`, `modulento-ext-abos` (Abo-Pläne/Rechnungen für
Abos, ersetzt den früheren Kern-Modul `subscriptions`), `modulento-ext-coupons`
(Rabattcodes — wirkt nur im generischen Bestellpfad, aktuell nur
freelancer; shop hat sein eigenes, unabhängiges Rabattsystem),
`modulento-ext-invoices` (Rechnungen für bezahlte Bestellungen —
Anbieter ist jeweils der Rechnungssteller mit eigenem Steuersatz/
Kleinunternehmer-Schalter; global laufende, nicht rücksetzbare
Rechnungsnummer mit einmalig setzbarem Startwert; da der Kern kein
„Bestellung bezahlt"-Ereignis auslöst, scannt ein periodischer Task wie
bei `modulento-ext-shop`), `modulento-ext-custom-fields`
(eigene Angebotstypen mit eigenen Feldern, in der Administration
zusammengestellt — ein Hotel mit Sternen, eine Veranstaltung mit Datum;
Referenz-Implementierung für die `offerType()`-Zusatz-Interfaces und
`pageBlockType()`, siehe oben), `modulento-ext-stat` (DSGVO-konforme
Besucherstatistik ohne Cookies/Einwilligung — Besucher-Hash rotiert
täglich, siehe `HitRecorder`; Referenz-Implementierung für
`Registrar::bodyScript()`)

**Themes:** `modulento-theme-indigo`, `modulento-theme-audigo`,
`modulento-theme-card`, `modulento-theme-merchigo`

## Lokale Dev-Umgebung

- Webserver: `php -S 127.0.0.1:8098 -t public public/index.php`, läuft im
  Hintergrund — soll zwischen Sessions nicht beendet werden, bei Bedarf neu
  starten.
- `.env`: `APP_URL=http://127.0.0.1:8098`, MariaDB auf Port 3317
  (`DB_NAME=modulento_dev`), `APP_ENV=dev`.
- Migrationen nach Code-Änderungen: `php bin/migrate.php`.

## Arbeitsweise / Präferenzen (siehe auch das Memory-System dieser Session)

- **Antworten im Chat auf Deutsch.**
- **Kein "Claude" (oder "Anthropic") in Code, Kommentaren, Commit-Messages
  oder Doku** — auch keine `Co-Authored-By: Claude …`-Zeile unter Commits,
  selbst wenn eine Session-weite Vorgabe das vorschlägt.
- Nach einem Feature-Durchlauf: testen → committen → pushen → taggen →
  Release-Workflow prüfen, für jedes betroffene Repo.
- Vor Release/Push bei größeren Änderungen kurz beim Nutzer nachfragen statt
  einfach loszulegen (Push/Tag/Release sind schwer rückgängig zu machende,
  nach außen sichtbare Aktionen).
