<?php

declare(strict_types=1);

namespace App\Contracts\OwnerActionCenter;

use App\Models\ShopOwner;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;

interface OwnerAttentionAdapter
{
    public function adapterKey(): string;

    public function coverageSource(): string;

    public function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerAttentionAdapterResult;
}
