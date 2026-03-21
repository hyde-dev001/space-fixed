<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset OTP</title>
</head>
<body style="margin:0;padding:0;background:#f7f7f7;font-family:Arial,Helvetica,sans-serif;color:#111;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f7f7f7;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #eaeaea;border-radius:8px;overflow:hidden;">
                    <tr>
                        <td style="padding:24px 24px 8px 24px;">
                            <h1 style="margin:0;font-size:22px;line-height:1.3;">Password Reset Verification</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 24px 0 24px;font-size:14px;line-height:1.7;color:#333;">
                            You requested to reset your SoleSpace password. Use the OTP below to continue:
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 24px 8px 24px;" align="center">
                            <div style="display:inline-block;padding:12px 20px;border:1px dashed #111;border-radius:8px;font-size:28px;letter-spacing:6px;font-weight:700;">{{ $otp }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 24px 0 24px;font-size:14px;line-height:1.7;color:#333;">
                            This OTP is valid for 10 minutes. If you did not request this, you can safely ignore this email.
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;font-size:12px;color:#666;">
                            SoleSpace Security Team
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
