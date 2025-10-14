<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drip_bank_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('institution_id')->nullable()->index();
            $table->unsignedBigInteger('group_id')->nullable()->index();

            $table->string('name');
            $table->text('iban')->nullable();
            $table->char('iban_hash', 64)->nullable()->index();
            $table->string('bic')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('initial_balance', 16, 4)->default(0);
            $table->date('opened_at')->nullable();
            $table->date('closed_at')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('institution_id')->references('id')->on('drip_institutions')->nullOnDelete();
            $table->foreign('group_id')->references('id')->on('drip_bank_account_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_bank_accounts');
    }
};


