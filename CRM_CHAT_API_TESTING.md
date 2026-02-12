# CRM Chat System - API Testing Guide

## 🧪 Quick Test Commands

### Prerequisites
- XAMPP running with Apache and MySQL
- Laravel app accessible at `http://localhost/solespace-master`
- User authenticated (get auth token or use session cookies)

---

## 📬 Customer API Endpoints

### 1. Get Contacted Shops List
```bash
GET /api/customer/conversations/shops

curl -X GET "http://localhost/solespace-master/api/customer/conversations/shops" \
  -H "Accept: application/json" \
  -H "Cookie: XSRF-TOKEN=your-token; laravel_session=your-session"
```

**Expected Response:**
```json
[
  {
    "id": 1,
    "conversation_id": 5,
    "name": "Downtown Shoe Repair",
    "location": "123 Main St, Downtown",
    "online": false,
    "lastMessage": "Hello! How can we help?",
    "lastMessageTime": "2026-02-12T10:30:00.000000Z",
    "unreadCount": 2
  }
]
```

---

### 2. Get or Create Conversation with Shop
```bash
POST /api/customer/conversations/get-or-create

curl -X POST "http://localhost/solespace-master/api/customer/conversations/get-or-create" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Cookie: XSRF-TOKEN=your-token; laravel_session=your-session" \
  -d '{"shop_owner_id": 1}'
```

**Expected Response:**
```json
{
  "id": 5,
  "shop_owner_id": 1,
  "customer_id": 10,
  "status": "open",
  "priority": "medium",
  "last_message_at": "2026-02-12T10:30:00.000000Z",
  "shopOwner": {
    "id": 1,
    "business_name": "Downtown Shoe Repair",
    "business_address": "123 Main St"
  },
  "messages": []
}
```

---

### 3. Send Text Message
```bash
POST /api/customer/conversations/{conversation}/messages

curl -X POST "http://localhost/solespace-master/api/customer/conversations/5/messages" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Cookie: XSRF-TOKEN=your-token; laravel_session=your-session" \
  -d '{"content": "Hello! I need help with my shoe repair order."}'
```

---

### 4. Send Message with Images
```bash
POST /api/customer/conversations/{conversation}/messages

curl -X POST "http://localhost/solespace-master/api/customer/conversations/5/messages" \
  -H "Accept: application/json" \
  -H "Cookie: XSRF-TOKEN=your-token; laravel_session=your-session" \
  -F "content=Here are photos of the damage" \
  -F "images[]=@/path/to/image1.jpg" \
  -F "images[]=@/path/to/image2.jpg"
```

**Expected Response:**
```json
{
  "message": "Message sent successfully",
  "data": {
    "id": 15,
    "conversation_id": 5,
    "sender_type": "customer",
    "sender_id": 10,
    "content": "Here are photos of the damage",
    "attachments": [
      "/storage/conversation-images/xyz123.jpg",
      "/storage/conversation-images/abc456.jpg"
    ],
    "read_at": null,
    "created_at": "2026-02-12T10:35:00.000000Z",
    "sender": {
      "id": 10,
      "name": "John Doe"
    }
  }
}
```

---

### 5. Get Messages in Conversation
```bash
GET /api/customer/conversations/{conversation}/messages

curl -X GET "http://localhost/solespace-master/api/customer/conversations/5/messages" \
  -H "Accept: application/json" \
  -H "Cookie: XSRF-TOKEN=your-token; laravel_session=your-session"
```

---

## 🏢 CRM Staff API Endpoints

### 1. Get All Shop Conversations
```bash
GET /api/crm/conversations?status=open&priority=high

curl -X GET "http://localhost/solespace-master/api/crm/conversations?status=open" \
  -H "Accept: application/json" \
  -H "Cookie: XSRF-TOKEN=your-token; laravel_session=your-session"
```

**Query Parameters:**
- `status` - Filter by status: `open`, `in_progress`, `resolved`, `closed`, `all`
- `priority` - Filter by priority: `low`, `medium`, `high`
- `assigned_to_id` - Filter by assigned staff member ID

**Expected Response:**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 5,
      "shop_owner_id": 1,
      "customer_id": 10,
      "status": "open",
      "priority": "medium",
      "assigned_to_id": null,
      "assigned_to_type": null,
      "last_message_at": "2026-02-12T10:35:00.000000Z",
      "customer": {
        "id": 10,
        "name": "John Doe",
        "email": "john@example.com"
      },
      "messages": [
        {
          "id": 15,
          "content": "Hello! I need help with my repair.",
          "sender_type": "customer",
          "created_at": "2026-02-12T10:35:00.000000Z"
        }
      ]
    }
  ],
  "per_page": 20,
  "total": 1
}
```

---

### 2. Get Specific Conversation with Full History
```bash
GET /api/crm/conversations/{conversation}

curl -X GET "http://localhost/solespace-master/api/crm/conversations/5" \
  -H "Accept: application/json" \
  -H "Cookie: XSRF-TOKEN=your-token; laravel_session=your-session"
```

---

### 3. Send Reply to Customer
```bash
POST /api/crm/conversations/{conversation}/messages

curl -X POST "http://localhost/solespace-master/api/crm/conversations/5/messages" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Cookie: XSRF-TOKEN=your-token; laravel_session=your-session" \
  -d '{"content": "Hi John! We can help with that. Can you bring it in tomorrow?"}'
```

---

### 4. Transfer Conversation to Repairer
```bash
POST /api/crm/conversations/{conversation}/transfer

curl -X POST "http://localhost/solespace-master/api/crm/conversations/5/transfer" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Cookie: XSRF-TOKEN=your-token; laravel_session=your-session" \
  -d '{
    "to_user_id": 7,
    "to_department": "repairer",
    "transfer_note": "Technical question about sole replacement. Customer sent photos."
  }'
```

**Expected Response:**
```json
{
  "message": "Conversation transferred successfully",
  "conversation": {
    "id": 5,
    "assigned_to_id": 7,
    "assigned_to_type": "repairer",
    "assignedTo": {
      "id": 7,
      "name": "Mike Wilson",
      "role": "Repairer"
    }
  }
}
```

---

### 5. Update Conversation Status
```bash
PATCH /api/crm/conversations/{conversation}/status

curl -X PATCH "http://localhost/solespace-master/api/crm/conversations/5/status" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Cookie: XSRF-TOKEN=your-token; laravel_session=your-session" \
  -d '{"status": "resolved"}'
```

**Valid Status Values:**
- `open` - New conversation
- `in_progress` - Being handled
- `resolved` - Issue resolved
- `closed` - Conversation closed

---

### 6. Update Conversation Priority
```bash
PATCH /api/crm/conversations/{conversation}/priority

curl -X PATCH "http://localhost/solespace-master/api/crm/conversations/5/priority" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Cookie: XSRF-TOKEN=your-token; laravel_session=your-session" \
  -d '{"priority": "high"}'
```

**Valid Priority Values:**
- `low`
- `medium`
- `high`

---

## 🧑‍💻 Testing with Postman

### 1. Import Collection
Create a new Postman collection with these endpoints:

**Base URL:** `http://localhost/solespace-master`

### 2. Authentication Setup
Add to Collection Pre-request Script:
```javascript
// Get CSRF token from cookies
const xsrfToken = pm.cookies.get('XSRF-TOKEN');
pm.environment.set('xsrf_token', xsrfToken);

// Get Laravel session
const sessionToken = pm.cookies.get('laravel_session');
pm.environment.set('session_token', sessionToken);
```

### 3. Add Headers to Collection
```
Accept: application/json
X-XSRF-TOKEN: {{xsrf_token}}
Cookie: XSRF-TOKEN={{xsrf_token}}; laravel_session={{session_token}}
```

---

## 🐛 Common Issues & Solutions

### 1. 401 Unauthorized
**Issue:** Not authenticated
**Solution:** Login first via `/api/login` or use web authentication

### 2. 403 Forbidden
**Issue:** User doesn't have access to this shop's conversation
**Solution:** Verify user's `shop_owner_id` matches conversation's `shop_owner_id`

### 3. 422 Validation Error
**Issue:** Missing required fields or invalid data
**Solution:** Check request body matches validation rules

### 4. 404 Not Found
**Issue:** Conversation doesn't exist
**Solution:** Verify conversation ID is correct

### 5. Images Not Uploading
**Issue:** File size too large or wrong format
**Solution:** 
- Max 5 images per message
- Max 5MB per image
- Supported formats: JPEG, PNG, JPG, GIF

---

## 📊 Database Queries for Testing

### Create Test Customer User
```sql
INSERT INTO users (name, email, password, role, created_at, updated_at)
VALUES ('John Doe', 'john@test.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, NOW(), NOW());
```

### Create Test CRM Staff User
```sql
INSERT INTO users (name, email, password, role, shop_owner_id, created_at, updated_at)
VALUES ('CRM Staff', 'crm@test.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'CRM', 1, NOW(), NOW());
```

### Check Conversations
```sql
SELECT 
    c.id,
    c.status,
    c.priority,
    so.business_name as shop,
    u.name as customer,
    COUNT(cm.id) as message_count
FROM conversations c
JOIN shop_owners so ON c.shop_owner_id = so.id
JOIN users u ON c.customer_id = u.id
LEFT JOIN conversation_messages cm ON c.id = cm.conversation_id
GROUP BY c.id
ORDER BY c.last_message_at DESC;
```

### Check Messages
```sql
SELECT 
    cm.id,
    c.id as conversation_id,
    cm.sender_type,
    u.name as sender,
    cm.content,
    cm.created_at
FROM conversation_messages cm
JOIN conversations c ON cm.conversation_id = c.id
JOIN users u ON cm.sender_id = u.id
WHERE c.id = 1
ORDER BY cm.created_at ASC;
```

### Check Transfers
```sql
SELECT 
    ct.id,
    c.id as conversation_id,
    from_user.name as from_user,
    ct.from_department,
    to_user.name as to_user,
    ct.to_department,
    ct.transfer_note,
    ct.created_at
FROM conversation_transfers ct
JOIN conversations c ON ct.conversation_id = c.id
JOIN users from_user ON ct.from_user_id = from_user.id
LEFT JOIN users to_user ON ct.to_user_id = to_user.id
ORDER BY ct.created_at DESC;
```

---

## ✅ Test Checklist

### Customer Flow
- [ ] Customer can get list of contacted shops
- [ ] Customer can create new conversation with shop
- [ ] Customer can send text message
- [ ] Customer can send images (up to 5)
- [ ] Customer can view message history
- [ ] Customer sees "Support Team" for all shop replies

### CRM Flow
- [ ] CRM can view all shop's conversations
- [ ] CRM can filter by status and priority
- [ ] CRM can view full conversation history
- [ ] CRM can send reply to customer
- [ ] CRM can upload images in reply
- [ ] CRM can transfer to repairer with note
- [ ] CRM can update conversation status
- [ ] CRM can update conversation priority

### Security
- [ ] Customer cannot view other customers' conversations
- [ ] CRM cannot view other shops' conversations
- [ ] Unauthorized requests return 401
- [ ] Cross-shop access attempts return 403

### Data Integrity
- [ ] Conversations have correct shop_owner_id
- [ ] Messages have correct sender_type
- [ ] Transfers are logged correctly
- [ ] last_message_at updates on new message
- [ ] Read receipts work correctly

---

## 📝 Next Steps

1. **Test all endpoints** using Postman or curl
2. **Verify shop scoping** - make sure CRM only sees their shop's data
3. **Test image uploads** - verify storage and retrieval
4. **Check database** - verify all foreign keys and relationships
5. **Update frontend** - integrate React components with these APIs
6. **Add real-time** - implement broadcasting for live updates (optional)

---

## 🎯 Frontend Integration Example

```typescript
// Example: Get contacted shops
async function getContactedShops() {
  const response = await fetch('/api/customer/conversations/shops', {
    headers: {
      'Accept': 'application/json',
      'X-XSRF-TOKEN': getCsrfToken()
    },
    credentials: 'include'
  });
  
  const shops = await response.json();
  return shops;
}

// Example: Send message with images
async function sendMessage(conversationId: number, content: string, images: File[]) {
  const formData = new FormData();
  formData.append('content', content);
  images.forEach(image => formData.append('images[]', image));
  
  const response = await fetch(`/api/customer/conversations/${conversationId}/messages`, {
    method: 'POST',
    headers: {
      'Accept': 'application/json',
      'X-XSRF-TOKEN': getCsrfToken()
    },
    credentials: 'include',
    body: formData
  });
  
  return await response.json();
}
```

---

**Backend is ready! Start testing and integrate with frontend.** 🚀
