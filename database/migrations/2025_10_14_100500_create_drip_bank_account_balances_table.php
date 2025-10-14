<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drip_bank_account_balances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('bank_account_id')->index();

            $table->string('balance_type'); // z. B. booked, available, expected
            $table->string('amount');
            $table->string('currency')->nullable();
            $table->timestamp('retrieved_at'); // Zeitpunkt der Abfrage
            
            // Legacy fields for compatibility
            $table->date('as_of_date')->index();
            $table->decimal('balance', 16, 4);

            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('bank_account_id')->references('id')->on('drip_bank_accounts')->onDelete('cascade');
            $table->unique(['bank_account_id', 'as_of_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_bank_account_balances');
    }
};


