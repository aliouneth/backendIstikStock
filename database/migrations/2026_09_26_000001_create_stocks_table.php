<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->string('ticker', 12)->unique();
            $table->string('name')->nullable();
            $table->string('sector')->nullable();
            $table->string('industry')->nullable();
            $table->string('exchange', 32)->nullable();
            $table->unsignedBigInteger('market_cap')->nullable();
            $table->unsignedBigInteger('float_shares')->nullable();
            $table->unsignedBigInteger('avg_volume')->nullable();
            $table->decimal('last_price', 12, 4)->nullable();
            $table->boolean('is_penny')->default(true);
            $table->string('source', 32)->default('synthetic');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['is_penny', 'sector']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
