<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender_type',
        'sender_id',
        'content',
        'attachments',
        'read_at',
    ];

    protected $casts = [
        'attachments' => 'array',
        'read_at' => 'datetime',
    ];

    /**
     * Get the conversation this message belongs to
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the sender of this message
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Check if this message is from customer
     */
    public function isFromCustomer(): bool
    {
        return $this->sender_type === 'customer';
    }

    /**
     * Check if this message is from CRM
     */
    public function isFromCRM(): bool
    {
        return $this->sender_type === 'crm';
    }

    /**
     * Check if this message is from repairer
     */
    public function isFromRepairer(): bool
    {
        return $this->sender_type === 'repairer';
    }

    /**
     * Check if message has been read
     */
    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Mark message as read
     */
    public function markAsRead(): void
    {
        if (!$this->isRead()) {
            $this->update(['read_at' => now()]);
        }
    }
}
