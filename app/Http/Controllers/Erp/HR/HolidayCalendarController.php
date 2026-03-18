<?php

namespace App\Http\Controllers\ERP\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\HolidayCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * HolidayCalendarController
 *
 * Manages the per-shop holiday calendar that drives automatic rest-day /
 * special-holiday / regular-holiday hour classification in payroll.
 *
 * Endpoints
 *   GET    /api/hr/holidays              index  — list holidays (filterable by year)
 *   POST   /api/hr/holidays              store  — add a single holiday
 *   PUT    /api/hr/holidays/{id}         update — edit a holiday
 *   DELETE /api/hr/holidays/{id}         destroy — soft-delete a holiday (sets is_active=false)
 *   POST   /api/hr/holidays/sync-ph/{year}  — auto-seed Philippine public holidays for a year
 */
class HolidayCalendarController extends Controller
{
    // -------------------------------------------------------------------------
    // STATIC DATA: Philippine public holidays by month-day
    // Sources: RA 9492, RA 10966, executive proclamations (regularly updated)
    // -------------------------------------------------------------------------

    /**
     * Regular (legal) Philippine holidays (same every year unless moved by proclamation).
     * We use a fixed list as the baseline; the sync endpoint always upserts by
     * (shop_owner_id, holiday_date, holiday_name) so it is safe to re-run.
     */
    private const PH_REGULAR_HOLIDAYS = [
        ['month' => 1,  'day' => 1,  'name' => "New Year's Day"],
        ['month' => 4,  'day' => 9,  'name' => 'Araw ng Kagitingan (Day of Valor)'],
        ['month' => 5,  'day' => 1,  'name' => 'Labor Day'],
        ['month' => 6,  'day' => 12, 'name' => 'Independence Day'],
        ['month' => 8,  'day' => 25, 'name' => 'National Heroes Day'],   // Last Monday of August — approximated
        ['month' => 11, 'day' => 30, 'name' => 'Bonifacio Day'],
        ['month' => 12, 'day' => 25, 'name' => 'Christmas Day'],
        ['month' => 12, 'day' => 30, 'name' => "Rizal Day"],
    ];

    /**
     * Special non-working Philippine holidays (fixed-date subset).
     * Variable-date ones (Eid al-Fitr, Eid al-Adha, Maundy Thursday,
     * Good Friday, Black Saturday) are computed dynamically.
     */
    private const PH_SPECIAL_HOLIDAYS = [
        ['month' => 1,  'day' => 2,  'name' => 'Special Working Holiday (post-New Year)'],
        ['month' => 8,  'day' => 21, 'name' => "Ninoy Aquino Day"],
        ['month' => 11, 'day' => 1,  'name' => "All Saints' Day"],
        ['month' => 11, 'day' => 2,  'name' => "All Souls' Day"],
        ['month' => 12, 'day' => 8,  'name' => 'Feast of the Immaculate Conception'],
        ['month' => 12, 'day' => 24, 'name' => 'Christmas Eve'],
        ['month' => 12, 'day' => 31, "name" => "New Year's Eve"],
    ];

    // -------------------------------------------------------------------------
    // AUTH
    // -------------------------------------------------------------------------

    private function authorizeUser(): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        $user = Auth::guard('user')->user();
        if (
            ! $user->hasRole('Manager')
            && ! $user->can('access-payslip-generation')
        ) {
            return null;
        }
        return $user;
    }

    // -------------------------------------------------------------------------
    // INDEX
    // -------------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $year = (int) $request->get('year', now()->year);

        $holidays = HolidayCalendar::where('shop_owner_id', $user->shop_owner_id)
            ->whereYear('holiday_date', $year)
            ->orderBy('holiday_date')
            ->get()
            ->map(fn ($h) => [
                'id'             => $h->id,
                'holiday_date'   => $h->holiday_date->toDateString(),
                'holiday_name'   => $h->holiday_name,
                'holiday_type'   => $h->holiday_type,
                'is_paid'        => $h->is_paid,
                'rate_multiplier' => (float) $h->rate_multiplier,
                'is_active'      => $h->is_active,
            ]);

        return response()->json([
            'year'     => $year,
            'holidays' => $holidays,
            'count'    => $holidays->count(),
        ]);
    }

    // -------------------------------------------------------------------------
    // STORE
    // -------------------------------------------------------------------------

    public function store(Request $request): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'holiday_date'    => 'required|date',
            'holiday_name'    => 'required|string|max:150',
            'holiday_type'    => 'required|in:regular,special_non_working,special_working,local',
            'is_paid'         => 'sometimes|boolean',
            'rate_multiplier' => 'sometimes|numeric|min:1|max:5',
            'is_active'       => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $holiday = HolidayCalendar::create([
            'shop_owner_id'   => $user->shop_owner_id,
            'holiday_date'    => $request->holiday_date,
            'holiday_name'    => $request->holiday_name,
            'holiday_type'    => $request->holiday_type,
            'is_paid'         => $request->boolean('is_paid', true),
            'rate_multiplier' => (float) $request->get('rate_multiplier', $this->defaultMultiplier($request->holiday_type)),
            'is_active'       => $request->boolean('is_active', true),
        ]);

        return response()->json(['holiday' => $holiday, 'message' => 'Holiday added successfully'], 201);
    }

    // -------------------------------------------------------------------------
    // UPDATE
    // -------------------------------------------------------------------------

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $holiday = HolidayCalendar::where('shop_owner_id', $user->shop_owner_id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'holiday_date'    => 'sometimes|date',
            'holiday_name'    => 'sometimes|string|max:150',
            'holiday_type'    => 'sometimes|in:regular,special_non_working,special_working,local',
            'is_paid'         => 'sometimes|boolean',
            'rate_multiplier' => 'sometimes|numeric|min:1|max:5',
            'is_active'       => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $holiday->fill($request->only([
            'holiday_date', 'holiday_name', 'holiday_type',
            'is_paid', 'rate_multiplier', 'is_active',
        ]));
        $holiday->save();

        return response()->json(['holiday' => $holiday, 'message' => 'Holiday updated successfully']);
    }

    // -------------------------------------------------------------------------
    // DESTROY  (sets is_active = false — non-destructive)
    // -------------------------------------------------------------------------

    public function destroy(int $id): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $holiday = HolidayCalendar::where('shop_owner_id', $user->shop_owner_id)->findOrFail($id);
        $holiday->update(['is_active' => false]);

        return response()->json(['message' => 'Holiday deactivated']);
    }

    // -------------------------------------------------------------------------
    // SYNC PHILIPPINE HOLIDAYS
    // -------------------------------------------------------------------------

    /**
     * Auto-seed Philippine national and special holidays for a given year.
     * Uses upsert logic so running multiple times is safe.
     * Also computes variable-date holidays (Easter-based, Eid approximations).
     *
     * Returns a summary: { created, updated, skipped, holidays[] }
     */
    public function syncPhilippineHolidays(Request $request, int $year): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($year < 2020 || $year > 2035) {
            return response()->json(['error' => 'Year must be between 2020 and 2035'], 422);
        }

        $holidaysToSync = $this->buildPhHolidaysForYear($year);

        $created = 0;
        $updated = 0;
        $synced  = [];

        foreach ($holidaysToSync as $entry) {
            $existing = HolidayCalendar::where('shop_owner_id', $user->shop_owner_id)
                ->where('holiday_date', $entry['date'])
                ->where('holiday_name', $entry['name'])
                ->first();

            if ($existing) {
                // Only update is_active if it was previously deactivated by the user
                if (! $existing->is_active) {
                    $existing->update(['is_active' => true]);
                    $updated++;
                }
                $synced[] = array_merge($entry, ['action' => 'skipped']);
                continue;
            }

            HolidayCalendar::create([
                'shop_owner_id'   => $user->shop_owner_id,
                'holiday_date'    => $entry['date'],
                'holiday_name'    => $entry['name'],
                'holiday_type'    => $entry['type'],
                'is_paid'         => true,
                'rate_multiplier' => $this->defaultMultiplier($entry['type']),
                'is_active'       => true,
            ]);
            $created++;
            $synced[] = array_merge($entry, ['action' => 'created']);
        }

        return response()->json([
            'year'    => $year,
            'created' => $created,
            'updated' => $updated,
            'skipped' => count($synced) - $created - $updated,
            'message' => "Philippine holidays for {$year} synced successfully.",
            'holidays' => $synced,
        ]);
    }

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    /**
     * Build the full list of PH public holidays for a given year,
     * including variable-date ones (Maundy Thursday, Good Friday,
     * Black Saturday and Eid approximations).
     */
    private function buildPhHolidaysForYear(int $year): array
    {
        $list = [];

        // Fixed regular holidays
        foreach (self::PH_REGULAR_HOLIDAYS as $h) {
            $list[] = [
                'date' => "{$year}-" . str_pad($h['month'], 2, '0', STR_PAD_LEFT) . '-' . str_pad($h['day'], 2, '0', STR_PAD_LEFT),
                'name' => $h['name'],
                'type' => 'regular',
            ];
        }

        // National Heroes Day — last Monday of August
        $lastMonday = Carbon::create($year, 8, 31)->startOfDay();
        while (! $lastMonday->isMonday()) {
            $lastMonday->subDay();
        }
        // Replace the approximate 8-25 entry with the exact date
        $list = array_filter($list, fn ($e) => $e['name'] !== 'National Heroes Day');
        $list[] = ['date' => $lastMonday->toDateString(), 'name' => 'National Heroes Day', 'type' => 'regular'];

        // Fixed special non-working holidays
        foreach (self::PH_SPECIAL_HOLIDAYS as $h) {
            $list[] = [
                'date' => "{$year}-" . str_pad($h['month'], 2, '0', STR_PAD_LEFT) . '-' . str_pad($h['day'], 2, '0', STR_PAD_LEFT),
                'name' => $h['name'],
                'type' => 'special_non_working',
            ];
        }

        // Easter-based holidays (Maundy Thursday, Good Friday, Black Saturday)
        [$maundyThursday, $goodFriday, $blackSaturday] = $this->easterHolidays($year);
        $list[] = ['date' => $maundyThursday, 'name' => 'Maundy Thursday',  'type' => 'regular'];
        $list[] = ['date' => $goodFriday,     'name' => 'Good Friday',       'type' => 'regular'];
        $list[] = ['date' => $blackSaturday,  'name' => 'Black Saturday',    'type' => 'special_non_working'];

        // Eid al-Fitr and Eid al-Adha — approximate based on standard Islamic calendar
        // These are proclaimed annually; we provide an estimate and the user can adjust.
        [$eidAlFitr, $eidAlAdha] = $this->eidApproximations($year);
        $list[] = ['date' => $eidAlFitr, 'name' => 'Eid al-Fitr (approx.)', 'type' => 'regular'];
        $list[] = ['date' => $eidAlAdha, 'name' => 'Eid al-Adha (approx.)', 'type' => 'regular'];

        return array_values($list);
    }

    /**
     * Compute Maundy Thursday, Good Friday, and Black Saturday dates.
     * Uses the Butcher/Gregorian algorithm.
     */
    private function easterHolidays(int $year): array
    {
        // Gregorian Easter algorithm
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;

        $easter        = Carbon::create($year, $month, $day);
        $maundyThursday = $easter->copy()->subDays(3)->toDateString();
        $goodFriday    = $easter->copy()->subDays(2)->toDateString();
        $blackSaturday = $easter->copy()->subDays(1)->toDateString();

        return [$maundyThursday, $goodFriday, $blackSaturday];
    }

    /**
     * Approximate Eid al-Fitr and Eid al-Adha.
     * These are based on the Hijri calendar; dates shift ~11 days earlier each Gregorian year.
     * Reference baseline: Eid al-Fitr 2024-04-10, Eid al-Adha 2024-06-17.
     */
    private function eidApproximations(int $year): array
    {
        $baseYear  = 2024;
        $baseEidAlFitr = Carbon::create(2024, 4, 10);
        $baseEidAlAdha = Carbon::create(2024, 6, 17);

        $yearDiff  = $year - $baseYear;
        $daysShift = $yearDiff * -11; // Hijri year is ~354 days

        $eidAlFitr = $baseEidAlFitr->copy()->addDays($daysShift);
        $eidAlAdha = $baseEidAlAdha->copy()->addDays($daysShift);

        // Snap to same Gregorian year
        if ($eidAlFitr->year !== $year) {
            $eidAlFitr->addYear();
        }
        if ($eidAlAdha->year !== $year) {
            $eidAlAdha->addYear();
        }

        return [$eidAlFitr->toDateString(), $eidAlAdha->toDateString()];
    }

    private function defaultMultiplier(string $type): float
    {
        return match ($type) {
            'regular'              => 2.00,
            'special_non_working'  => 1.30,
            'special_working'      => 1.30,
            'local'                => 1.30,
            default                => 1.00,
        };
    }
}
