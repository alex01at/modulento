<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\Session;

/**
 * Administration -> Export/Import: a chosen part of the configuration an
 * administrator built by hand (Design, the home page, the main menu, and
 * whatever an extension adds through Registrar::exportable() - the
 * Landing-page addon's own page, for one) written to one file, or read
 * back from one - to back it up, or to carry it over to another
 * installation. Only what the file actually names is touched; everything
 * else about this installation stays exactly as it was.
 */
final class ExportController extends Controller
{
    private const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

    public function index(array $params): void
    {
        $app = $this->app;

        $sections = [];
        foreach ($app->exportables() as $key => $item) {
            $sections[] = ['key' => $key, 'label_key' => $item['label_key']];
        }

        $this->render('@admin/export.twig', ['sections' => $sections]);
    }

    public function export(array $params): void
    {
        $app = $this->app;
        $chosen = is_array($_POST['sections'] ?? null) ? array_filter($_POST['sections'], 'is_string') : [];

        $data = [];
        foreach ($app->exportables() as $key => $item) {
            if (in_array($key, $chosen, true)) {
                $data[$key] = ($item['export'])($app);
            }
        }

        if ($data === []) {
            Session::flash('error', $this->trans('core.export.error.none_chosen'));
            $this->redirect('/admin/export');
            return;
        }

        $payload = (string) json_encode([
            'modulento_export' => true,
            'core_version' => $app->coreVersion(),
            'exported_at' => gmdate('Y-m-d H:i'),
            'sections' => $data,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="modulento-export-' . gmdate('Y-m-d') . '.json"');
        header('Content-Length: ' . strlen($payload));
        echo $payload;
    }

    public function import(array $params): void
    {
        $app = $this->app;
        $file = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file($file['tmp_name'] ?? '')) {
            Session::flash('error', $this->trans('core.export.error.upload'));
            $this->redirect('/admin/export');
            return;
        }
        if (filesize($file['tmp_name']) > self::MAX_UPLOAD_BYTES) {
            Session::flash('error', $this->trans('core.export.error.upload_size'));
            $this->redirect('/admin/export');
            return;
        }

        $decoded = json_decode((string) file_get_contents($file['tmp_name']), true);
        if (!is_array($decoded) || ($decoded['modulento_export'] ?? null) !== true || !is_array($decoded['sections'] ?? null)) {
            Session::flash('error', $this->trans('core.export.error.invalid_file'));
            $this->redirect('/admin/export');
            return;
        }

        $applied = [];
        foreach ($app->exportables() as $key => $item) {
            if (array_key_exists($key, $decoded['sections'])) {
                ($item['import'])($decoded['sections'][$key], $app);
                $applied[] = $this->trans($item['label_key']);
            }
        }

        if ($applied === []) {
            Session::flash('error', $this->trans('core.export.error.nothing_matched'));
        } else {
            Session::flash('success', $this->trans('core.export.imported', ['names' => implode(', ', $applied)]));
        }
        $this->redirect('/admin/export');
    }
}
