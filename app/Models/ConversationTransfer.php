<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationTransfer extends Model
{
    protected $fillable = [
        'conversation_id',
        'from_user_id',
        'from_department',
        'to_user_id',
        'to_department',
        'transfer_note',
    ];

    /**
     * Get the conversation this transfer belongs to
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the user who initiated the transfer
     */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /**
     * Get the user who received the transfer
     */
    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /**
     * Check if this is a transfer to CRM
     */
    public function isTransferToCRM(): bool
    {
        return $this->to_department === 'crm';
    }

    /**
     * Check if this is a transfer to repairer
     */
    public function isTransferToRepairer(): bool
    {
        return $this->to_department === 'repairer';
    }
}
