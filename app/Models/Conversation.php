<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $fillable = [
        'shop_owner_id',
        'customer_id',
        'order_id',
        'assigned_to_id',
        'assigned_to_type',
        'status',
        'priority',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    /**
     * Get the shop owner this conversation belongs to
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    /**
     * Get the customer in this conversation
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * Get the order related to this conversation (optional)
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the repair request related to this conversation (optional)
     */
    public function repairRequest(): HasOne
    {
        return $this->hasOne(RepairRequest::class, 'conversation_id');
    }

    /**
     * Get the staff member assigned to this conversation
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /**
     * Get all messages in this conversation
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('created_at', 'asc');
    }

    /**
     * Get all transfer records for this conversation
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(ConversationTransfer::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get unread messages count for a specific user
     */
    public function unreadMessagesCount(int $userId): int
    {
        return $this->messages()
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Mark all messages as read for a specific user
     */
    public function markAsRead(int $userId): void
    {
        $this->messages()
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
