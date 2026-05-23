<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drip_bank_transaction_categories', function (Blueprint $table) {
            $table->string('direction', 10)->default('debit')->after('color');
        });

        // Backfill: set direction based on parent category name
        // Parent categories named "Einnahmen" and their children → credit
        // "Konzern-intern", "Bank & Finanzen" → both
        // Everything else → debit
        $creditParents = DB::table('drip_bank_transaction_categories')
            ->whereNull('parent_id')
            ->whereIn('name', ['Einnahmen'])
            ->pluck('id');

        $bothParents = DB::table('drip_bank_transaction_categories')
            ->whereNull('parent_id')
            ->whereIn('name', ['Konzern-intern', 'Bank & Finanzen'])
            ->pluck('id');

        if ($creditParents->isNotEmpty()) {
            // Parent itself
            DB::table('drip_bank_transaction_categories')
                ->whereIn('id', $creditParents)
                ->update(['direction' => 'credit']);

            // Children
            DB::table('drip_bank_transaction_categories')
                ->whereIn('parent_id', $creditParents)
                ->update(['direction' => 'credit']);
        }

        if ($bothParents->isNotEmpty()) {
            DB::table('drip_bank_transaction_categories')
                ->whereIn('id', $bothParents)
                ->update(['direction' => 'both']);

            DB::table('drip_bank_transaction_categories')
                ->whereIn('parent_id', $bothParents)
                ->update(['direction' => 'both']);
        }
    }

    public function down(): void
    {
        Schema::table('drip_bank_transaction_categories', function (Blueprint $table) {
            $table->dropColumn('direction');
        });
    }
};
