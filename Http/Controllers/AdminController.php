<?php

namespace Plugin\AccessAudit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Plugin\AccessAudit\Models\AuditAccessLog;
use Plugin\AccessAudit\Models\AuditBanLog;
use Plugin\AccessAudit\Models\AuditNodeStatus;
use Plugin\AccessAudit\Models\AuditReport;
use Plugin\AccessAudit\Models\AuditRule;
use Plugin\AccessAudit\Services\AuditProcessor;

class AdminController extends Controller
{


    public function stats()
    {
        // 仪表盘是实时状态页：每次请求直接从数据库读取。
        // 之前的 60 秒进程内缓存会导致“日志表已有新记录，但顶部仍显示 0”，
        // 且 Octane 多 worker 下各 worker 缓存失效时间不同，视觉上更容易错乱。
        $now = time();
        $todayStart = strtotime('today');
        $yesterdayStart = $todayStart - 86400;

        $data = [
            'rules_total' => AuditRule::query()->count(),
            'rules_enabled' => AuditRule::query()->where('enabled', 1)->count(),
            // 大表总数仍使用 information_schema 估算；首页实际展示的“今日”指标均为精确查询。
            'reports_total' => self::approxRowCount('audit_reports'),
            'reports_today' => AuditReport::query()->where('created_at', '>=', $todayStart)->count(),
            'bans_total' => AuditBanLog::query()->where('action', 'ban')->count(),
            'bans_today' => AuditBanLog::query()->where('action', 'ban')->where('created_at', '>=', $todayStart)->count(),
            // 访问量取自全量访问日志表（节点开启 report_all 才有数据），
            // 与 reports_*（命中记录）是两张不同的表，卡片上不能混用。
            'logs_total' => self::approxRowCount('audit_access_logs'),
            'logs_today' => AuditAccessLog::query()->where('created_at', '>=', $todayStart)->count(),
        ];

        // ── 仪表盘趋势数据（卡片上的“较昨日”与迷你折线）──
        // 全部走 created_at 上的现有索引做范围扫描，且每个指标只发一条 SQL，
        // 禁止按小时循环查询（24 次 COUNT 会把连接占满）。
        $data += self::trendData($now, $todayStart, $yesterdayStart);

        return response()->json(['data' => $data]);
    }

    /**
     * 仪表盘趋势指标。
     *
     * 返回：
     *   logs_yesterday —— 昨日访问量，用于“较昨日 ±N%”
     *   reports_yesterday / bans_yesterday —— 命中 / 封禁的同比基数
     *   trend_24h  —— 最近 24 个整点小时的访问量，供前端画迷你折线
     *   nodes_total / nodes_online —— 在线节点 x/y
     *
     * 折线取访问量（audit_access_logs）而非命中量：命中是稀疏事件，
     * 小时分桶后大量为 0，画出来是一条几乎贴底的直线没有信息量。
     * 全部为范围查询 + GROUP BY，命中 created_at 上的现有索引。
     */
    private static function trendData(int $now, int $todayStart, int $yesterdayStart): array
    {
        $out = [
            'logs_yesterday' => 0,
            'reports_yesterday' => 0,
            'bans_yesterday' => 0,
            'trend_24h' => [],
            'nodes_total' => 0,
            'nodes_online' => 0,
        ];

        try {
            $out['logs_yesterday'] = (int) AuditAccessLog::query()
                ->where('created_at', '>=', $yesterdayStart)
                ->where('created_at', '<', $todayStart)
                ->count();

            $out['reports_yesterday'] = (int) AuditReport::query()
                ->where('created_at', '>=', $yesterdayStart)
                ->where('created_at', '<', $todayStart)
                ->count();

            $out['bans_yesterday'] = (int) AuditBanLog::query()
                ->where('action', 'ban')
                ->where('created_at', '>=', $yesterdayStart)
                ->where('created_at', '<', $todayStart)
                ->count();

            // 最近 24 整点：以「小时起点」为桶，一条 GROUP BY 取回全部非零小时，
            // 再在 PHP 侧补零成 24 个点（保证前端拿到等长数组，画图不用判断空洞）。
            $since = $now - 86399;
            $rows = AuditAccessLog::query()
                ->selectRaw('FLOOR(created_at / 3600) AS bucket, COUNT(*) AS n')
                ->where('created_at', '>=', $since)
                ->groupBy('bucket')
                ->pluck('n', 'bucket');

            $curHour = (int) floor($now / 3600);
            $series = [];
            for ($i = 23; $i >= 0; $i--) {
                $series[] = (int) ($rows[$curHour - $i] ?? 0);
            }
            $out['trend_24h'] = $series;
        } catch (\Throwable $e) {
            // 趋势数据失败不应让整个仪表盘 500，保留零值继续
            \Illuminate\Support\Facades\Log::warning('[AccessAudit] stats 趋势查询失败: ' . $e->getMessage());
        }

        try {
            // 在线判定沿用 NodeHealthMonitor 的口径：曾经上报过且静默时长未超阈值。
            $offlineMinutes = 10;
            try {
                $config = \Plugin\AccessAudit\Services\ConfigCache::get();
                $offlineMinutes = max(1, (int) ($config['node_offline_minutes']['value'] ?? 10));
            } catch (\Throwable $e) {
                // 配置不可读时用默认阈值
            }

            // total 只数“曾经上报过”的节点：从未上报的节点（没开审计）不该拉低在线率，
            // 分母与离线判定口径保持一致，否则在线率永远是个假数字。
            $out['nodes_total'] = (int) AuditNodeStatus::query()
                ->where('last_report_at', '>', 0)
                ->count();
            $out['nodes_online'] = (int) AuditNodeStatus::query()
                ->where('last_report_at', '>', 0)
                ->where('last_report_at', '>=', $now - $offlineMinutes * 60)
                ->count();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[AccessAudit] stats 节点统计失败: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * information_schema 的行数估算（InnoDB 统计值，误差可接受）。
     * 读取失败时退回对应表的精确 COUNT。
     */
    private static function approxRowCount(string $table): int
    {
        try {
            $row = \Illuminate\Support\Facades\DB::selectOne(
                'SELECT TABLE_ROWS AS n FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            );
            if ($row && $row->n !== null) {
                return (int) $row->n;
            }
        } catch (\Throwable $e) {
            // information_schema 不可读（权限等）时退回精确 COUNT
        }

        return (int) \Illuminate\Support\Facades\DB::table($table)->count();
    }

    // ── 节点维度 ──────────────────────────────────────────────

    /**
     * 节点列表 + 上报健康状态（供分节点查看的选择器）
     */
    public function nodes()
    {
        $statuses = AuditNodeStatus::query()->get()->keyBy('node_id');
        // 节点名称从 v2_server 取
        $servers = \App\Models\Server::query()->whereIn('id', $statuses->keys())->pluck('name', 'id');

        $now = time();
        return response()->json(['data' => $statuses->map(fn ($s) => [
            'node_id' => $s->node_id,
            'node_name' => $servers[$s->node_id] ?? "节点 #{$s->node_id}",
            'last_report_at' => (int) $s->last_report_at,
            'silent_minutes' => $s->last_report_at > 0 ? intdiv($now - (int) $s->last_report_at, 60) : null,
            'last_events_count' => (int) $s->last_events_count,
            'total_reports' => (int) $s->total_reports,
            'total_events' => (int) $s->total_events,
            'total_matched' => (int) $s->total_matched,
            'total_banned' => (int) $s->total_banned,
        ])->values()]);
    }

    // ── 规则管理 ──────────────────────────────────────────────

    public function rules()
    {
        return response()->json([
            'data' => AuditRule::query()->orderByDesc('id')->get(),
        ]);
    }

    public function saveRule(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string|max:100',
            'match_type' => 'required|in:' . implode(',', AuditRule::TYPES),
            'match_value' => 'required|string',
            'enabled' => 'nullable|boolean',
            'threshold' => 'nullable|integer|min:1',
            'window_minutes' => 'nullable|integer|min:1',
            'remark' => 'nullable|string|max:255',
        ]);

        $data['enabled'] = $request->boolean('enabled', true);
        $data['threshold'] = $data['threshold'] ?? null;
        $data['window_minutes'] = $data['window_minutes'] ?? null;

        if (!empty($data['id'])) {
            $rule = AuditRule::find($data['id']);
            if (!$rule) {
                return response()->json(['error' => ['message' => '规则不存在']], 404);
            }
            $rule->update($data);
        } else {
            $rule = AuditRule::create($data);
        }
        \Plugin\AccessAudit\Services\RuleMatcher::flushCache();

        return response()->json(['data' => $rule]);
    }

    public function deleteRule(Request $request)
    {
        $rule = AuditRule::find($request->input('id'));
        if (!$rule) {
            return response()->json(['error' => ['message' => '规则不存在']], 404);
        }
        $rule->delete();
        \Plugin\AccessAudit\Services\RuleMatcher::flushCache();
        return response()->json(['data' => true]);
    }

    // ── 审计记录 ──────────────────────────────────────────────

    public function reports(Request $request)
    {
        $query = AuditReport::query()->orderByDesc('id');
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('rule_id')) {
            $query->where('rule_id', (int) $request->input('rule_id'));
        }
        if ($request->filled('node_id')) {
            $query->where('node_id', (int) $request->input('node_id'));
        }
        $reports = $query->limit(200)->get();

        $emails = User::query()->whereIn('id', $reports->pluck('user_id')->unique())
            ->pluck('email', 'id');
        $ruleNames = AuditRule::query()->whereIn('id', $reports->pluck('rule_id')->unique())
            ->pluck('name', 'id');

        return response()->json(['data' => $reports->map(fn ($r) => [
            'id' => $r->id,
            'user_id' => $r->user_id,
            'user_email' => $emails[$r->user_id] ?? "?#{$r->user_id}",
            'rule_id' => $r->rule_id,
            'rule_name' => $ruleNames[$r->rule_id] ?? "?#{$r->rule_id}",
            'node_id' => $r->node_id,
            'target' => $r->target,
            'target_ip' => $r->target_ip,
            'source_ip' => $r->source_ip,
            'banned' => (bool) $r->banned,
            'created_at' => $r->created_at,
        ])]);
    }

    public function banLogs()
    {
        return response()->json([
            'data' => AuditBanLog::query()->orderByDesc('id')->limit(100)->get(),
        ]);
    }

    // ── 手动封禁 / 解封 ───────────────────────────────────────

    public function ban(Request $request, AuditProcessor $processor)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'reason' => 'nullable|string|max:255',
        ]);
        $user = User::byEmail($data['email'])->first();
        if (!$user) {
            return response()->json(['error' => ['message' => '用户不存在']], 404);
        }
        if ($user->banned) {
            return response()->json(['error' => ['message' => '用户已处于封禁状态']], 422);
        }

        // 手动封禁记到一条虚拟规则（rule_id=0 表示手动）
        $rule = new AuditRule();
        $rule->id = 0;
        $rule->name = '手动封禁';
        $processor->ban($user, $rule, 0, 0, 0,
            $data['reason'] ?: '管理员手动封禁',
            (int) $request->user()->id);

        return response()->json(['data' => ['message' => "已封禁 {$user->email}"]]);
    }

    public function unban(Request $request, AuditProcessor $processor)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'reason' => 'nullable|string|max:255',
        ]);
        $user = User::byEmail($data['email'])->first();
        if (!$user) {
            return response()->json(['error' => ['message' => '用户不存在']], 404);
        }

        $processor->unban($user, (int) $request->user()->id, $data['reason'] ?? '');

        return response()->json(['data' => ['message' => "已解封 {$user->email}"]]);
    }
}
