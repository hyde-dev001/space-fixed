<?php

namespace Database\Factories\HR;

use App\Models\HR\AttendanceRecord;
use App\Models\Employee;
use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Carbon\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\HR\AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = AttendanceRecord::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $checkIn = Carbon::parse($this->faker->time('H:i', '09:00'));
        $checkOut = $checkIn->copy()->addHours($this->faker->numberBetween(7, 10));
        $workingHours = $checkOut->diffInHours($checkIn);

        return [
            'employee_id' => Employee::factory(),
            'shop_owner_id' => ShopOwner::factory(),
            'date' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'check_in_time' => $checkIn->format('H:i'),
            'check_out_time' => $checkOut->format('H:i'),
            'status' => $this->faker->randomElement(['present', 'absent', 'late', 'half_day']),
            'biometric_id' => $this->faker->optional()->uuid,
            'notes' => $this->faker->optional()->sentence,
            'working_hours' => $workingHours,
            'overtime_hours' => $this->faker->randomFloat(2, 0, 3),
        ];
    }

    /**
     * Indicate that the attendance record is for a present employee.
     */
    public function present(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'present',
        ]);
    }

    /**
     * Indicate that the attendance record is for an absent employee.
     */
    public function absent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'absent',
            'check_in_time' => null,
            'check_out_time' => null,
            'working_hours' => 0,
            'overtime_hours' => 0,
        ]);
    }

    /**
     * Indicate that the employee was late.
     */
    public function late(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'late',
        ]);
    }
}
