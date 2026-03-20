<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Facades\Auth;

class ActivityLogController extends Controller
{
    protected array $subjectLabelCache = [];

    /**
     * Fields that are safe to expose in audit logs per model type
     * All other fields are filtered out for privacy/security
     */
    protected $safeFieldsByModel = [
        // Repair/Order related
        'App\\Models\\RepairRequest' => ['status', 'priority', 'description', 'request_id', 'total_cost', 'completed_at'],
        'App\\Models\\Order' => ['status', 'total_amount', 'delivery_method', 'order_number'],
        // Product related
        'App\\Models\\Product' => ['name', 'sku', 'brand', 'price', 'quantity', 'status'],
        'App\\Models\\InventoryItem' => ['name', 'sku', 'quantity', 'reorder_level', 'status'],
        // HR related
        'App\\Models\\Employee' => ['first_name', 'last_name', 'email', 'position', 'department', 'status'],
        'App\\Models\\User' => ['first_name', 'last_name', 'email', 'role', 'status'],
        'App\\Models\\HR\\AttendanceRecord' => ['date', 'time_in', 'time_out', 'status'],
        'App\\Models\\HR\\LeaveRequest' => ['status', 'leave_type', 'start_date', 'end_date', 'reason'],
        // Finance related
        'App\\Models\\Finance\\Expense' => ['category', 'amount', 'description', 'status', 'date'],
        'App\\Models\\Finance\\Invoice' => ['invoice_number', 'amount', 'status', 'due_date'],
        'App\\Models\\Finance\\Payment' => ['amount', 'method', 'status', 'date'],
        // Customer related
        'App\\Models\\Customer' => ['name', 'email', 'phone', 'address', 'status'],
    ];

    /**
     * Fields that should never be exposed (security/privacy sensitive)
     */
    protected $sensitiveFields = [
        'password', 'remember_token', 'two_factor_secret', 'api_token',
        'ip_address', 'user_agent', 'session_id',
        'card_number', 'cvv', 'bank_account',
        'ssn', 'tax_id',
        'causer_id', 'causer_type', // Don't expose who made the change (shown separately)
    ];

    /**
     * Translate internal field names to user-friendly labels
     */
    protected function getFieldLabel($field, $modelType = null)
    {
        $labels = [
            'status' => 'Status',
            'priority' => 'Priority',
            'description' => 'Description',
            'request_id' => 'Request ID',
            'total_cost' => 'Total Cost',
            'completed_at' => 'Completed',
            'amount' => 'Amount',
            'category' => 'Category',
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'email' => 'Email',
            'phone' => 'Phone',
            'position' => 'Position',
            'department' => 'Department',
            'reason' => 'Reason',
            'date' => 'Date',
            'time_in' => 'Time In',
            'time_out' => 'Time Out',
            'name' => 'Name',
            'sku' => 'SKU',
            'price' => 'Price',
            'quantity' => 'Quantity',
        ];
        return $labels[$field] ?? ucwords(str_replace('_', ' ', $field));
    }

    /**
     * Filter changes to only safe fields based on model type
     */
    protected function filterSafeChanges($changes, $subjectType)
    {
        if (!isset($this->safeFieldsByModel[$subjectType])) {
            // If model type not in whitelist, return empty (deny by default)
            return [];
        }

        $safeFields = $this->safeFieldsByModel[$subjectType];
        $filtered = [];

        foreach ($changes as $field => $change) {
            // Skip if field is in sensitive list
            if (in_array($field, $this->sensitiveFields)) {
                continue;
            }

            // Only include if in safe fields list
            if (in_array($field, $safeFields)) {
                $filtered[$field] = [
                    'label' => $this->getFieldLabel($field, $subjectType),
                    'old' => $change['old'],
                    'new' => $change['new'],
                ];
            }
        }

        return $filtered;
    }

    /**
     * Resolve a user-friendly subject label (prefer name/title/reference over numeric id)
     */
    protected function getSubjectLabel($log): string
    {
        $cacheKey = (string) ($log->subject_type ?? '') . ':' . (string) ($log->subject_id ?? '');
        if (isset($this->subjectLabelCache[$cacheKey])) {
            return $this->subjectLabelCache[$cacheKey];
        }

        $defaultLabel = 'Record';

        $preferredFields = [
            'name',
            'title',
            'product_name',
            'request_id',
            'order_number',
            'invoice_number',
            'reference_no',
            'reference_number',
            'sku',
            'email',
        ];

        $subject = $log->subject;
        if ($subject) {
            foreach ($preferredFields as $field) {
                if (isset($subject->{$field}) && !empty($subject->{$field})) {
                    return $this->subjectLabelCache[$cacheKey] = (string) $subject->{$field};
                }
            }
        }

        $subjectType = $log->subject_type;
        if ($subjectType && $log->subject_id && class_exists($subjectType) && is_subclass_of($subjectType, Model::class)) {
            try {
                $resolvedSubject = null;

                if (method_exists($subjectType, 'withTrashed')) {
                    $resolvedSubject = $subjectType::withTrashed()->find($log->subject_id);
                }

                if (!$resolvedSubject) {
                    $resolvedSubject = $subjectType::find($log->subject_id);
                }

                if ($resolvedSubject) {
                    foreach ($preferredFields as $field) {
                        if (isset($resolvedSubject->{$field}) && !empty($resolvedSubject->{$field})) {
                            return $this->subjectLabelCache[$cacheKey] = (string) $resolvedSubject->{$field};
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore and continue to property snapshots fallback
            }
        }

        $properties = is_array($log->properties) ? $log->properties : [];
        $attributeSnapshots = [
            $properties['attributes'] ?? [],
            $properties['old'] ?? [],
        ];

        foreach ($attributeSnapshots as $snapshot) {
            if (!is_array($snapshot)) {
                continue;
            }

            foreach ($preferredFields as $field) {
                if (array_key_exists($field, $snapshot) && !empty($snapshot[$field])) {
                    return $this->subjectLabelCache[$cacheKey] = (string) $snapshot[$field];
                }
            }
        }

        // Final fallback: inspect sibling activity rows for same subject and reuse any historical name/reference snapshot
        if ($log->subject_type && $log->subject_id) {
            $siblingActivities = Activity::query()
                ->where('subject_type', $log->subject_type)
                ->where('subject_id', $log->subject_id)
                ->latest('id')
                ->limit(10)
                ->get(['properties']);

            foreach ($siblingActivities as $activityRow) {
                $rowProperties = is_array($activityRow->properties) ? $activityRow->properties : [];
                $rowSnapshots = [
                    $rowProperties['attributes'] ?? [],
                    $rowProperties['old'] ?? [],
                ];

                foreach ($rowSnapshots as $snapshot) {
                    if (!is_array($snapshot)) {
                        continue;
                    }

                    foreach ($preferredFields as $field) {
                        if (array_key_exists($field, $snapshot) && !empty($snapshot[$field])) {
                            return $this->subjectLabelCache[$cacheKey] = (string) $snapshot[$field];
                        }
                    }
                }
            }
        }

        return $this->subjectLabelCache[$cacheKey] = $defaultLabel;
    }

    /**
     * Get activity logs filtered by role
     * 
     * Roles and their visibility:
     * - Manager: See everything in their shop
     * - HR: Employee, payroll, leave, training, attendance, performance changes
     * - Finance: Expenses, invoices, payments, approvals
     * - CRM: Customers, leads, orders, inquiries
     * - Shop Owner: See everything (they own the business)
     */
    public function index(Request $request)
    {
        try {
            // Check if authenticated via either guard
            $user = Auth::guard('user')->user();
            $shopOwner = Auth::guard('shop_owner')->user();
            
            // If not authenticated with either guard, return unauthorized
            if (!$user && !$shopOwner) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
            
            // Shop Owner sees everything
            if ($shopOwner) {
                return $this->getShopOwnerLogs($shopOwner->id, $request);
            }
            
            // User role-based filtering
            if ($user) {
                // Use Spatie role checking
                if ($user->hasRole('Manager')) {
                    return $this->getManagerLogs($user, $request);
                }
                
                if ($user->hasRole('HR')) {
                    return $this->getHRLogs($user, $request);
                }
                
                if ($user->hasAnyRole(['Finance Manager', 'Finance Staff'])) {
                    return $this->getFinanceLogs($user, $request);
                }
                
                if ($user->hasRole('CRM')) {
                    return $this->getCRMLogs($user, $request);
                }
                
                // Default: role doesn't have audit log access
                return response()->json([
                    'message' => 'Your role does not have audit log access'
                ], 403);
            }
            
            return response()->json(['message' => 'Unauthorized'], 401);
        } catch (\Exception $e) {
            \Log::error('Activity log error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'Error fetching activity logs',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
    
    /**
     * Shop Owner sees EVERYTHING
     */
    private function getShopOwnerLogs($shopOwnerId, Request $request)
    {
        $query = Activity::query()
            ->where(function($q) use ($shopOwnerId) {
                // Activities performed by shop owner
                $q->where(function($subQ) use ($shopOwnerId) {
                    $subQ->where('causer_type', 'App\\Models\\ShopOwner')
                         ->where('causer_id', $shopOwnerId);
                })
                // OR activities performed by users/employees of this shop
                ->orWhere(function($subQ) use ($shopOwnerId) {
                    $subQ->where('causer_type', 'App\\Models\\User')
                         ->whereIn('causer_id', function($userQuery) use ($shopOwnerId) {
                             $userQuery->select('id')
                                       ->from('users')
                                       ->where('shop_owner_id', $shopOwnerId);
                         });
                });
            })
            ->with(['causer', 'subject'])
            ->orderBy('created_at', 'desc');
        
        return $this->applyFiltersAndPaginate($query, $request);
    }
    
    /**
     * Manager sees everything in their shop (oversight & compliance)
     */
    private function getManagerLogs($user, Request $request)
    {
        $shopOwnerId = $user->shop_owner_id;
        
        $query = Activity::query()
            ->where(function($q) use ($shopOwnerId) {
                // Activities performed by shop owner
                $q->where(function($subQ) use ($shopOwnerId) {
                    $subQ->where('causer_type', 'App\\Models\\ShopOwner')
                         ->where('causer_id', $shopOwnerId);
                })
                // OR activities performed by users/employees in this shop
                ->orWhere(function($subQ) use ($shopOwnerId) {
                    $subQ->where('causer_type', 'App\\Models\\User')
                         ->whereIn('causer_id', function($userQuery) use ($shopOwnerId) {
                             $userQuery->select('id')
                                       ->from('users')
                                       ->where('shop_owner_id', $shopOwnerId);
                         });
                });
            })
            ->with(['causer', 'subject'])
            ->orderBy('created_at', 'desc');
        
        return $this->applyFiltersAndPaginate($query, $request);
    }
    
    /**
     * HR sees: Employee, Payroll, Leave, Training, Attendance, Performance changes
     */
    private function getHRLogs($user, Request $request)
    {
        $shopOwnerId = $user->shop_owner_id;
        
        $query = Activity::query()
            ->whereIn('subject_type', [
                'App\\Models\\User',
                'App\\Models\\Employee',
                'App\\Models\\HR\\Payroll',
                'App\\Models\\HR\\LeaveRequest',
                'App\\Models\\HR\\AttendanceRecord',
                'App\\Models\\HR\\Department',
            ])
            ->where(function($q) use ($shopOwnerId) {
                // Filter by causers from this shop (shop owner or their users)
                $q->where(function($subQ) use ($shopOwnerId) {
                    $subQ->where('causer_type', 'App\\Models\\ShopOwner')
                         ->where('causer_id', $shopOwnerId);
                })
                ->orWhere(function($subQ) use ($shopOwnerId) {
                    $subQ->where('causer_type', 'App\\Models\\User')
                         ->whereIn('causer_id', function($userQuery) use ($shopOwnerId) {
                             $userQuery->select('id')
                                       ->from('users')
                                       ->where('shop_owner_id', $shopOwnerId);
                         });
                });
            })
            ->with(['causer', 'subject'])
            ->orderBy('created_at', 'desc');
        
        return $this->applyFiltersAndPaginate($query, $request);
    }
    
    /**
     * Finance sees: Expenses, Invoices, Payments, Approvals, Price Change Requests
     */
    private function getFinanceLogs($user, Request $request)
    {
        $shopOwnerId = $user->shop_owner_id;
        
        $query = Activity::query()
            ->whereIn('subject_type', [
                'App\\Models\\Finance\\Expense',
                'App\\Models\\Finance\\Invoice',
                'App\\Models\\Finance\\Payment',
                'App\\Models\\Finance\\Revenue',
                'App\\Models\\Finance\\BankAccount',
                'App\\Models\\PriceChangeRequest',
            ])
            ->where(function($q) use ($shopOwnerId) {
                // Filter by causers from this shop (shop owner or their users)
                $q->where(function($subQ) use ($shopOwnerId) {
                    $subQ->where('causer_type', 'App\\Models\\ShopOwner')
                         ->where('causer_id', $shopOwnerId);
                })
                ->orWhere(function($subQ) use ($shopOwnerId) {
                    $subQ->where('causer_type', 'App\\Models\\User')
                         ->whereIn('causer_id', function($userQuery) use ($shopOwnerId) {
                             $userQuery->select('id')
                                       ->from('users')
                                       ->where('shop_owner_id', $shopOwnerId);
                         });
                });
            })
            ->with(['causer', 'subject'])
            ->orderBy('created_at', 'desc');
        
        return $this->applyFiltersAndPaginate($query, $request);
    }
    
    /**
     * CRM sees: Customers, Leads, Orders, Inquiries
     */
    private function getCRMLogs($user, Request $request)
    {
        $shopOwnerId = $user->shop_owner_id;
        
        $query = Activity::query()
            ->whereIn('subject_type', [
                'App\\Models\\Customer',
                'App\\Models\\CRM\\Lead',
                'App\\Models\\Order',
                'App\\Models\\CRM\\Inquiry',
                'App\\Models\\CRM\\Interaction',
            ])
            ->where(function($q) use ($shopOwnerId) {
                // Filter by causers from this shop (shop owner or their users)
                $q->where(function($subQ) use ($shopOwnerId) {
                    $subQ->where('causer_type', 'App\\Models\\ShopOwner')
                         ->where('causer_id', $shopOwnerId);
                })
                ->orWhere(function($subQ) use ($shopOwnerId) {
                    $subQ->where('causer_type', 'App\\Models\\User')
                         ->whereIn('causer_id', function($userQuery) use ($shopOwnerId) {
                             $userQuery->select('id')
                                       ->from('users')
                                       ->where('shop_owner_id', $shopOwnerId);
                         });
                });
            })
            ->with(['causer', 'subject'])
            ->orderBy('created_at', 'desc');
        
        return $this->applyFiltersAndPaginate($query, $request);
    }
    
    /**
     * Apply filters and return paginated results with stats
     */
    private function applyFiltersAndPaginate($query, Request $request)
    {
        // Date range filter
        if ($request->has('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        
        if ($request->has('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }
        
        // Event filter (created, updated, deleted)
        if ($request->has('event')) {
            $query->where('event', $request->event);
        }
        
        // Subject type filter
        if ($request->has('subject_type')) {
            $query->where('subject_type', 'like', '%' . $request->subject_type . '%');
        }
        
        // Causer filter
        if ($request->has('causer_id')) {
            $query->where('causer_id', $request->causer_id);
        }
        
        // Get total before pagination for stats
        $total = $query->count();
        
        // Get stats
        $stats = [
            'total_logs' => $total,
            'logs_last_24h' => (clone $query)->where('created_at', '>=', now()->subDay())->count(),
            'event_counts' => (clone $query)->selectRaw('event, COUNT(*) as count')
                ->groupBy('event')
                ->pluck('count', 'event')
                ->toArray(),
            'subject_type_counts' => (clone $query)->selectRaw('subject_type, COUNT(*) as count')
                ->groupBy('subject_type')
                ->pluck('count', 'subject_type')
                ->toArray(),
        ];
        
        // Paginate and transform data
        $logs = $query->paginate($request->get('per_page', 20));
        
        // Transform log data to include additional metadata
        $logs->getCollection()->transform(function ($log) {
            // Get causer info with role
            $causerInfo = null;
            if ($log->causer) {
                if ($log->causer_type === 'App\\Models\\User') {
                    $causerInfo = [
                        'id' => $log->causer->id,
                        'name' => $log->causer->first_name . ' ' . $log->causer->last_name,
                        'email' => $log->causer->email,
                        'role' => $log->causer->role ?? 'Staff',
                    ];
                } elseif ($log->causer_type === 'App\\Models\\ShopOwner') {
                    $causerInfo = [
                        'id' => $log->causer->id,
                        'name' => $log->causer->first_name . ' ' . $log->causer->last_name,
                        'email' => $log->causer->email,
                        'role' => 'Shop Owner',
                    ];
                }
            }
            
            // Extract IP and user agent from properties
            $properties = $log->properties ?? [];
            $metadata = [
                'ip_address' => $properties['ip_address'] ?? 'N/A',
                'user_agent' => $properties['user_agent'] ?? 'N/A',
            ];
            
            // Get only changed fields for diff view
            $changes = [];
            if ($log->event === 'updated' && isset($properties['old']) && isset($properties['attributes'])) {
                $old = $properties['old'];
                $new = $properties['attributes'];
                
                foreach ($new as $key => $newValue) {
                    if (isset($old[$key]) && $old[$key] != $newValue) {
                        $changes[$key] = [
                            'old' => $old[$key],
                            'new' => $newValue,
                        ];
                    }
                }
            }

            // Filter changes to only safe/business-relevant fields
            $safeChanges = $this->filterSafeChanges($changes, $log->subject_type);
            $subjectLabel = $this->getSubjectLabel($log);
            
            return [
                'id' => $log->id,
                'log_name' => $log->log_name,
                'description' => $log->description,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'subject_label' => $subjectLabel,
                'event' => $log->event,
                'changes' => $safeChanges,  // Only safe, filtered changes
                'created_at' => $log->created_at,
                'causer' => $causerInfo,
                'metadata' => $metadata,
            ];
        });
        
        return response()->json([
            'logs' => $logs,
            'stats' => $stats,
        ]);
    }
}
