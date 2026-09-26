<?php

declare(strict_types=1);

namespace App\Enums;

enum OwnerShellSelectionReason: string
{
    case GlobalDisabled = 'global_disabled';
    case ShopNotAllowlisted = 'shop_not_allowlisted';
    case ShopAllowlisted = 'shop_allowlisted';
    case AlwaysOn = 'always_on';
    case InvalidRegistrationContext = 'invalid_registration_context';
    case CohortEvaluationFailed = 'cohort_evaluation_failed';
    case ShellCompositionFailed = 'shell_composition_failed';
}
