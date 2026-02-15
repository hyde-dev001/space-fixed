# Repairer Chat System - Backend Implementation

## Overview
Complete backend API implementation for the repairer department to handle technical support conversations transferred from CRM.

## Files Created

### 1. Controller
**Location:** `app/Http/Controllers/Api/Repairer/ConversationController.php`

Handles all repairer-specific conversation operations:
- List conversations assigned to repairer department
- View conversation details with transfer notes
- Send messages to customers
- Transfer conversations back to CRM
- Update conversation status (resolve, close)
- Update conversation priority

### 2. Migration
**Location:** `database/migrations/2026_02_12_120000_add_repairer_role_to_users.php`

Adds `REPAIRER` role to the users table enum field.

### 3. Routes
**Location:** `routes/api.php`

Added repairer routes under `/api/repairer/conversations`:
- `GET /api/repairer/conversations` - List all conversations assigned to repair department
- `GET /api/repairer/conversations/{id}` - Get specific conversation with messages
- `POST /api/repairer/conversations/{id}/messages` - Send message
- `POST /api/repairer/conversations/{id}/transfer` - Transfer back to CRM
- `PATCH /api/repairer/conversations/{id}/status` - Update status
- `PATCH /api/repairer/conversations/{id}/priority` - Update priority

## Security & Permissions

### Role-Based Access Control
Routes are protected by middleware:
```php
middleware(['web', 'auth:user', 'role:REPAIRER,MANAGER,SUPER_ADMIN'])
```

**Allowed Roles:**
- `REPAIRER` - Repair technicians
- `MANAGER` - Shop managers (can access both CRM and Repairer)
- `SUPER_ADMIN` - System administrators

### Shop Isolation
All queries are scoped to the authenticated user's shop:
```php
$shopOwnerId = $user->shop_owner_id ?? $user->id;
Conversation::where('shop_owner_id', $shopOwnerId)
```

### Department Filtering
Repairers only see conversations assigned to their department:
```php
->where('assigned_to_type', 'repairer')
```

## Key Features

### 1. Department-Specific Filtering
- Only shows conversations assigned to `assigned_to_type = 'repairer'`
- Filters out CRM conversations automatically

### 2. Transfer Note Visibility
- Displays transfer notes from CRM staff
- Shows who transferred the conversation
- Includes transfer timestamp

### 3. Transfer Back to CRM
- Repairers can return conversations to CRM
- Requires transfer note explaining resolution or reason
- Creates transfer record for audit trail

### 4. Status Management
- Mark conversations as resolved
- Update priority levels
- Change conversation status (open, in_progress, resolved, closed)

### 5. Message History
- Full conversation history visible
- Maintains context from CRM interactions
- Support for text messages and image attachments

## Database Schema

### Required Fields (Already exist in migrations)

**conversations table:**
- `assigned_to_type` - enum('crm', 'repairer')
- `assigned_to_id` - ID of assigned staff member
- `status` - enum('open', 'in_progress', 'resolved', 'closed')
- `priority` - enum('low', 'medium', 'high')
- `last_message_at` - timestamp

**conversation_transfers table:**
- `conversation_id`
- `from_user_id`
- `from_department` - enum('crm', 'repairer')
- `to_user_id`
- `to_department` - enum('crm', 'repairer')
- `transfer_note`
- `created_at`

**users table:**
- `role` - Now includes 'REPAIRER'

## API Response Examples

### Get Conversations
```json
{
  "data": [
    {
      "id": 1,
      "customer": {
        "id": 5,
        "name": "Miguel Bato",
        "email": "ultimo123@gmail.com"
      },
      "status": "in_progress",
      "priority": "high",
      "last_message_at": "2026-02-12T10:30:00Z",
      "transfer_note": "Customer asking about motherboard replacement timeline",
      "transferred_from_name": "John Doe (CRM)",
      "transferred_at": "2026-02-12T09:15:00Z"
    }
  ]
}
```

### Get Conversation with Messages
```json
{
  "id": 1,
  "customer": {
    "id": 5,
    "name": "Miguel Bato",
    "email": "ultimo123@gmail.com"
  },
  "messages": [
    {
      "id": 1,
      "sender_type": "customer",
      "content": "hello",
      "created_at": "2026-02-12T08:44:00Z"
    },
    {
      "id": 2,
      "sender_type": "staff",
      "content": "How can I help you?",
      "created_at": "2026-02-12T08:50:00Z"
    }
  ],
  "transfer_note": "Customer asking about motherboard replacement timeline",
  "transferred_from": "John Doe"
}
```

## Testing the Implementation

### 1. Run Migration
```bash
php artisan migrate
```

### 2. Create Test Repairer User
```sql
UPDATE users SET role = 'REPAIRER' WHERE id = [user_id];
```

### 3. Transfer a Conversation from CRM
Use the CRM interface to transfer a conversation to the repairer department.

### 4. Access Repairer Interface
Navigate to the repairer support page (needs to be added to routing).

### 5. Test API Endpoints
```bash
# Get conversations
GET /api/repairer/conversations

# View specific conversation
GET /api/repairer/conversations/1

# Send message
POST /api/repairer/conversations/1/messages
{
  "content": "I've diagnosed the issue..."
}

# Mark as resolved
PATCH /api/repairer/conversations/1/status
{
  "status": "resolved"
}

# Transfer back to CRM
POST /api/repairer/conversations/1/transfer
{
  "to_department": "crm",
  "transfer_note": "Issue resolved - replaced motherboard"
}
```

## Frontend Integration

The repairer interface is already created at:
`resources/js/Pages/ERP/CRM/repairerSupport.tsx`

Add to your routing configuration to make it accessible.

## Next Steps

1. ✅ Backend routes and controller created
2. ✅ Permission system implemented
3. ✅ Department filtering configured
4. ⏳ Run migration to add REPAIRER role
5. ⏳ Add frontend route for repairer interface
6. ⏳ Test transfer workflow end-to-end
7. ⏳ Add notifications for new transfers
8. ⏳ Create repairer dashboard with metrics

## Notes

- All operations are logged for audit purposes
- Shop isolation prevents cross-shop data access
- Transfer history is preserved for accountability
- Image attachments are supported in messages
- Real-time updates can be added using WebSockets/Pusher
