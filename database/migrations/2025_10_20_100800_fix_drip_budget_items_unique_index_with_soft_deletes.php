<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hard-delete any soft-deleted budget items to avoid conflicts
        DB::table('drip_budget_items')->whereNotNull('deleted_at')->delete();

        // Drop the old unique index that doesn't account for soft deletes
        Schema::table('drip_budget_items', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'name']);
        });

        // Create a partial unique index that only applies to non-deleted rows
        // MySQL doesn't support partial indexes, so we use a virtual column approach
        DB::statement('ALTER TABLE drip_budget_items ADD COLUMN unique_name_check VARCHAR(255) GENERATED ALWAYS AS (IF(deleted_at IS NULL, name, NULL)) STORED');
        DB::statement('ALTER TABLE drip_budget_items ADD UNIQUE INDEX drip_budget_items_team_id_name_soft_unique (team_id, unique_name_check)');
    }

    public function down(): void
    {
        Schema::table('drip_budget_items', function (Blueprint $table) {
            $table->dropIndex('drip_budget_items_team_id_name_soft_unique');
            $table->dropColumn('unique_name_check');
            $table->unique(['team_id', 'name']);
        });
    }
};
