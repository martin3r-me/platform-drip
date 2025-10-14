<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drip_bank_accounts', function (Blueprint $table) {
            // Entferne evtl. bestehende Indexe auf IBAN (VARCHAR) bevor wir auf TEXT wechseln
            foreach ([
                'drip_bank_accounts_iban_unique', // typischer Name
                'iban', // manchmal direkt Indexname
            ] as $indexName) {
                try { $table->dropIndex($indexName); } catch (\Throwable $e) {}
                try { $table->dropUnique($indexName); } catch (\Throwable $e) {}
            }

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


