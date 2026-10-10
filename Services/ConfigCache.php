<?php

namespace Plugin\AccessAudit\Services;

use App\Services\Plugin\PluginConfigService;

/**
 * 插件配置进程内缓存（60s TTL）。
 *
 * 上报热路径（ReportController：每节点 4 POST/分钟）与每分钟的
 * NodeHealthMonitor 都要读插件配置。直接查库时，50 个节点意味着
 * 200+ 次/分钟的纯配置查询。配置变更频率极低，60s TTL 足够：
 * 变更最迟 60 秒生效，且不依赖任何配置变更钩子。
 *
 * 需要立即生效的场景（如插件升级）调用 flush()。
 */
class ConfigCache
{
    private const TTL = 60;

    private static ?array $cache = null;
    private static int $cachedAt = 0;

    /**
     * @return array<string, mixed> 原始插件配置数组
     */
    public static function get(): array
    {
        if (self::$cache !== null && time() - self::$cachedAt < self::TTL) {
            return self::$cache;
        }
        self::$cache = app(PluginConfigService::class)->getConfig('access_audit') ?? [];
        self::$cachedAt = time();
        return self::$cache;
    }

    public static function flush(): void
    {
        self::$cache = null;
        self::$cachedAt = 0;
    }
}
