<x-mail::message>
# Welcome to {{ $shopName }}

Hello {{ $userName }},

You have been invited to join {{ $shopName }}. Use the button below to set up your account and create your password.

<x-mail::button :url=$inviteUrl>
Set up your account
</x-mail::button>

This invitation link expires on **{{ $expiresAt }}** and can only be used once.

If you were not expecting this invitation, you can safely ignore this email.

Thanks,
{{ $shopName }}
</x-mail::message>
