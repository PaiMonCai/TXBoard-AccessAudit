<?php

namespace Plugin\AccessAudit\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 节点审计上报健康状态（每个上报节点一行）
 */
class AuditNodeStatus extends Model
{
    protected $table = 'audit_node_status';

    // 表只有 created_at/updated_at 是 int 时间戳
    protected $dateFormat = 'U';

    protected $fillable = [
        'node_id',
        'last_report_at',
        'last_events_count',
        'last_matched_count',
        'last_banned_count',
        'total_reports',
        'total_events',
        'total_matched',
        'total_banned',
        'last_alert_at',
        'spike_alert_at',
    ];
}
