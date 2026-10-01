<?php

declare(strict_types=1);

namespace Modulento\Core\Catalogue;

use Modulento\Core\Content\Pages;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Locales;
use PDO;

/**
 * Categories in two levels, each with a name and an address per language.
 * Like pages, a category without a name in a language shows the default
 * language's.
 */
final class Categories
{
    public function __construct(private PDO $db, private Locales $locales)
    {
    }

    /** @return array<int, array<string, mixed>> every category with all its translations, parents before children */
    public function all(): array
    {
        $rows = $this->db->query('SELECT * FROM category ORDER BY (parent_id IS NOT NULL), position, id')->fetchAll();
        $categories = [];
        foreach ($rows as $row) {
            $row['id'] = (int) $row['id'];
            $row['parent_id'] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
            $categories[$row['id']] = $row + ['translations' => []];
        }

        foreach ($this->db->query('SELECT * FROM category_translation')->fetchAll() as $row) {
            if (isset($categories[(int) $row['category_id']])) {
                $categories[(int) $row['category_id']]['translations'][$row['locale']] = ['name' => $row['name'], 'slug' => $row['slug']];
            }
        }

        return $categories;
    }

    public function find(int $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * The tree as a template needs it, in a language.
     *
     * @return array<int, array{id: int, name: string, path: string, children: array<int, array{id: int, name: string, path: string}>}>
     */
    public function tree(string $locale): array
    {
        $tree = [];
        $all = $this->all();

        foreach ($all as $category) {
            $view = $this->view($category, $locale);
            if ($view === null) {
                continue;
            }
            if ($category['parent_id'] === null) {
                $tree[$category['id']] = $view + ['children' => []];
            } elseif (isset($tree[$category['parent_id']])) {
                $tree[$category['parent_id']]['children'][] = $view;
            }
        }

        return array_values($tree);
    }

    /** @return array{id: int, name: string, path: string}|null */
    public function view(array $category, string $locale): ?array
    {
        $text = $category['translations'][$locale] ?? $category['translations'][$this->locales->default()] ?? null;

        return $text !== null ? ['id' => $category['id'], 'name' => $text['name'], 'path' => '/categories/' . $text['slug']] : null;
    }

    /** The category that has this address in a language (or in the default language, where it has no own name). */
    public function findBySlug(string $locale, string $slug): ?array
    {
        foreach ($this->all() as $category) {
            if (($category['translations'][$locale]['slug'] ?? null) === $slug) {
                return $category;
            }
        }
        foreach ($this->all() as $category) {
            if (!isset($category['translations'][$locale]) && ($category['translations'][$this->locales->default()]['slug'] ?? null) === $slug) {
                return $category;
            }
        }

        return null;
    }

    /** @return int[] the category's own id and those of its children */
    public function withChildren(int $id): array
    {
        $ids = [$id];
        foreach ($this->all() as $category) {
            if ($category['parent_id'] === $id) {
                $ids[] = $category['id'];
            }
        }

        return $ids;
    }

    /**
     * @param array<string, array{name: string, slug: string}> $translations by locale; a language with an empty name is removed
     * @return array{id: ?int, errors: array<int, array{key: string, params: array}>}
     */
    public function save(?int $id, ?int $parentId, int $position, array $translations): array
    {
        $errors = [];
        $all = $this->all();

        if ($parentId !== null) {
            $parent = $all[$parentId] ?? null;
            $hasChildren = $id !== null && count($this->withChildren($id)) > 1;
            // Two levels: the parent must be a top category, and a category
            // that has children cannot become a child itself.
            if ($parent === null || $parent['parent_id'] !== null || $parentId === $id || $hasChildren) {
                $errors[] = ['key' => 'core.admin.categories.error.parent', 'params' => []];
            }
        }

        $rows = [];
        foreach ($translations as $locale => $text) {
            $name = trim($text['name']);
            if ($name === '') {
                continue;
            }
            $slug = Pages::slugify(trim($text['slug']) !== '' ? $text['slug'] : $name);
            if ($slug === '') {
                $errors[] = ['key' => 'core.admin.categories.error.slug', 'params' => ['locale' => $locale]];
                continue;
            }
            foreach ($all as $other) {
                if ($other['id'] !== $id && ($other['translations'][$locale]['slug'] ?? null) === $slug) {
                    $errors[] = ['key' => 'core.admin.categories.error.slug_taken', 'params' => ['locale' => $locale, 'slug' => $slug]];
                    continue 2;
                }
            }
            $rows[$locale] = ['name' => mb_substr($name, 0, 150), 'slug' => $slug];
        }

        if ($rows === [] && $errors === []) {
            $errors[] = ['key' => 'core.admin.categories.error.no_name', 'params' => []];
        }
        if ($errors !== []) {
            return ['id' => $id, 'errors' => $errors];
        }

        $this->db->beginTransaction();

        if ($id === null) {
            $stmt = $this->db->prepare('INSERT INTO category (parent_id, position, created_at) VALUES (:parent, :position, :now)');
            $stmt->execute(['parent' => $parentId, 'position' => $position, 'now' => Clock::now()]);
            $id = (int) $this->db->lastInsertId();
        } else {
            $stmt = $this->db->prepare('UPDATE category SET parent_id = :parent, position = :position WHERE id = :id');
            $stmt->execute(['parent' => $parentId, 'position' => $position, 'id' => $id]);
        }

        $delete = $this->db->prepare('DELETE FROM category_translation WHERE category_id = :id');
        $delete->execute(['id' => $id]);
        $insert = $this->db->prepare('INSERT INTO category_translation (category_id, locale, name, slug) VALUES (:id, :locale, :name, :slug)');
        foreach ($rows as $locale => $row) {
            $insert->execute(['id' => $id, 'locale' => $locale] + $row);
        }

        $this->db->commit();

        return ['id' => $id, 'errors' => []];
    }

    /** A category that still has subcategories is not deleted; its offers simply lose their category. */
    public function delete(int $id): bool
    {
        if (count($this->withChildren($id)) > 1) {
            return false;
        }

        $clear = $this->db->prepare('UPDATE offer SET category_id = NULL WHERE category_id = :id');
        $clear->execute(['id' => $id]);
        $stmt = $this->db->prepare('DELETE FROM category WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return true;
    }
}
