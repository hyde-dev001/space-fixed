<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

final class TerminationAttentionAdapter extends EmployeeLifecycleAttentionAdapter
{
    protected function requestType(): string
    {
        return 'termination';
    }

    protected function sourceType(): string
    {
        return 'termination_request';
    }

    protected function adapterKeyValue(): string
    {
        return 'termination_requests';
    }

    protected function coverageSourceValue(): string
    {
        return 'terminations';
    }
}
