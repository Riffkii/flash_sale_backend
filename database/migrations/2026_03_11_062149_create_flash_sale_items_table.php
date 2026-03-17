<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('flash_sale_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('stock')->default(0);
            $table->decimal('price', 10, 2);
            $table->timestamps();
        });

        Schema::create('flash_sale_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('flash_sale_items')->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sale_orders');
        Schema::dropIfExists('flash_sale_items');
    }
};