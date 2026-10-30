<x-mail::message>
# Administrator password reset

Hello {{ $recipientName }},

Use the link below to reset your SoleSpace administrator password. The link expires and can be used only once.

<x-mail::button :url="$resetUrl">
Reset password
</x-mail::button>

If you did not request this, you can ignore this message.
</x-mail::message>
