<?php

namespace Database\Factories;

use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MaintenanceWindow> */
class MaintenanceWindowFactory extends Factory
{
    protected $model = MaintenanceWindow::class;

    public function definition(): array
    {
        return [
            'title' => 'Scheduled platform maintenance',
            'public_message' => 'SoleSpace will undergo scheduled maintenance.',
            'internal_note' => null,
            'status' => MaintenanceStatus::Draft,
            'starts_at' => null,
            'ends_at' => null,
            'activated_at' => null,
            'ended_at' => null,
            'cancelled_at' => null,
            'version' => 1,
            'notify_before_minutes' => 15,
            'transaction_freeze_minutes' => 3,
            'progress_stage' => null,
            'public_update_message' => null,
            'public_update_updated_at' => null,
            'created_by' => null,
            'updated_by' => null,
            'activated_by' => null,
            'ended_by' => null,
            'cancelled_by' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state([
            'status' => MaintenanceStatus::Draft,
            'starts_at' => null,
            'ends_at' => null,
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(function (): array {
            $startsAt = now()->addHour();

            return [
                'status' => MaintenanceStatus::Scheduled,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
            ];
        });
    }

    public function active(): static
    {
        return $this->state(function (): array {
            $startsAt = now()->subMinutes(30);

            return [
                'status' => MaintenanceStatus::Active,
                'starts_at' => $startsAt,
                'ends_at' => now()->addMinutes(30),
                'activated_at' => $startsAt,
            ];
        });
    }

    public function ended(): static
    {
        return $this->state(function (): array {
            $endsAt = now()->subHour();

            return [
                'status' => MaintenanceStatus::Ended,
                'starts_at' => $endsAt->copy()->subHour(),
                'ends_at' => $endsAt,
                'ended_at' => $endsAt,
            ];
        });
    }

    public function cancelled(): static
    {
        return $this->state(function (): array {
            $startsAt = now()->addHour();

            return [
                'status' => MaintenanceStatus::Cancelled,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
                'cancelled_at' => now(),
            ];
        });
    }
}
