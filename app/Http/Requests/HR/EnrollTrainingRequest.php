<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnrollTrainingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // HR, shop_owner, and managers can enroll employees
        return $this->user()->hasAnyRole(['HR', 'shop_owner', 'manager']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $shopOwnerId = $this->user()->shop_owner_id;

        return [
            'training_program_id' => [
                'required',
                'integer',
                Rule::exists('hr_training_programs', 'id')
                    ->where('shop_owner_id', $shopOwnerId)
            ],
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('hr_employees', 'id')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->where('status', 'active')
            ],
            'enrollment_date' => ['nullable', 'date', 'before_or_equal:today'],
            'status' => ['nullable', 'in:enrolled,in_progress,completed,withdrawn,failed'],
            'is_mandatory' => ['nullable', 'boolean'],
            'enrolled_by' => [
                'nullable',
                'integer',
                Rule::exists('hr_employees', 'id')
                    ->where('shop_owner_id', $shopOwnerId)
            ],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'training_program_id.required' => 'Training program selection is required.',
            'training_program_id.exists' => 'The selected training program does not exist.',
            'employee_id.required' => 'Employee selection is required.',
            'employee_id.exists' => 'The selected employee does not exist or is not active.',
            'enrollment_date.before_or_equal' => 'Enrollment date cannot be in the future.',
            'status.in' => 'Invalid enrollment status.',
            'enrolled_by.exists' => 'The enrolling person does not exist.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'training_program_id' => 'training program',
            'employee_id' => 'employee',
            'enrollment_date' => 'enrollment date',
            'is_mandatory' => 'mandatory status',
            'enrolled_by' => 'enrolled by',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set shop_owner_id
        $this->merge([
            'shop_owner_id' => $this->user()->shop_owner_id,
        ]);

        // Set enrollment date to today if not provided
        if (!$this->has('enrollment_date')) {
            $this->merge(['enrollment_date' => now()->format('Y-m-d')]);
        }

        // Set default status to enrolled if not provided
        if (!$this->has('status')) {
            $this->merge(['status' => 'enrolled']);
        }

        // Set enrolled_by to current user's employee record if not provided
        if (!$this->has('enrolled_by')) {
            $enrolledBy = \App\Models\Employee::where('shop_owner_id', $this->user()->shop_owner_id)
                ->where('email', $this->user()->email)
                ->first();
            
            if ($enrolledBy) {
                $this->merge(['enrolled_by' => $enrolledBy->id]);
            }
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Check if employee is already enrolled
            if ($this->has('training_program_id') && $this->has('employee_id')) {
                $alreadyEnrolled = \App\Models\TrainingEnrollment::where('training_program_id', $this->training_program_id)
                    ->where('employee_id', $this->employee_id)
                    ->whereIn('status', ['enrolled', 'in_progress'])
                    ->exists();

                if ($alreadyEnrolled) {
                    $validator->errors()->add('employee_id', 
                        'This employee is already enrolled in this training program.'
                    );
                }
            }

            // Check training program capacity
            if ($this->has('training_program_id')) {
                $program = \App\Models\TrainingProgram::find($this->training_program_id);
                
                if ($program && $program->max_participants) {
                    $currentEnrollments = \App\Models\TrainingEnrollment::where('training_program_id', $this->training_program_id)
                        ->whereIn('status', ['enrolled', 'in_progress'])
                        ->count();

                    if ($currentEnrollments >= $program->max_participants) {
                        $validator->errors()->add('training_program_id', 
                            'This training program has reached maximum capacity (' . 
                            $program->max_participants . ' participants).'
                        );
                    }
                }

                // Check if training is in scheduled or active status
                if ($program && !in_array($program->status, ['scheduled', 'active'])) {
                    $validator->errors()->add('training_program_id', 
                        'Cannot enroll in a training program that is ' . $program->status . '.'
                    );
                }

                // Check if training hasn't ended
                if ($program && $program->end_date < now()) {
                    $validator->errors()->add('training_program_id', 
                        'Cannot enroll in a training program that has already ended.'
                    );
                }
            }

            // Validate manager authority (if not HR/shop_owner)
            if (!$this->user()->hasAnyRole(['HR', 'shop_owner'])) {
                $employee = \App\Models\Employee::find($this->employee_id);
                $manager = \App\Models\Employee::where('shop_owner_id', $this->user()->shop_owner_id)
                    ->where('email', $this->user()->email)
                    ->first();

                if ($employee && $manager && $employee->manager_id !== $manager->id) {
                    $validator->errors()->add('employee_id', 
                        'You can only enroll employees from your team.'
                    );
                }
            }
        });
    }
}
