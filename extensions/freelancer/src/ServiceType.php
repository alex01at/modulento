<?php

declare(strict_types=1);

namespace Modulento\Freelancer;

use Modulento\Core\App;
use Modulento\Core\Catalogue\OfferType;
use Modulento\Core\Support\Money;

final class ServiceType implements OfferType
{
    public const TIERS = [1, 2, 3];
    public const MAX_EXTRAS = 3;

    private const MIN_PRICE = 100;
    private const MAX_PRICE = 10_000_000;

    public function id(): string
    {
        return 'freelancer.service';
    }

    public function labelKey(): string
    {
        return 'freelancer.type.service';
    }

    public function formTemplate(): string
    {
        return '@freelancer/offer_form.twig';
    }

    public function detailTemplate(): string
    {
        return '@freelancer/offer_detail.twig';
    }

    public function formData(?int $offerId, ?array $typed, App $app): array
    {
        $locale = $app->translator->locale();

        if ($typed !== null) {
            // Shown again after a failed validation: exactly what was typed.
            $packages = [];
            foreach (self::TIERS as $tier) {
                $packages[$tier] = is_array($typed['package'][$tier] ?? null) ? $typed['package'][$tier] : [];
            }
            $extras = [];
            for ($i = 0; $i < self::MAX_EXTRAS; $i++) {
                $extras[$i] = is_array($typed['extra'][$i] ?? null) ? $typed['extra'][$i] : [];
            }

            return [
                'tiers' => self::TIERS,
                'packages' => $packages,
                'extras' => $extras,
                'requirements' => is_array($typed['requirements'] ?? null) ? $typed['requirements'] : [],
                'locales' => $app->locales->enabled(),
            ];
        }

        $stored = $offerId !== null ? $this->load($offerId, $app) : ['packages' => [], 'extras' => [], 'requirements' => []];
        $packages = [];
        foreach (self::TIERS as $tier) {
            $package = $stored['packages'][$tier] ?? null;
            $packages[$tier] = $package !== null ? [
                'price' => Money::input($package['price'], $locale),
                'delivery_days' => $package['delivery_days'],
                'revisions' => $package['revisions'],
                'text' => $package['texts'],
            ] : [];
        }
        $extras = [];
        for ($i = 0; $i < self::MAX_EXTRAS; $i++) {
            $extra = $stored['extras'][$i] ?? null;
            $extras[$i] = $extra !== null ? [
                'price' => Money::input($extra['price'], $locale),
                'extra_days' => $extra['extra_days'],
                'text' => $extra['texts'],
            ] : [];
        }

        return [
            'tiers' => self::TIERS,
            'packages' => $packages,
            'extras' => $extras,
            'requirements' => $stored['requirements'],
            'locales' => $app->locales->enabled(),
        ];
    }

    public function validate(array $input, App $app): array
    {
        $errors = [];
        $locales = $app->locales->enabled();
        $packages = [];

        foreach (self::TIERS as $tier) {
            $raw = is_array($input['package'][$tier] ?? null) ? $input['package'][$tier] : [];
            $priceInput = trim((string) ($raw['price'] ?? ''));

            // The first package is the offer; the others are optional and
            // exist once they have a price.
            if ($priceInput === '' && $tier !== 1) {
                continue;
            }

            $price = Money::parse($priceInput);
            $days = (int) ($raw['delivery_days'] ?? 0);
            $revisions = (int) ($raw['revisions'] ?? 0);

            if ($price === null || $price < self::MIN_PRICE || $price > self::MAX_PRICE) {
                $errors[] = 'freelancer.error.price';
            }
            if ($days < 1 || $days > 365) {
                $errors[] = 'freelancer.error.delivery_days';
            }
            if ($revisions < 0 || $revisions > 99) {
                $errors[] = 'freelancer.error.revisions';
            }

            $texts = [];
            foreach ($locales as $locale) {
                $text = is_array($raw['text'][$locale] ?? null) ? $raw['text'][$locale] : [];
                $name = trim((string) ($text['name'] ?? ''));
                $description = trim(str_replace("\r\n", "\n", (string) ($text['description'] ?? '')));
                if ($name === '' && $description === '') {
                    continue;
                }
                if (mb_strlen($name) > 80 || mb_strlen($description) > 1000) {
                    $errors[] = 'freelancer.error.too_long';
                    continue;
                }
                $texts[$locale] = ['name' => $name, 'description' => $description];
            }

            $packages[$tier] = ['price' => (int) $price, 'delivery_days' => $days, 'revisions' => $revisions, 'texts' => $texts];
        }

        $extras = [];
        for ($i = 0; $i < self::MAX_EXTRAS; $i++) {
            $raw = is_array($input['extra'][$i] ?? null) ? $input['extra'][$i] : [];
            $priceInput = trim((string) ($raw['price'] ?? ''));

            $texts = [];
            foreach ($locales as $locale) {
                $title = trim((string) ($raw['text'][$locale]['title'] ?? ''));
                if ($title !== '') {
                    $texts[$locale] = ['title' => mb_substr($title, 0, 150)];
                }
            }
            if ($priceInput === '' && $texts === []) {
                continue;
            }

            $price = Money::parse($priceInput);
            $days = (int) ($raw['extra_days'] ?? 0);
            if ($price === null || $price < self::MIN_PRICE || $price > self::MAX_PRICE || $texts === [] || $days < 0 || $days > 365) {
                $errors[] = 'freelancer.error.extra';
                continue;
            }
            $extras[] = ['price' => $price, 'extra_days' => $days, 'texts' => $texts];
        }

        $requirements = [];
        foreach ($locales as $locale) {
            $text = trim(str_replace("\r\n", "\n", (string) ($input['requirements'][$locale] ?? '')));
            if ($text !== '') {
                $requirements[$locale] = mb_substr($text, 0, 2000);
            }
        }

        return [
            'values' => ['packages' => $packages, 'extras' => $extras, 'requirements' => $requirements],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    public function save(int $offerId, array $values, App $app): ?int
    {
        $db = $app->db;
        $db->beginTransaction();

        // The translation rows go with their parents (ON DELETE CASCADE).
        foreach (['x_freelancer_package', 'x_freelancer_extra', 'x_freelancer_requirement'] as $table) {
            $delete = $db->prepare("DELETE FROM {$table} WHERE offer_id = :id");
            $delete->execute(['id' => $offerId]);
        }

        $insertPackage = $db->prepare(
            'INSERT INTO x_freelancer_package (offer_id, tier, price, delivery_days, revisions) VALUES (:offer, :tier, :price, :days, :revisions)'
        );
        $insertPackageText = $db->prepare(
            'INSERT INTO x_freelancer_package_translation (package_id, locale, name, description) VALUES (:package, :locale, :name, :description)'
        );
        foreach ($values['packages'] as $tier => $package) {
            $insertPackage->execute(['offer' => $offerId, 'tier' => $tier, 'price' => $package['price'], 'days' => $package['delivery_days'], 'revisions' => $package['revisions']]);
            $packageId = (int) $db->lastInsertId();
            foreach ($package['texts'] as $locale => $text) {
                $insertPackageText->execute(['package' => $packageId, 'locale' => $locale] + $text);
            }
        }

        $insertExtra = $db->prepare('INSERT INTO x_freelancer_extra (offer_id, position, price, extra_days) VALUES (:offer, :position, :price, :days)');
        $insertExtraText = $db->prepare('INSERT INTO x_freelancer_extra_translation (extra_id, locale, title) VALUES (:extra, :locale, :title)');
        foreach ($values['extras'] as $position => $extra) {
            $insertExtra->execute(['offer' => $offerId, 'position' => $position, 'price' => $extra['price'], 'days' => $extra['extra_days']]);
            $extraId = (int) $db->lastInsertId();
            foreach ($extra['texts'] as $locale => $text) {
                $insertExtraText->execute(['extra' => $extraId, 'locale' => $locale] + $text);
            }
        }

        $insertRequirement = $db->prepare('INSERT INTO x_freelancer_requirement (offer_id, locale, text) VALUES (:offer, :locale, :text)');
        foreach ($values['requirements'] as $locale => $text) {
            $insertRequirement->execute(['offer' => $offerId, 'locale' => $locale, 'text' => $text]);
        }

        $db->commit();

        return $values['packages'] !== [] ? min(array_column($values['packages'], 'price')) : null;
    }

    public function detailData(int $offerId, string $locale, App $app): array
    {
        $stored = $this->load($offerId, $app);
        $default = $app->locales->default();
        $pick = fn (array $texts) => $texts[$locale] ?? $texts[$default] ?? (array_values($texts)[0] ?? []);
        $currency = $app->offers->find($offerId)['currency'] ?? $app->offers->currency();

        $packages = [];
        foreach ($stored['packages'] as $tier => $package) {
            $text = $pick($package['texts']);
            $packages[] = [
                'tier' => $tier,
                // A package without its own name is called by its tier.
                'name' => ($text['name'] ?? '') !== '' ? $text['name'] : $app->translator->trans('freelancer.tier.' . $tier),
                'description' => $text['description'] ?? '',
                'price' => $package['price'],
                'delivery_days' => $package['delivery_days'],
                'revisions' => $package['revisions'],
            ];
        }

        return [
            'currency' => $currency,
            'packages' => $packages,
            'extras' => array_map(fn (array $extra) => [
                'title' => $pick($extra['texts'])['title'] ?? '',
                'price' => $extra['price'],
                'extra_days' => $extra['extra_days'],
            ], $stored['extras']),
            'requirements' => $stored['requirements'][$locale] ?? $stored['requirements'][$default] ?? '',
        ];
    }

    /** @return array{packages: array<int, array>, extras: array<int, array>, requirements: array<string, string>} */
    private function load(int $offerId, App $app): array
    {
        $db = $app->db;

        $packages = [];
        $stmt = $db->prepare('SELECT * FROM x_freelancer_package WHERE offer_id = :id ORDER BY tier');
        $stmt->execute(['id' => $offerId]);
        $byId = [];
        foreach ($stmt->fetchAll() as $row) {
            $packages[(int) $row['tier']] = [
                'price' => (int) $row['price'], 'delivery_days' => (int) $row['delivery_days'], 'revisions' => (int) $row['revisions'], 'texts' => [],
            ];
            $byId[(int) $row['id']] = (int) $row['tier'];
        }
        $stmt = $db->prepare(
            'SELECT t.* FROM x_freelancer_package_translation t JOIN x_freelancer_package p ON p.id = t.package_id WHERE p.offer_id = :id'
        );
        $stmt->execute(['id' => $offerId]);
        foreach ($stmt->fetchAll() as $row) {
            $packages[$byId[(int) $row['package_id']]]['texts'][$row['locale']] = ['name' => $row['name'], 'description' => $row['description']];
        }

        $extras = [];
        $stmt = $db->prepare('SELECT * FROM x_freelancer_extra WHERE offer_id = :id ORDER BY position, id');
        $stmt->execute(['id' => $offerId]);
        $index = [];
        foreach ($stmt->fetchAll() as $row) {
            $index[(int) $row['id']] = count($extras);
            $extras[] = ['price' => (int) $row['price'], 'extra_days' => (int) $row['extra_days'], 'texts' => []];
        }
        $stmt = $db->prepare(
            'SELECT t.* FROM x_freelancer_extra_translation t JOIN x_freelancer_extra e ON e.id = t.extra_id WHERE e.offer_id = :id'
        );
        $stmt->execute(['id' => $offerId]);
        foreach ($stmt->fetchAll() as $row) {
            $extras[$index[(int) $row['extra_id']]]['texts'][$row['locale']] = ['title' => $row['title']];
        }

        $stmt = $db->prepare('SELECT locale, text FROM x_freelancer_requirement WHERE offer_id = :id');
        $stmt->execute(['id' => $offerId]);

        return ['packages' => $packages, 'extras' => $extras, 'requirements' => $stmt->fetchAll(\PDO::FETCH_KEY_PAIR)];
    }
}
