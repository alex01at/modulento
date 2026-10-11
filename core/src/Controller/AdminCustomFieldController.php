<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\CustomField\CustomFields;
use Modulento\Core\Support\Session;

/** The admin's own field definitions for offers (optionally restricted to a type) and the provider profile. */
final class AdminCustomFieldController extends Controller
{
    public function index(array $params): void
    {
        $entity = $this->entity();
        $locale = $this->app->translator->locale();

        $this->render('@admin/custom_fields.twig', [
            'entity' => $entity,
            'fields' => array_map(fn (array $field) => [
                'id' => $field['id'],
                'label' => $this->fieldLabel($field, $locale),
                'type' => $field['type'],
                'required' => $field['required'],
            ], $this->app->customFields->definitions($entity)),
        ]);
    }

    public function edit(array $params): void
    {
        $entity = $this->entity();
        $field = isset($params['id']) ? $this->app->customFields->find($entity, (int) $params['id']) : null;
        if (isset($params['id']) && $field === null) {
            $this->redirect('/admin/custom-fields?entity=' . $entity);
            return;
        }

        $this->renderForm($entity, $field, []);
    }

    public function save(array $params): void
    {
        $entity = in_array($_POST['entity'] ?? '', ['offer', 'provider'], true) ? $_POST['entity'] : 'offer';
        $id = isset($params['id']) ? (int) $params['id'] : null;

        $translations = [];
        foreach ($this->app->locales->enabled() as $locale) {
            $input = is_array($_POST['text'][$locale] ?? null) ? $_POST['text'][$locale] : [];
            $translations[$locale] = [
                'label' => (string) ($input['label'] ?? ''),
                'options' => is_array($input['options'] ?? null) ? $input['options'] : [],
            ];
        }
        $values = [
            'type' => (string) ($_POST['type'] ?? ''),
            'required' => isset($_POST['required']),
            'position' => (int) ($_POST['position'] ?? 0),
            'type_ids' => is_array($_POST['type_ids'] ?? null) ? $_POST['type_ids'] : [],
        ];

        $result = $this->app->customFields->save($entity, $id, $values, $translations);

        if ($result['errors'] !== []) {
            $field = [
                'id' => $id,
                'entity' => $entity,
                'type' => $values['type'],
                'required' => $values['required'],
                'position' => $values['position'],
                'type_ids' => $values['type_ids'],
                'translations' => array_map(
                    fn (array $t) => ['label' => $t['label'], 'option_labels' => array_filter($t['options'], fn ($v) => trim((string) $v) !== '')],
                    $translations
                ),
            ];
            $this->renderForm($entity, $field, array_map(fn (array $error) => $this->trans($error['key'], $error['params']), $result['errors']));
            return;
        }

        Session::flash('success', $this->trans('core.admin.custom_fields.saved'));
        $this->redirect('/admin/custom-fields?entity=' . $entity);
    }

    public function delete(array $params): void
    {
        $entity = $this->entity();
        $this->app->customFields->delete($entity, (int) $params['id']);

        Session::flash('success', $this->trans('core.admin.custom_fields.deleted'));
        $this->redirect('/admin/custom-fields?entity=' . $entity);
    }

    private function entity(): string
    {
        return in_array($_GET['entity'] ?? '', ['offer', 'provider'], true) ? $_GET['entity'] : 'offer';
    }

    /** @return string[] a select field's option keys, in their stored order - the same set in every language */
    private function optionKeys(array $field): array
    {
        foreach ($field['translations'] ?? [] as $translation) {
            if (($translation['option_labels'] ?? []) !== []) {
                return array_keys($translation['option_labels']);
            }
        }

        return [];
    }

    private function fieldLabel(array $field, string $locale): string
    {
        return $field['translations'][$locale]['label']
            ?? $field['translations'][$this->app->locales->default()]['label']
            ?? $field['field_key'];
    }

    private function renderForm(string $entity, ?array $field, array $errors): void
    {
        $this->render('@admin/custom_field_edit.twig', [
            'entity' => $entity,
            'field' => $field,
            'types' => CustomFields::TYPES,
            'offer_types' => $entity === 'offer'
                ? array_map(fn ($type) => ['id' => $type->id(), 'label' => $this->trans($type->labelKey())], array_values($this->app->offers->types()))
                : [],
            'locales' => $this->app->locales->enabled(),
            'default_locale' => $this->app->locales->default(),
            'option_keys' => $field !== null ? $this->optionKeys($field) : [],
            'errors' => $errors,
        ]);
    }
}
