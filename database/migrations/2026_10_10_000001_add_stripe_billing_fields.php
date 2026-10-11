<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->unique()->after('auto_renew');
            $table->string('stripe_subscription_id')->nullable()->index()->after('stripe_customer_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('stripe_product_id')->nullable()->after('annual_price');
        });

        Schema::table('subscription_checkouts', function (Blueprint $table) {
            $table->string('stripe_subscription_id')->nullable()->unique()->after('provider_reference');
        });

        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('type');
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');

        Schema::table('subscription_checkouts', function (Blueprint $table) {
            $table->dropUnique(['stripe_subscription_id']);
            $table->dropColumn('stripe_subscription_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('stripe_product_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['stripe_customer_id']);
            $table->dropIndex(['stripe_subscription_id']);
            $table->dropColumn(['stripe_customer_id', 'stripe_subscription_id']);
        });
    }
};
