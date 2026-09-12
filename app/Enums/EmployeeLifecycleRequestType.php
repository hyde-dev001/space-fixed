<?php

declare(strict_types=1);

namespace App\Enums;

enum EmployeeLifecycleRequestType: string
{
    case TERMINATION = 'termination';
    case REHIRE = 'rehire';

    public function label(): string
    {
        return match ($this) {
            self::TERMINATION => 'Employment termination',
            self::REHIRE => 'Employee rehire',
        };
    }

    public function actionNoun(): string
    {
        return match ($this) {
            self::TERMINATION => 'terminate the employment',
            self::REHIRE => 'rehire the employee',
        };
    }
}
