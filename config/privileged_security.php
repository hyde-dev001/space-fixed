<?php

return [
    'issuer' => config('app.name'),
    'setup_token_minutes' => 1440,
    'reset_token_minutes' => 60,
    'token_authorization_minutes' => 15,
    'recovery_ack_minutes' => 15,
    'recent_reauthentication_minutes' => 15,
    'totp_window' => 1,
    'recovery_code_count' => 8,
];
