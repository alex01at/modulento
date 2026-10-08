<?php

declare(strict_types=1);

namespace Modulento\Core\Request;

use Modulento\Core\App;
use Modulento\Core\Support\Clock;
use PDO;

/**
 * A provider's proposal on a request - a price and a message, not a
 * thread: like a real application, submitted once (editable while nobody
 * has decided yet), not negotiated back and forth beforehand. Once the
 * buyer accepts one, it becomes a real order - see accept().
 */
final class RequestApplications
{
    public function __construct(private PDO $db)
    {
    }

    /** @return int the application's id (new or the provider's existing one, updated) */
    public function add(int $requestId, int $providerId, int $price, ?int $deliveryDays, string $message): int
    {
        $existing = $this->db->prepare("SELECT id FROM request_application WHERE request_id = :request AND provider_id = :provider AND status = 'submitted'");
        $existing->execute(['request' => $requestId, 'provider' => $providerId]);
        $id = $existing->fetchColumn();

        $now = Clock::now();
        if ($id !== false) {
            $this->db->prepare('UPDATE request_application SET price = :price, delivery_days = :days, message = :message, updated_at = :now WHERE id = :id')
                ->execute(['price' => $price, 'days' => $deliveryDays, 'message' => $message, 'now' => $now, 'id' => $id]);

            return (int) $id;
        }

        $this->db->prepare(
            'INSERT INTO request_application (request_id, provider_id, price, delivery_days, message, created_at, updated_at)
             VALUES (:request, :provider, :price, :days, :message, :now, :now2)'
        )->execute(['request' => $requestId, 'provider' => $providerId, 'price' => $price, 'days' => $deliveryDays, 'message' => $message, 'now' => $now, 'now2' => $now]);

        return (int) $this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ra.*, p.account_id, p.name AS provider_name, p.slug AS provider_slug, p.status AS provider_status
             FROM request_application ra JOIN provider p ON p.id = ra.provider_id WHERE ra.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->cast($row) : null;
    }

    /** Every application on a request, newest first - for the buyer choosing one. */
    public function forRequest(int $requestId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ra.*, p.account_id, p.name AS provider_name, p.slug AS provider_slug, p.status AS provider_status
             FROM request_application ra JOIN provider p ON p.id = ra.provider_id WHERE ra.request_id = :request ORDER BY ra.id DESC'
        );
        $stmt->execute(['request' => $requestId]);

        return array_map(fn (array $r) => $this->cast($r), $stmt->fetchAll());
    }

    /** A provider's own applications, newest first - "my applications". */
    public function forProvider(int $providerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ra.*, r.title AS request_title, r.slug AS request_slug, r.status AS request_status, r.currency AS request_currency
             FROM request_application ra JOIN request r ON r.id = ra.request_id WHERE ra.provider_id = :provider ORDER BY ra.id DESC'
        );
        $stmt->execute(['provider' => $providerId]);

        return array_map(function (array $r) {
            $r['id'] = (int) $r['id'];
            $r['request_id'] = (int) $r['request_id'];
            $r['price'] = (int) $r['price'];

            return $r;
        }, $stmt->fetchAll());
    }

    /** Whether this provider already has an open application on this request. */
    public function hasApplied(int $requestId, int $providerId): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM request_application WHERE request_id = :request AND provider_id = :provider AND status = 'submitted'");
        $stmt->execute(['request' => $requestId, 'provider' => $providerId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function withdraw(int $id): void
    {
        $this->db->prepare("UPDATE request_application SET status = 'withdrawn', updated_at = :now WHERE id = :id AND status = 'submitted'")
            ->execute(['now' => Clock::now(), 'id' => $id]);
    }

    /**
     * Turns an application into a real order - the buyer's own choice,
     * with a payment method and accepted terms, exactly like a regular
     * checkout (Orders::create(), see OrderController::place()); only the
     * price and the provider are already fixed by the application, so
     * there is nothing left for a flow's own form to ask.
     *
     * @return int the new order's id
     */
    public function accept(array $application, array $request, array $buyer, string $paymentMethod, string $locale, bool $termsAccepted, App $app): int
    {
        $flow = $app->orders->flow(RequestFlow::ID);
        $orderId = $app->orders->create(
            $buyer,
            ['id' => null, 'provider_id' => $application['provider_id'], 'provider_name' => $application['provider_name'], 'currency' => $request['currency']],
            $request['title'],
            $flow,
            [['label' => $request['title'], 'quantity' => 1, 'unit_price' => $application['price']]],
            ['request_id' => $request['id'], 'application_id' => $application['id']],
            $paymentMethod,
            $locale,
            null,
            $termsAccepted
        );

        $now = Clock::now();
        $this->db->prepare("UPDATE request_application SET status = 'accepted', updated_at = :now WHERE id = :id")
            ->execute(['now' => $now, 'id' => $application['id']]);
        $this->db->prepare("UPDATE request_application SET status = 'declined', updated_at = :now WHERE request_id = :request AND id <> :id AND status = 'submitted'")
            ->execute(['now' => $now, 'request' => $request['id'], 'id' => $application['id']]);

        return $orderId;
    }

    private function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['request_id'] = (int) $row['request_id'];
        $row['provider_id'] = (int) $row['provider_id'];
        $row['account_id'] = (int) $row['account_id'];
        $row['price'] = (int) $row['price'];
        $row['delivery_days'] = $row['delivery_days'] !== null ? (int) $row['delivery_days'] : null;

        return $row;
    }
}
