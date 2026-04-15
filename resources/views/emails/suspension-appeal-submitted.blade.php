<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Suspension Appeal Submitted</title>
</head>
<body style="margin:0; padding:0; background:#f5f7fb; font-family:Arial, Helvetica, sans-serif; color:#1f2937;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px; background:#ffffff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
                    <tr>
                        <td style="padding:20px 24px; background:#0f172a; color:#ffffff;">
                            <h1 style="margin:0; font-size:20px; line-height:1.3;">New Suspension Appeal Submitted</h1>
                            <p style="margin:8px 0 0; font-size:13px; color:#cbd5e1;">SoleSpace Admin Alert</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 16px; font-size:14px; line-height:1.6;">
                                A suspended {{ $accountTypeLabel }} account has submitted an appeal and is awaiting review.
                            </p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse; margin-bottom:16px;">
                                <tr>
                                    <td style="padding:8px 0; width:170px; font-size:13px; color:#6b7280;">Account Name</td>
                                    <td style="padding:8px 0; font-size:14px; color:#111827; font-weight:600;">{{ $accountName }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 0; width:170px; font-size:13px; color:#6b7280;">Account Type</td>
                                    <td style="padding:8px 0; font-size:14px; color:#111827; text-transform:capitalize;">{{ $accountTypeLabel }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 0; width:170px; font-size:13px; color:#6b7280;">Account Email</td>
                                    <td style="padding:8px 0; font-size:14px; color:#111827;">{{ $recipientEmail }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 0; width:170px; font-size:13px; color:#6b7280;">Submitted At</td>
                                    <td style="padding:8px 0; font-size:14px; color:#111827;">{{ $submittedAtLabel }}</td>
                                </tr>
                            </table>

                            @if(!empty($suspensionReason))
                                <div style="margin-bottom:16px; padding:12px; background:#fef2f2; border:1px solid #fecaca; border-radius:8px;">
                                    <p style="margin:0 0 6px; font-size:12px; font-weight:700; color:#991b1b; text-transform:uppercase;">Suspension Reason</p>
                                    <p style="margin:0; font-size:14px; line-height:1.6; color:#7f1d1d;">{{ $suspensionReason }}</p>
                                </div>
                            @endif

                            <div style="margin-bottom:20px; padding:12px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px;">
                                <p style="margin:0 0 6px; font-size:12px; font-weight:700; color:#1e3a8a; text-transform:uppercase;">Appeal Message</p>
                                <p style="margin:0; font-size:14px; line-height:1.6; color:#1e3a8a;">{{ $appealMessage }}</p>
                            </div>

                            <a href="{{ $reviewUrl }}" style="display:inline-block; background:#1d4ed8; color:#ffffff; text-decoration:none; font-size:14px; font-weight:600; padding:10px 16px; border-radius:8px;">
                                Review Appeal in Admin Panel
                            </a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
