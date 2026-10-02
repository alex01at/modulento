<?php

declare(strict_types=1);

namespace Modulento\Auction;

use Modulento\Core\App;
use Modulento\Core\Event\AccountDeleted;
use Modulento\Core\Event\AccountExport;
use Modulento\Core\Event\OfferStatusChanged;
use Modulento\Core\Extension\Extension as ExtensionContract;
use Modulento\Core\Extension\Registrar;
use Modulento\Core\Support\Router;

/**
 * Turns the catalogue into an auction house: an offer of the type
 * "auction.lot" has a starting price and runs for a number of days from
 * the moment it is published. Visitors bid; when the time is up, the
 * highest bid becomes an order - created here, not through the order
 * form - and SaleFlow describes how it is handed over and completed.
 */
final class Extension implements ExtensionContract
{
    public function register(Registrar $registrar): void
    {
        $registrar->offerType(new LotType());
        $registrar->orderFlow(new SaleFlow());

        $registrar->routes(function (Router $router): void {
            $router->post('/auction/{id}/bid', [BidController::class, 'bid']);
        });

        // The clock starts when the lot can be seen, not when it is written.
        $registrar->listen(OfferStatusChanged::class, function (OfferStatusChanged $event, App $app): void {
            if ($event->newStatus === 'published') {
                (new Auctions($app->db))->start($event->offerId);
            }
        });

        $registrar->task('auction.close', 1, function (App $app): void {
            (new Auctions($app->db))->closeDue($app);
        });

        $registrar->listen(AccountExport::class, function (AccountExport $event, App $app): void {
            $event->add('auction', ['bids' => (new Auctions($app->db))->bidsOfAccount($event->accountId)]);
        });

        // The account's bids went with it.
        $registrar->listen(AccountDeleted::class, function (AccountDeleted $event, App $app): void {
            (new Auctions($app->db))->resyncOpen($app);
        });
    }
}
