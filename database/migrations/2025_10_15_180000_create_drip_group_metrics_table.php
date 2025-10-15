<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drip_group_metrics', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('group_id')->index();
            $table->date('date')->index();

            $table->decimal('inflow', 16, 4)->default(0);
            $table->decimal('outflow', 16, 4)->default(0);
            $table->decimal('net', 16, 4)->default(0);
            $table->decimal('burn_rate_30d', 16, 4)->default(0);
            $table->integer('runway_days')->default(0);

            $table->json('top_categories')->nullable();
            $table->json('forecast_30d')->nullable();

            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('group_id')->references('id')->on('drip_bank_account_groups')->onDelete('cascade');
            $table->unique(['team_id', 'group_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_group_metrics');
    }
};


