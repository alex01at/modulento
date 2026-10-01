<?php

declare(strict_types=1);

namespace Modulento\Core\Account;

use Modulento\Core\App;
use Modulento\Core\Event\AccountDeleted;

/**
 * Deleting an account, whoever asks for it. The database removes every
 * row that references the account; files on disk have to be removed by
 * hand before that, while the rows still say which ones they are.
 */
final class AccountRemoval
{
    public static function run(App $app, int $accountId, string $email): void
    {
        $provider = $app->providers->findByAccount($accountId);
        if ($provider !== null) {
            foreach ($app->offers->listAll((int) $provider['id'], null, 1, 100000)['rows'] as $offer) {
                $app->offerImages->deleteAll($offer['id']);
            }
        }

        $app->accounts->delete($accountId);
        $app->events->dispatch(new AccountDeleted($accountId, $email));
    }
}
