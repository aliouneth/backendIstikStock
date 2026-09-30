<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_checkouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            // Snapshot of the plan at purchase time, so later admin edits to the
            // plan never rewrite what a customer was actually charged.
            $table->string('plan_name');
            $table->string('billing_period', 16);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('usd');
            $table->boolean('auto_renew')->default(false);

            $table->string('status', 24)->default('pending')->index();
            $table->string('driver', 24)->default('manual');
            $table->string('provider_reference')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_checkouts');
    }
};
