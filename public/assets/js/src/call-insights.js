/*
 * Call Insights dashboard (GPL-3.0-or-later).
 *
 * Security rules this file follows (plan: Security):
 * - Every string from the server (caller names and numbers, labels) is put
 *   into the page with textContent, never as HTML.
 * - Chart tooltips are built by our own formatters, which escape every value
 *   with echarts.format.encodeHTML. Axis and series labels are drawn on the
 *   canvas, where markup has no effect.
 * - Recording playback links are fetched from the core only when the user
 *   presses play, one call at a time.
 * - CSV exports prefix cells a spreadsheet would read as a formula
 *   (= + - @, tab, CR) with an apostrophe (OWASP CSV injection).
 *
 * The data comes from this module's controller (query, calls, labels,
 * status) and, for the Live now tile and playback, from the core's REST API.
 * jQuery's $.ajax is used for every request: the core's TokenManager adds
 * the Bearer token to it.
 */

/* global globalRootUrl, globalTranslate, echarts, $ */

const BASE = `${globalRootUrl}module-call-insights/`;
const t = (key, fallback) => (globalTranslate && globalTranslate[key]) || fallback;

const OUTCOMES = {
  answered: ['ci_OutcomeAnswered', 'Answered', '#21ba45'],
  voicemail: ['ci_OutcomeVoicemail', 'Voicemail', '#a333c8'],
  no_answer: ['ci_OutcomeNoAnswer', 'No answer', '#f2711c'],
  abandoned_queue: ['ci_OutcomeAbandonedQueue', 'Abandoned in queue', '#db2828'],
  abandoned_ivr: ['ci_OutcomeAbandonedIvr', 'Left in the IVR', '#e07b7b'],
  busy: ['ci_OutcomeBusy', 'Busy', '#fbbd08'],
  failed: ['ci_OutcomeFailed', 'Failed', '#767676'],
  out_of_hours: ['ci_OutcomeOutOfHours', 'Out of hours', '#00b5ad'],
};
const DIRECTIONS = { in: ['ci_Inbound', 'Inbound'], out: ['ci_Outbound', 'Outbound'], internal: ['ci_Internal', 'Internal'] };
const WEEKDAYS = ['ci_Mon', 'ci_Tue', 'ci_Wed', 'ci_Thu', 'ci_Fri', 'ci_Sat', 'ci_Sun'];
const WEEKDAY_FALLBACK = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const state = {
  from: '', to: '', compare: false, filters: {}, tab: 'overview',
  labels: { extensions: {}, providers: {} }, charts: {}, drill: null, loading: 0,
};

/* ------------------------------------------------------------------ helpers */

const $id = (id) => document.getElementById(id);

function el(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  Object.entries(attrs).forEach(([k, v]) => {
    if (k === 'class') node.className = v;
    else if (k === 'text') node.textContent = v;
    else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
    else node.setAttribute(k, v);
  });
  children.forEach((c) => node.append(c instanceof Node ? c : document.createTextNode(String(c))));
  return node;
}

const esc = (s) => echarts.format.encodeHTML(String(s));
const isoDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const daysBetween = (a, b) => Math.round((new Date(`${b}T00:00:00`) - new Date(`${a}T00:00:00`)) / 86400000) + 1;
const outcomeLabel = (o) => (OUTCOMES[o] ? t(OUTCOMES[o][0], OUTCOMES[o][1]) : o);
const directionLabel = (d) => (DIRECTIONS[d] ? t(DIRECTIONS[d][0], DIRECTIONS[d][1]) : d);
const weekdayLabel = (n) => t(WEEKDAYS[n - 1], WEEKDAY_FALLBACK[n - 1]);

/** "Anna Smith (201)", or the number alone when it has no name. */
function numberLabel(number) {
  if (number === null || number === undefined || number === '') return t('ci_None', '—');
  const name = state.labels.extensions[number];
  return name ? `${name} (${number})` : String(number);
}

function duration(seconds) {
  if (seconds === null || seconds === undefined) return '—';
  const s = Math.round(seconds);
  if (s < 60) return `${s} s`;
  const m = Math.floor(s / 60);
  if (m < 60) return `${m} min ${s % 60} s`;
  return `${Math.floor(m / 60)} h ${m % 60} min`;
}

function intervalFor(from, to) {
  const days = daysBetween(from, to);
  if (days <= 62) return 'day';
  if (days <= 370) return 'week';
  return 'month';
}

function params(extra = {}) {
  const p = { from: state.from, to: state.to, ...extra };
  const filters = { ...state.filters, ...(extra.filters || {}) };
  delete p.filters;
  Object.entries(filters).forEach(([name, values]) => {
    if (values && values.length) p[`filters[${name}]`] = values;
  });
  return p;
}

function get(action, data) {
  state.loading += 1;
  return $.ajax({ url: BASE + action, method: 'GET', data, dataType: 'json', traditional: false })
    .then((r) => (r && r.success ? r.data : $.Deferred().reject(r && r.message)))
    .fail((xhr) => {
      const msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) || (typeof xhr === 'string' ? xhr : null);
      showError(msg || t('ci_LoadFailed', 'Call Insights could not load this data.'));
    })
    .always(() => { state.loading -= 1; });
}

function showError(message) {
  const box = $id('ci-error');
  box.textContent = message;
  box.classList.remove('ci-hidden');
}

const clearError = () => $id('ci-error').classList.add('ci-hidden');

function chart(id) {
  if (!state.charts[id]) {
    state.charts[id] = echarts.init($id(id), null, { renderer: 'canvas' });
  }
  return state.charts[id];
}

/* ------------------------------------------------------------------ header */

function applyPreset(preset) {
  const today = new Date();
  let from = new Date(today);
  if (preset === 'today') {
    // from = today
  } else if (preset === 'month') {
    from = new Date(today.getFullYear(), today.getMonth(), 1);
  } else if (preset !== 'custom') {
    from.setDate(today.getDate() - (Number(preset) - 1));
  }
  if (preset !== 'custom') {
    $id('ci-from').value = isoDate(from);
    $id('ci-to').value = isoDate(today);
  }
}

function readHeader() {
  state.from = $id('ci-from').value;
  state.to = $id('ci-to').value;
  state.compare = $id('ci-compare').checked;
  state.filters = {};
  ['direction', 'did', 'queue', 'answered_by'].forEach((name) => {
    const v = $id(`ci-filter-${name}`).value;
    if (v !== '') state.filters[name] = [v];
  });
  saveToUrl();
}

/** Filters and range live in the URL so a view can be bookmarked or shared. */
function saveToUrl() {
  const q = new URLSearchParams();
  q.set('from', state.from);
  q.set('to', state.to);
  if (state.compare) q.set('compare', '1');
  q.set('tab', state.tab);
  Object.entries(state.filters).forEach(([k, v]) => q.set(k, v[0]));
  window.history.replaceState(null, '', `${window.location.pathname}?${q.toString()}`);
}

function loadFromUrl() {
  const q = new URLSearchParams(window.location.search);
  const valid = (d) => /^\d{4}-\d{2}-\d{2}$/.test(d || '');
  if (valid(q.get('from')) && valid(q.get('to'))) {
    $id('ci-preset').value = 'custom';
    $id('ci-from').value = q.get('from');
    $id('ci-to').value = q.get('to');
  } else {
    applyPreset($id('ci-preset').value);
  }
  $id('ci-compare').checked = q.get('compare') === '1';
  ['direction', 'did', 'queue', 'answered_by'].forEach((name) => {
    if (q.get(name)) $id(`ci-filter-${name}`).dataset.pending = q.get(name);
  });
  if (['overview', 'traffic', 'team', 'lines', 'queues', 'callers'].includes(q.get('tab'))) state.tab = q.get('tab');
}

function fillSelect(name, values, label) {
  const select = $id(`ci-filter-${name}`);
  const current = select.dataset.pending || select.value;
  while (select.options.length > 1) select.remove(1);
  values.forEach((v) => select.add(new Option(label(v), v)));
  if (current && values.includes(current)) select.value = current;
  delete select.dataset.pending;
  $(select).dropdown('set selected', select.value || '');
}

/** Filter choices come from the data in the selected range. */
function loadFilterChoices() {
  const keysOf = (r) => r.current.series.map((s) => s.key).filter((k) => k !== '');
  const base = { from: state.from, to: state.to, metric: 'calls', interval: 'none' };
  return $.when(
    get('query', { ...base, breakdown: 'did' }),
    get('query', { ...base, breakdown: 'queue' }),
    get('query', { ...base, breakdown: 'answered_by' }),
  ).then((dids, queues, people) => {
    fillSelect('did', keysOf(dids), (v) => v);
    fillSelect('queue', keysOf(queues), numberLabel);
    fillSelect('answered_by', keysOf(people), numberLabel);
  });
}

/* ------------------------------------------------------------------ overview */

const KPIS = [
  ['calls', 'ci_KpiCalls', 'Calls', 'count'],
  ['answer_rate', 'ci_KpiAnswerRate', 'Answered (inbound)', 'percent'],
  ['missed', 'ci_KpiMissed', 'Missed', 'count', 'bad'],
  ['voicemail', 'ci_KpiVoicemail', 'Voicemail', 'count'],
  ['service_level', 'ci_KpiServiceLevel', 'Service level', 'percent'],
  ['avg_wait', 'ci_KpiAvgWait', 'Avg wait', 'seconds', 'bad'],
  ['avg_talk', 'ci_KpiAvgTalk', 'Avg talk', 'seconds'],
  ['unique_callers', 'ci_KpiUniqueCallers', 'Unique callers', 'count'],
];

function formatKpi(value, kind) {
  if (value === null || value === undefined) return '—';
  if (kind === 'percent') return `${value}%`;
  if (kind === 'seconds') return duration(value);
  return Number(value).toLocaleString();
}

function changeChip(cur, prev, kind, badWhenUp) {
  if (!state.compare || prev === null || prev === undefined || cur === null || cur === undefined) return null;
  let text; let delta;
  if (kind === 'percent') {
    delta = Math.round((cur - prev) * 10) / 10;
    text = `${delta >= 0 ? '+' : ''}${delta} ${t('ci_Points', 'pts')}`;
  } else {
    if (prev === 0) return el('div', { class: 'ci-change flat', text: t('ci_NoPrevious', 'no calls before') });
    delta = Math.round(((cur - prev) / prev) * 100);
    text = `${delta >= 0 ? '+' : ''}${delta}%`;
  }
  const good = badWhenUp ? delta < 0 : delta > 0;
  const cls = delta === 0 ? 'flat' : (good ? 'up' : 'down');
  return el('div', { class: `ci-change ${cls}`, text: `${text} ${t('ci_VsPrevious', 'vs previous')}` });
}

function loadKpis() {
  const box = $id('ci-kpis');
  const requests = KPIS.map(([metric]) => get('query', params({ metric, interval: 'none', compare: state.compare ? '1' : '0' })));
  return $.when(...requests).then((...results) => {
    box.replaceChildren();
    results.forEach((r, i) => {
      const [metric, key, fallback, kind, bad] = KPIS[i];
      const cur = r.current.series.length ? r.current.series[0].values[0] : (kind === 'count' ? 0 : null);
      const prev = r.previous ? (r.previous.series.length ? r.previous.series[0].values[0] : (kind === 'count' ? 0 : null)) : null;
      const tile = el('div', { class: 'statistic', title: t(`${key}Hint`, '') },
        el('div', { class: 'value', text: formatKpi(cur, kind) }),
        el('div', { class: 'label', text: t(key, fallback) }));
      const chip = changeChip(cur, prev, kind, bad === 'bad');
      if (chip) tile.append(chip);
      if (metric === 'missed') tile.addEventListener('click', () => openDrill(t('ci_KpiMissed', 'Missed'), { direction: ['in'], outcome: ['no_answer', 'abandoned_queue', 'abandoned_ivr', 'busy', 'failed', 'out_of_hours'] }));
      if (metric === 'voicemail') tile.addEventListener('click', () => openDrill(t('ci_KpiVoicemail', 'Voicemail'), { outcome: ['voicemail'] }));
      if (metric === 'calls') tile.addEventListener('click', () => openDrill(t('ci_KpiCalls', 'Calls'), {}));
      box.append(tile);
    });
  });
}

function tooltipFormatter(params) {
  const rows = (Array.isArray(params) ? params : [params]).filter((p) => p.value !== null && p.value !== undefined);
  if (!rows.length) return '';
  const head = esc(rows[0].axisValueLabel || rows[0].name);
  return `${head}<br>${rows.map((p) => `${p.marker}${esc(p.seriesName)}: <b>${esc(p.value)}</b>`).join('<br>')}`;
}

function loadOutcomeChart() {
  const interval = intervalFor(state.from, state.to);
  return get('query', params({ metric: 'calls', interval, breakdown: 'outcome', compare: state.compare ? '1' : '0' })).then((r) => {
    const cur = r.current;
    const series = cur.series.map((s) => ({
      name: outcomeLabel(s.key), type: 'line', stack: 'calls', areaStyle: { opacity: 0.6 }, symbol: 'none',
      data: s.values, itemStyle: { color: (OUTCOMES[s.key] || [])[2] }, outcome: s.key,
    }));
    if (r.previous) {
      const totals = r.previous.buckets.map((_, i) => r.previous.series.reduce((sum, s) => sum + (s.values[i] || 0), 0));
      series.push({
        name: t('ci_PreviousPeriod', 'Previous period'), type: 'line', symbol: 'none',
        lineStyle: { type: 'dashed', color: '#767676' }, itemStyle: { color: '#767676' },
        data: cur.buckets.map((_, i) => (i < totals.length ? totals[i] : null)),
      });
    }
    const c = chart('ci-chart-outcomes');
    c.setOption({
      tooltip: { trigger: 'axis', formatter: tooltipFormatter },
      legend: { top: 0 },
      grid: { left: 50, right: 20, top: 40, bottom: 60 },
      xAxis: { type: 'category', data: cur.buckets, boundaryGap: false },
      yAxis: { type: 'value', minInterval: 1 },
      dataZoom: [{ type: 'slider', bottom: 10 }, { type: 'inside' }],
      series,
    }, true);
    c.off('click');
    c.on('click', (p) => {
      const s = series[p.seriesIndex];
      if (!s.outcome || interval !== 'day') return;
      openDrill(`${outcomeLabel(s.outcome)} · ${p.name}`, { outcome: [s.outcome], local_date: [p.name] });
    });
    // Zooming narrows the date range for every chart on the page.
    c.off('datazoom');
    c.on('datazoom', () => {
      const opt = c.getOption().dataZoom[0];
      const buckets = cur.buckets;
      const a = buckets[opt.startValue ?? 0]; const b = buckets[opt.endValue ?? buckets.length - 1];
      if (interval === 'day' && a && b && (a !== state.from || b !== state.to)) {
        clearTimeout(state.zoomTimer);
        state.zoomTimer = setTimeout(() => {
          $id('ci-preset').value = 'custom';
          $(`#ci-preset`).dropdown('set selected', 'custom');
          $id('ci-from').value = a; $id('ci-to').value = b; refresh();
        }, 600);
      }
    });
  });
}

/* ------------------------------------------------------------------ traffic */

function loadHeatmap() {
  if (daysBetween(state.from, state.to) > 92) {
    chart('ci-chart-heatmap').setOption({ title: { text: t('ci_HeatmapRange', 'Choose 92 days or fewer for the hour-by-weekday view.'), left: 'center', top: 'middle', textStyle: { fontSize: 13, fontWeight: 'normal' } } }, true);
    return $.when();
  }
  // One query per weekday, each broken down by hour.
  return $.when(...[1, 2, 3, 4, 5, 6, 7].map((wd) => get('query', params({ metric: 'calls', interval: 'none', breakdown: 'hour', filters: { direction: ['in'], weekday: [String(wd)] } }))))
    .then((...byDay) => {
      const data = [];
      let max = 0;
      byDay.forEach((r, i) => {
        r.current.series.forEach((s) => {
          const v = s.values[0];
          max = Math.max(max, v);
          data.push([Number(s.key), i, v]);
        });
      });
      const hours = Array.from({ length: 24 }, (_, h) => `${String(h).padStart(2, '0')}:00`);
      const days = [1, 2, 3, 4, 5, 6, 7].map(weekdayLabel);
      const c = chart('ci-chart-heatmap');
      c.setOption({
        tooltip: { formatter: (p) => `${esc(days[p.value[1]])} ${esc(hours[p.value[0]])}<br>${esc(t('ci_InboundCalls', 'Inbound calls'))}: <b>${esc(p.value[2])}</b>` },
        grid: { left: 60, right: 20, top: 10, bottom: 80 },
        xAxis: { type: 'category', data: hours, splitArea: { show: true } },
        yAxis: { type: 'category', data: days, inverse: true, splitArea: { show: true } },
        visualMap: { min: 0, max: Math.max(1, max), calculable: true, orient: 'horizontal', left: 'center', bottom: 0, itemHeight: 120 },
        series: [{ type: 'heatmap', data, emphasis: { itemStyle: { borderColor: '#333', borderWidth: 1 } } }],
      }, true);
      c.off('click');
      c.on('click', (p) => openDrill(`${days[p.value[1]]} ${hours[p.value[0]]}`, { direction: ['in'], weekday: [String(p.value[1] + 1)], hour: [String(p.value[0])] }));
    });
}

/**
 * Calls per day: a bar chart up to 92 days (easy to read day by day), a
 * calendar for longer ranges (up to a year).
 */
function loadCalendar() {
  const days = daysBetween(state.from, state.to);
  if (days > 366) {
    chart('ci-chart-calendar').clear();
    return $.when();
  }
  return get('query', params({ metric: 'calls', interval: 'day' })).then((r) => {
    const series = r.current.series[0] || { values: [] };
    const data = r.current.buckets.map((b, i) => [b, series.values[i] || 0]);
    const c = chart('ci-chart-calendar');
    if (days <= 92) {
      c.setOption({
        tooltip: { trigger: 'axis', formatter: tooltipFormatter },
        grid: { left: 50, right: 20, top: 10, bottom: 30 },
        xAxis: { type: 'category', data: r.current.buckets },
        yAxis: { type: 'value', minInterval: 1 },
        series: [{ name: t('ci_KpiCalls', 'Calls'), type: 'bar', data: data.map((d) => d[1]), itemStyle: { color: '#2185d0' } }],
      }, true);
      c.off('click');
      c.on('click', (p) => openDrill(p.name, { local_date: [p.name] }));
      return;
    }
    const max = Math.max(1, ...data.map((d) => d[1]));
    c.setOption({
      tooltip: { formatter: (p) => `${esc(p.value[0])}<br>${esc(t('ci_KpiCalls', 'Calls'))}: <b>${esc(p.value[1])}</b>` },
      visualMap: { min: 0, max, show: false, inRange: { color: ['#eef5fb', '#2185d0'] } },
      calendar: {
        range: [state.from, state.to], cellSize: ['auto', 16], top: 30, left: 50, right: 20,
        dayLabel: { firstDay: 1 }, yearLabel: { show: false },
        itemStyle: { borderWidth: 1, borderColor: '#fff' }, splitLine: { lineStyle: { color: '#bbb' } },
      },
      series: [{ type: 'heatmap', coordinateSystem: 'calendar', data }],
    }, true);
    c.off('click');
    c.on('click', (p) => openDrill(p.value[0], { local_date: [p.value[0]] }));
  });
}

function loadConcurrency() {
  const c = chart('ci-chart-concurrency');
  if (daysBetween(state.from, state.to) > 92) {
    $id('ci-peak-note').textContent = '';
    c.setOption({ title: { text: t('ci_HeatmapRange', 'Choose 92 days or fewer for this view.'), left: 'center', top: 'middle', textStyle: { fontSize: 13, fontWeight: 'normal' } } }, true);
    return $.when();
  }
  return get('concurrency', params()).then((r) => {
    const hours = Array.from({ length: 24 }, (_, h) => `${String(h).padStart(2, '0')}:00`);
    $id('ci-peak-note').textContent = r.peak ? `${t('ci_PeakWas', 'busiest moment')}: ${r.peak} · ${r.peak_at}` : '';
    c.setOption({
      tooltip: { trigger: 'axis', formatter: tooltipFormatter },
      grid: { left: 50, right: 20, top: 10, bottom: 30 },
      xAxis: { type: 'category', data: hours },
      yAxis: { type: 'value', minInterval: 1 },
      series: [{ name: t('ci_PeakConcurrent', 'Peak calls at once'), type: 'bar', data: r.by_hour, itemStyle: { color: '#6435c9' } }],
    }, true);
  });
}

/* ------------------------------------------------------------------ tables */

/**
 * Renders rows into a sortable table. columns: [{ key, label, kind, align }],
 * kind is 'text' | 'count' | 'percent' | 'seconds'. Cells are text only.
 */
function renderTable(table, columns, rows, onRowClick, sort = { key: columns[1] && columns[1].key, desc: true }) {
  table.ciTable = { columns, rows, onRowClick, sort };
  const fmt = (v, kind) => {
    if (v === null || v === undefined || v === '') return '—';
    if (kind === 'percent') return `${v}%`;
    if (kind === 'seconds') return duration(v);
    if (kind === 'count') return Number(v).toLocaleString();
    return String(v);
  };
  const sorted = [...rows].sort((x, y) => {
    const a = x[sort.key]; const b = y[sort.key];
    const cmp = typeof a === 'string' || typeof b === 'string' ? String(a ?? '').localeCompare(String(b ?? '')) : (a ?? -1) - (b ?? -1);
    return sort.desc ? -cmp : cmp;
  });
  const head = el('thead', {}, el('tr', {}, ...columns.map((c) => {
    const th = el('th', { class: c.kind === 'text' ? '' : 'right aligned', text: c.label });
    th.addEventListener('click', () => renderTable(table, columns, rows, onRowClick,
      { key: c.key, desc: sort.key === c.key ? !sort.desc : c.kind !== 'text' }));
    return th;
  })));
  const body = el('tbody');
  if (!sorted.length) body.append(el('tr', {}, el('td', { colspan: String(columns.length), text: t('ci_NoData', 'No calls in this range.') })));
  sorted.forEach((r) => {
    const tr = el('tr', {}, ...columns.map((c) => el('td', { class: c.kind === 'text' ? '' : 'right aligned', text: fmt(r[c.key], c.kind) })));
    if (onRowClick) tr.addEventListener('click', () => onRowClick(r));
    body.append(tr);
  });
  table.replaceChildren(head, body);
  table.classList.toggle('ci-clickable', !!onRowClick);
}

function tableQuery(breakdown, metrics, filters = {}) {
  return get('table', params({ breakdown, metrics, filters }));
}

const providerLabel = (id) => (id ? (state.labels.providers[id] || id) : t('ci_None', '—'));

/* ------------------------------------------------------------------ lines */

function loadLines() {
  const inbound = { direction: ['in'] };
  const lineMetrics = ['inbound', 'answered', 'missed', 'voicemail', 'answer_rate', 'avg_wait', 'service_level'];
  const lines = tableQuery('did', lineMetrics, inbound).then((r) => {
    const rows = r.rows.filter((x) => x.key !== '').map((x) => ({ ...x, name: x.key }));
    renderTable($id('ci-lines-table'), [
      { key: 'name', label: t('ci_Line', 'Line (DID)'), kind: 'text' },
      { key: 'inbound', label: t('ci_Inbound', 'Inbound'), kind: 'count' },
      { key: 'answered', label: t('ci_Answered', 'Answered'), kind: 'count' },
      { key: 'missed', label: t('ci_KpiMissed', 'Missed'), kind: 'count' },
      { key: 'voicemail', label: t('ci_KpiVoicemail', 'Voicemail'), kind: 'count' },
      { key: 'answer_rate', label: t('ci_AnswerRate', 'Answer rate'), kind: 'percent' },
      { key: 'avg_wait', label: t('ci_AvgWait', 'Avg wait'), kind: 'seconds' },
      { key: 'service_level', label: t('ci_KpiServiceLevel', 'Service level'), kind: 'percent' },
    ], rows, (row) => openDrill(row.name, { did: [row.key], direction: ['in'] }));
    const c = chart('ci-chart-lines');
    const cats = rows.map((x) => x.name);
    const bar = (key, name, color) => ({ name, type: 'bar', stack: 'line', data: rows.map((x) => x[key]), itemStyle: { color } });
    c.setOption({
      tooltip: { trigger: 'axis', formatter: tooltipFormatter },
      legend: { top: 0 },
      grid: { left: 90, right: 20, top: 35, bottom: 20, containLabel: false },
      yAxis: { type: 'category', data: cats, inverse: true },
      xAxis: { type: 'value', minInterval: 1 },
      series: [
        bar('answered', t('ci_OutcomeAnswered', 'Answered'), OUTCOMES.answered[2]),
        bar('voicemail', t('ci_OutcomeVoicemail', 'Voicemail'), OUTCOMES.voicemail[2]),
        bar('missed', t('ci_KpiMissed', 'Missed'), OUTCOMES.abandoned_queue[2]),
      ],
    }, true);
    c.off('click');
    c.on('click', (p) => openDrill(p.name, { did: [p.name], direction: ['in'] }));
  });
  const trunks = tableQuery('provider', ['inbound', 'outbound', 'answered', 'busy_failed', 'talk_seconds']).then((r) => {
    const rows = r.rows.filter((x) => x.key !== '').map((x) => ({ ...x, name: providerLabel(x.key) }));
    renderTable($id('ci-trunks-table'), [
      { key: 'name', label: t('ci_Trunk', 'Trunk'), kind: 'text' },
      { key: 'inbound', label: t('ci_Inbound', 'Inbound'), kind: 'count' },
      { key: 'outbound', label: t('ci_Outbound', 'Outbound'), kind: 'count' },
      { key: 'answered', label: t('ci_Answered', 'Answered'), kind: 'count' },
      { key: 'busy_failed', label: t('ci_BusyFailed', 'Busy or failed'), kind: 'count' },
      { key: 'talk_seconds', label: t('ci_TotalTalk', 'Total talk'), kind: 'seconds' },
    ], rows, (row) => openDrill(row.name, { provider: [row.key] }));
  });
  return $.when(lines, trunks);
}

/* ------------------------------------------------------------------ queues & IVR */

const WAIT_BANDS = ['0-10', '10-20', '20-30', '30-60', '60-120', '120+'];

function loadQueues() {
  const inbound = { direction: ['in'] };
  const queues = tableQuery('queue', ['inbound', 'answered', 'abandoned', 'answer_rate', 'avg_wait', 'service_level'], inbound).then((r) => {
    const rows = r.rows.filter((x) => x.key !== '').map((x) => ({ ...x, name: numberLabel(x.key) }));
    renderTable($id('ci-queues-table'), [
      { key: 'name', label: t('ci_Queue', 'Queue'), kind: 'text' },
      { key: 'inbound', label: t('ci_KpiCalls', 'Calls'), kind: 'count' },
      { key: 'answered', label: t('ci_Answered', 'Answered'), kind: 'count' },
      { key: 'abandoned', label: t('ci_OutcomeAbandonedQueue', 'Abandoned'), kind: 'count' },
      { key: 'answer_rate', label: t('ci_AnswerRate', 'Answer rate'), kind: 'percent' },
      { key: 'avg_wait', label: t('ci_AvgWait', 'Avg wait'), kind: 'seconds' },
      { key: 'service_level', label: t('ci_KpiServiceLevel', 'Service level'), kind: 'percent' },
    ], rows, (row) => openDrill(row.name, { queue: [row.key], direction: ['in'] }));
  });
  const waits = tableQuery('wait_band', ['calls'], { direction: ['in'], outcome: ['answered'] }).then((r) => {
    const byBand = Object.fromEntries(r.rows.map((x) => [x.key, x.calls]));
    const c = chart('ci-chart-waits');
    c.setOption({
      tooltip: { trigger: 'axis', formatter: tooltipFormatter },
      grid: { left: 50, right: 20, top: 10, bottom: 30 },
      xAxis: { type: 'category', data: WAIT_BANDS.map((b) => `${b} s`) },
      yAxis: { type: 'value', minInterval: 1 },
      series: [{ name: t('ci_AnsweredCalls', 'Answered calls'), type: 'bar', data: WAIT_BANDS.map((b) => byBand[b] || 0), itemStyle: { color: '#21ba45' } }],
    }, true);
  });
  const funnel = tableQuery(null, ['ivr_entered', 'ivr_routed', 'ivr_answered'], inbound).then((r) => {
    const row = r.rows[0] || { ivr_entered: 0, ivr_routed: 0, ivr_answered: 0 };
    const data = [
      { name: t('ci_FunnelEntered', 'Reached the IVR'), value: row.ivr_entered },
      { name: t('ci_FunnelRouted', 'Got past the IVR'), value: row.ivr_routed },
      { name: t('ci_FunnelAnswered', 'Answered'), value: row.ivr_answered },
    ];
    const c = chart('ci-chart-funnel');
    c.setOption({
      tooltip: { trigger: 'item', formatter: (p) => `${esc(p.name)}: <b>${esc(p.value)}</b>` },
      series: [{ type: 'funnel', left: '10%', width: '80%', sort: 'none', minSize: '10%', data,
        label: { show: true, position: 'inside', formatter: (p) => `${p.name}: ${p.value}` } }],
    }, true);
  });
  const keys = tableQuery('ivr_digits', ['calls', 'answered', 'abandoned', 'voicemail', 'answer_rate'], inbound).then((r) => {
    const rows = r.rows.filter((x) => x.key !== '').map((x) => ({ ...x, name: x.key.replace(/t/g, t('ci_Timeout', 'timeout')) }));
    renderTable($id('ci-ivr-table'), [
      { key: 'name', label: t('ci_KeysPressed', 'Keys pressed'), kind: 'text' },
      { key: 'calls', label: t('ci_KpiCalls', 'Calls'), kind: 'count' },
      { key: 'answered', label: t('ci_Answered', 'Answered'), kind: 'count' },
      { key: 'abandoned', label: t('ci_OutcomeAbandonedQueue', 'Abandoned'), kind: 'count' },
      { key: 'voicemail', label: t('ci_KpiVoicemail', 'Voicemail'), kind: 'count' },
      { key: 'answer_rate', label: t('ci_AnswerRate', 'Answer rate'), kind: 'percent' },
    ], rows, (row) => openDrill(`${t('ci_KeysPressed', 'Keys pressed')} ${row.name}`, { ivr_digits: [row.key], direction: ['in'] }));
  });
  return $.when(queues, waits, funnel, keys);
}

/* ------------------------------------------------------------------ callers */

function loadCallers() {
  return get('callers', params()).then((r) => {
    const box = $id('ci-callers-kpis');
    box.replaceChildren();
    const tile = (value, label, hint) => el('div', { class: 'statistic', title: hint || '' },
      el('div', { class: 'value', text: value }), el('div', { class: 'label', text: label }));
    box.append(
      tile(Number(r.missed_calls).toLocaleString(), t('ci_MissedIncludingVoicemail', 'Missed (with voicemail)')),
      tile(r.callback_rate === null ? '—' : `${r.callback_rate}%`, t('ci_CalledBack', 'Called back'),
        t('ci_CalledBackHint', 'Missed calls followed by an answered call with the same number within the callback window.').replace('%h', r.window_hours)),
      tile(duration(r.avg_callback_seconds), t('ci_TimeToCallback', 'Avg time to call back')),
      tile(Number(r.new_callers || 0).toLocaleString(), t('ci_NewCallers', 'New callers'), t('ci_NewCallersHint', 'Called for the first time in this range.')),
      tile(Number(r.returning_callers || 0).toLocaleString(), t('ci_ReturningCallers', 'Returning callers')),
    );
    $id('ci-not-returned-note').textContent = `${r.not_returned.length}${r.not_returned.length >= 200 ? '+' : ''}`;
    renderTable($id('ci-not-returned-table'), [
      { key: 'name', label: t('ci_Caller', 'Caller'), kind: 'text' },
      { key: 'missed', label: t('ci_MissedCalls', 'Missed calls'), kind: 'count' },
      { key: 'last_missed_at', label: t('ci_LastMissed', 'Last missed'), kind: 'text' },
      { key: 'outcome', label: t('ci_Outcome', 'Outcome'), kind: 'text' },
    ], r.not_returned.map((x) => ({ ...x, name: numberLabel(x.number), last_missed_at: x.last_missed_at.slice(0, 16), outcome: outcomeLabel(x.last_outcome) })),
    (row) => openDrill(row.name, { caller: [row.number], direction: ['in'] }), { key: 'last_missed_at', desc: true });
    renderTable($id('ci-top-callers-table'), [
      { key: 'name', label: t('ci_Caller', 'Caller'), kind: 'text' },
      { key: 'calls', label: t('ci_KpiCalls', 'Calls'), kind: 'count' },
      { key: 'answered', label: t('ci_Answered', 'Answered'), kind: 'count' },
      { key: 'missed', label: t('ci_KpiMissed', 'Missed'), kind: 'count' },
      { key: 'last_call', label: t('ci_LastCall', 'Last call'), kind: 'text' },
    ], r.top_callers.map((x) => ({ ...x, name: numberLabel(x.number), last_call: x.last_call.slice(0, 16) })),
    (row) => openDrill(row.name, { caller: [row.number], direction: ['in'] }));
  });
}

/* ------------------------------------------------------------------ team */

function loadTeam() {
  return get('team', params()).then((rows) => {
    const total = rows.reduce((sum, r) => sum + r.answered, 0) || 1;
    state.teamRows = rows.map((r) => ({ ...r, key: r.extension, name: numberLabel(r.extension), share: r.answered / total }));
    renderTeam(state.teamSort || 'answered', state.teamDesc !== false);
  });
}

function renderTeam(sortKey, desc) {
  state.teamSort = sortKey; state.teamDesc = desc;
  const rows = [...(state.teamRows || [])].sort((x, y) => {
    const a = x[sortKey]; const b = y[sortKey];
    const cmp = typeof a === 'string' ? a.localeCompare(b) : (a ?? -1) - (b ?? -1);
    return desc ? -cmp : cmp;
  });
  const body = $id('ci-team').tBodies[0];
  body.replaceChildren();
  if (!rows.length) {
    body.append(el('tr', {}, el('td', { colspan: '10', text: t('ci_NoData', 'No calls in this range.') })));
    return;
  }
  const maxShare = Math.max(...rows.map((r) => r.share)) || 1;
  const num = (v) => (v === null || v === undefined ? '—' : Number(v).toLocaleString());
  const drillCell = (text, title, filters) => el('td', {
    class: 'right aligned ci-link', text,
    onclick: (e) => { e.stopPropagation(); openDrill(title, filters); },
  });
  rows.forEach((r) => {
    const bar = el('span', { class: 'ci-bar' });
    bar.style.width = `${Math.round((r.share / maxShare) * 6)}em`;
    body.append(el('tr', { onclick: () => openDrill(r.name, { rang: [r.key] }) },
      el('td', { text: r.name }),
      el('td', { class: 'right aligned', text: num(r.rang) }),
      el('td', { class: 'right aligned', text: num(r.answered) }),
      r.unanswered > 0
        ? drillCell(num(r.unanswered), `${r.name} · ${t('ci_Unanswered', 'Unanswered')}`, { rang_unanswered: [r.key] })
        : el('td', { class: 'right aligned', text: num(r.unanswered) }),
      el('td', { class: 'right aligned', text: r.answer_rate === null ? '—' : `${r.answer_rate}%` }),
      el('td', { class: 'right aligned', text: duration(r.avg_ring) }),
      el('td', { class: 'right aligned', text: duration(r.talk_seconds) }),
      el('td', { class: 'right aligned', text: duration(r.avg_talk) }),
      r.outbound > 0
        ? drillCell(num(r.outbound), `${r.name} · ${t('ci_OutboundCalls', 'Outbound')}`, { direction: ['out'], caller: [r.key] })
        : el('td', { class: 'right aligned', text: '0' }),
      el('td', { class: 'ci-bar-cell' }, bar, ` ${Math.round(r.share * 100)}%`)));
  });
}

/* ------------------------------------------------------------------ drill-down */

function openDrill(title, filters) {
  state.drill = { title, filters, page: 1 };
  $id('ci-drill-title').textContent = `${title} · ${state.from} – ${state.to}`;
  loadDrillPage();
  $('#ci-drill').modal('show');
}

function loadDrillPage() {
  const d = state.drill;
  const body = $id('ci-drill-table').tBodies[0];
  body.replaceChildren(el('tr', {}, el('td', { colspan: '8', text: t('ci_Loading', 'Loading…') })));
  get('calls', params({ filters: d.filters, page: d.page })).then((r) => {
    body.replaceChildren();
    if (!r.calls.length) body.append(el('tr', {}, el('td', { colspan: '8', text: t('ci_NoData', 'No calls in this range.') })));
    r.calls.forEach((call) => body.append(...drillRows(call)));
    const first = (r.page - 1) * r.page_size + 1;
    $id('ci-drill-count').textContent = r.total ? `${first}–${first + r.calls.length - 1} / ${r.total}` : '';
    $id('ci-drill-prev').disabled = r.page <= 1;
    $id('ci-drill-next').disabled = r.page * r.page_size >= r.total;
  });
}

function drillRows(call) {
  const legsRow = el('tr', { class: 'ci-legs ci-hidden' }, el('td', { colspan: '8' }, legsTable(call.legs)));
  const hasRecording = call.legs.some((l) => l.has_recording);
  const playCell = el('td', {});
  if (hasRecording) {
    playCell.append(el('button', {
      class: 'ui mini basic icon button', title: t('ci_Play', 'Play recording'),
      onclick: (e) => { e.stopPropagation(); play(call.linkedid, playCell); },
    }, el('i', { class: 'play icon' })));
  }
  const to = call.direction === 'in' ? (call.answered_by || call.first_queue || call.callee_num) : call.callee_num;
  const row = el('tr', { onclick: () => legsRow.classList.toggle('ci-hidden') },
    el('td', { text: call.start_time.slice(0, 19) }),
    el('td', { text: directionLabel(call.direction) }),
    el('td', { text: numberLabel(call.caller_num) }),
    el('td', { text: numberLabel(to) }),
    el('td', { text: outcomeLabel(call.outcome) + (call.transfer_count > 0 ? ` · ${t('ci_Transferred', 'transferred')}` : '') }),
    el('td', { class: 'right aligned', text: duration(call.wait_seconds) }),
    el('td', { class: 'right aligned', text: duration(call.talk_seconds) }),
    playCell);
  row.style.cursor = 'pointer';
  return [row, legsRow];
}

function legsTable(legs) {
  const table = el('table', { class: 'ui very basic compact small table' });
  const body = el('tbody');
  legs.forEach((l) => {
    const dst = String(l.dst_chan || '');
    let kind = numberLabel(l.dst_num);
    if (dst.startsWith('App:')) kind = `${t('ci_LegIvr', 'IVR')} ${numberLabel(l.dst_num)}`;
    if (dst.startsWith('Queue:')) kind = `${t('ci_LegQueue', 'Queue')} ${numberLabel(l.dst_num)}`;
    if (dst === 'VOICEMAIL') kind = t('ci_LegVoicemail', 'Voicemail');
    body.append(el('tr', {},
      el('td', { text: String(l.start || '').slice(11, 19) }),
      el('td', { text: `${numberLabel(l.src_num)}${l.src_name ? ` · ${l.src_name}` : ''}` }),
      el('td', { text: kind }),
      el('td', { text: l.disposition || '' }),
      el('td', { class: 'right aligned', text: duration(l.billsec) }),
      el('td', { text: l.dtmf_digits ? `${t('ci_Keys', 'keys')} ${l.dtmf_digits}` : '' })));
  });
  table.append(body);
  return table;
}

/**
 * Fetches this call's playback links from the core's call history only now,
 * when the user asks (the link alone grants access to the recording).
 */
function play(linkedid, cell) {
  cell.replaceChildren(el('span', { text: t('ci_Loading', 'Loading…') }));
  $.ajax({ url: '/pbxcore/api/v3/cdr', method: 'GET', data: { linkedid, limit: 5 }, dataType: 'json' })
    .then((r) => {
      const records = (r && r.data && r.data.records) || [];
      const legs = records.flatMap((c) => c.records || []);
      const urls = legs.map((l) => l.playback_url).filter((u) => typeof u === 'string' && u.startsWith('/pbxcore/api/v3/cdr:playback?token='));
      cell.replaceChildren();
      if (!urls.length) {
        cell.append(el('span', { text: t('ci_NoRecording', 'No recording') }));
        return;
      }
      urls.forEach((u) => cell.append(el('audio', { controls: '', preload: 'none', src: u })));
      cell.querySelector('audio').play().catch(() => {});
    })
    .fail(() => cell.replaceChildren(el('span', { text: t('ci_PlaybackFailed', 'Playback unavailable') })));
}

/* ------------------------------------------------------------------ status, live */

function loadStatus() {
  return get('status', {}).then((s) => {
    const parts = [];
    if (s.last_run_at) parts.push(`${t('ci_DataAsOf', 'Data as of')} ${s.last_run_at.slice(0, 16)}`);
    if (s.oldest_day) parts.push(`${t('ci_HistoryFrom', 'history from')} ${s.oldest_day}`);
    $id('ci-asof').textContent = parts.join(' · ');
    $id('ci-backfill-message').classList.toggle('ci-hidden', !!s.backfill_done);
  });
}

function pollLive() {
  if (document.hidden) return;
  $.ajax({ url: '/pbxcore/api/v3/pbx-status:getActiveCalls', method: 'GET', dataType: 'json' })
    .then((r) => {
      // The core answers success with an empty list when it cannot read the
      // active calls, so 0 may also mean "unknown"; the tile says so.
      const n = Array.isArray(r && r.data) ? new Set(r.data.map((c) => c.linkedid || c.UNIQUEID)).size : null;
      $id('ci-live-value').textContent = n === null ? '–' : String(n);
      $id('ci-live').title = t('ci_LiveHint', 'Calls in progress right now. 0 can also mean the PBX could not report them.');
    })
    .fail(() => { $id('ci-live-value').textContent = '–'; });
}

/* ------------------------------------------------------------------ export, backup */

/** One CSV cell: formula-like text gets an apostrophe, then RFC 4180 quoting. */
function csvCell(value) {
  let s = value === null || value === undefined ? '' : String(value);
  if (/^[=+\-@\t\r]/.test(s)) s = `'${s}`;
  return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

const toCsv = (rows) => rows.map((r) => r.map(csvCell).join(',')).join('\r\n');

function download(filename, blob) {
  const a = el('a', { href: URL.createObjectURL(blob), download: filename });
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

// The byte-order mark makes spreadsheets read the file as UTF-8.
const downloadCsv = (name, rows) => download(`${name}-${state.from}-${state.to}.csv`,
  new Blob(['﻿', toCsv(rows)], { type: 'text/csv;charset=utf-8' }));

/**
 * The table as rows of plain values: numbers stay numbers (seconds, counts),
 * text columns as shown. Tables from renderTable keep their data; others are
 * read from the page.
 */
function tableRows(table) {
  if (table.id === 'ci-team' && state.teamRows) {
    const keys = [...table.querySelectorAll('th[data-key]')].map((th) => th.dataset.key);
    const head = [...table.querySelectorAll('th[data-key]')].map((th) => th.textContent.trim());
    return [head, ...state.teamRows.map((r) => keys.map((k) => (k === 'share' ? Math.round(r.share * 1000) / 10 : r[k])))];
  }
  if (table.ciTable) {
    const { columns, rows } = table.ciTable;
    return [columns.map((c) => c.label), ...rows.map((r) => columns.map((c) => r[c.key]))];
  }
  return [...table.rows].map((tr) => [...tr.cells].map((c) => c.textContent.trim()));
}

/** Adds a CSV button above every table and a PNG button above every chart. */
function addExportButtons() {
  const button = (label, icon, onClick) => el('button', { class: 'ui mini basic icon button', type: 'button', title: label, onclick: onClick },
    el('i', { class: `${icon} icon` }), ` ${label}`);
  document.querySelectorAll('#call-insights .tab.segment table').forEach((table) => {
    table.before(el('div', { class: 'ci-export' }, button(t('ci_ExportCsv', 'CSV'), 'download',
      () => downloadCsv(table.id.replace(/^ci-/, ''), tableRows(table)))));
  });
  document.querySelectorAll('#call-insights .ci-chart').forEach((node) => {
    node.before(el('div', { class: 'ci-export' }, button(t('ci_ExportPng', 'PNG'), 'image', () => {
      const c = state.charts[node.id];
      if (!c) return;
      // Decoded here: the core's CSP (connect-src) blocks fetch() of a data: URL.
      const bytes = atob(c.getDataURL({ type: 'png', pixelRatio: 2, backgroundColor: '#fff' }).split(',')[1]);
      const png = Uint8Array.from(bytes, (ch) => ch.charCodeAt(0));
      download(`${node.id.replace(/^ci-chart-/, '')}-${state.from}-${state.to}.png`, new Blob([png], { type: 'image/png' }));
    })));
  });
}

function backupMessage(kind, text) {
  const box = $id('ci-backup-message');
  box.className = `ui ${kind} message`;
  box.textContent = text;
}

function exportTotals() {
  get('exportTotals', {}).then((r) => download(r.filename, new Blob([r.csv], { type: 'text/csv;charset=utf-8' })));
}

/** Sends the chosen file as the request body; the server checks every line. */
function importTotals(file) {
  if (!file) return;
  if (file.size > 20 * 1024 * 1024) {
    backupMessage('negative', t('ci_ImportTooLarge', 'The file is too large.'));
    return;
  }
  file.text().then((text) => $.ajax({
    url: `${BASE}importTotals`, method: 'POST', data: text, processData: false,
    contentType: 'text/csv; charset=utf-8', dataType: 'json',
  })).then((r) => {
    const d = r.data;
    backupMessage('positive', t('ci_ImportDone', 'Days added: {added}. Days already present and left unchanged: {skipped}.')
      .replace('{added}', d.days_added).replace('{skipped}', d.days_skipped));
  }, (xhr) => {
    backupMessage('negative', (xhr && xhr.responseJSON && xhr.responseJSON.message) || t('ci_ImportFailed', 'The file could not be imported.'));
  }).always(() => { $id('ci-import-file').value = ''; });
}

/* ------------------------------------------------------------------ main */

function refresh() {
  readHeader();
  clearError();
  if (!state.from || !state.to || state.from > state.to) {
    showError(t('ci_BadRange', 'Choose a start date on or before the end date.'));
    return;
  }
  loadStatus();
  loadFilterChoices();
  if (state.tab === 'overview') { loadKpis(); loadOutcomeChart(); }
  if (state.tab === 'traffic') { loadHeatmap(); loadCalendar(); loadConcurrency(); }
  if (state.tab === 'team') loadTeam();
  if (state.tab === 'lines') loadLines();
  if (state.tab === 'queues') loadQueues();
  if (state.tab === 'callers') loadCallers();
}

function selectTab(tab) {
  state.tab = tab;
  document.querySelectorAll('#call-insights [data-tab]').forEach((n) => n.classList.toggle('active', n.dataset.tab === tab));
  refresh();
  Object.values(state.charts).forEach((c) => c.resize());
}

function init() {
  loadFromUrl();
  $('#call-insights .ui.dropdown').dropdown();
  $('#ci-compare-box').checkbox();
  $('#ci-drill').modal({ autofocus: false });

  $id('ci-preset').addEventListener('change', (e) => { applyPreset(e.target.value); refresh(); });
  ['ci-from', 'ci-to'].forEach((id) => $id(id).addEventListener('change', () => {
    $id('ci-preset').value = 'custom';
    $('#ci-preset').dropdown('set selected', 'custom');
    refresh();
  }));
  $id('ci-compare').addEventListener('change', refresh);
  ['direction', 'did', 'queue', 'answered_by'].forEach((name) => $id(`ci-filter-${name}`).addEventListener('change', refresh));
  document.querySelectorAll('#ci-tabs .item').forEach((a) => a.addEventListener('click', () => selectTab(a.dataset.tab)));
  document.querySelectorAll('#ci-team th[data-key]').forEach((th) => th.addEventListener('click', () => {
    const key = th.dataset.key;
    renderTeam(key, state.teamSort === key ? !state.teamDesc : key !== 'name');
  }));
  addExportButtons();
  $id('ci-export-totals').addEventListener('click', exportTotals);
  $id('ci-import-totals').addEventListener('click', () => $id('ci-import-file').click());
  $id('ci-import-file').addEventListener('change', (e) => importTotals(e.target.files[0]));
  $id('ci-drill-prev').addEventListener('click', () => { state.drill.page -= 1; loadDrillPage(); });
  $id('ci-drill-next').addEventListener('click', () => { state.drill.page += 1; loadDrillPage(); });
  window.addEventListener('resize', () => Object.values(state.charts).forEach((c) => c.resize()));

  get('labels', {}).always((labels) => {
    if (labels && labels.extensions) state.labels = labels;
    selectTab(state.tab);
  });
  pollLive();
  setInterval(pollLive, 10000);
}

$(document).ready(init);
