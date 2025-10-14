<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drip_go_cardless_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->text('access_token');
            $table->char('access_token_hash', 64)->nullable()->index();
            $table->text('refresh_token')->nullable();
            $table->char('refresh_token_hash', 64)->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->json('scopes')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['team_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_go_cardless_tokens');
    }
};


