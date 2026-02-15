<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use App\Models\RepairRequest;

class RepairRequestController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'shoe_type' => 'nullable|string|max:255',
            'brand' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'shop_owner_id' => 'nullable|exists:shop_owners,id',
            'services' => 'required|array',
            'services.*' => 'exists:repair_services,id',
            'images' => 'required|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
            'total' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Generate unique request ID
            $requestId = 'REP-' . date('Ymd') . str_pad(RepairRequest::whereDate('created_at', today())->count() + 1, 3, '0', STR_PAD_LEFT);

            // Handle image uploads
            $imagePaths = [];
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $path = $image->store('repair-requests', 'public');
                    $imagePaths[] = $path;
                }
            }

            // Create repair request
            $repairRequest = RepairRequest::create([
                'request_id' => $requestId,
                'customer_name' => $request->customer_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'shoe_type' => $request->shoe_type,
                'brand' => $request->brand,
                'description' => $request->description,
                'shop_owner_id' => $request->shop_owner_id,
                'images' => json_encode($imagePaths),
                'total' => $request->total,
                'status' => 'pending',
            ]);

            // Attach services
            $repairRequest->services()->attach($request->services);

            return response()->json([
                'success' => true,
                'message' => 'Repair request submitted successfully',
                'data' => [
                    'request_id' => $requestId,
                    'total' => $request->total,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit repair request: ' . $e->getMessage()
            ], 500);
        }
    }

    public function index(Request $request)
    {
        $query = RepairRequest::with(['services', 'shopOwner']);

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by shop owner
        if ($request->has('shop_owner_id')) {
            $query->where('shop_owner_id', $request->shop_owner_id);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('request_id', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('shoe_type', 'like', "%{$search}%");
            });
        }

        $repairRequests = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $repairRequests->map(function ($request) {
                $images = json_decode($request->images, true);
                return [
                    'id' => $request->request_id,
                    'customer' => $request->customer_name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'item' => $request->shoe_type . ($request->brand ? " ({$request->brand})" : ''),
                    'service' => $request->services->pluck('name')->join(', '),
                    'total' => '₱' . number_format($request->total, 0),
                    'status' => $request->status,
                    'createdAt' => $request->created_at->format('Y-m-d h:i A'),
                    'startedAt' => $request->started_at ? $request->started_at->format('Y-m-d h:i A') : null,
                    'completedAt' => $request->completed_at ? $request->completed_at->format('Y-m-d h:i A') : null,
                    'notes' => $request->description,
                    'imageUrl' => !empty($images) ? Storage::url($images[0]) : null,
                    'repairDetails' => $request->services->pluck('description')->toArray(),
                ];
            })
        ]);
    }

    public function updateStatus(Request $request, $requestId)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:received,pending,in-progress,completed,ready-for-pickup',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $repairRequest = RepairRequest::where('request_id', $requestId)->firstOrFail();
            
            $repairRequest->status = $request->status;
            
            if ($request->status === 'in-progress' && !$repairRequest->started_at) {
                $repairRequest->started_at = now();
            }
            
            if ($request->status === 'ready-for-pickup' && !$repairRequest->completed_at) {
                $repairRequest->completed_at = now();
            }
            
            $repairRequest->save();

            return response()->json([
                'success' => true,
                'message' => 'Status updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update status: ' . $e->getMessage()
            ], 500);
        }
    }
}
