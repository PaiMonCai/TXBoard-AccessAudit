<?php

namespace Plugin\AccessAudit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Plugin\AccessAudit\Models\AuditAccessLog;
use Plugin\AccessAudit\Models\AuditNodeStatus;
use Plugin\AccessAudit\Models\AuditReport;
use Plugin\AccessAudit\Models\AuditRule;
use Plugin\AccessAudit\Services\AuditProcessor;
use Plugin\AccessAudit\Services\RuleMatcher;

/**
 * 节点上报接口（ServerV2 中间件认证：server_token + node_id / machine token）
 *
 * POST /api/v1/plugin/access-audit/report?token=<server_token>&node_id=<id>
 * Body: {
 *   "events": [
 *     {"user_id": 123, "target": "example.com", "target_ip": "93.184.216.34", "source_ip": "1.2.3.4", "matched": true},
 *     ...
 *   ]
 * }
 * node_id 从认证属性 node_info 取，不信上报方自报（防伪造）。
 *
 * 性能约定（重要）：
 *   单次 POST 的 SQL 次数必须是 O(1) + O(N/CHUNK)，与事件条数解耦。
 *   禁止在事件循环里逐条查询/写入，否则节点队列回压时会瞬间打满连接池。
 */
class ReportController extends Controller
{
    private const MAX_EVENTS = 500;

    /** 批量写入的行数上限，避免单条 SQL 超过 max_allowed_packet */
    private const CHUNK = 200;

    public function report(Request $request, AuditProcessor $processor)
    {
        $node = $request->attributes->get('node_info');
        if (!$node) {
            // handshake 路径允许不带 node_id，但上报必须带
            return response()->json(['error' => ['message' => '缺少 node_id']], 422);
        }
        $nodeId = (int) $node->id;

        $data = $request->validate([
            'events' => 'required|array|min:1|max:' . self::MAX_EVENTS,
            'events.*.user_id' => 'required|integer|min:1',
            'events.*.target' => 'required|string|max:255',
            'events.*.target_ip' => 'nullable|string|max:45',
            'events.*.source_ip' => 'nullable|string|max:45',
            'events.*.matched' => 'nullable|boolean',
        ]);

        $events = $data['events'];
        $now = time();

        // 兼容旧版节点（不带 matched 字段）：缺省视为命中（旧行为=只上报命中项）
        $isMatched = fn (array $e) => array_key_exists('matched', $e) ? !empty($e['matched']) : true;

        // ---------- 1. 全量访问日志：批量插入（只做写，不参与后续匹配） ----------
        $logRows = [];
        $matchedEvents = [];
        foreach ($events as $event) {
            $hit = $isMatched($event);
            $logRows[] = [
                'node_id' => $nodeId,
                'user_id' => (int) $event['user_id'],
                'target' => mb_substr((string) $event['target'], 0, 255),
                'target_ip' => isset($event['target_ip']) ? mb_substr((string) $event['target_ip'], 0, 45) : null,
                'source_ip' => isset($event['source_ip']) ? mb_substr((string) $event['source_ip'], 0, 45) : null,
                'matched' => $hit ? 1 : 0,
                'created_at' => $now,
            ];
            // 未命中事件只进全量日志，不进匹配流程，省掉规则计算与全部后续 SQL
            if ($hit) {
                $matchedEvents[] = $event;
            }
        }
        try {
            foreach (array_chunk($logRows, self::CHUNK) as $chunk) {
                AuditAccessLog::query()->insert($chunk);
            }
        } catch (\Throwable $e) {
            Log::error('[AccessAudit] 全量日志写入失败: ' . $e->getMessage(), ['node_id' => $nodeId]);
        }

        // ---------- 2. 批量取用户（1 次查询） ----------
        $userIds = array_values(array_unique(array_map(
            fn ($e) => (int) $e['user_id'], $matchedEvents
        )));
        $users = $userIds
            ? User::query()->whereIn('id', $userIds)->get()->keyBy('id')
            : collect();

        // ---------- 3. 规则匹配（内存完成，零 SQL） ----------
        // 一次载入规则集，避免 AuditProcessor 内部每个用户重复实例化 RuleMatcher
        $matcher = new RuleMatcher();

        $reportRows = [];
        $hitPairs = [];          // 命中的 (user_id, rule_id) 对，用于一次性预取历史计数
        $pairKeys = [];          // 去重
        $seen = [];              // 本次请求内每个 key 已出现的次数（游标）
        $hitMeta = [];           // key => [user, rule, target, window]
        $windowMin = $processor->config()['window'];

        $matched = 0;
        foreach ($matchedEvents as $event) {
            $user = $users->get((int) $event['user_id']);
            if (!$user) {
                continue;
            }
            $rule = $matcher->match((string) $event['target']);
            if (!$rule) {
                continue;
            }

            $key = $user->id . ':' . $rule->id;
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            $reportRows[] = [
                'user_id' => $user->id,
                'rule_id' => $rule->id,
                'node_id' => $nodeId,
                'target' => mb_substr((string) $event['target'], 0, 255),
                'target_ip' => isset($event['target_ip']) ? mb_substr((string) $event['target_ip'], 0, 45) : null,
                'source_ip' => isset($event['source_ip']) ? mb_substr((string) $event['source_ip'], 0, 45) : null,
                'banned' => 0,
                'created_at' => $now,
            ];

            if (!isset($pairKeys[$key])) {
                $pairKeys[$key] = true;
                $hitPairs[] = ['user_id' => $user->id, 'rule_id' => $rule->id];
                $hitMeta[$key] = [$user, $rule, (string) $event['target']];
            }
            // 窗口取该规则自身的配置，取最大窗口以保证预取覆盖所有规则
            $ruleWindow = $rule->window_minutes ?: $windowMin;
            $windowMin = max($windowMin, (int) $ruleWindow);

            $matched++;
        }

        // ---------- 4. 命中记录批量插入（ceil(M / CHUNK) 次） ----------
        try {
            foreach (array_chunk($reportRows, self::CHUNK) as $chunk) {
                AuditReport::query()->insert($chunk);
            }
        } catch (\Throwable $e) {
            Log::error('[AccessAudit] 命中记录写入失败: ' . $e->getMessage(), ['node_id' => $nodeId]);
        }

        // ---------- 5. 一次预取全部历史命中数（1 次 GROUP BY） ----------
        $hitCounts = [];
        if ($hitPairs) {
            try {
                $hitCounts = $processor->prefetchHitCounts($hitPairs, $windowMin);
            } catch (\Throwable $e) {
                Log::error('[AccessAudit] 命中计数预取失败: ' . $e->getMessage(), ['node_id' => $nodeId]);
            }
        }

        // ---------- 6. 阈值判定 + 封禁（每用户最多 1 次，无额外 COUNT） ----------
        $banned = 0;
        $bannedUsers = [];   // 同一次请求内同一用户只封禁一次
        $cursor = [];        // key => 已消费条数
        foreach (array_keys($pairKeys) as $key) {
            // 用本次请求内的累计条数补足预取的偏差：查询在写入之后，会把本批全部算进去，
            // 因此第 i 条（i 从 1 开始）的「本条之前计数」= 总数 - (本批命中数 - i + 1)
            [$user, $rule] = $hitMeta[$key];
            if (!empty($bannedUsers[$user->id])) {
                continue;
            }
            $total = $hitCounts[$key] ?? 0;
            $count = $seen[$key] ?? 0;
            if ($total === 0 && $count > 0) {
                // 预取失败时退化为本地游标（宁可漏封一次，也不放大 SQL）
                $total = $count;
            }
            $cfg = $processor->config();
            if (empty($cfg['auto_ban']) || $user->banned) {
                continue;
            }
            $threshold = $rule->threshold ?: $cfg['threshold'];
            // 已消费到本批最后一条时的历史数：total - 1
            $hitsBefore = max(0, $total - 1);
            if ($hitsBefore + 1 >= $threshold) {
                try {
                    $processor->ban(
                        $user,
                        $rule,
                        $total,
                        $threshold,
                        $rule->window_minutes ?: $cfg['window'],
                        "自动封禁：命中规则「{$rule->name}」{$total} 次（阈值 {$threshold}）",
                        0
                    );
                    $banned++;
                    $bannedUsers[$user->id] = true;
                } catch (\Throwable $e) {
                    Log::error('[AccessAudit] 封禁失败: ' . $e->getMessage(), [
                        'user_id' => $user->id,
                        'node_id' => $nodeId,
                    ]);
                }
            }
        }

        // ---------- 7. 更新节点健康状态（1 次 upsert 语义） ----------
        try {
            $status = AuditNodeStatus::query()->firstOrNew(['node_id' => $nodeId]);
            $status->last_report_at = time();
            $status->last_events_count = count($events);
            $status->last_matched_count = $matched;
            $status->last_banned_count = $banned;
            $status->total_reports = (int) $status->total_reports + 1;
            $status->total_events = (int) $status->total_events + count($events);
            $status->total_matched = (int) $status->total_matched + $matched;
            $status->total_banned = (int) $status->total_banned + $banned;
            $status->save();
        } catch (\Throwable $e) {
            Log::warning('[AccessAudit] 节点状态更新失败: ' . $e->getMessage(), ['node_id' => $nodeId]);
        }

        return response()->json(['data' => [
            'node_id' => $nodeId,
            'received' => count($events),
            'skipped' => count($events) - count($matchedEvents),
            'matched' => $matched,
            'banned' => $banned,
        ]]);
    }

    /**
     * 下发当前启用的规则给节点（用于本地预过滤，只上报命中项）
     *
     * GET /api/v1/plugin/access-audit/rules?token=<server_token>&node_id=<id>
     */
    public function rules(Request $request)
    {
        $rules = AuditRule::query()
            ->where('enabled', 1)
            ->get(['id', 'name', 'match_type', 'match_value']);

        return response()->json(['data' => $rules]);
    }
}
