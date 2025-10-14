<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drip_bank_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('bank_account_id')->index();
            $table->unsignedBigInteger('category_id')->nullable()->index();

            $table->date('booked_at')->index();
            $table->string('counterparty_name')->nullable();
            $table->string('counterparty_iban')->nullable();
            $table->string('reference')->nullable();
            $table->decimal('amount', 16, 4);
            $table->string('currency', 3)->default('EUR');
            $table->string('direction', 8)->index(); // credit|debit
            $table->string('status', 16)->default('booked')->index(); // booked|pending
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('bank_account_id')->references('id')->on('drip_bank_accounts')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('drip_bank_transaction_categories')->nullOnDelete();
            $table->index(['team_id', 'booked_at']);
            $table->index(['team_id', 'amount']);
            $table->index(['team_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_bank_transactions');
    }
};


