<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Account\Tokens;
use Modulento\Core\Account\AccountRemoval;
use Modulento\Core\Support\Session;

/** Accounts and roles as an administrator sees them. */
final class AdminAccountController extends Controller
{
    private const PER_PAGE = 50;
    private const RESET_TTL_SECONDS = 86400;

    public function index(array $params): void
    {
        $search = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $list = $this->app->accounts->list($search, $page, self::PER_PAGE);

        $this->render('@admin/accounts.twig', [
            'accounts' => $list['rows'],
            'total' => $list['total'],
            'search' => $search,
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
        ]);
    }

    public function show(array $params): void
    {
        $account = $this->app->accounts->findById((int) $params['id']);
        if ($account === null) {
            $this->redirect('/admin/accounts');
            return;
        }

        unset($account['password_hash']);

        $this->render('@admin/account.twig', [
            'target' => $account,
            'roles' => array_values($this->app->roles->all()),
            'role_ids' => $this->app->roles->idsOfAccount((int) $account['id']),
            'provider' => $this->app->providers->findByAccount((int) $account['id']),
            'is_self' => (int) $account['id'] === $this->app->auth->account()['id'],
            'is_last_admin' => $this->app->accounts->isLastAdmin((int) $account['id']),
        ]);
    }

    public function block(array $params): void
    {
        $account = $this->target($params);
        if ($account === null) {
            return;
        }

        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note === '') {
            $this->back($account, 'error', 'core.admin.accounts.error.note');
            return;
        }

        $this->app->accounts->setStatus((int) $account['id'], 'blocked', $note);
        // A blocked account is refused anyway; deleting keeps its devices
        // from logging in again by themselves once it is unblocked.
        $this->app->loginTokens->revokeAll((int) $account['id']);
        $this->app->mailer->send($account['email'], 'emails/account_blocked.txt.twig', ['note' => $note], $this->localeOf($account));
        $this->back($account, 'success', 'core.admin.accounts.blocked');
    }

    public function unblock(array $params): void
    {
        $account = $this->app->accounts->findById((int) $params['id']);
        if ($account === null) {
            $this->redirect('/admin/accounts');
            return;
        }

        $this->app->accounts->setStatus((int) $account['id'], 'active', null);
        $this->back($account, 'success', 'core.admin.accounts.unblocked');
    }

    public function verify(array $params): void
    {
        $account = $this->app->accounts->findById((int) $params['id']);
        if ($account !== null) {
            $this->app->accounts->markVerified((int) $account['id']);
            $this->app->tokens->revoke((int) $account['id'], Tokens::VERIFY_EMAIL);
            $this->back($account, 'success', 'core.admin.accounts.verified');
            return;
        }

        $this->redirect('/admin/accounts');
    }

    /** An administrator never sets or sees a password; the owner gets a link to choose one. */
    public function sendReset(array $params): void
    {
        $account = $this->app->accounts->findById((int) $params['id']);
        if ($account === null) {
            $this->redirect('/admin/accounts');
            return;
        }

        $locale = $this->localeOf($account);
        $token = $this->app->tokens->create((int) $account['id'], Tokens::RESET_PASSWORD, self::RESET_TTL_SECONDS);
        $this->app->mailer->send($account['email'], 'emails/reset_password.txt.twig', [
            'link' => $this->app->url('/reset-password/' . $token, $locale, true),
            'minutes' => intdiv(self::RESET_TTL_SECONDS, 60),
        ], $locale);

        $this->back($account, 'success', 'core.admin.accounts.reset_sent');
    }

    /** Needs the right to manage roles as well: handing out roles is handing out permissions. */
    public function setRoles(array $params): void
    {
        $account = $this->app->accounts->findById((int) $params['id']);
        if ($account === null) {
            $this->redirect('/admin/accounts');
            return;
        }

        $roleIds = array_map('intval', is_array($_POST['roles'] ?? null) ? $_POST['roles'] : []);
        $adminRoleId = $this->app->roles->adminRoleId();

        if ($this->app->accounts->isLastAdmin((int) $account['id']) && !in_array($adminRoleId, $roleIds, true)) {
            $this->back($account, 'error', 'core.admin.accounts.error.last_admin');
            return;
        }

        $this->app->roles->setForAccount((int) $account['id'], $roleIds);
        $this->back($account, 'success', 'core.admin.accounts.roles_saved');
    }

    public function delete(array $params): void
    {
        $account = $this->target($params);
        if ($account === null) {
            return;
        }

        $provider = $this->app->providers->findByAccount((int) $account['id']);
        if ($this->app->orders->hasOpen((int) $account['id'], $provider !== null ? (int) $provider['id'] : null)) {
            $this->back($account, 'error', 'core.account.delete.open_orders');
            return;
        }

        AccountRemoval::run($this->app, (int) $account['id'], $account['email']);

        Session::flash('success', $this->trans('core.admin.accounts.deleted', ['email' => $account['email']]));
        $this->redirect('/admin/accounts');
    }

    /**
     * The account an irreversible or locking action is aimed at - never
     * the administrator's own account and never the last one that may do
     * everything, so nobody locks the site's owner out by a slip.
     */
    private function target(array $params): ?array
    {
        $account = $this->app->accounts->findById((int) $params['id']);
        if ($account === null) {
            $this->redirect('/admin/accounts');
            return null;
        }

        if ((int) $account['id'] === $this->app->auth->account()['id'] || $this->app->accounts->isLastAdmin((int) $account['id'])) {
            $this->back($account, 'error', 'core.admin.accounts.error.protected');
            return null;
        }

        // Only someone who may do everything acts against someone who may.
        if ($this->app->accounts->isAdmin((int) $account['id']) && !$this->app->auth->can('core.roles.manage')) {
            $this->back($account, 'error', 'core.admin.accounts.error.protected');
            return null;
        }

        return $account;
    }

    private function localeOf(array $account): string
    {
        return $this->app->locales->isEnabled($account['locale']) ? $account['locale'] : $this->app->locales->default();
    }

    private function back(array $account, string $type, string $messageKey): void
    {
        Session::flash($type, $this->trans($messageKey));
        $this->redirect('/admin/accounts/' . $account['id']);
    }

    // --- Roles ---------------------------------------------------------

    public function roles(array $params): void
    {
        $this->render('@admin/roles.twig', ['roles' => array_values($this->app->roles->all())]);
    }

    public function editRole(array $params): void
    {
        $role = isset($params['id']) ? $this->app->roles->find((int) $params['id']) : null;
        if (isset($params['id']) && ($role === null || $role['name'] === 'admin')) {
            $this->redirect('/admin/roles');
            return;
        }

        $this->renderRole($role, null);
    }

    public function saveRole(array $params): void
    {
        $id = isset($params['id']) ? (int) $params['id'] : null;
        $name = (string) ($_POST['name'] ?? '');
        $permissions = array_map('strval', is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : []);

        $problem = $this->app->roles->save($id, $name, $permissions, array_keys($this->app->permissions()));

        if ($problem !== null) {
            $this->renderRole(['id' => $id, 'name' => $name, 'permissions' => $permissions], $this->trans($problem));
            return;
        }

        Session::flash('success', $this->trans('core.admin.roles.saved'));
        $this->redirect('/admin/roles');
    }

    public function deleteRole(array $params): void
    {
        if ($this->app->roles->delete((int) $params['id'])) {
            Session::flash('success', $this->trans('core.admin.roles.deleted'));
        }

        $this->redirect('/admin/roles');
    }

    private function renderRole(?array $role, ?string $error): void
    {
        $this->render('@admin/role_edit.twig', [
            'role' => $role,
            'error' => $error,
            'permissions' => $this->app->permissions(),
        ]);
    }
}
