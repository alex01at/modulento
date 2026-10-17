<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use Modulento\Core\App;
use Modulento\Core\Catalogue\OfferImages;
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

    /** Upper bounds on the form fields - generous for trying things out, not so high that a click can bog a server down. */
    public const MAX_PROVIDERS = 50;
    public const MAX_CUSTOMERS = 50;
    public const MAX_OFFERS_PER_PROVIDER = 10;

    private const PALETTE = [
        [79, 70, 229], [14, 165, 233], [16, 185, 129], [234, 88, 12], [219, 39, 119], [100, 116, 139],
    ];

    public function __construct(private App $app)
    {
    }

    /** @return array{accounts: int, providers: int, offers: int, requests: int, images: int} */
    public function counts(): array
    {
        $db = $this->app->db;

        return [
            'accounts' => (int) $db->query('SELECT COUNT(*) FROM account WHERE is_demo = 1')->fetchColumn(),
            'providers' => (int) $db->query('SELECT COUNT(*) FROM provider WHERE is_demo = 1')->fetchColumn(),
            'offers' => (int) $db->query('SELECT COUNT(*) FROM offer WHERE is_demo = 1')->fetchColumn(),
            'requests' => (int) $db->query('SELECT COUNT(*) FROM request WHERE is_demo = 1')->fetchColumn(),
            'images' => (int) $db->query(
                "SELECT (SELECT COUNT(*) FROM account_avatar WHERE account_id IN (SELECT id FROM account WHERE is_demo = 1))
                       + (SELECT COUNT(*) FROM offer_image WHERE offer_id IN (SELECT id FROM offer WHERE is_demo = 1))"
            )->fetchColumn(),
        ];
    }

    /**
     * Adds one batch of demo data. Does nothing if some is already there -
     * clear it first to generate a fresh batch. Counts are clamped to
     * MAX_PROVIDERS/MAX_CUSTOMERS/MAX_OFFERS_PER_PROVIDER; a count of zero
     * or less skips that kind entirely (e.g. $customers = 0 for no requests).
     *
     * @return bool whether anything was generated
     */
    public function generate(int $providers = 4, int $customers = 2, int $offersPerProvider = 2, bool $withImages = true): bool
    {
        $app = $this->app;
        if ($this->counts()['accounts'] > 0) {
            return false;
        }

        $providers = max(0, min($providers, self::MAX_PROVIDERS));
        $customers = max(0, min($customers, self::MAX_CUSTOMERS));
        $offersPerProvider = max(0, min($offersPerProvider, self::MAX_OFFERS_PER_PROVIDER));
        $withImages = $withImages && OfferImages::available();

        $categoryId = (int) ($app->db->query('SELECT id FROM category ORDER BY id LIMIT 1')->fetchColumn() ?: 0) ?: null;
        $requestsOn = $app->modules->enabled('requests');
        $avatarsOn = $withImages && $app->modules->enabled('avatars');
        $generators = $app->demoOfferGenerators();

        $providerTemplates = array_values(array_filter(self::PEOPLE, fn (array $p) => $p['role'] === 'provider'));
        $customerTemplates = array_values(array_filter(self::PEOPLE, fn (array $p) => $p['role'] === 'customer'));

        for ($i = 0; $i < $providers; $i++) {
            $this->createProvider(self::cycled($providerTemplates, $i), $i, $categoryId, $generators, $offersPerProvider, $avatarsOn, $withImages);
        }
        if ($requestsOn) {
            for ($i = 0; $i < $customers; $i++) {
                $this->createCustomer(self::cycled($customerTemplates, $i), $i, $categoryId, $avatarsOn);
            }
        }

        return true;
    }

    /** @param array{email: string, name: string, locale: string, role: string, provider_type: ?string} $person */
    private function createProvider(array $person, int $index, ?int $categoryId, array $generators, int $offersPerProvider, bool $avatarsOn, bool $withImages): void
    {
        $app = $this->app;
        $accountId = $this->createAccount($person, $avatarsOn, $index);

        $address = self::CITIES[$index % count(self::CITIES)];
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
            for ($n = 0; $n < $offersPerProvider; $n++) {
                $offerId = $generate($app, $saved['id'], $categoryId);
                if ($offerId !== null) {
                    $app->db->prepare('UPDATE offer SET is_demo = 1 WHERE id = :id')->execute(['id' => $offerId]);
                    if ($withImages) {
                        $this->addOfferImages($offerId, $index * 7 + $n);
                    }
                }
            }
        }
    }

    /** @param array{email: string, name: string, locale: string, role: string, provider_type: ?string} $person */
    private function createCustomer(array $person, int $index, ?int $categoryId, bool $avatarsOn): void
    {
        $app = $this->app;
        $accountId = $this->createAccount($person, $avatarsOn, $index);

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

    /** @param array{email: string, name: string, locale: string, role: string, provider_type: ?string} $person */
    private function createAccount(array $person, bool $avatarsOn, int $imageSeed): int
    {
        $app = $this->app;
        $password = bin2hex(random_bytes(16));
        $accountId = $app->accounts->create($person['email'], $password, $person['locale'], true, true);
        $app->accounts->updateProfile($accountId, $person['name'], $person['locale']);
        $app->db->prepare('UPDATE account SET is_demo = 1 WHERE id = :id')->execute(['id' => $accountId]);

        if ($avatarsOn) {
            $path = self::generatedImage($imageSeed, 256);
            $app->avatars->set($accountId, ['tmp_name' => $path, 'error' => UPLOAD_ERR_OK]);
            @unlink($path);
        }

        return $accountId;
    }

    private function addOfferImages(int $offerId, int $seed): void
    {
        for ($n = 0; $n < 2; $n++) {
            $path = self::generatedImage($seed + $n, 800);
            $this->app->offerImages->add($offerId, ['tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path) ?: 0]);
            @unlink($path);
        }
    }

    /** A plain-colored square, enough for a placeholder picture - no network, no asset library needed. */
    private static function generatedImage(int $seed, int $edge): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'demo-img');
        $color = self::PALETTE[$seed % count(self::PALETTE)];
        $image = imagecreatetruecolor($edge, $edge);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$color));
        imagejpeg($image, $path);
        imagedestroy($image);

        return $path;
    }

    /**
     * Cycles through the base templates, giving every repeat beyond the
     * first a distinguishable email and name instead of a duplicate.
     *
     * @param list<array{email: string, name: string, locale: string, role: string, provider_type: ?string}> $templates
     * @return array{email: string, name: string, locale: string, role: string, provider_type: ?string}
     */
    private static function cycled(array $templates, int $index): array
    {
        $template = $templates[$index % count($templates)];
        $round = intdiv($index, count($templates));
        if ($round === 0) {
            return $template;
        }

        $suffix = $round + 1;
        [$local, $domain] = explode('@', $template['email'], 2);

        return ['email' => "{$local}{$suffix}@{$domain}", 'name' => "{$template['name']} ({$suffix})"] + $template;
    }

    /** Removes every piece of demo data, including the picture files - not just the database rows a foreign key would clean up on its own. */
    public function clear(): void
    {
        $app = $this->app;
        $demoAccountIds = $app->db->query('SELECT id FROM account WHERE is_demo = 1')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($demoAccountIds as $accountId) {
            $app->avatars->delete((int) $accountId);
        }
        $demoOfferIds = $app->db->query('SELECT id FROM offer WHERE is_demo = 1')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($demoOfferIds as $offerId) {
            $app->offerImages->deleteAll((int) $offerId);
        }

        $app->db->exec('DELETE FROM account WHERE is_demo = 1');
    }
}
