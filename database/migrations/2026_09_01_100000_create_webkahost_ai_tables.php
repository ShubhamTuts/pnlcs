<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Webkahost AI credits, API keys and usage.
 *
 * Billing already has account credit (money). AI credits are a separate
 * wallet: a customer buys a pack, gets a key, and every gateway request
 * deducts tokens-as-credits. The two must never share a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('balance', 14, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('ai_credit_packs', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 120);
            $table->unsignedInteger('credits');
            $table->decimal('price', 10, 2);
            $table->boolean('featured')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('ai_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32); // purchase, usage, refund, bonus, grant
            $table->decimal('credits', 14, 4);
            $table->string('description', 255);
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->unsignedBigInteger('usage_event_id')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('prefix', 16)->index();
            $table->string('key_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_api_key_id')->nullable()->constrained('ai_api_keys')->nullOnDelete();
            $table->string('source', 32)->default('gateway'); // gateway, agent
            $table->string('model', 120);
            $table->string('provider', 64)->default('webkahost');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('credits_charged', 14, 4)->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('status', 24)->default('ok');
            $table->string('request_id', 64)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('ai_agent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16); // user, assistant, tool
            $table->text('content');
            $table->json('tool_calls')->nullable();
            $table->timestamps();
        });

        DB::table('ai_credit_packs')->insert([
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'credits' => 1000,
                'price' => 10.00,
                'featured' => false,
                'sort_order' => 10,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'builder',
                'name' => 'Builder',
                'credits' => 5000,
                'price' => 45.00,
                'featured' => true,
                'sort_order' => 20,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'scale',
                'name' => 'Scale',
                'credits' => 20000,
                'price' => 150.00,
                'featured' => false,
                'sort_order' => 30,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_messages');
        Schema::dropIfExists('ai_usage_events');
        Schema::dropIfExists('ai_api_keys');
        Schema::dropIfExists('ai_ledger_entries');
        Schema::dropIfExists('ai_credit_packs');
        Schema::dropIfExists('ai_wallets');
    }
};
