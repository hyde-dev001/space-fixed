<!DOCTYPE html>
<html lang="en">
<body>
    <p>Hello {{ $supplierName }},</p>

    <p>
        We have processed payment for {{ $poNumber }} in the amount of PHP {{ $amount }}.
        Payment was sent via {{ $paymentMethod }} under reference
        {{ $externalTransactionReference }}.
    </p>

    <p>
        Payment date: {{ optional($externallyPaidAt)->toDateTimeString() }}<br>
        Destination: {{ $maskedDestination['bank_name'] ?? $maskedDestination['destination_type'] ?? 'Configured destination' }}
        ({{ $maskedDestination['masked_account_number'] ?? 'masked account' }})
    </p>

    <p>Please verify receipt of the funds in your account.</p>
</body>
</html>
