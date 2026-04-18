<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Warning Notice</title>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 24px; background: #f5f7fb; color: #111827;">
<div style="max-width: 640px; margin: 0 auto; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden;">
    <div style="background: #7c2d12; color: #ffffff; padding: 16px 20px; font-size: 18px; font-weight: 700;">
        SoleSpace Account Warning
    </div>

    <div style="padding: 20px; line-height: 1.6;">
        <p style="margin: 0 0 12px 0;">Hello {{ $accountName ?: 'Shop Owner' }},</p>

        <p style="margin: 0 0 12px 0;">
            Your shop account has been formally warned after an administrative review of customer-submitted report(s).
        </p>

        <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px; margin: 14px 0;">
            <p style="margin: 0 0 8px 0;"><strong>Total Reports Reviewed:</strong> {{ $reportCount }}</p>
            <p style="margin: 0 0 8px 0;"><strong>Primary Reason:</strong> {{ $primaryReason }}</p>
            <p style="margin: 0;"><strong>Reviewed At:</strong> {{ $reviewedAtLabel }}</p>
        </div>

        @if(!empty($adminNotes))
            <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; margin: 14px 0;">
                <p style="margin: 0;"><strong>Admin Notes:</strong> {{ $adminNotes }}</p>
            </div>
        @endif

        <p style="margin: 0 0 12px 0;">
            Please review your shop operations and take corrective action immediately. Repeated or severe violations may lead to account suspension.
        </p>

        <p style="margin: 0; color: #6b7280; font-size: 13px;">
            If you believe this warning was issued in error, contact SoleSpace support through official channels.
        </p>
    </div>
</div>
</body>
</html>
