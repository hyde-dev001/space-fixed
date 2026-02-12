# 🚀 CRM Chat System - Quick Start Guide

## ✅ What We Built (5 Phases Complete)

### Phase 1: Database ✅
- ✅ `conversations` table - Shop-scoped customer conversations
- ✅ `conversation_messages` table - Messages with sender tracking
- ✅ `conversation_transfers` table - CRM → Repairer handoff logs

### Phase 2: Models ✅
- ✅ `Conversation` - With shop/customer/staff relationships
- ✅ `ConversationMessage` - With sender relationship and read tracking
- ✅ `ConversationTransfer` - Transfer audit trail

### Phase 3: Controllers ✅
- ✅ `API\Customer\ConversationController` - Customer-side chat
- ✅ `API\CRM\ConversationController` - CRM staff interface

### Phase 4: Policies ✅
- ✅ `ConversationPolicy` - Shop scoping and access control

### Phase 5: Routes ✅
- ✅ Customer routes: `/api/customer/conversations/*`
- ✅ CRM routes: `/api/crm/conversations/*`

---

## 📋 File Locations

```
Database Migrations:
├── database/migrations/2026_02_12_105332_create_conversations_table.php
├── database/migrations/2026_02_12_105450_create_conversation_messages_table.php
└── database/migrations/2026_02_12_105507_create_conversation_transfers_table.php

Models:
├── app/Models/Conversation.php
├── app/Models/ConversationMessage.php
└── app/Models/ConversationTransfer.php

Controllers:
├── app/Http/Controllers/API/Customer/ConversationController.php
└── app/Http/Controllers/API/CRM/ConversationController.php

Policies:
└── app/Policies/ConversationPolicy.php

Routes:
└── routes/api.php (lines 223-249)

Documentation:
├── CRM_CHAT_BACKEND_SUMMARY.md (Full implementation details)
└── CRM_CHAT_API_TESTING.md (API testing guide)
```

---

## 🎯 Key Features

### Multi-Shop Support ✅
- Each shop has independent conversations
- Staff only see their shop's conversations
- Customer can contact multiple shops

### CRM → Repairer Transfer ✅
- Transfer conversations between departments
- Add context notes
- Full audit trail

### Message System ✅
- Text messages
- Image attachments (up to 5 per message)
- Read receipts
- Conversation history

### Customer Experience ✅
- Contact different shops independently
- See "Support Team" (seamless CRM/Repairer handoff)
- View all past conversations

---

## 🔌 API Endpoints Quick Reference

### Customer APIs
```
GET    /api/customer/conversations/shops           # List contacted shops
POST   /api/customer/conversations/get-or-create   # Start conversation
GET    /api/customer/conversations/{id}/messages   # View messages
POST   /api/customer/conversations/{id}/messages   # Send message
```

### CRM APIs
```
GET    /api/crm/conversations                      # List shop's conversations
GET    /api/crm/conversations/{id}                 # View conversation
POST   /api/crm/conversations/{id}/messages        # Reply to customer
POST   /api/crm/conversations/{id}/transfer        # Transfer to repairer
PATCH  /api/crm/conversations/{id}/status          # Update status
PATCH  /api/crm/conversations/{id}/priority        # Update priority
```

---

## 🧪 Quick Test (Using Browser Console)

### 1. Customer Creates Conversation
```javascript
fetch('/api/customer/conversations/get-or-create', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ shop_owner_id: 1 })
}).then(r => r.json()).then(console.log);
```

### 2. Customer Sends Message
```javascript
fetch('/api/customer/conversations/1/messages', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ content: 'Hello! I need help.' })
}).then(r => r.json()).then(console.log);
```

### 3. CRM Views Conversations
```javascript
fetch('/api/crm/conversations')
  .then(r => r.json())
  .then(console.log);
```

### 4. CRM Replies
```javascript
fetch('/api/crm/conversations/1/messages', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ content: 'Hi! How can we help?' })
}).then(r => r.json()).then(console.log);
```

### 5. CRM Transfers to Repairer
```javascript
fetch('/api/crm/conversations/1/transfer', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    to_user_id: 5,
    to_department: 'repairer',
    transfer_note: 'Technical question'
  })
}).then(r => r.json()).then(console.log);
```

---

## 📊 Database Quick Check

```sql
-- See all conversations
SELECT * FROM conversations ORDER BY last_message_at DESC;

-- See messages in conversation 1
SELECT * FROM conversation_messages WHERE conversation_id = 1 ORDER BY created_at;

-- See transfer history
SELECT * FROM conversation_transfers ORDER BY created_at DESC;
```

---

## 🔒 Security Features

✅ Shop scoping enforced at query level  
✅ Policy-based authorization  
✅ Customer can only view own conversations  
✅ CRM can only view shop's conversations  
✅ All routes require authentication  

---

## 📱 Frontend Integration (Next Step)

### Update Customer UI (message.tsx)
Replace mock data with:
```typescript
// Get shops list
const shops = await fetch('/api/customer/conversations/shops').then(r => r.json());

// Get or create conversation
const conversation = await fetch('/api/customer/conversations/get-or-create', {
  method: 'POST',
  body: JSON.stringify({ shop_owner_id: selectedShop.id })
}).then(r => r.json());

// Send message
const result = await fetch(`/api/customer/conversations/${conversation.id}/messages`, {
  method: 'POST',
  body: JSON.stringify({ content: message })
}).then(r => r.json());
```

### Update CRM UI (customerSupport.tsx)
Replace mock data with:
```typescript
// Get conversations
const conversations = await fetch('/api/crm/conversations?status=open').then(r => r.json());

// Send reply
const result = await fetch(`/api/crm/conversations/${conversation.id}/messages`, {
  method: 'POST',
  body: formData // FormData with content and images
}).then(r => r.json());

// Transfer conversation
await fetch(`/api/crm/conversations/${conversation.id}/transfer`, {
  method: 'POST',
  body: JSON.stringify({
    to_user_id: repairerId,
    to_department: 'repairer',
    transfer_note: note
  })
});
```

---

## 🎉 System is Ready!

**Backend:** ✅ Fully implemented and tested  
**Database:** ✅ All tables created  
**API Routes:** ✅ All endpoints working  
**Security:** ✅ Shop scoping enforced  
**Documentation:** ✅ Complete

**Next Step:** Integrate frontend React components with these APIs

---

## 📚 Full Documentation

- **CRM_CHAT_BACKEND_SUMMARY.md** - Complete implementation details
- **CRM_CHAT_API_TESTING.md** - Full API testing guide with curl examples

---

## 🆘 Need Help?

**Common Issues:**
- 401 Unauthorized → Login first
- 403 Forbidden → User doesn't belong to this shop
- 422 Validation → Check request body format
- 404 Not Found → Conversation ID doesn't exist

**Debugging:**
```php
// Check user's shop
SELECT id, name, shop_owner_id FROM users WHERE id = 1;

// Check conversations for shop
SELECT * FROM conversations WHERE shop_owner_id = 1;

// Check messages
SELECT * FROM conversation_messages WHERE conversation_id = 1;
```

---

**🚀 Backend Complete! Ready for frontend integration.**
