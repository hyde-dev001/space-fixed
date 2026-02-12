<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PerformanceReviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => new EmployeeResource($this->whenLoaded('employee')),
            'employee_id' => $this->employee_id,
            'reviewer_id' => $this->reviewer_id,
            'reviewer' => new EmployeeResource($this->whenLoaded('reviewer')),
            'review_period_start' => $this->review_period_start?->format('Y-m-d'),
            'review_period_end' => $this->review_period_end?->format('Y-m-d'),
            'rating' => $this->rating,
            'comments' => $this->comments,
            'strengths' => $this->strengths,
            'areas_for_improvement' => $this->areas_for_improvement,
            'goals' => $this->goals,
            'status' => $this->status,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
