<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Position Template Permission Model
 * 
 * Represents individual permissions that belong to a position template
 */
class PositionTemplatePermission extends Model
{
    use HasFactory;

    protected $fillable = [
        'position_template_id',
        'permission_name',
    ];

    /**
     * Get the template this permission belongs to
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(PositionTemplate::class, 'position_template_id');
    }
}
