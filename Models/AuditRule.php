<?php

namespace Plugin\AccessAudit\Models;

use Illuminate\Database\Eloquent\Model;

class AuditRule extends Model
{
    protected $table = 'audit_rules';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'enabled' => 'boolean',
        'threshold' => 'integer',
        'window_minutes' => 'integer',
    ];

    public const TYPES = ['domain', 'domain_suffix', 'keyword', 'ip_cidr'];
}
