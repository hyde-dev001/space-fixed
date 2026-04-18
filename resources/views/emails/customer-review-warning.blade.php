<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Account Warning</title>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 24px; background: #f5f7fb; color: #111827;">
<div style="max-width: 640px; margin: 0 auto; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden;">
    <div style="background: #7c2d12; color: #ffffff; padding: 16px 20px; font-size: 18px; font-weight: 700;">
        SoleSpace Customer Account Warning
    </div>

    <div style="padding: 20px; line-height: 1.6;">
        <p style="margin: 0 0 12px 0;">Hello {{ $customerName ?: 'Customer' }},</p>

        <p style="margin: 0 0 12px 0;">
            Your account has received a policy warning after review of a reported customer review.
        </p>

        <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px; margin: 14px 0;">
            <p style="margin: 0 0 8px 0;"><strong>Warning Strike:</strong> {{ $warningStrike }} / {{ $warningLimit }}</p>
            <p style="margin: 0 0 8px 0;"><strong>Primary Reason:</strong> {{ $reasonLabel }}</p>
            <p style="margin: 0;"><strong>Reviewed At:</strong> {{ $reviewedAtLabel }}</p>
        </div>

        @if(!empty($adminNotes))
            <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; margin: 14px 0;">
                <p style="margin: 0;"><strong>Admin Notes:</strong> {{ $adminNotes }}</p>
            </div>
        @endif

        <p style="margin: 0 0 12px 0;">
            Please keep your reviews respectful and policy-compliant. Reaching {{ $warningLimit }} warnings may result in account suspension.
        </p>

        <p style="margin: 0; color: #6b7280; font-size: 13px;">
            If you think this warning is incorrect, contact SoleSpace support through official channels.
        </p>
    </div>
</div>
</body>
</html>
