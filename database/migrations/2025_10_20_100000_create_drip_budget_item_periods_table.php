<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drip_budget_item_periods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('budget_item_id');

            $table->date('period_start');
            $table->date('period_end');
            $table->date('expected_date')->nullable();
            $table->decimal('planned_amount', 15, 2);
            $table->decimal('actual_amount', 15, 2)->default(0);
            $table->decimal('percent', 8, 1)->default(0);
            $table->string('status', 20)->default('pending');
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('budget_item_id')->references('id')->on('drip_budget_items')->onDelete('cascade');
            $table->unique(['budget_item_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_budget_item_periods');
    }
};
