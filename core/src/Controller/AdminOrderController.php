<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Order\OrderNotifier;
use Modulento\Core\Support\Session;

/** Every order, for an administrator who has to look into one or step in. */
final class AdminOrderController extends Controller
{
    private const PER_PAGE = 50;

    public function index(array $params): void
    {
        $counts = $this->app->orders->counts();
        $state = is_string($_GET['state'] ?? null) && isset($counts[$_GET['state']]) ? $_GET['state'] : null;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $list = $this->app->orders->list('admin', null, $state, $page, self::PER_PAGE);

        $this->render('@admin/orders.twig', [
            'orders' => array_map(fn (array $order) => $order + ['state_label_key' => $this->stateLabel($order)], $list['rows']),
            'counts' => $counts,
            'state' => $state,
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
        ]);
    }

    public function show(array $params): void
    {
        $app = $this->app;
        $order = $app->orders->find((int) $params['id']);
        if ($order === null) {
            $this->redirect('/admin/orders');
            return;
        }

        $flow = $app->orders->flow($order['flow']);

        $this->render('@admin/order.twig', [
            'order' => $order + [
                'state_label_key' => $this->stateLabel($order),
                'event_labels' => array_map(fn (array $event) => $app->orders->eventLabel($order, $event['transition']), $order['events']),
            ],
            'payment_label_key' => ($app->orders->paymentMethods()[$order['payment_method']] ?? null)?->labelKey(),
            'payments' => OrderController::attempts($app, $order['id']),
            'files' => $app->orderFiles->ofOrder($order['id']),
            'actions' => $app->orders->available($order, 'admin', $app),
            'flow_template' => $flow?->orderDetailTemplate(),
            'flow_data' => $flow?->orderDetailData($order, $app->translator->locale(), $app) ?? [],
        ]);
    }

    public function transition(array $params): void
    {
        $app = $this->app;
        $order = $app->orders->find((int) $params['id']);
        if ($order === null) {
            $this->redirect('/admin/orders');
            return;
        }

        $name = (string) ($_POST['transition'] ?? '');
        $note = trim((string) ($_POST['note'] ?? ''));
        $problem = $app->orders->apply($order['id'], $name, 'admin', $app->auth->account()['id'], $note, $app);

        if ($problem === null) {
            OrderNotifier::stateChanged($app, $order, $app->orders->find($order['id']), $name, 'admin', $note !== '' ? $note : null);
            Session::flash('success', $this->trans('core.order.updated'));
        } else {
            Session::flash('error', $this->trans($problem));
        }

        $this->redirect('/admin/orders/' . $order['id']);
    }

    public function download(array $params): void
    {
        $file = $this->app->orderFiles->find((int) $params['id'], (int) $params['file']);
        if ($file === null) {
            $this->redirect('/admin/orders');
            return;
        }

        OrderController::sendFile($file);
    }

    private function stateLabel(array $order): string
    {
        return $this->app->orders->flow($order['flow'])?->states()[$order['state']]['label'] ?? 'core.order.state_unknown';
    }
}
