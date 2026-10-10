#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Xboard AccessAudit 节点审计 agent

工作原理：
1. 从面板拉取审计规则（GET /rules），本地编译匹配器
2. tail xray access log，逐行解析 (user_id, target)
3. 本地匹配命中才入队（全量日志不出节点）
4. 攒批上报面板（POST /report），达到阈值后面板自动封禁 + TG 告警

要求：python3.7+，仅标准库。Xray 内核（sing-box 不适用，见 README）。

用法：
  python3 audit-agent.py /path/to/audit-agent.yml
"""

import json
import os
import re
import signal
import ssl
import sys
import threading
import time
import urllib.request
import urllib.parse
import urllib.error

try:
    import yaml  # type: ignore
    HAS_YAML = True
except ImportError:
    yaml = None  # type: ignore
    HAS_YAML = False


# ── 极简 YAML 子集解析（无 PyYAML 时的降级，只支持本配置文件的扁平结构）──

def load_config(path):
    with open(path, 'r', encoding='utf-8') as f:
        text = f.read()
    if HAS_YAML:
        return yaml.safe_load(text)
    cfg = {}
    for line in text.splitlines():
        line = line.split('#', 1)[0].rstrip()
        if not line.strip() or ':' not in line:
            continue
        k, _, v = line.partition(':')
        v = v.strip().strip('"').strip("'")
        if v.lower() in ('true', 'false'):
            v = v.lower() == 'true'
        elif v.isdigit():
            v = int(v)
        cfg[k.strip()] = v
    return cfg


# ── 规则匹配器（与面板 RuleMatcher.php 语义一致）──

def ip_to_int(ip):
    try:
        parts = ip.split('.')
        if len(parts) != 4:
            return None
        n = 0
        for p in parts:
            x = int(p)
            if not 0 <= x <= 255:
                return None
            n = (n << 8) | x
        return n
    except ValueError:
        return None


def ip_in_cidr(ip, cidr):
    if '/' not in cidr:
        return ip == cidr
    subnet, bits = cidr.split('/', 1)
    ipn, sn = ip_to_int(ip), ip_to_int(subnet)
    if ipn is None or sn is None:
        return False
    bits = int(bits)
    if bits == 0:
        return True
    mask = (0xFFFFFFFF << (32 - bits)) & 0xFFFFFFFF
    return (ipn & mask) == (sn & mask)


class Matcher(object):
    def __init__(self, rules):
        # rules: [{id, name, match_type, match_value}]
        self.compiled = []
        for r in rules:
            values = [v.strip().lower() for v in re.split(r'[\r\n,]+', r.get('match_value') or '') if v.strip()]
            if values:
                self.compiled.append((r, values))

    def is_ip(self, t):
        return ip_to_int(t) is not None or ':' in t

    def match(self, target):
        t = target.strip().lower()
        if not t:
            return None
        is_ip = self.is_ip(t)
        for rule, values in self.compiled:
            mt = rule.get('match_type')
            if is_ip and mt not in ('ip_cidr', 'keyword'):
                continue
            for v in values:
                if mt == 'domain' and t == v:
                    return rule
                if mt == 'domain_suffix' and (t == v or t.endswith('.' + v)):
                    return rule
                if mt == 'keyword' and v in t:
                    return rule
                if mt == 'ip_cidr' and ip_in_cidr(t, v):
                    return rule
        return None


# ── 日志 tail ──

class LogTailer(object):
    """跟踪日志文件，支持轮转（大小变小或 inode 变化时重开）"""

    def __init__(self, path):
        self.path = path
        self.fh = None
        self.inode = None

    def open_at_end(self):
        st = os.stat(self.path)
        self.fh = open(self.path, 'r', encoding='utf-8', errors='replace')
        self.inode = st.st_ino
        self.fh.seek(0, os.SEEK_END)

    def lines(self):
        """生成器：持续产出新行；文件不存在时等待重试"""
        buf = ''
        while True:
            if self.fh is None:
                try:
                    self.open_at_end()
                    buf = ''
                except OSError:
                    time.sleep(3)
                    continue
            try:
                st = os.stat(self.path)
                if st.st_ino != self.inode or st.st_size < self.fh.tell():
                    # 轮转了：重开新文件从头读
                    self.fh.close()
                    self.open_at_end()
                    self.fh.seek(0)
                    buf = ''
            except OSError:
                time.sleep(2)
                continue

            chunk = self.fh.read()
            if not chunk:
                time.sleep(0.5)
                continue
            buf += chunk
            while '\n' in buf:
                line, buf = buf.split('\n', 1)
                yield line


# ── access log 解析 ──

# xray access 典型格式（email 为 user@<id>）：
# 2026/09/16 10:00:00.123 from 1.2.3.4:5678 accepted tcp:example.com:443 [vmess-in >> direct] email: user@123
# 部分版本: ... accepted tcp:example.com:443 [vmess-in -> proxy] email: user@123
#
# 用户标识两种可能（取决于 xboard-node 版本 / 面板）：
#   a) user@<数字ID>        → 直接得到 user_id（xboard-node 默认，user_id 编进 email）
#   b) user@<uuid>          → 需要本地 uuid→user_id 映射（见 UserDirectory）
# 邮箱前缀不一定是 "user"，这里用宽松的 [^@\s]* 前缀。
EMAIL_RE = re.compile(r'email:\s*(?P<email>[^\s\]]+)')
UID_RE = re.compile(r'^user@(?P<uid>\d+)$')
UUID_RE = re.compile(
    r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'
)
XRAY_ACCEPT_RE = re.compile(
    r'from\s+(?P<src>[\d.a-fA-F:]+)\s+accepted\s+(?:tcp|udp):(?P<target>[^:\s]+)(?::\d+)?'
)
XRAY_ACCEPT_RE2 = re.compile(
    r'accepted\s+(?:tcp|udp):(?P<target>[^:\s]+)(?::\d+)?'
)

PRIVATE_IP_RE = re.compile(r'^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|127\.|::1$|fc|fd|fe80)')


def extract_user_ref(email):
    """从 access log 的 email 字段解析用户标识。

    返回 (kind, value)：
      ('id', int)      → email 形如 user@123，直接可用
      ('uuid', str)    → email 形如 user@<uuid>，需要目录反查
      (None, None)     → 无法识别
    """
    if not email:
        return None, None
    email = email.strip().strip('"\'').rstrip(',;')
    m = UID_RE.match(email)
    if m:
        return 'id', int(m.group('uid'))
    # 宽松形式：任意前缀@<uuid>，或整串就是 uuid
    local = email.split('@', 1)[-1]
    if UUID_RE.match(local):
        return 'uuid', local.lower()
    if UUID_RE.match(email):
        return 'uuid', email.lower()
    # 末段是纯数字：<anything>@<digits>
    tail = local.strip()
    if tail.isdigit():
        return 'id', int(tail)
    return None, None


def parse_line(line):
    """解析一行 access log。

    返回 (kind, value, target, src)；kind 为 'id' 或 'uuid'。
    无法解析或目标是内网地址时返回 None。
    """
    m = XRAY_ACCEPT_RE.search(line) or XRAY_ACCEPT_RE2.search(line)
    if not m:
        return None
    target = m.group('target').strip()
    # 跳过明显内网/保留目标，减少噪音
    if PRIVATE_IP_RE.match(target):
        return None
    src = m.groupdict().get('src') or None
    if src:
        src = src.rsplit(':', 1)[0] if src.count(':') == 1 else src

    em = EMAIL_RE.search(line)
    kind, val = extract_user_ref(em.group('email') if em else None)
    if kind is None:
        return None
    return kind, val, target, src


# ── uuid → user_id 目录（v2.1+，兼容 email 里是 uuid 的部署）──

class UserDirectory(object):
    """定期从面板拉取用户列表，建立 uuid → user_id 映射。

    仅当 access log 里出现 uuid 形式的用户标识时才需要。
    接口与 xboard-node 原版一致：
      旧版认证: GET /api/v1/server/UniProxy/user
      machine:  GET /api/v2/server/user
    返回 {"users": [{"id": 1, "uuid": "...", "speed_limit": 0, "device_limit": 0}, ...]}

    拉取失败时保留上一次的映射（不清空），避免网络抖动导致全部无法上报。
    """

    def __init__(self, fetch, ttl, log_fn):
        self._fetch = fetch          # callable() -> list[dict]
        self._ttl = ttl
        self._log = log_fn
        self._map = {}               # uuid(lower) -> user_id
        self._last = 0.0
        self._lock = threading.Lock()
        self._missed = set()         # 记录查不到的 uuid，避免刷日志

    def refresh(self, force=False):
        if not force and time.time() - self._last < self._ttl:
            return
        try:
            users = self._fetch()
        except Exception as e:
            self._log('用户目录刷新失败(保留旧映射): %s' % e)
            return
        m = {}
        for u in users or []:
            uid = u.get('id')
            uuid = (u.get('uuid') or '').strip().lower()
            if uid and uuid:
                m[uuid] = int(uid)
        with self._lock:
            self._map = m
            self._last = time.time()
            self._missed.clear()
        self._log('用户目录已刷新: %d 条 uuid 映射' % len(m))

    def resolve(self, uuid):
        with self._lock:
            uid = self._map.get(uuid.lower())
            known = bool(self._map)
            missed = uuid in self._missed
        if uid is None and known and not missed:
            with self._lock:
                self._missed.add(uuid)
            self._log('uuid 未在用户目录中找到(可能已删除): %s' % uuid)
        return uid


# ── 主循环 ──

class Agent(object):
    def __init__(self, cfg):
        self.panel = str(cfg['panel_url']).rstrip('/')
        # server_token（推荐）或旧版 api_secret 均可；认证字段与原版节点上报一致
        self.token = str(cfg.get('server_token') or cfg.get('api_secret') or '')
        self.node_id = int(cfg.get('node_id', 0))
        self.machine_id = int(cfg.get('machine_id', 0) or 0)
        self.log_path = str(cfg['log_path'])
        self.batch_size = int(cfg.get('batch_size', 50))
        self.flush_interval = float(cfg.get('flush_interval', 15))
        self.rules_ttl = int(cfg.get('rules_refresh', 300))
        # 用户目录刷新间隔（秒）。0 = 关闭（email 里是 uuid 时无法上报 user_id）
        self.user_ttl = int(cfg.get('user_refresh', 600))
        self.insecure = bool(cfg.get('insecure_tls', False))
        self.queue = []
        self.lock = threading.Lock()
        self.matcher = Matcher([])
        self.last_rules = 0
        self.stop = False
        self.dir = UserDirectory(self.fetch_users, self.user_ttl, log)
        self._warned_uuid = False

        self.ctx = ssl.create_default_context()
        if self.insecure:
            self.ctx.check_hostname = False
            self.ctx.verify_mode = ssl.CERT_NONE

    # ── HTTP ──

    def _auth_fields(self):
        """认证字段：machine 模式带 machine_id，否则带 node_id。"""
        if self.machine_id > 0:
            return {'token': self.token, 'machine_id': self.machine_id, 'node_id': self.node_id}
        return {'token': self.token, 'node_id': self.node_id}

    def api(self, method, path, payload=None, raw=False):
        # ServerV2 认证：token/node_id 放在 query（GET）或 body（POST），
        # 与 xboard-node 原版上报一致
        auth = self._auth_fields()
        if method == 'GET':
            sep = '&' if '?' in path else '?'
            qs = urllib.parse.urlencode(auth)
            url = self.panel + path + sep + qs
            data = None
        else:
            url = self.panel + path
            payload = dict(payload or {})
            payload.update(auth)
            data = json.dumps(payload).encode()
        req = urllib.request.Request(url, data=data, method=method)
        req.add_header('Accept', 'application/json')
        if data:
            req.add_header('Content-Type', 'application/json')
        with urllib.request.urlopen(req, timeout=30, context=self.ctx) as resp:
            if raw:
                return resp
            return json.loads(resp.read().decode())

    # ── 规则 / 用户目录 ──

    def refresh_rules(self, force=False):
        if not force and time.time() - self.last_rules < self.rules_ttl:
            return
        try:
            body = self.api('GET', '/api/v1/plugin/access-audit/rules')
            rules = body.get('data') or []
            self.matcher = Matcher(rules)
            self.last_rules = time.time()
            log('规则已刷新: %d 条' % len(rules))
        except Exception as e:
            log('规则刷新失败: %s' % e)

    def fetch_users(self):
        """拉取面板用户列表（与 xboard-node 原版接口/结构一致）。"""
        path = '/api/v2/server/user' if self.machine_id > 0 else '/api/v1/server/UniProxy/user'
        body = self.api('GET', path)
        return body.get('users') or []

    # ── 上报 ──

    def flusher(self):
        while not self.stop:
            time.sleep(self.flush_interval)
            self.flush()

    def flush(self):
        with self.lock:
            if not self.queue:
                return
            batch, self.queue = self.queue[:self.batch_size], self.queue[self.batch_size:]
        try:
            body = self.api('POST', '/api/v1/plugin/access-audit/report',
                            {'events': batch})
            d = body.get('data') or {}
            log('上报 %d 条: matched=%s banned=%s' % (len(batch), d.get('matched'), d.get('banned')))
        except Exception as e:
            log('上报失败(重新入队): %s' % e)
            with self.lock:
                self.queue = batch + self.queue

    # ── 主循环 ──

    def run(self):
        self.refresh_rules(force=True)
        if self.user_ttl > 0:
            self.dir.refresh(force=True)
        t = threading.Thread(target=self.flusher, daemon=True)
        t.start()
        log('开始跟踪日志: %s (node_id=%d, 面板=%s)' % (self.log_path, self.node_id, self.panel))

        for line in LogTailer(self.log_path).lines():
            if self.stop:
                break
            self.refresh_rules()
            parsed = parse_line(line)
            if not parsed:
                continue
            kind, val, target, src = parsed

            if kind == 'id':
                uid = val
            else:
                if self.user_ttl <= 0:
                    if not self._warned_uuid:
                        self._warned_uuid = True
                        log('日志中的用户标识是 uuid，但 user_refresh=0 已关闭用户目录，'
                            '该条及后续 uuid 记录将被跳过（请设置 user_refresh > 0）')
                    continue
                self.dir.refresh()
                uid = self.dir.resolve(val)
                if uid is None:
                    continue

            if not self.matcher.match(target):
                continue
            ev = {'user_id': uid, 'target': target}
            if src:
                ev['source_ip'] = src
            with self.lock:
                self.queue.append(ev)
            if len(self.queue) >= self.batch_size:
                self.flush()


def log(msg):
    print('[%s] %s' % (time.strftime('%Y-%m-%d %H:%M:%S'), msg), flush=True)


def main():
    if len(sys.argv) != 2:
        print('用法: python3 audit-agent.py /path/to/audit-agent.yml')
        sys.exit(1)
    cfg = load_config(sys.argv[1])
    for k in ('panel_url', 'log_path'):
        if not cfg.get(k):
            print('配置缺少必填项: %s' % k)
            sys.exit(1)
    if not (cfg.get('server_token') or cfg.get('api_secret')):
        print('配置缺少必填项: server_token（面板节点通讯密钥）')
        sys.exit(1)
    if not cfg.get('node_id'):
        print('配置缺少必填项: node_id')
        sys.exit(1)

    agent = Agent(cfg)

    def on_sig(*_):
        agent.stop = True
        agent.flush()
        sys.exit(0)
    signal.signal(signal.SIGTERM, on_sig)
    signal.signal(signal.SIGINT, on_sig)

    agent.run()


if __name__ == '__main__':
    main()
