<?php

namespace App\Http\Controllers\API\CRM;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationTransfer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ConversationController extends Controller
{
    /**
     * Get all conversations for the CRM's shop
     * Supports filtering by status, priority, and assigned staff
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Get shop_owner_id from authenticated user
        // For employees, they should have shop_owner_id set
        // For shop owners, they have their own id
        $shopOwnerId = $user->shop_owner_id ?? $user->id;

        if (!$shopOwnerId) {
            \Log::error('CRM: User not associated with a shop', [
                'user_id' => $user->id,
                'shop_owner_id' => $user->shop_owner_id ?? null,
            ]);
            return response()->json(['error' => 'User not associated with a shop'], 403);
        }

        \Log::info('CRM: Fetching conversations', [
            'user_id' => $user->id,
            'shop_owner_id' => $shopOwnerId,
        ]);

        $query = Conversation::where('shop_owner_id', $shopOwnerId)
            ->with(['customer', 'order', 'assignedTo', 'messages' => function ($q) {
                $q->latest()->limit(1); // Only load last message
            }]);

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by priority
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }

        // Filter by assigned staff
        if ($request->has('assigned_to_id')) {
            $query->where('assigned_to_id', $request->assigned_to_id);
        }

        // Order by last message time (most recent first)
        $conversations = $query->orderBy('last_message_at', 'desc')
            ->paginate(20);

        return response()->json($conversations);
    }

    /**
     * Get a specific conversation with all its messages
     */
    public function show(Conversation $conversation): JsonResponse
    {
        $user = Auth::user();

        // Get shop_owner_id for comparison
        $userShopOwnerId = $user->shop_owner_id ?? $user->id;

        // Verify user has access to this shop's conversation
        if ($conversation->shop_owner_id !== $userShopOwnerId) {
            \Log::warning('CRM: Unauthorized access to conversation', [
                'user_id' => $user->id,
                'conversation_shop_owner_id' => $conversation->shop_owner_id,
                'user_shop_owner_id' => $userShopOwnerId,
            ]);
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $conversation->load([
            'customer',
            'order',
            'assignedTo',
            'messages.sender',
            'transfers.fromUser',
            'transfers.toUser'
        ]);

        // Mark messages as read for this user
        $conversation->markAsRead($user->id);

        // Return in consistent format
        return response()->json([
            'id' => $conversation->id,
            'shop_owner_id' => $conversation->shop_owner_id,
            'customer_id' => $conversation->customer_id,
            'status' => $conversation->status,
            'priority' => $conversation->priority,
            'last_message_at' => $conversation->last_message_at,
            'customer' => [
                'id' => $conversation->customer->id,
                'name' => $conversation->customer->name,
                'email' => $conversation->customer->email,
            ],
            'messages' => $conversation->messages->map(function ($msg) {
                return [
                    'id' => $msg->id,
                    'conversation_id' => $msg->conversation_id,
                    'sender_id' => $msg->sender_id,
                    'sender_type' => $msg->sender_type,
                    'content' => $msg->content,
                    'attachments' => $msg->attachments,
                    'created_at' => $msg->created_at,
                ];
            }),
        ]);
    }

    /**
     * Send a message in a conversation
     */
    public function sendMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $user = Auth::user();

        // Verify access
        if ($conversation->shop_owner_id !== $user->shop_owner_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'content' => 'nullable|string|max:5000',
            'images' => 'nullable|array|max:5',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:5120' // 5MB max per image
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Must have either content or images
        if (!$request->get('content') && !$request->hasFile('images')) {
            return response()->json(['error' => 'Message must contain text or images'], 422);
        }

        // Handle image uploads
        $attachments = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $path = $image->store('conversation-images', 'public');
                $attachments[] = Storage::url($path);
            }
        }

        // Determine sender type based on user role
        $senderType = $this->determineSenderType($user);

        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type' => $senderType,
            'sender_id' => $user->id,
            'content' => $request->get('content'),
            'attachments' => count($attachments) > 0 ? $attachments : null,
        ]);

        // Update conversation's last_message_at
        $conversation->update([
            'last_message_at' => now(),
            'status' => $conversation->status === 'open' ? 'in_progress' : $conversation->status
        ]);

        $message->load('sender');

        return response()->json([
            'message' => 'Message sent successfully',
            'data' => $message
        ], 201);
    }

    /**
     * Transfer conversation to another staff member or department
     */
    public function transfer(Request $request, Conversation $conversation): JsonResponse
    {
        $user = Auth::user();

        // Verify access
        if ($conversation->shop_owner_id !== $user->shop_owner_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'to_user_id' => 'nullable|exists:users,id',
            'to_department' => 'required|in:crm,repairer',
            'transfer_note' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Verify target user belongs to the same shop
        if ($request->to_user_id) {
            $targetUser = User::find($request->to_user_id);
            if ($targetUser->shop_owner_id !== $user->shop_owner_id) {
                return response()->json(['error' => 'Target user not in same shop'], 422);
            }
        }

        // Create transfer record
        ConversationTransfer::create([
            'conversation_id' => $conversation->id,
            'from_user_id' => $user->id,
            'from_department' => $this->determineSenderType($user),
            'to_user_id' => $request->to_user_id,
            'to_department' => $request->to_department,
            'transfer_note' => $request->transfer_note,
        ]);

        // Update conversation assignment
        $conversation->update([
            'assigned_to_id' => $request->to_user_id,
            'assigned_to_type' => $request->to_department,
        ]);

        return response()->json([
            'message' => 'Conversation transferred successfully',
            'conversation' => $conversation->load('assignedTo')
        ]);
    }

    /**
     * Update conversation status
     */
    public function updateStatus(Request $request, Conversation $conversation): JsonResponse
    {
        $user = Auth::user();

        // Verify access
        if ($conversation->shop_owner_id !== $user->shop_owner_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:open,in_progress,resolved,closed'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $conversation->update(['status' => $request->status]);

        return response()->json([
            'message' => 'Status updated successfully',
            'conversation' => $conversation
        ]);
    }

    /**
     * Update conversation priority
     */
    public function updatePriority(Request $request, Conversation $conversation): JsonResponse
    {
        $user = Auth::user();

        // Verify access
        if ($conversation->shop_owner_id !== $user->shop_owner_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'priority' => 'required|in:low,medium,high'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $conversation->update(['priority' => $request->priority]);

        return response()->json([
            'message' => 'Priority updated successfully',
            'conversation' => $conversation
        ]);
    }

    /**
     * Determine sender type based on user role/department
     */
    private function determineSenderType(User $user): string
    {
        // Check user's role or department
        // You may need to adjust this based on your role system
        if ($user->role === 'CRM' || $user->department === 'CRM') {
            return 'crm';
        } elseif ($user->role === 'Repairer' || $user->position === 'Repairer') {
            return 'repairer';
        }

        // Default to CRM for staff users
        return 'crm';
    }
}
