<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceRecordResource extends JsonResource
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
            'date' => $this->date?->format('Y-m-d'),
            'check_in_time' => $this->check_in_time?->format('H:i:s'),
            'check_out_time' => $this->check_out_time?->format('H:i:s'),
            'auto_clocked_out' => (bool) $this->auto_clocked_out,
            'auto_clockout_reason' => $this->auto_clockout_reason,
            'working_hours' => $this->working_hours,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
