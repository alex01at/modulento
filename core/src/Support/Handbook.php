<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * The operator's manual, shipped with the core so that it always matches the
 * version installed. A chapter's prose is not here: it is the Twig file
 * themes/default/templates/handbook/<locale>/<slug>.twig - the same way the
 * administration's developer docs (themes/admin/templates/docs/<locale>.twig)
 * keep their text, one file per language, nothing in the database. A locale
 * with no file of its own falls back to English (see HandbookController).
 */
final class Handbook
{
    /** Chapter slug => language key of its title, in reading order. */
    public const CHAPTERS = [
        'intro' => 'core.handbook.chapter.intro',
        'installation' => 'core.handbook.chapter.installation',
        'first-steps' => 'core.handbook.chapter.first_steps',
        'administration' => 'core.handbook.chapter.administration',
        'pages' => 'core.handbook.chapter.pages',
        'accounts' => 'core.handbook.chapter.accounts',
        'marketplace' => 'core.handbook.chapter.marketplace',
        'payments' => 'core.handbook.chapter.payments',
        'subscriptions' => 'core.handbook.chapter.subscriptions',
        'extensions' => 'core.handbook.chapter.extensions',
        'themes' => 'core.handbook.chapter.themes',
        'languages' => 'core.handbook.chapter.languages',
        'operations' => 'core.handbook.chapter.operations',
        'glossary' => 'core.handbook.chapter.glossary',
    ];
}
