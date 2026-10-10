<?php

namespace Plugin\AccessAudit\Services;

use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugin\AccessAudit\Models\AuditBanLog;
use Plugin\AccessAudit\Models\AuditReport;
use Plugin\AccessAudit\Models\AuditRule;

/**
 * 审计处理器：记录命中 → 阈值判定 → 自动封禁 → TG 告警
 *
 * 性能约定（重要）：
 *   - 构造零 SQL：配置经 ConfigCache（60s TTL）读取，规则匹配的规则集
 *     由 RuleMatcher 快照缓存提供
 *   - 阈值判定所需的历史命中数由调用方【批量预取】，避免每条事件一次 COUNT(*)
 *   - ban() 的事务内只做写操作，TG 网络请求一律放在事务外
 */
class AuditProcessor
{
    private array $cfg;

    public function __construct()
    {
        $config = ConfigCache::get();
        $val = fn (string $key, $default) => $config[$key]['value'] ?? $default;
        $this->cfg = [
            'auto_ban' => (int) $val('auto_ban_enabled', 1) === 1,
            'threshold' => max(1, (int) $val('default_threshold', 3)),
            'window' => max(1, (int) $val('default_window_minutes', 60)),
            'chat_id' => trim((string) $val('alert_chat_id', '')),
        ];
    }

    /** 清空配置缓存（配置变更后需要立即生效时调用） */
    public static function flushConfigCache(): void
    {
        ConfigCache::flush();
    }

    public function config(): array
    {
        return $this->cfg;
    }

    /**
     * 处理单条上报。返回 ['rule_id' => ?int, 'banned' => bool]
     *
     * @param int|null $hitsBefore 调用方预取的历史命中数（不含本条）。
     *                             传 null 时退化为内部查询（兼容老调用方）。
     */
    public function process(
        User $user,
        string $target,
        int $nodeId,
        ?string $sourceIp,
        RuleMatcher $matcher,
        ?int $hitsBefore = null
    ): array {
        $rule = $matcher->match($target);
        if (!$rule) {
            return ['rule_id' => null, 'banned' => false];
        }

        // 用 insert 而非 create：create 会为每条命中单独开一次写事务，
        // 且 Eloquent 事件/时间戳处理在批量场景下纯属浪费。
        AuditReport::query()->insert([
            'user_id' => $user->id,
            'rule_id' => $rule->id,
            'node_id' => $nodeId,
            'target' => mb_substr($target, 0, 255),
            'source_ip' => $sourceIp ? mb_substr($sourceIp, 0, 45) : null,
            'banned' => 0,
            'created_at' => time(),
        ]);

        $banned = false;
        if ($this->cfg['auto_ban'] && !$user->banned) {
            $banned = $this->checkThresholdAndBan($user, $rule, $target, $nodeId, $hitsBefore);
        }

        return ['rule_id' => $rule->id, 'banned' => $banned];
    }

    /**
     * 批量预取「用户+规则」维度的历史命中数，供 process() 复用。
     *
     * 一次 GROUP BY 查询取回所有 (user_id, rule_id) 组合在窗口内的命中数，
     * 把 N 条事件 × 1 次 COUNT(*) 压成 1 次查询。
     *
     * @param array<int, array{user_id:int, rule_id:int}> $pairs
     * @return array<string, int>  key = "user_id:rule_id"
     */
    public function prefetchHitCounts(array $pairs, int $windowMinutes): array
    {
        if (!$pairs) {
            return [];
        }
        $userIds = array_values(array_unique(array_column($pairs, 'user_id')));
        $ruleIds = array_values(array_unique(array_column($pairs, 'rule_id')));
        $since = time() - max(1, $windowMinutes) * 60;

        $rows = AuditReport::query()
            ->selectRaw('user_id, rule_id, COUNT(*) AS cnt')
            ->whereIn('user_id', $userIds)
            ->whereIn('rule_id', $ruleIds)
            ->where('created_at', '>=', $since)
            ->groupBy('user_id', 'rule_id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->user_id . ':' . $r->rule_id] = (int) $r->cnt;
        }
        return $out;
    }

    /**
     * 判断是否达阈值并封禁。
     *
     * @param int|null $hitsBefore 预取的历史命中数；null 时内部查询
     */
    private function checkThresholdAndBan(
        User $user,
        AuditRule $rule,
        string $target,
        int $nodeId,
        ?int $hitsBefore = null
    ): bool {
        $threshold = $rule->threshold ?: $this->cfg['threshold'];
        $windowMin = $rule->window_minutes ?: $this->cfg['window'];

        if ($hitsBefore === null) {
            $hits = AuditReport::query()
                ->where('user_id', $user->id)
                ->where('rule_id', $rule->id)
                ->where('created_at', '>=', time() - $windowMin * 60)
                ->count();
        } else {
            // 预取的是「本条之前」的数量，加上本条写入的 1 条
            $hits = $hitsBefore + 1;
        }

        if ($hits < $threshold) {
            return false;
        }

        $this->ban($user, $rule, $hits, $threshold, $windowMin,
            "自动封禁：{$windowMin} 分钟内命中规则「{$rule->name}」{$hits} 次（最近目标：{$target}，节点 #{$nodeId}）");
        return true;
    }

    /**
     * 封禁用户 + 写日志 + TG 告警（自动与手动共用）
     *
     * 事务内只做写操作；TG 网络请求放在事务提交之后，
     * 避免网络抖动把数据库连接长时间占住。
     */
    public function ban(User $user, AuditRule $rule, int $hits, int $threshold, int $windowMin, string $reason, int $operatorId = 0): void
    {
        DB::transaction(function () use ($user, $rule, $hits, $threshold, $windowMin, $reason, $operatorId) {
            $user->banned = 1;
            $user->save();

            AuditBanLog::create([
                'user_id' => $user->id,
                'user_email' => $user->email,
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'hit_count' => $hits,
                'threshold' => $threshold,
                'window_minutes' => $windowMin,
                'operator_id' => $operatorId,
                'action' => 'ban',
                'reason' => $reason,
                'created_at' => time(),
            ]);

            AuditReport::query()
                ->where('user_id', $user->id)
                ->where('rule_id', $rule->id)
                ->where('banned', 0)
                ->update(['banned' => 1]);
        });

        $this->notify("🚫 <b>访问审计封禁</b>\n"
            . "用户：{$user->email} (#{$user->id})\n"
            . "规则：{$rule->name}\n"
            . "命中：{$hits} 次 / 阈值 {$threshold} 次（{$windowMin} 分钟窗口）\n"
            . "原因：{$reason}");
    }

    /**
     * 解封（仅手动）
     */
    public function unban(User $user, int $operatorId, string $reason = ''): void
    {
        DB::transaction(function () use ($user, $operatorId, $reason) {
            $user->banned = 0;
            $user->save();

            AuditBanLog::create([
                'user_id' => $user->id,
                'user_email' => $user->email,
                'rule_id' => 0,
                'rule_name' => '',
                'hit_count' => 0,
                'threshold' => 0,
                'window_minutes' => 0,
                'operator_id' => $operatorId,
                'action' => 'unban',
                'reason' => $reason ?: '管理员手动解封',
                'created_at' => time(),
            ]);
        });

        $this->notify("✅ <b>访问审计解封</b>\n用户：{$user->email} (#{$user->id})\n操作：管理员手动解封");
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
                    Log::warning('[AccessAudit] 无可用 TG 告警接收人（未配置 chat_id 且无管理员绑定 TG）');
                    return;
                }
                $chatId = (string) $admin->telegram_id;
            }
            app(TelegramService::class)->sendMessage((int) $chatId, $text, 'HTML');
        } catch (\Throwable $e) {
            Log::error('[AccessAudit] TG 告警发送失败: ' . $e->getMessage());
        }
    }
}
