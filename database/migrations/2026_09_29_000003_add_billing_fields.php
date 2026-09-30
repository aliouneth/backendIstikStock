<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->decimal('annual_price', 10, 2)->nullable()->after('price');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(false)->after('subscription_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('auto_renew');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('annual_price');
        });
    }
};
