<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\PurchaseRequest;
use App\Models\PurchaseOrder;
use App\Models\ReplenishmentRequest;
use App\Models\StockRequestApproval;
use App\Policies\PurchaseRequestPolicy;
use App\Policies\PurchaseOrderPolicy;
use App\Policies\ReplenishmentRequestPolicy;
use App\Policies\StockRequestApprovalPolicy;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        PurchaseRequest::class => PurchaseRequestPolicy::class,
        PurchaseOrder::class => PurchaseOrderPolicy::class,
        ReplenishmentRequest::class => ReplenishmentRequestPolicy::class,
        StockRequestApproval::class => StockRequestApprovalPolicy::class,
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
