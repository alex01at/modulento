<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Order\OrderFiles;
use Modulento\Core\Order\OrderNotifier;
use Modulento\Core\Review\ReviewView;
use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\Session;

/** Ordering an offer, and an order as buyer and provider see it. Every route here is login-only. */
final class OrderController extends Controller
{
    private const PER_PAGE = 30;

    // --- Placing an order -----------------------------------------------------

    public function form(array $params): void
    {
        $context = $this->orderable($params);
        if ($context !== null) {
            $this->renderForm($context, $_GET, []);
        }
    }

    public function place(array $params): void
    {
        $app = $this->app;
        $context = $this->orderable($params);
        if ($context === null) {
            return;
        }

        [$offer, $flow] = $context;
        $locale = $app->translator->locale();
        $built = $flow->build($offer, $_POST, $locale, $app);
        $errors = $built['errors'];

        $note = trim(str_replace("\r\n", "\n", (string) ($_POST['note'] ?? '')));
        if (mb_strlen($note) > 5000) {
            $errors[] = 'core.order.error.note_too_long';
        }

        $uploads = OrderFiles::uploads($_FILES['files'] ?? null);
        $fileProblem = OrderFiles::problem($uploads);
        if ($fileProblem !== null) {
            $errors[] = $fileProblem;
        }

        $methods = $app->orders->paymentMethods();
        $methodId = (string) ($_POST['payment_method'] ?? array_key_first($methods));
        if (!isset($methods[$methodId])) {
            $errors[] = 'core.order.error.payment_method';
        }

        // The button says that the order costs money; published terms have
        // to be accepted with it.
        $mustAccept = $app->pages->links('terms', $locale) !== [];
        if ($mustAccept && !isset($_POST['accept_terms'])) {
            $errors[] = 'core.register.error.terms';
        }
        if ($errors === [] && (new RateLimiter($app->db))->hit('order-place', (string) $app->auth->account()['id'], 20, 3600)) {
            $errors[] = 'core.error.too_many_requests';
        }

        if ($errors !== [] || $built['items'] === []) {
            $this->renderForm($context, $_POST, array_map(fn (string $key) => $this->trans($key, self::fileLimits()), array_unique($errors)));
            return;
        }

        $title = $app->offers->text($offer, $locale)['title'] ?? '';
        $orderId = $app->orders->create(
            $app->auth->account(), $offer, $title, $flow, $built['items'], $built['data'], $methodId, $locale, $note !== '' ? $note : null, $mustAccept
        );
        $app->orderFiles->store($orderId, $app->auth->account()['id'], 'buyer', $app->orders->lastEventId($orderId), null, $uploads);
        $order = $app->orders->find($orderId);

        OrderNotifier::stateChanged($app, null, $order, 'place', 'buyer', $note !== '' ? $note : null);

        $payUrl = $methods[$methodId]->begin($order, $app);
        if ($payUrl !== null) {
            header('Location: ' . $payUrl);
            return;
        }

        Session::flash('success', $this->trans('core.order.placed'));
        $this->redirect('/orders/' . $orderId);
    }

    /** @return array{0: array, 1: \Modulento\Core\Order\OrderFlow}|null the public offer and its flow, if the account may order it */
    private function orderable(array $params): ?array
    {
        $app = $this->app;
        $offer = $app->offers->findPublicBySlug($app->translator->locale(), $params['slug']);
        $flow = $offer !== null ? $app->orders->flowForOfferType($offer['type']) : null;

        if ($offer === null || $flow === null || !$flow->checkout()) {
            $this->notFound();
            return null;
        }
        if ($offer['account_id'] === $app->auth->account()['id']) {
            Session::flash('error', $this->trans('core.order.error.own_offer'));
            $this->redirect('/offers/' . $params['slug']);
            return null;
        }

        return [$offer, $flow];
    }

    private function renderForm(array $context, array $input, array $errors): void
    {
        [$offer, $flow] = $context;
        $app = $this->app;
        $locale = $app->translator->locale();
        $text = $app->offers->text($offer, $locale);
        $provider = $app->providers->find($offer['provider_id']);

        $this->render('order/new.twig', [
            'offer' => [
                'title' => $text['title'],
                'path' => '/offers/' . $text['slug'],
                'provider_name' => $offer['provider_name'],
                'provider_type' => $provider['type'] ?? 'business',
                'currency' => $offer['currency'],
            ],
            'flow_template' => $flow->orderFormTemplate(),
            'flow_data' => $flow->orderFormData($offer, $input, $locale, $app),
            'note' => (string) ($input['note'] ?? ''),
            'payment_methods' => array_map(
                fn ($method) => ['id' => $method->id(), 'label_key' => $method->labelKey(), 'description_key' => $method->descriptionKey()],
                array_values($app->orders->paymentMethods())
            ),
            'terms' => $app->pages->links('terms', $locale)[0] ?? null,
            'file_limits' => self::fileLimits(),
            'errors' => $errors,
        ]);
    }

    // --- Lists ----------------------------------------------------------------

    public function purchases(array $params): void
    {
        $this->renderList('buyer', $this->app->auth->account()['id']);
    }

    public function sales(array $params): void
    {
        $provider = $this->app->providers->findByAccount($this->app->auth->account()['id']);
        if ($provider === null) {
            $this->redirect('/account/provider');
            return;
        }

        $this->renderList('provider', (int) $provider['id']);
    }

    private function renderList(string $role, int $id): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $list = $this->app->orders->list($role, $id, null, $page, self::PER_PAGE);

        $this->render('order/index.twig', [
            'role' => $role,
            'orders' => array_map(fn (array $order) => $this->summary($order), $list['rows']),
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
        ]);
    }

    // --- One order --------------------------------------------------------------

    public function show(array $params): void
    {
        $found = $this->ownOrder($params);
        if ($found === null) {
            return;
        }

        [$order, $role] = $found;
        $app = $this->app;
        $locale = $app->translator->locale();
        $flow = $app->orders->flow($order['flow']);
        $method = $app->orders->paymentMethods()[$order['payment_method']] ?? null;
        $provider = $order['provider_id'] !== null ? $app->providers->find($order['provider_id']) : null;
        $buyer = $order['buyer_id'] !== null ? $app->accounts->findById($order['buyer_id']) : null;
        $files = $app->orderFiles->ofOrder($order['id']);
        $review = $app->reviews->findByOrder($order['id']);

        $this->render('order/show.twig', [
            'order' => $this->summary($order) + [
                'items' => $order['items'],
                'events' => array_map(fn (array $event) => [
                    'label_key' => $app->orders->eventLabel($order, $event['transition']),
                    'role' => $event['actor_role'],
                    'note' => $event['note'],
                    'at' => $event['created_at'],
                    'files' => $files['events'][(int) $event['id']] ?? [],
                ], $order['events']),
                'messages' => array_map(fn (array $message) => $message + ['files' => $files['messages'][(int) $message['id']] ?? []], $order['messages']),
                'payment_label_key' => $method?->labelKey(),
                'payment_description_key' => $method?->descriptionKey(),
                'final' => $app->orders->isFinal($order),
            ],
            'role' => $role,
            'actions' => $app->orders->available($order, $role, $app),
            'can_mark_paid' => $role === 'provider' && $order['payment_state'] === 'unpaid' && ($method?->confirmedByProvider() ?? false),
            // Each side sees how to reach the other: needed to settle
            // payment and invoice between them.
            'counterpart' => $role === 'buyer'
                ? ['name' => $order['provider_name'], 'email' => $provider['contact_email'] ?? $provider['account_email'] ?? null, 'path' => $provider !== null ? '/providers/' . $provider['slug'] : null]
                : ['name' => $order['buyer_name'], 'email' => $buyer['email'] ?? null, 'path' => null],
            'flow_template' => $flow?->orderDetailTemplate(),
            'flow_data' => $flow?->orderDetailData($order, $locale, $app) ?? [],
            'file_limits' => self::fileLimits(),
            'review' => $review !== null ? ReviewView::of($review) : null,
            'can_review' => $role === 'buyer' && $review === null && $app->orders->isReviewable($order),
            'can_reply' => $role === 'provider' && $review !== null && $review['reply'] === null && $review['status'] === 'published',
        ]);
    }

    public function transition(array $params): void
    {
        $found = $this->ownOrder($params);
        if ($found === null) {
            return;
        }

        [$order, $role] = $found;
        $app = $this->app;
        $name = (string) ($_POST['transition'] ?? '');
        $note = trim((string) ($_POST['note'] ?? ''));

        // Files are checked first: a refused file must not leave the
        // step done without it.
        $uploads = $app->orders->acceptsFiles($order, $name) ? OrderFiles::uploads($_FILES['files'] ?? null) : [];
        $problem = OrderFiles::problem($uploads)
            ?? $app->orders->apply($order['id'], $name, $role, $app->auth->account()['id'], $note, $app);

        if ($problem === null) {
            $app->orderFiles->store($order['id'], $app->auth->account()['id'], $role, $app->orders->lastEventId($order['id']), null, $uploads);
            OrderNotifier::stateChanged($app, $order, $app->orders->find($order['id']), $name, $role, $note !== '' ? $note : null);
            Session::flash('success', $this->trans('core.order.updated'));
        } else {
            Session::flash('error', $this->trans($problem, self::fileLimits()));
        }

        $this->redirect('/orders/' . $order['id']);
    }

    /** A file of the order, for its buyer and its provider only, always as a download. */
    public function download(array $params): void
    {
        $found = $this->ownOrder($params);
        if ($found === null) {
            return;
        }

        $file = $this->app->orderFiles->find($found[0]['id'], (int) $params['file']);
        if ($file === null) {
            $this->notFound();
            return;
        }

        self::sendFile($file);
    }

    /** @param array{path: string, name: string, size: int} $file */
    public static function sendFile(array $file): void
    {
        // Never shown by the browser, whatever the file claims to be: an
        // uploaded HTML or SVG file must not run in this site's name.
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename=\"" . preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']) . "\"; filename*=UTF-8''" . rawurlencode($file['name']));
        header('Content-Length: ' . $file['size']);
        header('Cache-Control: private, no-store');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        readfile($file['path']);
    }

    /** @return array{max: int, megabytes: int, types: string} for messages and hints about attachments */
    public static function fileLimits(): array
    {
        return [
            'max' => OrderFiles::MAX_FILES,
            'megabytes' => max(1, intdiv(OrderFiles::maxBytes(), 1024 * 1024)),
            'types' => implode(', ', OrderFiles::EXTENSIONS),
        ];
    }

    public function message(array $params): void
    {
        $found = $this->ownOrder($params);
        if ($found === null) {
            return;
        }

        [$order, $role] = $found;
        $app = $this->app;
        $accountId = $app->auth->account()['id'];
        $body = trim(str_replace("\r\n", "\n", (string) ($_POST['body'] ?? '')));

        $uploads = OrderFiles::uploads($_FILES['files'] ?? null);
        $fileProblem = OrderFiles::problem($uploads);

        // A message is text, files, or both.
        if (($body === '' && $uploads === []) || mb_strlen($body) > 5000) {
            Session::flash('error', $this->trans('core.order.message.error'));
        } elseif ($fileProblem !== null) {
            Session::flash('error', $this->trans($fileProblem, self::fileLimits()));
        } elseif ((new RateLimiter($app->db))->hit('order-message', (string) $accountId, 60, 3600)) {
            Session::flash('error', $this->trans('core.error.too_many_requests'));
        } else {
            $messageId = $app->orders->addMessage($order['id'], $accountId, $role, $body);
            $app->orderFiles->store($order['id'], $accountId, $role, null, $messageId, $uploads);
            OrderNotifier::message($app, $order, $role, $body !== '' ? $body : $this->trans('core.order.file.sent_files', ['count' => count($uploads)]));
        }

        $this->redirect('/orders/' . $order['id']);
    }

    /** The provider confirms having received a payment that did not go through the platform. */
    public function markPaid(array $params): void
    {
        $found = $this->ownOrder($params);
        if ($found === null) {
            return;
        }

        [$order, $role] = $found;
        $method = $this->app->orders->paymentMethods()[$order['payment_method']] ?? null;

        if ($role === 'provider' && $method !== null && $method->confirmedByProvider() && $this->app->orders->markPaid($order['id'])) {
            Session::flash('success', $this->trans('core.order.marked_paid'));
        }

        $this->redirect('/orders/' . $order['id']);
    }

    /** @return array{0: array, 1: string}|null the order and the side the logged-in account is on; anyone else gets a 404 */
    private function ownOrder(array $params): ?array
    {
        $app = $this->app;
        $accountId = $app->auth->account()['id'];
        $order = $app->orders->find((int) $params['id']);
        $provider = $app->providers->findByAccount($accountId);
        $role = $order !== null ? $app->orders->roleOf($order, $accountId, $provider !== null ? (int) $provider['id'] : null) : null;

        if ($role === null) {
            $this->notFound();
            return null;
        }

        return [$order, $role];
    }

    private function summary(array $order): array
    {
        $flow = $this->app->orders->flow($order['flow']);

        return [
            'id' => $order['id'],
            'number' => $order['number'],
            'title' => $order['offer_title'],
            'buyer_name' => $order['buyer_name'],
            'provider_name' => $order['provider_name'],
            'state' => $order['state'],
            'state_label_key' => $flow?->states()[$order['state']]['label'] ?? 'core.order.state_unknown',
            'total' => $order['total'],
            'currency' => $order['currency'],
            'payment_state' => $order['payment_state'],
            'created_at' => $order['created_at'],
            'due_at' => $order['due_at'],
        ];
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
    }
}
