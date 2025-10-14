<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drip_requisitions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->string('type', 32)->index(); // e.g. consent, data_sync
            $table->string('provider', 64)->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->json('payload')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['team_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_requisitions');
    }
};


