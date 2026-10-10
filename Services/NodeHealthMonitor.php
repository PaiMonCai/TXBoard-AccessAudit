<?php

namespace Plugin\AccessAudit\Services;

use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Log;
use Plugin\AccessAudit\Models\AuditNodeStatus;
use Plugin\AccessAudit\Models\AuditReport;

/**
 * 节点级异常监控：
 *  1. 上报中断告警：节点曾经上报过，但超过 N 分钟无新上报
 *  2. 命中突增告警：最近窗口内命中数同比上一窗口增长超过 X%
 *
 * 由 Plugin::schedule() 每分钟调用一次。
 *
 * 性能约定（重要）：
 *   节点数可达上百，禁止「每节点 N 次 COUNT(*)」的写法。
 *   所有节点的窗口计数用【一条 GROUP BY 查询】取回后内存比对，
 *   配合 NodeHealthMonitor 的每分钟调度，SQL 次数从 O(节点数×2) 降为 O(1)。
 */
class NodeHealthMonitor
{
    private array $cfg;

    public function __construct()
    {
        $config = ConfigCache::get();
        $val = fn (string $key, $default) => $config[$key]['value'] ?? $default;
        $this->cfg = [
            'enabled' => (int) $val('node_health_enabled', 1) === 1,
            'offline_minutes' => max(1, (int) $val('node_offline_minutes', 10)),
            'offline_cooldown' => max(1, (int) $val('node_offline_cooldown', 3600)),
            'spike_enabled' => (int) $val('node_spike_enabled', 1) === 1,
            'spike_window' => max(5, (int) $val('node_spike_window', 30)),       // 分钟
            'spike_growth_pct' => max(10, (int) $val('node_spike_growth_pct', 200)), // 增长百分比
            'spike_min_hits' => max(3, (int) $val('node_spike_min_hits', 10)),   // 当前窗口至少这么多命中才判突增
            'spike_cooldown' => max(1, (int) $val('node_spike_cooldown', 1800)),
            'chat_id' => trim((string) $val('alert_chat_id', '')),
        ];
    }

    public function run(): void
    {
        if (!$this->cfg['enabled']) {
            return;
        }

        $now = time();
        $nodes = AuditNodeStatus::query()->get();
        if ($nodes->isEmpty()) {
            return;
        }

        // 一次性取回所有节点在「本窗口 / 上一窗口」的命中数，避免逐节点 COUNT(*)
        $spike = $this->cfg['spike_enabled'] ? $this->prefetchSpikeCounts($now) : [null, null];

        foreach ($nodes as $node) {
            $this->checkOffline($node, $now);
            if ($this->cfg['spike_enabled']) {
                $this->checkSpike($node, $now, $spike[0][$node->node_id] ?? 0, $spike[1][$node->node_id] ?? 0);
            }
        }
    }

    /**
     * 一条 GROUP BY 取回全部节点的命中计数。
     *
     * @return array{0: array<int,int>, 1: array<int,int>} [当前窗口, 上一窗口]，key 为 node_id
     */
    private function prefetchSpikeCounts(int $now): array
    {
        $windowSec = $this->cfg['spike_window'] * 60;
        $curStart = $now - $windowSec;
        $prevStart = $now - $windowSec * 2;

        $rows = AuditReport::query()
            ->selectRaw(
                'node_id,'
                . ' SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS cur_cnt,'
                . ' SUM(CASE WHEN created_at <  ? THEN 1 ELSE 0 END) AS prev_cnt',
                [$curStart, $curStart]
            )
            ->where('created_at', '>=', $prevStart)
            ->groupBy('node_id')
            ->get();

        $cur = [];
        $prev = [];
        foreach ($rows as $r) {
            $cur[(int) $r->node_id] = (int) $r->cur_cnt;
            $prev[(int) $r->node_id] = (int) $r->prev_cnt;
        }
        return [$cur, $prev];
    }

    /**
     * 上报中断：曾经上报过（last_report_at > 0）但超时未报
     */
    private function checkOffline(AuditNodeStatus $node, int $now): void
    {
        if ($node->last_report_at <= 0) {
            return; // 从未上报过，不算异常（可能是新节点还没开审计）
        }
        $silentFor = $now - (int) $node->last_report_at;
        if ($silentFor < $this->cfg['offline_minutes'] * 60) {
            return;
        }
        // 冷却：避免每分钟重复告警
        if ($now - (int) $node->last_alert_at < $this->cfg['offline_cooldown']) {
            return;
        }

        $node->last_alert_at = $now;
        $node->save();

        $minutes = intdiv($silentFor, 60);
        $this->notify("🔇 <b>节点审计上报中断</b>\n"
            . "节点：#{$node->node_id}\n"
            . "已静默：{$minutes} 分钟（阈值 {$this->cfg['offline_minutes']} 分钟）\n"
            . "最后上报：" . date('Y-m-d H:i:s', (int) $node->last_report_at) . "\n"
            . "累计上报：{$node->total_reports} 批 / {$node->total_events} 条");
    }

    /**
     * 命中突增：当前窗口命中数 vs 上一窗口，增长超阈值且当前窗口达到最小命中数
     *
     * @param int $cur  当前窗口命中数（预取）
     * @param int $prev 上一窗口命中数（预取）
     */
    private function checkSpike(AuditNodeStatus $node, int $now, int $cur, int $prev): void
    {
        if ($now - (int) $node->spike_alert_at < $this->cfg['spike_cooldown']) {
            return;
        }
        if ($cur < $this->cfg['spike_min_hits']) {
            return;
        }
        // 上一窗口为 0 时，当前窗口 >= min_hits 即视为突增
        $growth = $prev > 0 ? (($cur - $prev) / $prev) * 100 : PHP_INT_MAX;
        if ($growth < $this->cfg['spike_growth_pct']) {
            return;
        }

        $node->spike_alert_at = $now;
        $node->save();

        $growthText = $prev > 0 ? round($growth) . '%' : '∞（上窗口 0 命中）';
        $this->notify("📈 <b>节点审计命中突增</b>\n"
            . "节点：#{$node->node_id}\n"
            . "最近 {$this->cfg['spike_window']} 分钟命中：{$cur} 条\n"
            . "前 {$this->cfg['spike_window']} 分钟命中：{$prev} 条\n"
            . "增长率：{$growthText}（阈值 {$this->cfg['spike_growth_pct']}%）");
    }

    private function notify(string $text): void
    {
        try {
            $chatId = $this->cfg['chat_id'];
            if ($chatId === '') {
                $admin = User::query()
                    ->where('is_admin', 1)
                    ->whereNotNull('telegram_id')
                    ->where('telegram_id', '>', 0)
                    ->orderBy('id')
                    ->first();
                if (!$admin) {
                    Log::warning('[AccessAudit] 节点异常告警无接收人（未配置 chat_id 且无管理员绑定 TG）');
                    return;
                }
                $chatId = (string) $admin->telegram_id;
            }
            app(TelegramService::class)->sendMessage((int) $chatId, $text, 'HTML');
        } catch (\Throwable $e) {
            Log::error('[AccessAudit] 节点异常 TG 告警失败: ' . $e->getMessage());
        }
    }
}
