<?php

namespace App\Providers;

use App\Events\PurchaseOrderCompleted;
use App\Events\PurchaseOrderDelivered;
use App\Events\PurchaseOrderSent;
use App\Events\PurchaseRequestApproved;
use App\Events\PurchaseRequestRejected;
use App\Events\PurchaseRequestSubmittedToFinance;
use App\Events\ReplenishmentRequestAccepted;
use App\Listeners\CreatePurchaseOrderFromPR;
use App\Listeners\CreateStockMovementOnDelivery;
use App\Listeners\NotifyFinanceOfNewPR;
use App\Listeners\NotifyReplenishmentReviewed;
use App\Listeners\NotifyRequesterOfPRApproval;
use App\Listeners\NotifyRequesterOfPRRejection;
use App\Listeners\SendPOToSupplier;
use App\Listeners\UpdateInventoryOnDelivery;
use App\Listeners\UpdateSupplierMetrics;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],

        // Purchase Request Events
        PurchaseRequestSubmittedToFinance::class => [
            NotifyFinanceOfNewPR::class,
        ],

        PurchaseRequestApproved::class => [
            NotifyRequesterOfPRApproval::class,
            CreatePurchaseOrderFromPR::class,
        ],

        PurchaseRequestRejected::class => [
            NotifyRequesterOfPRRejection::class,
        ],

        // Purchase Order Events
        PurchaseOrderSent::class => [
            SendPOToSupplier::class,
        ],

        PurchaseOrderDelivered::class => [
            UpdateInventoryOnDelivery::class,
            CreateStockMovementOnDelivery::class,
        ],

        PurchaseOrderCompleted::class => [
            UpdateSupplierMetrics::class,
        ],

        // Replenishment Request Events
        ReplenishmentRequestAccepted::class => [
            NotifyReplenishmentReviewed::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
