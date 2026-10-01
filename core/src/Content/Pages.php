<?php

declare(strict_types=1);

namespace Modulento\Core\Content;

use Modulento\Core\Support\Clock;
use Modulento\Core\Support\HtmlSanitizer;
use Modulento\Core\Support\Locales;
use PDO;

/**
 * Content pages. A page has one text per language; where a language has
 * none, the default language's text is shown rather than nothing - a
 * visitor reading English still has to be able to reach the imprint.
 */
final class Pages
{
    public const ROLES = ['imprint', 'privacy', 'terms'];

    // First path segments a page must not use: its address would never be
    // reached, because these routes are matched first.
    private const RESERVED_SLUGS = ['admin', 'account', 'assets', 'cron', 'login', 'logout', 'register', 'forgot-password', 'reset-password', 'verify-email', 'providers', 'offers', 'categories', 'media'];

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
     * @param array{status: string, role: ?string, in_header: bool, in_footer: bool, position: int} $fields
     * @param array<string, array{title: string, slug: string, meta_description: string, body: string}> $translations
     *        by locale; a language whose title is empty is removed from the page
     * @return array{id: ?int, errors: array<int, array{key: string, params: array}>}
     */
    public function save(?int $id, array $fields, array $translations): array
    {
        $errors = [];
        $rows = [];

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
                'body' => HtmlSanitizer::clean($text['body']),
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
            'in_header' => (int) $fields['in_header'],
            'in_footer' => (int) $fields['in_footer'],
            'position' => $fields['position'],
            'now' => Clock::now(),
        ];

        if ($id === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO page (status, role, in_header, in_footer, position, created_at, updated_at)
                 VALUES (:status, :role, :in_header, :in_footer, :position, :now, :now2)'
            );
            $stmt->execute($values + ['now2' => $values['now']]);
            $id = (int) $this->db->lastInsertId();
        } else {
            $stmt = $this->db->prepare(
                'UPDATE page SET status = :status, role = :role, in_header = :in_header, in_footer = :in_footer,
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
