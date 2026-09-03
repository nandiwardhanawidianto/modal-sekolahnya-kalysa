<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_cost_histories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('hpp');
            $t->decimal('admin_percent',7,4)->nullable();
            $t->date('effective_from');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['product_id','effective_from']);
            $t->index('effective_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_cost_histories');
    }
};
