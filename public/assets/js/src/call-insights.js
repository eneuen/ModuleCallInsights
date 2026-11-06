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

/**
 * Every colour on the page comes from these shade sets, lightest (0) to
 * darkest (9): cool greys for text, axes and anything secondary, and one
 * set per hue used for meaning. Picking shades from a fixed set keeps
 * colours consistent; the stylesheet repeats the ones it needs.
 */
const SHADES = {
  grey: ['#f5f7fa', '#e4e7eb', '#cbd2d9', '#9aa5b1', '#7b8794', '#616e7c', '#52606d', '#3e4c59', '#323f4b', '#1f2933'],
  blue: ['#ebf4fc', '#cfe5f7', '#a5cdef', '#6eafe3', '#3d93d8', '#2185d0', '#1a6fb0', '#155a8f', '#10456e', '#0b3250'],
  green: ['#e6f6ec', '#c1e8cf', '#93d6ac', '#5fc086', '#38a866', '#2f9157', '#247548', '#1b5c39', '#13432a', '#0b2b1b'],
  red: ['#fdecec', '#f8cfcf', '#f1a6a6', '#e77777', '#dc5353', '#cc3b3b', '#ab2f2f', '#8a2525', '#691c1c', '#481313'],
  amber: ['#fff6e5', '#fde8bf', '#fbd38d', '#f6b956', '#f0a02e', '#de8a15', '#b8700f', '#90560c', '#6a3f08', '#452905'],
  purple: ['#f3effb', '#e0d6f5', '#c6b4ec', '#a68de0', '#8b6bd3', '#7552c2', '#5f40a3', '#4a3181', '#36235f', '#22163d'],
  teal: ['#e6f7f7', '#bfeaea', '#8fd8d9', '#5cc2c4', '#36a9ac', '#2a9093', '#217477', '#195a5c', '#104042', '#082728'],
};
const G = SHADES.grey;

/**
 * The roles the charts use. Volumes are always the one blue (the admin
 * cabinet's own); colour beyond that is kept for outcomes, and anything
 * secondary, such as the previous period, axes and grid lines, is grey.
 */
const PALETTE = {
  primary: SHADES.blue[5],
  primaryDark: SHADES.blue[7],
  text: G[9],
  label: G[6],
  muted: G[4],
  axis: G[2],
  grid: G[1],
  // Light to dark, for heatmaps and the funnel.
  scale: [SHADES.blue[0], SHADES.blue[2], SHADES.blue[4], SHADES.blue[6], SHADES.blue[8]],
  // Explore breakdowns: distinct hues, alternating lighter and darker so
  // neighbours stay apart for colour-blind readers too.
  series: [SHADES.blue[5], SHADES.amber[3], SHADES.teal[6], SHADES.purple[3], SHADES.red[6],
    SHADES.green[3], G[6], SHADES.blue[2], SHADES.amber[7], SHADES.teal[3]],
};

/**
 * Outcomes as [label key, fallback, hue, shade]. Good, bad and neutral
 * outcomes differ in lightness as well as hue (light green against dark
 * red), so they stay apart without full colour vision.
 */
const OUTCOMES = {
  answered: ['ci_OutcomeAnswered', 'Answered', 'green', 4],
  voicemail: ['ci_OutcomeVoicemail', 'Voicemail', 'purple', 4],
  no_answer: ['ci_OutcomeNoAnswer', 'No Answer', 'amber', 3],
  abandoned_queue: ['ci_OutcomeAbandonedQueue', 'Abandoned in Queue', 'red', 6],
  abandoned_ivr: ['ci_OutcomeAbandonedIvr', 'Left in the IVR', 'red', 3],
  busy: ['ci_OutcomeBusy', 'Busy', 'amber', 6],
  failed: ['ci_OutcomeFailed', 'Failed', 'grey', 5],
  out_of_hours: ['ci_OutcomeOutOfHours', 'Out of Hours', 'teal', 5],
};
const outcomeColor = (o) => (OUTCOMES[o] ? SHADES[OUTCOMES[o][2]][OUTCOMES[o][3]] : PALETTE.muted);
/** A lighter shade of the same hue, for filled areas under an outcome's line. */
const outcomeFill = (o) => (OUTCOMES[o] ? SHADES[OUTCOMES[o][2]][Math.max(1, OUTCOMES[o][3] - 2)] : G[2]);

/** One ECharts theme for every chart: text, axes, legend, tooltip and zoom bar in the shared greys. */
echarts.registerTheme('call-insights', {
  color: PALETTE.series,
  backgroundColor: 'transparent',
  textStyle: { color: G[7] },
  title: { textStyle: { color: G[6], fontSize: 13, fontWeight: 'normal' } },
  legend: { textStyle: { color: G[7] }, inactiveColor: G[2] },
  tooltip: { backgroundColor: '#fff', borderColor: G[2], borderWidth: 1, textStyle: { color: G[8] }, extraCssText: 'box-shadow: 0 2px 8px rgba(31, 41, 51, .12);' },
  categoryAxis: { axisLine: { lineStyle: { color: G[2] } }, axisTick: { lineStyle: { color: G[2] } }, axisLabel: { color: G[6] }, splitLine: { lineStyle: { color: G[1] } } },
  valueAxis: { axisLine: { show: false, lineStyle: { color: G[2] } }, axisLabel: { color: G[6] }, splitLine: { lineStyle: { color: G[1] } } },
  dataZoom: { borderColor: G[2], fillerColor: 'rgba(33, 133, 208, .12)', handleStyle: { color: '#fff', borderColor: G[4] },
    textStyle: { color: G[6] }, dataBackground: { lineStyle: { color: G[3] }, areaStyle: { color: G[1] } } },
  visualMap: { textStyle: { color: G[6] } },
});

/** A coloured dot and the outcome's name, for tables. */
function outcomeCell(o) {
  const dot = el('span', { class: 'ci-dot' });
  dot.style.background = outcomeColor(o);
  return [dot, outcomeLabel(o)];
}
const DIRECTIONS = { in: ['ci_Inbound', 'Inbound'], out: ['ci_Outbound', 'Outbound'], internal: ['ci_Internal', 'Internal'] };
const WEEKDAYS = ['ci_Mon', 'ci_Tue', 'ci_Wed', 'ci_Thu', 'ci_Fri', 'ci_Sat', 'ci_Sun'];
const WEEKDAY_FALLBACK = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const state = {
  from: '', to: '', compare: false, filters: {}, tab: 'overview',
  labels: { extensions: {}, providers: {} }, charts: {}, drill: null, loading: 0,
  explore: { metric: 'calls', breakdown: '', interval: 'auto', chart: 'line' }, catalog: null, reports: null,
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
/** IVR keys as stored ("1", "t,2": one entry per IVR level, t = timeout), for display. */
const ivrKeysLabel = (keys) => String(keys).split(',').map((k) => (k === 't' ? t('ci_Timeout', 'timeout') : k)).join(', ');

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
    state.charts[id] = echarts.init($id(id), 'call-insights', { renderer: 'canvas' });
  }
  return state.charts[id];
}

/** Axes take their colours from the theme; this only marks where an axis is built. */
const axis = (o) => o;

/** True when every value in every series is 0 or empty. */
const allEmpty = (series) => series.every((sr) => sr.values.every((v) => !v));

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
  if (state.tab === 'explore') Object.entries(state.explore).forEach(([k, v]) => { if (v) q.set(`x_${k}`, v); });
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
  if (['overview', 'traffic', 'team', 'lines', 'queues', 'flows', 'callers', 'explore', 'settings'].includes(q.get('tab'))) state.tab = q.get('tab');
  // Checked against the menus once the catalog has loaded (syncExploreControls).
  ['metric', 'breakdown', 'interval', 'chart'].forEach((k) => { if (q.get(`x_${k}`)) state.explore[k] = q.get(`x_${k}`); });
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
  ['answer_rate', 'ci_KpiAnswerRate', 'Answered (Inbound)', 'percent'],
  ['missed', 'ci_KpiMissed', 'Missed', 'count', 'bad'],
  ['voicemail', 'ci_KpiVoicemail', 'Voicemail', 'count', 'bad'],
  ['service_level', 'ci_KpiServiceLevel', 'Service Level', 'percent'],
  ['avg_wait', 'ci_KpiAvgWait', 'Avg Wait', 'seconds', 'bad'],
  ['avg_talk', 'ci_KpiAvgTalk', 'Avg Talk', 'seconds'],
  ['unique_callers', 'ci_KpiUniqueCallers', 'Unique Callers', 'count'],
];

function formatKpi(value, kind) {
  if (value === null || value === undefined) return '—';
  if (kind === 'percent') return `${value}%`;
  if (kind === 'seconds') return duration(value);
  return Number(value).toLocaleString();
}

/**
 * The change against the previous period: an arrow and the change, coloured
 * only when it is good or bad news (badWhenUp: more is worse, as for missed
 * calls). No change stays grey.
 */
function changeChip(cur, prev, kind, badWhenUp) {
  if (!state.compare || prev === null || prev === undefined || cur === null || cur === undefined) return null;
  let delta; let text;
  if (kind === 'percent') {
    delta = Math.round((cur - prev) * 10) / 10;
    text = `${Math.abs(delta)} ${t('ci_Points', 'pts')}`;
  } else {
    if (prev === 0) return el('div', { class: 'ci-change', text: t('ci_NoPrevious', 'no calls before') });
    delta = Math.round(((cur - prev) / prev) * 100);
    text = `${Math.abs(delta)}%`;
  }
  if (delta === 0) return el('div', { class: 'ci-change', text: t('ci_NoChange', 'No change vs previous') });
  const good = badWhenUp ? delta < 0 : delta > 0;
  return el('div', { class: `ci-change ${good ? 'good' : 'bad'}` },
    el('span', { class: 'ci-change-value', text: `${delta > 0 ? '▲' : '▼'} ${text}` }),
    ` ${t('ci_VsPrevious', 'vs previous')}`);
}

/** One KPI card: label, value, and optionally the change and a drill-down. */
function kpiCard(label, value, hint, onClick) {
  const empty = value === '—';
  const card = el('div', { class: `ci-kpi${onClick ? ' ci-clickable' : ''}`, title: hint || '' },
    el('div', { class: 'ci-kpi-label', text: label }),
    el('div', { class: `ci-kpi-value${empty ? ' ci-empty' : ''}`, text: value }));
  if (onClick) {
    card.setAttribute('role', 'button');
    card.tabIndex = 0;
    card.addEventListener('click', onClick);
    card.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onClick(); } });
  }
  return card;
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
      const drills = {
        missed: () => openDrill(t('ci_KpiMissed', 'Missed'), { direction: ['in'], outcome: ['no_answer', 'abandoned_queue', 'abandoned_ivr', 'busy', 'failed', 'out_of_hours'] }),
        voicemail: () => openDrill(t('ci_KpiVoicemail', 'Voicemail'), { outcome: ['voicemail'] }),
        calls: () => openDrill(t('ci_KpiCalls', 'Calls'), {}),
      };
      const card = kpiCard(t(key, fallback), formatKpi(cur, kind), t(`${key}Hint`, ''), drills[metric]);
      const chip = changeChip(cur, prev, kind, bad === 'bad');
      if (chip) card.append(chip);
      box.append(card);
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
    const c = chart('ci-chart-outcomes');
    if (allEmpty(cur.series)) {
      showChartMessage(c, noDataText());
      return;
    }
    const series = cur.series.map((s) => ({
      name: outcomeLabel(s.key), type: 'line', stack: 'calls', symbol: 'none', smooth: 0.15,
      lineStyle: { width: 1.5, color: outcomeColor(s.key) }, areaStyle: { color: outcomeFill(s.key), opacity: 0.85 },
      data: s.values, itemStyle: { color: outcomeColor(s.key) }, outcome: s.key,
    }));
    if (r.previous) {
      const totals = r.previous.buckets.map((_, i) => r.previous.series.reduce((sum, s) => sum + (s.values[i] || 0), 0));
      series.push({
        name: t('ci_PreviousPeriod', 'Previous Period'), type: 'line', symbol: 'none',
        lineStyle: { type: 'dashed', color: PALETTE.muted, width: 1.5 }, itemStyle: { color: PALETTE.muted },
        data: cur.buckets.map((_, i) => (i < totals.length ? totals[i] : null)),
      });
    }
    c.setOption({
      tooltip: { trigger: 'axis', formatter: tooltipFormatter },
      legend: { top: 0, icon: 'circle', itemWidth: 9, itemHeight: 9 },
      grid: { left: 50, right: 20, top: 40, bottom: 60 },
      xAxis: axis({ type: 'category', data: cur.buckets, boundaryGap: false }),
      yAxis: axis({ type: 'value', minInterval: 1 }),
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
    showChartMessage(chart('ci-chart-heatmap'), t('ci_HeatmapRange', 'Choose 92 days or fewer for the hour-by-weekday view.'));
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
      if (max === 0) {
        showChartMessage(c, noDataText());
        return;
      }
      c.setOption({
        tooltip: { formatter: (p) => `${esc(days[p.value[1]])} ${esc(hours[p.value[0]])}<br>${esc(t('ci_InboundCalls', 'Inbound Calls'))}: <b>${esc(p.value[2])}</b>` },
        grid: { left: 60, right: 20, top: 10, bottom: 56 },
        xAxis: axis({ type: 'category', data: hours, splitArea: { show: false }, axisTick: { show: false } }),
        yAxis: axis({ type: 'category', data: days, inverse: true, splitArea: { show: false }, axisTick: { show: false } }),
        // A stronger scale than the default, so quiet hours are still told apart; the legend sits under the grid.
        visualMap: { min: 0, max: Math.max(1, max), orient: 'horizontal', left: 'center', bottom: 4, itemWidth: 10, itemHeight: 160,
          text: [String(max), '0'], inRange: { color: PALETTE.scale } },
        series: [{ type: 'heatmap', data, itemStyle: { borderColor: '#fff', borderWidth: 2, borderRadius: 2 },
          emphasis: { itemStyle: { borderColor: PALETTE.primaryDark, borderWidth: 2 } } }],
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
    showChartMessage(chart('ci-chart-calendar'), t('ci_CalendarRange', 'Choose a year or less for this view.'));
    return $.when();
  }
  return get('query', params({ metric: 'calls', interval: 'day' })).then((r) => {
    const series = r.current.series[0] || { values: [] };
    const data = r.current.buckets.map((b, i) => [b, series.values[i] || 0]);
    const c = chart('ci-chart-calendar');
    if (allEmpty(r.current.series)) {
      showChartMessage(c, noDataText());
      return;
    }
    if (days <= 92) {
      c.setOption({
        tooltip: { trigger: 'axis', formatter: tooltipFormatter },
        grid: { left: 50, right: 20, top: 10, bottom: 30 },
        xAxis: axis({ type: 'category', data: r.current.buckets, axisTick: { show: false } }),
        yAxis: axis({ type: 'value', minInterval: 1 }),
        series: [{ name: t('ci_KpiCalls', 'Calls'), type: 'bar', barMaxWidth: 28, data: data.map((d) => d[1]),
          itemStyle: { color: PALETTE.primary, borderRadius: [2, 2, 0, 0] }, emphasis: { itemStyle: { color: PALETTE.primaryDark } } }],
      }, true);
      c.off('click');
      c.on('click', (p) => openDrill(p.name, { local_date: [p.name] }));
      return;
    }
    const max = Math.max(1, ...data.map((d) => d[1]));
    c.setOption({
      tooltip: { formatter: (p) => `${esc(p.value[0])}<br>${esc(t('ci_KpiCalls', 'Calls'))}: <b>${esc(p.value[1])}</b>` },
      visualMap: { min: 0, max, show: false, inRange: { color: PALETTE.scale } },
      calendar: {
        range: [state.from, state.to], cellSize: ['auto', 16], top: 30, left: 50, right: 20,
        dayLabel: { firstDay: 1 }, yearLabel: { show: false },
        itemStyle: { borderWidth: 1, borderColor: '#fff' }, splitLine: { lineStyle: { color: PALETTE.axis } },
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
    showChartMessage(c, t('ci_HeatmapRange', 'Choose 92 days or fewer for this view.'));
    return $.when();
  }
  return get('concurrency', params()).then((r) => {
    const hours = Array.from({ length: 24 }, (_, h) => `${String(h).padStart(2, '0')}:00`);
    $id('ci-peak-note').textContent = r.peak ? `${t('ci_PeakWas', 'busiest moment')}: ${r.peak} · ${r.peak_at}` : '';
    if (!r.peak) {
      showChartMessage(c, noDataText());
      return;
    }
    c.setOption({
      tooltip: { trigger: 'axis', formatter: tooltipFormatter },
      grid: { left: 50, right: 20, top: 10, bottom: 30 },
      xAxis: axis({ type: 'category', data: hours, axisTick: { show: false } }),
      yAxis: axis({ type: 'value', minInterval: 1 }),
      series: [{ name: t('ci_PeakConcurrent', 'Peak Calls at Once, by Hour'), type: 'bar', barMaxWidth: 24, data: r.by_hour,
        itemStyle: { color: PALETTE.primary, borderRadius: [2, 2, 0, 0] }, emphasis: { itemStyle: { color: PALETTE.primaryDark } } }],
    }, true);
  });
}

/* ------------------------------------------------------------------ empty states */

/** "No calls in this range", plus where the history starts when the range is before it. */
function noDataText() {
  const oldest = state.status && state.status.oldest_day;
  const base = t('ci_NoData', 'No calls in this range.');
  return oldest && state.to < oldest ? `${base} ${t('ci_HistoryStarts', 'History starts on {d}.').replace('{d}', oldest)}` : base;
}

const emptyRow = (span) => el('tr', {}, el('td', { class: 'ci-empty-cell', colspan: String(span), text: noDataText() }));

/** Replaces a chart's content with a centred message (no axes or zoom bar). */
function showChartMessage(c, text) {
  c.setOption({
    title: { text, left: 'center', top: 'middle' },
    series: [], xAxis: [], yAxis: [], dataZoom: [], visualMap: [], legend: [], calendar: [],
  }, true);
}

/* ------------------------------------------------------------------ tables */

/**
 * Renders rows into a sortable table. columns: [{ key, label, kind, align }],
 * kind is 'text' | 'count' | 'percent' | 'seconds'. Cells are text only.
 */
function renderTable(table, columns, rows, onRowClick, sort = { key: columns[1] && columns[1].key, desc: true }, limit = 0) {
  table.ciTable = { columns, rows, onRowClick, sort, limit };
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
  const isText = (c) => c.kind === 'text' || c.kind === 'outcome';
  const head = el('thead', {}, el('tr', {}, ...columns.map((c) => {
    const th = el('th', { class: isText(c) ? '' : 'right aligned', text: c.label });
    if (c.key === sort.key) th.classList.add('sorted', sort.desc ? 'descending' : 'ascending');
    th.addEventListener('click', () => renderTable(table, columns, rows, onRowClick,
      { key: c.key, desc: sort.key === c.key ? !sort.desc : !isText(c) }, limit));
    return th;
  })));
  const body = el('tbody');
  if (!sorted.length) body.append(emptyRow(columns.length));
  const shown = limit && !table.ciExpanded ? sorted.slice(0, limit) : sorted;
  shown.forEach((r) => {
    const tr = el('tr', {}, ...columns.map((c, i) => {
      if (c.kind === 'outcome') return el('td', {}, ...outcomeCell(r[c.key]));
      const td = el('td', { class: isText(c) ? '' : 'right aligned', text: fmt(r[c.key], c.kind) });
      if (i === 0 && r.muted) { td.classList.add('ci-muted'); if (r.title) td.title = r.title; }
      return td;
    }));
    if (onRowClick) tr.addEventListener('click', () => onRowClick(r));
    body.append(tr);
  });
  if (shown.length < sorted.length) {
    const more = el('tr', { class: 'ci-more' }, el('td', { colspan: String(columns.length),
      text: t('ci_ShowAll', 'Show All {n}').replace('{n}', sorted.length.toLocaleString()) }));
    more.addEventListener('click', (e) => { e.stopPropagation(); table.ciExpanded = true; renderTable(table, columns, rows, onRowClick, sort, limit); });
    body.append(more);
  }
  table.replaceChildren(head, body);
  table.classList.toggle('ci-clickable', !!onRowClick);
}

function tableQuery(breakdown, metrics, filters = {}) {
  return get('table', params({ breakdown, metrics, filters }));
}

// A trunk no longer in the PBX configuration keeps the short part of its id, so two of them can be told apart.
const providerLabel = (id) => (id ? (state.labels.providers[id]
  || `${t('ci_DeletedTrunk', 'Deleted Trunk')} · ${String(id).replace(/^(SIP|IAX)-TRUNK-/i, '')}`) : t('ci_None', '—'));
const isKnownProvider = (id) => !!state.labels.providers[id];

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
      { key: 'answer_rate', label: t('ci_AnswerRate', 'Answer Rate'), kind: 'percent' },
      { key: 'avg_wait', label: t('ci_AvgWait', 'Avg Wait'), kind: 'seconds' },
      { key: 'service_level', label: t('ci_KpiServiceLevel', 'Service Level'), kind: 'percent' },
    ], rows, (row) => openDrill(row.name, { did: [row.key], direction: ['in'] }));
    // One band per line, so a PBX with two lines gets a short chart and one with twenty a tall one.
    const node = $id('ci-chart-lines');
    node.style.height = `${Math.max(140, rows.length * 40 + 80)}px`;
    const c = chart('ci-chart-lines');
    c.resize();
    if (!rows.length) {
      showChartMessage(c, noDataText());
      return;
    }
    const cats = rows.map((x) => x.name);
    const bar = (key, name, color) => ({ name, type: 'bar', stack: 'line', barMaxWidth: 26, data: rows.map((x) => x[key]), itemStyle: { color } });
    c.setOption({
      tooltip: { trigger: 'axis', formatter: tooltipFormatter },
      legend: { top: 0, icon: 'circle', itemWidth: 9, itemHeight: 9 },
      grid: { left: 90, right: 20, top: 35, bottom: 24, containLabel: false },
      yAxis: axis({ type: 'category', data: cats, inverse: true, axisTick: { show: false } }),
      xAxis: axis({ type: 'value', minInterval: 1 }),
      series: [
        bar('answered', t('ci_OutcomeAnswered', 'Answered'), outcomeColor('answered')),
        bar('voicemail', t('ci_OutcomeVoicemail', 'Voicemail'), outcomeColor('voicemail')),
        bar('missed', t('ci_KpiMissed', 'Missed'), outcomeColor('abandoned_queue')),
      ],
    }, true);
    c.off('click');
    c.on('click', (p) => openDrill(p.name, { did: [p.name], direction: ['in'] }));
  });
  const trunks = tableQuery('provider', ['inbound', 'outbound', 'answered', 'busy_failed', 'talk_seconds']).then((r) => {
    // A trunk that is no longer configured is shown muted, with its id on hover.
    const rows = r.rows.filter((x) => x.key !== '').map((x) => ({ ...x, name: providerLabel(x.key),
      muted: !isKnownProvider(x.key), title: x.key }));
    renderTable($id('ci-trunks-table'), [
      { key: 'name', label: t('ci_Trunk', 'Trunk'), kind: 'text' },
      { key: 'inbound', label: t('ci_Inbound', 'Inbound'), kind: 'count' },
      { key: 'outbound', label: t('ci_Outbound', 'Outbound'), kind: 'count' },
      { key: 'answered', label: t('ci_Answered', 'Answered'), kind: 'count' },
      { key: 'busy_failed', label: t('ci_BusyFailed', 'Busy or Failed'), kind: 'count' },
      { key: 'talk_seconds', label: t('ci_TotalTalk', 'Total Talk'), kind: 'seconds' },
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
      { key: 'abandoned', label: t('ci_OutcomeAbandonedQueue', 'Abandoned in Queue'), kind: 'count' },
      { key: 'answer_rate', label: t('ci_AnswerRate', 'Answer Rate'), kind: 'percent' },
      { key: 'avg_wait', label: t('ci_AvgWait', 'Avg Wait'), kind: 'seconds' },
      { key: 'service_level', label: t('ci_KpiServiceLevel', 'Service Level'), kind: 'percent' },
    ], rows, (row) => openDrill(row.name, { queue: [row.key], direction: ['in'] }));
  });
  const waits = tableQuery('wait_band', ['calls'], { direction: ['in'], outcome: ['answered'] }).then((r) => {
    const byBand = Object.fromEntries(r.rows.map((x) => [x.key, x.calls]));
    const c = chart('ci-chart-waits');
    if (!WAIT_BANDS.some((b) => byBand[b])) {
      showChartMessage(c, noDataText());
      return;
    }
    c.setOption({
      tooltip: { trigger: 'axis', formatter: tooltipFormatter },
      grid: { left: 50, right: 20, top: 10, bottom: 30 },
      xAxis: axis({ type: 'category', data: WAIT_BANDS.map((b) => `${b} s`), axisTick: { show: false } }),
      yAxis: axis({ type: 'value', minInterval: 1 }),
      series: [{ name: t('ci_AnsweredCalls', 'Answered Calls'), type: 'bar', barMaxWidth: 40, data: WAIT_BANDS.map((b) => byBand[b] || 0),
        itemStyle: { color: PALETTE.primary, borderRadius: [2, 2, 0, 0] }, emphasis: { itemStyle: { color: PALETTE.primaryDark } } }],
    }, true);
  });
  const funnel = tableQuery(null, ['ivr_entered', 'ivr_routed', 'ivr_answered'], inbound).then((r) => {
    const row = r.rows[0] || { ivr_entered: 0, ivr_routed: 0, ivr_answered: 0 };
    const data = [
      { name: t('ci_FunnelEntered', 'Reached the IVR'), value: row.ivr_entered },
      { name: t('ci_FunnelRouted', 'Got Past the IVR'), value: row.ivr_routed },
      { name: t('ci_FunnelAnswered', 'Answered'), value: row.ivr_answered },
    ];
    const c = chart('ci-chart-funnel');
    if (!row.ivr_entered) {
      showChartMessage(c, noDataText());
      return;
    }
    // Shades of the primary blue, darkest at the end of the funnel.
    data.forEach((d, i) => {
      d.itemStyle = { color: SHADES.blue[[3, 5, 7][i]] };
      if (i === 0) d.label = { color: SHADES.blue[9] };    // dark text on the light shade, for contrast
    });
    c.setOption({
      tooltip: { trigger: 'item', formatter: (p) => `${esc(p.name)}: <b>${esc(p.value)}</b>` },
      series: [{ type: 'funnel', left: '10%', width: '80%', top: 10, bottom: 10, sort: 'none', minSize: '20%', gap: 2, data,
        label: { show: true, position: 'inside', color: '#fff', formatter: (p) => `${p.name}: ${p.value}` } }],
    }, true);
  });
  const keys = tableQuery('ivr_digits', ['calls', 'answered', 'abandoned', 'voicemail', 'answer_rate'], inbound).then((r) => {
    const rows = r.rows.filter((x) => x.key !== '').map((x) => ({ ...x, name: ivrKeysLabel(x.key) }));
    renderTable($id('ci-ivr-table'), [
      { key: 'name', label: t('ci_KeysPressed', 'Keys Pressed'), kind: 'text' },
      { key: 'calls', label: t('ci_KpiCalls', 'Calls'), kind: 'count' },
      { key: 'answered', label: t('ci_Answered', 'Answered'), kind: 'count' },
      { key: 'abandoned', label: t('ci_OutcomeAbandonedQueue', 'Abandoned in Queue'), kind: 'count' },
      { key: 'voicemail', label: t('ci_KpiVoicemail', 'Voicemail'), kind: 'count' },
      { key: 'answer_rate', label: t('ci_AnswerRate', 'Answer Rate'), kind: 'percent' },
    ], rows, (row) => openDrill(`${t('ci_KeysPressed', 'Keys Pressed')} ${row.name}`, { ivr_digits: [row.key], direction: ['in'] }));
  });
  return $.when(queues, waits, funnel, keys);
}

/* ------------------------------------------------------------------ callers */

function loadCallers() {
  return get('callers', params()).then((r) => {
    ['ci-not-returned-table', 'ci-top-callers-table'].forEach((id) => { $id(id).ciExpanded = false; });
    const box = $id('ci-callers-kpis');
    box.replaceChildren();
    const tile = (value, label, hint) => kpiCard(label, value, hint);
    box.append(
      tile(Number(r.missed_calls).toLocaleString(), t('ci_MissedIncludingVoicemail', 'Missed (with Voicemail)')),
      tile(r.callback_rate === null ? '—' : `${r.callback_rate}%`, t('ci_CalledBack', 'Called Back'),
        t('ci_CalledBackHint', 'Missed calls followed by an answered call with the same number within the callback window.').replace('%h', r.window_hours)),
      tile(duration(r.avg_callback_seconds), t('ci_TimeToCallback', 'Avg Time to Call Back')),
      tile(Number(r.new_callers || 0).toLocaleString(), t('ci_NewCallers', 'New Callers'), t('ci_NewCallersHint', 'Called for the first time in this range.')),
      tile(Number(r.returning_callers || 0).toLocaleString(), t('ci_ReturningCallers', 'Returning Callers')),
    );
    $id('ci-not-returned-note').textContent = `${r.not_returned.length}${r.not_returned.length >= 200 ? '+' : ''}`;
    renderTable($id('ci-not-returned-table'), [
      { key: 'name', label: t('ci_Caller', 'Caller'), kind: 'text' },
      { key: 'missed', label: t('ci_MissedCalls', 'Missed Calls'), kind: 'count' },
      { key: 'last_missed_at', label: t('ci_LastMissed', 'Last Missed'), kind: 'text' },
      { key: 'outcome', label: t('ci_Outcome', 'Outcome'), kind: 'outcome' },
    ], r.not_returned.map((x) => ({ ...x, name: numberLabel(x.number), last_missed_at: x.last_missed_at.slice(0, 16), outcome: x.last_outcome })),
    (row) => openDrill(row.name, { caller: [row.number], direction: ['in'] }), { key: 'last_missed_at', desc: true }, 15);
    renderTable($id('ci-top-callers-table'), [
      { key: 'name', label: t('ci_Caller', 'Caller'), kind: 'text' },
      { key: 'calls', label: t('ci_KpiCalls', 'Calls'), kind: 'count' },
      { key: 'answered', label: t('ci_Answered', 'Answered'), kind: 'count' },
      { key: 'missed', label: t('ci_KpiMissed', 'Missed'), kind: 'count' },
      { key: 'last_call', label: t('ci_LastCall', 'Last Call'), kind: 'text' },
    ], r.top_callers.map((x) => ({ ...x, name: numberLabel(x.number), last_call: x.last_call.slice(0, 16) })),
    (row) => openDrill(row.name, { caller: [row.number], direction: ['in'] }), undefined, 10);
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
  document.querySelectorAll('#ci-team th[data-key]').forEach((th) => {
    th.classList.remove('sorted', 'ascending', 'descending');
    if (th.dataset.key === sortKey) th.classList.add('sorted', desc ? 'descending' : 'ascending');
  });
  const body = $id('ci-team').tBodies[0];
  body.replaceChildren();
  if (!rows.length) {
    body.append(emptyRow(10));
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
    if (!r.calls.length) body.append(emptyRow(8));
    r.calls.forEach((call) => body.append(...drillRows(call)));
    const first = (r.page - 1) * r.page_size + 1;
    $id('ci-drill-count').textContent = r.total ? `${first}–${first + r.calls.length - 1} / ${r.total}` : '';
    $id('ci-drill-prev').disabled = r.page <= 1;
    $id('ci-drill-next').disabled = r.page * r.page_size >= r.total;
  });
}

/** Readable names for the CDR's leg dispositions. */
const DISPOSITIONS = {
  ANSWERED: ['ci_LegAnswered', 'Answered'], 'NO ANSWER': ['ci_LegNoAnswer', 'No Answer'], NOANSWER: ['ci_LegNoAnswer', 'No Answer'],
  BUSY: ['ci_LegBusy', 'Busy'], FAILED: ['ci_LegFailed', 'Failed'], CANCEL: ['ci_LegCancelled', 'Cancelled'],
  CONGESTION: ['ci_LegFailed', 'Failed'],
};
const dispositionLabel = (d) => (DISPOSITIONS[d] ? t(...DISPOSITIONS[d]) : String(d || ''));

/**
 * One call as a row, followed by its legs as indented rows in the same
 * columns (hidden until the call is clicked).
 */
function drillRows(call) {
  const legRows = legRowsOf(call.legs);
  const hasRecording = call.legs.some((l) => l.has_recording);
  const playCell = el('td', { class: 'right aligned' });
  if (hasRecording) {
    playCell.append(el('button', {
      class: 'ui mini basic icon button', title: t('ci_Play', 'Play Recording'),
      onclick: (e) => { e.stopPropagation(); play(call.linkedid, playCell); },
    }, el('i', { class: 'play icon' })));
  }
  const to = call.direction === 'in' ? (call.answered_by || call.first_queue || call.callee_num) : call.callee_num;
  const outcome = el('td', {}, ...outcomeCell(call.outcome));
  if (call.transfer_count > 0) outcome.append(el('span', { class: 'ci-muted', text: ` · ${t('ci_Transferred', 'transferred')}` }));
  const row = el('tr', {
    onclick: () => {
      const open = row.classList.toggle('ci-call-open');
      legRows.forEach((r) => r.classList.toggle('ci-hidden', !open));
    },
  },
  el('td', { text: call.start_time.slice(0, 19) }),
  el('td', { text: directionLabel(call.direction) }),
  el('td', { text: numberLabel(call.caller_num) }),
  el('td', { text: numberLabel(to) }),
  outcome,
  el('td', { class: 'right aligned', text: duration(call.wait_seconds) }),
  el('td', { class: 'right aligned', text: duration(call.talk_seconds) }),
  playCell);
  row.style.cursor = 'pointer';
  return [row, ...legRows];
}

/** A call's legs, one row each, in the call table's columns. */
function legRowsOf(legs) {
  return legs.map((l) => {
    const dst = String(l.dst_chan || '');
    let kind = numberLabel(l.dst_num);
    if (dst.startsWith('App:')) kind = `${t('ci_LegIvr', 'IVR')} ${numberLabel(l.dst_num)}`;
    if (dst.startsWith('Queue:')) kind = `${t('ci_LegQueue', 'Queue')} ${numberLabel(l.dst_num)}`;
    if (dst === 'VOICEMAIL') kind = t('ci_LegVoicemail', 'Voicemail');
    return el('tr', { class: 'ci-legs ci-hidden' },
      el('td', { text: String(l.start || '').slice(11, 19) }),
      el('td', {}),
      el('td', { text: `${numberLabel(l.src_num)}${l.src_name ? ` · ${l.src_name}` : ''}` }),
      el('td', { text: kind }),
      el('td', { text: dispositionLabel(l.disposition) }),
      el('td', {}),
      el('td', { class: 'right aligned', text: duration(l.billsec) }),
      el('td', { class: 'right aligned', text: l.dtmf_digits ? `${t('ci_Keys', 'keys')} ${ivrKeysLabel(l.dtmf_digits)}` : '' }));
  });
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
    state.status = s;
    const parts = [];
    if (s.last_run_at) {
      // Just the time when the data is from today.
      const when = s.last_run_at.slice(0, 10) === isoDate(new Date()) ? s.last_run_at.slice(11, 16) : s.last_run_at.slice(0, 16);
      parts.push(`${t('ci_DataAsOf', 'Updated')} ${when}`);
    }
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
      $id('ci-live').classList.toggle('active', n > 0);
      $id('ci-live').title = t('ci_LiveHint', 'Calls in progress right now. 0 can also mean the PBX could not report them.');
    })
    .fail(() => { $id('ci-live-value').textContent = '–'; });
}

/* ------------------------------------------------------------------ call flows */

const FLOW_STAGES = ['line', 'ivr', 'queue', 'person', 'transfer', 'outcome'];
// Every routing stage in shades of the one blue, transfers grey; colour is kept for the outcomes.
const FLOW_COLORS = { line: SHADES.blue[7], ivr: SHADES.blue[6], queue: SHADES.blue[5], person: SHADES.blue[4], transfer: G[5] };
/** Stage => drill-down filter for a node's key (none for transfers). */
const FLOW_FILTERS = { line: 'did', ivr: 'ivr_digits', queue: 'queue', person: 'answered_by', outcome: 'outcome' };

function flowLabel(node) {
  if (node.key === '@other') return t('ci_FlowOther', 'Other');
  switch (node.stage) {
    case 'line': return node.key === '' ? t('ci_FlowNoLine', 'No Line') : node.key;
    case 'ivr': return node.key === '' ? t('ci_FlowIvrNoKey', 'IVR, No Key Recorded')
      : `${t('ci_FlowIvr', 'IVR')} ${ivrKeysLabel(node.key)}`;
    case 'queue': case 'person': return numberLabel(node.key);
    case 'transfer': return t('ci_FlowTransferred', 'Transferred');
    default: return outcomeLabel(node.key);
  }
}

function flowFilters(...nodes) {
  const filters = { direction: ['in'] };
  nodes.forEach((n) => {
    if (n && n.key !== '@other' && FLOW_FILTERS[n.stage]) filters[FLOW_FILTERS[n.stage]] = [n.key];
  });
  return filters;
}

function loadFlows() {
  return get('flows', params()).then((r) => {
    const byId = Object.fromEntries(r.nodes.map((n) => [n.id, n]));
    $id('ci-flows-note').textContent = `${t('ci_InboundCalls', 'Inbound Calls')}: ${r.calls.toLocaleString()}`;
    const c = chart('ci-chart-flows');
    if (!r.calls) {
      showChartMessage(c, noDataText());
      return;
    }
    // Node names are the server's ids (unique per stage); labels are drawn on the canvas.
    c.setOption({
      tooltip: {
        trigger: 'item',
        formatter: (p) => (p.dataType === 'edge'
          ? `${esc(flowLabel(byId[p.data.source]))} → ${esc(flowLabel(byId[p.data.target]))}: <b>${esc(p.data.value)}</b>`
          : `${esc(flowLabel(byId[p.name]))}: <b>${esc(p.value)}</b>`),
      },
      series: [{
        type: 'sankey', left: 10, right: 160, top: 10, bottom: 10, nodeGap: 10, draggable: false,
        // Links take the colour of where they lead, so the bands into each outcome carry its colour.
        emphasis: { focus: 'adjacency', lineStyle: { opacity: 0.55 } },
        lineStyle: { color: 'target', opacity: 0.28, curveness: 0.5 },
        label: { formatter: (p) => flowLabel(byId[p.name]) },
        levels: FLOW_STAGES.map((stage, depth) => ({ depth, itemStyle: { color: FLOW_COLORS[stage] } })),
        data: r.nodes.map((n) => ({
          name: n.id, value: n.value, depth: FLOW_STAGES.indexOf(n.stage),
          itemStyle: n.stage === 'outcome' && OUTCOMES[n.key] ? { color: outcomeColor(n.key) } : undefined,
        })),
        links: r.links,
      }],
    }, true);
    c.off('click');
    c.on('click', (p) => {
      if (p.dataType === 'edge') {
        const a = byId[p.data.source]; const b = byId[p.data.target];
        openDrill(`${flowLabel(a)} → ${flowLabel(b)}`, flowFilters(a, b));
      } else if (byId[p.name]) {
        openDrill(flowLabel(byId[p.name]), flowFilters(byId[p.name]));
      }
    });
  });
}

/* ------------------------------------------------------------------ explore */

const METRIC_NAMES = {
  calls: 'Calls', answered: 'Answered', missed: 'Missed', voicemail: 'Voicemail', inbound: 'Inbound Calls',
  outbound: 'Outbound Calls', talk_seconds: 'Total Talk Time', avg_talk: 'Average Talk Time', avg_wait: 'Average Wait',
  answer_rate: 'Answer Rate (Inbound)', service_level: 'Service Level', unique_callers: 'Unique Callers',
  abandoned: 'Abandoned', busy_failed: 'Busy or Failed', ivr_entered: 'Reached the IVR', ivr_routed: 'Got Past the IVR',
  ivr_keyed: 'Pressed a Key in the IVR', ivr_answered: 'Answered After the IVR',
};
const BREAKDOWN_NAMES = {
  outcome: 'Outcome', direction: 'Direction', did: 'Line (DID)', provider: 'Trunk', queue: 'Queue',
  answered_by: 'Answered By', hour: 'Hour of Day', weekday: 'Weekday', ivr_digits: 'IVR Keys', wait_band: 'Time to Answer',
};
/** Breakdowns whose keys can be used as a drill-down filter of the same name. */
const DRILLABLE = ['outcome', 'direction', 'did', 'provider', 'queue', 'answered_by', 'hour', 'weekday', 'ivr_digits'];
const SECONDS_METRICS = ['talk_seconds', 'avg_talk', 'avg_wait'];
const PERCENT_METRICS = ['answer_rate', 'service_level'];

const metricName = (m) => t(`ci_M_${m}`, METRIC_NAMES[m] || m);
const breakdownName = (b) => (b ? t(`ci_B_${b}`, BREAKDOWN_NAMES[b] || b) : t('ci_Total', 'Total'));
const metricKind = (m) => (SECONDS_METRICS.includes(m) ? 'seconds' : (PERCENT_METRICS.includes(m) ? 'percent' : 'count'));

function keyLabel(breakdown, key) {
  if (!breakdown) return metricName(state.explore.metric);
  const none = t('ci_None', '—');
  switch (breakdown) {
    case 'outcome': return outcomeLabel(key);
    case 'direction': return directionLabel(key);
    case 'provider': return providerLabel(key);
    case 'queue': return key === '' ? t('ci_NoQueue', 'No Queue') : numberLabel(key);
    case 'answered_by': return key === '' ? t('ci_NotAnswered', 'Not Answered') : numberLabel(key);
    case 'weekday': return weekdayLabel(Number(key));
    case 'hour': return `${String(key).padStart(2, '0')}:00`;
    case 'ivr_digits': return key === '' ? t('ci_NoIvr', 'No IVR') : ivrKeysLabel(key);
    case 'wait_band': return key === '' ? none : `${key} s`;
    case 'did': return key === '' ? t('ci_FlowNoLine', 'No Line') : String(key);
    default: return key === '' ? none : String(key);
  }
}

function formatValue(value, metric) {
  if (value === null || value === undefined) return '—';
  const kind = metricKind(metric);
  if (kind === 'seconds') return duration(value);
  if (kind === 'percent') return `${value}%`;
  return Number(value).toLocaleString();
}

/** Fills the metric and breakdown menus from the server's whitelists. */
function loadCatalog() {
  if (state.catalog) return $.Deferred().resolve(state.catalog).promise();
  return get('catalog', {}).then((c) => {
    state.catalog = c;
    const metric = $id('ci-x-metric');
    c.metrics.forEach((m) => metric.add(new Option(metricName(m.key), m.key)));
    const breakdown = $id('ci-x-breakdown');
    c.breakdowns.forEach((b) => breakdown.add(new Option(breakdownName(b), b)));
    syncExploreControls();
    return c;
  });
}

/** Puts state.explore into the menus (values not on the menus fall back to the defaults). */
function syncExploreControls() {
  const x = state.explore;
  state.syncingExplore = true;
  [['metric', 'calls'], ['breakdown', ''], ['interval', 'auto'], ['chart', 'line']].forEach(([name, fallback]) => {
    const select = $id(`ci-x-${name}`);
    if (![...select.options].some((o) => o.value === x[name])) x[name] = fallback;
    select.value = x[name];
    $(select).dropdown('set selected', x[name]);
  });
  state.syncingExplore = false;
}

function readExplore() {
  if (state.syncingExplore) return;
  ['metric', 'breakdown', 'interval', 'chart'].forEach((name) => { state.explore[name] = $id(`ci-x-${name}`).value; });
  saveToUrl();
  loadExplore();
}

function showExploreAs(table) {
  const node = $id('ci-chart-explore'); const tbl = $id('ci-explore-table');
  node.classList.toggle('ci-hidden', table);
  tbl.classList.toggle('ci-hidden', !table);
  if (node.ciExport) node.ciExport.classList.toggle('ci-hidden', table);
  if (tbl.ciExport) tbl.ciExport.classList.toggle('ci-hidden', !table);
  if (!table && state.charts['ci-chart-explore']) state.charts['ci-chart-explore'].resize();
}

function exploreDrill(key, bucket, interval) {
  const x = state.explore;
  const filters = {};
  if (x.breakdown && DRILLABLE.includes(x.breakdown)) filters[x.breakdown] = [String(key)];
  if (interval === 'day') filters.local_date = [bucket];
  if (interval === 'hour') { filters.local_date = [bucket.slice(0, 10)]; filters.hour = [String(Number(bucket.slice(11)))]; }
  const parts = [metricName(x.metric)];
  if (x.breakdown) parts.push(keyLabel(x.breakdown, key));
  if (interval !== 'none' && bucket) parts.push(bucket);
  openDrill(parts.join(' · '), filters);
}

function loadExplore() {
  return loadCatalog().then(() => {
    const x = state.explore;
    const msg = $id('ci-x-message');
    msg.className = 'ci-hidden';
    let interval = x.interval === 'auto' ? intervalFor(state.from, state.to) : x.interval;
    if (x.chart === 'pie') interval = 'none';
    if (x.chart === 'pie' && !x.breakdown) {
      message('ci-x-message', 'info', t('ci_XPieNeedsBreakdown', 'A pie chart needs a breakdown.'));
      chart('ci-chart-explore').clear();
      showExploreAs(false);
      return null;
    }
    const compare = state.compare && !x.breakdown && ['line', 'bar'].includes(x.chart);
    const data = { metric: x.metric, interval, ...(x.breakdown ? { breakdown: x.breakdown } : {}), ...(compare ? { compare: '1' } : {}) };
    return get('query', params(data)).then((r) => {
      // A series with no value anywhere (an average over no calls) only clutters the legend.
      const cur = { ...r.current, series: r.current.series.filter((x) => x.values.some((v) => v !== null && v !== 0)) };
      const sourceNote = cur.source === 'daily' ? t('ci_XFromTotals', 'Answered from the daily totals.') : '';
      $id('ci-x-note').textContent = sourceNote;
      if (x.chart === 'table') renderExploreTable(cur);
      else renderExploreChart(cur, compare ? r.previous : null);
    });
  });
}

function renderExploreChart(cur, prev) {
  const x = state.explore;
  showExploreAs(false);
  const c = chart('ci-chart-explore');
  const valueFmt = (v) => formatValue(v, x.metric);
  const tooltip = {
    trigger: x.chart === 'pie' ? 'item' : 'axis',
    formatter: (ps) => {
      const rows = (Array.isArray(ps) ? ps : [ps]).filter((p) => p.value !== null && p.value !== undefined);
      if (!rows.length) return '';
      if (x.chart === 'pie') return `${esc(rows[0].name)}: <b>${esc(valueFmt(rows[0].value))}</b> (${esc(rows[0].percent)}%)`;
      return `${esc(rows[0].axisValueLabel)}<br>${rows.map((p) => `${p.marker}${esc(p.seriesName)}: <b>${esc(valueFmt(p.value))}</b>`).join('<br>')}`;
    },
  };
  if (!cur.series.length) {
    showChartMessage(c, noDataText());
    return;
  }
  if (x.chart === 'pie') {
    c.setOption({
      color: PALETTE.series,
      tooltip, legend: { type: 'scroll', orient: 'vertical', left: 0, top: 'middle', icon: 'circle', itemWidth: 9, itemHeight: 9 },
      series: [{ type: 'pie', radius: ['40%', '70%'], center: ['60%', '50%'], itemStyle: { borderColor: '#fff', borderWidth: 2 },
        data: cur.series.map((s) => ({ name: keyLabel(x.breakdown, s.key), value: s.values[0], key: s.key,
          itemStyle: x.breakdown === 'outcome' && OUTCOMES[s.key] ? { color: outcomeColor(s.key) } : undefined })) }],
    }, true);
  } else {
    const type = x.chart === 'line' ? 'line' : 'bar';
    const series = cur.series.map((s) => ({
      name: keyLabel(x.breakdown, s.key), type, data: s.values, key: s.key,
      stack: x.chart === 'stacked' ? 'all' : undefined, smooth: type === 'line' ? 0.2 : undefined,
      barMaxWidth: 32,
      itemStyle: x.breakdown === 'outcome' && OUTCOMES[s.key] ? { color: outcomeColor(s.key) } : undefined,
    }));
    if (prev && prev.series[0]) {
      series.push({ name: t('ci_PreviousPeriod', 'Previous Period'), type, data: prev.series[0].values, lineStyle: { type: 'dashed' },
        itemStyle: { color: PALETTE.muted }, silent: true });
    }
    c.setOption({
      color: PALETTE.series,
      tooltip, legend: { type: 'scroll', top: 0, icon: 'circle', itemWidth: 9, itemHeight: 9 },
      grid: { left: 60, right: 20, top: 40, bottom: 60 },
      xAxis: axis({ type: 'category', data: cur.buckets, axisTick: { show: false } }),
      yAxis: axis({ type: 'value', axisLabel: { formatter: valueFmt, color: PALETTE.label } }),
      dataZoom: cur.buckets.length > 31 ? [{ type: 'slider', bottom: 10 }] : [],
      series,
    }, true);
  }
  c.off('click');
  c.on('click', (p) => {
    const s = x.chart === 'pie' ? p.data : cur.series[p.seriesIndex];
    if (!s || s.key === undefined) return;
    exploreDrill(s.key, x.chart === 'pie' ? null : cur.buckets[p.dataIndex], cur.interval);
  });
}

/** Rows are the breakdown keys, columns the time buckets (plus a total for counts). */
function renderExploreTable(cur) {
  const x = state.explore;
  showExploreAs(true);
  const kind = metricKind(x.metric);
  const counts = state.catalog.metrics.find((m) => m.key === x.metric).count;
  const columns = [{ key: 'name', label: breakdownName(x.breakdown), kind: 'text' }];
  const many = cur.buckets.length > 1;
  cur.buckets.forEach((b, i) => columns.push({ key: `b${i}`, label: cur.interval === 'none' ? metricName(x.metric) : b, kind }));
  if (many && counts) columns.push({ key: 'total', label: t('ci_Total', 'Total'), kind });
  const rows = cur.series.map((s) => {
    const row = { name: keyLabel(x.breakdown, s.key), key: s.key };
    s.values.forEach((v, i) => { row[`b${i}`] = v; });
    if (many && counts) row.total = s.values.reduce((a, v) => a + (v || 0), 0);
    return row;
  });
  renderTable($id('ci-explore-table'), columns, rows, (row) => exploreDrill(row.key, null, 'none'),
    { key: many && counts ? 'total' : (columns[1] && columns[1].key), desc: true });
}

/* ------------------------------------------------------------------ saved reports */

function currentDefinition() {
  const preset = $id('ci-preset').value;
  const d = { ...state.explore, preset, compare: state.compare, filters: state.filters };
  if (preset === 'custom') { d.from = state.from; d.to = state.to; }
  return d;
}

function loadReports(selectId) {
  return get('reports', {}).then((reports) => {
    state.reports = reports;
    const select = $id('ci-x-report');
    while (select.options.length > 1) select.remove(1);
    reports.forEach((r) => select.add(new Option(r.name, String(r.id))));
    $(select).dropdown('refresh');
    $(select).dropdown('set selected', selectId ? String(selectId) : '');
    $id('ci-x-delete').disabled = !selectId;
  });
}

/** Opens a saved report: its range, filters and choices become the current view. */
function openReport(id) {
  const r = (state.reports || []).find((x) => String(x.id) === String(id));
  $id('ci-x-delete').disabled = !r;
  if (!r) return;
  const d = r.definition;
  Object.assign(state.explore, { metric: d.metric, breakdown: d.breakdown, interval: d.interval, chart: d.chart });
  syncExploreControls();
  $('#ci-preset').dropdown('set selected', d.preset);
  $id('ci-preset').value = d.preset;
  if (d.preset === 'custom') { $id('ci-from').value = d.from; $id('ci-to').value = d.to; } else applyPreset(d.preset);
  $id('ci-compare').checked = d.compare;
  ['direction', 'did', 'queue', 'answered_by'].forEach((name) => {
    const v = d.filters[name] ? d.filters[name][0] : '';
    const select = $id(`ci-filter-${name}`);
    if (v && ![...select.options].some((o) => o.value === v)) select.add(new Option(v, v));
    select.value = v;
    $(select).dropdown('set selected', v);
  });
  refresh();
}

function saveReport() {
  const selected = (state.reports || []).find((x) => String(x.id) === $id('ci-x-report').value);
  // eslint-disable-next-line no-alert
  const name = window.prompt(t('ci_XReportName', 'Report name'), selected ? selected.name : '');
  if (name === null || name.trim() === '') return;
  post('saveReport', { name: name.trim(), definition: JSON.stringify(currentDefinition()) }).then((r) => {
    message('ci-x-message', 'positive', t('ci_XSaved', 'Report saved.'));
    loadReports(r.data.id);
  }, (xhr) => message('ci-x-message', 'negative', failText(xhr, t('ci_XSaveFailed', 'The report could not be saved.'))));
}

function deleteReport() {
  const id = $id('ci-x-report').value;
  const r = (state.reports || []).find((x) => String(x.id) === id);
  // eslint-disable-next-line no-alert
  if (!r || !window.confirm(`${t('ci_XDeleteConfirm', 'Delete this report?')} ${r.name}`)) return;
  post('deleteReport', { id }).then(() => {
    message('ci-x-message', 'positive', t('ci_XDeleted', 'Report deleted.'));
    loadReports(null);
  }, (xhr) => message('ci-x-message', 'negative', failText(xhr, t('ci_XSaveFailed', 'The report could not be saved.'))));
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
    return [columns.map((c) => c.label), ...rows.map((r) => columns.map((c) => (c.kind === 'outcome' ? outcomeLabel(r[c.key]) : r[c.key])))];
  }
  return [...table.rows].map((tr) => [...tr.cells].map((c) => c.textContent.trim()));
}

/**
 * The heading row a table or chart belongs to: the nearest section heading
 * before it in the same container (a chart and the table under it share one).
 */
function sectionActions(node) {
  for (let n = node.previousElementSibling; n; n = n.previousElementSibling) {
    if (n.classList.contains('ci-section-head')) return n.querySelector('.ci-section-actions');
  }
  const head = el('div', { class: 'ci-section-head' }, el('span'), el('div', { class: 'ci-section-actions' }));
  node.before(head);
  return head.querySelector('.ci-section-actions');
}

/** Adds a CSV button for every table and a PNG button for every chart, in its section's heading row. */
function addExportButtons() {
  const button = (label, icon, title, onClick) => el('button', { class: 'ui mini basic compact button', type: 'button', title, onclick: onClick },
    el('i', { class: `${icon} icon` }), label);
  document.querySelectorAll('#call-insights .tab.segment .ci-chart').forEach((node) => {
    node.ciExport = button(t('ci_ExportPng', 'PNG'), 'image', t('ci_ExportPngHint', 'Download this chart as an image'), () => {
      const c = state.charts[node.id];
      if (!c) return;
      // Decoded here: the core's CSP (connect-src) blocks fetch() of a data: URL.
      const bytes = atob(c.getDataURL({ type: 'png', pixelRatio: 2, backgroundColor: '#fff' }).split(',')[1]);
      const png = Uint8Array.from(bytes, (ch) => ch.charCodeAt(0));
      download(`${node.id.replace(/^ci-chart-/, '')}-${state.from}-${state.to}.png`, new Blob([png], { type: 'image/png' }));
    });
    sectionActions(node).append(node.ciExport);
  });
  document.querySelectorAll('#call-insights .tab.segment table').forEach((table) => {
    table.ciExport = button(t('ci_ExportCsv', 'CSV'), 'download', t('ci_ExportCsvHint', 'Download this table as CSV'),
      () => downloadCsv(table.id.replace(/^ci-/, ''), tableRows(table)));
    sectionActions(table).append(table.ciExport);
  });
}

/** Fades the tab bar's right edge while more tabs are scrolled out of view. */
function updateTabsOverflow() {
  const tabs = $id('ci-tabs');
  const overflow = tabs.scrollWidth > tabs.clientWidth + 1;
  tabs.classList.toggle('ci-overflow', overflow);
  tabs.classList.toggle('ci-at-end', overflow && tabs.scrollLeft + tabs.clientWidth >= tabs.scrollWidth - 1);
}

const backupMessage = (kind, text) => message('ci-backup-message', kind, text);

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

/* ------------------------------------------------------------------ settings */

function message(id, kind, text) {
  const box = $id(id);
  box.className = `ui ${kind} message`;
  box.textContent = text;
}

const failText = (xhr, fallback) => (xhr && xhr.responseJSON && xhr.responseJSON.message) || fallback;

function loadSettings() {
  return get('settings', {}).then((s) => {
    $id('ci-service-level').value = s.service_level_seconds;
    $id('ci-callback-window').value = s.callback_window_hours;
    $('#ci-backfill-speed').dropdown('set selected', s.backfill_speed);
    $id('ci-rebuild').disabled = !!s.rebuild_pending;
    if (s.rebuild_pending) message('ci-rebuild-message', 'info', t('ci_RebuildPending', 'A rebuild is waiting for the indexer; it starts within a minute.'));
  });
}

/** Write actions are POSTs through $.ajax, so the core's TokenManager adds the Bearer token they require. */
function post(action, data) {
  return $.ajax({ url: BASE + action, method: 'POST', data, dataType: 'json' });
}

function saveSettings(e) {
  e.preventDefault();
  post('saveSettings', {
    service_level_seconds: $id('ci-service-level').value,
    callback_window_hours: $id('ci-callback-window').value,
    backfill_speed: $id('ci-backfill-speed').value,
  }).then(() => message('ci-settings-message', 'positive', t('ci_SettingsSaved', 'Settings saved.')),
    (xhr) => message('ci-settings-message', 'negative', failText(xhr, t('ci_SaveFailed', 'The settings could not be saved.'))));
}

function rebuild() {
  // eslint-disable-next-line no-alert
  if (!window.confirm(t('ci_RebuildConfirm', 'Rebuild the call index from the call history? The dashboard is incomplete until it finishes.'))) return;
  post('rebuild', {}).then(() => {
    $id('ci-rebuild').disabled = true;
    message('ci-rebuild-message', 'info', t('ci_RebuildPending', 'A rebuild is waiting for the indexer; it starts within a minute.'));
  }, (xhr) => message('ci-rebuild-message', 'negative', failText(xhr, t('ci_SaveFailed', 'The settings could not be saved.'))));
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
  if (state.tab === 'flows') loadFlows();
  if (state.tab === 'explore') { loadExplore(); if (!state.reports) loadReports(null); }
  if (state.tab === 'settings') loadSettings();
}

function selectTab(tab) {
  state.tab = tab;
  document.querySelectorAll('#call-insights [data-tab]').forEach((n) => n.classList.toggle('active', n.dataset.tab === tab));
  // Bring the tab into view sideways only, without scrolling the page.
  const bar = $id('ci-tabs');
  const item = bar.querySelector(`[data-tab="${tab}"]`);
  if (item) {
    const left = item.getBoundingClientRect().left - bar.getBoundingClientRect().left + bar.scrollLeft;
    if (left < bar.scrollLeft || left + item.offsetWidth > bar.scrollLeft + bar.clientWidth) bar.scrollLeft = left - 16;
  }
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
  ['metric', 'breakdown', 'interval', 'chart'].forEach((name) => $id(`ci-x-${name}`).addEventListener('change', readExplore));
  $id('ci-x-report').addEventListener('change', (e) => openReport(e.target.value));
  $id('ci-x-save').addEventListener('click', saveReport);
  $id('ci-x-delete').addEventListener('click', deleteReport);
  $id('ci-settings-form').addEventListener('submit', saveSettings);
  $id('ci-rebuild').addEventListener('click', rebuild);
  $id('ci-export-totals').addEventListener('click', exportTotals);
  $id('ci-import-totals').addEventListener('click', () => $id('ci-import-file').click());
  $id('ci-import-file').addEventListener('change', (e) => importTotals(e.target.files[0]));
  $id('ci-drill-prev').addEventListener('click', () => { state.drill.page -= 1; loadDrillPage(); });
  $id('ci-drill-next').addEventListener('click', () => { state.drill.page += 1; loadDrillPage(); });
  window.addEventListener('resize', () => { Object.values(state.charts).forEach((c) => c.resize()); updateTabsOverflow(); });
  $id('ci-tabs').addEventListener('scroll', updateTabsOverflow, { passive: true });
  updateTabsOverflow();

  get('labels', {}).always((labels) => {
    if (labels && labels.extensions) state.labels = labels;
    selectTab(state.tab);
  });
  pollLive();
  setInterval(pollLive, 10000);
}

$(document).ready(init);
