<?php

declare(strict_types=1);

namespace Modulento\Core\Account;

use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Countries;
use PDO;

/**
 * A buyer's own company details - entirely optional, for their records and
 * for whoever they deal with. Separate from Provider\Providers, which is
 * about someone *offering* something, not buying: a company that only ever
 * orders never needs a provider profile.
 */
final class BillingProfile
{
    private const MAX_LENGTHS = ['company_name' => 150, 'website' => 255, 'vat_id' => 20, 'tax_id' => 50, 'street' => 200, 'postal_code' => 20, 'city' => 100];

    public function __construct(private PDO $db)
    {
    }

    public function find(int $accountId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM account_billing_profile WHERE account_id = :id');
        $stmt->execute(['id' => $accountId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{values: array<string, ?string>, errors: string[]} errors are language keys
     */
    public function validate(array $input): array
    {
        $values = [];
        foreach (array_keys(self::MAX_LENGTHS) as $field) {
            $values[$field] = trim((string) ($input[$field] ?? ''));
        }
        $values['country'] = strtoupper(trim((string) ($input['country'] ?? '')));
        $values['vat_id'] = strtoupper((string) preg_replace('/[\s.\-]/', '', $values['vat_id']));

        $errors = [];
        foreach (self::MAX_LENGTHS as $field => $max) {
            if (mb_strlen($values[$field]) > $max) {
                $errors[] = 'core.account.billing.error.too_long';
                break;
            }
        }
        if ($values['website'] !== '' && filter_var($values['website'], FILTER_VALIDATE_URL) === false) {
            $errors[] = 'core.account.billing.error.website';
        }
        if ($values['vat_id'] !== '' && preg_match('/^[A-Z]{2}[A-Z0-9]{2,13}$/', $values['vat_id']) !== 1) {
            $errors[] = 'core.account.billing.error.vat_id';
        }
        if ($values['country'] !== '' && !Countries::isValid($values['country'])) {
            $errors[] = 'core.account.billing.error.country';
        }

        return ['values' => $values, 'errors' => array_values(array_unique($errors))];
    }

    /** @param array<string, string> $values from validate()'s "values" */
    public function save(int $accountId, array $values): void
    {
        $nullable = fn (string $value) => $value !== '' ? $value : null;
        $columns = [
            'company_name' => $nullable($values['company_name']),
            'website' => $nullable($values['website']),
            'vat_id' => $nullable($values['vat_id']),
            'tax_id' => $nullable($values['tax_id']),
            'street' => $nullable($values['street']),
            'postal_code' => $nullable($values['postal_code']),
            'city' => $nullable($values['city']),
            'country' => $nullable($values['country']),
            'updated_at' => Clock::now(),
        ];

        $exists = $this->find($accountId) !== null;
        if ($exists) {
            $assignments = implode(', ', array_map(fn (string $column) => "{$column} = :{$column}", array_keys($columns)));
            $stmt = $this->db->prepare("UPDATE account_billing_profile SET {$assignments} WHERE account_id = :id");
            $stmt->execute($columns + ['id' => $accountId]);
        } else {
            $columns = ['account_id' => $accountId] + $columns;
            $stmt = $this->db->prepare(
                'INSERT INTO account_billing_profile (' . implode(', ', array_keys($columns)) . ') VALUES (:' . implode(', :', array_keys($columns)) . ')'
            );
            $stmt->execute($columns);
        }
    }
}
