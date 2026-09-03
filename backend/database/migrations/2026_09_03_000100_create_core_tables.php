<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('stores', function(Blueprint $t){
   $t->id();$t->string('name',120);$t->string('shopee_username',120)->nullable();$t->string('shopee_shop_id',80)->nullable();$t->boolean('active')->default(true);$t->timestamps();$t->softDeletes();
   $t->unique('name');$t->index('shopee_username');
  });
  Schema::create('products', function(Blueprint $t){
   $t->id();$t->foreignId('store_id')->constrained()->cascadeOnDelete();$t->string('shopee_product_id',80);$t->text('name');$t->string('parent_sku',190)->nullable();$t->boolean('active')->default(true);$t->foreignId('last_import_batch_id')->nullable();$t->timestamps();$t->softDeletes();
   $t->unique(['store_id','shopee_product_id']);$t->index(['store_id','active']);
  });
  Schema::create('product_variants', function(Blueprint $t){
   $t->id();$t->foreignId('product_id')->constrained()->cascadeOnDelete();$t->foreignId('store_id')->constrained()->cascadeOnDelete();$t->string('shopee_variation_id',80);$t->text('name')->nullable();$t->string('sku',190)->nullable();$t->unsignedBigInteger('current_price')->default(0);$t->unsignedBigInteger('stock')->nullable();$t->unsignedInteger('minimum_purchase')->nullable();$t->boolean('active')->default(true);$t->foreignId('last_import_batch_id')->nullable();$t->timestamps();$t->softDeletes();
   $t->unique(['store_id','shopee_variation_id']);$t->index(['store_id','sku']);
  });
  Schema::create('variant_cost_histories', function(Blueprint $t){
   $t->id();$t->foreignId('product_variant_id')->constrained()->cascadeOnDelete();$t->unsignedBigInteger('hpp');$t->decimal('admin_percent',7,4)->nullable();$t->date('effective_from');$t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();$t->timestamps();
   $t->unique(['product_variant_id','effective_from']);$t->index('effective_from');
  });
  Schema::create('store_fee_histories', function(Blueprint $t){
   $t->id();$t->foreignId('store_id')->constrained()->cascadeOnDelete();$t->decimal('default_admin_percent',7,4)->default(0);$t->unsignedBigInteger('fixed_fee_per_order')->default(0);$t->date('effective_from');$t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();$t->timestamps();
   $t->unique(['store_id','effective_from']);
  });
  Schema::create('orders', function(Blueprint $t){
   $t->id();$t->foreignId('store_id')->constrained()->cascadeOnDelete();$t->string('order_number',80);$t->string('status',120)->nullable();$t->text('cancel_reason')->nullable();$t->string('return_status',190)->nullable();$t->dateTime('ordered_at')->nullable();$t->dateTime('paid_at')->nullable();$t->dateTime('completed_at')->nullable();$t->unsignedBigInteger('total_payment')->default(0);$t->unsignedInteger('total_qty')->default(0);$t->unsignedInteger('returned_qty')->default(0);$t->unsignedBigInteger('product_revenue')->default(0);$t->foreignId('last_import_batch_id')->nullable();$t->timestamps();$t->softDeletes();
   $t->unique(['store_id','order_number']);$t->index(['store_id','ordered_at']);$t->index(['store_id','status']);
  });
  Schema::create('order_items', function(Blueprint $t){
   $t->id();$t->foreignId('order_id')->constrained()->cascadeOnDelete();$t->foreignId('store_id')->constrained()->cascadeOnDelete();$t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();$t->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();$t->text('product_name');$t->text('variation_name')->nullable();$t->string('parent_sku',190)->nullable();$t->string('reference_sku',190)->nullable();$t->unsignedBigInteger('original_price')->default(0);$t->unsignedBigInteger('unit_price_after_discount')->default(0);$t->unsignedInteger('qty')->default(0);$t->unsignedInteger('returned_qty')->default(0);$t->unsignedBigInteger('subtotal')->default(0);$t->string('line_key',64);$t->foreignId('last_import_batch_id')->nullable();$t->timestamps();
   $t->unique(['order_id','line_key']);$t->index(['store_id','product_variant_id']);
  });
  Schema::create('settlements', function(Blueprint $t){
   $t->id();$t->foreignId('store_id')->constrained()->cascadeOnDelete();$t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();$t->string('order_number',80);$t->date('order_date')->nullable();$t->date('released_at')->nullable();$t->bigInteger('actual_income')->default(0);$t->bigInteger('product_price')->default(0);$t->bigInteger('buyer_refund')->default(0);$t->bigInteger('admin_fee')->default(0);$t->bigInteger('process_fee')->default(0);$t->bigInteger('transaction_fee')->default(0);$t->bigInteger('campaign_fee')->default(0);$t->bigInteger('seller_voucher')->default(0);$t->bigInteger('other_fee')->default(0);$t->foreignId('last_import_batch_id')->nullable();$t->timestamps();
   $t->unique(['store_id','order_number']);$t->index(['store_id','released_at']);
  });
  Schema::create('adjustments', function(Blueprint $t){
   $t->id();$t->foreignId('store_id')->constrained()->cascadeOnDelete();$t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();$t->string('order_number',80)->nullable();$t->date('adjustment_date');$t->string('type',255);$t->text('reason')->nullable();$t->bigInteger('amount');$t->date('released_at')->nullable();$t->string('fingerprint',64);$t->foreignId('last_import_batch_id')->nullable();$t->timestamps();
   $t->unique(['store_id','fingerprint']);$t->index(['store_id','adjustment_date']);$t->index(['store_id','order_number']);
  });
  Schema::create('ad_cost_periods', function(Blueprint $t){
   $t->id();$t->foreignId('store_id')->constrained()->cascadeOnDelete();$t->date('start_date');$t->date('end_date');$t->unsignedBigInteger('amount')->default(0);$t->string('source',30)->default('manual');$t->string('source_filename')->nullable();$t->string('source_hash',64)->nullable();$t->string('shopee_username',120)->nullable();$t->string('shopee_shop_id',80)->nullable();$t->json('breakdown')->nullable();$t->string('note',255)->nullable();$t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();$t->foreignId('last_import_batch_id')->nullable();$t->timestamps();
   $t->index(['store_id','start_date']);$t->index(['store_id','end_date']);$t->index(['store_id','source_hash']);
  });
 }
 public function down(): void {
  Schema::dropIfExists('ad_cost_periods');Schema::dropIfExists('adjustments');Schema::dropIfExists('settlements');Schema::dropIfExists('order_items');Schema::dropIfExists('orders');Schema::dropIfExists('store_fee_histories');Schema::dropIfExists('variant_cost_histories');Schema::dropIfExists('product_variants');Schema::dropIfExists('products');Schema::dropIfExists('stores');
 }
};
