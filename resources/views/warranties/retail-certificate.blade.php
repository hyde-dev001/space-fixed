<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Product Warranty Certificate</title>
<style>
    @page { margin: 38px 42px; }
    body { font-family: "DejaVu Sans", sans-serif; color: #181818; font-size: 10px; line-height: 1.5; }
    h1 { font-size: 22px; margin: 6px 0 18px; } h2 { font-size: 13px; margin: 18px 0 8px; }
    .brand { font-weight: bold; letter-spacing: 3px; font-size: 16px; border-bottom: 2px solid #181818; padding-bottom: 10px; }
    .muted { color: #555; } .reference { font-size: 13px; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; } th, td { padding: 8px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
    th { background: #f3f3f3; } tr { page-break-inside: avoid; }
    .text { white-space: pre-wrap; overflow-wrap: anywhere; } .notice { border: 1px solid #ccc; padding: 12px; margin-top: 22px; }
</style></head>
<body>
<div class="brand">SOLESPACE</div>
<h1>Product Warranty Certificate</h1>
<p class="reference">Warranty Reference: {{ $issuance->warranty_number }}</p>
<p><strong>Order:</strong> {{ $issuance->order_snapshot['number'] ?? $issuance->order_id }}<br>
<strong>Customer:</strong> {{ $issuance->customer_snapshot['name'] }}<br>
<strong>Shop:</strong> {{ $issuance->shop_snapshot['name'] }}<br>
<span class="muted">{{ $issuance->shop_snapshot['address'] ?? '' }}<br>{{ $issuance->shop_snapshot['phone'] ?? '' }} · {{ $issuance->shop_snapshot['email'] ?? '' }}</span></p>
<p><strong>Order date:</strong> {{ $issuance->order_snapshot['created_at'] ?? 'See transaction record' }}<br>
<strong>Fulfilled:</strong> {{ $issuance->fulfilled_at->setTimezone($issuance->business_timezone)->format('F j, Y g:i A') }}<br>
<strong>Issued:</strong> {{ $issuance->issued_at->setTimezone($issuance->business_timezone)->format('F j, Y g:i A') }}<br>
<strong>Business timezone:</strong> {{ $issuance->business_timezone }}</p>
<h2>Covered Items</h2>
<table><thead><tr><th>Product / Variant</th><th>Original Quantity</th><th>Coverage</th></tr></thead><tbody>
@foreach ($issuance->warranties as $warranty)
<tr><td><strong>{{ $warranty->item_snapshot['name'] }}</strong><br>Size: {{ $warranty->item_snapshot['size'] ?? '—' }} · Color: {{ $warranty->item_snapshot['color'] ?? '—' }}</td>
<td>{{ $warranty->original_covered_quantity }}</td><td>{{ $warranty->policy_snapshot['duration_value'] }} {{ $warranty->policy_snapshot['duration_unit'] }}<br>
{{ $warranty->warranty_start_date->setTimezone($issuance->business_timezone)->format('F j, Y g:i A') }}<br>to {{ $warranty->warranty_expiration_date->setTimezone($issuance->business_timezone)->format('F j, Y g:i A') }}<br>
Status at generation: {{ ucfirst($issuance->certificate_status_at_generation[$warranty->id] ?? 'active') }}</td></tr>
@endforeach
</tbody></table>
@foreach ($issuance->warranties->unique(fn ($warranty) => json_encode($warranty->policy_snapshot)) as $warranty)
<h2>{{ $warranty->policy_snapshot['title'] }}</h2>
<p class="text">{{ $warranty->policy_snapshot['description'] ?? '' }}</p>
<h2>Terms and Conditions</h2><div class="text">{{ $warranty->policy_snapshot['terms'] ?? '' }}</div>
<h2>Exclusions</h2><div class="text">{{ $warranty->policy_snapshot['exclusions'] ?? 'None specified.' }}</div>
<h2>Customer Instructions</h2><div class="text">{{ $warranty->policy_snapshot['instructions'] ?? 'Present this certificate to the shop for assessment.' }}</div>
@endforeach
<div class="notice">This certificate records the Product Warranty issued for this specific SoleSpace transaction. Current warranty status, remaining covered quantity, subsequent refunds, or administrative voiding should be verified in SoleSpace when the system is available. Active coverage allows assessment under the shop's terms; refund approval remains subject to the existing inspection and approval process.</div>
<p class="muted">Generated {{ $issuance->certificate_generated_at?->setTimezone($issuance->business_timezone)->format('F j, Y g:i A') }}. Retain this document for offline identification at the shop.</p>
</body></html>
