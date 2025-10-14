<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drip_internal_transfers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('from_account_id')->index();
            $table->unsignedBigInteger('to_account_id')->index();
            $table->unsignedBigInteger('source_transaction_id')->nullable()->index();
            $table->unsignedBigInteger('target_transaction_id')->nullable()->index();

            $table->date('transferred_at')->index();
            $table->decimal('amount', 16, 4);
            $table->string('currency', 3)->default('EUR');
            $table->string('reference')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('from_account_id')->references('id')->on('drip_bank_accounts')->onDelete('cascade');
            $table->foreign('to_account_id')->references('id')->on('drip_bank_accounts')->onDelete('cascade');
            $table->foreign('source_transaction_id')->references('id')->on('drip_bank_transactions')->nullOnDelete();
            $table->foreign('target_transaction_id')->references('id')->on('drip_bank_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_internal_transfers');
    }
};


