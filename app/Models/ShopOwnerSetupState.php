<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopOwnerSetupState extends Model
{
    protected $fillable = ['shop_owner_id', 'welcome_seen_at', 'tutorials'];

    protected $casts = [
        'welcome_seen_at' => 'datetime',
        'tutorials' => 'array',
    ];
}
