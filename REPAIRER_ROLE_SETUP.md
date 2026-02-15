# Repairer Role & CRM Permissions Setup

## Overview
This document outlines the implementation of the **Repairer role** and enhanced **CRM conversation permissions** using Spatie Laravel Permission package.

## What Was Done

### 1. Added New Role: Repairer
A new `Repairer` role has been created for technical support staff who handle repair-related customer inquiries transferred from CRM.

**Role Permissions (13 total):**
- **Technical Support Conversations:**
  - `view-repairer-conversations` - View all conversations assigned to repair department
  - `send-repairer-messages` - Send messages to customers
  - `transfer-repairer-conversations` - Transfer conversations back to CRM
  - `update-repairer-conversation-status` - Update conversation status (open, in_progress, resolved)

- **Repair Services:**
  - `view-repair-services` - View repair service catalog
  - `manage-repair-services` - Manage repair services

- **Pricing:**
  - `view-repair-pricing` - View repair service pricing
  - `view-shoe-pricing` - View shoe repair pricing

- **Job Orders:**
  - `view-job-orders` - View job orders
  - `create-job-orders` - Create new job orders
  - `edit-job-orders` - Edit existing job orders
  - `complete-job-orders` - Mark job orders as complete

- **General:**
  - `view-dashboard` - Access to dashboard

### 2. Enhanced CRM Role with Conversation Permissions
The existing `CRM` role has been updated to include customer support conversation permissions.

**New CRM Permissions (4 added):**
- `view-crm-conversations` - View customer support conversations
- `send-crm-messages` - Send messages to customers
- `transfer-crm-conversations` - Transfer conversations to other departments (e.g., Repairer)
- `update-crm-conversation-status` - Update conversation status

**Total CRM Permissions:** 21 (previously 17)

### 3. Updated Routes with Permission Middleware

#### API Routes (routes/api.php)
Replaced `old_role` middleware with Spatie `permission` middleware:

**CRM Conversation Routes:**
```php
Route::prefix('crm/conversations')
    ->middleware(['web', 'auth:user', 'permission:view-crm-conversations'])
    ->group(function () {
        Route::get('/', [ConversationController::class, 'index']);
        Route::post('/{conversation}/messages', ...)->middleware('permission:send-crm-messages');
        Route::post('/{conversation}/transfer', ...)->middleware('permission:transfer-crm-conversations');
        Route::patch('/{conversation}/status', ...)->middleware('permission:update-crm-conversation-status');
    });
```

**Repairer Conversation Routes:**
```php
Route::prefix('repairer/conversations')
    ->middleware(['web', 'auth:user', 'permission:view-repairer-conversations'])
    ->group(function () {
        Route::get('/', [ConversationController::class, 'index']);
        Route::post('/{conversation}/messages', ...)->middleware('permission:send-repairer-messages');
        Route::post('/{conversation}/transfer', ...)->middleware('permission:transfer-repairer-conversations');
        Route::patch('/{conversation}/status', ...)->middleware('permission:update-repairer-conversation-status');
    });
```

#### Web Routes (routes/web.php)
Updated page access routes:

**CRM Customer Support Page:**
```php
Route::get('/customer-support', ...)
    ->middleware('permission:view-crm-conversations')
    ->name('customer-support');
```

**Repairer Support Page:**
```php
Route::get('/erp/staff/repairer-support', ...)
    ->middleware(['auth:user', 'permission:view-repairer-conversations'])
    ->name('erp.repairer.support');
```

### 4. Added Position Templates

#### New Customer Support Position
**Position:** Customer Support Representative  
**Category:** Sales  
**Recommended Role:** CRM  
**Permissions:**
- view-customers
- view-crm-conversations
- send-crm-messages
- transfer-crm-conversations
- update-crm-conversation-status
- view-dashboard

#### New Repairer Positions
**Position:** Repair Technician  
**Category:** Technical  
**Recommended Role:** Repairer  
**Permissions:**
- view-repairer-conversations
- send-repairer-messages
- transfer-repairer-conversations
- update-repairer-conversation-status
- view-job-orders
- create-job-orders
- edit-job-orders
- complete-job-orders
- view-repair-pricing
- view-dashboard

**Position:** Senior Repair Technician  
**Category:** Technical  
**Recommended Role:** Repairer  
**Permissions:** (All Repair Technician permissions plus)
- view-repair-services
- manage-repair-services
- view-shoe-pricing

### 5. Updated UserAccessControlController
Added Repairer to the role mapping when creating new users:

```php
$roleMap = [
    'Manager' => 'Manager',
    'Finance' => 'Finance',
    'HR' => 'HR',
    'CRM' => 'CRM',
    'Repairer' => 'Repairer',  // ← New
    'Staff' => 'Staff',
];
```

## How to Use

### 1. Run Seeders (Already Done)
```bash
php artisan db:seed --class=RolesAndPermissionsSeeder
php artisan db:seed --class=PositionTemplatesSeeder
```

### 2. Assign Repairer Role to Users
**Option A: Through User Management UI**
1. Navigate to ERP → Manager → User Management
2. Create new user or edit existing user
3. Select "Repairer" as the role
4. Optionally select "Repair Technician" or "Senior Repair Technician" position template

**Option B: Programmatically**
```php
use App\Models\User;

$user = User::find($userId);
$user->assignRole('Repairer');
```

### 3. Grant Individual Permissions (Optional)
HR or Shop Owner can grant additional permissions on top of the base role:

```php
$user->givePermissionTo('manage-repair-services');
```

## Access Control Matrix

| Role | Customer Support Page | Repairer Support Page | Can Transfer to Repairer | Can Transfer to CRM |
|------|----------------------|----------------------|--------------------------|---------------------|
| Manager | ✅ | ✅ | ✅ | ✅ |
| CRM | ✅ | ❌ | ✅ | N/A |
| Repairer | ❌ | ✅ | N/A | ✅ |
| Finance | ❌ | ❌ | ❌ | ❌ |
| HR | ❌ | ❌ | ❌ | ❌ |
| Staff | ❌* | ❌* | ❌ | ❌ |

*Unless granted specific permissions by HR/Manager

## Permission Hierarchy

```
Manager (84 permissions)
    ├── All system permissions
    └── Full access to all modules

CRM (21 permissions)
    ├── Customer Management
    ├── Leads & Opportunities
    ├── CRM Conversations (Customer Support)
    └── CRM Reports

Repairer (13 permissions)
    ├── Repairer Conversations (Technical Support)
    ├── Repair Services
    ├── Job Orders
    └── Pricing (View Only)

Finance (27 permissions)
    ├── Expenses & Invoices
    ├── Payroll
    ├── Pricing Approvals
    └── Finance Reports

HR (16 permissions)
    ├── Employee Management
    ├── Attendance & Leave
    ├── Payroll Processing
    └── HR Reports

Staff (3 permissions)
    ├── Basic dashboard access
    ├── View job orders
    └── Create job orders
```

## Frontend Integration

The frontend pages automatically check permissions:

**Customer Support Page** (`resources/js/Pages/ERP/CRM/customerSupport.tsx`):
- Protected by: `permission:view-crm-conversations`
- API calls require: `send-crm-messages`, `transfer-crm-conversations`, etc.

**Repairer Support Page** (`resources/js/Pages/ERP/repairer/repairerSupport.tsx`):
- Protected by: `permission:view-repairer-conversations`
- API calls require: `send-repairer-messages`, `transfer-repairer-conversations`, etc.

## Testing Checklist

- [ ] Create a test user with Repairer role
- [ ] Verify Repairer can access `/erp/staff/repairer-support`
- [ ] Verify Repairer can view conversations assigned to repair department
- [ ] Verify Repairer can send messages
- [ ] Verify Repairer can transfer conversations back to CRM
- [ ] Verify Repairer can mark conversations as resolved
- [ ] Verify CRM can access `/erp/crm/customer-support`
- [ ] Verify CRM can transfer conversations to Repairer
- [ ] Verify Finance/HR cannot access either support page
- [ ] Verify Manager can access both pages

## Database Changes Summary

**New Permissions Added:** 8
- view-crm-conversations
- send-crm-messages
- transfer-crm-conversations
- update-crm-conversation-status
- view-repairer-conversations
- send-repairer-messages
- transfer-repairer-conversations
- update-repairer-conversation-status

**New Role Added:** 1
- Repairer (with 13 permissions)

**New Position Templates Added:** 3
- Customer Support Representative (CRM)
- Repair Technician (Repairer)
- Senior Repair Technician (Repairer)

## Troubleshooting

### User Cannot Access Page
**Problem:** User gets 403 Forbidden or redirected  
**Solution:**
1. Check user has correct role: `php artisan tinker` → `User::find($id)->roles`
2. Check user has required permission: `User::find($id)->hasPermissionTo('view-crm-conversations')`
3. Clear permission cache: `php artisan permission:cache-reset`

### Routes Not Working
**Problem:** API endpoints return 403  
**Solution:**
1. Ensure user is authenticated: Check auth:user middleware
2. Verify permission exists: `php artisan permission:show`
3. Check route middleware order: auth must come before permission

### Position Template Not Applying
**Problem:** Permissions not granted when selecting position template  
**Solution:**
1. Verify template exists: `PositionTemplate::with('permissions')->get()`
2. Check UserAccessControlController applies permissions after role assignment
3. Ensure HR has permission to assign roles

## Related Files

### Seeders
- `database/seeders/RolesAndPermissionsSeeder.php` - Role and permission definitions
- `database/seeders/PositionTemplatesSeeder.php` - Position templates with permissions

### Routes
- `routes/api.php` - API endpoints for conversations (lines 229-250)
- `routes/web.php` - Page access routes (lines 673-677, 810)

### Controllers
- `app/Http/Controllers/ShopOwner/UserAccessControlController.php` - User role assignment
- `app/Http/Controllers/Api/CRM/ConversationController.php` - CRM conversation logic
- `app/Http/Controllers/Api/Repairer/ConversationController.php` - Repairer conversation logic

### Frontend
- `resources/js/Pages/ERP/CRM/customerSupport.tsx` - Customer support interface
- `resources/js/Pages/ERP/repairer/repairerSupport.tsx` - Repairer support interface

## Next Steps

1. **Clear Permission Cache** (if needed):
   ```bash
   php artisan permission:cache-reset
   ```

2. **Create Test Users**:
   ```bash
   php artisan tinker
   # Then create users with Repairer and CRM roles for testing
   ```

3. **Update User Management UI** (if needed):
   - Add "Repairer" option to role dropdown
   - Add "Customer Support Representative", "Repair Technician" to position templates dropdown

4. **Update Navigation Menu** (if needed):
   - Add links to Customer Support page for CRM users
   - Add links to Repairer Support page for Repairer users

5. **Documentation for End Users**:
   - Create user guide for CRM staff on using customer support
   - Create user guide for Repairer staff on handling technical support
   - Document transfer workflow between CRM and Repairer

## Conclusion

The Repairer role and enhanced CRM conversation permissions are now fully implemented using Spatie Laravel Permission package. The system provides fine-grained access control for customer support and technical support workflows, with proper permission checks at both route and API levels.

**Key Benefits:**
✅ Role-based access control (RBAC)  
✅ Fine-grained permissions for conversation management  
✅ Clear separation between CRM and Repairer responsibilities  
✅ Position templates for quick onboarding  
✅ Manager retains full access to all features  
✅ Audit trail support (through existing PermissionAuditLog)
