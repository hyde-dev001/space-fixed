<?php

namespace App\Support;

final class PayrollMoney
{
    public static function add(mixed ...$values): string
    {
        $total = '0';

        foreach ($values as $value) {
            $total = bcadd($total, self::number($value), 8);
        }

        return self::round($total);
    }

    public static function subtract(mixed $left, mixed $right): string
    {
        return self::round(bcsub(self::number($left), self::number($right), 8));
    }

    public static function multiply(mixed $left, mixed $right): string
    {
        return self::round(bcmul(self::number($left), self::number($right), 8));
    }

    public static function divide(mixed $left, mixed $right, int $scale = 8): string
    {
        if (self::compare($right, 0) === 0) {
            return '0.00000000';
        }

        return bcdiv(self::number($left), self::number($right), $scale);
    }

    public static function percent(mixed $amount, mixed $rate): string
    {
        return self::multiply($amount, bcdiv(self::number($rate), '100', 8));
    }

    public static function compare(mixed $left, mixed $right): int
    {
        return bccomp(self::number($left), self::number($right), 2);
    }

    public static function maxZero(mixed $amount): string
    {
        return self::compare($amount, 0) < 0 ? '0.00' : self::round($amount);
    }

    public static function round(mixed $amount): string
    {
        $number = self::number($amount);

        return bccomp($number, '0', 8) < 0
            ? bcsub($number, '0.005', 2)
            : bcadd($number, '0.005', 2);
    }

    private static function number(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_float($value)) {
            return number_format($value, 8, '.', '');
        }

        return trim((string) $value);
    }
}
