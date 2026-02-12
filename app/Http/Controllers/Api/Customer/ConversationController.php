<?php

namespace App\Http\Controllers\API\Customer;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ShopOwner;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ConversationController extends Controller
{
    /**
     * Get all conversations for the authenticated customer
     */
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                \Log::warning('ConversationController@index - No authenticated user');
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
            
            \Log::info('ConversationController@index - Fetching conversations', ['user_id' => $user->id]);
            
            $conversations = Conversation::where('customer_id', $user->id)
                ->with(['shopOwner' => function ($q) {
                    // Select specific fields from shop_owners
                    $q->select('id', 'business_name', 'business_address', 'profile_photo', 'email', 'phone');
                }, 'messages' => function ($q) {
                    $q->latest()->limit(1); // Only load last message
                }])
                ->orderBy('last_message_at', 'desc')
                ->get()
                ->map(function ($conversation) {
                    // Format the response
                    return [
                        'id' => $conversation->id,
                        'shop_owner_id' => $conversation->shop_owner_id,
                        'customer_id' => $conversation->customer_id,
                        'status' => $conversation->status,
                        'last_message_at' => $conversation->last_message_at,
                        'shopOwner' => $conversation->shopOwner ? [
                            'id' => $conversation->shopOwner->id,
                            'business_name' => $conversation->shopOwner->business_name,
                            'location' => $conversation->shopOwner->business_address,
                            'profile_photo' => $conversation->shopOwner->profile_photo,
                            'email' => $conversation->shopOwner->email,
                            'phone' => $conversation->shopOwner->phone,
                        ] : null,
                        'messages' => $conversation->messages,
                    ];
                });

            return response()->json($conversations);
        } catch (\Exception $e) {
            \Log::error('ConversationController@index - Error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Server error'], 500);
        }
    }

    /**
     * Get or create a conversation with a specific shop
     */
    public function getOrCreate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shop_owner_id' => 'required|exists:shop_owners,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = Auth::user();
        
        // Find existing conversation or create new one
        $conversation = Conversation::firstOrCreate(
            [
                'shop_owner_id' => $request->shop_owner_id,
                'customer_id' => $user->id,
            ],
            [
                'status' => 'open',
                'priority' => 'medium',
                'last_message_at' => now(),
            ]
        );

        $conversation->load(['shopOwner', 'messages.sender']);

        return response()->json($conversation);
    }

    /**
     * Send a message to a shop
     */
    public function sendMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $user = Auth::user();
        
        // Verify this is the customer's conversation
        if ($conversation->customer_id !== $user->id) {
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

        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'sender_id' => $user->id,
            'content' => $request->get('content'),
            'attachments' => count($attachments) > 0 ? $attachments : null,
        ]);

        // Update conversation's last_message_at
        $conversation->update(['last_message_at' => now()]);

        $message->load('sender');

        return response()->json([
            'message' => 'Message sent successfully',
            'data' => $message
        ], 201);
    }

    /**
     * Get messages for a specific conversation
     */
    public function getMessages(Conversation $conversation): JsonResponse
    {
        $user = Auth::user();
        
        // Verify this is the customer's conversation
        if ($conversation->customer_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $messages = $conversation->messages()
            ->with('sender')
            ->orderBy('created_at', 'asc')
            ->get();

        // Mark messages as read for this customer
        $conversation->markAsRead($user->id);

        return response()->json($messages);
    }

    /**
     * Get list of shops the customer has contacted
     */
    public function getContactedShops(): JsonResponse
    {
        $user = Auth::user();
        
        $conversations = Conversation::where('customer_id', $user->id)
            ->with(['shopOwner', 'messages' => function ($q) {
                $q->latest()->limit(1);
            }])
            ->orderBy('last_message_at', 'desc')
            ->get();
        
        $shops = $conversations->map(function (Conversation $conversation) use ($user) {
            $lastMessage = $conversation->messages->first();
            
            return [
                'id' => $conversation->shop_owner_id,
                'conversation_id' => $conversation->id,
                'name' => $conversation->shopOwner->business_name,
                'location' => $conversation->shopOwner->business_address,
                'online' => false, // TODO: Implement online status
                'lastMessage' => $lastMessage?->content,
                'lastMessageTime' => $conversation->last_message_at,
                'unreadCount' => $conversation->unreadMessagesCount($user->id),
            ];
        });

        return response()->json($shops);
    }
}
