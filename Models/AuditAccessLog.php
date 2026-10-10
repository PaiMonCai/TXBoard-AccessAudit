<?php

namespace Plugin\AccessAudit\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 全量访问日志（tx-node audit.report_all=true 时上报）
 */
class AuditAccessLog extends Model
{
    protected $table = 'audit_access_logs';

    public $timestamps = false; // 只有 created_at int 列

    protected $fillable = [
        'node_id',
        'user_id',
        'target',
        'target_ip',
        'source_ip',
        'matched',
        'created_at',
    ];

    protected $casts = [
        'matched' => 'boolean',
    ];
}
