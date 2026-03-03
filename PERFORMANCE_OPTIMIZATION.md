# Performance Optimization Documentation
## Inventory Management System - SoleSpace ERP

### Database Optimizations

#### 1. Indexes (Already Implemented in Migrations)

All migrations include proper indexing for optimal query performance:

**Inventory Items Table:**
```php
- Primary key: id
- Unique index: sku
- Foreign key index: shop_owner_id
- Composite index: (shop_owner_id, category)
- Composite index: (shop_owner_id, available_quantity) // For low stock queries
```

**Stock Movements Table:**
```php
- Primary key: id
- Foreign key index: inventory_item_id
- Foreign key index: performed_by
- Composite index: (inventory_item_id, performed_at) // For history queries
- Index: movement_type // For filtering
```

**Suppliers Table:**
```php
- Primary key: id
- Foreign key index: shop_owner_id
- Index: email
```

**Supplier Orders Table:**
```php
- Primary key: id
- Unique index: order_number
- Foreign key index: supplier_id
- Foreign key index: created_by
- Composite index: (supplier_id, status)
- Index: expected_delivery_date // For overdue checks
```

**Inventory Alerts Table:**
```php
- Primary key: id
- Foreign key index: inventory_item_id
- Composite index: (inventory_item_id, status)
- Index: alert_type
```

#### 2. Eager Loading Strategies

**InventoryService:**
```php
// Dashboard metrics - eager load shop owner
public function getDashboardMetrics($shopOwnerId)
{
    return InventoryItem::with('shopOwner')
        ->where('shop_owner_id', $shopOwnerId)
        ->get();
}

// Stock movements - eager load relationships
public function getStockMovements($filters)
{
    return StockMovement::with(['inventoryItem', 'performedBy'])
        ->when($filters['inventory_item_id'] ?? null, ...)
        ->get();
}
```

**InventoryItemController:**
```php
// Index with relationships
public function index(Request $request)
{
    return InventoryItem::with('category', 'supplier')
        ->where('shop_owner_id', $request->user()->shop_owner_id)
        ->paginate(15);
}
```

**SupplierOrderController:**
```php
// Show with items
public function show($id)
{
    return SupplierOrder::with(['supplier', 'items.inventoryItem'])
        ->findOrFail($id);
}
```

#### 3. Query Optimization Techniques

**Pagination:**
```php
// All list endpoints use pagination (15 items per page)
InventoryItem::paginate(15);
StockMovement::paginate(20);
SupplierOrder::paginate(15);
```

**Select Specific Columns:**
```php
// Dashboard metrics - only needed columns
InventoryItem::select('id', 'name', 'available_quantity', 'cost_price')
    ->where('shop_owner_id', $shopOwnerId)
    ->get();
```

**Query Scopes:**
```php
// Reusable query scopes in models
public function scopeLowStock($query)
{
    return $query->whereColumn('available_quantity', '<=', 'reorder_level')
                 ->where('available_quantity', '>', 0);
}

public function scopeOutOfStock($query)
{
    return $query->where('available_quantity', 0);
}
```

**Database Transactions:**
```php
// Stock transfers use transactions
DB::transaction(function () use ($fromItem, $toItem, $quantity) {
    $fromItem->decrement('available_quantity', $quantity);
    $toItem->increment('available_quantity', $quantity);
    StockMovement::create([...]);
});
```

### Caching Strategies

#### 1. Dashboard Metrics Caching

```php
// Cache dashboard metrics for 5 minutes
public function getDashboardMetrics($shopOwnerId)
{
    return Cache::remember("dashboard_metrics_{$shopOwnerId}", 300, function () use ($shopOwnerId) {
        return $this->calculateMetrics($shopOwnerId);
    });
}

// Invalidate cache on inventory changes
protected function invalidateDashboardCache()
{
    Cache::forget("dashboard_metrics_{$this->shop_owner_id}");
}
```

#### 2. Stock Levels Chart Caching

```php
// Cache chart data for 10 minutes
public function getStockLevelsChart($shopOwnerId)
{
    return Cache::remember("stock_chart_{$shopOwnerId}", 600, function () use ($shopOwnerId) {
        return $this->generateChartData($shopOwnerId);
    });
}
```

#### 3. Low Stock Alerts Caching

```php
// Cache active alerts for 2 minutes
public function getLowStockItems($shopOwnerId)
{
    return Cache::remember("low_stock_{$shopOwnerId}", 120, function () use ($shopOwnerId) {
        return InventoryItem::lowStock()
            ->where('shop_owner_id', $shopOwnerId)
            ->get();
    });
}
```

### Queue & Job Optimizations

#### 1. Background Processing

**Stock Alerts (Daily at 9 AM):**
```php
// Scheduled command
protected function schedule(Schedule $schedule)
{
    $schedule->command('inventory:check-alerts')->dailyAt('09:00');
}

// Queued job
CheckLowStockJob::dispatch($shopOwnerId)->onQueue('inventory');
```

**Overdue Orders Check:**
```php
// Run every 6 hours
$schedule->job(new CheckOverdueOrdersJob)->everySixHours();
```

**Inventory Sync:**
```php
// Run hourly during business hours
$schedule->job(new SyncInventoryWithProductsJob)
         ->hourly()
         ->between('8:00', '18:00');
```

#### 2. Queue Configuration

**config/queue.php:**
```php
'connections' => [
    'database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ],
    
    // Dedicated inventory queue
    'inventory' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'inventory',
        'retry_after' => 120,
    ],
],
```

### Event & Listener Optimizations

#### 1. Queued Listeners

All listeners implement `ShouldQueue`:
```php
class SendLowStockNotification implements ShouldQueue
{
    use Queueable;
    
    public $queue = 'notifications';
    public $tries = 3;
    public $timeout = 30;
}
```

#### 2. Event Batching

```php
// Batch stock movements for bulk operations
public function bulkUpdateQuantities(array $updates)
{
    DB::transaction(function () use ($updates) {
        foreach ($updates as $update) {
            // Update happens
            event(new InventoryItemUpdated($item));
        }
    });
}
```

### API Response Optimizations

#### 1. Resource Collections

```php
// Use API resources for consistent formatting
return InventoryItemResource::collection($items);

// Pagination included
return InventoryItemResource::collection(
    InventoryItem::paginate(15)
);
```

#### 2. Conditional Loading

```php
// Load relationships only when needed
public function show($id, Request $request)
{
    $item = InventoryItem::findOrFail($id);
    
    if ($request->has('include')) {
        $item->load($request->input('include'));
    }
    
    return $item;
}
```

### File Upload Optimizations

#### 1. Image Processing

```php
// Resize images on upload
public function uploadImage(Request $request, $id)
{
    $image = $request->file('image');
    
    // Store original
    $path = $image->store('inventory', 'public');
    
    // Generate thumbnail (async job)
    GenerateThumbnailJob::dispatch($path);
    
    return response()->json(['path' => $path]);
}
```

#### 2. Storage Configuration

**config/filesystems.php:**
```php
'disks' => [
    'public' => [
        'driver' => 'local',
        'root' => storage_path('app/public'),
        'url' => env('APP_URL').'/storage',
        'visibility' => 'public',
        // Add CDN URL for production
        'url' => env('CDN_URL', env('APP_URL').'/storage'),
    ],
],
```

### Frontend Optimizations

#### 1. API Call Debouncing

```typescript
// Debounce search queries
const debouncedSearch = debounce((query: string) => {
    inventoryAPI.getAll({ search: query });
}, 300);
```

#### 2. Data Caching

```typescript
// Cache frequently accessed data
const cachedData = useMemo(() => {
    return inventoryAPI.getDashboardMetrics();
}, [shopOwnerId]);
```

#### 3. Pagination

```typescript
// Lazy load with pagination
const loadMore = async (page: number) => {
    const response = await inventoryAPI.getAll({ page });
    setItems(prev => [...prev, ...response.data]);
};
```

### Monitoring & Profiling

#### 1. Query Logging (Development)

```php
// Enable query log in development
if (app()->environment('local')) {
    DB::listen(function ($query) {
        Log::info($query->sql, $query->bindings);
    });
}
```

#### 2. Performance Metrics

```php
// Track slow queries
DB::whenQueryingForLongerThan(1000, function ($connection) {
    Log::warning('Slow query detected', [
        'connection' => $connection->getName(),
    ]);
});
```

#### 3. Cache Hit Rate Monitoring

```php
// Monitor cache effectiveness
Event::listen(CacheHit::class, function ($event) {
    Log::info('Cache hit', ['key' => $event->key]);
});

Event::listen(CacheMissed::class, function ($event) {
    Log::info('Cache miss', ['key' => $event->key]);
});
```

### Production Recommendations

#### 1. Database

- Use connection pooling
- Enable query caching
- Regular ANALYZE TABLE for statistics
- Monitor slow query log
- Consider read replicas for heavy loads

#### 2. Caching

- Use Redis for cache and sessions in production
- Implement cache warming for frequently accessed data
- Set appropriate TTLs based on data volatility

#### 3. Queue Workers

- Run dedicated queue workers: `php artisan queue:work --queue=inventory,notifications,default`
- Use Supervisor for process monitoring
- Scale workers based on queue depth

#### 4. Assets

- Enable CDN for static assets
- Implement lazy loading for images
- Use image optimization tools
- Enable browser caching

#### 5. Monitoring

- Implement application performance monitoring (APM)
- Set up error tracking (Sentry, Bugsnag)
- Monitor queue metrics
- Track cache hit rates
- Monitor database performance

### Expected Performance Metrics

**API Response Times:**
- Dashboard metrics: < 200ms
- List endpoints: < 150ms
- Detail endpoints: < 100ms
- Create/Update operations: < 300ms

**Database Queries:**
- Simple queries: < 10ms
- Complex aggregations: < 50ms
- Report generation: < 500ms

**Cache Performance:**
- Hit rate target: > 80%
- Cache lookup: < 5ms

**Queue Processing:**
- Stock alerts: < 5 seconds
- Report generation: < 30 seconds
- Bulk operations: < 2 minutes
