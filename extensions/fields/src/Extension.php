<?php

declare(strict_types=1);

namespace Modulento\Fields;

use Modulento\Core\App;
use Modulento\Core\Extension\Extension as ExtensionContract;
use Modulento\Core\Extension\Registrar;
use Modulento\Core\Support\Router;

/**
 * Kinds of offer that the operator puts together in the administration, from
 * fields of their own: a hotel with stars and a price per night, an event
 * with a date. Each kind shows up in the catalogue like a kind of offer an
 * extension would bring, with its fields in the form, on the offer's page, on
 * the cards, and as filters and sorts of the list.
 *
 * Offers of these kinds are listed and asked about (messages to the
 * provider); they have no order flow of their own.
 */
final class Extension implements ExtensionContract
{
    public function register(Registrar $registrar): void
    {
        $registrar->permission('fields.manage', 'fields.permission.manage');

        $registrar->routes(function (Router $router): void {
            $router->get('/admin/fields', [AdminController::class, 'index'], 'fields.manage');
            $router->get('/admin/fields/new', [AdminController::class, 'editType'], 'fields.manage');
            $router->post('/admin/fields/new', [AdminController::class, 'saveType'], 'fields.manage');
            $router->get('/admin/fields/{id}', [AdminController::class, 'editType'], 'fields.manage');
            $router->post('/admin/fields/{id}', [AdminController::class, 'saveType'], 'fields.manage');
            $router->post('/admin/fields/{id}/delete', [AdminController::class, 'deleteType'], 'fields.manage');
            $router->get('/admin/fields/{id}/field/new', [AdminController::class, 'editField'], 'fields.manage');
            $router->post('/admin/fields/{id}/field/new', [AdminController::class, 'saveField'], 'fields.manage');
            $router->get('/admin/fields/{id}/field/{field}', [AdminController::class, 'editField'], 'fields.manage');
            $router->post('/admin/fields/{id}/field/{field}', [AdminController::class, 'saveField'], 'fields.manage');
            $router->post('/admin/fields/{id}/field/{field}/delete', [AdminController::class, 'deleteField'], 'fields.manage');
        });

        $registrar->adminMenu('fields.admin.menu', '/admin/fields', 'fields.manage', 'marketplace');

        $registrar->offerTypes(function (App $app): array {
            $values = new Values($app->db);
            $default = $app->locales->default();

            $types = [];
            foreach ((new Definitions($app->db))->types() as $definition) {
                $type = new CustomOfferType($definition, $values, $default);
                $types[] = $type;
                self::defineTexts($app, $definition, $type, $default);
            }

            return $types;
        });
    }

    /**
     * The names the core asks the translator for: the type's, and the labels
     * of the sorts the type adds, in every language of the site.
     */
    private static function defineTexts(App $app, TypeDefinition $definition, CustomOfferType $type, string $default): void
    {
        foreach ($app->locales->enabled() as $locale) {
            $texts = ['fields.type.' . $definition->key => $definition->label($locale, $default)];

            foreach ($definition->fields as $field) {
                foreach (['asc', 'desc'] as $direction) {
                    if ($field->sortable) {
                        $texts['fields.sort.' . $definition->key . '.' . $field->key . '.' . $direction] = $app->translator->trans(
                            'fields.sort.' . $direction,
                            ['field' => $field->label($locale, $default)],
                            $locale
                        );
                    }
                }
            }

            $app->translator->define('fields', $locale, $texts);
        }
    }
}
