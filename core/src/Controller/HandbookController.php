<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\Handbook;

/**
 * The operator's manual: a table of contents and its chapters. Public, so that
 * someone evaluating Modulento or setting it up for the first time can read it
 * without an account. See Handbook for where a chapter's text lives.
 */
final class HandbookController extends Controller
{
    public function index(array $params): void
    {
        $this->render('handbook/index.twig', ['chapters' => $this->chapters()]);
    }

    public function chapter(array $params): void
    {
        $slugs = array_keys(Handbook::CHAPTERS);
        $slug = (string) ($params['slug'] ?? '');
        $index = array_search($slug, $slugs, true);

        if ($index === false) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        $app = $this->app;
        $this->render('handbook/chapter.twig', [
            'slug' => $slug,
            'title_key' => Handbook::CHAPTERS[$slug],
            // A locale with no file of its own reads the English one (same fallback as @admin/docs).
            'body_templates' => ['handbook/' . $app->translator->locale() . '/' . $slug . '.twig', 'handbook/en/' . $slug . '.twig'],
            'chapters' => $this->chapters(),
            'prev' => $index > 0 ? ['slug' => $slugs[$index - 1], 'title_key' => Handbook::CHAPTERS[$slugs[$index - 1]]] : null,
            'next' => $index < count($slugs) - 1 ? ['slug' => $slugs[$index + 1], 'title_key' => Handbook::CHAPTERS[$slugs[$index + 1]]] : null,
        ]);
    }

    /** @return list<array{slug: string, title_key: string}> */
    private function chapters(): array
    {
        return array_map(
            fn (string $slug, string $titleKey) => ['slug' => $slug, 'title_key' => $titleKey],
            array_keys(Handbook::CHAPTERS),
            Handbook::CHAPTERS
        );
    }
}
