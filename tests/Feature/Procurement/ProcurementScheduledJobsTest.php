<?php

namespace Tests\Feature\Procurement;

use App\Jobs\CheckOverduePurchaseOrdersJob;
use App\Jobs\GenerateProcurementReportJob;
use App\Listeners\NotifyOverduePOs;
use App\Mail\OverduePurchaseOrdersMail;
use App\Models\PurchaseOrder;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProcurementScheduledJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_null_shop_jobs_send_nothing(): void
    {
        Mail::fake();

        (new GenerateProcurementReportJob())->handle();
        (new CheckOverduePurchaseOrdersJob())->handle();

        Mail::assertNothingSent();
    }

    public function test_overdue_notifications_never_cross_shop_boundaries(): void
    {
        Mail::fake();
        $role = Role::findOrCreate('Procurement Manager', 'user');
        $firstOwner = ShopOwner::factory()->create();
        $secondOwner = ShopOwner::factory()->create();
        $firstUser = User::factory()->for($firstOwner)->create();
        $secondUser = User::factory()->for($secondOwner)->create();
        $firstUser->assignRole($role);
        $secondUser->assignRole($role);

        foreach ([[$firstOwner, $firstUser], [$secondOwner, $secondUser]] as [$owner, $user]) {
            $supplier = Supplier::factory()->create(['shop_owner_id' => $owner->id]);
            PurchaseOrder::factory()->create([
                'shop_owner_id' => $owner->id,
                'supplier_id' => $supplier->id,
                'ordered_by' => $user->id,
                'status' => 'sent',
                'expected_delivery_date' => now()->subDay(),
            ]);
        }

        (new NotifyOverduePOs())->handle($firstOwner->id);

        Mail::assertSent(OverduePurchaseOrdersMail::class, 1);
        Mail::assertSent(OverduePurchaseOrdersMail::class, fn ($mail) =>
            $mail->hasTo($firstUser->email)
            && $mail->overduePOs->every(fn ($po) => $po->shop_owner_id === $firstOwner->id));
        Mail::assertNotSent(OverduePurchaseOrdersMail::class, fn ($mail) => $mail->hasTo($secondUser->email));
    }
}
