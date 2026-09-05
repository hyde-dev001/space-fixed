<?php

declare(strict_types=1);

namespace App\Enums\Logistics;

enum LogisticsAction: string
{
    case ASSIGN_RIDER = 'assign_rider';
    case SCHEDULE_DELIVERY = 'schedule_delivery';
    case RESOLVE_EXCEPTION = 'resolve_exception';
    case SUBMIT_PROOF = 'submit_proof';
    case REVIEW_PROOF = 'review_proof';
    case CONFIRM_RETURN_RECEIPT = 'confirm_return_receipt';
}
