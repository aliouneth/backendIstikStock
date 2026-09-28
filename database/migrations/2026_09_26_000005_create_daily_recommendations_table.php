<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_recommendations', function (Blueprint $table) {
            $table->id();
            $table->date('recommendation_date');
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rank');
            $table->decimal('model_score', 10, 4);
            $table->decimal('price_at_recommendation', 12, 4);
            $table->text('rationale');
            $table->json('features')->nullable();
            $table->string('model_version', 64);
            $table->timestamps();

            $table->unique(['recommendation_date', 'stock_id']);
            $table->index('recommendation_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_recommendations');
    }
};
