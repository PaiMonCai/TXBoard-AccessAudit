<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('audit_access_logs')) {
            Schema::create('audit_access_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('node_id');
                $table->integer('user_id');
                $table->string('target')->default('');
                $table->string('source_ip', 45)->nullable();
                $table->tinyInteger('matched')->default(0); // 是否命中审计规则
                $table->integer('created_at');
                $table->index(['node_id', 'created_at'], 'idx_node_time');
                $table->index(['user_id', 'created_at'], 'idx_user_time');
                $table->index('created_at', 'idx_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_access_logs');
    }
};
