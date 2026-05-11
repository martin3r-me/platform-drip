<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drip_budget_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('category_id')->nullable()->index();

            $table->string('name');
            $table->string('direction'); // debit|credit
            $table->decimal('amount', 15, 2);
            $table->string('frequency'); // monthly|quarterly|yearly|weekly
            $table->tinyInteger('day_of_month')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('category_id')->references('id')->on('drip_bank_transaction_categories')->nullOnDelete();
            $table->unique(['team_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_budget_items');
    }
};
