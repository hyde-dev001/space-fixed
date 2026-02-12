<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'action', 'object_type', 'object_id', 'data',
        'shop_owner_id', 'actor_user_id', 'target_type', 'target_id', 'metadata'
    ];

    protected $casts = [
        'data' => 'array',
        'metadata' => 'array',
    ];
}
