<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drip_finance_metrics', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();

            $table->date('date')->index();
            $table->decimal('income', 16, 4)->default(0);
            $table->decimal('expenses', 16, 4)->default(0);
            $table->decimal('savings', 16, 4)->default(0);
            $table->decimal('balance', 16, 4)->default(0);
            $table->json('extras')->nullable();

            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->unique(['team_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_finance_metrics');
    }
};


