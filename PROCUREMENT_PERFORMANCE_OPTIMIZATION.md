# Procurement Module Performance Optimization Guide

## Overview
This document outlines performance optimizations implemented for the procurement module to ensure fast response times and efficient resource usage.

## Database Query Optimizations

### 1. Eager Loading Relationships
**Issue:** N+1 query problem when loading related data
**Solution:** Use eager loading in controllers and services

```php
// Bad - N+1 queries
$purchaseRequests = PurchaseRequest::all();
foreach ($purchaseRequests as $pr) {
    echo $pr->supplier->name; // Each iteration queries database
}

// Good - Eager loading
$purchaseRequests = PurchaseRequest::with(['supplier', 'requester', 'approver'])->get();
```

**Implementation in Controllers:**
```php
// PurchaseRequestController@index
public function index(Request $request)
{
    $query = PurchaseRequest::query()
        ->with(['supplier', 'inventoryItem', 'requester', 'reviewer', 'approver'])
        ->where('shop_owner_id', auth()->user()->shop_owner_id);
    
    // Apply filters...
    
    return $query->paginate(15);
}
```

### 2. Database Indexes
**Already Implemented in Migrations:**

**purchase_requests table:**
- `idx_shop_owner` on shop_owner_id
- `idx_supplier` on supplier_id
- `idx_status` on status
- `idx_priority` on priority
- `idx_dates` on (requested_date, approved_date)

**purchase_orders table:**
- `idx_shop_owner` on shop_owner_id
- `idx_supplier` on supplier_id
- `idx_status` on status
- `idx_pr_id` on pr_id
- `idx_delivery_dates` on (expected_delivery_date, actual_delivery_date)

**replenishment_requests table:**
- `idx_shop_owner` on shop_owner_id
- `idx_status` on status
- `idx_inventory_item` on inventory_item_id
- `idx_requested_date` on requested_date

**stock_request_approvals table:**
- `idx_shop_owner` on shop_owner_id
- `idx_status` on status
- `idx_inventory_item` on inventory_item_id
- `idx_requested_date` on requested_date

### 3. Query Optimization Tips

**Use select() to limit columns:**
```php
// Instead of selecting all columns
PurchaseRequest::all();

// Select only needed columns
PurchaseRequest::select('id', 'pr_number', 'product_name', 'status', 'total_cost')->get();
```

**Use chunk() for large datasets:**
```php
// Process large datasets in chunks to avoid memory issues
PurchaseOrder::where('status', 'completed')
    ->chunk(100, function ($orders) {
        foreach ($orders as $order) {
            // Process order
        }
    });
```

## Caching Strategy

### 1. Cache Metrics Data
**Implementation:**
```php
// In PurchaseRequestService
public function getMetrics($shopOwnerId)
{
    return Cache::remember("pr_metrics_{$shopOwnerId}", 300, function () use ($shopOwnerId) {
        return [
            'total_requests' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)->count(),
            'pending_finance' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)
                ->where('status', 'pending_finance')->count(),
            'approved' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)
                ->where('status', 'approved')->count(),
            // ... other metrics
        ];
    });
}
```

### 2. Cache Supplier List
```php
// Cache active suppliers for 1 hour
$suppliers = Cache::remember('active_suppliers', 3600, function () {
    return Supplier::where('is_active', true)
        ->select('id', 'name', 'email', 'phone')
        ->get();
});
```

### 3. Cache Invalidation
**Clear cache when data changes:**
```php
// In PurchaseRequestService after creating/updating
public function createPurchaseRequest($data)
{
    $pr = PurchaseRequest::create($data);
    
    // Clear metrics cache
    Cache::forget("pr_metrics_{$data['shop_owner_id']}");
    
    return $pr;
}
```

## Queue Job Optimization

### 1. Use Queues for Heavy Operations
**Already implemented:**
- `SendPOToSupplierJob` - Email sending
- `GenerateProcurementReportJob` - Report generation
- `UpdateSupplierMetricsJob` - Batch metrics updates

### 2. Job Configuration
```php
// In .env
QUEUE_CONNECTION=database  # or redis for better performance

# In config/queue.php - set appropriate timeouts
'timeout' => 60,
'retry_after' => 90,
```

## API Response Optimization

### 1. Pagination
**Always paginate list endpoints:**
```php
// Default pagination of 15 items
return PurchaseRequest::paginate(15);

// Allow custom per_page with maximum limit
$perPage = min($request->input('per_page', 15), 100);
return PurchaseRequest::paginate($perPage);
```

### 2. API Resource Transformers
**Use API Resources to control response structure:**
```php
// app/Http/Resources/PurchaseRequestResource.php
class PurchaseRequestResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'pr_number' => $this->pr_number,
            'product_name' => $this->product_name,
            'total_cost' => $this->total_cost,
            'status' => $this->status,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            // Only include when needed
        ];
    }
}
```

## Frontend Optimization

### 1. API Call Debouncing
**In TypeScript API service:**
```typescript
// Debounce search requests
import { debounce } from 'lodash';

const debouncedSearch = debounce(async (searchTerm: string) => {
    const response = await purchaseRequestApi.getAll({ search: searchTerm });
    // Update UI
}, 300);
```

### 2. Use Pagination
```typescript
// Load data in pages instead of all at once
const fetchPurchaseRequests = async (page: number = 1) => {
    const response = await purchaseRequestApi.getAll({
        page,
        per_page: 15
    });
    return response.data;
};
```

## Database Connection Pooling

### Configuration in config/database.php
```php
'mysql' => [
    'driver' => 'mysql',
    // ...
    'options' => [
        PDO::ATTR_PERSISTENT => true,  // Use persistent connections
    ],
    'pool' => [
        'min' => 2,
        'max' => 10,
    ],
],
```

## Monitoring & Profiling

### 1. Enable Query Logging (Development Only)
```php
// In AppServiceProvider
if (app()->environment('local')) {
    DB::listen(function ($query) {
        if ($query->time > 100) { // Log slow queries (>100ms)
            Log::warning('Slow query detected', [
                'sql' => $query->sql,
                'time' => $query->time,
                'bindings' => $query->bindings,
            ]);
        }
    });
}
```

### 2. Use Laravel Telescope (Development)
```bash
composer require laravel/telescope --dev
php artisan telescope:install
php artisan migrate
```

## Performance Benchmarks

### Target Response Times
- List endpoints (paginated): < 200ms
- Single record fetch: < 50ms
- Create operations: < 150ms
- Update operations: < 100ms
- Complex metrics: < 500ms

### Database Query Limits
- Maximum queries per request: 10
- Maximum query time: 100ms
- Use explain to analyze slow queries

## Additional Optimizations

### 1. Use Database Transactions for Multi-Step Operations
```php
DB::beginTransaction();
try {
    $pr = PurchaseRequest::create($data);
    $po = PurchaseOrder::create($poData);
    
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    throw $e;
}
```

### 2. Optimize Counts
```php
// Bad - loads all records
$count = PurchaseRequest::all()->count();

// Good - count in database
$count = PurchaseRequest::count();
```

### 3. Use exists() instead of count() for checks
```php
// Bad
if (PurchaseRequest::where('status', 'pending')->count() > 0)

// Good
if (PurchaseRequest::where('status', 'pending')->exists())
```

## Recommended Additional Indexes

### If performance issues arise, consider:
```sql
-- Composite index for common queries
ALTER TABLE purchase_requests 
ADD INDEX idx_shop_status_priority (shop_owner_id, status, priority);

-- Index for date range queries
ALTER TABLE purchase_orders 
ADD INDEX idx_shop_dates (shop_owner_id, expected_delivery_date);

-- Full-text search index
ALTER TABLE purchase_requests 
ADD FULLTEXT INDEX ft_product_search (product_name, justification);
```

## Redis Caching (Production)

### Install Redis
```bash
composer require predis/predis
```

### Configure in .env
```
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

### Use Redis for:
- Session storage
- Cache storage
- Queue jobs
- Real-time metrics

## Summary

**Key Performance Strategies:**
1. ✅ Database indexes on all foreign keys and frequently queried columns
2. ✅ Eager loading to prevent N+1 queries
3. ✅ Pagination for all list endpoints
4. ✅ Queue jobs for heavy operations
5. ✅ Cache frequently accessed data (metrics, supplier lists)
6. ✅ Use database transactions for data integrity
7. ✅ Monitor slow queries in development
8. ✅ API response optimization with resources
9. ✅ Frontend debouncing for search
10. ✅ Chunk processing for large datasets

**Expected Performance:**
- API response time: < 200ms for most endpoints
- Database queries: < 10 per request
- Page load time: < 1 second
- Support for 1000+ concurrent users with proper server configuration
