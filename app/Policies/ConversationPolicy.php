<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ConversationPolicy
{
    /**
     * Determine whether the user can view any models.
     * Customers can view their own conversations
     * Staff can only view conversations from their shop
     */
    public function viewAny(User $user): bool
    {
        // Both customers and staff can view conversations
        return true;
    }

    /**
     * Determine whether the user can view the model.
     * Users can view if:
     * - They are the customer in the conversation
     * - They are staff at the shop that owns the conversation
     */
    public function view(User $user, Conversation $conversation): bool
    {
        // Customer can view their own conversation
        if ($conversation->customer_id === $user->id) {
            return true;
        }

        // Staff can view conversations from their shop
        if ($user->shop_owner_id === $conversation->shop_owner_id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can create models.
     * Customers can create conversations
     */
    public function create(User $user): bool
    {
        // Any authenticated user can create a conversation
        return true;
    }

    /**
     * Determine whether the user can update the model.
     * Only staff from the conversation's shop can update it
     */
    public function update(User $user, Conversation $conversation): bool
    {
        return $user->shop_owner_id === $conversation->shop_owner_id;
    }

    /**
     * Determine whether the user can delete the model.
     * Only shop staff can delete conversations
     */
    public function delete(User $user, Conversation $conversation): bool
    {
        return $user->shop_owner_id === $conversation->shop_owner_id;
    }

    /**
     * Determine whether the user can send messages in this conversation
     */
    public function sendMessage(User $user, Conversation $conversation): bool
    {
        // Customer can send messages in their own conversation
        if ($conversation->customer_id === $user->id) {
            return true;
        }

        // Staff can send messages in their shop's conversations
        if ($user->shop_owner_id === $conversation->shop_owner_id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can transfer the conversation
     * Only staff from the conversation's shop can transfer
     */
    public function transfer(User $user, Conversation $conversation): bool
    {
        return $user->shop_owner_id === $conversation->shop_owner_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Conversation $conversation): bool
    {
        return $user->shop_owner_id === $conversation->shop_owner_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Conversation $conversation): bool
    {
        return $user->shop_owner_id === $conversation->shop_owner_id;
    }
}
