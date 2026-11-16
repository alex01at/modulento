<?php

declare(strict_types=1);

namespace Modulento\Core\Controller\Api;

use Modulento\Core\Controller\Controller;

/**
 * Base class of the mobile API's controllers. Every answer is JSON in one
 * of two shapes: {"data": ...} or {"error": {"code": ..., "message": ...}}.
 */
abstract class ApiController extends Controller
{
    private ?array $input = null;

    protected function data(array $data, int $status = 200): void
    {
        $this->json(['data' => $data], $status);
    }

    protected function fail(string $code, string $messageKey, int $status): void
    {
        $this->json(['error' => ['code' => $code, 'message' => $this->trans($messageKey)]], $status);
    }

    /**
     * A field of the request, as text: sent as a form field or, with the
     * content type application/json, as a key of a JSON object.
     */
    protected function input(string $name): ?string
    {
        if ($this->input === null) {
            $this->input = $_POST;
            if (str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
                $body = json_decode((string) file_get_contents('php://input'), true);
                $this->input = is_array($body) ? $body + $_POST : $_POST;
            }
        }

        $value = $this->input[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * The account as an app gets to see it.
     *
     * @return array{id: int, email: string, display_name: ?string, locale: string, created_at: string, demo_mode: bool}
     */
    protected static function accountData(array $account): array
    {
        return [
            'id' => (int) $account['id'],
            'email' => (string) $account['email'],
            'display_name' => $account['display_name'] ?? null,
            'locale' => (string) $account['locale'],
            'created_at' => (string) $account['created_at'],
            'demo_mode' => in_array($account['demo_mode'] ?? false, [true, 1, '1'], true),
        ];
    }
}
