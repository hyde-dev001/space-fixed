<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollResource extends JsonResource
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
            'pay_period_start' => $this->pay_period_start?->format('Y-m-d'),
            'pay_period_end' => $this->pay_period_end?->format('Y-m-d'),
            'basic_salary' => number_format($this->basic_salary, 2),
            'allowances' => number_format($this->allowances, 2),
            'deductions' => number_format($this->deductions, 2),
            'gross_salary' => number_format($this->basic_salary + $this->allowances, 2),
            'net_salary' => number_format($this->net_salary, 2),
            'status' => $this->status,
            'payment_date' => $this->payment_date?->format('Y-m-d'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
