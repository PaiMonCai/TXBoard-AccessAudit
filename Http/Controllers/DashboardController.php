<?php

namespace Plugin\AccessAudit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Plugin\AccessAudit\Models\AuditAccessLog;

class DashboardController extends Controller
{
    private const LOGS_PER_PAGE = 20;

    /**
     * 访问日志分页查询。
     *
     * 每页固定 20 条，避免日志量大时单页高度失控；筛选条件与旧接口完全兼容。
     */
    public function logs(Request $request)
    {
        $query = AuditAccessLog::query()->orderByDesc('id');

        if ($request->filled('node_id')) {
            $query->where('node_id', (int) $request->input('node_id'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('keyword')) {
            $kw = trim((string) $request->input('keyword'));
            $query->where('target', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $kw) . '%');
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', (int) $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', (int) $request->input('to'));
        }
        if ($request->filled('matched')) {
            $query->where('matched', (int) $request->input('matched'));
        }

        $page = max(1, (int) $request->input('page', 1));
        $perPage = self::LOGS_PER_PAGE;
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $logs = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $emails = User::query()
            ->whereIn('id', $logs->pluck('user_id')->unique())
            ->pluck('email', 'id');
        $nodeNames = \App\Models\Server::query()
            ->whereIn('id', $logs->pluck('node_id')->unique())
            ->pluck('name', 'id');

        return response()->json(['data' => [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
            'list' => $logs->map(fn ($l) => [
                'id' => $l->id,
                'node_id' => $l->node_id,
                'node_name' => $nodeNames[$l->node_id] ?? "节点 #{$l->node_id}",
                'user_id' => $l->user_id,
                'user_email' => $emails[$l->user_id] ?? "?#{$l->user_id}",
                'target' => $l->target,
                'target_ip' => $l->target_ip,
                'source_ip' => $l->source_ip,
                'matched' => (bool) $l->matched,
                'created_at' => $l->created_at,
            ]),
        ]]);
    }

    /**
     * 管理员手动清空全部访问日志明细。
     *
     * 仅删除 audit_access_logs；审计规则、命中记录、封禁记录与分析聚合均保留。
     */
    public function clearLogs()
    {
        $deleted = (int) AuditAccessLog::query()->delete();

        return response()->json(['data' => [
            'deleted' => $deleted,
            'message' => "已清空 {$deleted} 条访问日志",
        ]]);
    }
}
