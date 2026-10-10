# TXBoard AccessAudit

[![Plugin CI](https://github.com/ANRCM0/TXBoard-AccessAudit/actions/workflows/ci.yml/badge.svg)](https://github.com/ANRCM0/TXBoard-AccessAudit/actions/workflows/ci.yml)


> TXBoard 官方第一方访问审计插件。  
> 独立于 TXBoard Core 与 TX-Node Core 发布，通过 **TXBoard Plugin Package v1** 安装和运行。

当前插件版本：**2.4.0**。

AccessAudit 为 TXBoard 提供节点访问审计、规则匹配、命中记录、自动封禁、节点健康监控、分析统计与 Telegram 告警能力。它是一个**可选插件**：不安装 AccessAudit，TXBoard 的用户、套餐、支付、订阅和节点控制链路仍可完整运行。

## 定位

```text
TXBoard                         TX-Node
Control Plane                  Agent / Data Plane
    │                               │
    ├── Plugin Runtime              ├── Core node protocol
    │       │                       │
    │       └── AccessAudit ◄───────┤ optional audit reporter
    │
    └── Admin Bridge
            │
            └── AccessAudit admin/dist
```

- **TXBoard**：提供 Plugin Runtime、Schema UI、Admin Bridge 和插件静态资源宿主。
- **AccessAudit**：拥有自己的后端、迁移、规则、分析逻辑和 Admin App。
- **TX-Node**：只提供可选 audit reporter/client；AccessAudit 不是节点核心协议的硬依赖。
- **node-agent/**：仅用于 legacy Xray 场景的兼容 sidecar。

## 主要能力

- 域名、域名后缀、关键词、IP CIDR 审计规则
- 节点访问事件与规则命中记录
- 阈值 / 时间窗口驱动的自动封禁
- 管理员手动封禁与解封
- 节点上报健康监控
- 访问量、命中率、活跃用户、热门目标与排行分析
- 小时级聚合与数据保留策略
- Telegram 告警
- TX-Node 可选审计上报
- legacy Xray sidecar 兼容

## 安装

### 方式一：Release ZIP

推荐方式。

1. 在本仓库 Releases 下载对应版本的插件 ZIP。
2. 登录 TXBoard Admin。
3. 打开 **插件管理**。
4. 上传 ZIP。
5. 安装并启用 `access_audit`。

TXBoard 会自动：

```text
上传 ZIP
   ↓
校验 Plugin Package
   ↓
安装到 api/plugins/AccessAudit
   ↓
执行 migrations
   ↓
发布 admin/dist
   ↓
启用 routes / scheduler / hooks
```

### 方式二：开发环境

将本仓库内容放到 TXBoard：

```text
api/plugins/AccessAudit/
```

然后在 TXBoard 插件管理中安装并启用。

## Plugin Package v1

AccessAudit 从 2.4.0 起采用 TXBoard **Plugin Package v1**：

```text
TXBoard-AccessAudit/
├── Plugin.php
├── config.json
│
├── Http/
│   └── Controllers/
├── Models/
├── Services/
│
├── routes/
│   ├── api.php
│   └── web.php
│
├── database/
│   └── migrations/
│
├── admin/
│   └── dist/
│       ├── index.html
│       ├── app.js
│       └── styles.css
│
└── node-agent/
```

复杂后台页面不再编译进 TXBoard Admin。插件通过 `config.json` 声明自己的 Admin App：

```json
{
  "package": {
    "schema": 1,
    "admin": {
      "format": "static-app",
      "dist": "admin/dist"
    }
  },
  "admin_menus": [
    {
      "title": "访问审计",
      "path": "dashboard",
      "app": "admin/index.html#/dashboard"
    }
  ]
}
```

TXBoard 将 `admin/dist` 发布到：

```text
/plugins/access_audit/admin/
```

并通过同源 iframe + **Admin Bridge v1** 提供管理员 Authorization、API 基址、插件信息与宿主导航。

Plugin Package 契约见：

https://github.com/ANRCM0/TXBoard/tree/main/contracts/plugin-package

## Admin 页面

启用后提供：

```text
/admin/plugins/access_audit/dashboard
/admin/plugins/access_audit/analytics
/admin/plugins/access_audit/rules
/admin/plugins/access_audit/reports
/admin/plugins/access_audit/ban-logs
/admin/plugins/access_audit/settings
```

其中：

- Dashboard / Analytics：插件自带 `admin/dist`
- Rules / Reports / Ban Logs：TXBoard Schema-driven CRUD
- Settings：TXBoard Schema-driven Settings

这样简单页面复用宿主 UI，复杂页面保持插件自己的独立发布生命周期。

## Node API

节点侧可选扩展：

```http
GET  /api/v1/plugin/access-audit/rules
POST /api/v1/plugin/access-audit/report
```

节点认证沿用 TXBoard 的 ServerV2 认证。服务端从认证上下文确认节点身份，不信任上报方自行声明的 node_id。

## TX-Node

新部署优先使用 TX-Node 的可选审计 reporter/client：

https://github.com/ANRCM0/TX-Node

AccessAudit 与 TX-Node 的关系是：

```text
TX-Node core
    │
    └── optional audit reporter
             │
             ▼
       AccessAudit API
```

即使未安装 AccessAudit，TX-Node 的核心节点能力仍应正常运行。

## Legacy Xray sidecar

`node-agent/` 保留给无法使用 TX-Node 原生 reporter 的旧 Xray 部署。

它不是 TX-Node 本体，也不应被视为新的节点架构。

相关说明：

[ node-agent/README.md ](node-agent/README.md)

## 配置

主要配置项包括：

- 自动封禁开关
- 默认命中阈值
- 默认统计窗口
- 访问日志保留时间
- 分析聚合开关与保留时间
- 节点离线阈值
- 命中突增检测
- Telegram 告警 Chat ID

具体字段以 [config.json](config.json) 为准，TXBoard Admin 会根据 Schema 自动生成设置页面。

## 数据模型

AccessAudit 自己维护审计相关表，包括：

```text
audit_rules
audit_reports
audit_ban_logs
audit_node_status
audit_access_logs
audit_hourly_stats
...
```

数据库变化通过插件自己的 `database/migrations/` 管理，不进入 TXBoard Core migrations。

## 开发与验证

本仓库不需要构建 TXBoard，也不需要把前端源码复制回 TXBoard。

基础检查包括：

```bash
# PHP syntax
find . -name '*.php' -print0 | xargs -0 -n1 php -l

# Admin App JavaScript syntax
node --check admin/dist/app.js

# Python sidecar syntax
python3 -m py_compile node-agent/audit-agent.py

# Manifest
jq . config.json
```

提交与 Pull Request 会由 GitHub Actions 自动执行这些检查，并验证插件发布 ZIP 可以正确生成。

## 发布

版本号以 `config.json` 为唯一事实来源。

发布新版本：

1. 更新 `config.json.version`。
2. 完成代码与 migration 兼容检查。
3. 合并到 `main`。
4. 创建与版本一致的 Git tag，例如 `v2.4.0`。
5. GitHub Actions 自动生成可上传到 TXBoard 的 Release ZIP。

发布包是完整包而不是增量补丁，TXBoard Plugin Manager 根据版本号完成升级。

## 安全边界

AccessAudit 后端 PHP 代码运行在 TXBoard Laravel 进程内，因此插件拥有应用级权限。

**只应安装可信来源的插件包。**

Plugin Package 的 ZIP 路径检查、体积限制和 iframe UI 隔离可以降低包格式与前端集成风险，但不能把恶意 PHP 插件变成沙箱代码。

## 与 TXBoard Core 的边界

AccessAudit 不属于 TXBoard Core。

TXBoard Core 只负责：

```text
Plugin Runtime
Plugin Package contract
Schema UI
Admin Bridge
Asset publishing
```

AccessAudit 自己负责：

```text
审计规则
访问事件
命中记录
封禁策略
节点健康
审计分析
告警
Admin App
```

这也是本仓库独立存在的原因：AccessAudit 可以独立开发、版本化和发布，而不要求 TXBoard 为每次插件 UI 更新重新构建镜像。

## Related projects

- TXBoard: https://github.com/ANRCM0/TXBoard
- TX-Node: https://github.com/ANRCM0/TX-Node
- Plugin Package Contract: https://github.com/ANRCM0/TXBoard/tree/main/contracts/plugin-package
