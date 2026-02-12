<?php

namespace App\Services\HR;

use App\Models\TrainingProgram;
use App\Models\TrainingSession;
use App\Models\TrainingEnrollment;
use App\Models\Certification;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use App\Notifications\HR\TrainingEnrolled;
use App\Notifications\HR\TrainingCompleted;
use App\Notifications\HR\CertificationIssued;
use App\Notifications\HR\CertificationExpiring;

/**
 * TrainingService handles all training-related business logic
 * including enrollment validation, certificate issuance, and training lifecycle management
 */
class TrainingService
{
    /**
     * Validate if employee can be enrolled in a training program
     * 
     * @param Employee $employee
     * @param TrainingProgram $program
     * @param TrainingSession|null $session
     * @return array ['valid' => bool, 'message' => string]
     */
    public function validateEnrollment(Employee $employee, TrainingProgram $program, ?TrainingSession $session = null): array
    {
        // Check if program is active
        if (!$program->is_active) {
            return [
                'valid' => false,
                'message' => 'This training program is not currently active'
            ];
        }
        
        // Check for duplicate enrollment
        $existingEnrollment = TrainingEnrollment::where('employee_id', $employee->id)
            ->where('training_program_id', $program->id)
            ->whereIn('status', ['enrolled', 'in_progress'])
            ->first();
        
        if ($existingEnrollment) {
            return [
                'valid' => false,
                'message' => 'Employee is already enrolled in this training program'
            ];
        }
        
        // Check prerequisites if specified
        if ($program->prerequisites) {
            $hasPrerequisites = $this->checkPrerequisites($employee, $program->prerequisites);
            if (!$hasPrerequisites) {
                return [
                    'valid' => false,
                    'message' => 'Employee does not meet the prerequisites for this training'
                ];
            }
        }
        
        // Check session availability if specified
        if ($session) {
            if (!$session->hasAvailableSeats()) {
                return [
                    'valid' => false,
                    'message' => 'No seats available in the selected training session'
                ];
            }
            
            // Check if session is in the future
            if (Carbon::parse($session->start_date)->isPast()) {
                return [
                    'valid' => false,
                    'message' => 'Cannot enroll in a past training session'
                ];
            }
        }
        
        return [
            'valid' => true,
            'message' => 'Enrollment is valid'
        ];
    }

    /**
     * Enroll employee in a training program
     * 
     * @param Employee $employee
     * @param TrainingProgram $program
     * @param TrainingSession|null $session
     * @param int|null $enrolledBy
     * @return TrainingEnrollment
     * @throws \Exception
     */
    public function enrollEmployee(Employee $employee, TrainingProgram $program, ?TrainingSession $session = null, ?int $enrolledBy = null): TrainingEnrollment
    {
        DB::beginTransaction();
        
        try {
            // Validate enrollment
            $validation = $this->validateEnrollment($employee, $program, $session);
            
            if (!$validation['valid']) {
                throw new \Exception($validation['message']);
            }
            
            // Create enrollment record
            $enrollment = TrainingEnrollment::create([
                'employee_id' => $employee->id,
                'training_program_id' => $program->id,
                'training_session_id' => $session?->id,
                'status' => 'enrolled',
                'enrolled_date' => now(),
                'progress_percentage' => 0,
                'enrolled_by' => $enrolledBy ?? auth()->id(),
                'shop_owner_id' => $employee->shop_owner_id
            ]);
            
            // Increment session enrolled count if session specified
            if ($session) {
                $session->incrementEnrolled();
            }
            
            DB::commit();
            
            Log::info('Employee enrolled in training', [
                'employee_id' => $employee->id,
                'training_program_id' => $program->id,
                'enrollment_id' => $enrollment->id
            ]);
            
            // Send notification to employee
            try {
                if ($employee->user) {
                    $employee->user->notify(new TrainingEnrolled($enrollment, $program));
                }
            } catch (\Exception $e) {
                Log::error('Failed to send training enrollment notification', [
                    'enrollment_id' => $enrollment->id,
                    'error' => $e->getMessage()
                ]);
            }
            
            return $enrollment;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to enroll employee in training', [
                'employee_id' => $employee->id,
                'training_program_id' => $program->id,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Mark enrollment as in progress
     * 
     * @param TrainingEnrollment $enrollment
     * @return bool
     */
    public function startTraining(TrainingEnrollment $enrollment): bool
    {
        try {
            $enrollment->markInProgress();
            
            Log::info('Training started', [
                'enrollment_id' => $enrollment->id,
                'employee_id' => $enrollment->employee_id
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Failed to start training', [
                'enrollment_id' => $enrollment->id,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Update training progress
     * 
     * @param TrainingEnrollment $enrollment
     * @param int $progressPercentage
     * @return bool
     */
    public function updateProgress(TrainingEnrollment $enrollment, int $progressPercentage): bool
    {
        try {
            if ($progressPercentage < 0 || $progressPercentage > 100) {
                throw new \Exception('Progress percentage must be between 0 and 100');
            }
            
            $enrollment->update([
                'progress_percentage' => $progressPercentage
            ]);
            
            // Auto-start if not yet started
            if ($enrollment->status === 'enrolled' && $progressPercentage > 0) {
                $enrollment->markInProgress();
            }
            
            Log::info('Training progress updated', [
                'enrollment_id' => $enrollment->id,
                'progress' => $progressPercentage
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Failed to update training progress', [
                'enrollment_id' => $enrollment->id,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Complete training and issue certificate if applicable
     * 
     * @param TrainingEnrollment $enrollment
     * @param float|null $assessmentScore
     * @param bool $passed
     * @param string|null $notes
     * @return array ['enrollment' => TrainingEnrollment, 'certificate' => Certification|null]
     * @throws \Exception
     */
    public function completeTraining(TrainingEnrollment $enrollment, ?float $assessmentScore = null, bool $passed = true, ?string $notes = null): array
    {
        DB::beginTransaction();
        
        try {
            // Mark enrollment as completed
            $enrollment->markCompleted($assessmentScore, $passed, $notes);
            
            $certificate = null;
            
            // Issue certificate if passed and program issues certificates
            if ($passed && $enrollment->program->issues_certificate) {
                $certificate = $this->issueCertificate($enrollment);
            }
            
            DB::commit();
            
            Log::info('Training completed', [
                'enrollment_id' => $enrollment->id,
                'passed' => $passed,
                'certificate_issued' => $certificate !== null
            ]);
            
            // Send completion notification
            try {
                if ($enrollment->employee && $enrollment->employee->user) {
                    $enrollment->employee->user->notify(new TrainingCompleted($enrollment, $enrollment->program, $passed));
                }
            } catch (\Exception $e) {
                Log::error('Failed to send training completed notification', [
                    'enrollment_id' => $enrollment->id,
                    'error' => $e->getMessage()
                ]);
            }
            
            return [
                'enrollment' => $enrollment->fresh(['program', 'employee', 'certification']),
                'certificate' => $certificate
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to complete training', [
                'enrollment_id' => $enrollment->id,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Issue certificate for completed training
     * 
     * @param TrainingEnrollment $enrollment
     * @return Certification
     * @throws \Exception
     */
    protected function issueCertificate(TrainingEnrollment $enrollment): Certification
    {
        try {
            $program = $enrollment->program;
            
            // Generate unique certificate number
            $certificateNumber = 'CERT-' . strtoupper(uniqid());
            
            // Calculate expiry date if validity period is set
            $expiryDate = null;
            if ($program->certificate_validity_months) {
                $expiryDate = Carbon::now()->addMonths($program->certificate_validity_months);
            }
            
            // Create certificate
            $certificate = Certification::create([
                'employee_id' => $enrollment->employee_id,
                'training_enrollment_id' => $enrollment->id,
                'certificate_name' => $program->title,
                'certificate_number' => $certificateNumber,
                'issuing_organization' => config('app.name'),
                'issue_date' => now(),
                'expiry_date' => $expiryDate,
                'status' => 'active',
                'issued_by' => auth()->id(),
                'shop_owner_id' => $enrollment->shop_owner_id
            ]);
            
            Log::info('Certificate issued', [
                'certificate_id' => $certificate->id,
                'employee_id' => $enrollment->employee_id,
                'certificate_number' => $certificateNumber
            ]);
            
            // Send certificate issued notification
            try {
                if ($enrollment->employee && $enrollment->employee->user) {
                    $enrollment->employee->user->notify(new CertificationIssued($certificate, $program));
                }
            } catch (\Exception $e) {
                Log::error('Failed to send certification issued notification', [
                    'certificate_id' => $certificate->id,
                    'error' => $e->getMessage()
                ]);
            }
            
            return $certificate;
            
        } catch (\Exception $e) {
            Log::error('Failed to issue certificate', [
                'enrollment_id' => $enrollment->id,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Check if employee has expiring certificates and send notifications
     * 
     * @param int $shopOwnerId
     * @param int $daysThreshold Default 30 days
     * @return int Number of notifications sent
     */
    public function checkExpiringCertificates(int $shopOwnerId, int $daysThreshold = 30): int
    {
        $expiringCertificates = Certification::where('shop_owner_id', $shopOwnerId)
            ->expiringSoon($daysThreshold)
            ->with('employee.user')
            ->get();
        
        $notificationsSent = 0;
        
        foreach ($expiringCertificates as $certificate) {
            try {
                $daysUntilExpiry = $certificate->daysUntilExpiry();
                
                if ($certificate->employee && $certificate->employee->user) {
                    $certificate->employee->user->notify(new CertificationExpiring($certificate, $daysUntilExpiry));
                    $notificationsSent++;
                }
                
            } catch (\Exception $e) {
                Log::error('Failed to send certificate expiring notification', [
                    'certificate_id' => $certificate->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        Log::info('Certificate expiry check completed', [
            'shop_owner_id' => $shopOwnerId,
            'expiring_count' => $expiringCertificates->count(),
            'notifications_sent' => $notificationsSent
        ]);
        
        return $notificationsSent;
    }

    /**
     * Revoke certificate
     * 
     * @param Certification $certificate
     * @param string $reason
     * @return bool
     */
    public function revokeCertificate(Certification $certificate, string $reason = ''): bool
    {
        try {
            $certificate->update([
                'status' => 'revoked',
                'notes' => ($certificate->notes ? $certificate->notes . "\n\n" : '') . "Revoked: {$reason}"
            ]);
            
            Log::info('Certificate revoked', [
                'certificate_id' => $certificate->id,
                'reason' => $reason
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Failed to revoke certificate', [
                'certificate_id' => $certificate->id,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Get training statistics for an employee
     * 
     * @param Employee $employee
     * @return array
     */
    public function getEmployeeTrainingStatistics(Employee $employee): array
    {
        $enrollments = TrainingEnrollment::where('employee_id', $employee->id)->get();
        $certifications = Certification::where('employee_id', $employee->id)->get();
        
        return [
            'total_enrollments' => $enrollments->count(),
            'in_progress' => $enrollments->where('status', 'in_progress')->count(),
            'completed' => $enrollments->where('status', 'completed')->count(),
            'completion_rate' => $enrollments->count() > 0 
                ? round(($enrollments->where('status', 'completed')->count() / $enrollments->count()) * 100, 2)
                : 0,
            'total_certifications' => $certifications->count(),
            'active_certifications' => $certifications->where('status', 'active')->count(),
            'expired_certifications' => $certifications->where('status', 'expired')->count(),
            'total_training_hours' => $enrollments->where('status', 'completed')
                ->sum(function($enrollment) {
                    return $enrollment->program->duration_hours ?? 0;
                })
        ];
    }

    /**
     * Get training completion report for a shop
     * 
     * @param int $shopOwnerId
     * @param string|null $startDate
     * @param string|null $endDate
     * @return array
     */
    public function getCompletionReport(int $shopOwnerId, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = TrainingEnrollment::where('shop_owner_id', $shopOwnerId);
        
        if ($startDate) {
            $query->where('enrolled_date', '>=', $startDate);
        }
        
        if ($endDate) {
            $query->where('enrolled_date', '<=', $endDate);
        }
        
        $enrollments = $query->with('program', 'employee')->get();
        
        $byCategory = $enrollments->groupBy(function($enrollment) {
            return $enrollment->program->category ?? 'other';
        })->map(function($group) {
            return [
                'total' => $group->count(),
                'completed' => $group->where('status', 'completed')->count(),
                'in_progress' => $group->where('status', 'in_progress')->count(),
                'completion_rate' => $group->count() > 0
                    ? round(($group->where('status', 'completed')->count() / $group->count()) * 100, 2)
                    : 0
            ];
        });
        
        return [
            'total_enrollments' => $enrollments->count(),
            'completed' => $enrollments->where('status', 'completed')->count(),
            'in_progress' => $enrollments->where('status', 'in_progress')->count(),
            'completion_rate' => $enrollments->count() > 0
                ? round(($enrollments->where('status', 'completed')->count() / $enrollments->count()) * 100, 2)
                : 0,
            'by_category' => $byCategory,
            'average_score' => $enrollments->where('status', 'completed')
                ->whereNotNull('assessment_score')
                ->avg('assessment_score') ?? 0
        ];
    }

    /**
     * Check if employee meets training prerequisites
     * 
     * @param Employee $employee
     * @param string $prerequisites
     * @return bool
     */
    protected function checkPrerequisites(Employee $employee, string $prerequisites): bool
    {
        // TODO: Implement actual prerequisite checking logic
        // This could check for:
        // - Completed trainings
        // - Certifications
        // - Years of experience
        // - Department/role requirements
        
        // For now, return true (no prerequisite checking)
        return true;
    }

    /**
     * Get recommended trainings for an employee based on role and skills
     * 
     * @param Employee $employee
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getRecommendedTrainings(Employee $employee): \Illuminate\Database\Eloquent\Collection
    {
        // Get employee's current certifications and completed trainings
        $completedProgramIds = TrainingEnrollment::where('employee_id', $employee->id)
            ->where('status', 'completed')
            ->pluck('training_program_id');
        
        // Get active programs employee hasn't completed
        $recommendedPrograms = TrainingProgram::where('shop_owner_id', $employee->shop_owner_id)
            ->where('is_active', true)
            ->whereNotIn('id', $completedProgramIds)
            ->withCount('enrollments')
            ->orderBy('is_mandatory', 'desc')
            ->orderBy('enrollments_count', 'desc')
            ->limit(5)
            ->get();
        
        return $recommendedPrograms;
    }
}
