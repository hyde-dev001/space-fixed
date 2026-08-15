<?php

declare(strict_types=1);

namespace App\Enums;

enum OwnerShellFallbackReason: string
{
    case MissingDestination = 'missing_destination';
    case MissingAction = 'missing_action';
    case Verification = 'verification';
    case UserPreference = 'user_preference';
}
