<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('audit_rules')) {
            Schema::create('audit_rules', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name');
                $table->string('match_type', 20)->default('domain_suffix'); // domain, domain_suffix, keyword, ip_cidr
                $table->text('match_value');
                $table->tinyInteger('enabled')->default(1);
                $table->integer('threshold')->nullable();       // null = 用全局默认
                $table->integer('window_minutes')->nullable();  // null = 用全局默认
                $table->string('remark')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->index(['enabled', 'match_type'], 'idx_enabled_type');
            });
        }

        if (!Schema::hasTable('audit_reports')) {
            Schema::create('audit_reports', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('user_id');
                $table->integer('rule_id');
                $table->integer('node_id')->default(0);
                $table->string('target')->default('');
                $table->string('source_ip', 45)->nullable();
                $table->tinyInteger('banned')->default(0);       // 本次上报是否触发了封禁
                $table->integer('created_at');
                $table->index(['user_id', 'created_at'], 'idx_user_time');
                $table->index('created_at', 'idx_created');
            });
        }

        if (!Schema::hasTable('audit_ban_logs')) {
            Schema::create('audit_ban_logs', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->string('user_email')->default('');
                $table->integer('rule_id');
                $table->string('rule_name')->default('');
                $table->integer('hit_count')->default(0);
                $table->integer('threshold')->default(0);
                $table->integer('window_minutes')->default(0);
                $table->integer('operator_id')->default(0);      // 0 = 自动
                $table->string('action', 10)->default('ban');    // ban / unban
                $table->string('reason')->nullable();
                $table->integer('created_at');
                $table->index(['user_id', 'created_at'], 'idx_user_time');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_ban_logs');
        Schema::dropIfExists('audit_reports');
        Schema::dropIfExists('audit_rules');
    }
};
