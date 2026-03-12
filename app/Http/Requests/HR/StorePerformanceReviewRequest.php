<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePerformanceReviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // HR, shop_owner, and managers can create performance reviews
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
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('hr_employees', 'id')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->where('status', 'active')
            ],
            'reviewer_id' => [
                'required',
                'integer',
                Rule::exists('hr_employees', 'id')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->where('status', 'active')
            ],
            'review_period_start' => ['required', 'date'],
            'review_period_end' => ['required', 'date', 'after_or_equal:review_period_start'],
            'review_date' => ['required', 'date', 'after_or_equal:review_period_end', 'before_or_equal:today'],
            
            // Ratings (1-5 scale)
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'quality_of_work' => ['nullable', 'integer', 'min:1', 'max:5'],
            'productivity' => ['nullable', 'integer', 'min:1', 'max:5'],
            'communication' => ['nullable', 'integer', 'min:1', 'max:5'],
            'teamwork' => ['nullable', 'integer', 'min:1', 'max:5'],
            'initiative' => ['nullable', 'integer', 'min:1', 'max:5'],
            'punctuality' => ['nullable', 'integer', 'min:1', 'max:5'],
            'leadership' => ['nullable', 'integer', 'min:1', 'max:5'],
            
            // Comments
            'strengths' => ['nullable', 'string', 'max:1000'],
            'areas_for_improvement' => ['nullable', 'string', 'max:1000'],
            'goals_achieved' => ['nullable', 'string', 'max:1000'],
            'goals_for_next_period' => ['nullable', 'string', 'max:1000'],
            'comments' => ['nullable', 'string', 'max:2000'],
            'employee_comments' => ['nullable', 'string', 'max:1000'],
            
            // Status
            'status' => ['nullable', 'in:draft,submitted,acknowledged,completed'],
            
            // Recommendations
            'promotion_recommended' => ['nullable', 'boolean'],
            'salary_increase_recommended' => ['nullable', 'boolean'],
            'training_recommended' => ['nullable', 'boolean'],
            'recommended_salary_increase_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
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
            'employee_id.required' => 'Employee selection is required.',
            'employee_id.exists' => 'The selected employee does not exist or is not active.',
            'reviewer_id.required' => 'Reviewer selection is required.',
            'reviewer_id.exists' => 'The selected reviewer does not exist or is not active.',
            'review_period_start.required' => 'Review period start date is required.',
            'review_period_end.required' => 'Review period end date is required.',
            'review_period_end.after_or_equal' => 'Review period end date must be on or after start date.',
            'review_date.required' => 'Review date is required.',
            'review_date.after_or_equal' => 'Review date must be on or after review period end date.',
            'review_date.before_or_equal' => 'Review date cannot be in the future.',
            'rating.required' => 'Overall rating is required.',
            'rating.min' => 'Rating must be at least 1.',
            'rating.max' => 'Rating cannot exceed 5.',
            'quality_of_work.between' => 'Quality of work rating must be between 1 and 5.',
            'productivity.between' => 'Productivity rating must be between 1 and 5.',
            'communication.between' => 'Communication rating must be between 1 and 5.',
            'teamwork.between' => 'Teamwork rating must be between 1 and 5.',
            'recommended_salary_increase_percentage.max' => 'Recommended salary increase cannot exceed 100%.',
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
            'employee_id' => 'employee',
            'reviewer_id' => 'reviewer',
            'review_period_start' => 'review period start',
            'review_period_end' => 'review period end',
            'review_date' => 'review date',
            'quality_of_work' => 'quality of work',
            'areas_for_improvement' => 'areas for improvement',
            'goals_achieved' => 'goals achieved',
            'goals_for_next_period' => 'goals for next period',
            'employee_comments' => 'employee comments',
            'promotion_recommended' => 'promotion recommendation',
            'salary_increase_recommended' => 'salary increase recommendation',
            'training_recommended' => 'training recommendation',
            'recommended_salary_increase_percentage' => 'recommended salary increase percentage',
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

        // If reviewer not provided, use current user's employee record
        if (!$this->has('reviewer_id')) {
            $reviewer = \App\Models\Employee::where('shop_owner_id', $this->user()->shop_owner_id)
                ->where('email', $this->user()->email)
                ->first();
            
            if ($reviewer) {
                $this->merge(['reviewer_id' => $reviewer->id]);
            }
        }

        // Set default status to draft if not provided
        if (!$this->has('status')) {
            $this->merge(['status' => 'draft']);
        }

        // Set review date to today if not provided
        if (!$this->has('review_date')) {
            $this->merge(['review_date' => now()->format('Y-m-d')]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Validate reviewer has authority (is manager or HR)
            if ($this->has('employee_id') && $this->has('reviewer_id')) {
                $employee = \App\Models\Employee::find($this->employee_id);
                $reviewer = \App\Models\Employee::find($this->reviewer_id);

                // Check if reviewer is the employee's manager or if user has HR role
                if ($employee && $reviewer) {
                    $isManager = $employee->manager_id === $reviewer->id;
                    $isHR = $this->user()->hasAnyRole(['HR', 'shop_owner']);

                    if (!$isManager && !$isHR) {
                        $validator->errors()->add('reviewer_id', 
                            'Reviewer must be the employee\'s manager or have HR role.'
                        );
                    }
                }

                // Prevent self-review
                if ($this->employee_id === $this->reviewer_id) {
                    $validator->errors()->add('reviewer_id', 'Employees cannot review themselves.');
                }
            }

            // Check for duplicate reviews in the same period
            if ($this->has('employee_id') && $this->has('review_period_start') && $this->has('review_period_end')) {
                $exists = \App\Models\PerformanceReview::where('employee_id', $this->employee_id)
                    ->where('shop_owner_id', $this->user()->shop_owner_id)
                    ->where(function ($query) {
                        $query->whereBetween('review_period_start', [$this->review_period_start, $this->review_period_end])
                            ->orWhereBetween('review_period_end', [$this->review_period_start, $this->review_period_end])
                            ->orWhere(function ($q) {
                                $q->where('review_period_start', '<=', $this->review_period_start)
                                  ->where('review_period_end', '>=', $this->review_period_end);
                            });
                    })
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('review_period', 
                        'A performance review already exists for this employee in this time period.'
                    );
                }
            }
        });
    }
}
