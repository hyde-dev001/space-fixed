<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApprovalHistory extends Model
{
    use HasFactory;

    protected $table = 'approval_history';

    protected $fillable = [
        'approval_id',
        'level',
        'reviewer_id',
        'action',
        'comments'
    ];

    protected $casts = [
        'level' => 'integer'
    ];

    /**
     * Get the approval request this history belongs to
     */
    public function approval()
    {
        return $this->belongsTo(Approval::class);
    }

    /**
     * Get the reviewer who performed this action
     */
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
