<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->index('user_id');
        });

        Schema::create('watchlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watchlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['watchlist_id', 'stock_id']);
            $table->index('stock_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist_items');
        Schema::dropIfExists('watchlists');
    }
};
