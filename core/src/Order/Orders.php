<?php

declare(strict_types=1);

namespace Modulento\Core\Order;

use LogicException;
use Modulento\Core\App;
use Modulento\Core\Support\Clock;
use PDO;

/**
 * Orders, and the engine that moves them from state to state.
 *
 * Every change of state goes through apply(). It decides in one UPDATE
 * that only succeeds while the order is still in the expected state, so two
 * people clicking at the same time - or a click racing a deadline - cannot
 * both win.
 */
final class Orders
{
    /** As a transition's target: the state the order was in before the current one. */
    public const PREVIOUS = '@previous';
    public const ROLES = ['buyer', 'provider', 'admin', 'system'];

    /** @var array<string, OrderFlow> */
    private array $flows = [];
    /** @var array<string, PaymentMethod> */
    private array $paymentMethods = [];

    public function __construct(private PDO $db)
    {
        $this->registerPaymentMethod(new OfflinePayment());
    }

    public function registerFlow(OrderFlow $flow): void
    {
        if (isset($this->flows[$flow->id()])) {
            throw new LogicException('Order flow "' . $flow->id() . '" is already registered');
        }

        $this->flows[$flow->id()] = $flow;
    }

    public function flow(string $id): ?OrderFlow
    {
        return $this->flows[$id] ?? null;
    }

    /** The flow through which offers of a type become orders, or null if they cannot be ordered. */
    public function flowForOfferType(string $offerType): ?OrderFlow
    {
        foreach ($this->flows as $flow) {
            if ($flow->offerType() === $offerType) {
                return $flow;
            }
        }

        return null;
    }

    public function registerPaymentMethod(PaymentMethod $method): void
    {
        $this->paymentMethods[$method->id()] = $method;
    }

    /** @return array<string, PaymentMethod> */
    public function paymentMethods(): array
    {
        return $this->paymentMethods;
    }

    /**
     * @param array{id: int, email: string, display_name: ?string} $buyer
     * @param array<int, array{label: string, quantity: int, unit_price: int}> $items
     * @param array<string, mixed> $data
     */
    public function create(
        array $buyer,
        array $offer,
        string $offerTitle,
        OrderFlow $flow,
        array $items,
        array $data,
        string $paymentMethod,
        string $locale,
        ?string $note,
        bool $termsAccepted
    ): int {
        $now = Clock::now();
        $total = 0;
        foreach ($items as $item) {
            $total += $item['quantity'] * $item['unit_price'];
        }

        $state = $flow->initialState();
        $this->db->beginTransaction();

        $stmt = $this->db->prepare(
            'INSERT INTO orders (buyer_id, provider_id, offer_id, flow, state, state_actor, buyer_name, provider_name, offer_title,
                 total, currency, locale, payment_method, payment_state, data, terms_accepted_at, created_at, updated_at)
             VALUES (:buyer, :provider, :offer, :flow, :state, :actor, :buyer_name, :provider_name, :offer_title,
                 :total, :currency, :locale, :payment_method, :payment_state, :data, :terms, :now, :now2)'
        );
        $stmt->execute([
            'buyer' => $buyer['id'],
            'provider' => $offer['provider_id'],
            'offer' => $offer['id'],
            'flow' => $flow->id(),
            'state' => $state,
            'actor' => 'buyer',
            'buyer_name' => ($buyer['display_name'] ?? '') !== '' ? $buyer['display_name'] : $buyer['email'],
            'provider_name' => $offer['provider_name'],
            'offer_title' => mb_substr($offerTitle, 0, 150),
            'total' => $total,
            'currency' => $offer['currency'],
            'locale' => $locale,
            'payment_method' => $paymentMethod,
            'payment_state' => 'unpaid',
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'terms' => $termsAccepted ? $now : null,
            'now' => $now,
            'now2' => $now,
        ]);
        $id = (int) $this->db->lastInsertId();

        $insertItem = $this->db->prepare(
            'INSERT INTO order_item (order_id, position, label, quantity, unit_price) VALUES (:order, :position, :label, :quantity, :price)'
        );
        foreach (array_values($items) as $position => $item) {
            $insertItem->execute([
                'order' => $id, 'position' => $position, 'label' => mb_substr($item['label'], 0, 255),
                'quantity' => $item['quantity'], 'price' => $item['unit_price'],
            ]);
        }

        $this->recordEvent($id, 'place', null, $state, $buyer['id'], 'buyer', $note);
        $this->db->commit();

        $this->setDeadline($id, $flow, $state);

        return $id;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $order = $stmt->fetch();
        if (!$order) {
            return null;
        }

        $order = self::typed($order);

        $stmt = $this->db->prepare('SELECT * FROM order_item WHERE order_id = :id ORDER BY position');
        $stmt->execute(['id' => $id]);
        $order['items'] = $stmt->fetchAll();

        $stmt = $this->db->prepare('SELECT * FROM order_event WHERE order_id = :id ORDER BY id');
        $stmt->execute(['id' => $id]);
        $order['events'] = $stmt->fetchAll();

        $stmt = $this->db->prepare('SELECT * FROM order_message WHERE order_id = :id ORDER BY id');
        $stmt->execute(['id' => $id]);
        $order['messages'] = $stmt->fetchAll();

        return $order;
    }

    /**
     * @param string $role "buyer" (orders of the account), "provider"
     *        (orders for the provider id) or "admin" (all; $id ignored)
     * @return array{rows: array<int, array>, total: int}
     */
    public function list(string $role, ?int $id, ?string $state, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        if ($role === 'buyer') {
            $where[] = 'buyer_id = :id';
            $params['id'] = $id;
        } elseif ($role === 'provider') {
            $where[] = 'provider_id = :id';
            $params['id'] = $id;
        }
        if ($state !== null) {
            $where[] = 'state = :state';
            $params['state'] = $state;
        }
        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = $this->db->prepare("SELECT COUNT(*) FROM orders {$whereSql}");
        $count->execute($params);

        $stmt = $this->db->prepare(
            "SELECT * FROM orders {$whereSql} ORDER BY id DESC LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage)
        );
        $stmt->execute($params);

        return ['rows' => array_map([self::class, 'typed'], $stmt->fetchAll()), 'total' => (int) $count->fetchColumn()];
    }

    /** Language key of what an entry of the history says happened. */
    public function eventLabel(array $order, string $transition): string
    {
        if ($transition === 'place') {
            $flow = $this->flow($order['flow']);

            return $flow?->states()[$flow->initialState()]['entered'] ?? 'core.order.event.place';
        }

        $definition = $this->flow($order['flow'])?->transitions()[$transition] ?? null;

        return $definition['done'] ?? $definition['label'] ?? 'core.order.event.unknown';
    }

    /** Whether the order ended in a way that its buyer may review. */
    public function isReviewable(array $order): bool
    {
        return (bool) ($this->flow($order['flow'])?->states()[$order['state']]['reviewable'] ?? false);
    }

    /** Whether files may be attached to a transition. */
    public function acceptsFiles(array $order, string $transition): bool
    {
        return (bool) ($this->flow($order['flow'])?->transitions()[$transition]['files'] ?? false);
    }

    /** The id of the newest history entry - the one a transition just wrote. */
    public function lastEventId(int $orderId): ?int
    {
        $stmt = $this->db->prepare('SELECT MAX(id) FROM order_event WHERE order_id = :id');
        $stmt->execute(['id' => $orderId]);
        $id = $stmt->fetchColumn();

        return $id !== null && $id !== false ? (int) $id : null;
    }

    /** The side of an order an account is on, or null if it has nothing to do with it. */
    public function roleOf(array $order, int $accountId, ?int $providerIdOfAccount): ?string
    {
        if ($order['buyer_id'] === $accountId) {
            return 'buyer';
        }
        if ($providerIdOfAccount !== null && $order['provider_id'] === $providerIdOfAccount) {
            return 'provider';
        }

        return null;
    }

    public function isFinal(array $order): bool
    {
        $flow = $this->flow($order['flow']);

        return $flow === null || ($flow->states()[$order['state']]['final'] ?? false);
    }

    /**
     * The transitions a role can apply to an order right now.
     *
     * @return array<string, array{label: string, note: ?string, files: bool}> name => button label key, whether a note is asked and whether files can be attached
     */
    public function available(array $order, string $role, App $app): array
    {
        $flow = $this->flow($order['flow']);
        if ($flow === null) {
            return [];
        }

        $available = [];
        foreach ($flow->transitions() as $name => $transition) {
            if ($this->permits($flow, $name, $transition, $order, $role, $app)) {
                $available[$name] = ['label' => $transition['label'], 'note' => $transition['note'] ?? null, 'files' => $transition['files'] ?? false];
            }
        }

        return $available;
    }

    /**
     * Applies a transition.
     *
     * @return string|null language key of the reason it was refused, null on success
     */
    public function apply(int $orderId, string $transitionName, string $role, ?int $actorId, ?string $note, App $app): ?string
    {
        $order = $this->find($orderId);
        $flow = $order !== null ? $this->flow($order['flow']) : null;
        $transition = $flow?->transitions()[$transitionName] ?? null;

        if ($order === null || $flow === null || $transition === null || !$this->permits($flow, $transitionName, $transition, $order, $role, $app)) {
            return 'core.order.error.not_possible';
        }

        $note = $note !== null ? trim(str_replace("\r\n", "\n", $note)) : '';
        if (($transition['note'] ?? null) === 'required' && $note === '') {
            return 'core.order.error.note_required';
        }
        if (mb_strlen($note) > 5000) {
            return 'core.order.error.note_too_long';
        }
        if (!isset($transition['note'])) {
            $note = '';
        }

        $from = $order['state'];
        $to = $transition['to'] === self::PREVIOUS ? $order['previous_state'] : $transition['to'];
        if ($to === null || !isset($flow->states()[$to])) {
            return 'core.order.error.not_possible';
        }

        $now = Clock::now();
        $final = $flow->states()[$to]['final'] ?? false;

        $this->db->beginTransaction();

        // The WHERE on the old state is what makes this safe against a
        // second request doing the same or something else at the same time.
        $stmt = $this->db->prepare(
            'UPDATE orders SET state = :to, previous_state = :from, state_actor = :role, due_at = NULL, due_transition = NULL,
                 updated_at = :now, closed_at = :closed
             WHERE id = :id AND state = :expected'
        );
        $stmt->execute(['to' => $to, 'from' => $from, 'role' => $role, 'now' => $now, 'closed' => $final ? $now : null, 'id' => $orderId, 'expected' => $from]);

        if ($stmt->rowCount() !== 1) {
            $this->db->rollBack();

            return 'core.order.error.changed_meanwhile';
        }

        $this->recordEvent($orderId, $transitionName, $from, $to, $actorId, $role, $note !== '' ? $note : null);
        $this->db->commit();

        $this->setDeadline($orderId, $flow, $to);

        return null;
    }

    /** Applies the due transition of every order whose deadline has passed. @return int how many */
    public function runDeadlines(App $app): int
    {
        $stmt = $this->db->prepare('SELECT id, due_transition FROM orders WHERE due_at IS NOT NULL AND due_at <= :now ORDER BY due_at LIMIT 200');
        $stmt->execute(['now' => Clock::now()]);

        $applied = 0;
        foreach ($stmt->fetchAll() as $due) {
            $before = $this->find((int) $due['id']);
            if ($this->apply((int) $due['id'], (string) $due['due_transition'], 'system', null, null, $app) === null) {
                $applied++;
                OrderNotifier::stateChanged($app, $before, $this->find((int) $due['id']), (string) $due['due_transition'], 'system', null);
            } else {
                // A deadline that can no longer be applied must not be
                // tried again every few minutes.
                $clear = $this->db->prepare('UPDATE orders SET due_at = NULL, due_transition = NULL WHERE id = :id');
                $clear->execute(['id' => $due['id']]);
            }
        }

        return $applied;
    }

    /** @return int the message's id */
    public function addMessage(int $orderId, int $accountId, string $role, string $body): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO order_message (order_id, account_id, author_role, body, created_at) VALUES (:order, :account, :role, :body, :now)'
        );
        $stmt->execute(['order' => $orderId, 'account' => $accountId, 'role' => $role, 'body' => $body, 'now' => Clock::now()]);

        return (int) $this->db->lastInsertId();
    }

    /** Records that the order has been paid. Returns false if it already was. */
    public function markPaid(int $orderId): bool
    {
        $stmt = $this->db->prepare("UPDATE orders SET payment_state = 'paid', paid_at = :now, updated_at = :now2 WHERE id = :id AND payment_state = 'unpaid'");
        $stmt->execute(['now' => Clock::now(), 'now2' => Clock::now(), 'id' => $orderId]);

        return $stmt->rowCount() === 1;
    }

    /** Whether an account still has orders that are not finished, as buyer or as provider. */
    public function hasOpen(int $accountId, ?int $providerId): bool
    {
        $stmt = $this->db->prepare('SELECT flow, state FROM orders WHERE closed_at IS NULL AND (buyer_id = :account OR provider_id = :provider)');
        $stmt->execute(['account' => $accountId, 'provider' => $providerId ?? 0]);

        return $stmt->fetch() !== false;
    }

    /** @return array<string, int> state => number of orders */
    public function counts(): array
    {
        return array_map('intval', $this->db->query('SELECT state, COUNT(*) FROM orders GROUP BY state ORDER BY state')->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    private function permits(OrderFlow $flow, string $name, array $transition, array $order, string $role, App $app): bool
    {
        if (!in_array($order['state'], $transition['from'], true) || !in_array($role, $transition['actor'], true)) {
            return false;
        }

        // "by" ties an answer to a request: the side that asked cannot
        // accept its own request, and only it can withdraw it.
        $by = $transition['by'] ?? null;
        if ($by === 'counterparty' && $role === $order['state_actor']) {
            return false;
        }
        if ($by === 'initiator' && $role !== $order['state_actor']) {
            return false;
        }

        return $flow->allows($name, $order, $app);
    }

    private function setDeadline(int $orderId, OrderFlow $flow, string $state): void
    {
        $order = $this->find($orderId);
        $deadline = $order !== null ? $flow->deadline($state, $order) : null;
        if ($deadline === null) {
            return;
        }

        $stmt = $this->db->prepare('UPDATE orders SET due_at = :due, due_transition = :transition WHERE id = :id');
        $stmt->execute(['due' => Clock::now($deadline['seconds']), 'transition' => $deadline['transition'], 'id' => $orderId]);
    }

    private function recordEvent(int $orderId, string $transition, ?string $from, string $to, ?int $actorId, string $role, ?string $note): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO order_event (order_id, transition, from_state, to_state, actor_id, actor_role, note, created_at)
             VALUES (:order, :transition, :from, :to, :actor, :role, :note, :now)'
        );
        $stmt->execute([
            'order' => $orderId, 'transition' => $transition, 'from' => $from, 'to' => $to,
            'actor' => $actorId, 'role' => $role, 'note' => $note, 'now' => Clock::now(),
        ]);
    }

    private static function typed(array $order): array
    {
        foreach (['id', 'total'] as $field) {
            $order[$field] = (int) $order[$field];
        }
        foreach (['buyer_id', 'provider_id', 'offer_id'] as $field) {
            $order[$field] = $order[$field] !== null ? (int) $order[$field] : null;
        }
        $order['data'] = json_decode((string) $order['data'], true) ?: [];
        $order['number'] = sprintf('%06d', $order['id']);

        return $order;
    }
}
