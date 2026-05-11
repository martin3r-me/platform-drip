<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drip_liquidity_forecasts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('team_id')->index();
            $table->date('forecast_date');
            $table->decimal('projected_balance', 15, 2);
            $table->decimal('planned_credits', 15, 2)->default(0);
            $table->decimal('planned_debits', 15, 2)->default(0);
            $table->timestamp('computed_at');

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->unique(['team_id', 'forecast_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_liquidity_forecasts');
    }
};
