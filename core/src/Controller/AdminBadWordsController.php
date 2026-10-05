<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\Session;

/** The word filter in the administration: which words messages, questions and reviews may not contain. */
final class AdminBadWordsController extends Controller
{
    public function index(array $params): void
    {
        $this->render('@admin/badwords.twig', [
            'words' => implode("\n", $this->app->badWords->words()),
            'count' => count($this->app->badWords->words()),
            'customized' => $this->app->badWords->isCustomized(),
        ]);
    }

    public function save(array $params): void
    {
        $count = $this->app->badWords->save((string) ($_POST['words'] ?? ''));

        Session::flash($count > 0 ? 'success' : 'error', $this->trans($count > 0 ? 'core.badwords.saved' : 'core.badwords.empty', ['count' => $count]));
        $this->redirect('/admin/badwords');
    }

    public function reset(array $params): void
    {
        $this->app->badWords->reset();
        Session::flash('success', $this->trans('core.badwords.reset_done'));
        $this->redirect('/admin/badwords');
    }
}
