<?php

namespace Tests\Feature\RetailWarranty;

use App\Jobs\DeliverRetailWarranty;
use App\Models\RetailWarranty;
use App\Models\RetailWarrantyIssuance;
use App\Services\RetailWarrantyCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function issuance(): RetailWarrantyIssuance
    {
        Storage::fake('local');
        $issuance = RetailWarrantyIssuance::factory()->create();
        RetailWarranty::factory()->count(4)->create(['retail_warranty_issuance_id' => $issuance->id]);

        return $issuance;
    }

    public function test_four_items_deliver_one_email_with_one_combined_pdf_and_never_resend_after_known_success(): void
    {
        $issuance = $this->issuance();
        $job = new DeliverRetailWarranty($issuance->id);
        $job->handle(app(RetailWarrantyCertificateService::class));
        $job->handle(app(RetailWarrantyCertificateService::class));
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertCount(1, $email->getAttachments());
        $this->assertSame('application/pdf', $email->getAttachments()[0]->getMediaType().'/'.$email->getAttachments()[0]->getMediaSubtype());
        $this->assertStringContainsString($issuance->warranty_number, $email->getHtmlBody());
        $this->assertSame('sent', $issuance->fresh()->email_delivery_state);
        $this->assertDatabaseCount('retail_warranty_issuances', 1);
        $this->assertSame(1, count(Storage::disk('local')->allFiles('retail-warranties')));
    }

    public function test_missing_walk_in_email_skips_delivery_without_losing_the_certificate(): void
    {
        $issuance = $this->issuance();
        // Fixture before issuance; persisted issuance snapshots are immutable.
        \Illuminate\Support\Facades\DB::table('retail_warranty_issuances')->where('id', $issuance->id)->update(['customer_id' => null, 'customer_snapshot' => json_encode(['name' => 'Walk-in Buyer', 'email' => null])]);
        (new DeliverRetailWarranty($issuance->id))->handle(app(RetailWarrantyCertificateService::class));
        $this->assertSame('skipped', $issuance->fresh()->email_delivery_state);
        Storage::disk('local')->assertExists($issuance->fresh()->certificate_path);
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_possible_transport_acceptance_is_unknown_and_never_blindly_retried(): void
    {
        $issuance = $this->issuance();
        $mailer = Mail::mailer();
        Mail::shouldReceive('mailer')->andReturn($mailer);
        $pendingMail = \Mockery::mock(\Illuminate\Mail\PendingMail::class);
        $pendingMail->shouldReceive('send')->once()->andThrow(new \RuntimeException('Provider transport failure'));
        Mail::shouldReceive('to')->once()->andReturn($pendingMail);
        $job = new DeliverRetailWarranty($issuance->id);
        $job->handle(app(RetailWarrantyCertificateService::class));
        $job->handle(app(RetailWarrantyCertificateService::class));
        $this->assertSame('unknown', $issuance->fresh()->email_delivery_state);
        $this->assertSame(1, $issuance->fresh()->email_attempts);
        $this->assertSame(4, $issuance->warranties()->count());
    }

    public function test_pre_send_pdf_failure_can_retry_the_same_issuance(): void
    {
        $issuance = $this->issuance();
        $pdf = \Mockery::mock(RetailWarrantyCertificateService::class);
        $pdf->shouldReceive('generate')->once()->andThrow(new \RuntimeException('Storage unavailable'));
        try {
            (new DeliverRetailWarranty($issuance->id))->handle($pdf);
        } catch (\RuntimeException $exception) {
        }
        $this->assertSame('failed', $issuance->fresh()->email_delivery_state);
        (new DeliverRetailWarranty($issuance->id))->handle(app(RetailWarrantyCertificateService::class));
        $this->assertSame('sent', $issuance->fresh()->email_delivery_state);
        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
    }
}
