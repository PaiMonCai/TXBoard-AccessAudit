const BRIDGE_VERSION = 1;
const app = document.getElementById("app");
let context = null;
const state = {
  page: 1,
  keyword: "",
  matched: "",
  range: "24h",
  nodeId: ""
};

function esc(value) {
  return String(value == null ? "" : value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function fmtTime(value) {
  const n = Number(value || 0);
  if (!n) return "-";
  return new Date(n < 10000000000 ? n * 1000 : n).toLocaleString();
}

function unwrap(payload) {
  return payload && Object.prototype.hasOwnProperty.call(payload, "data") ? payload.data : payload;
}

async function request(path, options) {
  if (!context) throw new Error("TXBoard Bridge 尚未初始化");
  const init = Object.assign({ method: "GET" }, options || {});
  const headers = Object.assign({ Accept: "application/json" }, init.headers || {});
  if (context.auth && context.auth.authorization) headers.Authorization = context.auth.authorization;
  if (init.body && typeof init.body !== "string") {
    headers["Content-Type"] = "application/json";
    init.body = JSON.stringify(init.body);
  }
  init.headers = headers;

  const root = context.api && context.api.root ? String(context.api.root).replace(/\/+$/, "") : "";
  const response = await fetch(root + path, init);
  let payload = null;
  try { payload = await response.json(); } catch (_) {}
  if (!response.ok) {
    const message =
      payload && payload.error && payload.error.message ||
      payload && payload.message ||
      "请求失败 (" + response.status + ")";
    throw new Error(message);
  }
  return unwrap(payload);
}

function ready() {
  if (window.parent === window) return;
  window.parent.postMessage({ type: "txboard:plugin:ready", version: BRIDGE_VERSION }, window.location.origin);
}

function navigateHost(path) {
  if (window.parent === window) return;
  window.parent.postMessage({
    type: "txboard:plugin:navigate",
    version: BRIDGE_VERSION,
    path: path
  }, window.location.origin);
}

function bindHostNavigation() {
  document.querySelectorAll("[data-host-nav]").forEach(function (node) {
    node.addEventListener("click", function () {
      navigateHost(node.getAttribute("data-host-nav"));
    });
  });
}

function shell(content) {
  return '<div class="page">' +
    '<div class="toolbar">' +
      '<button class="button" data-host-nav="rules">规则管理</button>' +
      '<button class="button" data-host-nav="reports">命中记录</button>' +
      '<button class="button" data-host-nav="ban-logs">封禁记录</button>' +
      '<button class="button" data-host-nav="settings">插件设置</button>' +
      '<span class="spacer"></span>' +
      '<button class="button" id="view-dashboard">概览</button>' +
      '<button class="button" id="view-analytics">分析</button>' +
    '</div>' +
    content +
  '</div>';
}

function bindViewTabs() {
  const dashboard = document.getElementById("view-dashboard");
  const analytics = document.getElementById("view-analytics");
  if (dashboard) dashboard.addEventListener("click", function () { location.hash = "#/dashboard"; });
  if (analytics) analytics.addEventListener("click", function () { location.hash = "#/analytics"; });
}

function renderError(error) {
  app.innerHTML = shell('<div class="notice error"><strong>AccessAudit 页面加载失败</strong><br>' + esc(error.message || error) + '</div>');
  bindHostNavigation();
  bindViewTabs();
}

function metric(label, value, hint) {
  return '<div class="card metric"><span>' + esc(label) + '</span><strong>' +
    esc(value == null ? "—" : value) + '</strong>' +
    (hint ? '<small>' + esc(hint) + '</small>' : '') + '</div>';
}

function table(headers, rows, emptyText) {
  let html = '<div class="table-wrap"><table><thead><tr>';
  headers.forEach(function (header) { html += '<th>' + esc(header) + '</th>'; });
  html += '</tr></thead><tbody>';
  if (!rows.length) {
    html += '<tr><td class="empty" colspan="' + headers.length + '">' + esc(emptyText || "暂无数据") + '</td></tr>';
  } else {
    rows.forEach(function (row) {
      html += "<tr>";
      row.forEach(function (cell) { html += "<td>" + cell + "</td>"; });
      html += "</tr>";
    });
  }
  return html + "</tbody></table></div>";
}

async function dashboard() {
  app.innerHTML = shell('<div class="notice">正在加载访问审计数据…</div>');
  bindHostNavigation();
  bindViewTabs();

  try {
    const query = new URLSearchParams({ page: String(state.page) });
    if (state.keyword) query.set("keyword", state.keyword);
    if (state.matched !== "") query.set("matched", state.matched);

    const results = await Promise.all([
      request("/plugin/access-audit/stats"),
      request("/plugin/access-audit/nodes"),
      request("/plugin/access-audit/logs?" + query.toString())
    ]);
    const stats = results[0] || {};
    const nodes = results[1] || [];
    const logs = results[2] || { list: [], total: 0, page: 1, pages: 1 };

    const metrics =
      '<div class="metrics">' +
      metric("今日访问", stats.logs_today, "昨日 " + Number(stats.logs_yesterday || 0)) +
      metric("今日命中", stats.reports_today, "累计 " + Number(stats.reports_total || 0)) +
      metric("今日封禁", stats.bans_today, "累计 " + Number(stats.bans_total || 0)) +
      metric("在线审计节点", Number(stats.nodes_online || 0) + "/" + Number(stats.nodes_total || 0), "启用规则 " + Number(stats.rules_enabled || 0) + "/" + Number(stats.rules_total || 0)) +
      '</div>';

    const nodeRows = nodes.map(function (node) {
      return [
        esc(node.node_name || ("节点 #" + node.node_id)),
        esc(node.silent_minutes == null ? "-" : node.silent_minutes + " 分钟"),
        esc(node.total_events || 0),
        esc(node.total_matched || 0),
        esc(node.total_banned || 0)
      ];
    });

    const logRows = (logs.list || []).map(function (row) {
      return [
        esc(row.node_name || ("节点 #" + row.node_id)),
        esc(row.user_email || ("#" + row.user_id)),
        "<code>" + esc(row.target || "-") + "</code>",
        esc(row.target_ip || "-"),
        esc(row.source_ip || "-"),
        row.matched ? '<span class="status warn">是</span>' : '<span class="status ok">否</span>',
        esc(fmtTime(row.created_at))
      ];
    });

    const body =
      metrics +
      '<div class="two-col">' +
        '<section class="card"><div class="head"><div><h3>节点健康</h3><p>只统计曾经上报过审计数据的节点。</p></div></div>' +
          table(["节点","静默","事件","命中","封禁"], nodeRows, "暂无节点上报") +
        '</section>' +
        '<section class="card"><div class="head"><div><h3>手动封禁</h3><p>通过 AccessAudit 后端执行封禁或解封。</p></div></div>' +
          '<div class="fields">' +
            '<label class="field"><span>用户邮箱</span><input id="ban-email" placeholder="user@example.com"></label>' +
            '<label class="field"><span>原因</span><input id="ban-reason" placeholder="可选"></label>' +
          '</div>' +
          '<div class="actions"><button class="button danger" id="ban-user">封禁</button><button class="button" id="unban-user">解封</button></div>' +
        '</section>' +
      '</div>' +
      '<section class="card">' +
        '<div class="head"><div><h3>访问日志</h3><p>全量日志只在节点开启 report_all 时产生。</p></div><button class="button danger" id="clear-logs">清空日志</button></div>' +
        '<div class="filters"><input id="log-keyword" placeholder="筛选目标域名/IP…" value="' + esc(state.keyword) + '">' +
          '<select id="log-matched"><option value="">全部</option><option value="1">已命中</option><option value="0">未命中</option></select>' +
          '<button class="button" id="apply-filter">查询</button></div>' +
        table(["节点","用户","目标","目标 IP","源 IP","命中","时间"], logRows, "暂无访问日志") +
        '<div class="pagination"><span>共 ' + Number(logs.total || 0) + ' 条 · 第 ' + Number(logs.page || state.page) + ' / ' + Number(logs.pages || 1) + ' 页</span>' +
          '<div class="actions"><button class="button" id="prev-page">上一页</button><button class="button" id="next-page">下一页</button></div></div>' +
      '</section>';

    app.innerHTML = shell(body);
    bindHostNavigation();
    bindViewTabs();

    const matchedSelect = document.getElementById("log-matched");
    if (matchedSelect) matchedSelect.value = state.matched;

    document.getElementById("apply-filter").addEventListener("click", function () {
      state.keyword = document.getElementById("log-keyword").value.trim();
      state.matched = document.getElementById("log-matched").value;
      state.page = 1;
      dashboard();
    });

    document.getElementById("prev-page").disabled = state.page <= 1;
    document.getElementById("next-page").disabled = state.page >= Number(logs.pages || 1);
    document.getElementById("prev-page").addEventListener("click", function () {
      state.page = Math.max(1, state.page - 1);
      dashboard();
    });
    document.getElementById("next-page").addEventListener("click", function () {
      state.page += 1;
      dashboard();
    });

    async function userAction(action) {
      const email = document.getElementById("ban-email").value.trim();
      const reason = document.getElementById("ban-reason").value.trim();
      if (!email) return alert("请输入用户邮箱");
      try {
        const result = await request("/plugin/access-audit/" + action, {
          method: "POST",
          body: { email: email, reason: reason }
        });
        alert(result && result.message ? result.message : "操作成功");
        dashboard();
      } catch (error) {
        alert(error.message || error);
      }
    }

    document.getElementById("ban-user").addEventListener("click", function () { userAction("ban"); });
    document.getElementById("unban-user").addEventListener("click", function () { userAction("unban"); });
    document.getElementById("clear-logs").addEventListener("click", async function () {
      if (!confirm("确认清空全部访问日志？规则、命中记录与分析聚合不会被删除。")) return;
      try {
        const result = await request("/plugin/access-audit/logs/clear", { method: "POST", body: {} });
        alert(result && result.message ? result.message : "日志已清空");
        state.page = 1;
        dashboard();
      } catch (error) {
        alert(error.message || error);
      }
    });
  } catch (error) {
    renderError(error);
  }
}

function polyline(points, key, width, height, maxValue) {
  if (!points.length) return "";
  const left = 24, right = 12, top = 12, bottom = 24;
  const innerW = width - left - right;
  const innerH = height - top - bottom;
  const coords = points.map(function (point, index) {
    const x = left + (points.length === 1 ? innerW / 2 : innerW * index / (points.length - 1));
    const y = top + innerH - (Number(point[key] || 0) / maxValue) * innerH;
    return x.toFixed(1) + "," + y.toFixed(1);
  }).join(" ");
  const cls = key === "events" ? "events" : "matched";
  return '<polyline class="' + cls + '" fill="none" stroke-width="2.5" points="' + coords + '"/>';
}

function trendChart(points) {
  const width = 900, height = 250;
  const maxValue = Math.max(1, ...points.map(function (p) {
    return Math.max(Number(p.events || 0), Number(p.matched || 0));
  }));
  return '<div class="chart"><svg viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="访问命中趋势">' +
    '<line x1="24" y1="226" x2="888" y2="226" stroke="var(--line)"/>' +
    '<line x1="24" y1="12" x2="24" y2="226" stroke="var(--line)"/>' +
    '<g style="stroke:var(--primary)">' + polyline(points, "events", width, height, maxValue) + '</g>' +
    '<g style="stroke:var(--danger)">' + polyline(points, "matched", width, height, maxValue) + '</g>' +
    '</svg></div>' +
    '<div class="legend"><span><i class="dot events"></i>访问</span><span><i class="dot matched"></i>命中</span><span>峰值 ' + esc(maxValue) + '</span></div>';
}

function ranking(title, headers, rows) {
  return '<section class="card"><div class="head"><div><h3>' + esc(title) + '</h3></div></div>' +
    table(headers, rows, "暂无数据") + '</section>';
}

async function analytics() {
  app.innerHTML = shell('<div class="notice">正在加载审计分析…</div>');
  bindHostNavigation();
  bindViewTabs();

  try {
    const nodes = await request("/plugin/access-audit/nodes");
    const params = new URLSearchParams({ range: state.range });
    if (state.nodeId) params.set("node_id", state.nodeId);
    const data = await request("/plugin/access-audit/analytics?" + params.toString());

    const nodeOptions = (nodes || []).map(function (node) {
      const selected = String(node.node_id) === String(state.nodeId) ? " selected" : "";
      return '<option value="' + esc(node.node_id) + '"' + selected + '>' + esc(node.node_name) + '</option>';
    }).join("");

    const summary = data.summary || {};
    const body =
      '<section class="card"><div class="filters">' +
        '<select id="range"><option value="1h">最近 1 小时</option><option value="24h">最近 24 小时</option><option value="7d">最近 7 天</option><option value="30d">最近 30 天</option></select>' +
        '<select id="node"><option value="">全部节点</option>' + nodeOptions + '</select>' +
        '<button class="button" id="refresh-analysis">刷新</button>' +
      '</div>' +
      (data.coverage ? '<div class="notice">趋势来源：' + esc(data.coverage.trend_source) + ' · 明细保留 ' + esc(data.coverage.detail_retention_days) + ' 天</div>' : '') +
      '</section>' +
      '<div class="metrics">' +
        metric("访问事件", summary.events) +
        metric("规则命中", summary.matched) +
        metric("命中率", summary.match_rate == null ? "—" : summary.match_rate + "%") +
        metric("活跃用户", summary.active_users) +
        metric("封禁", summary.bans == null ? "节点筛选下不可用" : summary.bans) +
      '</div>' +
      '<section class="card"><div class="head"><div><h3>访问 / 命中趋势</h3><p>短周期使用原始数据，7d/30d 优先使用小时聚合。</p></div></div>' +
        trendChart(data.trend || []) +
      '</section>' +
      '<div class="two-col">' +
        ranking("节点排行", ["节点","访问","命中","命中率"], (data.nodes || []).map(function (x) {
          return [esc(x.node_name), esc(x.events), esc(x.matched), esc(x.match_rate == null ? "-" : x.match_rate + "%")];
        })) +
        ranking("规则排行", ["规则","命中","用户","封禁"], (data.rules || []).map(function (x) {
          return [esc(x.rule_name), esc(x.hits), esc(x.users), esc(x.bans == null ? "-" : x.bans)];
        })) +
        ranking("活跃用户", ["用户","访问","命中"], (data.top_users || []).map(function (x) {
          return [esc(x.user_email), esc(x.events), esc(x.matched)];
        })) +
        ranking("热门目标", ["目标","访问","命中"], (data.top_targets || []).map(function (x) {
          return ["<code>" + esc(x.target) + "</code>", esc(x.events), esc(x.matched)];
        })) +
      '</div>';

    app.innerHTML = shell(body);
    bindHostNavigation();
    bindViewTabs();

    document.getElementById("range").value = state.range;
    document.getElementById("node").value = state.nodeId;
    document.getElementById("refresh-analysis").addEventListener("click", function () {
      state.range = document.getElementById("range").value;
      state.nodeId = document.getElementById("node").value;
      analytics();
    });
  } catch (error) {
    renderError(error);
  }
}

function render() {
  if (!context) return;
  const view = location.hash === "#/analytics" ? "analytics" : "dashboard";
  if (view === "analytics") analytics();
  else dashboard();
}

window.addEventListener("message", function (event) {
  if (event.origin !== window.location.origin) return;
  const message = event.data;
  if (!message || message.type !== "txboard:plugin:init" || message.version !== BRIDGE_VERSION) return;
  if (!message.plugin || message.plugin.code !== "access_audit") return;
  context = message;
  render();
});

window.addEventListener("hashchange", render);

setTimeout(function () {
  if (!context && window.parent === window) {
    app.innerHTML = '<div class="boot"><strong>AccessAudit</strong><span>请从 TXBoard Admin 的插件管理页面打开此应用。</span></div>';
  }
}, 800);

ready();
