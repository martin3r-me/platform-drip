<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drip_requisitions', function (Blueprint $table) {
            // Add missing GoCardless fields if they don't exist
            if (!Schema::hasColumn('drip_requisitions', 'reference')) {
                $table->string('reference')->nullable()->index()->after('external_id');
            }
            if (!Schema::hasColumn('drip_requisitions', 'institution_id')) {
                $table->unsignedBigInteger('institution_id')->nullable()->index()->after('reference');
            }
            if (!Schema::hasColumn('drip_requisitions', 'redirect')) {
                $table->string('redirect')->nullable()->after('status');
            }
            if (!Schema::hasColumn('drip_requisitions', 'accounts')) {
                $table->json('accounts')->nullable()->after('redirect');
            }
            if (!Schema::hasColumn('drip_requisitions', 'linked_at')) {
                $table->timestamp('linked_at')->nullable()->after('accounts');
            }
        });

        // Add foreign key if it doesn't exist
        if (!Schema::hasColumn('drip_requisitions', 'institution_id')) {
            Schema::table('drip_requisitions', function (Blueprint $table) {
                $table->foreign('institution_id')->references('id')->on('drip_institutions')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('drip_requisitions', function (Blueprint $table) {
            $table->dropForeign(['institution_id']);
            $table->dropColumn(['reference', 'institution_id', 'redirect', 'accounts', 'linked_at']);
        });
    }
};
