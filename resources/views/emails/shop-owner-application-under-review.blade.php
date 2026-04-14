<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Under Review</title>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 24px; background: #f5f7fb; color: #111827;">
<div style="max-width: 640px; margin: 0 auto; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden;">
    <div style="background: #16233b; color: #ffffff; padding: 16px 20px; font-size: 18px; font-weight: 700;">
        SoleSpace Shop Owner Registration
    </div>

    <div style="padding: 20px; line-height: 1.6;">
        <p style="margin: 0 0 12px 0;">Hello {{ $ownerName ?: 'Shop Owner' }},</p>

        <p style="margin: 0 0 12px 0;">
            Thank you for registering <strong>{{ $businessName }}</strong>.
        </p>

        <p style="margin: 0 0 12px 0;">
            Your application is currently <strong>under review</strong>. You can check your latest application status anytime using the button below.
        </p>

        <p style="margin: 0 0 20px 0;">
            <a href="{{ $pendingApprovalUrl }}" style="display: inline-block; background: #16233b; color: #ffffff; text-decoration: none; padding: 10px 16px; border-radius: 8px; font-weight: 600;">
                View Application Status
            </a>
        </p>

        <p style="margin: 0 0 12px 0; color: #6b7280; font-size: 13px;">
            Review usually takes 3 to 7 business days. We will also notify you by email once the review is done.
        </p>

        <p style="margin: 0; color: #6b7280; font-size: 13px;">
            This link is secure and time-limited for your protection.
        </p>
    </div>
</div>
</body>
</html>
