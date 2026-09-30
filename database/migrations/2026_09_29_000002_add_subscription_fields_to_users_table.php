<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('user')->after('password');
            $table->foreignId('subscription_id')
                ->nullable()
                ->after('role')
                ->constrained('subscriptions')
                ->nullOnDelete();
            $table->timestamp('subscription_expires_at')->nullable()->after('subscription_id');
            $table->boolean('is_active')->default(true)->after('subscription_expires_at');
            $table->string('api_token_hash', 64)->nullable()->unique()->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('api_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['subscription_id']);
            $table->dropColumn([
                'role',
                'subscription_id',
                'subscription_expires_at',
                'is_active',
                'api_token_hash',
                'last_login_at',
            ]);
        });
    }
};
