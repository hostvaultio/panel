<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('backups', 'replaces_backup_uuid')) {
            Schema::table('backups', function (Blueprint $table) {
                // Backups are soft-deleted; retain the UUID for recovery/audit.
                $table->uuid('replaces_backup_uuid')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (DB::table('backups as candidate')
            ->join('backups as source', 'source.uuid', '=', 'candidate.replaces_backup_uuid')
            ->whereNull('candidate.deleted_at')->whereNull('source.deleted_at')->exists()) {
            throw new RuntimeException('Reconcile backup replacements before rollback.');
        }

        Schema::table('backups', function (Blueprint $table) {
            $table->dropIndex(['replaces_backup_uuid']);
            $table->dropColumn('replaces_backup_uuid');
        });
    }
};
