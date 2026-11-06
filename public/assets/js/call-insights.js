/* Call Insights for MikoPBX | GPL-3.0-or-later | built from public/assets/js/src */
(() => {
  // public/assets/js/src/call-insights.js
  var BASE = `${globalRootUrl}module-call-insights/`;
  var t = (key, fallback) => globalTranslate && globalTranslate[key] || fallback;
  var OUTCOMES = {
    answered: ["ci_OutcomeAnswered", "Answered", "#21ba45"],
    voicemail: ["ci_OutcomeVoicemail", "Voicemail", "#a333c8"],
    no_answer: ["ci_OutcomeNoAnswer", "No answer", "#f2711c"],
    abandoned_queue: ["ci_OutcomeAbandonedQueue", "Abandoned in queue", "#db2828"],
    abandoned_ivr: ["ci_OutcomeAbandonedIvr", "Left in the IVR", "#e07b7b"],
    busy: ["ci_OutcomeBusy", "Busy", "#fbbd08"],
    failed: ["ci_OutcomeFailed", "Failed", "#767676"],
    out_of_hours: ["ci_OutcomeOutOfHours", "Out of hours", "#00b5ad"]
  };
  var DIRECTIONS = { in: ["ci_Inbound", "Inbound"], out: ["ci_Outbound", "Outbound"], internal: ["ci_Internal", "Internal"] };
  var WEEKDAYS = ["ci_Mon", "ci_Tue", "ci_Wed", "ci_Thu", "ci_Fri", "ci_Sat", "ci_Sun"];
  var WEEKDAY_FALLBACK = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
  var state = {
    from: "",
    to: "",
    compare: false,
    filters: {},
    tab: "overview",
    labels: { extensions: {}, providers: {} },
    charts: {},
    drill: null,
    loading: 0
  };
  var $id = (id) => document.getElementById(id);
  function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => {
      if (k === "class") node.className = v;
      else if (k === "text") node.textContent = v;
      else if (k.startsWith("on")) node.addEventListener(k.slice(2), v);
      else node.setAttribute(k, v);
    });
    children.forEach((c) => node.append(c instanceof Node ? c : document.createTextNode(String(c))));
    return node;
  }
  var esc = (s) => echarts.format.encodeHTML(String(s));
  var isoDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  var daysBetween = (a, b) => Math.round((/* @__PURE__ */ new Date(`${b}T00:00:00`) - /* @__PURE__ */ new Date(`${a}T00:00:00`)) / 864e5) + 1;
  var outcomeLabel = (o) => OUTCOMES[o] ? t(OUTCOMES[o][0], OUTCOMES[o][1]) : o;
  var directionLabel = (d) => DIRECTIONS[d] ? t(DIRECTIONS[d][0], DIRECTIONS[d][1]) : d;
  var weekdayLabel = (n) => t(WEEKDAYS[n - 1], WEEKDAY_FALLBACK[n - 1]);
  function numberLabel(number) {
    if (number === null || number === void 0 || number === "") return t("ci_None", "\u2014");
    const name = state.labels.extensions[number];
    return name ? `${name} (${number})` : String(number);
  }
  function duration(seconds) {
    if (seconds === null || seconds === void 0) return "\u2014";
    const s = Math.round(seconds);
    if (s < 60) return `${s} s`;
    const m = Math.floor(s / 60);
    if (m < 60) return `${m} min ${s % 60} s`;
    return `${Math.floor(m / 60)} h ${m % 60} min`;
  }
  function intervalFor(from, to) {
    const days = daysBetween(from, to);
    if (days <= 62) return "day";
    if (days <= 370) return "week";
    return "month";
  }
  function params(extra = {}) {
    const p = { from: state.from, to: state.to, ...extra };
    const filters = { ...state.filters, ...extra.filters || {} };
    delete p.filters;
    Object.entries(filters).forEach(([name, values]) => {
      if (values && values.length) p[`filters[${name}]`] = values;
    });
    return p;
  }
  function get(action, data) {
    state.loading += 1;
    return $.ajax({ url: BASE + action, method: "GET", data, dataType: "json", traditional: false }).then((r) => r && r.success ? r.data : $.Deferred().reject(r && r.message)).fail((xhr) => {
      const msg = xhr && xhr.responseJSON && xhr.responseJSON.message || (typeof xhr === "string" ? xhr : null);
      showError(msg || t("ci_LoadFailed", "Call Insights could not load this data."));
    }).always(() => {
      state.loading -= 1;
    });
  }
  function showError(message) {
    const box = $id("ci-error");
    box.textContent = message;
    box.classList.remove("ci-hidden");
  }
  var clearError = () => $id("ci-error").classList.add("ci-hidden");
  function chart(id) {
    if (!state.charts[id]) {
      state.charts[id] = echarts.init($id(id), null, { renderer: "canvas" });
    }
    return state.charts[id];
  }
  function applyPreset(preset) {
    const today = /* @__PURE__ */ new Date();
    let from = new Date(today);
    if (preset === "today") {
    } else if (preset === "month") {
      from = new Date(today.getFullYear(), today.getMonth(), 1);
    } else if (preset !== "custom") {
      from.setDate(today.getDate() - (Number(preset) - 1));
    }
    if (preset !== "custom") {
      $id("ci-from").value = isoDate(from);
      $id("ci-to").value = isoDate(today);
    }
  }
  function readHeader() {
    state.from = $id("ci-from").value;
    state.to = $id("ci-to").value;
    state.compare = $id("ci-compare").checked;
    state.filters = {};
    ["direction", "did", "queue", "answered_by"].forEach((name) => {
      const v = $id(`ci-filter-${name}`).value;
      if (v !== "") state.filters[name] = [v];
    });
    saveToUrl();
  }
  function saveToUrl() {
    const q = new URLSearchParams();
    q.set("from", state.from);
    q.set("to", state.to);
    if (state.compare) q.set("compare", "1");
    q.set("tab", state.tab);
    Object.entries(state.filters).forEach(([k, v]) => q.set(k, v[0]));
    window.history.replaceState(null, "", `${window.location.pathname}?${q.toString()}`);
  }
  function loadFromUrl() {
    const q = new URLSearchParams(window.location.search);
    const valid = (d) => /^\d{4}-\d{2}-\d{2}$/.test(d || "");
    if (valid(q.get("from")) && valid(q.get("to"))) {
      $id("ci-preset").value = "custom";
      $id("ci-from").value = q.get("from");
      $id("ci-to").value = q.get("to");
    } else {
      applyPreset($id("ci-preset").value);
    }
    $id("ci-compare").checked = q.get("compare") === "1";
    ["direction", "did", "queue", "answered_by"].forEach((name) => {
      if (q.get(name)) $id(`ci-filter-${name}`).dataset.pending = q.get(name);
    });
    if (["overview", "traffic", "team"].includes(q.get("tab"))) state.tab = q.get("tab");
  }
  function fillSelect(name, values, label) {
    const select = $id(`ci-filter-${name}`);
    const current = select.dataset.pending || select.value;
    while (select.options.length > 1) select.remove(1);
    values.forEach((v) => select.add(new Option(label(v), v)));
    if (current && values.includes(current)) select.value = current;
    delete select.dataset.pending;
    $(select).dropdown("set selected", select.value || "");
  }
  function loadFilterChoices() {
    const keysOf = (r) => r.current.series.map((s) => s.key).filter((k) => k !== "");
    const base = { from: state.from, to: state.to, metric: "calls", interval: "none" };
    return $.when(
      get("query", { ...base, breakdown: "did" }),
      get("query", { ...base, breakdown: "queue" }),
      get("query", { ...base, breakdown: "answered_by" })
    ).then((dids, queues, people) => {
      fillSelect("did", keysOf(dids), (v) => v);
      fillSelect("queue", keysOf(queues), numberLabel);
      fillSelect("answered_by", keysOf(people), numberLabel);
    });
  }
  var KPIS = [
    ["calls", "ci_KpiCalls", "Calls", "count"],
    ["answer_rate", "ci_KpiAnswerRate", "Answered (inbound)", "percent"],
    ["missed", "ci_KpiMissed", "Missed", "count", "bad"],
    ["voicemail", "ci_KpiVoicemail", "Voicemail", "count"],
    ["service_level", "ci_KpiServiceLevel", "Service level", "percent"],
    ["avg_wait", "ci_KpiAvgWait", "Avg wait", "seconds", "bad"],
    ["avg_talk", "ci_KpiAvgTalk", "Avg talk", "seconds"],
    ["unique_callers", "ci_KpiUniqueCallers", "Unique callers", "count"]
  ];
  function formatKpi(value, kind) {
    if (value === null || value === void 0) return "\u2014";
    if (kind === "percent") return `${value}%`;
    if (kind === "seconds") return duration(value);
    return Number(value).toLocaleString();
  }
  function changeChip(cur, prev, kind, badWhenUp) {
    if (!state.compare || prev === null || prev === void 0 || cur === null || cur === void 0) return null;
    let text;
    let delta;
    if (kind === "percent") {
      delta = Math.round((cur - prev) * 10) / 10;
      text = `${delta >= 0 ? "+" : ""}${delta} ${t("ci_Points", "pts")}`;
    } else {
      if (prev === 0) return el("div", { class: "ci-change flat", text: t("ci_NoPrevious", "no calls before") });
      delta = Math.round((cur - prev) / prev * 100);
      text = `${delta >= 0 ? "+" : ""}${delta}%`;
    }
    const good = badWhenUp ? delta < 0 : delta > 0;
    const cls = delta === 0 ? "flat" : good ? "up" : "down";
    return el("div", { class: `ci-change ${cls}`, text: `${text} ${t("ci_VsPrevious", "vs previous")}` });
  }
  function loadKpis() {
    const box = $id("ci-kpis");
    const requests = KPIS.map(([metric]) => get("query", params({ metric, interval: "none", compare: state.compare ? "1" : "0" })));
    return $.when(...requests).then((...results) => {
      box.replaceChildren();
      results.forEach((r, i) => {
        const [metric, key, fallback, kind, bad] = KPIS[i];
        const cur = r.current.series.length ? r.current.series[0].values[0] : kind === "count" ? 0 : null;
        const prev = r.previous ? r.previous.series.length ? r.previous.series[0].values[0] : kind === "count" ? 0 : null : null;
        const tile = el(
          "div",
          { class: "statistic", title: t(`${key}Hint`, "") },
          el("div", { class: "value", text: formatKpi(cur, kind) }),
          el("div", { class: "label", text: t(key, fallback) })
        );
        const chip = changeChip(cur, prev, kind, bad === "bad");
        if (chip) tile.append(chip);
        if (metric === "missed") tile.addEventListener("click", () => openDrill(t("ci_KpiMissed", "Missed"), { direction: ["in"], outcome: ["no_answer", "abandoned_queue", "abandoned_ivr", "busy", "failed", "out_of_hours"] }));
        if (metric === "voicemail") tile.addEventListener("click", () => openDrill(t("ci_KpiVoicemail", "Voicemail"), { outcome: ["voicemail"] }));
        if (metric === "calls") tile.addEventListener("click", () => openDrill(t("ci_KpiCalls", "Calls"), {}));
        box.append(tile);
      });
    });
  }
  function tooltipFormatter(params2) {
    const rows = (Array.isArray(params2) ? params2 : [params2]).filter((p) => p.value !== null && p.value !== void 0);
    if (!rows.length) return "";
    const head = esc(rows[0].axisValueLabel || rows[0].name);
    return `${head}<br>${rows.map((p) => `${p.marker}${esc(p.seriesName)}: <b>${esc(p.value)}</b>`).join("<br>")}`;
  }
  function loadOutcomeChart() {
    const interval = intervalFor(state.from, state.to);
    return get("query", params({ metric: "calls", interval, breakdown: "outcome", compare: state.compare ? "1" : "0" })).then((r) => {
      const cur = r.current;
      const series = cur.series.map((s) => ({
        name: outcomeLabel(s.key),
        type: "line",
        stack: "calls",
        areaStyle: { opacity: 0.6 },
        symbol: "none",
        data: s.values,
        itemStyle: { color: (OUTCOMES[s.key] || [])[2] },
        outcome: s.key
      }));
      if (r.previous) {
        const totals = r.previous.buckets.map((_, i) => r.previous.series.reduce((sum, s) => sum + (s.values[i] || 0), 0));
        series.push({
          name: t("ci_PreviousPeriod", "Previous period"),
          type: "line",
          symbol: "none",
          lineStyle: { type: "dashed", color: "#767676" },
          itemStyle: { color: "#767676" },
          data: cur.buckets.map((_, i) => i < totals.length ? totals[i] : null)
        });
      }
      const c = chart("ci-chart-outcomes");
      c.setOption({
        tooltip: { trigger: "axis", formatter: tooltipFormatter },
        legend: { top: 0 },
        grid: { left: 50, right: 20, top: 40, bottom: 60 },
        xAxis: { type: "category", data: cur.buckets, boundaryGap: false },
        yAxis: { type: "value", minInterval: 1 },
        dataZoom: [{ type: "slider", bottom: 10 }, { type: "inside" }],
        series
      }, true);
      c.off("click");
      c.on("click", (p) => {
        const s = series[p.seriesIndex];
        if (!s.outcome || interval !== "day") return;
        openDrill(`${outcomeLabel(s.outcome)} \xB7 ${p.name}`, { outcome: [s.outcome], local_date: [p.name] });
      });
      c.off("datazoom");
      c.on("datazoom", () => {
        var _a, _b;
        const opt = c.getOption().dataZoom[0];
        const buckets = cur.buckets;
        const a = buckets[(_a = opt.startValue) != null ? _a : 0];
        const b = buckets[(_b = opt.endValue) != null ? _b : buckets.length - 1];
        if (interval === "day" && a && b && (a !== state.from || b !== state.to)) {
          clearTimeout(state.zoomTimer);
          state.zoomTimer = setTimeout(() => {
            $id("ci-preset").value = "custom";
            $(`#ci-preset`).dropdown("set selected", "custom");
            $id("ci-from").value = a;
            $id("ci-to").value = b;
            refresh();
          }, 600);
        }
      });
    });
  }
  function loadHeatmap() {
    if (daysBetween(state.from, state.to) > 92) {
      chart("ci-chart-heatmap").setOption({ title: { text: t("ci_HeatmapRange", "Choose 92 days or fewer for the hour-by-weekday view."), left: "center", top: "middle", textStyle: { fontSize: 13, fontWeight: "normal" } } }, true);
      return $.when();
    }
    return $.when(...[1, 2, 3, 4, 5, 6, 7].map((wd) => get("query", params({ metric: "calls", interval: "none", breakdown: "hour", filters: { direction: ["in"], weekday: [String(wd)] } })))).then((...byDay) => {
      const data = [];
      let max = 0;
      byDay.forEach((r, i) => {
        r.current.series.forEach((s) => {
          const v = s.values[0];
          max = Math.max(max, v);
          data.push([Number(s.key), i, v]);
        });
      });
      const hours = Array.from({ length: 24 }, (_, h) => `${String(h).padStart(2, "0")}:00`);
      const days = [1, 2, 3, 4, 5, 6, 7].map(weekdayLabel);
      const c = chart("ci-chart-heatmap");
      c.setOption({
        tooltip: { formatter: (p) => `${esc(days[p.value[1]])} ${esc(hours[p.value[0]])}<br>${esc(t("ci_InboundCalls", "Inbound calls"))}: <b>${esc(p.value[2])}</b>` },
        grid: { left: 60, right: 20, top: 10, bottom: 80 },
        xAxis: { type: "category", data: hours, splitArea: { show: true } },
        yAxis: { type: "category", data: days, inverse: true, splitArea: { show: true } },
        visualMap: { min: 0, max: Math.max(1, max), calculable: true, orient: "horizontal", left: "center", bottom: 0, itemHeight: 120 },
        series: [{ type: "heatmap", data, emphasis: { itemStyle: { borderColor: "#333", borderWidth: 1 } } }]
      }, true);
      c.off("click");
      c.on("click", (p) => openDrill(`${days[p.value[1]]} ${hours[p.value[0]]}`, { direction: ["in"], weekday: [String(p.value[1] + 1)], hour: [String(p.value[0])] }));
    });
  }
  function loadCalendar() {
    const days = daysBetween(state.from, state.to);
    if (days > 366) {
      chart("ci-chart-calendar").clear();
      return $.when();
    }
    return get("query", params({ metric: "calls", interval: "day" })).then((r) => {
      const series = r.current.series[0] || { values: [] };
      const data = r.current.buckets.map((b, i) => [b, series.values[i] || 0]);
      const c = chart("ci-chart-calendar");
      if (days <= 92) {
        c.setOption({
          tooltip: { trigger: "axis", formatter: tooltipFormatter },
          grid: { left: 50, right: 20, top: 10, bottom: 30 },
          xAxis: { type: "category", data: r.current.buckets },
          yAxis: { type: "value", minInterval: 1 },
          series: [{ name: t("ci_KpiCalls", "Calls"), type: "bar", data: data.map((d) => d[1]), itemStyle: { color: "#2185d0" } }]
        }, true);
        c.off("click");
        c.on("click", (p) => openDrill(p.name, { local_date: [p.name] }));
        return;
      }
      const max = Math.max(1, ...data.map((d) => d[1]));
      c.setOption({
        tooltip: { formatter: (p) => `${esc(p.value[0])}<br>${esc(t("ci_KpiCalls", "Calls"))}: <b>${esc(p.value[1])}</b>` },
        visualMap: { min: 0, max, show: false, inRange: { color: ["#eef5fb", "#2185d0"] } },
        calendar: {
          range: [state.from, state.to],
          cellSize: ["auto", 16],
          top: 30,
          left: 50,
          right: 20,
          dayLabel: { firstDay: 1 },
          yearLabel: { show: false },
          itemStyle: { borderWidth: 1, borderColor: "#fff" },
          splitLine: { lineStyle: { color: "#bbb" } }
        },
        series: [{ type: "heatmap", coordinateSystem: "calendar", data }]
      }, true);
      c.off("click");
      c.on("click", (p) => openDrill(p.value[0], { local_date: [p.value[0]] }));
    });
  }
  function loadTeam() {
    const q = (metric, extra = {}) => get("query", params({ metric, interval: "none", breakdown: "answered_by", ...extra }));
    return $.when(q("answered"), q("talk_seconds"), q("avg_talk"), q("avg_wait")).then((answered, talk, avgTalk, avgWait) => {
      const byKey = (r) => Object.fromEntries(r.current.series.map((s) => [s.key, s.values[0]]));
      const a = byKey(answered);
      const tk = byKey(talk);
      const at = byKey(avgTalk);
      const aw = byKey(avgWait);
      const rows = Object.keys(a).filter((k) => k !== "" && a[k] > 0).map((k) => ({
        key: k,
        name: numberLabel(k),
        answered: a[k],
        talk: tk[k] || 0,
        avg_talk: at[k],
        avg_wait: aw[k]
      }));
      const total = rows.reduce((s, r) => s + r.answered, 0) || 1;
      rows.forEach((r) => {
        r.share = r.answered / total;
      });
      state.teamRows = rows;
      renderTeam(state.teamSort || "answered", state.teamDesc !== false);
    });
  }
  function renderTeam(sortKey, desc) {
    state.teamSort = sortKey;
    state.teamDesc = desc;
    const rows = [...state.teamRows || []].sort((x, y) => {
      const a = x[sortKey];
      const b = y[sortKey];
      const cmp = typeof a === "string" ? a.localeCompare(b) : (a != null ? a : -1) - (b != null ? b : -1);
      return desc ? -cmp : cmp;
    });
    const body = $id("ci-team").tBodies[0];
    body.replaceChildren();
    if (!rows.length) {
      body.append(el("tr", {}, el("td", { colspan: "6", text: t("ci_NoData", "No calls in this range.") })));
      return;
    }
    const maxShare = Math.max(...rows.map((r) => r.share));
    rows.forEach((r) => {
      const bar = el("span", { class: "ci-bar" });
      bar.style.width = `${Math.round(r.share / maxShare * 8)}em`;
      const tr = el(
        "tr",
        { onclick: () => openDrill(r.name, { answered_by: [r.key], outcome: ["answered"] }) },
        el("td", { text: r.name }),
        el("td", { class: "right aligned", text: r.answered.toLocaleString() }),
        el("td", { class: "right aligned", text: duration(r.talk) }),
        el("td", { class: "right aligned", text: duration(r.avg_talk) }),
        el("td", { class: "right aligned", text: duration(r.avg_wait) }),
        el("td", { class: "ci-bar-cell" }, bar, ` ${Math.round(r.share * 100)}%`)
      );
      body.append(tr);
    });
  }
  function openDrill(title, filters) {
    state.drill = { title, filters, page: 1 };
    $id("ci-drill-title").textContent = `${title} \xB7 ${state.from} \u2013 ${state.to}`;
    loadDrillPage();
    $("#ci-drill").modal("show");
  }
  function loadDrillPage() {
    const d = state.drill;
    const body = $id("ci-drill-table").tBodies[0];
    body.replaceChildren(el("tr", {}, el("td", { colspan: "8", text: t("ci_Loading", "Loading\u2026") })));
    get("calls", params({ filters: d.filters, page: d.page })).then((r) => {
      body.replaceChildren();
      if (!r.calls.length) body.append(el("tr", {}, el("td", { colspan: "8", text: t("ci_NoData", "No calls in this range.") })));
      r.calls.forEach((call) => body.append(...drillRows(call)));
      const first = (r.page - 1) * r.page_size + 1;
      $id("ci-drill-count").textContent = r.total ? `${first}\u2013${first + r.calls.length - 1} / ${r.total}` : "";
      $id("ci-drill-prev").disabled = r.page <= 1;
      $id("ci-drill-next").disabled = r.page * r.page_size >= r.total;
    });
  }
  function drillRows(call) {
    const legsRow = el("tr", { class: "ci-legs ci-hidden" }, el("td", { colspan: "8" }, legsTable(call.legs)));
    const hasRecording = call.legs.some((l) => l.has_recording);
    const playCell = el("td", {});
    if (hasRecording) {
      playCell.append(el("button", {
        class: "ui mini basic icon button",
        title: t("ci_Play", "Play recording"),
        onclick: (e) => {
          e.stopPropagation();
          play(call.linkedid, playCell);
        }
      }, el("i", { class: "play icon" })));
    }
    const to = call.direction === "in" ? call.answered_by || call.first_queue || call.callee_num : call.callee_num;
    const row = el(
      "tr",
      { onclick: () => legsRow.classList.toggle("ci-hidden") },
      el("td", { text: call.start_time.slice(0, 19) }),
      el("td", { text: directionLabel(call.direction) }),
      el("td", { text: numberLabel(call.caller_num) }),
      el("td", { text: numberLabel(to) }),
      el("td", { text: outcomeLabel(call.outcome) + (call.transfer_count > 0 ? ` \xB7 ${t("ci_Transferred", "transferred")}` : "") }),
      el("td", { class: "right aligned", text: duration(call.wait_seconds) }),
      el("td", { class: "right aligned", text: duration(call.talk_seconds) }),
      playCell
    );
    row.style.cursor = "pointer";
    return [row, legsRow];
  }
  function legsTable(legs) {
    const table = el("table", { class: "ui very basic compact small table" });
    const body = el("tbody");
    legs.forEach((l) => {
      const dst = String(l.dst_chan || "");
      let kind = numberLabel(l.dst_num);
      if (dst.startsWith("App:")) kind = `${t("ci_LegIvr", "IVR")} ${numberLabel(l.dst_num)}`;
      if (dst.startsWith("Queue:")) kind = `${t("ci_LegQueue", "Queue")} ${numberLabel(l.dst_num)}`;
      if (dst === "VOICEMAIL") kind = t("ci_LegVoicemail", "Voicemail");
      body.append(el(
        "tr",
        {},
        el("td", { text: String(l.start || "").slice(11, 19) }),
        el("td", { text: `${numberLabel(l.src_num)}${l.src_name ? ` \xB7 ${l.src_name}` : ""}` }),
        el("td", { text: kind }),
        el("td", { text: l.disposition || "" }),
        el("td", { class: "right aligned", text: duration(l.billsec) }),
        el("td", { text: l.dtmf_digits ? `${t("ci_Keys", "keys")} ${l.dtmf_digits}` : "" })
      ));
    });
    table.append(body);
    return table;
  }
  function play(linkedid, cell) {
    cell.replaceChildren(el("span", { text: t("ci_Loading", "Loading\u2026") }));
    $.ajax({ url: "/pbxcore/api/v3/cdr", method: "GET", data: { linkedid, limit: 5 }, dataType: "json" }).then((r) => {
      const records = r && r.data && r.data.records || [];
      const legs = records.flatMap((c) => c.records || []);
      const urls = legs.map((l) => l.playback_url).filter((u) => typeof u === "string" && u.startsWith("/pbxcore/api/v3/cdr:playback?token="));
      cell.replaceChildren();
      if (!urls.length) {
        cell.append(el("span", { text: t("ci_NoRecording", "No recording") }));
        return;
      }
      urls.forEach((u) => cell.append(el("audio", { controls: "", preload: "none", src: u })));
      cell.querySelector("audio").play().catch(() => {
      });
    }).fail(() => cell.replaceChildren(el("span", { text: t("ci_PlaybackFailed", "Playback unavailable") })));
  }
  function loadStatus() {
    return get("status", {}).then((s) => {
      const parts = [];
      if (s.last_run_at) parts.push(`${t("ci_DataAsOf", "Data as of")} ${s.last_run_at.slice(0, 16)}`);
      if (s.oldest_day) parts.push(`${t("ci_HistoryFrom", "history from")} ${s.oldest_day}`);
      $id("ci-asof").textContent = parts.join(" \xB7 ");
      $id("ci-backfill-message").classList.toggle("ci-hidden", !!s.backfill_done);
    });
  }
  function pollLive() {
    if (document.hidden) return;
    $.ajax({ url: "/pbxcore/api/v3/pbx-status:getActiveCalls", method: "GET", dataType: "json" }).then((r) => {
      const n = Array.isArray(r && r.data) ? new Set(r.data.map((c) => c.linkedid || c.UNIQUEID)).size : null;
      $id("ci-live-value").textContent = n === null ? "\u2013" : String(n);
      $id("ci-live").title = t("ci_LiveHint", "Calls in progress right now. 0 can also mean the PBX could not report them.");
    }).fail(() => {
      $id("ci-live-value").textContent = "\u2013";
    });
  }
  function refresh() {
    readHeader();
    clearError();
    if (!state.from || !state.to || state.from > state.to) {
      showError(t("ci_BadRange", "Choose a start date on or before the end date."));
      return;
    }
    loadStatus();
    loadFilterChoices();
    if (state.tab === "overview") {
      loadKpis();
      loadOutcomeChart();
    }
    if (state.tab === "traffic") {
      loadHeatmap();
      loadCalendar();
    }
    if (state.tab === "team") loadTeam();
  }
  function selectTab(tab) {
    state.tab = tab;
    document.querySelectorAll("#call-insights [data-tab]").forEach((n) => n.classList.toggle("active", n.dataset.tab === tab));
    refresh();
    Object.values(state.charts).forEach((c) => c.resize());
  }
  function init() {
    loadFromUrl();
    $("#call-insights .ui.dropdown").dropdown();
    $("#ci-compare-box").checkbox();
    $("#ci-drill").modal({ autofocus: false });
    $id("ci-preset").addEventListener("change", (e) => {
      applyPreset(e.target.value);
      refresh();
    });
    ["ci-from", "ci-to"].forEach((id) => $id(id).addEventListener("change", () => {
      $id("ci-preset").value = "custom";
      $("#ci-preset").dropdown("set selected", "custom");
      refresh();
    }));
    $id("ci-compare").addEventListener("change", refresh);
    ["direction", "did", "queue", "answered_by"].forEach((name) => $id(`ci-filter-${name}`).addEventListener("change", refresh));
    document.querySelectorAll("#ci-tabs .item").forEach((a) => a.addEventListener("click", () => selectTab(a.dataset.tab)));
    document.querySelectorAll("#ci-team th[data-key]").forEach((th) => th.addEventListener("click", () => {
      const key = th.dataset.key;
      renderTeam(key, state.teamSort === key ? !state.teamDesc : key !== "name");
    }));
    $id("ci-drill-prev").addEventListener("click", () => {
      state.drill.page -= 1;
      loadDrillPage();
    });
    $id("ci-drill-next").addEventListener("click", () => {
      state.drill.page += 1;
      loadDrillPage();
    });
    window.addEventListener("resize", () => Object.values(state.charts).forEach((c) => c.resize()));
    get("labels", {}).always((labels) => {
      if (labels && labels.extensions) state.labels = labels;
      selectTab(state.tab);
    });
    pollLive();
    setInterval(pollLive, 1e4);
  }
  $(document).ready(init);
})();
