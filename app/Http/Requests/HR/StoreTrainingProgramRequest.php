<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTrainingProgramRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only HR and shop_owner can create training programs
        return $this->user()->hasAnyRole(['HR', 'shop_owner']);
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
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => [
                'required',
                'in:technical,soft_skills,compliance,leadership,orientation,product,safety,other'
            ],
            'mode' => ['required', 'in:in_person,online,hybrid'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'duration_hours' => ['nullable', 'numeric', 'min:0.5', 'max:1000'],
            'max_participants' => ['nullable', 'integer', 'min:1', 'max:1000'],
            
            // Trainer Information
            'trainer_name' => ['nullable', 'string', 'max:200'],
            'trainer_id' => [
                'nullable',
                'integer',
                Rule::exists('hr_employees', 'id')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->where('status', 'active')
            ],
            'trainer_email' => ['nullable', 'email', 'max:255'],
            'external_trainer' => ['nullable', 'boolean'],
            
            // Location
            'location' => ['nullable', 'string', 'max:200'],
            'venue' => ['nullable', 'string', 'max:200'],
            'online_platform' => ['nullable', 'string', 'max:100'],
            'meeting_link' => ['nullable', 'url', 'max:500'],
            
            // Cost
            'cost_per_participant' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'total_budget' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            
            // Status
            'status' => ['nullable', 'in:scheduled,active,completed,cancelled'],
            
            // Additional
            'prerequisites' => ['nullable', 'string', 'max:500'],
            'learning_objectives' => ['nullable', 'string', 'max:1000'],
            'materials_required' => ['nullable', 'string', 'max:500'],
            'certificate_awarded' => ['nullable', 'boolean'],
            'certificate_validity_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
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
            'title.required' => 'Training program title is required.',
            'type.required' => 'Training type is required.',
            'type.in' => 'Invalid training type selected.',
            'mode.required' => 'Training mode is required.',
            'mode.in' => 'Invalid training mode selected.',
            'start_date.required' => 'Start date is required.',
            'start_date.after_or_equal' => 'Start date cannot be in the past.',
            'end_date.required' => 'End date is required.',
            'end_date.after_or_equal' => 'End date must be on or after start date.',
            'duration_hours.min' => 'Minimum duration is 0.5 hours.',
            'max_participants.min' => 'Must allow at least 1 participant.',
            'trainer_id.exists' => 'The selected trainer does not exist or is not active.',
            'trainer_email.email' => 'Please provide a valid trainer email address.',
            'meeting_link.url' => 'Please provide a valid meeting link URL.',
            'cost_per_participant.min' => 'Cost cannot be negative.',
            'certificate_validity_months.max' => 'Certificate validity cannot exceed 120 months (10 years).',
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
            'title' => 'training title',
            'type' => 'training type',
            'mode' => 'training mode',
            'start_date' => 'start date',
            'end_date' => 'end date',
            'duration_hours' => 'duration',
            'max_participants' => 'maximum participants',
            'trainer_id' => 'internal trainer',
            'trainer_name' => 'trainer name',
            'trainer_email' => 'trainer email',
            'external_trainer' => 'external trainer',
            'online_platform' => 'online platform',
            'meeting_link' => 'meeting link',
            'cost_per_participant' => 'cost per participant',
            'total_budget' => 'total budget',
            'learning_objectives' => 'learning objectives',
            'materials_required' => 'materials required',
            'certificate_awarded' => 'certificate',
            'certificate_validity_months' => 'certificate validity',
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

        // Set default status to scheduled if not provided
        if (!$this->has('status')) {
            $this->merge(['status' => 'scheduled']);
        }

        // Calculate duration if not provided
        if (!$this->has('duration_hours') && $this->has('start_date') && $this->has('end_date')) {
            $start = \Carbon\Carbon::parse($this->start_date);
            $end = \Carbon\Carbon::parse($this->end_date);
            $this->merge(['duration_hours' => $start->diffInHours($end)]);
        }

        // Set external_trainer flag based on trainer_id
        if ($this->has('trainer_id') && !$this->has('external_trainer')) {
            $this->merge(['external_trainer' => false]);
        } elseif (!$this->has('trainer_id') && $this->has('trainer_name') && !$this->has('external_trainer')) {
            $this->merge(['external_trainer' => true]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Validate online mode requirements
            if ($this->mode === 'online' && !$this->has('meeting_link') && !$this->has('online_platform')) {
                $validator->errors()->add('meeting_link', 
                    'For online training, either meeting link or online platform is required.'
                );
            }

            // Validate in-person mode requirements
            if ($this->mode === 'in_person' && !$this->has('location') && !$this->has('venue')) {
                $validator->errors()->add('location', 
                    'For in-person training, either location or venue is required.'
                );
            }

            // Validate trainer information
            if (!$this->has('trainer_id') && !$this->has('trainer_name')) {
                $validator->errors()->add('trainer_id', 
                    'Either internal trainer or external trainer name is required.'
                );
            }

            // Validate certificate validity
            if ($this->certificate_awarded && !$this->has('certificate_validity_months')) {
                $validator->errors()->add('certificate_validity_months', 
                    'Certificate validity period is required when certificate is awarded.'
                );
            }

            // Validate budget calculations
            if ($this->has('cost_per_participant') && $this->has('max_participants') && $this->has('total_budget')) {
                $estimatedTotal = $this->cost_per_participant * $this->max_participants;
                if ($this->total_budget < $estimatedTotal) {
                    $validator->errors()->add('total_budget', 
                        'Total budget is less than estimated cost ('. 
                        number_format($estimatedTotal, 2) . ' based on cost per participant).'
                    );
                }
            }
        });
    }
}
