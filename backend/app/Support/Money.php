<?php
namespace App\Support;
final class Money
{
    public static function rupiah(mixed $value): int
    {
        if ($value === null || $value === '' || $value === '-') return 0;
        if (is_int($value)) return $value;
        if (is_float($value)) return (int) round($value);
        $s = trim((string) $value);
        $negative = str_starts_with($s, '-');
        $digits = preg_replace('/[^0-9]/', '', $s) ?? '0';
        $amount = $digits === '' ? 0 : (int) $digits;
        return $negative ? -$amount : $amount;
    }
}
