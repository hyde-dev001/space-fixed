<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

final class RehireAttentionAdapter extends EmployeeLifecycleAttentionAdapter
{
    protected function requestType(): string
    {
        return 'rehire';
    }

    protected function sourceType(): string
    {
        return 'rehire_request';
    }

    protected function adapterKeyValue(): string
    {
        return 'rehire_requests';
    }

    protected function coverageSourceValue(): string
    {
        return 'rehires';
    }
}
