<?php

namespace App\Enums\Logistics;

enum ProofType: string
{
    case PHOTO = 'photo';
    case SIGNATURE = 'signature';
    case QR = 'qr';
    case STAFF_CONFIRMATION = 'staff_confirmation';
    case CUSTOMER_CONFIRMATION = 'customer_confirmation';
    case COURIER_RECEIPT = 'courier_receipt';
    case TRACKING_CONFIRMATION = 'tracking_confirmation';
}
