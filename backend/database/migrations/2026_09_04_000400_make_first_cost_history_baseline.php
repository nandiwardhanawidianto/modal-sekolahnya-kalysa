<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BASELINE_DATE = '2000-01-01';

    public function up(): void
    {
        DB::transaction(function () {

            // Admin toko yang sudah pernah diinput.
            $storeIds = DB::table('store_fee_histories')
                ->distinct()
                ->pluck('store_id');

            foreach ($storeIds as $storeId) {

                $first = DB::table('store_fee_histories')
                    ->where('store_id', $storeId)
                    ->orderBy('effective_from')
                    ->orderBy('id')
                    ->first();

                if (
                    $first &&
                    $first->effective_from !== self::BASELINE_DATE
                ) {
                    DB::table('store_fee_histories')
                        ->where('id', $first->id)
                        ->update([
                            'effective_from' => self::BASELINE_DATE
                        ]);
                }
            }

            // HPP default produk yang sudah pernah diinput.
            $productIds = DB::table('product_cost_histories')
                ->distinct()
                ->pluck('product_id');

            foreach ($productIds as $productId) {

                $first = DB::table('product_cost_histories')
                    ->where('product_id', $productId)
                    ->orderBy('effective_from')
                    ->orderBy('id')
                    ->first();

                if (
                    $first &&
                    $first->effective_from !== self::BASELINE_DATE
                ) {
                    DB::table('product_cost_histories')
                        ->where('id', $first->id)
                        ->update([
                            'effective_from' => self::BASELINE_DATE
                        ]);
                }
            }

            // HPP override variasi yang sudah pernah diinput.
            $variantIds = DB::table('variant_cost_histories')
                ->distinct()
                ->pluck('product_variant_id');

            foreach ($variantIds as $variantId) {

                $first = DB::table('variant_cost_histories')
                    ->where('product_variant_id', $variantId)
                    ->orderBy('effective_from')
                    ->orderBy('id')
                    ->first();

                if (
                    $first &&
                    $first->effective_from !== self::BASELINE_DATE
                ) {
                    DB::table('variant_cost_histories')
                        ->where('id', $first->id)
                        ->update([
                            'effective_from' => self::BASELINE_DATE
                        ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Tidak dibalik otomatis karena tanggal asli pertama
        // tidak bisa direkonstruksi secara aman.
    }
};