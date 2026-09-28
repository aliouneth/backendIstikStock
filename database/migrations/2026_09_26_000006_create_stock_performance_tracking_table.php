<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_performance_tracking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recommendation_id')->constrained('daily_recommendations')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('track_date');
            $table->decimal('close_price', 12, 4);
            $table->decimal('pct_change_since_recommendation', 10, 3)->nullable();
            $table->decimal('benchmark_pct_change', 10, 3)->nullable();
            $table->timestamps();

            $table->unique(['recommendation_id', 'track_date']);
            $table->index('track_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_performance_tracking');
    }
};
