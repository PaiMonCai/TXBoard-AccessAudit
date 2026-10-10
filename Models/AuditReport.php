<?php

namespace Plugin\AccessAudit\Models;

use Illuminate\Database\Eloquent\Model;

class AuditReport extends Model
{
    protected $table = 'audit_reports';
    protected $dateFormat = 'U';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'banned' => 'boolean',
    ];
}
