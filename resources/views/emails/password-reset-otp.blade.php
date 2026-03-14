<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset OTP</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f7fb;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;">
                <tr>
                    <td style="background:#111827;color:#ffffff;padding:20px 24px;font-size:20px;font-weight:bold;">
                        SoleSpace
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px;color:#111827;">
                        <p style="margin:0 0 12px;font-size:16px;">You requested to reset your password.</p>
                        <p style="margin:0 0 16px;font-size:14px;color:#4b5563;">Use this One-Time Password (OTP) to continue:</p>

                        <div style="margin:18px 0;padding:14px 18px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;display:inline-block;font-size:28px;letter-spacing:6px;font-weight:700;color:#1f2937;">
                            {{ $otp }}
                        </div>

                        <p style="margin:16px 0 0;font-size:14px;color:#4b5563;">This OTP expires in 10 minutes.</p>
                        <p style="margin:8px 0 0;font-size:14px;color:#4b5563;">If you did not request this, you can ignore this email.</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
