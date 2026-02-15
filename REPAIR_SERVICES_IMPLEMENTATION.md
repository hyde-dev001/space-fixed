# Repair Services Backend Implementation

## Overview
This implementation adds full backend functionality for managing repair services, allowing staff and managers to upload services for shoe repairs. The pricing and services page now fetches data from the backend database.

## What Was Implemented

### 1. Database Schema
**Table: `repair_services`**
- `id` - Primary key
- `name` - Service name (e.g., "Basic Cleaning")
- `category` - Service category (e.g., "Care", "Repair", "Restoration")
- `price` - Service price (decimal)
- `duration` - Service duration (e.g., "20 min", "1 hour")
- `description` - Service description (optional)
- `status` - Service status (Active, Inactive, Pending, Under Review, Rejected)
- `rejection_reason` - Reason for rejection (optional)
- `created_by` - Foreign key to employees table
- `updated_by` - Foreign key to employees table
- `created_at` - Timestamp
- `updated_at` - Timestamp
- `deleted_at` - Soft delete timestamp

### 2. Backend Components

#### Model: `App\Models\RepairService`
- Located: `app/Models/RepairService.php`
- Features:
  - Soft deletes enabled
  - Activity logging integration
  - Relationships with Employee model
  - Fillable attributes for mass assignment

#### Controller: `App\Http\Controllers\Api\RepairServiceController`
- Located: `app/Http/Controllers/Api/RepairServiceController.php`
- Endpoints:
  - `GET /api/repair-services` - List all services with filtering
  - `POST /api/repair-services` - Create new service
  - `GET /api/repair-services/{id}` - Get single service
  - `PUT /api/repair-services/{id}` - Update service
  - `DELETE /api/repair-services/{id}` - Delete service (soft delete)

#### Routes
- Located: `routes/web.php`
- Protected by `auth:user` middleware
- Permission-based access control:
  - View: No specific permission required
  - Create/Edit/Delete: Requires `create-products` or `edit-products` permission

### 3. Frontend Integration

#### Upload Service Page (`uploadService.tsx`)
- Path: `resources/js/Pages/ERP/repairer/uploadService.tsx`
- Features:
  - Fetches services from backend on component mount
  - Create new repair services with validation
  - Edit existing services with backend sync
  - Delete services with confirmation
  - Real-time search and filtering
  - Loading states and error handling

#### Pricing & Services Page (`PricingAndServices.tsx`)
- Path: `resources/js/Pages/ERP/repairer/PricingAndServices.tsx`
- Features:
  - Fetches services from backend on component mount
  - Price change requests with reason tracking
  - Add new services from pricing page
  - Status filtering (Active, Under Review, Rejected)
  - Real-time data synchronization

## API Usage Examples

### Fetch All Services
```javascript
const response = await axios.get('/api/repair-services');
const services = response.data.data;
```

### Create Service
```javascript
const response = await axios.post('/api/repair-services', {
  name: 'Basic Cleaning',
  category: 'Care',
  price: 180,
  duration: '20 min',
  description: 'Professional cleaning service',
  status: 'Active'
});
```

### Update Service
```javascript
const response = await axios.put(`/api/repair-services/${id}`, {
  price: 200,
  status: 'Under Review'
});
```

### Delete Service
```javascript
const response = await axios.delete(`/api/repair-services/${id}`);
```

### Filter Services
```javascript
const response = await axios.get('/api/repair-services', {
  params: {
    status: 'Active',
    category: 'Care',
    search: 'cleaning'
  }
});
```

## Database Migration

The migration was automatically run during implementation. If you need to run it manually:

```bash
php artisan migrate
```

To rollback:
```bash
php artisan migrate:rollback
```

## Permissions Required

Users need the following permissions to manage repair services:
- **View Services**: No specific permission (authenticated users)
- **Create Services**: `create-products` or `edit-products`
- **Edit Services**: `edit-products`
- **Delete Services**: `delete-products`

These permissions align with the existing product management permissions.

## Data Flow

1. **Upload Service Page**:
   - User creates/edits service → Frontend validates → API call → Controller validates → Saves to database → Returns response → Frontend refreshes list

2. **Pricing & Services Page**:
   - User views services → Component mounts → Fetches from API → Displays services
   - User edits price → Sets status to "Under Review" → Waits for approval

## Testing

To test the implementation:

1. Navigate to the Upload Services page (staff/manager role required)
2. Create a new repair service
3. Verify it appears in the list
4. Navigate to Pricing & Services page
5. Verify the service appears there as well
6. Edit the service price
7. Verify status changes to "Under Review"

## Notes

- All prices are stored as decimals in the database but displayed with peso sign (₱) in the frontend
- Services support soft deletes - deleted items remain in database with `deleted_at` timestamp
- Activity logging is enabled for audit trail
- Status flow: Active → Under Review → Approved/Rejected
- The system tracks who created and updated each service
