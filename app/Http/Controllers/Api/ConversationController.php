<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\RepairRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ConversationController extends Controller
{
    /**
     * Get all conversations for shop owner (individual shops)
     * Displays both general customer support and repair conversations
     */
    public function indexShopOwner(Request $request): JsonResponse
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        if (!$shopOwner) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        \Log::info('Shop Owner: Fetching conversations', [
            'shop_owner_id' => $shopOwner->id,
        ]);

        $query = Conversation::where('shop_owner_id', $shopOwner->id)
            ->with([
                'customer',
                'order',
                'repairRequest' => function ($q) {
                    $q->select('id', 'conversation_id', 'request_id', 'shoe_type', 'brand', 'description', 'status');
                },
                'messages' => function ($q) {
                    $q->latest()->limit(1);
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

        // Order by last message time (most recent first)
        $conversations = $query->orderBy('last_message_at', 'desc')
            ->paginate(20);

        // Format response
        $conversations->getCollection()->transform(function ($conversation) {
            // Build repair_type from description, fallback to shoe_type and brand
            $repairRequestData = null;
            if ($conversation->repairRequest) {
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
                    'profile_photo' => $conversation->customer->profile_photo,
                    'profile_photo_url' => $conversation->customer->profile_photo
                        ? (str_starts_with($conversation->customer->profile_photo, '/')
                            ? $conversation->customer->profile_photo
                            : '/storage/' . ltrim($conversation->customer->profile_photo, '/'))
                        : null,
                ],
                'repairRequest' => $repairRequestData,
                'messages' => $conversation->messages,
            ];
        });

        return response()->json($conversations);
    }

    /**
     * Get a specific conversation with all its messages (Shop Owner)
     */
    public function showShopOwner($id): JsonResponse
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        if (!$shopOwner) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        // Verify shop owner has access to this conversation
        if ($conversation->shop_owner_id !== $shopOwner->id) {
            \Log::warning('Shop Owner: Unauthorized access to conversation', [
                'shop_owner_id' => $shopOwner->id,
                'conversation_shop_owner_id' => $conversation->shop_owner_id,
            ]);
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $conversation->load([
            'customer',
            'order',
            'repairRequest',
            'messages.sender'
        ]);

        // Build repair info
        $repairRequestData = null;
        if ($conversation->repairRequest) {
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
                'profile_photo' => $conversation->customer->profile_photo,
                'profile_photo_url' => $conversation->customer->profile_photo
                    ? (str_starts_with($conversation->customer->profile_photo, '/')
                        ? $conversation->customer->profile_photo
                        : '/storage/' . ltrim($conversation->customer->profile_photo, '/'))
                    : null,
            ],
            'repairRequest' => $repairRequestData,
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
     * Send a message in a conversation (Shop Owner)
     */
    public function storeMessageShopOwner(Request $request, $id): JsonResponse
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        if (!$shopOwner) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        // Verify access
        if ($conversation->shop_owner_id !== $shopOwner->id) {
            \Log::warning('Shop Owner: Unauthorized message send attempt', [
                'shop_owner_id' => $shopOwner->id,
                'conversation_shop_owner_id' => $conversation->shop_owner_id,
            ]);
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

        // Create message with shop_owner sender type
        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $shopOwner->id,
            'sender_type' => 'shop_owner',
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
     * Activate payment for completed repair (Shop Owner)
     */
    public function activatePaymentShopOwner(Request $request, $id): JsonResponse
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        if (!$shopOwner) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        // Verify access
        if ($conversation->shop_owner_id !== $shopOwner->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get the repair request
        $repairRequest = $conversation->repairRequest;
        if (!$repairRequest) {
            return response()->json(['error' => 'No repair request found'], 404);
        }

        // Validate repair is at correct status for payment activation
        $validStatuses = ['repairer_accepted', 'received', 'completed', 'ready_for_pickup'];
        if (!in_array($repairRequest->status, $validStatuses)) {
            return response()->json(['error' => 'Cannot activate payment at current status'], 400);
        }

        // Check if payment is already enabled
        if ($repairRequest->payment_enabled) {
            return response()->json(['error' => 'Payment is already activated'], 400);
        }

        // Enable payment without changing status
        $repairRequest->update([
            'payment_enabled' => true,
            'payment_enabled_at' => now(),
            'payment_enabled_by' => $shopOwner->id,
        ]);

        // Update conversation status
        $conversation->update([
            'status' => 'awaiting_payment',
        ]);

        // Create a system message
        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $shopOwner->id,
            'sender_type' => 'shop_owner',
            'content' => 'Payment has been activated. Please proceed to pay before we start the repair work.',
        ]);

        return response()->json([
            'message' => 'Payment activated successfully',
            'repair_status' => $repairRequest->status,
        ]);
    }

    /**
     * Transfer conversation to repairer department (Shop Owner)
     */
    public function transferShopOwner(Request $request, $id): JsonResponse
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        if (!$shopOwner) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        // Verify access
        if ($conversation->shop_owner_id !== $shopOwner->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'to_department' => 'required|in:repairer',
            'transfer_note' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Update conversation assignment to repairer department
        $conversation->update([
            'assigned_to_type' => 'repairer',
            'assigned_to_id' => null, // Will be assigned to specific repairer later
        ]);

        // Create transfer note as system message
        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $shopOwner->id,
            'sender_type' => 'system',
            'content' => "Conversation transferred to Repairer department.\nNote: " . $request->transfer_note,
        ]);

        return response()->json([
            'message' => 'Conversation transferred successfully',
        ]);
    }
}
