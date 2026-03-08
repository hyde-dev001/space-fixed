<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\RepairRequest;
use App\Models\Finance\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymongoWebhookController extends Controller
{
    /**
     * Handle PayMongo webhook events
     */
    public function handle(Request $request)
    {
        try {
            $payload = $request->all();
            
            Log::info('PayMongo Webhook Received', ['payload' => $payload]);

            // Verify webhook signature (recommended for production)
            // $this->verifyWebhookSignature($request);

            $eventType = $payload['data']['attributes']['type'] ?? null;
            $eventData = $payload['data']['attributes']['data'] ?? null;

            if (!$eventType || !$eventData) {
                Log::warning('Invalid webhook payload structure');
                return response()->json(['message' => 'Invalid payload'], 400);
            }

            // Handle payment link paid event
            if ($eventType === 'link.payment.paid') {
                return $this->handlePaymentPaid($eventData);
            }

            // Handle payment link payment failed
            if ($eventType === 'link.payment.failed') {
                return $this->handlePaymentFailed($eventData);
            }

            return response()->json(['message' => 'Event received'], 200);

        } catch (\Exception $e) {
            Log::error('Webhook processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Server error'], 500);
        }
    }

    /**
     * Handle successful payment
     */
    private function handlePaymentPaid($eventData)
    {
        $attributes = $eventData['attributes'] ?? [];
        $paymentLinkId = $attributes['payment_link_id'] ?? null;
        $paymentId = $eventData['id'] ?? null;
        $amount = $attributes['amount'] ?? 0;

        if (!$paymentLinkId) {
            Log::error('No payment_link_id in webhook data');
            return response()->json(['message' => 'Missing payment_link_id'], 400);
        }

        // Try to find order by payment_link_id
        $order = Order::where('paymongo_link_id', $paymentLinkId)->first();

        if ($order) {
            // Handle product order payment
            return $this->handleOrderPayment($order, $paymentId);
        }

        // Try to find repair request by payment_link_id
        $repairRequest = RepairRequest::where('paymongo_link_id', $paymentLinkId)->first();

        if ($repairRequest) {
            // Handle repair request payment
            return $this->handleRepairPayment($repairRequest, $paymentId);
        }

        Log::warning('Order or RepairRequest not found for payment_link_id', ['payment_link_id' => $paymentLinkId]);
        return response()->json(['message' => 'Order or RepairRequest not found'], 404);
    }

    /**
     * Handle product order payment
     */
    private function handleOrderPayment($order, $paymentId)
    {
        // Update order payment status
        $order->update([
            'payment_status' => 'paid',
            'paymongo_payment_id' => $paymentId,
            'paid_at' => now(),
        ]);

        // Log payment processing
        activity()
            ->performedOn($order)
            ->withProperties([
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name ?? 'N/A',
                'amount_paid' => $order->total_amount,
                'payment_id' => $paymentId,
                'payment_method' => 'PayMongo',
                'payment_status' => 'paid',
            ])
            ->log("Order payment processed: {$order->order_number} - ₱{$order->total_amount}");

        // Update associated invoice to paid status if exists
        if ($order->invoice_id) {
            $invoice = Invoice::find($order->invoice_id);
            if ($invoice && $invoice->status !== 'paid') {
                $invoice->update([
                    'status' => 'paid',
                    'payment_date' => now(),
                    'payment_method' => 'paymongo',
                ]);
                
                Log::info('Invoice marked as paid', [
                    'invoice_id' => $invoice->id,
                    'invoice_reference' => $invoice->reference,
                ]);
            }
        }

        Log::info('Order payment confirmed', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'payment_id' => $paymentId,
        ]);

        // You can also send confirmation email here
        // Mail::to($order->customer_email)->send(new OrderConfirmation($order));

        return response()->json(['message' => 'Payment processed'], 200);
    }

    /**
     * Handle repair request payment
     */
    private function handleRepairPayment($repairRequest, $paymentId)
    {
        $policy = $repairRequest->payment_policy ?? 'deposit_50';

        $repairRequest->update([
            'paymongo_payment_id'  => $paymentId,
            'payment_completed_at' => now(),
        ]);

        if ($policy === 'full_upfront') {
            // Single upfront payment → fully settled immediately
            $repairRequest->update(['payment_status' => 'completed']);
            if ($repairRequest->is_high_value && $repairRequest->requires_owner_approval) {
                $repairRequest->update(['status' => 'owner_approval_pending']);
            } else {
                $repairRequest->update(['status' => 'pending']);
            }
            $phaseLabel = 'full upfront payment';

        } elseif ($policy === 'pay_after') {
            // Payment at pickup → fully settled, status stays ready_for_pickup
            $repairRequest->update(['payment_status' => 'completed']);
            $phaseLabel = 'pay-after (at pickup) payment';

        } else {
            // deposit_50 (default): two-phase logic
            $isDepositPhase = in_array($repairRequest->payment_status ?? 'pending', ['pending', null]);
            $repairRequest->update(['payment_status' => $isDepositPhase ? 'paid' : 'completed']);

            if ($isDepositPhase) {
                if ($repairRequest->is_high_value && $repairRequest->requires_owner_approval) {
                    $repairRequest->update(['status' => 'owner_approval_pending']);
                } else {
                    $repairRequest->update(['status' => 'pending']);
                }
                $phaseLabel = 'deposit (50%)';
            } else {
                $phaseLabel = 'remaining balance (50%)';
            }
        }

        activity()
            ->performedOn($repairRequest)
            ->withProperties([
                'request_id'     => $repairRequest->request_id,
                'policy'         => $policy,
                'phase'          => $phaseLabel,
                'payment_id'     => $paymentId,
                'payment_method' => 'PayMongo',
                'payment_status' => $repairRequest->fresh()->payment_status,
            ])
            ->log("Repair payment processed ({$phaseLabel}): {$repairRequest->request_id}");

        Log::info("Repair payment webhook handled", [
            'repair_id'  => $repairRequest->id,
            'request_id' => $repairRequest->request_id,
            'policy'     => $policy,
            'phase'      => $phaseLabel,
            'payment_id' => $paymentId,
        ]);

        return response()->json(['message' => 'Repair payment processed'], 200);
    }

    /**
     * Handle failed payment
     */
    private function handlePaymentFailed($eventData)
    {
        $attributes = $eventData['attributes'] ?? [];
        $paymentLinkId = $attributes['payment_link_id'] ?? null;

        if (!$paymentLinkId) {
            return response()->json(['message' => 'Missing payment_link_id'], 400);
        }

        $order = Order::where('paymongo_link_id', $paymentLinkId)->first();

        if ($order) {
            Log::info('Payment failed for order', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);

            // Optionally update order status or send notification
            // $order->update(['payment_status' => 'failed']);
        }

        return response()->json(['message' => 'Payment failure recorded'], 200);
    }

    /**
     * Verify webhook signature (optional but recommended)
     */
    private function verifyWebhookSignature(Request $request)
    {
        $signature = $request->header('Paymongo-Signature');
        $payload = $request->getContent();
        $webhookSecret = config('services.paymongo.webhook_secret');

        if (!$signature || !$webhookSecret) {
            throw new \Exception('Missing webhook signature or secret');
        }

        $computedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        if (!hash_equals($computedSignature, $signature)) {
            throw new \Exception('Invalid webhook signature');
        }
    }
}
