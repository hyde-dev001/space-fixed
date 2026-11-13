<!doctype html><html lang="en"><head><meta charset="utf-8"></head>
<body style="font-family:Arial,sans-serif;color:#171717;line-height:1.6">
<h1>Product Warranty</h1>
<p>Hello {{ $issuance->customer_snapshot['name'] }},</p>
<p>Your order from <strong>{{ $issuance->shop_snapshot['name'] }}</strong> is covered by the shop's Product Warranty.</p>
<p><strong>Warranty Reference:</strong> {{ $issuance->warranty_number }}<br>
<strong>Order:</strong> {{ $issuance->order_snapshot['number'] ?? $issuance->order_id }}<br>
<strong>Fulfilled:</strong> {{ $issuance->fulfilled_at->setTimezone($issuance->business_timezone)->format('F j, Y g:i A') }}</p>
<ul>@foreach ($issuance->warranties as $warranty)<li><strong>{{ $warranty->item_snapshot['name'] }}</strong> — Size {{ $warranty->item_snapshot['size'] ?? '—' }}, {{ $warranty->item_snapshot['color'] ?? '' }}, Qty {{ $warranty->original_covered_quantity }}<br>
{{ $warranty->policy_snapshot['duration_value'] }} {{ $warranty->policy_snapshot['duration_unit'] }}: {{ $warranty->warranty_start_date->setTimezone($issuance->business_timezone)->format('F j, Y g:i A') }} – {{ $warranty->warranty_expiration_date->setTimezone($issuance->business_timezone)->format('F j, Y g:i A') }} ({{ $issuance->business_timezone }})</li>@endforeach</ul>
@foreach ($issuance->warranties->unique(fn ($warranty) => json_encode($warranty->policy_snapshot)) as $warranty)
<p><strong>Terms:</strong> {{ \Illuminate\Support\Str::limit($warranty->policy_snapshot['terms'] ?? '', 600) }}<br>
<strong>Exclusions:</strong> {{ \Illuminate\Support\Str::limit($warranty->policy_snapshot['exclusions'] ?? '', 300) }}<br>
<strong>Instructions:</strong> {{ \Illuminate\Support\Str::limit($warranty->policy_snapshot['instructions'] ?? '', 300) }}</p>
@endforeach
<p>Please review and save the attached combined Product Warranty Certificate for the complete terms and exclusions. You can present it at the physical shop if SoleSpace is temporarily unavailable.</p>
<p>Coverage permits assessment under the shop's terms; refund approval still requires the existing inspection and approval process. Current status and remaining quantities are available in My Orders.</p>
<p>SoleSpace</p></body></html>
