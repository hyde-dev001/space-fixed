<?php

namespace App\Services;

class ShopPolicyTemplateService
{
    public function buildSections(string $businessType, string $registrationType): array
    {
        $normalizedBusinessType = strtolower(trim($businessType));

        $sections = [
            'refund_payment_terms' => "1. Refund Request Window\nCustomers may request a refund within the approved shop refund period, subject to verification and order status.\n\n2. Payment Settlement\nRefund release and payment reversals follow payment channel settlement timelines and may take additional processing days.\n\n3. Refund Exclusions\nRequests with confirmed misuse, completed services, or policy violations may be rejected based on shop review.",
        ];

        if (in_array($normalizedBusinessType, ['repair', 'both'], true)) {
            $sections['repair_service_terms'] = "1. Repair Scope and Approval\nOnly approved services listed in the repair request are included. Additional work requires customer confirmation.\n\n2. Repair Timeline\nEstimated completion dates may adjust based on parts, workload, and shoe condition updates communicated by the shop.\n\n3. Pickup and Return Responsibility\nCustomers must coordinate pickup or delivery return schedules promptly after repair completion notice.";
        }

        if (in_array($normalizedBusinessType, ['retail', 'both'], true)) {
            $sections['retail_terms'] = "1. Stock and Order Confirmation\nAll orders are subject to stock verification before fulfillment. Out-of-stock items will be replaced, refunded, or cancelled as agreed.\n\n2. Shipping and Delivery Window\nDelivery timelines depend on courier operations and destination. Delays may occur during peak periods or weather disruptions.\n\n3. Return Conditions\nReturns are accepted for eligible items in original condition and complete packaging, following shop return procedures.";
        }

        return $sections;
    }
}
