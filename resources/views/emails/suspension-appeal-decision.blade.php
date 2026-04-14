<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suspension Appeal Decision</title>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 24px; background: #f5f7fb; color: #111827;">
<div style="max-width: 640px; margin: 0 auto; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden;">
    <div style="background: #16233b; color: #ffffff; padding: 16px 20px; font-size: 18px; font-weight: 700;">
        SoleSpace Appeal Decision
    </div>

    <div style="padding: 20px; line-height: 1.6;">
        <p style="margin: 0 0 12px 0;">Hello {{ $accountName ?: 'User' }},</p>

        <p style="margin: 0 0 12px 0;">
            Your suspension appeal for your {{ $accountTypeLabel }} account has been reviewed.
        </p>

        <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; margin: 14px 0;">
            <p style="margin: 0 0 8px 0;"><strong>Decision:</strong> {{ strtoupper($decision) }}</p>
            @if(!empty($reviewerNotes))
                <p style="margin: 0;"><strong>Notes:</strong> {{ $reviewerNotes }}</p>
            @endif
        </div>

        @if($decision === 'approved')
            <p style="margin: 0;">Your account access has been restored. Thank you for your patience.</p>
        @else
            <p style="margin: 0;">Your account remains suspended at this time.</p>
        @endif
    </div>
</div>
</body>
</html>
