<?php

namespace Plugin\AccessAudit;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;
use Plugin\AccessAudit\Services\AnalyticsService;
use Plugin\AccessAudit\Services\AuditProcessor;
use Plugin\AccessAudit\Services\ConfigCache;
use Plugin\AccessAudit\Services\NodeHealthMonitor;
use Plugin\AccessAudit\Services\RuleMatcher;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        // 本插件以接收节点上报 + 管理员手动操作为主，暂不注册核心 hook
    }

    public function install(): void
    {
        // 迁移由 PluginManager 自动执行
    }

    public function cleanup(): void
    {
        // 预留
    }

    public function update(string $oldVersion, string $newVersion): void
    {
        AuditProcessor::flushConfigCache();
        RuleMatcher::flushCache();
    }

    public function schedule(Schedule $schedule): void
    {
        // 每个任务执行时读取最新配置，避免 schedule:work 长驻进程继续使用旧设置。
        $schedule->call(function () {
            $cfg = ConfigCache::get();
            $days = (int) ($cfg['report_retention_days']['value'] ?? 30);
            if ($days > 0) {
                self::purgeBefore('audit_reports', time() - $days * 86400);
            }
        })->name('access-audit:purge')->daily()->onOneServer()->withoutOverlapping(5);

        $schedule->call(function () {
            $cfg = ConfigCache::get();
            $days = (int) ($cfg['access_log_retention_days']['value'] ?? 3);
            if ($days > 0) {
                self::purgeBefore('audit_access_logs', time() - $days * 86400);
            }
        })->name('access-audit:purge-logs')->daily()->onOneServer()->withoutOverlapping(5);

        $schedule->call(function () {
            $cfg = ConfigCache::get();
            if ((int) ($cfg['analytics_enabled']['value'] ?? 1) === 1) {
                (new AnalyticsService())->aggregateRecent();
            }
        })->name('access-audit:analytics-rollup')->everyFiveMinutes()->onOneServer()->withoutOverlapping(4);

        $schedule->call(function () {
            $cfg = ConfigCache::get();
            $days = (int) ($cfg['analytics_retention_days']['value'] ?? 365);
            if ($days > 0) {
                self::purgeBefore('audit_hourly_stats', time() - $days * 86400, 'bucket_at');
            }
        })->name('access-audit:purge-analytics')->daily()->onOneServer()->withoutOverlapping(5);

        $schedule->call(function () {
            (new NodeHealthMonitor())->run();
        })->name('access-audit:node-health')->everyMinute()->onOneServer()->withoutOverlapping(2);
    }

    private const PURGE_BATCH = 2000;

    private static function purgeBefore(string $table, int $cutoff, string $timeColumn = 'created_at'): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        $deleted = 0;
        do {
            $n = \Illuminate\Support\Facades\DB::table($table)
                ->where($timeColumn, '<', $cutoff)
                ->limit(self::PURGE_BATCH)
                ->delete();
            $deleted += $n;
            if ($n >= self::PURGE_BATCH) {
                usleep(100000);
            }
        } while ($n >= self::PURGE_BATCH);

        if ($deleted > 0) {
            \Illuminate\Support\Facades\Log::info('[AccessAudit] 清理完成', [
                'table' => $table,
                'deleted' => $deleted,
            ]);
        }
    }
}
