<?php

namespace App\Models\HR;

use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class Shift extends Model
{
    use HasFactory;

    protected $table = 'hr_shifts';

    protected $fillable = [
        'shop_owner_id',
        'name',
        'code',
        'start_time',
        'end_time',
        'break_duration',
        'grace_period',
        'is_overnight',
        'is_active',
        'overtime_multiplier',
        'description',
    ];

    protected $casts = [
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'break_duration' => 'integer',
        'grace_period' => 'integer',
        'is_overnight' => 'boolean',
        'is_active' => 'boolean',
        'overtime_multiplier' => 'decimal:2',
    ];

    /**
     * Get the shop owner
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    /**
     * Get shift schedules
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(ShiftSchedule::class);
    }

    /**
     * Scope: Filter by shop owner
     */
    public function scopeForShopOwner(Builder $query, int $shopOwnerId): Builder
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    /**
     * Scope: Active shifts only
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Overnight shifts
     */
    public function scopeOvernight(Builder $query): Builder
    {
        return $query->where('is_overnight', true);
    }

    /**
     * Scope: By code
     */
    public function scopeByCode(Builder $query, string $code): Builder
    {
        return $query->where('code', $code);
    }

    /**
     * Calculate shift duration in hours
     */
    public function getDurationHours(): float
    {
        $start = Carbon::parse($this->start_time);
        $end = Carbon::parse($this->end_time);

        if ($this->is_overnight) {
            $end->addDay();
        }

        $totalMinutes = $end->diffInMinutes($start);
        $workingMinutes = $totalMinutes - $this->break_duration;

        return round($workingMinutes / 60, 2);
    }

    /**
     * Get formatted shift time
     */
    public function getFormattedTimeAttribute(): string
    {
        return Carbon::parse($this->start_time)->format('g:i A') . ' - ' . 
               Carbon::parse($this->end_time)->format('g:i A');
    }

    /**
     * Check if time falls within shift
     */
    public function isWithinShift(string $time): bool
    {
        $checkTime = Carbon::parse($time);
        $start = Carbon::parse($this->start_time)->subMinutes($this->grace_period);
        $end = Carbon::parse($this->end_time);

        if ($this->is_overnight) {
            $end->addDay();
        }

        return $checkTime->between($start, $end);
    }

    /**
     * Check if time is late
     */
    public function isLateCheckIn(string $time): bool
    {
        $checkTime = Carbon::parse($time);
        $allowedTime = Carbon::parse($this->start_time)->addMinutes($this->grace_period);

        return $checkTime->gt($allowedTime);
    }

    /**
     * Calculate overtime hours
     */
    public function calculateOvertime(string $checkIn, string $checkOut): float
    {
        $start = Carbon::parse($checkIn);
        $end = Carbon::parse($checkOut);

        $totalMinutes = $end->diffInMinutes($start);
        $workingMinutes = $totalMinutes - $this->break_duration;
        $actualHours = $workingMinutes / 60;

        $shiftDuration = $this->getDurationHours();

        $overtimeHours = max(0, $actualHours - $shiftDuration);

        return round($overtimeHours, 2);
    }

    /**
     * Get active shifts for dropdown
     */
    public static function getActiveOptions(int $shopOwnerId): array
    {
        return static::forShopOwner($shopOwnerId)
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn($shift) => [
                'value' => $shift->id,
                'label' => $shift->name . ' (' . $shift->formatted_time . ')',
                'code' => $shift->code,
            ])
            ->toArray();
    }
}
