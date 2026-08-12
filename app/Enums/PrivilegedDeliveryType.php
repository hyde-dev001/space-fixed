<?php

declare(strict_types=1);

namespace App\Enums;

enum PrivilegedDeliveryType: string
{
    case PRIVILEGED_ADMIN_SETUP = 'privileged_admin_setup';
    case PRIVILEGED_PASSWORD_RESET = 'privileged_password_reset';
    case SHOP_REGISTRATION_APPROVED = 'shop_registration_approved';
    case SHOP_REGISTRATION_REJECTED = 'shop_registration_rejected';
    case SHOP_SUSPENSION_NOTICE = 'shop_suspension_notice';
    case CUSTOMER_SUSPENSION_NOTICE = 'customer_suspension_notice';
    case SHOP_REPORT_WARNING = 'shop_report_warning';
    case SUSPENSION_APPEAL_SUBMITTED = 'suspension_appeal_submitted';
    case SUSPENSION_APPEAL_DECIDED = 'suspension_appeal_decided';
    case SHOP_OWNER_UPGRADE_REQUESTED = 'shop_owner_upgrade_requested';
    case SHOP_OWNER_UPGRADE_REVIEWED = 'shop_owner_upgrade_reviewed';
    case SHOP_DOCUMENT_RENEWAL_SUBMITTED = 'shop_document_renewal_submitted';
    case SHOP_DOCUMENT_RENEWAL_REVIEWED = 'shop_document_renewal_reviewed';
}
