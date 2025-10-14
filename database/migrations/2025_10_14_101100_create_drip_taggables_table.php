<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drip_taggables', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('tag_id')->index();
            $table->string('taggable_type');
            $table->unsignedBigInteger('taggable_id');
            $table->timestamps();

            $table->index(['taggable_type', 'taggable_id']);
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            // Hinweis: tag_id referenziert separate Tags-Tabelle, falls vorhanden
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_taggables');
    }
};


