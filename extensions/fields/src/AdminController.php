<?php

declare(strict_types=1);

namespace Modulento\Fields;

use Modulento\Core\Controller\Controller;
use Modulento\Core\Support\Session;

/** The builder: kinds of offer and their fields. */
final class AdminController extends Controller
{
    public function index(array $params): void
    {
        $locale = $this->app->translator->locale();
        $default = $this->app->locales->default();

        $this->render('@fields/admin_types.twig', [
            'types' => array_map(fn (TypeDefinition $type) => [
                'id' => $type->id,
                'label' => $type->label($locale, $default),
                'offer_type' => $type->offerType(),
                'fields' => count($type->fields),
                'offers' => $this->app->offers->countOfType($type->offerType()),
            ], $this->definitions()->types()),
        ]);
    }

    // --- Types ------------------------------------------------------------------

    public function editType(array $params): void
    {
        $type = isset($params['id']) ? $this->definitions()->type((int) $params['id']) : null;
        if (isset($params['id']) && $type === null) {
            $this->redirect('/admin/fields');
            return;
        }

        $this->renderType($type, $type?->labels ?? [], $type?->position ?? 0, []);
    }

    public function saveType(array $params): void
    {
        $definitions = $this->definitions();
        $type = isset($params['id']) ? $definitions->type((int) $params['id']) : null;
        if (isset($params['id']) && $type === null) {
            $this->redirect('/admin/fields');
            return;
        }

        $labels = is_array($_POST['label'] ?? null) ? $_POST['label'] : [];
        $position = (int) ($_POST['position'] ?? 0);
        $result = $definitions->saveType($type?->id, $labels, $position, $this->app->locales->enabled(), $this->app->locales->default());

        if ($result['errors'] !== []) {
            $this->renderType($type, array_filter($labels, 'is_string'), $position, $this->messages($result['errors']));
            return;
        }

        Session::flash('success', $this->trans('fields.saved'));
        // A new type goes on to its fields.
        $this->redirect('/admin/fields/' . $result['id']);
    }

    public function deleteType(array $params): void
    {
        $type = $this->definitions()->type((int) $params['id']);
        if ($type === null) {
            $this->redirect('/admin/fields');
            return;
        }
        if ($this->app->offers->countOfType($type->offerType()) > 0) {
            Session::flash('error', $this->trans('fields.error.type_in_use'));
            $this->redirect('/admin/fields/' . $type->id);
            return;
        }

        $this->definitions()->deleteType($type->id);
        Session::flash('success', $this->trans('fields.type.deleted'));
        $this->redirect('/admin/fields');
    }

    /** @param array<string, mixed> $labels */
    private function renderType(?TypeDefinition $type, array $labels, int $position, array $errors): void
    {
        $locale = $this->app->translator->locale();
        $default = $this->app->locales->default();

        $this->render('@fields/admin_type.twig', [
            'type' => $type !== null ? [
                'id' => $type->id,
                'offer_type' => $type->offerType(),
                'offers' => $this->app->offers->countOfType($type->offerType()),
                'fields' => array_map(fn (Field $field) => [
                    'id' => $field->id,
                    'label' => $field->label($locale, $default),
                    'kind' => $field->kind,
                    'required' => $field->required,
                    'filterable' => $field->filterable,
                    'sortable' => $field->sortable,
                    'in_card' => $field->inCard,
                    'is_price' => $field->isPrice,
                ], $type->fields),
            ] : null,
            'labels' => $labels,
            'position' => $position,
            'locales' => $this->app->locales->enabled(),
            'default' => $default,
            'errors' => $errors,
            'max_fields' => Definitions::MAX_FIELDS,
        ]);
    }

    // --- Fields -----------------------------------------------------------------

    public function editField(array $params): void
    {
        $type = $this->definitions()->type((int) $params['id']);
        $field = isset($params['field']) ? $type?->field((int) $params['field']) : null;
        if ($type === null || (isset($params['field']) && $field === null)) {
            $this->redirect($type !== null ? '/admin/fields/' . $type->id : '/admin/fields');
            return;
        }

        $this->renderField($type, $field, $field !== null ? [
            'labels' => $field->labels,
            'kind' => $field->kind,
            'required' => $field->required,
            'filterable' => $field->filterable,
            'sortable' => $field->sortable,
            'in_card' => $field->inCard,
            'is_price' => $field->isPrice,
            'position' => $field->position,
            'options' => $field->options,
        ] : ['kind' => 'text', 'position' => count($type->fields) * 10, 'labels' => [], 'options' => []], []);
    }

    public function saveField(array $params): void
    {
        $definitions = $this->definitions();
        $type = $definitions->type((int) $params['id']);
        $field = isset($params['field']) ? $type?->field((int) $params['field']) : null;
        if ($type === null || (isset($params['field']) && $field === null)) {
            $this->redirect($type !== null ? '/admin/fields/' . $type->id : '/admin/fields');
            return;
        }

        $locales = $this->app->locales->enabled();
        $input = [
            'labels' => is_array($_POST['label'] ?? null) ? $_POST['label'] : [],
            'kind' => $_POST['kind'] ?? '',
            'required' => !empty($_POST['required']),
            'filterable' => !empty($_POST['filterable']),
            'sortable' => !empty($_POST['sortable']),
            'in_card' => !empty($_POST['in_card']),
            'is_price' => !empty($_POST['is_price']),
            'position' => (int) ($_POST['position'] ?? 0),
            'options' => is_array($_POST['options'] ?? null) ? $_POST['options'] : [],
        ];
        $result = $definitions->saveField($type->id, $field?->id, $input, $locales, $this->app->locales->default());

        if ($result['errors'] !== []) {
            $this->renderField($type, $field, [
                'labels' => array_filter($input['labels'], 'is_string'),
                'kind' => $field?->kind ?? (string) $input['kind'],
                'options' => $this->typedOptions($input['options']),
            ] + $input, $this->messages($result['errors']));
            return;
        }

        Session::flash('success', $this->trans('fields.saved'));
        $this->redirect('/admin/fields/' . $type->id);
    }

    public function deleteField(array $params): void
    {
        $this->definitions()->deleteField((int) $params['id'], (int) $params['field']);
        Session::flash('success', $this->trans('fields.field.deleted'));
        $this->redirect('/admin/fields/' . (int) $params['id']);
    }

    /** @param array<string, mixed> $form */
    private function renderField(TypeDefinition $type, ?Field $field, array $form, array $errors): void
    {
        $options = $form['options'] ?? [];
        // A few empty rows to add options to.
        for ($i = 0; $i < ($options === [] ? 4 : 3); $i++) {
            $options[] = ['value' => '', 'labels' => []];
        }

        $this->render('@fields/admin_field.twig', [
            'type_id' => $type->id,
            'field_id' => $field?->id,
            'form' => ['options' => $options] + $form,
            'kinds' => Field::KINDS,
            'locales' => $this->app->locales->enabled(),
            'default' => $this->app->locales->default(),
            'errors' => $errors,
        ]);
    }

    /**
     * The option rows as typed, to show them again.
     *
     * @return list<array{value: string, labels: array<string, string>}>
     */
    private function typedOptions(mixed $rows): array
    {
        $options = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $labels = is_array($row) && is_array($row['label'] ?? null) ? array_filter($row['label'], 'is_string') : [];
            if ($labels !== []) {
                $options[] = ['value' => is_string($row['value'] ?? null) ? $row['value'] : '', 'labels' => $labels];
            }
        }

        return $options;
    }

    /** @param string[] $keys @return string[] */
    private function messages(array $keys): array
    {
        return array_map(fn (string $key) => $this->trans($key, ['max' => Definitions::MAX_FIELDS]), $keys);
    }

    private function definitions(): Definitions
    {
        return new Definitions($this->app->db);
    }
}
