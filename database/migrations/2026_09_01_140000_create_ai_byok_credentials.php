<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Bring-your-own-key: the customer stores an upstream LLM key. Inference
 * then bypasses the Webkahost credit wallet (unlimited for that account).
 * The key is encrypted at rest and never mixed with wk_live_ gateway keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_byok_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->default('openai');
            $table->string('base_url', 255)->nullable();
            $table->text('api_key');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_byok_credentials');
    }
};
