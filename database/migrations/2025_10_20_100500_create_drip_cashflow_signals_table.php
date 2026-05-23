<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drip_cashflow_signals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->string('provider_key', 64);
            $table->string('external_id', 255);
            $table->string('label', 500);
            $table->string('direction', 10); // credit|debit
            $table->decimal('amount', 14, 2);
            $table->decimal('override_amount', 14, 2)->nullable();
            $table->date('expected_date');
            $table->date('override_date')->nullable();
            $table->decimal('confidence', 3, 2)->default(1.0); // 0.00 - 1.00
            $table->string('confidence_level', 20)->default('expected'); // confirmed|expected|speculative
            $table->string('counterparty', 255)->nullable();
            $table->string('category', 255)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('status', 20)->default('active'); // active|resolved|dismissed|pinned
            $table->unsignedBigInteger('budget_item_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'provider_key', 'external_id'], 'drip_signals_team_provider_external');
            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'expected_date']);

            $table->foreign('budget_item_id')
                ->references('id')
                ->on('drip_budget_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_cashflow_signals');
    }
};
