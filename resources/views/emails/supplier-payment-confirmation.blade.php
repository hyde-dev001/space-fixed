<!DOCTYPE html>
<html lang="en">
<body>
    <p>Hello {{ $supplierName }},</p>

    <p>
        {{ $shopName }} verified and paid the supplier payment for purchase order
        {{ $poNumber }} in the amount of PHP {{ $amount }}.
    </p>

    <p>
        Receipt number: {{ $receiptNumber }}<br>
        Payment status: {{ $paymentStatus }}<br>
        Payment method: {{ $paymentMethod }}<br>
        External reference: {{ $externalTransactionReference }}<br>
        Payment date: {{ optional($externallyPaidAt)->toDateTimeString() }}<br>
        Destination:
        {{ $maskedDestination['wallet_provider'] ?? $maskedDestination['bank_name'] ?? $maskedDestination['destination_type'] ?? 'Configured destination' }}
        ({{ $maskedDestination['masked_account_identifier'] ?? $maskedDestination['masked_account_number'] ?? 'masked account' }})
    </p>

    <p>Please verify receipt of the funds in your account.</p>
</body>
</html>
