<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * 补齐审计热路径缺失的索引。
 *
 * 背景：节点上报（ReportController）和节点健康监控（NodeHealthMonitor）
 * 都以 node_id + created_at 为过滤条件做窗口统计，
 * 但 audit_reports 只有 idx_user_time 与 idx_created，
 * 导致带 node_id 的查询退化为索引扫描 + 大量回表，连接被长时间占用。
 *
 * 同时优化 (user_id, rule_id, created_at)：
 * prefetchHitCounts 的 GROUP BY user_id, rule_id + created_at >= ? 走此索引可避免排序。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_reports')) {
            Schema::table('audit_reports', function (Blueprint $table) {
                if (!$this->hasIndex('audit_reports', 'idx_node_time')) {
                    $table->index(['node_id', 'created_at'], 'idx_node_time');
                }
                if (!$this->hasIndex('audit_reports', 'idx_user_rule_time')) {
                    $table->index(['user_id', 'rule_id', 'created_at'], 'idx_user_rule_time');
                }
            });
        }

        if (Schema::hasTable('audit_access_logs')) {
            Schema::table('audit_access_logs', function (Blueprint $table) {
                if (!$this->hasIndex('audit_access_logs', 'idx_matched_time')) {
                    $table->index(['matched', 'created_at'], 'idx_matched_time');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('audit_reports')) {
            Schema::table('audit_reports', function (Blueprint $table) {
                if ($this->hasIndex('audit_reports', 'idx_node_time')) {
                    $table->dropIndex('idx_node_time');
                }
                if ($this->hasIndex('audit_reports', 'idx_user_rule_time')) {
                    $table->dropIndex('idx_user_rule_time');
                }
            });
        }

        if (Schema::hasTable('audit_access_logs')) {
            Schema::table('audit_access_logs', function (Blueprint $table) {
                if ($this->hasIndex('audit_access_logs', 'idx_matched_time')) {
                    $table->dropIndex('idx_matched_time');
                }
            });
        }
    }

    /** 索引是否已存在（防止重复迁移/手工建索引后报错） */
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
