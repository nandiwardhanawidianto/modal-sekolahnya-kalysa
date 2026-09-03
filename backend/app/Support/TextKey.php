<?php
namespace App\Support;
use Illuminate\Support\Str;
final class TextKey
{
    public static function normalize(?string $value): string
    {
        $value = Str::lower(trim((string) $value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }
    public static function canonicalVariation(?string $value): string
    {
        $value = self::normalize($value);
        $value = preg_replace('/^p\s*\.?\s*/u', '', $value) ?? $value;
        $value = preg_replace('/\blaminasi\b/u', '', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
    public static function line(string $product, ?string $variation, ?string $sku, int $unitPrice): string
    {
        return hash('sha256', implode('|',[self::normalize($product),self::normalize($variation),self::normalize($sku),(string)$unitPrice]));
    }
}
