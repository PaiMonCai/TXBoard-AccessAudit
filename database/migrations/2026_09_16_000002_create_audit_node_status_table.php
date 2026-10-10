<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('audit_node_status')) {
            Schema::create('audit_node_status', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('node_id')->unique();
                $table->integer('last_report_at')->default(0);   // 最后一次上报时间（0 = 从未上报）
                $table->integer('last_events_count')->default(0);
                $table->integer('last_matched_count')->default(0);
                $table->integer('last_banned_count')->default(0);
                $table->bigInteger('total_reports')->default(0); // 上报批次总数
                $table->bigInteger('total_events')->default(0);
                $table->bigInteger('total_matched')->default(0);
                $table->bigInteger('total_banned')->default(0);
                $table->integer('last_alert_at')->default(0);    // 上次"上报中断"告警时间
                $table->integer('spike_alert_at')->default(0);   // 上次"命中突增"告警时间
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->index('last_report_at', 'idx_last_report');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_node_status');
    }
};
