<?php

namespace App\Support\Logistics;

use BackedEnum;
use Carbon\Carbon;
use DateTimeInterface;

final class BatchStopSnapshot
{
    public static function fromLegs(iterable $legs): array
    {
        return self::normalize(is_array($legs) ? $legs : iterator_to_array($legs));
    }

    public static function normalize(array $stops): array
    {
        usort($stops, fn ($a, $b) => [data_get($a, 'stop_sequence'), data_get($a, 'id')]
            <=> [data_get($b, 'stop_sequence'), data_get($b, 'id')]);

        return array_map(fn ($stop) => [
            'id' => data_get($stop, 'id'),
            'sequence' => data_get($stop, 'sequence'),
            'leg_type' => data_get($stop, 'leg_type'),
            'status' => self::enum(data_get($stop, 'status')),
            'origin_snapshot' => data_get($stop, 'origin_snapshot'),
            'destination_snapshot' => data_get($stop, 'destination_snapshot'),
            'scheduled_delivery_date' => self::date(data_get($stop, 'scheduled_delivery_date')),
            'delivery_window' => data_get($stop, 'delivery_window'),
            'schedule_status' => self::enum(data_get($stop, 'schedule_status')),
            'stop_sequence' => data_get($stop, 'stop_sequence'),
            'urgent_at' => self::timestamp(data_get($stop, 'urgent_at')),
            'shipment' => [
                'id' => data_get($stop, 'shipment.id'),
                'source_type' => data_get($stop, 'shipment.source_type'),
                'source_id' => data_get($stop, 'shipment.source_id'),
            ],
        ], $stops);
    }

    private static function enum(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    private static function date(mixed $value): ?string
    {
        return $value === null ? null : ($value instanceof DateTimeInterface ? $value->format('Y-m-d') : Carbon::parse($value)->toDateString());
    }

    private static function timestamp(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }
}
