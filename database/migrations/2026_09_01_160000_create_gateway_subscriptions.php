<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Gateway-native recurring billing (Razorpay Subscriptions, later others).
 *
 * PNLCS still owns the invoice. The gateway stores the mandate and charges
 * each cycle; we record the remote plan/subscription ids here so webhooks
 * can pay or raise the next invoice without double-billing the cron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_plans', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 40);
            $table->string('remote_id', 64);
            $table->string('name', 160);
            $table->string('period', 20);
            $table->unsignedTinyInteger('interval')->default(1);
            $table->unsignedInteger('amount_subunit');
            $table->string('currency', 8);
            $table->timestamps();

            $table->unique(['gateway', 'remote_id']);
            $table->unique(['gateway', 'period', 'interval', 'amount_subunit', 'currency', 'name'], 'gateway_plans_fingerprint');
        });

        Schema::create('gateway_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('ai_credit_pack_id')->nullable();
            $table->string('gateway', 40);
            $table->string('remote_plan_id', 64)->nullable();
            $table->string('remote_id', 64);
            $table->string('status', 40)->default('created');
            $table->string('period', 20)->nullable();
            $table->unsignedTinyInteger('interval')->default(1);
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 8)->nullable();
            $table->unsignedInteger('total_count')->default(120);
            $table->unsignedInteger('paid_count')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'remote_id']);
            $table->index(['service_id', 'status']);
            $table->index(['ai_credit_pack_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_subscriptions');
        Schema::dropIfExists('gateway_plans');
    }
};
