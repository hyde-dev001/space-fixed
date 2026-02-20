<?php

namespace App\Http\Controllers\API\Repairer;

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
     * Get all conversations assigned to repairer department
     * Supports filtering by status, priority, and assigned staff
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        // Get shop_owner_id from authenticated user
        $shopOwnerId = $user->shop_owner_id ?? $user->id;
        
        if (!$shopOwnerId) {
            \Log::error('Repairer: User not associated with a shop', [
                'user_id' => $user->id,
                'shop_owner_id' => $user->shop_owner_id ?? null,
            ]);
            return response()->json(['error' => 'User not associated with a shop'], 403);
        }

        \Log::info('Repairer: Fetching conversations', [
            'user_id' => $user->id,
            'shop_owner_id' => $shopOwnerId,
        ]);

        $query = Conversation::where('shop_owner_id', $shopOwnerId)
            ->where('assigned_to_type', 'repairer') // Only repairer department conversations
            ->with([
                'customer', 
                'order', 
                'assignedTo',
                'repairRequest' => function ($q) {
                    $q->select('id', 'conversation_id', 'request_id', 'shoe_type', 'brand', 'description', 'status');
                },
                'messages' => function ($q) {
                    $q->latest()->limit(1);
                },
                'transfers' => function ($q) {
                    $q->latest()->limit(1)->with('fromUser');
                }
            ]);

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by priority
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }

        // Filter by assigned staff (specific repairer)
        if ($request->has('assigned_to_id')) {
            $query->where('assigned_to_id', $request->assigned_to_id);
        }

        // Order by last message time (most recent first)
        $conversations = $query->orderBy('last_message_at', 'desc')
            ->paginate(20);

        // Add transfer info to response
        $conversations->getCollection()->transform(function ($conversation) {
            $latestTransfer = $conversation->transfers->first();
            
            // Build repair_type from description, fallback to shoe_type and brand
            $repairRequestData = null;
            if ($conversation->repairRequest) {
                // Use description as the main display, fallback to shoe_type + brand
                $repairType = $conversation->repairRequest->description ?? '';
                if (empty($repairType)) {
                    $repairType = $conversation->repairRequest->shoe_type ?? '';
                    if ($conversation->repairRequest->brand) {
                        $repairType .= ' - ' . $conversation->repairRequest->brand;
                    }
                }
                
                $repairRequestData = [
                    'request_id' => $conversation->repairRequest->request_id,
                    'repair_type' => $repairType,
                    'description' => $conversation->repairRequest->description,
                    'status' => $conversation->repairRequest->status,
                ];
            }
            
            return [
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
                'repairRequest' => $repairRequestData,
                'messages' => $conversation->messages,
                'transfer_note' => $latestTransfer?->transfer_note,
                'transferred_from_name' => $latestTransfer?->fromUser?->name,
                'transferred_at' => $latestTransfer?->created_at,
            ];
        });

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
            \Log::warning('Repairer: Unauthorized access to conversation', [
                'user_id' => $user->id,
                'conversation_shop_owner_id' => $conversation->shop_owner_id,
                'user_shop_owner_id' => $userShopOwnerId,
            ]);
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Verify conversation is assigned to repairer department
        if ($conversation->assigned_to_type !== 'repairer') {
            \Log::warning('Repairer: Attempting to access non-repairer conversation', [
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'assigned_to_type' => $conversation->assigned_to_type,
            ]);
            return response()->json(['error' => 'This conversation is not assigned to repairer department'], 403);
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

        // Get latest transfer info
        $latestTransfer = $conversation->transfers->sortByDesc('created_at')->first();

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
            'transfer_note' => $latestTransfer?->transfer_note,
            'transferred_from' => $latestTransfer?->fromUser?->name,
        ]);
    }

    /**
     * Send a message in a conversation
     */
    public function sendMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $user = Auth::user();
        
        // Get shop_owner_id from authenticated user
        $userShopOwnerId = $user->shop_owner_id ?? $user->id;
        
        // Verify access
        if ($conversation->shop_owner_id !== $userShopOwnerId) {
            \Log::warning('Repairer: Unauthorized message send attempt', [
                'user_id' => $user->id,
                'conversation_shop_owner_id' => $conversation->shop_owner_id,
                'user_shop_owner_id' => $userShopOwnerId,
            ]);
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Verify conversation is assigned to repairer
        if ($conversation->assigned_to_type !== 'repairer') {
            return response()->json(['error' => 'This conversation is not assigned to repairer department'], 403);
        }

        $validator = Validator::make($request->all(), [
            'content' => 'nullable|string|max:5000',
            'images' => 'nullable|array|max:5',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:5120' // 5MB max per image
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Require either content or images
        if (!$request->content && !$request->hasFile('images')) {
            return response()->json(['error' => 'Message must have content or images'], 422);
        }

        // Handle image uploads
        $attachments = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $path = $image->store('conversation-attachments', 'public');
                $attachments[] = Storage::url($path);
            }
        }

        // Create message
        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'sender_type' => 'repairer',
            'content' => $request->content,
            'attachments' => !empty($attachments) ? $attachments : null,
        ]);

        // Update conversation's last message time
        $conversation->update([
            'last_message_at' => now(),
        ]);

        return response()->json([
            'message' => 'Message sent successfully',
            'data' => [
                'id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'sender_id' => $message->sender_id,
                'sender_type' => $message->sender_type,
                'content' => $message->content,
                'attachments' => $message->attachments,
                'created_at' => $message->created_at,
            ]
        ]);
    }

    /**
     * Transfer conversation back to CRM or to another repairer
     */
    public function transfer(Request $request, Conversation $conversation): JsonResponse
    {
        $user = Auth::user();
        
        // Get shop_owner_id from authenticated user
        $userShopOwnerId = $user->shop_owner_id ?? $user->id;
        
        // Verify access
        if ($conversation->shop_owner_id !== $userShopOwnerId) {
            \Log::warning('Repairer: Unauthorized transfer attempt', [
                'user_id' => $user->id,
                'conversation_shop_owner_id' => $conversation->shop_owner_id,
                'user_shop_owner_id' => $userShopOwnerId,
            ]);
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Verify conversation is currently with repairer
        if ($conversation->assigned_to_type !== 'repairer') {
            return response()->json(['error' => 'This conversation is not assigned to repairer department'], 403);
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
            $targetUserShopOwnerId = $targetUser->shop_owner_id ?? $targetUser->id;
            if ($targetUserShopOwnerId !== $userShopOwnerId) {
                return response()->json(['error' => 'Target user not in same shop'], 422);
            }
        }

        // Create transfer record
        ConversationTransfer::create([
            'conversation_id' => $conversation->id,
            'from_user_id' => $user->id,
            'from_department' => 'repairer',
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
     * Update conversation status (mark as resolved, etc.)
     */
    public function updateStatus(Request $request, Conversation $conversation): JsonResponse
    {
        $user = Auth::user();
        
        // Get shop_owner_id from authenticated user
        $userShopOwnerId = $user->shop_owner_id ?? $user->id;
        
        // Verify access
        if ($conversation->shop_owner_id !== $userShopOwnerId) {
            \Log::warning('Repairer: Unauthorized status update attempt', [
                'user_id' => $user->id,
                'conversation_shop_owner_id' => $conversation->shop_owner_id,
                'user_shop_owner_id' => $userShopOwnerId,
            ]);
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Verify conversation is assigned to repairer
        if ($conversation->assigned_to_type !== 'repairer') {
            return response()->json(['error' => 'This conversation is not assigned to repairer department'], 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:open,in_progress,resolved,closed'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $conversation->update(['status' => $request->status]);

        \Log::info('Repairer: Updated conversation status', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'status' => $request->status,
        ]);

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
        
        // Get shop_owner_id from authenticated user
        $userShopOwnerId = $user->shop_owner_id ?? $user->id;
        
        // Verify access
        if ($conversation->shop_owner_id !== $userShopOwnerId) {
            \Log::warning('Repairer: Unauthorized priority update attempt', [
                'user_id' => $user->id,
                'conversation_shop_owner_id' => $conversation->shop_owner_id,
                'user_shop_owner_id' => $userShopOwnerId,
            ]);
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Verify conversation is assigned to repairer
        if ($conversation->assigned_to_type !== 'repairer') {
            return response()->json(['error' => 'This conversation is not assigned to repairer department'], 403);
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
     * Activate payment for a repair request
     * This enables the "Pay Now" button on customer's side
     */
    public function activatePayment(Conversation $conversation): JsonResponse
    {
        $user = Auth::user();
        
        // Get shop_owner_id from authenticated user
        $userShopOwnerId = $user->shop_owner_id ?? $user->id;
        
        // Verify access
        if ($conversation->shop_owner_id !== $userShopOwnerId) {
            \Log::warning('Repairer: Unauthorized payment activation attempt', [
                'user_id' => $user->id,
                'conversation_shop_owner_id' => $conversation->shop_owner_id,
                'user_shop_owner_id' => $userShopOwnerId,
            ]);
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Verify conversation is assigned to repairer
        if ($conversation->assigned_to_type !== 'repairer') {
            return response()->json(['error' => 'This conversation is not assigned to repairer department'], 403);
        }

        // Get the repair request associated with this conversation
        $repairRequest = $conversation->repairRequest;
        
        if (!$repairRequest) {
            return response()->json(['error' => 'No repair request found for this conversation'], 404);
        }

        // Check if payment is already enabled
        if ($repairRequest->payment_enabled) {
            return response()->json([
                'success' => true,
                'message' => 'Payment is already enabled for this repair',
                'repair_request' => $repairRequest
            ]);
        }

        // Enable payment
        $repairRequest->update([
            'payment_enabled' => true,
            'payment_enabled_at' => now(),
            'payment_enabled_by' => $user->id,
        ]);

        \Log::info('Repairer: Payment activated for repair request', [
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'repair_request_id' => $repairRequest->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment has been activated successfully',
            'repair_request' => $repairRequest
        ]);
    }
}
