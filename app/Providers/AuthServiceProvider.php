<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\RepairRequest;
use App\Models\PurchaseRequest;
use App\Models\PurchaseOrder;
use App\Models\ReplenishmentRequest;
use App\Models\StockRequestApproval;
use App\Models\Supplier;
use App\Models\InventoryItem;
use App\Policies\RepairRequestPolicy;
use App\Policies\PurchaseRequestPolicy;
use App\Policies\PurchaseOrderPolicy;
use App\Policies\ReplenishmentRequestPolicy;
use App\Policies\StockRequestApprovalPolicy;
use App\Policies\SupplierPolicy;
use App\Policies\InventoryItemPolicy;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        RepairRequest::class => RepairRequestPolicy::class,
        PurchaseRequest::class => PurchaseRequestPolicy::class,
        PurchaseOrder::class => PurchaseOrderPolicy::class,
        ReplenishmentRequest::class => ReplenishmentRequestPolicy::class,
        StockRequestApproval::class => StockRequestApprovalPolicy::class,
        Supplier::class => SupplierPolicy::class,
        InventoryItem::class => InventoryItemPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Additional gates can be defined here if needed
    }
}
