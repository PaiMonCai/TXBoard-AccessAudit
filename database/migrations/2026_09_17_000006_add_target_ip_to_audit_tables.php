<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_access_logs') && !Schema::hasColumn('audit_access_logs', 'target_ip')) {
            Schema::table('audit_access_logs', function (Blueprint $table) {
                $table->string('target_ip', 45)->nullable()->after('target');
            });
        }

        if (Schema::hasTable('audit_reports') && !Schema::hasColumn('audit_reports', 'target_ip')) {
            Schema::table('audit_reports', function (Blueprint $table) {
                $table->string('target_ip', 45)->nullable()->after('target');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('audit_access_logs') && Schema::hasColumn('audit_access_logs', 'target_ip')) {
            Schema::table('audit_access_logs', function (Blueprint $table) {
                $table->dropColumn('target_ip');
            });
        }

        if (Schema::hasTable('audit_reports') && Schema::hasColumn('audit_reports', 'target_ip')) {
            Schema::table('audit_reports', function (Blueprint $table) {
                $table->dropColumn('target_ip');
            });
        }
    }
};
