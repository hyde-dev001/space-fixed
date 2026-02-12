<?php

namespace App\Traits;

use Spatie\Activitylog\LogOptions;

trait LogsActivityWithMetadata
{
    /**
     * Boot the trait and add metadata to activity logs
     */
    protected static function bootLogsActivityWithMetadata()
    {
        static::saving(function ($model) {
            if (method_exists($model, 'getActivitylogOptions')) {
                activity()
                    ->causedBy(auth()->user() ?? auth()->guard('shop_owner')->user())
                    ->withProperties([
                        'ip_address' => request()->ip(),
                        'user_agent' => request()->userAgent(),
                    ]);
            }
        });
    }
}
