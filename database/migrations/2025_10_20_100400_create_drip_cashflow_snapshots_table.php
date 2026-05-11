<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drip_cashflow_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('bank_account_id')->default(0);
            $table->unsignedBigInteger('category_id')->default(0);
            $table->string('counterparty_hash', 64)->default('');
            $table->string('direction', 10);
            $table->string('period_type', 10);
            $table->string('period_key', 10);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->unsignedInteger('transaction_count')->default(0);
            $table->datetime('computed_at');

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');

            $table->unique(
                ['team_id', 'bank_account_id', 'category_id', 'counterparty_hash', 'direction', 'period_type', 'period_key'],
                'drip_cs_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_cashflow_snapshots');
    }
};
