# AccessAudit Xray Sidecar

本目录是 AccessAudit 的 **legacy Xray compatibility sidecar**。

它用于读取 Xray access log，并把匹配结果上报到 TXBoard AccessAudit 插件。它不是 TX-Node 的组成部分，也不是新节点部署的首选方案。

## 推荐选择

- 使用 TX-Node：优先启用 TX-Node 自带的可选 AccessAudit reporter。
- 独立/legacy Xray：可以使用本目录的 Python sidecar。
- sing-box：优先通过 TX-Node 集成，不建议额外部署此 sidecar。

## Files

以本目录实际文件为准，核心通常包括：

- `audit-agent.py`
- `audit-agent.yml.example`
- `audit-agent.service`

## Basic deployment

```bash
mkdir -p /opt/txboard-audit-agent
cd /opt/txboard-audit-agent

cp audit-agent.yml.example audit-agent.yml
# 编辑 panel_url / server_token / node_id / log_path

cp audit-agent.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now audit-agent
systemctl status audit-agent
```

需要保证 Xray access log 可读，并配置 logrotate，避免日志无限增长。

## User mapping

AccessAudit 最终需要数字 `user_id`。sidecar 会优先从日志中的用户标识解析 ID；当日志只包含 UUID 时，可通过 TXBoard 用户接口建立 UUID → user_id 映射。

映射刷新失败时应保留上一次成功结果，避免短暂网络故障导致全部事件无法识别。

## Protocol

```http
GET  {panel}/api/v1/plugin/access-audit/rules
POST {panel}/api/v1/plugin/access-audit/report
```

详细字段与实际行为以 `audit-agent.py`、示例配置及 AccessAudit 后端实现为准。
