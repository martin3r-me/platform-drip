<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drip_bank_transaction_categories', function (Blueprint $table) {
            $table->decimal('default_tax_rate', 5, 2)->nullable()->after('direction');
        });
    }

    public function down(): void
    {
        Schema::table('drip_bank_transaction_categories', function (Blueprint $table) {
            $table->dropColumn('default_tax_rate');
        });
    }
};
