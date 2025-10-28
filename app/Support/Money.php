<?php

namespace App\Support;

final class Money
{
    // Half-up rounding with BCMath, returning string
    public static function round(string|int|float $v, int $scale = 2): string
    {
        if (!function_exists('bcadd')) {
            // Fallback – still return a string to keep types predictable
            return number_format((float) $v, $scale, '.', '');
        }

        $v = (string) $v;
        $scale = max(0, (int) $scale);

        $sign = (str_starts_with($v, '-')) ? '-' : '';
        $abs  = ltrim($v, '+-');

        // shift, add 0.5, floor, unshift  (half-up)
        $shift = bcpow('10', (string) $scale, $scale + 4);
        $shifted = bcmul($abs, $shift, $scale + 4);
        $shiftedPlus = bcadd($shifted, '0.5', 0);
        $rounded = bcdiv($shiftedPlus, $shift, $scale);

        return $sign === '-' ? bcsub('0', $rounded, $scale) : $rounded;
    }

    public static function r2(string|int|float $v): string { return self::round($v, 2); }
    public static function r4(string|int|float $v): string { return self::round($v, 4); }

    // helpers that keep everything as strings to avoid float casts
    public static function add(string|int|float $a, string|int|float $b, int $scale = 4): string
    { return function_exists('bcadd') ? bcadd((string)$a, (string)$b, $scale) : self::round(((float)$a)+((float)$b), $scale); }

    public static function sub(string|int|float $a, string|int|float $b, int $scale = 4): string
    { return function_exists('bcsub') ? bcsub((string)$a, (string)$b, $scale) : self::round(((float)$a)-((float)$b), $scale); }

    public static function mul(string|int|float $a, string|int|float $b, int $scale = 4): string
    { return function_exists('bcmul') ? bcmul((string)$a, (string)$b, $scale) : self::round(((float)$a)*((float)$b), $scale); }

    public static function div(string|int|float $a, string|int|float $b, int $scale = 4): string
    {
        if ((float)$b == 0.0) return self::round(0, $scale);
        return function_exists('bcdiv') ? bcdiv((string)$a, (string)$b, $scale) : self::round(((float)$a)/((float)$b), $scale);
    }
}
