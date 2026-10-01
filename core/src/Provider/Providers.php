<?php

declare(strict_types=1);

namespace Modulento\Core\Provider;

use Modulento\Core\Content\Pages;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Countries;
use Modulento\Core\Support\Locales;
use Modulento\Core\Support\Settings;
use PDO;

/**
 * Provider profiles: the account-side of "someone who offers something".
 * What is offered (gigs, dishes, auction lots) belongs to extensions; they
 * build on an approved provider.
 *
 * A profile is public only while its status is "approved". Whether a new
 * profile needs an administrator's approval is a setting; with approval
 * switched off, new profiles are approved at once.
 */
final class Providers
{
    public const SETTING_APPROVAL = 'core.provider_approval';
    public const STATUSES = ['pending', 'approved', 'rejected', 'suspended'];

    /** Identity and legal details. A change to any of them is flagged for the administration. */
    private const DETAIL_FIELDS = ['type', 'name', 'legal_name', 'street', 'postal_code', 'city', 'country', 'vat_id', 'tax_id', 'company_register'];
    private const MAX_LENGTHS = [
        'name' => 150, 'legal_name' => 200, 'street' => 200, 'postal_code' => 20, 'city' => 100,
        'contact_email' => 255, 'phone' => 50, 'vat_id' => 20, 'tax_id' => 50, 'company_register' => 100,
    ];

    public function __construct(private PDO $db, private Settings $settings, private Locales $locales)
    {
    }

    public function approvalRequired(): bool
    {
        return $this->settings->get(self::SETTING_APPROVAL, 'required') !== 'off';
    }

    /**
     * Switching approval off approves everyone who was waiting: from then
     * on a profile is active without a decision. Rejected and suspended
     * profiles stay as they are - those were decisions.
     *
     * @return int[] ids of the providers approved by this call
     */
    public function setApprovalRequired(bool $required): array
    {
        $this->settings->set(self::SETTING_APPROVAL, $required ? 'required' : 'off');
        if ($required) {
            return [];
        }

        $waiting = array_map('intval', $this->db->query("SELECT id FROM provider WHERE status = 'pending'")->fetchAll(PDO::FETCH_COLUMN));
        foreach ($waiting as $id) {
            $this->setStatus($id, 'approved', null, null);
        }

        return $waiting;
    }

    public function find(int $id): ?array
    {
        return $this->one('p.id = :value', $id);
    }

    public function findByAccount(int $accountId): ?array
    {
        return $this->one('p.account_id = :value', $accountId);
    }

    /** Only an approved profile of an active account has a public page. */
    public function findPublicBySlug(string $slug): ?array
    {
        $provider = $this->one('p.slug = :value', $slug);

        return $provider !== null && $provider['status'] === 'approved' && $provider['account_status'] === 'active' ? $provider : null;
    }

    /**
     * @param string|null $status one of self::STATUSES, null for all
     * @return array{rows: array<int, array>, total: int}
     */
    public function list(?string $status, int $page, int $perPage, bool $publicOnly = false): array
    {
        $where = [];
        $params = [];
        if ($status !== null) {
            $where[] = 'p.status = :status';
            $params['status'] = $status;
        }
        if ($publicOnly) {
            $where[] = "p.status = 'approved' AND a.status = 'active'";
        }
        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = $this->db->prepare("SELECT COUNT(*) FROM provider p JOIN account a ON a.id = p.account_id {$whereSql}");
        $count->execute($params);

        $stmt = $this->db->prepare(
            "SELECT p.*, a.email AS account_email, a.status AS account_status
             FROM provider p JOIN account a ON a.id = p.account_id {$whereSql}
             ORDER BY p.name, p.id LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage)
        );
        $stmt->execute($params);

        return ['rows' => array_map(fn (array $row) => $this->withTexts($row), $stmt->fetchAll()), 'total' => (int) $count->fetchColumn()];
    }

    /** @return array<string, int> status => number of profiles */
    public function counts(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->db->query('SELECT status, COUNT(*) FROM provider GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR) as $status => $count) {
            $counts[$status] = (int) $count;
        }

        return $counts;
    }

    /**
     * Checks and tidies what was typed into the profile form.
     *
     * @param array<string, mixed> $input
     * @return array{values: array<string, mixed>, texts: array<string, array{headline: string, description: string}>, errors: string[]}
     *         errors are language keys
     */
    public function validate(array $input, bool $alreadyCertified): array
    {
        $values = [];
        foreach (array_keys(self::MAX_LENGTHS) as $field) {
            $values[$field] = trim((string) ($input[$field] ?? ''));
        }
        $values['type'] = ($input['type'] ?? '') === 'business' ? 'business' : (($input['type'] ?? '') === 'private' ? 'private' : '');
        $values['country'] = strtoupper(trim((string) ($input['country'] ?? '')));
        $values['vat_id'] = strtoupper((string) preg_replace('/[\s.\-]/', '', $values['vat_id']));
        $values['self_certified'] = isset($input['self_certified']);

        $errors = [];
        if ($values['type'] === '') {
            $errors[] = 'core.provider.error.type';
        }
        // A private person offers under their own name, so one name is enough.
        if ($values['type'] === 'private' && $values['legal_name'] === '') {
            $values['legal_name'] = $values['name'];
        }
        foreach (['name', 'legal_name', 'street', 'postal_code', 'city'] as $field) {
            if ($values[$field] === '') {
                $errors[] = 'core.provider.error.required';
                break;
            }
        }
        foreach (self::MAX_LENGTHS as $field => $max) {
            if (mb_strlen($values[$field]) > $max) {
                $errors[] = 'core.provider.error.too_long';
                break;
            }
        }
        if (!Countries::isValid($values['country'])) {
            $errors[] = 'core.provider.error.country';
        }
        if ($values['contact_email'] !== '' && filter_var($values['contact_email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'core.provider.error.contact_email';
        }
        if ($values['vat_id'] !== '' && preg_match('/^[A-Z]{2}[A-Z0-9]{2,13}$/', $values['vat_id']) !== 1) {
            $errors[] = 'core.provider.error.vat_id';
        }
        if ($values['type'] === 'business') {
            // A business must be reachable and must have confirmed that
            // its offers comply with the law.
            if ($values['contact_email'] === '') {
                $errors[] = 'core.provider.error.business_contact';
            }
            if (!$values['self_certified'] && !$alreadyCertified) {
                $errors[] = 'core.provider.error.self_certified';
            }
        }

        $texts = [];
        foreach ($this->locales->enabled() as $locale) {
            $text = is_array($input['text'][$locale] ?? null) ? $input['text'][$locale] : [];
            $headline = trim((string) ($text['headline'] ?? ''));
            $description = trim(str_replace("\r\n", "\n", (string) ($text['description'] ?? '')));
            if ($headline === '' && $description === '') {
                continue;
            }
            if (mb_strlen($headline) > 200 || mb_strlen($description) > 5000) {
                $errors[] = 'core.provider.error.too_long';
                continue;
            }
            $texts[$locale] = ['headline' => $headline, 'description' => $description];
        }

        return ['values' => $values, 'texts' => $texts, 'errors' => array_values(array_unique($errors))];
    }

    /**
     * Creates the account's profile or updates it, from validate()'s output.
     *
     * @return array{id: int, created: bool, status: string}
     */
    public function save(int $accountId, array $values, array $texts): array
    {
        $existing = $this->findByAccount($accountId);
        $now = Clock::now();
        $nullable = fn (string $value) => $value !== '' ? $value : null;

        $columns = [
            'type' => $values['type'],
            'name' => $values['name'],
            'legal_name' => $values['legal_name'],
            'street' => $values['street'],
            'postal_code' => $values['postal_code'],
            'city' => $values['city'],
            'country' => $values['country'],
            'contact_email' => $nullable($values['contact_email']),
            'phone' => $nullable($values['phone']),
            'vat_id' => $nullable($values['vat_id']),
            'tax_id' => $nullable($values['tax_id']),
            'company_register' => $nullable($values['company_register']),
            'updated_at' => $now,
        ];

        $this->db->beginTransaction();

        if ($existing === null) {
            $columns += [
                'account_id' => $accountId,
                'status' => $this->approvalRequired() ? 'pending' : 'approved',
                'slug' => $this->uniqueSlug($values['name']),
                'self_certified_at' => $values['self_certified'] ? $now : null,
                'details_changed_at' => $now,
                'decided_at' => $this->approvalRequired() ? null : $now,
                'created_at' => $now,
            ];
            $stmt = $this->db->prepare(
                'INSERT INTO provider (' . implode(', ', array_keys($columns)) . ') VALUES (:' . implode(', :', array_keys($columns)) . ')'
            );
            $stmt->execute($columns);
            $id = (int) $this->db->lastInsertId();
            $status = $columns['status'];
        } else {
            $id = (int) $existing['id'];
            $status = $existing['status'];

            foreach (self::DETAIL_FIELDS as $field) {
                if ((string) $existing[$field] !== (string) $columns[$field]) {
                    $columns['details_changed_at'] = $now;
                    break;
                }
            }
            if ($values['self_certified'] && $existing['self_certified_at'] === null) {
                $columns['self_certified_at'] = $now;
            }
            // A rejected profile that was corrected asks for a new decision.
            if ($status === 'rejected') {
                $status = $this->approvalRequired() ? 'pending' : 'approved';
                $columns['status'] = $status;
                $columns['status_note'] = null;
            }

            $assignments = implode(', ', array_map(fn (string $column) => "{$column} = :{$column}", array_keys($columns)));
            $stmt = $this->db->prepare("UPDATE provider SET {$assignments} WHERE id = :id");
            $stmt->execute($columns + ['id' => $id]);
        }

        $delete = $this->db->prepare('DELETE FROM provider_translation WHERE provider_id = :id');
        $delete->execute(['id' => $id]);
        $insert = $this->db->prepare(
            'INSERT INTO provider_translation (provider_id, locale, headline, description) VALUES (:id, :locale, :headline, :description)'
        );
        foreach ($texts as $locale => $text) {
            $insert->execute(['id' => $id, 'locale' => $locale] + $text);
        }

        $this->db->commit();

        return ['id' => $id, 'created' => $existing === null, 'status' => $status];
    }

    /** @param int|null $decidedBy the administrator's account, null for a change made by the system */
    public function setStatus(int $id, string $status, ?string $note, ?int $decidedBy): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE provider SET status = :status, status_note = :note, decided_at = :now, decided_by = :by, updated_at = :now2 WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'now' => Clock::now(),
            'by' => $decidedBy,
            'now2' => Clock::now(),
            'id' => $id,
        ]);
    }

    /** The self-description in a language, or in the default language, or whichever exists. */
    public static function text(array $provider, string $locale, string $defaultLocale): ?array
    {
        return $provider['texts'][$locale] ?? $provider['texts'][$defaultLocale] ?? (array_values($provider['texts'])[0] ?? null);
    }

    private function one(string $condition, int|string $value): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT p.*, a.email AS account_email, a.status AS account_status, a.locale AS account_locale
             FROM provider p JOIN account a ON a.id = p.account_id WHERE {$condition}"
        );
        $stmt->execute(['value' => $value]);
        $row = $stmt->fetch();

        return $row ? $this->withTexts($row) : null;
    }

    private function withTexts(array $row): array
    {
        $stmt = $this->db->prepare('SELECT locale, headline, description FROM provider_translation WHERE provider_id = :id');
        $stmt->execute(['id' => $row['id']]);

        $row['texts'] = [];
        foreach ($stmt->fetchAll() as $text) {
            $row['texts'][$text['locale']] = ['headline' => $text['headline'], 'description' => $text['description']];
        }
        $row['changed_since_decision'] = $row['decided_at'] !== null && $row['details_changed_at'] > $row['decided_at'];

        return $row;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Pages::slugify($name) ?: 'provider';
        $slug = $base;
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM provider WHERE slug = :slug');

        for ($suffix = 2; ; $suffix++) {
            $stmt->execute(['slug' => $slug]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $slug;
            }
            $slug = $base . '-' . $suffix;
        }
    }
}
