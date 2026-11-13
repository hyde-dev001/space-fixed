<?php

declare(strict_types=1);

namespace App\Enums;

enum OwnerActionCenterRolloutReason: string
{
    case CanonicalShellNotSelected = 'canonical_shell_not_selected';
    case GlobalDisabled = 'action_center_global_disabled';
    case ShopNotAllowlisted = 'shop_not_allowlisted';
    case ShopAllowlisted = 'shop_allowlisted';
    case AlwaysOn = 'always_on';
    case CohortEvaluationFailed = 'cohort_evaluation_failed';
}
