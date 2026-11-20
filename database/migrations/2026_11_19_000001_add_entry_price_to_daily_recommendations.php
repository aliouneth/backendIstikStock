<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_recommendations', function (Blueprint $table) {
            $table->decimal('entry_price', 12, 4)->nullable()->after('price_at_recommendation');
        });
    }

    public function down(): void
    {
        Schema::table('daily_recommendations', function (Blueprint $table) {
            $table->dropColumn('entry_price');
        });
    }
};
