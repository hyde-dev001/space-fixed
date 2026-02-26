@component('mail::message')
# Welcome to {{ $shopName }}! 🎉

Hi **{{ $userName }}**,

You've been invited to join the {{ $shopName }} team on SoleSpace. We're excited to have you on board!

## 🔑 Set Up Your Account

Click the button below to create your password and activate your account:

@component('mail::button', ['url' => $inviteUrl, 'color' => 'success'])
Set Up My Account
@endcomponent

**Or copy this link:**
```
{{ $inviteUrl }}
```

---

## ⏰ Important Details

- **Invitation expires:** {{ $expiresAt }}
- **Your email:** This email was sent to the address registered in our system
- **Security:** This is a one-time use link. Once you set your password, the link becomes invalid

---

## 🆘 Need Help?

If you have any questions or didn't request this account, please contact your manager or shop owner.

Thanks,<br>
The {{ $shopName }} Team

---

<small style="color: #999;">
This is an automated message. Please do not reply to this email.<br>
If the button above doesn't work, copy and paste the link into your web browser.
</small>

@endcomponent
