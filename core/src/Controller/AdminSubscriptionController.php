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
            'bank' => $app->subscriptionBilling->bank(),
            'transfers' => $app->subscriptionBilling->pendingTransfers(),
            'methods' => $app->subscriptionBilling->methods(),
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

    /** One plan: its settings, the features it includes, and deleting it when nobody has had it. */
    public function plan(array $params): void
    {
        $app = $this->app;
        $plan = $app->subscriptions->plan((int) $params['id']);
        if ($plan === null) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        // The features this plan has, the ones extensions declare, and any key a plan has kept.
        $declared = $app->subscriptions->declaredFeatures();
        $keys = array_values(array_unique([...array_keys($declared), ...$plan['features']]));
        $this->render('@admin/subscription_plan.twig', [
            'plan' => $plan,
            'price' => Money::input($plan['price_cents'], $app->translator->locale()),
            'features' => array_map(fn (string $key) => ['key' => $key, 'label_key' => $declared[$key] ?? null], $keys),
            'periods' => [1, 3, 6, 12],
        ]);
    }

    public function updatePlan(array $params): void
    {
        $app = $this->app;
        $id = (int) $params['id'];
        $price = Money::parse((string) ($_POST['price'] ?? ''));
        // The boxes ticked, and any keys typed in by hand.
        $features = array_values(array_filter(is_array($_POST['features'] ?? null) ? $_POST['features'] : [], 'is_string'));
        $typed = preg_split('/[\s,]+/', (string) ($_POST['new_features'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $features = array_values(array_unique([...$features, ...$typed]));

        try {
            if ($price === null || $app->subscriptions->plan($id) === null) {
                throw new InvalidArgumentException('plan: price');
            }
            $app->subscriptions->updatePlan(
                $id,
                (string) ($_POST['name'] ?? ''),
                $price,
                strtoupper(trim((string) ($_POST['currency'] ?? ''))),
                (int) ($_POST['period_months'] ?? 0),
                $features,
                isset($_POST['active'])
            );
            $app->adminLog->record($app->auth->account()['id'], 'subscription_plan_change', null, (string) $id);
            Session::flash('success', $this->trans('core.admin.subscriptions.flash.plan_saved'));
        } catch (InvalidArgumentException) {
            Session::flash('error', $this->trans('core.admin.subscriptions.error.plan'));
        }

        $this->redirect('/admin/subscriptions/plans/' . $id);
    }

    public function deletePlan(array $params): void
    {
        $app = $this->app;
        $id = (int) $params['id'];
        try {
            $app->subscriptions->deletePlan($id);
            $app->adminLog->record($app->auth->account()['id'], 'subscription_plan_delete', null, (string) $id);
            Session::flash('success', $this->trans('core.admin.subscriptions.flash.plan_deleted'));
            $this->redirect('/admin/subscriptions');
        } catch (InvalidArgumentException) {
            Session::flash('error', $this->trans('core.admin.subscriptions.error.plan_in_use'));
            $this->redirect('/admin/subscriptions/plans/' . $id);
        }
    }

    /** The operator's bank account, to which the transfers for subscriptions are paid. */
    public function saveBank(array $params): void
    {
        $app = $this->app;
        try {
            $app->subscriptionBilling->saveBank(
                (string) ($_POST['holder'] ?? ''),
                (string) ($_POST['iban'] ?? ''),
                (string) ($_POST['bic'] ?? '')
            );
            $app->adminLog->record($app->auth->account()['id'], 'subscription_bank', null, '');
            Session::flash('success', $this->trans('core.admin.subscriptions.flash.bank_saved'));
        } catch (InvalidArgumentException) {
            Session::flash('error', $this->trans('core.admin.subscriptions.error.bank'));
        }

        $this->redirect('/admin/subscriptions');
    }

    /** The money of a transfer has arrived: the plan is given to the account from now. */
    public function confirmTransfer(array $params): void
    {
        $app = $this->app;
        $id = (int) $params['id'];
        try {
            $app->subscriptionBilling->confirmTransfer($id);
            $app->adminLog->record($app->auth->account()['id'], 'subscription_transfer', null, (string) $id);
            Session::flash('success', $this->trans('core.admin.subscriptions.flash.transfer_confirmed'));
        } catch (InvalidArgumentException) {
            Session::flash('error', $this->trans('core.admin.subscriptions.error.transfer'));
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
