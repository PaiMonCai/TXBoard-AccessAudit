<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AccessAudit v2.2 数据分析小时聚合表。
 *
 * 原始访问日志默认只保留 3 天；该表只保存 node/hour 级统计，
 * 让 7/30/365 天趋势无需长期保存全部连接明细。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('audit_hourly_stats')) {
            Schema::create('audit_hourly_stats', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('bucket_at');
                $table->integer('node_id');
                $table->unsignedBigInteger('events')->default(0);
                $table->unsignedBigInteger('matched')->default(0);
                $table->integer('updated_at');

                $table->unique(['bucket_at', 'node_id'], 'uniq_audit_hour_node');
                $table->index('bucket_at', 'idx_audit_hour_bucket');
                $table->index(['node_id', 'bucket_at'], 'idx_audit_hour_node_bucket');
            });
        }

        if (Schema::hasTable('audit_reports') && !$this->hasIndex('audit_reports', 'idx_rule_time')) {
            Schema::table('audit_reports', function (Blueprint $table) {
                $table->index(['rule_id', 'created_at'], 'idx_rule_time');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('audit_reports') && $this->hasIndex('audit_reports', 'idx_rule_time')) {
            Schema::table('audit_reports', function (Blueprint $table) {
                $table->dropIndex('idx_rule_time');
            });
        }
        Schema::dropIfExists('audit_hourly_stats');
    }

    private function hasIndex(string $table, string $index): bool
    {
        try {
            $indexes = Schema::getIndexes($table);
        } catch (\Throwable $e) {
            return false;
        }
        foreach ($indexes as $item) {
            if (($item['name'] ?? '') === $index) {
                return true;
            }
        }
        return false;
    }
};
