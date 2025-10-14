<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drip_go_cardless_tokens', function (Blueprint $table) {
            $table->text('access_token')->change();
            $table->char('access_token_hash', 64)->nullable()->index();
            $table->text('refresh_token')->nullable()->change();
            $table->char('refresh_token_hash', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('drip_go_cardless_tokens', function (Blueprint $table) {
            $table->string('access_token')->change();
            $table->dropColumn('access_token_hash');
            $table->string('refresh_token')->nullable()->change();
            $table->dropColumn('refresh_token_hash');
        });
    }
};


