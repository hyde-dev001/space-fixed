<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Suspension Notice</title>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 24px; background: #f5f7fb; color: #111827;">
<div style="max-width: 640px; margin: 0 auto; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden;">
    <div style="background: #16233b; color: #ffffff; padding: 16px 20px; font-size: 18px; font-weight: 700;">
        SoleSpace Account Suspension Notice
    </div>

    <div style="padding: 20px; line-height: 1.6;">
        <p style="margin: 0 0 12px 0;">Hello {{ $accountName ?: 'User' }},</p>

        <p style="margin: 0 0 12px 0;">
            Your {{ $accountTypeLabel }} account has been suspended after an administrative review.
        </p>

        <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; margin: 14px 0;">
            <p style="margin: 0 0 8px 0;"><strong>Reason:</strong> {{ $reason ?: 'Policy violation requiring manual review.' }}</p>
            <p style="margin: 0;"><strong>Appeal Link Expires:</strong> {{ $expiresAtLabel }}</p>
        </div>

        <p style="margin: 0 0 16px 0;">
            If you believe this action is incorrect, you may submit an appeal using the secure link below.
        </p>

        <p style="margin: 0 0 20px 0;">
            <a href="{{ $appealUrl }}" style="display: inline-block; background: #16233b; color: #ffffff; text-decoration: none; padding: 10px 16px; border-radius: 8px; font-weight: 600;">
                Submit Appeal
            </a>
        </p>

        <p style="margin: 0; color: #6b7280; font-size: 13px;">
            This link is unique and time-limited for your security.
        </p>
    </div>
</div>
</body>
</html>
