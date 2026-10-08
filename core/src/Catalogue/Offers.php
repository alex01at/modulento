<?php

declare(strict_types=1);

namespace Modulento\Core\Catalogue;

use LogicException;
use Modulento\Core\Content\Pages;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Locales;
use Modulento\Core\Support\Settings;
use PDO;

/**
 * Offers of the catalogue and the types extensions registered for them.
 *
 * An offer is public only while its status is "published", its provider is
 * approved and the provider's account is active - PUBLIC_WHERE is that
 * rule, and every query for visitors uses it.
 */
final class Offers
{
    public const SETTING_APPROVAL = 'core.offer_approval';
    public const STATUSES = ['draft', 'pending', 'published', 'rejected', 'paused'];
    public const SORTS = ['newest', 'rating', 'price_low', 'price_high'];

    private const PUBLIC_WHERE = "o.status = 'published' AND p.status = 'approved' AND a.status = 'active'";
    private const FROM = 'FROM offer o JOIN provider p ON p.id = o.provider_id JOIN account a ON a.id = p.account_id';

    /** @var array<string, OfferType> */
    private array $types = [];

    public function __construct(private PDO $db, private Settings $settings, private Locales $locales)
    {
    }

    public function registerType(OfferType $type): void
    {
        if (isset($this->types[$type->id()])) {
            throw new LogicException('Offer type "' . $type->id() . '" is already registered');
        }

        $this->types[$type->id()] = $type;
    }

    /** @return array<string, OfferType> */
    public function types(): array
    {
        return $this->types;
    }

    /** Null when the extension that brought the type is switched off; such offers are kept but not shown. */
    public function type(string $id): ?OfferType
    {
        return $this->types[$id] ?? null;
    }

    public function approvalRequired(): bool
    {
        return $this->settings->get(self::SETTING_APPROVAL, 'required') !== 'off';
    }

    /**
     * Switching approval off publishes every offer that was waiting.
     *
     * @return int[] ids of the offers published by this call
     */
    public function setApprovalRequired(bool $required): array
    {
        $this->settings->set(self::SETTING_APPROVAL, $required ? 'required' : 'off');
        if ($required) {
            return [];
        }

        $waiting = array_map('intval', $this->db->query("SELECT id FROM offer WHERE status = 'pending'")->fetchAll(PDO::FETCH_COLUMN));
        foreach ($waiting as $id) {
            $this->setStatus($id, 'published', null, null);
        }

        return $waiting;
    }

    public function currency(): string
    {
        return $this->settings->get('core.currency', 'EUR');
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*, p.account_id, p.name AS provider_name, p.slug AS provider_slug, p.status AS provider_status,
                    a.email AS account_email, a.locale AS account_locale, a.status AS account_status ' . self::FROM . ' WHERE o.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate([$row])[0] : null;
    }

    /** The public offer that has this address in a language (or in the default language where it has no own text). */
    public function findPublicBySlug(string $locale, string $slug): ?array
    {
        foreach (array_unique([$locale, $this->locales->default()]) as $candidate) {
            $stmt = $this->db->prepare('SELECT offer_id FROM offer_translation WHERE locale = :locale AND slug = :slug');
            $stmt->execute(['locale' => $candidate, 'slug' => $slug]);
            $id = $stmt->fetchColumn();
            if ($id === false) {
                continue;
            }

            $offer = $this->find((int) $id);
            if ($offer === null || !$this->isPublic($offer) || ($candidate !== $locale && isset($offer['texts'][$locale]))) {
                return null;
            }

            return $offer;
        }

        return null;
    }

    public function isPublic(array $offer): bool
    {
        return $offer['status'] === 'published' && $offer['provider_status'] === 'approved'
            && $offer['account_status'] === 'active' && isset($this->types[$offer['type']]);
    }

    /**
     * Offers for visitors.
     *
     * @param array{search?: string, category_ids?: int[], provider_id?: int, sort?: string} $filter
     * @return array{rows: array<int, array>, total: int}
     */
    /** @param int[] $priorityAccountIds providers whose offers come first, within whatever sort was chosen */
    public function listPublic(array $filter, string $locale, int $page, int $perPage, array $priorityAccountIds = []): array
    {
        $where = [self::PUBLIC_WHERE];
        $params = [];

        // Offers whose extension is switched off are not shown.
        $types = array_keys($this->types);
        if ($types === []) {
            return ['rows' => [], 'total' => 0];
        }
        $where[] = 'o.type IN (' . $this->placeholders('type', $types, $params) . ')';

        if (($filter['category_ids'] ?? []) !== []) {
            $where[] = 'o.category_id IN (' . $this->placeholders('cat', $filter['category_ids'], $params) . ')';
        }
        if (isset($filter['provider_id'])) {
            $where[] = 'o.provider_id = :provider';
            $params['provider'] = $filter['provider_id'];
        }

        $search = trim($filter['search'] ?? '');
        if ($search !== '') {
            // In the visitor's language and the default one, since an
            // offer without its own text is shown in the default language.
            $pattern = '%' . strtr(mb_substr($search, 0, 100), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $where[] = "EXISTS (SELECT 1 FROM offer_translation s WHERE s.offer_id = o.id AND s.locale IN (:s_locale, :s_default)
                AND (s.title LIKE :s1 ESCAPE '!' OR s.summary LIKE :s2 ESCAPE '!' OR s.description LIKE :s3 ESCAPE '!'))";
            $params += ['s_locale' => $locale, 's_default' => $this->locales->default(), 's1' => $pattern, 's2' => $pattern, 's3' => $pattern];
        }

        $order = match ($filter['sort'] ?? 'newest') {
            'price_low' => '(o.price_from IS NULL), o.price_from ASC, o.id DESC',
            'price_high' => 'o.price_from DESC, o.id DESC',
            // Rated offers first, by their average; the number of
            // ratings breaks ties.
            'rating' => '(o.rating_count = 0), (CASE WHEN o.rating_count > 0 THEN o.rating_sum * 1.0 / o.rating_count ELSE 0 END) DESC, o.rating_count DESC, o.id DESC',
            default => 'o.published_at DESC, o.id DESC',
        };

        $whereSql = implode(' AND ', $where);
        $count = $this->db->prepare('SELECT COUNT(*) ' . self::FROM . " WHERE {$whereSql}");
        $count->execute($params);

        // Added only now: the count above has nothing to do with order, and
        // its $params must not gain placeholders it never asked for.
        if ($priorityAccountIds !== []) {
            $order = '(p.account_id IN (' . $this->placeholders('pri', $priorityAccountIds, $params) . ')) DESC, ' . $order;
        }

        $stmt = $this->db->prepare(
            'SELECT o.*, p.account_id, p.name AS provider_name, p.slug AS provider_slug, p.status AS provider_status,
                    a.email AS account_email, a.locale AS account_locale, a.status AS account_status '
            . self::FROM . " WHERE {$whereSql} ORDER BY {$order} LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, (min($page, 100000) - 1) * $perPage)
        );
        $stmt->execute($params);

        return ['rows' => $this->hydrate($stmt->fetchAll()), 'total' => (int) $count->fetchColumn()];
    }

    /**
     * Offers for their provider or for the administration, whatever their status.
     *
     * @return array{rows: array<int, array>, total: int}
     */
    public function listAll(?int $providerId, ?string $status, int $page, int $perPage, ?string $type = null): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($providerId !== null) {
            $where[] = 'o.provider_id = :provider';
            $params['provider'] = $providerId;
        }
        if ($status !== null) {
            $where[] = 'o.status = :status';
            $params['status'] = $status;
        }
        if ($type !== null) {
            $where[] = 'o.type = :type';
            $params['type'] = $type;
        }
        $whereSql = implode(' AND ', $where);

        $count = $this->db->prepare('SELECT COUNT(*) ' . self::FROM . " WHERE {$whereSql}");
        $count->execute($params);

        $stmt = $this->db->prepare(
            'SELECT o.*, p.account_id, p.name AS provider_name, p.slug AS provider_slug, p.status AS provider_status,
                    a.email AS account_email, a.locale AS account_locale, a.status AS account_status '
            . self::FROM . " WHERE {$whereSql} ORDER BY o.updated_at DESC, o.id DESC LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, (min($page, 100000) - 1) * $perPage)
        );
        $stmt->execute($params);

        return ['rows' => $this->hydrate($stmt->fetchAll()), 'total' => (int) $count->fetchColumn()];
    }

    /** @return array<int, int> category id => number of public offers directly in it */
    public function publicCountsByCategory(): array
    {
        $types = array_keys($this->types);
        if ($types === []) {
            return [];
        }

        $params = [];
        $stmt = $this->db->prepare(
            'SELECT o.category_id, COUNT(*) ' . self::FROM . ' WHERE ' . self::PUBLIC_WHERE
            . ' AND o.category_id IS NOT NULL AND o.type IN (' . $this->placeholders('type', $types, $params) . ') GROUP BY o.category_id'
        );
        $stmt->execute($params);

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $categoryId => $count) {
            $counts[(int) $categoryId] = (int) $count;
        }

        return $counts;
    }

    /** @return array<string, int> status => number of offers */
    public function counts(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->db->query('SELECT status, COUNT(*) FROM offer GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR) as $status => $count) {
            $counts[$status] = (int) $count;
        }

        return $counts;
    }

    /**
     * Checks the part of the offer form every type shares.
     *
     * @param array<string, mixed> $input
     * @param int[] $categoryIds ids that exist
     * @return array{category_id: ?int, texts: array<string, array{title: string, summary: string, description: string}>, errors: string[]}
     */
    public function validate(array $input, array $categoryIds): array
    {
        $errors = [];
        $categoryId = (int) ($input['category_id'] ?? 0);
        if (!in_array($categoryId, $categoryIds, true)) {
            $categoryId = null;
            if ($categoryIds !== []) {
                $errors[] = 'core.offer.error.category';
            }
        }

        $texts = [];
        foreach ($this->locales->enabled() as $locale) {
            $text = is_array($input['text'][$locale] ?? null) ? $input['text'][$locale] : [];
            $title = trim((string) ($text['title'] ?? ''));
            $summary = trim((string) ($text['summary'] ?? ''));
            $description = trim(str_replace("\r\n", "\n", (string) ($text['description'] ?? '')));

            if ($title === '' && $summary === '' && $description === '') {
                continue;
            }
            if ($title === '' || mb_strlen($title) > 150 || mb_strlen($summary) > 300 || mb_strlen($description) > 10000) {
                $errors[] = 'core.offer.error.text';
                continue;
            }
            $texts[$locale] = ['title' => $title, 'summary' => $summary, 'description' => $description];
        }

        if ($texts === [] && !in_array('core.offer.error.text', $errors, true)) {
            $errors[] = 'core.offer.error.no_text';
        }

        return ['category_id' => $categoryId, 'texts' => $texts, 'errors' => array_values(array_unique($errors))];
    }

    /**
     * Stores the shared part. A new offer starts as a draft; saving never
     * changes the status of an existing one.
     *
     * @param array<string, array{title: string, summary: string, description: string}> $texts
     */
    public function save(?int $id, int $providerId, string $type, ?int $categoryId, array $texts): int
    {
        $now = Clock::now();
        $this->db->beginTransaction();

        if ($id === null) {
            $stmt = $this->db->prepare(
                "INSERT INTO offer (provider_id, type, category_id, status, currency, created_at, updated_at)
                 VALUES (:provider, :type, :category, 'draft', :currency, :now, :now2)"
            );
            $stmt->execute(['provider' => $providerId, 'type' => $type, 'category' => $categoryId, 'currency' => $this->currency(), 'now' => $now, 'now2' => $now]);
            $id = (int) $this->db->lastInsertId();
            $existing = [];
        } else {
            $stmt = $this->db->prepare('UPDATE offer SET category_id = :category, updated_at = :now WHERE id = :id');
            $stmt->execute(['category' => $categoryId, 'now' => $now, 'id' => $id]);
            $existing = $this->find($id)['texts'] ?? [];
        }

        $delete = $this->db->prepare('DELETE FROM offer_translation WHERE offer_id = :id');
        $delete->execute(['id' => $id]);

        $insert = $this->db->prepare(
            'INSERT INTO offer_translation (offer_id, locale, title, slug, summary, description)
             VALUES (:id, :locale, :title, :slug, :summary, :description)'
        );
        foreach ($texts as $locale => $text) {
            // The address stays as it is while the title does: links to a
            // published offer should not break because of an edit elsewhere.
            $slug = ($existing[$locale]['title'] ?? null) === $text['title']
                ? $existing[$locale]['slug']
                : $this->uniqueSlug($locale, $text['title']);
            $insert->execute(['id' => $id, 'locale' => $locale, 'slug' => $slug] + $text);
        }

        $this->db->commit();

        return $id;
    }

    /** A bare row for a wizard to hang its progress - and uploaded images - on; texts and type-specific data come later, through save(). */
    public function createDraft(int $providerId, string $type): int
    {
        $now = Clock::now();
        $stmt = $this->db->prepare(
            "INSERT INTO offer (provider_id, type, status, currency, created_at, updated_at)
             VALUES (:provider, :type, 'draft', :currency, :now, :now2)"
        );
        $stmt->execute(['provider' => $providerId, 'type' => $type, 'currency' => $this->currency(), 'now' => $now, 'now2' => $now]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Drafts a wizard started but never finished - no offer_translation row
     * was ever written for them, which save() always does together with the
     * final step. Ready to be removed by a cleanup task.
     *
     * @return int[]
     */
    public function abandonedDraftIds(int $seconds): array
    {
        $stmt = $this->db->prepare(
            "SELECT o.id FROM offer o WHERE o.status = 'draft' AND o.created_at < :before
             AND NOT EXISTS (SELECT 1 FROM offer_translation t WHERE t.offer_id = o.id)"
        );
        $stmt->execute(['before' => Clock::now(-$seconds)]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function setPriceFrom(int $id, ?int $minorUnits): void
    {
        $stmt = $this->db->prepare('UPDATE offer SET price_from = :price WHERE id = :id');
        $stmt->execute(['price' => $minorUnits, 'id' => $id]);
    }

    /** @param int|null $decidedBy the administrator's account for a decision, null for a change by the provider or the system */
    public function setStatus(int $id, string $status, ?string $note, ?int $decidedBy): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            return;
        }

        $now = Clock::now();
        $set = ['status = :status', 'status_note = :note', 'updated_at = :now'];
        $params = ['status' => $status, 'note' => $note !== null && trim($note) !== '' ? trim($note) : null, 'now' => $now, 'id' => $id];

        // The first publication is what "newest" sorts by; pausing and
        // resuming does not move an offer back to the top.
        if ($status === 'published') {
            $set[] = 'published_at = COALESCE(published_at, :published)';
            $params['published'] = $now;
        }
        if ($decidedBy !== null) {
            $set[] = 'decided_at = :decided_at';
            $set[] = 'decided_by = :decided_by';
            $params += ['decided_at' => $now, 'decided_by' => $decidedBy];
        }

        $stmt = $this->db->prepare('UPDATE offer SET ' . implode(', ', $set) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM offer WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** Title, summary, description and slug in a language, or in the default language, or whichever exists. */
    public function text(array $offer, string $locale): ?array
    {
        return $offer['texts'][$locale] ?? $offer['texts'][$this->locales->default()] ?? (array_values($offer['texts'])[0] ?? null);
    }

    /** @param array<int, array> $rows @return array<int, array> with ids as ints, texts by locale and images */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $params = [];
        $in = $this->placeholders('id', array_column($rows, 'id'), $params);

        $texts = [];
        $stmt = $this->db->prepare("SELECT * FROM offer_translation WHERE offer_id IN ({$in})");
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $text) {
            $texts[(int) $text['offer_id']][$text['locale']] = [
                'title' => $text['title'], 'slug' => $text['slug'], 'summary' => $text['summary'], 'description' => $text['description'],
            ];
        }

        $images = [];
        $stmt = $this->db->prepare("SELECT * FROM offer_image WHERE offer_id IN ({$in}) ORDER BY position, id");
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $image) {
            $images[(int) $image['offer_id']][] = $image;
        }

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['provider_id'] = (int) $row['provider_id'];
            $row['account_id'] = (int) $row['account_id'];
            $row['category_id'] = $row['category_id'] !== null ? (int) $row['category_id'] : null;
            $row['price_from'] = $row['price_from'] !== null ? (int) $row['price_from'] : null;
            $row['texts'] = $texts[$row['id']] ?? [];
            $row['images'] = $images[$row['id']] ?? [];
        }

        return $rows;
    }

    /** @param array<int, int|string> $values */
    private function placeholders(string $prefix, array $values, array &$params): string
    {
        $names = [];
        foreach (array_values($values) as $i => $value) {
            $names[] = ':' . $prefix . $i;
            $params[$prefix . $i] = $value;
        }

        return implode(', ', $names);
    }

    private function uniqueSlug(string $locale, string $title): string
    {
        $base = Pages::slugify($title) ?: 'offer';
        $slug = $base;
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM offer_translation WHERE locale = :locale AND slug = :slug');

        for ($suffix = 2; ; $suffix++) {
            $stmt->execute(['locale' => $locale, 'slug' => $slug]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $slug;
            }
            $slug = $base . '-' . $suffix;
        }
    }
}
