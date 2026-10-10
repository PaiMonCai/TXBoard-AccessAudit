<?php

namespace Plugin\AccessAudit\Models;

use Illuminate\Database\Eloquent\Model;

class AuditBanLog extends Model
{
    protected $table = 'audit_ban_logs';
    protected $dateFormat = 'U';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
    ];
}
