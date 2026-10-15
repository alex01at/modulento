<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use Modulento\Core\App;
use PDO;

/**
 * A handful of accounts, provider profiles, requests and (where an
 * installed extension's offer type takes part, see Registrar::demoOffers())
 * offers, so a fresh installation has something to look at - all of it
 * marked is_demo (migration 036) and owned by demo accounts, so removing it
 * again is the one statement clear() runs: the foreign keys do the rest.
 *
 * Never run automatically; only Administration → Demo data calls this.
 */
final class DemoData
{
    /**
     * @var list<array{email: string, name: string, locale: string, role: string, provider_type: ?string}>
     * role "provider" becomes an approved provider profile; "customer" may get a demo request.
     */
    private const PEOPLE = [
        ['email' => 'demo.mara@example.test', 'name' => 'Mara Nowak', 'locale' => 'de', 'role' => 'provider', 'provider_type' => 'private'],
        ['email' => 'demo.thomas@example.test', 'name' => 'Thomas Vogel', 'locale' => 'de', 'role' => 'provider', 'provider_type' => 'private'],
        ['email' => 'demo.studio@example.test', 'name' => 'Studio Lichtblick GmbH', 'locale' => 'de', 'role' => 'provider', 'provider_type' => 'business'],
        ['email' => 'demo.elena@example.test', 'name' => 'Elena Sokolova', 'locale' => 'en', 'role' => 'provider', 'provider_type' => 'private'],
        ['email' => 'demo.jonas@example.test', 'name' => 'Jonas Richter', 'locale' => 'de', 'role' => 'customer', 'provider_type' => null],
        ['email' => 'demo.sarah@example.test', 'name' => 'Sarah Weiß', 'locale' => 'de', 'role' => 'customer', 'provider_type' => null],
    ];
    private const CITIES = [
        ['street' => 'Bahnhofstraße 12', 'postal_code' => '10115', 'city' => 'Berlin', 'country' => 'DE'],
        ['street' => 'Hauptstraße 44', 'postal_code' => '20095', 'city' => 'Hamburg', 'country' => 'DE'],
        ['street' => 'Mariahilfer Straße 7', 'postal_code' => '1060', 'city' => 'Wien', 'country' => 'AT'],
        ['street' => 'Bahnhofplatz 3', 'postal_code' => '8001', 'city' => 'Zürich', 'country' => 'CH'],
    ];
    private const HEADLINES = [
        'de' => 'Zuverlässig, pünktlich, mit Blick fürs Detail.',
        'en' => 'Reliable, on time, with an eye for detail.',
    ];
    private const DESCRIPTIONS = [
        'de' => "Seit mehreren Jahren für Kundinnen und Kunden unterwegs. Jedes Projekt bekommt die gleiche Sorgfalt, ob groß oder klein.\n\nDies ist ein Demo-Profil zu Vorführzwecken.",
        'en' => "Working with clients for several years. Every project gets the same care, big or small.\n\nThis is a demo profile for showcase purposes.",
    ];
    private const REQUEST_TITLES = [
        'de' => 'Ich suche Unterstützung für ein kleines Projekt',
        'en' => 'Looking for help with a small project',
    ];
    private const REQUEST_BODIES = [
        'de' => "Ich habe eine klare Vorstellung, brauche aber jemanden, der sie umsetzt. Gerne unverbindlich melden.\n\nDies ist eine Demo-Anfrage zu Vorführzwecken.",
        'en' => "I have a clear idea but need someone to carry it out. Feel free to get in touch, no obligation.\n\nThis is a demo request for showcase purposes.",
    ];

    public function __construct(private App $app)
    {
    }

    /** @return array{accounts: int, providers: int, offers: int, requests: int} */
    public function counts(): array
    {
        $db = $this->app->db;

        return [
            'accounts' => (int) $db->query('SELECT COUNT(*) FROM account WHERE is_demo = 1')->fetchColumn(),
            'providers' => (int) $db->query('SELECT COUNT(*) FROM provider WHERE is_demo = 1')->fetchColumn(),
            'offers' => (int) $db->query('SELECT COUNT(*) FROM offer WHERE is_demo = 1')->fetchColumn(),
            'requests' => (int) $db->query('SELECT COUNT(*) FROM request WHERE is_demo = 1')->fetchColumn(),
        ];
    }

    /**
     * Adds one batch of demo data. Does nothing if some is already there -
     * clear it first to generate a fresh batch.
     *
     * @return bool whether anything was generated
     */
    public function generate(): bool
    {
        $app = $this->app;
        if ($this->counts()['accounts'] > 0) {
            return false;
        }

        $categoryId = (int) ($app->db->query('SELECT id FROM category ORDER BY id LIMIT 1')->fetchColumn() ?: 0) ?: null;
        $requestsOn = $app->modules->enabled('requests');
        $generators = $app->demoOfferGenerators();

        foreach (self::PEOPLE as $i => $person) {
            $password = bin2hex(random_bytes(16));
            $accountId = $app->accounts->create($person['email'], $password, $person['locale'], true, true);
            $app->accounts->updateProfile($accountId, $person['name'], $person['locale']);
            $app->db->prepare('UPDATE account SET is_demo = 1 WHERE id = :id')->execute(['id' => $accountId]);

            if ($person['role'] === 'provider') {
                $address = self::CITIES[$i % count(self::CITIES)];
                $values = [
                    'type' => $person['provider_type'],
                    'name' => $person['name'],
                    'legal_name' => $person['name'],
                    'street' => $address['street'],
                    'postal_code' => $address['postal_code'],
                    'city' => $address['city'],
                    'country' => $address['country'],
                    'contact_email' => $person['email'],
                    'phone' => '',
                    'vat_id' => '',
                    'tax_id' => '',
                    'company_register' => '',
                    'self_certified' => true,
                ];
                $texts = [];
                foreach ($app->locales->enabled() as $locale) {
                    $texts[$locale] = [
                        'headline' => self::HEADLINES[$locale] ?? self::HEADLINES['de'],
                        'description' => self::DESCRIPTIONS[$locale] ?? self::DESCRIPTIONS['de'],
                    ];
                }
                $saved = $app->providers->save($accountId, $values, $texts);
                $app->providers->setStatus($saved['id'], 'approved', null, null);
                $app->db->prepare('UPDATE provider SET is_demo = 1 WHERE id = :id')->execute(['id' => $saved['id']]);

                foreach ($generators as $generate) {
                    for ($n = 0; $n < 2; $n++) {
                        $offerId = $generate($app, $saved['id'], $categoryId);
                        if ($offerId !== null) {
                            $app->db->prepare('UPDATE offer SET is_demo = 1 WHERE id = :id')->execute(['id' => $offerId]);
                        }
                    }
                }
            } elseif ($requestsOn) {
                $locale = $person['locale'];
                $requestId = $app->requests->create(
                    $accountId,
                    $categoryId,
                    self::REQUEST_TITLES[$locale] ?? self::REQUEST_TITLES['de'],
                    self::REQUEST_BODIES[$locale] ?? self::REQUEST_BODIES['de'],
                    10000,
                    50000,
                    null
                );
                $app->requests->setStatus($requestId, 'published', null, null);
                $app->db->prepare('UPDATE request SET is_demo = 1 WHERE id = :id')->execute(['id' => $requestId]);
            }
        }

        return true;
    }

    /** Removes every piece of demo data. Deleting the demo accounts is enough - everything else is theirs, by a foreign key. */
    public function clear(): void
    {
        $this->app->db->exec('DELETE FROM account WHERE is_demo = 1');
    }
}
