<?php

declare(strict_types=1);

namespace Modulento\Core\Content;

use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Locales;
use PDO;

/**
 * Content pages. A page has one text per language; where a language has
 * none, the default language's text is shown rather than nothing - a
 * visitor reading English still has to be able to reach the imprint.
 *
 * Its content is the same block layout as the home page (see HomeLayout,
 * whose static helpers this class reuses directly rather than duplicating
 * them - the established pattern, see OfferLayout), stored per page in the
 * "blocks" column instead of one setting shared by the whole site. Pages
 * also nest under a parent, for the administration's page tree and a later
 * mega menu - purely organisational, not reflected in the public address.
 */
final class Pages
{
    public const ROLES = ['imprint', 'privacy', 'terms'];

    // First path segments a page must not use: its address would never be
    // reached, because these routes are matched first.
    private const RESERVED_SLUGS = ['admin', 'account', 'assets', 'cron', 'login', 'logout', 'register', 'forgot-password', 'reset-password', 'verify-email', 'providers', 'offers', 'categories', 'media', 'orders', 'withdrawal', 'report'];

    public function __construct(private PDO $db, private Locales $locales)
    {
    }

    /** @return array<int, array<string, mixed>> every page with all its translations, for the administration */
    public function all(): array
    {
        $pages = [];
        foreach ($this->db->query('SELECT * FROM page ORDER BY position, id')->fetchAll() as $row) {
            $pages[(int) $row['id']] = $row + ['translations' => []];
        }

        foreach ($this->db->query('SELECT * FROM page_translation')->fetchAll() as $row) {
            if (isset($pages[(int) $row['page_id']])) {
                $pages[(int) $row['page_id']]['translations'][$row['locale']] = $row;
            }
        }

        return $pages;
    }

    public function find(int $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * The published page that has this address in a language. A slug of
     * the default language also works under another language's prefix,
     * for pages that language has no text for.
     *
     * @return array{page: array, text: array, text_locale: string}|null
     */
    public function findPublishedBySlug(string $locale, string $slug): ?array
    {
        foreach (array_unique([$locale, $this->locales->default()]) as $candidate) {
            $stmt = $this->db->prepare(
                "SELECT p.id FROM page p JOIN page_translation t ON t.page_id = p.id
                 WHERE p.status = 'published' AND t.locale = :locale AND t.slug = :slug"
            );
            $stmt->execute(['locale' => $candidate, 'slug' => $slug]);
            $id = $stmt->fetchColumn();

            if ($id === false) {
                continue;
            }

            $page = $this->find((int) $id);
            // In the requested language the page may have its own slug;
            // then that one is its address there, not this one.
            if ($candidate !== $locale && isset($page['translations'][$locale])) {
                return null;
            }

            return ['page' => $page, 'text' => $page['translations'][$candidate], 'text_locale' => $candidate];
        }

        return null;
    }

    /**
     * Saves the title of a page in one language, from the page itself (edit mode).
     * Null when the field is unknown, the language has no text of this page yet, or the
     * title is empty. Returns the cleaned value.
     */
    public function setText(int $id, string $locale, string $field, string $value): ?string
    {
        if ($field !== 'title') {
            return null;
        }
        $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value));
        if ($value === '' || mb_strlen($value) > 200) {
            return null;
        }

        $stmt = $this->db->prepare('UPDATE page_translation SET title = :value WHERE page_id = :id AND locale = :locale');
        $stmt->execute(['value' => $value, 'id' => $id, 'locale' => $locale]);
        if ($stmt->rowCount() === 0 && !$this->hasText($id, $locale)) {
            return null;
        }

        return $value;
    }

    private function hasText(int $id, string $locale): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM page_translation WHERE page_id = :id AND locale = :locale');
        $stmt->execute(['id' => $id, 'locale' => $locale]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * The blocks of a page, as stored. A page never opened in the block
     * editor has none yet - its legacy "body" text (one per language)
     * becomes a single text block, so nothing already published is lost.
     * Nothing is written back here; that only happens once the page is
     * actually saved through the editor.
     *
     * @return list<array{id: string, type: string, enabled: bool, texts: array<string, array<string, string>>, settings: array<string, mixed>}>
     */
    public function blocksFor(int $id): array
    {
        $page = $this->find($id);
        if ($page === null) {
            return [];
        }

        $data = json_decode((string) ($page['blocks'] ?? ''), true);
        if (is_array($data)) {
            $blocks = [];
            foreach ($data as $block) {
                if (!is_array($block) || !in_array($block['type'] ?? '', HomeLayout::TYPES, true)) {
                    continue;
                }
                $blocks[] = [
                    'id' => (string) ($block['id'] ?? ''),
                    'type' => $block['type'],
                    'enabled' => (bool) ($block['enabled'] ?? true),
                    'texts' => is_array($block['texts'] ?? null) ? $block['texts'] : [],
                    'settings' => is_array($block['settings'] ?? null) ? $block['settings'] : [],
                ];
            }

            return $blocks;
        }

        $texts = [];
        foreach ($page['translations'] as $locale => $text) {
            $body = trim((string) ($text['body'] ?? ''));
            if ($body !== '') {
                $texts[$locale] = ['body' => HomeLayout::clean('text', 'body', $body)];
            }
        }
        if ($texts === []) {
            return [];
        }

        return [['id' => 'legacy-body', 'type' => 'text', 'enabled' => true, 'texts' => $texts, 'settings' => []]];
    }

    /**
     * What a page shows in one language: the enabled blocks, their texts in
     * that language, else in the default language. Mirrors HomeLayout::view(),
     * minus the "a fresh site shows this" wording fallback - a page with
     * no text in a field simply shows none.
     *
     * @return list<array<string, mixed>>
     */
    public function view(int $id, string $locale, string $default, bool $includeHidden = false): array
    {
        $view = [];
        foreach ($this->blocksFor($id) as $block) {
            if (!$block['enabled'] && !$includeHidden) {
                continue;
            }
            $texts = [];
            foreach (HomeLayout::TEXT_FIELDS[$block['type']] as $field) {
                $text = (string) ($block['texts'][$locale][$field] ?? '');
                if ($text === '') {
                    $text = (string) ($block['texts'][$default][$field] ?? '');
                }
                $texts[$field] = $text;
            }

            $view[] = [
                'id' => $block['id'],
                'type' => $block['type'],
                'enabled' => $block['enabled'],
                'texts' => $texts,
                'count' => max(1, min(12, (int) ($block['settings']['count'] ?? 6))),
                'media' => (string) ($block['settings']['media'] ?? ''),
                'links' => HomeLayout::links($texts['items'] ?? ''),
                'background_type' => (string) ($block['settings']['background_type'] ?? ''),
                'background_color' => (string) ($block['settings']['background_color'] ?? ''),
                'background_color2' => (string) ($block['settings']['background_color2'] ?? ''),
                'background_media' => (string) ($block['settings']['background_media'] ?? ''),
            ];
        }

        return $view;
    }

    /** @param list<array<string, mixed>> $blocks */
    public function saveBlocks(int $id, array $blocks): void
    {
        $clean = [];
        foreach (array_slice($blocks, 0, HomeLayout::MAX_BLOCKS) as $block) {
            if (in_array($block['type'] ?? '', HomeLayout::TYPES, true)) {
                $clean[] = $block;
            }
        }
        $stmt = $this->db->prepare('UPDATE page SET blocks = :blocks WHERE id = :id');
        $stmt->execute(['blocks' => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'id' => $id]);
    }

    /** Saves one text field of one block in one language; returns the cleaned value, or null when there is no such field. */
    public function setBlockField(int $id, string $blockId, string $locale, string $field, string $value): ?string
    {
        $blocks = $this->blocksFor($id);
        foreach ($blocks as $i => $block) {
            if ($block['id'] !== $blockId) {
                continue;
            }
            if (!in_array($field, HomeLayout::TEXT_FIELDS[$block['type']] ?? [], true)) {
                return null;
            }
            $clean = HomeLayout::clean($block['type'], $field, $value);
            $blocks[$i]['texts'][$locale][$field] = $clean;
            $this->saveBlocks($id, $blocks);

            return $clean;
        }

        return null;
    }

    /**
     * Every id that descends from this page (children, grandchildren, ...) -
     * computed over the already-loaded list, no extra queries. Used to keep
     * a page from becoming its own ancestor, and to leave it and its own
     * subtree out of the "parent" choice in its own edit form.
     *
     * @return list<int>
     */
    public function descendantIds(int $id): array
    {
        $byParent = [];
        foreach ($this->all() as $page) {
            if ($page['parent_id'] !== null) {
                $byParent[(int) $page['parent_id']][] = (int) $page['id'];
            }
        }

        $ids = [];
        $queue = $byParent[$id] ?? [];
        while ($queue !== []) {
            $childId = array_shift($queue);
            if (in_array($childId, $ids, true)) {
                continue;
            }
            $ids[] = $childId;
            $queue = [...$queue, ...($byParent[$childId] ?? [])];
        }

        return $ids;
    }

    /** How many ancestors a page has (0 for a top-level page). For indenting the "parent" choice in its own form. */
    public function depth(int $id): int
    {
        $pages = $this->all();
        $depth = 0;
        $current = $pages[$id] ?? null;
        while ($current !== null && $current['parent_id'] !== null && $depth < 50) {
            $current = $pages[(int) $current['parent_id']] ?? null;
            $depth++;
        }

        return $depth;
    }

    /**
     * Every page nested under its parent, children ordered like their
     * siblings. Top-level pages are the roots; a page whose parent was
     * removed (disabled extension's own page, or similar) counts as a root
     * too rather than disappearing from the list.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(): array
    {
        $pages = $this->all();
        $byParent = [];
        foreach ($pages as $page) {
            $byParent[$page['parent_id'] !== null && isset($pages[(int) $page['parent_id']]) ? (int) $page['parent_id'] : 0][] = $page;
        }

        $build = function (int $parentId) use (&$build, $byParent): array {
            $children = $byParent[$parentId] ?? [];
            usort($children, fn (array $a, array $b) => $a['position'] <=> $b['position']);

            return array_map(fn (array $page) => $page + ['children' => $build((int) $page['id'])], $children);
        };

        return $build(0);
    }

    /**
     * Title and path of the pages shown in a menu, in a language.
     *
     * @param string $where "header", "footer" or a role from self::ROLES
     * @return array<int, array{title: string, path: string, role: ?string}>
     */
    public function links(string $where, string $locale): array
    {
        $links = [];
        foreach ($this->all() as $page) {
            $text = $page['translations'][$locale] ?? $page['translations'][$this->locales->default()] ?? null;
            $wanted = match ($where) {
                'header' => (bool) $page['in_header'],
                'footer' => (bool) $page['in_footer'] || $page['role'] !== null,
                default => $page['role'] === $where,
            };

            if ($page['status'] === 'published' && $wanted && $text !== null) {
                $links[] = ['title' => $text['title'], 'path' => '/' . $text['slug'], 'role' => $page['role']];
            }
        }

        return $links;
    }

    /**
     * @param array{status: string, role: ?string, in_header: bool, in_footer: bool, position: int, parent_id: ?int} $fields
     * @param array<string, array{title: string, slug: string, meta_description: string}> $translations
     *        by locale; a language whose title is empty is removed from the page
     * @return array{id: ?int, errors: array<int, array{key: string, params: array}>}
     */
    public function save(?int $id, array $fields, array $translations): array
    {
        $errors = [];
        $rows = [];
        // Content no longer travels through here (see blocksFor()/saveBlocks()),
        // but the column is still NOT NULL and still holds older pages' text
        // until they are opened in the block editor - kept as is, not wiped,
        // on every unrelated settings save.
        $existingBodies = $id !== null ? array_map(fn (array $text) => (string) $text['body'], $this->find($id)['translations'] ?? []) : [];

        $parentId = $fields['parent_id'];
        if ($parentId !== null) {
            if ($id !== null && ($parentId === $id || in_array($parentId, $this->descendantIds($id), true))) {
                $errors[] = ['key' => 'core.admin.pages.error.parent_cycle', 'params' => []];
                $parentId = null;
            } elseif ($this->find($parentId) === null) {
                $errors[] = ['key' => 'core.admin.pages.error.parent_invalid', 'params' => []];
                $parentId = null;
            }
        }

        foreach ($translations as $locale => $text) {
            $title = trim($text['title']);
            if ($title === '') {
                continue;
            }

            $slug = self::slugify(trim($text['slug']) !== '' ? $text['slug'] : $title);
            if ($slug === '' || in_array($slug, self::RESERVED_SLUGS, true) || $this->locales->isEnabled($slug)) {
                $errors[] = ['key' => 'core.admin.pages.error.slug', 'params' => ['locale' => $locale, 'slug' => $slug]];
                continue;
            }
            if ($this->slugTaken($locale, $slug, $id)) {
                $errors[] = ['key' => 'core.admin.pages.error.slug_taken', 'params' => ['locale' => $locale, 'slug' => $slug]];
                continue;
            }

            $rows[$locale] = [
                'title' => mb_substr($title, 0, 200),
                'slug' => $slug,
                'meta_description' => trim($text['meta_description']) !== '' ? mb_substr(trim($text['meta_description']), 0, 300) : null,
                'body' => $existingBodies[$locale] ?? '',
            ];
        }

        if ($rows === [] && $errors === []) {
            $errors[] = ['key' => 'core.admin.pages.error.no_text', 'params' => []];
        }
        if ($fields['role'] !== null && $this->roleTaken($fields['role'], $id)) {
            $errors[] = ['key' => 'core.admin.pages.error.role_taken', 'params' => []];
        }
        if ($errors !== []) {
            return ['id' => $id, 'errors' => $errors];
        }

        $this->db->beginTransaction();

        $values = [
            'status' => $fields['status'] === 'published' ? 'published' : 'draft',
            'role' => $fields['role'],
            'parent_id' => $parentId,
            'in_header' => (int) $fields['in_header'],
            'in_footer' => (int) $fields['in_footer'],
            'position' => $fields['position'],
            'now' => Clock::now(),
        ];

        if ($id === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO page (status, role, parent_id, in_header, in_footer, position, created_at, updated_at)
                 VALUES (:status, :role, :parent_id, :in_header, :in_footer, :position, :now, :now2)'
            );
            $stmt->execute($values + ['now2' => $values['now']]);
            $id = (int) $this->db->lastInsertId();
        } else {
            $stmt = $this->db->prepare(
                'UPDATE page SET status = :status, role = :role, parent_id = :parent_id, in_header = :in_header, in_footer = :in_footer,
                     position = :position, updated_at = :now WHERE id = :id'
            );
            $stmt->execute($values + ['id' => $id]);
        }

        $delete = $this->db->prepare('DELETE FROM page_translation WHERE page_id = :id');
        $delete->execute(['id' => $id]);

        $insert = $this->db->prepare(
            'INSERT INTO page_translation (page_id, locale, title, slug, meta_description, body)
             VALUES (:page_id, :locale, :title, :slug, :meta_description, :body)'
        );
        foreach ($rows as $locale => $row) {
            $insert->execute(['page_id' => $id, 'locale' => $locale] + $row);
        }

        $this->db->commit();

        return ['id' => $id, 'errors' => []];
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM page WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** Lower-case letters, digits and single hyphens; umlauts and accents are written out. */
    public static function slugify(string $text): string
    {
        $text = strtr(mb_strtolower(trim($text)), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii !== false ? $ascii : $text));

        return trim(substr($text, 0, 200), '-');
    }

    private function slugTaken(string $locale, string $slug, ?int $exceptPageId): bool
    {
        $stmt = $this->db->prepare('SELECT page_id FROM page_translation WHERE locale = :locale AND slug = :slug');
        $stmt->execute(['locale' => $locale, 'slug' => $slug]);
        $pageId = $stmt->fetchColumn();

        return $pageId !== false && (int) $pageId !== $exceptPageId;
    }

    private function roleTaken(string $role, ?int $exceptPageId): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM page WHERE role = :role');
        $stmt->execute(['role' => $role]);
        $pageId = $stmt->fetchColumn();

        return $pageId !== false && (int) $pageId !== $exceptPageId;
    }
}
