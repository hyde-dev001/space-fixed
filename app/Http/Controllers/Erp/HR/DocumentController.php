<?php

namespace App\Http\Controllers\ERP\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\EmployeeDocument;
use App\Models\Employee;
use App\Models\HR\AuditLog;
use App\Traits\HR\LogsHRActivity;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class DocumentController extends Controller
{
    use LogsHRActivity;
    /**
     * Display a listing of documents.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = EmployeeDocument::forShopOwner($user->shop_owner_id)
            ->with(['employee:id,first_name,last_name,email', 'uploader:id,name', 'verifier:id,name']);

        // Apply filters
        if ($request->filled('employee_id')) {
            $query->forEmployee($request->employee_id);
        }

        if ($request->filled('document_type')) {
            $query->ofType($request->document_type);
        }

        if ($request->filled('status')) {
            $query->withStatus($request->status);
        }

        if ($request->filled('expiring_within')) {
            $query->expiringWithin($request->expiring_within);
        }

        if ($request->filled('is_mandatory')) {
            $query->mandatory();
        }

        $documents = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json($documents);
    }

    /**
     * Upload a new document.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'document_type' => 'required|in:' . implode(',', array_keys(EmployeeDocument::DOCUMENT_TYPES)),
            'document_number' => 'nullable|string|max:100',
            'document_name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240', // 10MB max
            'issue_date' => 'nullable|date|before_or_equal:today',
            'expiry_date' => 'nullable|date|after:issue_date',
            'is_mandatory' => 'boolean',
            'requires_renewal' => 'boolean',
            'reminder_days' => 'integer|min:1|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if employee belongs to the same shop owner
        $employee = Employee::forShopOwner($user->shop_owner_id)
            ->findOrFail($request->employee_id);

        // Handle file upload
        $file = $request->file('file');
        $fileName = time() . '_' . $file->getClientOriginalName();
        $filePath = "hr/documents/{$user->shop_owner_id}/{$request->employee_id}/" . $fileName;
        
        // Store file (will use configured disk - local, s3, etc.)
        $storedPath = Storage::disk('public')->put($filePath, file_get_contents($file));

        // Create document record
        $document = EmployeeDocument::create([
            'employee_id' => $request->employee_id,
            'shop_owner_id' => $user->shop_owner_id,
            'document_type' => $request->document_type,
            'document_number' => $request->document_number,
            'document_name' => $request->document_name,
            'description' => $request->description,
            'file_path' => $storedPath,
            'file_name' => $file->getClientOriginalName(),
            'file_type' => $file->getClientOriginalExtension(),
            'file_size' => $file->getSize(),
            'issue_date' => $request->issue_date,
            'expiry_date' => $request->expiry_date,
            'is_mandatory' => $request->is_mandatory ?? false,
            'requires_renewal' => $request->requires_renewal ?? false,
            'reminder_days' => $request->reminder_days ?? 30,
            'uploaded_by' => $user->id,
        ]);

        // Audit log
        $this->auditCreated(
            AuditLog::MODULE_DOCUMENT,
            $document,
            "Document uploaded: {$document->document_name} for {$employee->first_name} {$employee->last_name}",
            ['compliance', 'document']
        );

        return response()->json([
            'message' => 'Document uploaded successfully',
            'document' => $document->load(['employee', 'uploader'])
        ], 201);
    }

    /**
     * Display the specified document.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $document = EmployeeDocument::forShopOwner($user->shop_owner_id)
            ->with(['employee', 'uploader', 'verifier'])
            ->findOrFail($id);

        return response()->json($document);
    }

    /**
     * Download document file.
     */
    public function download(Request $request, $id)
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $document = EmployeeDocument::forShopOwner($user->shop_owner_id)
            ->findOrFail($id);

        // Audit log (sensitive access)
        $this->auditSensitiveAccess(
            AuditLog::MODULE_DOCUMENT,
            EmployeeDocument::class,
            $document->id,
            "Document downloaded: {$document->document_name} for {$document->employee->first_name} {$document->employee->last_name}"
        );

        // Return file download
        if (Storage::disk('public')->exists($document->file_path)) {
            return Storage::disk('public')->download($document->file_path, $document->file_name);
        }

        return response()->json(['error' => 'File not found'], 404);
    }

    /**
     * Update the specified document metadata.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $document = EmployeeDocument::forShopOwner($user->shop_owner_id)
            ->findOrFail($id);

        // Cannot update verified or rejected documents
        if (in_array($document->status, ['verified', 'rejected'])) {
            return response()->json([
                'error' => 'Cannot update document that has been verified or rejected'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'document_number' => 'nullable|string|max:100',
            'document_name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'issue_date' => 'nullable|date|before_or_equal:today',
            'expiry_date' => 'nullable|date|after:issue_date',
            'is_mandatory' => 'boolean',
            'requires_renewal' => 'boolean',
            'reminder_days' => 'integer|min:1|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $document->update($validator->validated());

        return response()->json([
            'message' => 'Document updated successfully',
            'document' => $document->load(['employee', 'uploader'])
        ]);
    }

    /**
     * Verify a document.
     */
    public function verify(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            \Log::warning('Unauthorized document verification attempt', [
                'user_id' => $user->id,
                'user_role' => $user->getRoleNames()->first(),
                'document_id' => $id
            ]);
            return response()->json([
                'error' => 'Unauthorized. Only Managers or users with HR permissions can verify documents.'
            ], 403);
        }

        $document = EmployeeDocument::forShopOwner($user->shop_owner_id)
            ->findOrFail($id);

        if ($document->status !== 'pending') {
            return response()->json([
                'error' => 'Only pending documents can be verified'
            ], 422);
        }

        $document->verify($user->id);

        return response()->json([
            'message' => 'Document verified successfully',
            'document' => $document->fresh(['employee', 'verifier'])
        ]);
    }

    /**
     * Reject a document.
     */
    public function reject(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $document = EmployeeDocument::forShopOwner($user->shop_owner_id)
            ->findOrFail($id);

        if ($document->status !== 'pending') {
            return response()->json([
                'error' => 'Only pending documents can be rejected'
            ], 422);
        }

        $document->reject($user->id, $request->reason);

        return response()->json([
            'message' => 'Document rejected successfully',
            'document' => $document->fresh(['employee', 'verifier'])
        ]);
    }

    /**
     * Delete a document.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Security Check: Only shop_owner can delete documents
        if ($user->role !== 'shop_owner') {
            return response()->json([
                'error' => 'Unauthorized. Only shop owners can delete documents.'
            ], 403);
        }

        $document = EmployeeDocument::forShopOwner($user->shop_owner_id)
            ->findOrFail($id);

        // Delete file from storage
        if (Storage::disk('public')->exists($document->file_path)) {
            Storage::disk('public')->delete($document->file_path);
        }

        // Soft delete document record
        $document->delete();

        \Log::info('Document deleted', [
            'deleter_id' => $user->id,
            'document_id' => $id,
            'employee_id' => $document->employee_id,
        ]);

        return response()->json(['message' => 'Document deleted successfully']);
    }

    /**
     * Get expiring documents report.
     */
    public function expiringDocuments(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!in_array($user->role, ['HR', 'shop_owner'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $days = $request->get('days', 30);
        
        $expiringDocuments = EmployeeDocument::getExpiringDocuments($user->shop_owner_id, $days);
        
        return response()->json([
            'days' => $days,
            'count' => $expiringDocuments->count(),
            'documents' => $expiringDocuments
        ]);
    }

    /**
     * Get expired documents report.
     */
    public function expiredDocuments(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!in_array($user->role, ['HR', 'shop_owner'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $expiredDocuments = EmployeeDocument::getExpiredDocuments($user->shop_owner_id);
        
        return response()->json([
            'count' => $expiredDocuments->count(),
            'documents' => $expiredDocuments
        ]);
    }

    /**
     * Get employee's documents.
     */
    public function employeeDocuments(Request $request, $employeeId): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!in_array($user->role, ['HR', 'shop_owner'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check if employee belongs to the same shop owner
        $employee = Employee::forShopOwner($user->shop_owner_id)
            ->findOrFail($employeeId);

        $documents = EmployeeDocument::forEmployee($employeeId)
            ->forShopOwner($user->shop_owner_id)
            ->with(['uploader', 'verifier'])
            ->orderBy('created_at', 'desc')
            ->get();

        $missingMandatory = EmployeeDocument::getMissingMandatoryDocuments($employeeId, $user->shop_owner_id);

        return response()->json([
            'employee' => $employee,
            'documents' => $documents,
            'missing_mandatory' => $missingMandatory,
            'statistics' => [
                'total' => $documents->count(),
                'verified' => $documents->where('status', 'verified')->count(),
                'pending' => $documents->where('status', 'pending')->count(),
                'expired' => $documents->where('status', 'expired')->count(),
                'expiring_soon' => $documents->filter(fn($doc) => $doc->days_until_expiry !== null && $doc->days_until_expiry <= 30 && $doc->days_until_expiry > 0)->count(),
            ]
        ]);
    }

    /**
     * Get document types list.
     */
    public function documentTypes(): JsonResponse
    {
        return response()->json([
            'document_types' => EmployeeDocument::getDocumentTypes()
        ]);
    }

    /**
     * Get documents statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!in_array($user->role, ['HR', 'shop_owner'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = EmployeeDocument::forShopOwner($user->shop_owner_id);

        $total = $query->count();
        $pending = $query->pending()->count();
        $verified = $query->verified()->count();
        $rejected = $query->where('status', 'rejected')->count();
        $expired = $query->expired()->count();
        $expiringIn30Days = $query->expiringWithin(30)->count();
        $expiringIn7Days = $query->expiringWithin(7)->count();

        $byType = EmployeeDocument::forShopOwner($user->shop_owner_id)
            ->selectRaw('document_type, COUNT(*) as count')
            ->groupBy('document_type')
            ->get()
            ->pluck('count', 'document_type');

        return response()->json([
            'total' => $total,
            'pending' => $pending,
            'verified' => $verified,
            'rejected' => $rejected,
            'expired' => $expired,
            'expiring_in_30_days' => $expiringIn30Days,
            'expiring_in_7_days' => $expiringIn7Days,
            'by_type' => $byType,
        ]);
    }
}
