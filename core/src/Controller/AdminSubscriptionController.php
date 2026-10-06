<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use InvalidArgumentException;
use Modulento\Core\Support\Money;
use Modulento\Core\Support\Session;

/**
 * Plans and their grants, in the administration. The routes exist only while the
 * module "Subscriptions" is switched on (see Kernel), so none of this is reachable
 * with the module off.
 */
final class AdminSubscriptionController extends Controller
{
    public function index(array $params): void
    {
        $app = $this->app;
        $this->render('@admin/subscriptions.twig', [
            'plans' => $app->subscriptions->plans(),
            'recent' => $app->subscriptions->recent(50),
            'periods' => [1, 3, 6, 12],
        ]);
    }

    public function createPlan(array $params): void
    {
        $app = $this->app;
        $price = Money::parse((string) ($_POST['price'] ?? ''));
        $features = preg_split('/[\s,]+/', (string) ($_POST['features'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        try {
            if ($price === null) {
                throw new InvalidArgumentException('plan: price');
            }
            $app->subscriptions->createPlan(
                trim((string) ($_POST['slug'] ?? '')),
                (string) ($_POST['name'] ?? ''),
                $price,
                strtoupper(trim((string) ($_POST['currency'] ?? ''))),
                (int) ($_POST['period_months'] ?? 0),
                $features
            );
            $app->adminLog->record($app->auth->account()['id'], 'subscription_plan', null, trim((string) $_POST['slug']));
            Session::flash('success', $this->trans('core.admin.subscriptions.flash.plan_created'));
        } catch (InvalidArgumentException $e) {
            Session::flash('error', $this->trans(str_contains($e->getMessage(), 'taken')
                ? 'core.admin.subscriptions.error.slug_taken'
                : 'core.admin.subscriptions.error.plan'));
        }

        $this->redirect('/admin/subscriptions');
    }

    /** Gives an account a plan, or takes its plan away. The end date is the last day, included. */
    public function assign(array $params): void
    {
        $app = $this->app;
        $account = $app->accounts->findByEmail(trim((string) ($_POST['email'] ?? '')));
        $until = trim((string) ($_POST['until'] ?? ''));
        $planId = ($_POST['plan'] ?? '') === '' ? null : (int) $_POST['plan'];

        if ($account === null) {
            Session::flash('error', $this->trans('core.admin.subscriptions.error.account'));
        } elseif ($until !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) !== 1) {
            Session::flash('error', $this->trans('core.admin.subscriptions.error.until'));
        } else {
            try {
                $app->subscriptions->assign((int) $account['id'], $planId, $until === '' ? null : $until . ' 23:59:59');
                $app->adminLog->record($app->auth->account()['id'], 'subscription_assign', (int) $account['id'], $planId === null ? 'none' : (string) $planId);
                Session::flash('success', $this->trans('core.admin.subscriptions.flash.assigned', ['email' => $account['email']]));
            } catch (InvalidArgumentException) {
                Session::flash('error', $this->trans('core.admin.subscriptions.error.plan'));
            }
        }

        $this->redirect('/admin/subscriptions');
    }
}
