<?php

namespace Plugin\AccessAudit\Services;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Plugin\AccessAudit\Models\AuditRule;

/**
 * AccessAudit 数据分析服务。
 *
 * 设计原则：
 * - 1h/24h 使用原始表实时聚合，保证刚发生的数据马上可见；
 * - 7d/30d 优先读取 audit_hourly_stats，避免反复扫描百万级访问日志；
 * - 高频用户 / 目标只扫描最近 24h 明细，避免 GROUP BY target 拖垮数据库；
 * - 长期聚合只保存小时级 node 汇总，不复制原始访问明细。
 */
class AnalyticsService
{
    private const RANGES = [
        '1h' => 3600,
        '24h' => 86400,
        '7d' => 604800,
        '30d' => 2592000,
    ];

    /** 定时任务入口：首次运行回填现有明细覆盖期，之后只重算最近两小时。 */
    public function aggregateRecent(): void
    {
        if (!Schema::hasTable('audit_hourly_stats')) {
            return;
        }

        $now = time();
        $curHour = intdiv($now, 3600) * 3600;

        try {
            $hasStats = DB::table('audit_hourly_stats')->exists();
            if (!$hasStats) {
                $cfg = ConfigCache::get();
                $logDays = max(1, (int) ($cfg['access_log_retention_days']['value'] ?? 3));
                // 首次回填最多 7 天，默认配置即 3 天。单次两条 GROUP BY，而非逐小时循环。
                $hours = min(168, $logDays * 24);
                $this->aggregateRange($curHour - $hours * 3600, $curHour + 3600);
                return;
            }

            // 重算当前小时 + 上一小时，覆盖延迟到达、跨小时批量上报。
            $this->aggregateRange($curHour - 3600, $curHour + 3600);
        } catch (\Throwable $e) {
            Log::warning('[AccessAudit] analytics 聚合失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量聚合一个时间范围。
     * 访问量来自 audit_access_logs；命中量来自 audit_reports，语义与面板现有统计一致。
     */
    public function aggregateRange(int $from, int $to): void
    {
        if ($to <= $from || !Schema::hasTable('audit_hourly_stats')) {
            return;
        }

        $logs = DB::table('audit_access_logs')
            ->selectRaw('FLOOR(created_at / 3600) * 3600 AS bucket_at, node_id, COUNT(*) AS events')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->groupBy('bucket_at', 'node_id')
            ->get();

        $reports = DB::table('audit_reports')
            ->selectRaw('FLOOR(created_at / 3600) * 3600 AS bucket_at, node_id, COUNT(*) AS matched')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->groupBy('bucket_at', 'node_id')
            ->get();

        $rows = [];
        foreach ($logs as $row) {
            $key = (int) $row->bucket_at . ':' . (int) $row->node_id;
            $rows[$key] = [
                'bucket_at' => (int) $row->bucket_at,
                'node_id' => (int) $row->node_id,
                'events' => (int) $row->events,
                'matched' => 0,
                'updated_at' => time(),
            ];
        }

        foreach ($reports as $row) {
            $key = (int) $row->bucket_at . ':' . (int) $row->node_id;
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'bucket_at' => (int) $row->bucket_at,
                    'node_id' => (int) $row->node_id,
                    'events' => 0,
                    'matched' => 0,
                    'updated_at' => time(),
                ];
            }
            $rows[$key]['matched'] = (int) $row->matched;
        }

        if (!$rows) {
            return;
        }

        DB::table('audit_hourly_stats')->upsert(
            array_values($rows),
            ['bucket_at', 'node_id'],
            ['events', 'matched', 'updated_at']
        );
    }

    public function dashboard(string $range = '24h', ?int $nodeId = null): array
    {
        $range = isset(self::RANGES[$range]) ? $range : '24h';
        $to = time();
        $from = $to - self::RANGES[$range];

        $isRealtime = in_array($range, ['1h', '24h'], true);
        $bucketSize = $range === '1h' ? 300 : ($range === '24h' ? 3600 : ($range === '7d' ? 21600 : 86400));

        if ($isRealtime) {
            $trend = $this->rawTrend($from, $to, $bucketSize, $nodeId);
            $nodes = $this->rawNodeRanking($from, $to, $nodeId);
            $trendSource = 'raw';
        } else {
            $trend = $this->hourlyTrend($from, $to, $bucketSize, $nodeId);
            $nodes = $this->hourlyNodeRanking($from, $to, $nodeId);
            $trendSource = 'aggregate';

            // 刚升级、聚合表还没有历史数据时，不返回“全 0 假象”，退回现有明细覆盖范围。
            if (!$trend) {
                $trend = $this->rawTrend($from, $to, $bucketSize, $nodeId);
                $nodes = $this->rawNodeRanking($from, $to, $nodeId);
                $trendSource = 'raw-fallback';
            }
        }

        $events = array_sum(array_column($trend, 'events'));
        $matched = array_sum(array_column($trend, 'matched'));
        // ban log 当前没有 node_id；指定节点时不能把全局封禁数冒充成节点封禁数。
        $bans = $nodeId ? null : $this->banCount($from, $to);

        $cfg = ConfigCache::get();
        $detailDays = max(1, (int) ($cfg['access_log_retention_days']['value'] ?? 3));
        // 用户/目标 GROUP BY 是最重的维度查询：最多扫 24h，保护线上数据库。
        $rankingFrom = max($from, $to - 86400, $to - $detailDays * 86400);

        $activeUsers = $this->activeUsers($rankingFrom, $to, $nodeId);
        $rules = $this->ruleRanking($from, $to, $nodeId);
        $topUsers = $this->topUsers($rankingFrom, $to, $nodeId);
        $topTargets = $this->topTargets($rankingFrom, $to, $nodeId);

        $coverageStart = null;
        if (Schema::hasTable('audit_hourly_stats')) {
            $q = DB::table('audit_hourly_stats');
            if ($nodeId) {
                $q->where('node_id', $nodeId);
            }
            $coverageStart = $q->min('bucket_at');
            $coverageStart = $coverageStart !== null ? (int) $coverageStart : null;
        }

        return [
            'range' => $range,
            'node_id' => $nodeId,
            'summary' => [
                'events' => (int) $events,
                'matched' => (int) $matched,
                'match_rate' => $events > 0 ? round($matched * 100 / $events, 2) : null,
                'bans' => $bans === null ? null : (int) $bans,
                // 为避免跨小时重复计数，活跃用户只对“排行明细窗口”做精确 DISTINCT。
                'active_users' => (int) $activeUsers,
            ],
            'trend' => $trend,
            'nodes' => $nodes,
            'rules' => $rules,
            'top_users' => $topUsers,
            'top_targets' => $topTargets,
            'coverage' => [
                'requested_from' => $from,
                'requested_to' => $to,
                'trend_source' => $trendSource,
                'aggregate_start' => $coverageStart,
                'ranking_from' => $rankingFrom,
                'detail_retention_days' => $detailDays,
                'ranking_limited_to_24h' => true,
            ],
        ];
    }

    private function rawTrend(int $from, int $to, int $bucketSize, ?int $nodeId): array
    {
        $logs = DB::table('audit_access_logs')
            ->selectRaw("FLOOR(created_at / {$bucketSize}) * {$bucketSize} AS bucket_at, COUNT(*) AS events")
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to);
        if ($nodeId) {
            $logs->where('node_id', $nodeId);
        }
        $logRows = $logs->groupBy('bucket_at')->pluck('events', 'bucket_at');

        $reports = DB::table('audit_reports')
            ->selectRaw("FLOOR(created_at / {$bucketSize}) * {$bucketSize} AS bucket_at, COUNT(*) AS matched")
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to);
        if ($nodeId) {
            $reports->where('node_id', $nodeId);
        }
        $reportRows = $reports->groupBy('bucket_at')->pluck('matched', 'bucket_at');

        return $this->fillTrend($from, $to, $bucketSize, $logRows->all(), $reportRows->all());
    }

    private function hourlyTrend(int $from, int $to, int $bucketSize, ?int $nodeId): array
    {
        if (!Schema::hasTable('audit_hourly_stats')) {
            return [];
        }

        $q = DB::table('audit_hourly_stats')
            ->selectRaw("FLOOR(bucket_at / {$bucketSize}) * {$bucketSize} AS bucket, SUM(events) AS events, SUM(matched) AS matched")
            ->where('bucket_at', '>=', $from)
            ->where('bucket_at', '<=', $to);
        if ($nodeId) {
            $q->where('node_id', $nodeId);
        }

        $rows = $q->groupBy('bucket')->orderBy('bucket')->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $events = [];
        $matched = [];
        foreach ($rows as $r) {
            $events[(int) $r->bucket] = (int) $r->events;
            $matched[(int) $r->bucket] = (int) $r->matched;
        }

        return $this->fillTrend($from, $to, $bucketSize, $events, $matched);
    }

    private function fillTrend(int $from, int $to, int $bucketSize, array $events, array $matched): array
    {
        $start = intdiv($from, $bucketSize) * $bucketSize;
        $end = intdiv($to, $bucketSize) * $bucketSize;
        $out = [];

        for ($bucket = $start; $bucket <= $end; $bucket += $bucketSize) {
            $e = (int) ($events[$bucket] ?? $events[(string) $bucket] ?? 0);
            $m = (int) ($matched[$bucket] ?? $matched[(string) $bucket] ?? 0);
            $out[] = [
                'time' => $bucket,
                'events' => $e,
                'matched' => $m,
                'match_rate' => $e > 0 ? round($m * 100 / $e, 2) : null,
            ];
        }
        return $out;
    }

    private function rawNodeRanking(int $from, int $to, ?int $nodeId): array
    {
        $logs = DB::table('audit_access_logs')
            ->selectRaw('node_id, COUNT(*) AS events')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to);
        if ($nodeId) {
            $logs->where('node_id', $nodeId);
        }
        $logRows = $logs->groupBy('node_id')->pluck('events', 'node_id');

        $reports = DB::table('audit_reports')
            ->selectRaw('node_id, COUNT(*) AS matched')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to);
        if ($nodeId) {
            $reports->where('node_id', $nodeId);
        }
        $reportRows = $reports->groupBy('node_id')->pluck('matched', 'node_id');

        return $this->mergeNodeRanking($logRows->all(), $reportRows->all());
    }

    private function hourlyNodeRanking(int $from, int $to, ?int $nodeId): array
    {
        if (!Schema::hasTable('audit_hourly_stats')) {
            return [];
        }
        $q = DB::table('audit_hourly_stats')
            ->selectRaw('node_id, SUM(events) AS events, SUM(matched) AS matched')
            ->where('bucket_at', '>=', $from)
            ->where('bucket_at', '<=', $to);
        if ($nodeId) {
            $q->where('node_id', $nodeId);
        }
        $rows = $q->groupBy('node_id')->get();

        $events = [];
        $matched = [];
        foreach ($rows as $r) {
            $events[(int) $r->node_id] = (int) $r->events;
            $matched[(int) $r->node_id] = (int) $r->matched;
        }
        return $this->mergeNodeRanking($events, $matched);
    }

    private function mergeNodeRanking(array $events, array $matched): array
    {
        $ids = array_values(array_unique(array_merge(
            array_map('intval', array_keys($events)),
            array_map('intval', array_keys($matched))
        )));
        if (!$ids) {
            return [];
        }

        $names = Server::query()->whereIn('id', $ids)->pluck('name', 'id');
        $out = [];
        foreach ($ids as $id) {
            $e = (int) ($events[$id] ?? $events[(string) $id] ?? 0);
            $m = (int) ($matched[$id] ?? $matched[(string) $id] ?? 0);
            $out[] = [
                'node_id' => $id,
                'node_name' => $names[$id] ?? "节点 #{$id}",
                'events' => $e,
                'matched' => $m,
                'match_rate' => $e > 0 ? round($m * 100 / $e, 2) : null,
            ];
        }
        usort($out, fn ($a, $b) => $b['events'] <=> $a['events']);
        return array_slice($out, 0, 20);
    }

    private function ruleRanking(int $from, int $to, ?int $nodeId): array
    {
        $q = DB::table('audit_reports')
            ->selectRaw('rule_id, COUNT(*) AS hits, COUNT(DISTINCT user_id) AS users')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to);
        if ($nodeId) {
            $q->where('node_id', $nodeId);
        }
        $rows = $q->groupBy('rule_id')->orderByDesc('hits')->limit(20)->get();

        $ruleIds = $rows->pluck('rule_id')->map(fn ($v) => (int) $v)->all();
        $names = $ruleIds ? AuditRule::query()->whereIn('id', $ruleIds)->pluck('name', 'id') : collect();

        $banCounts = [];
        if (!$nodeId && $ruleIds) {
            $banCounts = DB::table('audit_ban_logs')
                ->selectRaw('rule_id, COUNT(*) AS bans')
                ->where('action', 'ban')
                ->whereIn('rule_id', $ruleIds)
                ->where('created_at', '>=', $from)
                ->where('created_at', '<=', $to)
                ->groupBy('rule_id')
                ->pluck('bans', 'rule_id')
                ->all();
        }

        return $rows->map(fn ($r) => [
            'rule_id' => (int) $r->rule_id,
            'rule_name' => $names[$r->rule_id] ?? "规则 #{$r->rule_id}",
            'hits' => (int) $r->hits,
            'users' => (int) $r->users,
            // ban log 没有 node_id；指定节点时不能伪造节点级封禁数。
            'bans' => $nodeId ? null : (int) ($banCounts[$r->rule_id] ?? 0),
        ])->values()->all();
    }

    private function topUsers(int $from, int $to, ?int $nodeId): array
    {
        $q = DB::table('audit_access_logs')
            ->selectRaw('user_id, COUNT(*) AS events, SUM(CASE WHEN matched = 1 THEN 1 ELSE 0 END) AS matched')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to);
        if ($nodeId) {
            $q->where('node_id', $nodeId);
        }
        $rows = $q->groupBy('user_id')->orderByDesc('events')->limit(10)->get();
        $ids = $rows->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        $emails = $ids ? User::query()->whereIn('id', $ids)->pluck('email', 'id') : collect();

        return $rows->map(fn ($r) => [
            'user_id' => (int) $r->user_id,
            'user_email' => $emails[$r->user_id] ?? "用户 #{$r->user_id}",
            'events' => (int) $r->events,
            'matched' => (int) $r->matched,
        ])->values()->all();
    }

    private function topTargets(int $from, int $to, ?int $nodeId): array
    {
        $q = DB::table('audit_access_logs')
            ->selectRaw('target, COUNT(*) AS events, SUM(CASE WHEN matched = 1 THEN 1 ELSE 0 END) AS matched')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->where('target', '<>', '');
        if ($nodeId) {
            $q->where('node_id', $nodeId);
        }

        return $q->groupBy('target')
            ->orderByDesc('events')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'target' => (string) $r->target,
                'events' => (int) $r->events,
                'matched' => (int) $r->matched,
            ])->values()->all();
    }

    private function activeUsers(int $from, int $to, ?int $nodeId): int
    {
        $q = DB::table('audit_access_logs')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to);
        if ($nodeId) {
            $q->where('node_id', $nodeId);
        }
        return (int) $q->distinct()->count('user_id');
    }

    private function banCount(int $from, int $to): int
    {
        return (int) DB::table('audit_ban_logs')
            ->where('action', 'ban')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->count();
    }
}
