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
            } elseif (preg_match('/^insert:([a-z0-9-]{1,32})$/', $action, $match) === 1) {
                $blocks = HomeLayout::insertAfter($blocks, $match[1], 'text');
            } elseif (preg_match('/^(up|down|delete|toggle|duplicate):([a-z0-9-]{1,32})$/', $action, $match) === 1) {
                $blocks = $this->step($blocks, $match[1], $match[2]);
            }
            $layout->save($blocks);
            Session::flash('success', $this->trans($action === 'save' ? 'core.offer_page.saved' : 'core.offer_page.changed'));
        }

        $this->redirect($this->safeReturn('/admin/offer-page'));
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

        if ($direction === 'duplicate') {
            // The core's own parts exist once; only text blocks are copied.
            return $blocks[$index]['type'] === 'text' ? HomeLayout::duplicateAfter($blocks, $id) : $blocks;
        }

        if ($direction === 'toggle') {
            $blocks[$index]['enabled'] = !$blocks[$index]['enabled'];

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

    /** A text field saved from the page itself, with JavaScript: the cleaned value comes back. */
    public function field(array $params): void
    {
        $app = $this->app;
        $data = json_decode((string) file_get_contents('php://input'), true);
        $locale = is_array($data) && is_string($data['locale'] ?? null) ? $data['locale'] : '';
        if (!is_array($data) || !$app->locales->isEnabled($locale) || !is_string($data['block'] ?? null)
            || !is_string($data['field'] ?? null) || !is_string($data['value'] ?? null)) {
            $this->json(['ok' => false], 422);
            return;
        }

        $clean = $app->offerLayout->setField($data['block'], $locale, $data['field'], $data['value']);
        if ($clean === null) {
            $this->json(['ok' => false], 422);
            return;
        }
        $this->json(['ok' => true, 'value' => $clean]);
    }
}
