<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drip_bank_accounts', function (Blueprint $table) {
            $table->text('iban')->nullable()->change();
            $table->char('iban_hash', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('drip_bank_accounts', function (Blueprint $table) {
            $table->string('iban')->nullable()->change();
            $table->dropColumn('iban_hash');
        });
    }
};


