<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Content\HomeLayout;
use Modulento\Core\Content\OfferLayout;
use Modulento\Core\Support\Session;

/** The editor of the offer page: which parts show, in which order, and the texts of free text blocks. */
final class AdminOfferPageController extends Controller
{
    public function index(array $params): void
    {
        $this->render('@admin/offer_page_edit.twig', [
            'blocks' => array_map(fn (array $block) => $block + ['label_key' => 'core.offer_page.type.' . $block['type']], $this->app->offerLayout->blocks()),
            'locale' => $this->app->translator->locale(),
            'default_locale' => $this->app->locales->default(),
            'customized' => $this->app->offerLayout->isCustomized(),
        ]);
    }

    public function save(array $params): void
    {
        $layout = $this->app->offerLayout;
        $blocks = $this->fromForm($layout->blocks());
        $action = (string) ($_POST['action'] ?? 'save');

        if ($action === 'reset') {
            $layout->reset();
            Session::flash('success', $this->trans('core.offer_page.reset_done'));
        } else {
            if ($action === 'add') {
                $blocks[] = HomeLayout::newBlock('text');
            } elseif (preg_match('/^(up|down|delete):([a-z0-9]{1,16})$/', $action, $match) === 1) {
                $blocks = $this->step($blocks, $match[1], $match[2]);
            }
            $layout->save($blocks);
            Session::flash('success', $this->trans($action === 'save' ? 'core.offer_page.saved' : 'core.offer_page.changed'));
        }

        $this->redirect('/admin/offer-page');
    }

    /**
     * The stored blocks with the form's values: enabled, and the texts of the
     * language the administration is in. Other languages are kept as they are.
     *
     * @param list<array<string, mixed>> $stored
     * @return list<array<string, mixed>>
     */
    private function fromForm(array $stored): array
    {
        $posted = is_array($_POST['blocks'] ?? null) ? $_POST['blocks'] : [];
        $locale = $this->app->translator->locale();
        $blocks = [];

        foreach ($stored as $block) {
            $id = $block['id'];
            if (!isset($posted[$id]) || !is_array($posted[$id])) {
                $blocks[] = $block;
                continue;
            }
            $input = $posted[$id];
            $texts = $block['texts'];
            if ($block['type'] === 'text') {
                foreach (['heading', 'body'] as $field) {
                    $value = is_string($input['texts'][$locale][$field] ?? null) ? $input['texts'][$locale][$field] : '';
                    $texts[$locale][$field] = HomeLayout::clean('text', $field, $value);
                }
            }
            $blocks[] = ['id' => $id, 'type' => $block['type'], 'enabled' => isset($input['enabled']), 'texts' => $texts];
        }

        return $blocks;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function step(array $blocks, string $direction, string $id): array
    {
        $index = null;
        foreach ($blocks as $i => $block) {
            if ($block['id'] === $id) {
                $index = $i;
            }
        }
        if ($index === null) {
            return $blocks;
        }

        if ($direction === 'delete') {
            // The core's own parts can be hidden, not removed.
            if ($blocks[$index]['type'] === 'text') {
                array_splice($blocks, $index, 1);
            }
        } else {
            $target = $direction === 'up' ? $index - 1 : $index + 1;
            if ($target >= 0 && $target < count($blocks)) {
                [$blocks[$index], $blocks[$target]] = [$blocks[$target], $blocks[$index]];
            }
        }

        return $blocks;
    }
}
