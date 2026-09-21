<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminPage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

final class AdminPagePermission extends Model
{
    protected $fillable = [
        'super_admin_id',
        'page_key',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'super_admin_id');
    }

    public static function grant(SuperAdmin $admin, AdminPage|string $page): self
    {
        $pageKey = $page instanceof AdminPage ? $page->value : $page;

        if (! AdminPage::isAssignable($pageKey)) {
            throw new InvalidArgumentException('The administrator page is not assignable.');
        }

        return self::query()->firstOrCreate([
            'super_admin_id' => $admin->getKey(),
            'page_key' => $pageKey,
        ]);
    }
}
