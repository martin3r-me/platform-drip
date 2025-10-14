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

            // GoCardless specific fields
            $table->string('external_id')->nullable()->index();
            $table->string('reference')->nullable()->index();
            $table->unsignedBigInteger('institution_id')->nullable()->index();
            $table->string('status', 32)->default('pending')->index();
            $table->string('redirect')->nullable();
            $table->json('accounts')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('access_expires_at')->nullable(); // Wann läuft der Zugriff ab
            $table->timestamp('last_sync_at')->nullable(); // Letztes erfolgreiches Update

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('institution_id')->references('id')->on('drip_institutions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_requisitions');
    }
};


