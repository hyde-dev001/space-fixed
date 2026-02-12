<?php

namespace App\Repositories\HR;

use App\Models\TrainingProgram;
use App\Models\TrainingEnrollment;
use App\Models\Certification;
use App\Repositories\HR\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;

/**
 * TrainingRepository - Handles all training-related database queries
 */
class TrainingRepository extends BaseRepository
{
    protected $enrollmentModel;
    protected $certificationModel;

    /**
     * TrainingRepository constructor
     *
     * @param TrainingProgram $model
     * @param TrainingEnrollment $enrollmentModel
     * @param Certification $certificationModel
     */
    public function __construct(
        TrainingProgram $model,
        TrainingEnrollment $enrollmentModel,
        Certification $certificationModel
    ) {
        $this->model = $model;
        $this->enrollmentModel = $enrollmentModel;
        $this->certificationModel = $certificationModel;
    }

    /**
     * Get training programs for a shop with filters
     *
     * @param int $shopOwnerId
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getShopPrograms(int $shopOwnerId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->where('shop_owner_id', $shopOwnerId);

        // Apply filters
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('title', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('description', 'like', '%' . $filters['search'] . '%');
            });
        }

        // Default relationships
        $with = $filters['with'] ?? ['enrollments', 'trainer'];
        $query->with($with);

        // Default ordering
        $orderBy = $filters['order_by'] ?? 'start_date';
        $orderDirection = $filters['order_direction'] ?? 'desc';
        $query->orderBy($orderBy, $orderDirection);

        return $query->paginate($perPage);
    }

    /**
     * Get active training programs
     *
     * @param int $shopOwnerId
     * @return Collection
     */
    public function getActivePrograms(int $shopOwnerId): Collection
    {
        return $this->model->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active')
            ->where('start_date', '<=', Carbon::now())
            ->where('end_date', '>=', Carbon::now())
            ->with(['enrollments', 'trainer'])
            ->get();
    }

    /**
     * Get upcoming training programs
     *
     * @param int $shopOwnerId
     * @param int $daysAhead
     * @return Collection
     */
    public function getUpcomingPrograms(int $shopOwnerId, int $daysAhead = 30): Collection
    {
        $endDate = Carbon::today()->addDays($daysAhead);

        return $this->model->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'scheduled')
            ->whereBetween('start_date', [Carbon::today(), $endDate])
            ->with(['enrollments', 'trainer'])
            ->orderBy('start_date', 'asc')
            ->get();
    }

    /**
     * Get completed training programs
     *
     * @param int $shopOwnerId
     * @param int|null $year
     * @return Collection
     */
    public function getCompletedPrograms(int $shopOwnerId, ?int $year = null): Collection
    {
        $query = $this->model->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'completed');

        if ($year) {
            $query->whereYear('end_date', $year);
        }

        return $query->with(['enrollments', 'trainer'])
            ->orderBy('end_date', 'desc')
            ->get();
    }

    /**
     * Get training statistics
     *
     * @param int $shopOwnerId
     * @param int|null $year
     * @return array
     */
    public function getTrainingStatistics(int $shopOwnerId, ?int $year = null): array
    {
        $query = $this->model->where('shop_owner_id', $shopOwnerId);

        if ($year) {
            $query->whereYear('start_date', $year);
        }

        $total = $query->count();
        $active = (clone $query)->where('status', 'active')->count();
        $completed = (clone $query)->where('status', 'completed')->count();
        $scheduled = (clone $query)->where('status', 'scheduled')->count();

        $byType = (clone $query)->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->type => $item->count];
            });

        return [
            'total' => $total,
            'active' => $active,
            'completed' => $completed,
            'scheduled' => $scheduled,
            'by_type' => $byType
        ];
    }

    // ==================== Training Enrollment Methods ====================

    /**
     * Get enrollments for a training program
     *
     * @param int $programId
     * @return Collection
     */
    public function getProgramEnrollments(int $programId): Collection
    {
        return $this->enrollmentModel->where('training_program_id', $programId)
            ->with('employee')
            ->get();
    }

    /**
     * Get employee's training enrollments
     *
     * @param int $employeeId
     * @param array $filters
     * @return Collection
     */
    public function getEmployeeEnrollments(int $employeeId, array $filters = []): Collection
    {
        $query = $this->enrollmentModel->where('employee_id', $employeeId)
            ->with('trainingProgram');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['year'])) {
            $query->whereHas('trainingProgram', function ($q) use ($filters) {
                $q->whereYear('start_date', $filters['year']);
            });
        }

        return $query->orderBy('enrollment_date', 'desc')->get();
    }

    /**
     * Enroll employee in training program
     *
     * @param int $programId
     * @param int $employeeId
     * @param array $additionalData
     * @return TrainingEnrollment
     */
    public function enrollEmployee(int $programId, int $employeeId, array $additionalData = []): TrainingEnrollment
    {
        return $this->enrollmentModel->create(array_merge([
            'training_program_id' => $programId,
            'employee_id' => $employeeId,
            'enrollment_date' => Carbon::now(),
            'status' => 'enrolled'
        ], $additionalData));
    }

    /**
     * Update enrollment status
     *
     * @param int $enrollmentId
     * @param string $status
     * @param array $additionalData
     * @return bool
     */
    public function updateEnrollmentStatus(int $enrollmentId, string $status, array $additionalData = []): bool
    {
        return $this->enrollmentModel->where('id', $enrollmentId)
            ->update(array_merge(['status' => $status], $additionalData));
    }

    /**
     * Mark enrollment as completed
     *
     * @param int $enrollmentId
     * @param float|null $score
     * @param string|null $feedback
     * @return bool
     */
    public function completeEnrollment(int $enrollmentId, ?float $score = null, ?string $feedback = null): bool
    {
        return $this->enrollmentModel->where('id', $enrollmentId)->update([
            'status' => 'completed',
            'completion_date' => Carbon::now(),
            'score' => $score,
            'feedback' => $feedback
        ]);
    }

    /**
     * Check if employee is enrolled in program
     *
     * @param int $programId
     * @param int $employeeId
     * @return bool
     */
    public function isEmployeeEnrolled(int $programId, int $employeeId): bool
    {
        return $this->enrollmentModel->where('training_program_id', $programId)
            ->where('employee_id', $employeeId)
            ->exists();
    }

    /**
     * Get completion statistics for program
     *
     * @param int $programId
     * @return array
     */
    public function getProgramCompletionStats(int $programId): array
    {
        $total = $this->enrollmentModel->where('training_program_id', $programId)->count();
        $completed = $this->enrollmentModel->where('training_program_id', $programId)
            ->where('status', 'completed')
            ->count();
        $inProgress = $this->enrollmentModel->where('training_program_id', $programId)
            ->where('status', 'in_progress')
            ->count();

        $completionRate = $total > 0 ? ($completed / $total) * 100 : 0;

        return [
            'total_enrolled' => $total,
            'completed' => $completed,
            'in_progress' => $inProgress,
            'completion_rate' => round($completionRate, 2)
        ];
    }

    // ==================== Certification Methods ====================

    /**
     * Get certifications for employee
     *
     * @param int $employeeId
     * @param array $filters
     * @return Collection
     */
    public function getEmployeeCertifications(int $employeeId, array $filters = []): Collection
    {
        $query = $this->certificationModel->where('employee_id', $employeeId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['expiring'])) {
            $daysAhead = $filters['expiring'];
            $query->whereNotNull('expiry_date')
                  ->whereBetween('expiry_date', [Carbon::today(), Carbon::today()->addDays($daysAhead)]);
        }

        return $query->orderBy('issue_date', 'desc')->get();
    }

    /**
     * Get all certifications for shop
     *
     * @param int $shopOwnerId
     * @param array $filters
     * @return Collection
     */
    public function getShopCertifications(int $shopOwnerId, array $filters = []): Collection
    {
        $query = $this->certificationModel->whereHas('employee', function ($q) use ($shopOwnerId) {
            $q->where('shop_owner_id', $shopOwnerId);
        })->with('employee');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('issue_date', 'desc')->get();
    }

    /**
     * Get expiring certifications
     *
     * @param int $shopOwnerId
     * @param int $daysAhead
     * @return Collection
     */
    public function getExpiringCertificates(int $shopOwnerId, int $daysAhead = 30): Collection
    {
        $startDate = Carbon::today();
        $endDate = $startDate->copy()->addDays($daysAhead);

        return $this->certificationModel->whereHas('employee', function ($q) use ($shopOwnerId) {
            $q->where('shop_owner_id', $shopOwnerId)
              ->where('status', 'active');
        })
            ->where('status', 'active')
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [$startDate, $endDate])
            ->with('employee')
            ->orderBy('expiry_date', 'asc')
            ->get();
    }

    /**
     * Get expired certifications
     *
     * @param int $shopOwnerId
     * @return Collection
     */
    public function getExpiredCertificates(int $shopOwnerId): Collection
    {
        return $this->certificationModel->whereHas('employee', function ($q) use ($shopOwnerId) {
            $q->where('shop_owner_id', $shopOwnerId);
        })
            ->where('status', 'active')
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<', Carbon::today())
            ->with('employee')
            ->get();
    }

    /**
     * Create certification
     *
     * @param array $data
     * @return Certification
     */
    public function createCertification(array $data): Certification
    {
        return $this->certificationModel->create($data);
    }

    /**
     * Update certification
     *
     * @param int $certificationId
     * @param array $data
     * @return bool
     */
    public function updateCertification(int $certificationId, array $data): bool
    {
        return $this->certificationModel->where('id', $certificationId)->update($data);
    }

    /**
     * Mark certification as expired
     *
     * @param int $certificationId
     * @return bool
     */
    public function expireCertification(int $certificationId): bool
    {
        return $this->certificationModel->where('id', $certificationId)->update([
            'status' => 'expired'
        ]);
    }

    /**
     * Renew certification
     *
     * @param int $certificationId
     * @param Carbon $newExpiryDate
     * @return bool
     */
    public function renewCertification(int $certificationId, Carbon $newExpiryDate): bool
    {
        return $this->certificationModel->where('id', $certificationId)->update([
            'status' => 'active',
            'expiry_date' => $newExpiryDate,
            'renewed_at' => Carbon::now()
        ]);
    }
}
