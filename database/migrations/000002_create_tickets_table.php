<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('total_stock')->comment('初始總庫存');
            $table->integer('current_stock')->comment('當前庫存 (僅用於參考，實際以 Redis 為準)');
            $table->decimal('price', 8, 2);
            $table->dateTime('start_time')->comment('搶票開始時間');
            $table->dateTime('end_time')->comment('搶票結束時間');
            $table->integer('timeout_minutes')->default(15)->comment('支付超時時間，單位：分鐘');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
