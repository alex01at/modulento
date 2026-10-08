<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Order\OrderNotifier;
use Modulento\Core\Payment\Payments;
use Modulento\Core\Support\Money;
use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\Session;
use RuntimeException;

/** Requests: a buyer describes what they need, providers apply, the buyer accepts one. */
final class RequestController extends Controller
{
    private const PER_PAGE = 24;
    private const MAX_OPEN = 20;

    // --- Public ----------------------------------------------------------------

    public function index(array $params): void
    {
        $locale = $this->app->translator->locale();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $categoryId = (int) ($_GET['category'] ?? 0);

        $list = $this->app->requests->listPublic([
            'category_ids' => $categoryId > 0 ? $this->app->categories->withChildren($categoryId) : [],
        ], $page, self::PER_PAGE);

        $this->render('request/index.twig', [
            'requests' => array_map(fn (array $r) => $this->card($r), $list['rows']),
            'total' => $list['total'],
            'categories' => $this->app->categories->tree($locale),
            'category_id' => $categoryId,
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
        ]);
    }

    public function show(array $params): void
    {
        $app = $this->app;
        $request = $app->requests->findBySlug((string) $params['slug']);
        if ($request === null || !$app->requests->isPublic($request)) {
            $this->notFound();
            return;
        }

        $accountId = $app->auth->account()['id'] ?? null;
        $provider = $accountId !== null ? $app->providers->findByAccount($accountId) : null;
        $category = $request['category_id'] !== null ? $app->categories->find($request['category_id']) : null;
        $locale = $app->translator->locale();

        $this->render('request/show.twig', [
            'request' => $this->card($request),
            'category' => $category !== null ? $app->categories->view($category, $locale) : null,
            'own' => $accountId === (int) $request['account_id'],
            'provider' => $provider,
            'already_applied' => $provider !== null && $app->requestApplications->hasApplied($request['id'], (int) $provider['id']),
        ]);
    }

    public function apply(array $params): void
    {
        $app = $this->app;
        $request = $app->requests->findBySlug((string) $params['slug']);
        if ($request === null || !$app->requests->isPublic($request)) {
            $this->notFound();
            return;
        }

        $provider = $this->provider();
        if ($provider === null) {
            return;
        }
        if ((int) $request['account_id'] === (int) $provider['account_id']) {
            $this->redirect($this->safeReturn('/requests/' . $request['slug']));
            return;
        }

        $price = Money::parse((string) ($_POST['price'] ?? ''));
        $deliveryDays = (int) ($_POST['delivery_days'] ?? 0);
        $message = trim(str_replace("\r\n", "\n", (string) ($_POST['message'] ?? '')));

        if ($price === null || $price < 1 || $price > 100_000_000 || mb_strlen($message) < 1 || mb_strlen($message) > 3000
            || (new RateLimiter($app->db))->hit('request-apply', (string) $provider['account_id'], 30, 3600)) {
            Session::flash('error', $this->trans('core.request.apply.error'));
            $this->redirect($this->safeReturn('/requests/' . $request['slug']));
            return;
        }

        $isNew = !$app->requestApplications->hasApplied($request['id'], (int) $provider['id']);
        $app->requestApplications->add($request['id'], (int) $provider['id'], $price, $deliveryDays > 0 ? $deliveryDays : null, $message);
        if ($isNew) {
            $app->notifications->create((int) $request['account_id'], 'request_application', 'core.notification.request_application', [
                'title' => $request['title'],
            ], '/account/requests/' . $request['id'] . '/applications');
        }

        Session::flash('success', $this->trans('core.request.apply.sent'));
        $this->redirect($this->safeReturn('/requests/' . $request['slug']));
    }

    // --- The buyer's own requests ------------------------------------------------

    public function mine(array $params): void
    {
        $app = $this->app;
        $accountId = $app->auth->account()['id'];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $list = $app->requests->listAll($accountId, null, $page, self::PER_PAGE);

        $this->render('account/requests.twig', [
            'requests' => array_map(fn (array $r) => $this->card($r), $list['rows']),
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
        ]);
    }

    public function create(array $params): void
    {
        $this->renderForm(null, []);
    }

    public function save(array $params): void
    {
        $app = $this->app;
        $accountId = $app->auth->account()['id'];
        $existing = isset($params['id']) ? $this->own($params) : null;
        if (isset($params['id']) && $existing === null) {
            return;
        }

        $result = $this->validated($_POST);
        if ($result['errors'] !== []) {
            $this->renderForm($existing, array_map(fn (string $key) => $this->trans($key), $result['errors']), $_POST);
            return;
        }

        $published = false;
        if ($existing === null) {
            if ($app->requests->listAll($accountId, null, 1, 1)['total'] >= self::MAX_OPEN) {
                Session::flash('error', $this->trans('core.request.error.too_many', ['max' => self::MAX_OPEN]));
                $this->redirect('/account/requests');
                return;
            }
            $id = $app->requests->create($accountId, $result['category_id'], $result['title'], $result['description'], $result['budget_min'], $result['budget_max'], $result['needed_by']);
            $published = !$app->requests->approvalRequired();
        } else {
            $app->requests->update($existing['id'], $result['category_id'], $result['title'], $result['description'], $result['budget_min'], $result['budget_max'], $result['needed_by']);
            $id = $existing['id'];
            // A corrected, previously rejected request is resubmitted by
            // saving it again - there is no separate wizard or submit step
            // here, unlike an offer's draft/submit.
            if ($existing['status'] === 'rejected') {
                $published = !$app->requests->approvalRequired();
                $app->requests->setStatus($id, $published ? 'published' : 'pending', null, null);
            }
        }
        if ($published) {
            AdminRequestController::notifySubscribers($app, $app->requests->find($id));
        }

        Session::flash('success', $this->trans($existing === null ? 'core.request.saved_new' : 'core.request.saved'));
        $this->redirect('/account/requests/' . $id . '/edit');
    }

    public function edit(array $params): void
    {
        $request = $this->own($params);
        if ($request === null) {
            return;
        }

        $this->renderForm($request, []);
    }

    public function delete(array $params): void
    {
        $request = $this->own($params);
        if ($request === null) {
            return;
        }
        if ($request['order_id'] !== null) {
            $this->redirect('/account/requests/' . $request['id'] . '/edit');
            return;
        }

        $this->app->requests->delete($request['id']);
        Session::flash('success', $this->trans('core.request.deleted'));
        $this->redirect('/account/requests');
    }

    public function applications(array $params): void
    {
        $request = $this->own($params);
        if ($request === null) {
            return;
        }

        $this->render('account/request_applications.twig', [
            'request' => $this->card($request),
            'applications' => $this->app->requestApplications->forRequest($request['id']),
        ]);
    }

    /** The small confirmation page: payment method, terms - everything else is already fixed by the application. */
    public function confirmAccept(array $params): void
    {
        $app = $this->app;
        [$request, $application] = $this->ownApplication($params) ?? [null, null];
        if ($request === null) {
            return;
        }

        $this->render('account/request_accept.twig', [
            'request' => $this->card($request),
            'application' => $application,
            'methods' => array_map(fn ($m) => ['id' => $m->id(), 'label_key' => $m->labelKey()], $app->payments->availableFor($application['provider_id'], $app)),
            'payment_method' => null,
            'terms' => $app->pages->links('terms', $app->translator->locale())[0] ?? null,
            'errors' => [],
        ]);
    }

    public function accept(array $params): void
    {
        $app = $this->app;
        [$request, $application] = $this->ownApplication($params) ?? [null, null];
        if ($request === null) {
            return;
        }

        $locale = $app->translator->locale();
        $methods = $app->payments->availableFor($application['provider_id'], $app);
        $chosen = $_POST['payment_method'] ?? (count($methods) === 1 ? array_key_first($methods) : '');
        $methodId = is_string($chosen) ? $chosen : '';
        $errors = [];
        if ($methods === []) {
            $errors[] = 'core.order.error.no_payment_method';
        } elseif (!isset($methods[$methodId])) {
            $errors[] = 'core.order.error.payment_method';
        }
        $mustAccept = $app->pages->links('terms', $locale) !== [];
        if ($mustAccept && !isset($_POST['accept_terms'])) {
            $errors[] = 'core.register.error.terms';
        }

        if ($errors !== []) {
            $this->render('account/request_accept.twig', [
                'request' => $this->card($request),
                'application' => $application,
                'methods' => array_map(fn ($m) => ['id' => $m->id(), 'label_key' => $m->labelKey()], $methods),
                'payment_method' => $methodId,
                'terms' => $app->pages->links('terms', $locale)[0] ?? null,
                'errors' => array_map(fn (string $key) => $this->trans($key), array_unique($errors)),
            ]);
            return;
        }

        $orderId = $app->requestApplications->accept($application, $request, $app->auth->account(), $methodId, $locale, $mustAccept, $app);
        $app->requests->fulfil($request['id'], $application['id'], $orderId);

        $order = $app->orders->find($orderId);
        OrderNotifier::stateChanged($app, null, $order, 'place', 'buyer', null);
        foreach ($app->requestApplications->forRequest($request['id']) as $other) {
            if ($other['status'] === 'declined') {
                $app->notifications->create((int) $other['account_id'], 'request_declined', 'core.notification.request_declined', [
                    'title' => $request['title'],
                ], '/account/applications');
            }
        }

        Session::flash('success', $this->trans('core.request.accepted'));

        try {
            $payUrl = $methods[$methodId]->begin($order, $app);
        } catch (RuntimeException $e) {
            Session::flash('error', $this->trans(Payments::report($e)));
            $payUrl = null;
        }

        if ($payUrl !== null) {
            header('Location: ' . $payUrl);
            return;
        }

        $this->redirect('/orders/' . $orderId);
    }

    // --- A provider's own applications -------------------------------------------

    public function myApplications(array $params): void
    {
        $provider = $this->provider();
        if ($provider === null) {
            return;
        }

        $this->render('account/applications.twig', [
            'applications' => $this->app->requestApplications->forProvider((int) $provider['id']),
        ]);
    }

    public function withdraw(array $params): void
    {
        $provider = $this->provider();
        if ($provider === null) {
            return;
        }

        $application = $this->app->requestApplications->find((int) $params['id']);
        if ($application !== null && (int) $application['provider_id'] === (int) $provider['id']) {
            $this->app->requestApplications->withdraw($application['id']);
            Session::flash('success', $this->trans('core.request.application_withdrawn'));
        }

        $this->redirect('/account/applications');
    }

    // --- Shared -------------------------------------------------------------------

    /** @return array{title: string, description: string, category_id: int, budget_min: ?int, budget_max: ?int, needed_by: ?string, errors: string[]} */
    private function validated(array $input): array
    {
        $errors = [];
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim(str_replace("\r\n", "\n", (string) ($input['description'] ?? '')));
        if ($title === '' || mb_strlen($title) > 150) {
            $errors[] = 'core.request.error.title';
        }
        if ($description === '' || mb_strlen($description) > 5000) {
            $errors[] = 'core.request.error.description';
        }

        $categoryId = (int) ($input['category_id'] ?? 0);
        if (!array_key_exists($categoryId, $this->app->categories->all())) {
            $errors[] = 'core.request.error.category';
        }

        $min = trim((string) ($input['budget_min'] ?? ''));
        $max = trim((string) ($input['budget_max'] ?? ''));
        $budgetMin = $min !== '' ? Money::parse($min) : null;
        $budgetMax = $max !== '' ? Money::parse($max) : null;
        if (($min !== '' && $budgetMin === null) || ($max !== '' && $budgetMax === null)) {
            $errors[] = 'core.request.error.budget';
        } elseif ($budgetMin !== null && $budgetMax !== null && $budgetMin > $budgetMax) {
            $errors[] = 'core.request.error.budget_order';
        }

        $neededBy = trim((string) ($input['needed_by'] ?? ''));
        $neededBy = preg_match('/^\d{4}-\d{2}-\d{2}$/', $neededBy) === 1 ? $neededBy : null;

        return ['title' => $title, 'description' => $description, 'category_id' => $categoryId > 0 ? $categoryId : null, 'budget_min' => $budgetMin, 'budget_max' => $budgetMax, 'needed_by' => $neededBy, 'errors' => $errors];
    }

    private function renderForm(?array $request, array $errors, ?array $typed = null): void
    {
        $app = $this->app;
        $locale = $app->translator->locale();
        // Typed values are already the human text the field had; a saved
        // request's budget is minor units and needs the same formatting
        // Money::input() gives every other price field in this project.
        $values = $typed ?? ($request !== null ? [
            'title' => $request['title'], 'category_id' => $request['category_id'], 'description' => $request['description'],
            'budget_min' => $request['budget_min'] !== null ? Money::input($request['budget_min'], $locale) : '',
            'budget_max' => $request['budget_max'] !== null ? Money::input($request['budget_max'], $locale) : '',
            'needed_by' => $request['needed_by'],
        ] : []);

        $this->render('account/request_edit.twig', [
            'request' => $request,
            'values' => $values,
            'categories' => $app->categories->tree($locale),
            'currency' => $app->requests->currency(),
            'approval_required' => $app->requests->approvalRequired(),
            'errors' => $errors,
        ]);
    }

    /** @return array<string, mixed> the request's own fields plus a public path, a short snippet and category name, for templates */
    private function card(array $request): array
    {
        $category = $request['category_id'] !== null ? $this->app->categories->find($request['category_id']) : null;

        return $request + [
            'path' => '/requests/' . $request['slug'],
            'snippet' => mb_strimwidth(str_replace("\n", ' ', $request['description']), 0, 200, '…'),
            'category_name' => $category !== null ? ($this->app->categories->view($category, $this->app->translator->locale())['name'] ?? '') : '',
        ];
    }

    /** The request from the id, if it belongs to the logged-in account. Anyone else gets a 404. */
    private function own(array $params): ?array
    {
        $request = $this->app->requests->find((int) $params['id']);

        if ($request === null || (int) $request['account_id'] !== $this->app->auth->account()['id']) {
            $this->notFound();
            return null;
        }

        return $request;
    }

    /** @return array{0: array, 1: array}|null the request (owned by the account) and one of its applications */
    private function ownApplication(array $params): ?array
    {
        $request = $this->own($params);
        if ($request === null) {
            return null;
        }

        $application = $this->app->requestApplications->find((int) $params['appId']);
        if ($application === null || $application['request_id'] !== $request['id'] || $application['status'] !== 'submitted') {
            $this->notFound();
            return null;
        }

        return [$request, $application];
    }

    /** The logged-in account's provider profile; without one, applying makes no sense yet. */
    private function provider(): ?array
    {
        $provider = $this->app->providers->findByAccount($this->app->auth->account()['id']);

        if ($provider === null) {
            Session::flash('error', $this->trans('core.offer.needs_provider'));
            $this->redirect('/account/provider');
        }

        return $provider;
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
    }
}
