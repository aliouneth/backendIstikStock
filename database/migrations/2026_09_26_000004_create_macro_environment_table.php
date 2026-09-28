<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('macro_environment', function (Blueprint $table) {
            $table->id();
            $table->date('observation_date')->unique();
            $table->decimal('fed_funds_rate', 6, 3)->nullable();
            $table->decimal('cpi_yoy', 6, 3)->nullable();
            $table->decimal('sp500_close', 12, 2)->nullable();
            $table->decimal('nasdaq_close', 12, 2)->nullable();
            $table->decimal('russell2000_close', 12, 2)->nullable();
            $table->decimal('vix_close', 8, 2)->nullable();
            $table->json('sector_sentiment')->nullable();
            $table->string('source', 32)->default('synthetic');
            $table->timestamps();

            $table->index('observation_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('macro_environment');
    }
};
