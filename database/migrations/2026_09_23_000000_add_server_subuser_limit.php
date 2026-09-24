<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Null preserves operator-managed servers. Hostvault supplies an
            // explicit allowance at provisioning and reconciliation.
            $table->unsignedInteger('subuser_limit')->nullable()->default(null);
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('subuser_limit');
        });
    }
};
