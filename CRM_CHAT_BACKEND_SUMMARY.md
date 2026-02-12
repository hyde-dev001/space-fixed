# CRM Customer Support Chat - Backend Implementation Summary

## ✅ Completed Implementation

### Phase 1: Database Migrations ✅
Created 3 migration files with complete schemas:

1. **conversations table** (`2026_02_12_105332_create_conversations_table.php`)
   - `shop_owner_id` - Which shop this conversation belongs to
   - `customer_id` - The customer in this conversation
   - `order_id` - Optional link to order
   - `assigned_to_id` - Staff member handling this conversation
   - `assigned_to_type` - enum: 'crm' or 'repairer'
   - `status` - enum: 'open', 'in_progress', 'resolved', 'closed'
   - `priority` - enum: 'low', 'medium', 'high'
   - `last_message_at` - For sorting conversations by activity
   - Indexes for performance on shop queries

2. **conversation_messages table** (`2026_02_12_105450_create_conversation_messages_table.php`)
   - `conversation_id` - Foreign key to conversations
   - `sender_type` - enum: 'customer', 'crm', 'repairer'
   - `sender_id` - Foreign key to users
   - `content` - Message text (nullable for image-only messages)
   - `attachments` - JSON array of image URLs
   - `read_at` - Timestamp for read receipts
   - Indexes for fetching messages and unread counts

3. **conversation_transfers table** (`2026_02_12_105507_create_conversation_transfers_table.php`)
   - `conversation_id` - Foreign key to conversations
   - `from_user_id` - Staff member who initiated transfer
   - `from_department` - enum: 'crm', 'repairer'
   - `to_user_id` - Staff member receiving the conversation (nullable)
   - `to_department` - enum: 'crm', 'repairer'
   - `transfer_note` - Optional reason/context for transfer
   - Indexes for conversation history and user transfer logs

**Status:** ✅ All migrations run successfully

---

### Phase 2: Eloquent Models ✅
Created 3 models with relationships and helper methods:

1. **Conversation Model** (`app/Models/Conversation.php`)
   - **Relationships:**
     - `shopOwner()` - belongsTo ShopOwner
     - `customer()` - belongsTo User
     - `order()` - belongsTo Order
     - `assignedTo()` - belongsTo User (staff member)
     - `messages()` - hasMany ConversationMessage
     - `transfers()` - hasMany ConversationTransfer
   - **Helper Methods:**
     - `unreadMessagesCount($userId)` - Count unread messages for user
     - `markAsRead($userId)` - Mark all messages as read

2. **ConversationMessage Model** (`app/Models/ConversationMessage.php`)
   - **Relationships:**
     - `conversation()` - belongsTo Conversation
     - `sender()` - belongsTo User
   - **Helper Methods:**
     - `isFromCustomer()` - Check sender type
     - `isFromCRM()` - Check sender type
     - `isFromRepairer()` - Check sender type
     - `isRead()` - Check if message is read
     - `markAsRead()` - Mark single message as read

3. **ConversationTransfer Model** (`app/Models/ConversationTransfer.php`)
   - **Relationships:**
     - `conversation()` - belongsTo Conversation
     - `fromUser()` - belongsTo User
     - `toUser()` - belongsTo User
   - **Helper Methods:**
     - `isTransferToCRM()` - Check if transferred to CRM
     - `isTransferToRepairer()` - Check if transferred to repairer

**Status:** ✅ All models created with proper relationships

---

### Phase 3: Controllers ✅
Created 2 controllers with complete API logic:

1. **CRM ConversationController** (`app/Http/Controllers/API/CRM/ConversationController.php`)
   - `index()` - Get all conversations for CRM's shop (with filters)
   - `show()` - Get specific conversation with all messages
   - `sendMessage()` - Send message with image upload support
   - `transfer()` - Transfer conversation to another staff/department
   - `updateStatus()` - Change conversation status
   - `updatePriority()` - Change conversation priority
   - **Shop Scoping:** All queries filtered by `shop_owner_id`
   - **Access Control:** Verifies user belongs to shop before actions

2. **Customer ConversationController** (`app/Http/Controllers/API/Customer/ConversationController.php`)
   - `index()` - Get all customer's conversations
   - `getOrCreate()` - Get existing or create new conversation with shop
   - `sendMessage()` - Customer sends message with images
   - `getMessages()` - Get all messages in conversation
   - `getContactedShops()` - Get list of shops customer has contacted
   - **Customer Scoping:** All queries filtered by `customer_id`

**Status:** ✅ Both controllers fully implemented

---

### Phase 4: Policies ✅
Created ConversationPolicy for authorization:

**ConversationPolicy** (`app/Policies/ConversationPolicy.php`)
- `viewAny()` - Staff with shop_owner_id can view conversations
- `view()` - Customer can view own conversations, staff can view shop's conversations
- `create()` - Any authenticated user can create conversations
- `update()` - Only shop staff can update conversations
- `delete()` - Only shop staff can delete conversations
- `sendMessage()` - Customer or shop staff can send messages
- `transfer()` - Only shop staff can transfer conversations

**Status:** ✅ Policy created with shop-scoping logic

---

### Phase 5: API Routes ✅
Registered all endpoints in `routes/api.php`:

**Customer Routes** (`/api/customer/conversations`)
```php
GET    /                        # Get all customer's conversations
GET    /shops                   # Get contacted shops list
POST   /get-or-create           # Get or create conversation with shop
GET    /{conversation}/messages # Get messages in conversation
POST   /{conversation}/messages # Send message to shop
```

**CRM Routes** (`/api/crm/conversations`)
```php
GET    /                        # Get all shop's conversations (with filters)
GET    /{conversation}          # Get specific conversation
POST   /{conversation}/messages # Send message to customer
POST   /{conversation}/transfer # Transfer to another staff/department
PATCH  /{conversation}/status   # Update conversation status
PATCH  /{conversation}/priority # Update conversation priority
```

**Middleware:** All routes protected with `['web', 'auth:user']`

**Status:** ✅ All routes registered and middleware applied

---

## 🔄 Next Steps (Future Phases)

### Phase 6: Frontend Integration
- Update `customerSupport.tsx` (CRM interface) to use real API
- Update `message.tsx` (Customer interface) to use real API
- Replace mock data with API calls
- Implement image upload UI
- Add loading states and error handling

### Phase 7: Real-time Broadcasting (Optional)
- Install Laravel Broadcasting
- Create `MessageSent` event
- Configure Pusher or Laravel Websockets
- Add real-time message updates to frontend

---

## 📊 Database Schema Overview

```
┌─────────────────────────────────────────────────────────────┐
│                     CONVERSATIONS                            │
├─────────────────────────────────────────────────────────────┤
│ id                                                           │
│ shop_owner_id     → shop_owners.id                          │
│ customer_id       → users.id                                │
│ order_id          → orders.id (nullable)                    │
│ assigned_to_id    → users.id (nullable)                     │
│ assigned_to_type  (crm/repairer)                            │
│ status            (open/in_progress/resolved/closed)        │
│ priority          (low/medium/high)                         │
│ last_message_at                                             │
└─────────────────────────────────────────────────────────────┘
                            ↓
                    Has Many Messages
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                 CONVERSATION_MESSAGES                        │
├─────────────────────────────────────────────────────────────┤
│ id                                                           │
│ conversation_id   → conversations.id                        │
│ sender_type       (customer/crm/repairer)                   │
│ sender_id         → users.id                                │
│ content           (text message)                            │
│ attachments       (JSON array of image URLs)                │
│ read_at           (timestamp)                               │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│               CONVERSATION_TRANSFERS                         │
├─────────────────────────────────────────────────────────────┤
│ id                                                           │
│ conversation_id   → conversations.id                        │
│ from_user_id      → users.id                                │
│ from_department   (crm/repairer)                            │
│ to_user_id        → users.id (nullable)                     │
│ to_department     (crm/repairer)                            │
│ transfer_note     (optional context)                        │
└─────────────────────────────────────────────────────────────┘
```

---

## 🎯 Key Features Implemented

### Multi-Shop Support
- ✅ Each shop has independent conversations
- ✅ Shop scoping enforced in queries
- ✅ Staff only see their shop's conversations

### CRM → Repairer Handoff
- ✅ Transfer system with audit log
- ✅ Transfer notes for context
- ✅ Track transfer history

### Message System
- ✅ Text messages
- ✅ Image attachments (up to 5 images per message)
- ✅ Read receipts
- ✅ Message history

### Customer Experience
- ✅ Contact multiple shops independently
- ✅ View conversation history per shop
- ✅ Send images with messages
- ✅ See "Support Team" label (seamless handoff)

### Access Control
- ✅ Policy-based authorization
- ✅ Shop-scoped data access
- ✅ Customer can only view own conversations
- ✅ Staff can only view shop's conversations

---

## 🔧 Configuration Notes

### Image Storage
- Images stored in `storage/app/public/conversation-images/`
- Max 5 images per message
- Max 5MB per image
- Supported formats: JPEG, PNG, JPG, GIF

### Authentication
- Uses existing Laravel auth system
- Assumes users have `shop_owner_id` field
- Middleware: `['web', 'auth:user']`

### Role Detection
The `determineSenderType()` method in CRM controller determines if user is CRM or Repairer:
```php
// Based on user->role or user->department
// Customize this based on your role system
```

---

## 📝 Usage Examples

### Customer Creates Conversation
```javascript
// Frontend call
const response = await fetch('/api/customer/conversations/get-or-create', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ shop_owner_id: 1 })
});
```

### Customer Sends Message
```javascript
const formData = new FormData();
formData.append('content', 'Hello! I need help with my repair order.');
formData.append('images[]', imageFile1);
formData.append('images[]', imageFile2);

await fetch('/api/customer/conversations/1/messages', {
  method: 'POST',
  body: formData
});
```

### CRM Views Conversations
```javascript
// Get all open conversations for shop
const response = await fetch('/api/crm/conversations?status=open');
```

### CRM Transfers to Repairer
```javascript
await fetch('/api/crm/conversations/1/transfer', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    to_user_id: 5,
    to_department: 'repairer',
    transfer_note: 'Technical question about sole replacement'
  })
});
```

---

## ✅ Checklist

- [x] Phase 1: Database migrations created and run
- [x] Phase 2: Eloquent models with relationships
- [x] Phase 3: CRM and Customer controllers
- [x] Phase 4: Authorization policy
- [x] Phase 5: API routes registered
- [ ] Phase 6: Frontend integration
- [ ] Phase 7: Real-time broadcasting (optional)

---

## 🎉 Ready for Testing!

The backend is now fully functional and ready to be integrated with the frontend. All endpoints are secured with authentication and shop-scoping logic.

**Next Action:** Update the React components (`customerSupport.tsx` and `message.tsx`) to use these API endpoints instead of mock data.
