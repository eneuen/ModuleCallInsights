{#
  Call Insights dashboard shell. No CDR data is rendered here: the script
  loads it as JSON and inserts it as text only (plan: Security). Only
  translations go through Volt, escaped with |e.
#}
<div id="call-insights" class="ci-dashboard">

    <div class="ui form ci-toolbar">
        <div class="ci-controls">
            <div class="ci-toolbar-row">
                <div class="ci-range">
                    <select id="ci-preset" class="ui dropdown">
                        <option value="today">{{ t._('ci_PresetToday')|e }}</option>
                        <option value="7">{{ t._('ci_PresetLast7')|e }}</option>
                        <option value="30" selected>{{ t._('ci_PresetLast30')|e }}</option>
                        <option value="month">{{ t._('ci_PresetThisMonth')|e }}</option>
                        <option value="90">{{ t._('ci_PresetLast90')|e }}</option>
                        <option value="365">{{ t._('ci_PresetLast365')|e }}</option>
                        <option value="custom">{{ t._('ci_PresetCustom')|e }}</option>
                    </select>
                    <input type="date" id="ci-from" aria-label="{{ t._('ci_From')|e }}">
                    <span class="ci-range-sep" aria-hidden="true">–</span>
                    <input type="date" id="ci-to" aria-label="{{ t._('ci_To')|e }}">
                    <div class="ui checkbox" id="ci-compare-box">
                        <input type="checkbox" id="ci-compare">
                        <label for="ci-compare">{{ t._('ci_ComparePrevious')|e }}</label>
                    </div>
                </div>
            </div>
            <div class="ci-toolbar-row ci-filters">
                <select id="ci-filter-direction" class="ui dropdown">
                    <option value="">{{ t._('ci_AllDirections')|e }}</option>
                    <option value="in">{{ t._('ci_Inbound')|e }}</option>
                    <option value="out">{{ t._('ci_Outbound')|e }}</option>
                    <option value="internal">{{ t._('ci_Internal')|e }}</option>
                </select>
                <select id="ci-filter-did" class="ui dropdown"><option value="">{{ t._('ci_AllLines')|e }}</option></select>
                <select id="ci-filter-queue" class="ui dropdown"><option value="">{{ t._('ci_AllQueues')|e }}</option></select>
                <select id="ci-filter-answered_by" class="ui dropdown"><option value="">{{ t._('ci_AllExtensions')|e }}</option></select>
            </div>
        </div>
        <div class="ci-status">
            <span class="ci-live" id="ci-live"><span class="ci-live-dot" aria-hidden="true"></span><b id="ci-live-value">–</b> {{ t._('ci_LiveNow')|e }}</span>
            <span class="ci-asof" id="ci-asof"></span>
        </div>
    </div>

    <div class="ui info message ci-hidden" id="ci-backfill-message">{{ t._('ci_BackfillRunning')|e }}</div>
    <div class="ui negative message ci-hidden" id="ci-error"></div>

    <div class="ui top attached tabular menu" id="ci-tabs">
        <a class="item active" data-tab="overview">{{ t._('ci_TabOverview')|e }}</a>
        <a class="item" data-tab="traffic">{{ t._('ci_TabTraffic')|e }}</a>
        <a class="item" data-tab="team">{{ t._('ci_TabTeam')|e }}</a>
        <a class="item" data-tab="lines">{{ t._('ci_TabLines')|e }}</a>
        <a class="item" data-tab="queues">{{ t._('ci_TabQueues')|e }}</a>
        <a class="item" data-tab="flows">{{ t._('ci_TabFlows')|e }}</a>
        <a class="item" data-tab="callers">{{ t._('ci_TabCallers')|e }}</a>
        <a class="item" data-tab="explore">{{ t._('ci_TabExplore')|e }}</a>
        <div class="right menu">
            <a class="item" data-tab="settings"><i class="cog icon"></i>{{ t._('ci_TabSettings')|e }}</a>
        </div>
    </div>

    <div class="ui bottom attached tab segment active" data-tab="overview">
        <div class="ci-kpi-grid ci-kpi-grid-4" id="ci-kpis"></div>
        <div class="ci-section-head"><h4 class="ui header">{{ t._('ci_CallsByOutcome')|e }} <span class="ci-subheader" id="ci-outcomes-note"></span></h4><div class="ci-section-actions"></div></div>
        <div class="ci-chart" id="ci-chart-outcomes"></div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="traffic">
        <div class="ci-section-head"><h4 class="ui header">{{ t._('ci_InboundByHour')|e }}</h4><div class="ci-section-actions"></div></div>
        <div class="ci-chart ci-chart-tall" id="ci-chart-heatmap"></div>
        <div class="ci-section-head"><h4 class="ui header">{{ t._('ci_DailyVolume')|e }}</h4><div class="ci-section-actions"></div></div>
        <div class="ci-chart" id="ci-chart-calendar"></div>
        <div class="ci-section-head"><h4 class="ui header">{{ t._('ci_PeakConcurrent')|e }} <span class="ci-subheader" id="ci-peak-note"></span></h4><div class="ci-section-actions"></div></div>
        <div class="ci-chart ci-chart-short" id="ci-chart-concurrency"></div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="team">
        <div class="ci-section-head"><h4 class="ui header">{{ t._('ci_TeamHeader')|e }}</h4><div class="ci-section-actions"></div></div>
        <div class="ci-scroll-x">
            <table class="ui very basic selectable sortable table ci-table" id="ci-team">
                <thead>
                <tr>
                    <th data-key="name">{{ t._('ci_Extension')|e }}</th>
                    <th data-key="rang" class="right aligned">{{ t._('ci_Rang')|e }}</th>
                    <th data-key="answered" class="right aligned">{{ t._('ci_Answered')|e }}</th>
                    <th data-key="unanswered" class="right aligned">{{ t._('ci_Unanswered')|e }}</th>
                    <th data-key="answer_rate" class="right aligned">{{ t._('ci_AnswerRate')|e }}</th>
                    <th data-key="avg_ring" class="right aligned">{{ t._('ci_AvgRing')|e }}</th>
                    <th data-key="talk_seconds" class="right aligned">{{ t._('ci_TotalTalk')|e }}</th>
                    <th data-key="avg_talk" class="right aligned">{{ t._('ci_AvgTalk')|e }}</th>
                    <th data-key="outbound" class="right aligned">{{ t._('ci_OutboundCalls')|e }}</th>
                    <th data-key="internal_received" class="right aligned" title="{{ t._('ci_InternalReceivedHint')|e }}">{{ t._('ci_InternalReceived')|e }}</th>
                    <th data-key="internal_made" class="right aligned" title="{{ t._('ci_InternalMadeHint')|e }}">{{ t._('ci_InternalMade')|e }}</th>
                    <th data-key="share">{{ t._('ci_ShareOfAnswered')|e }}</th>
                </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <p class="ci-note">{{ t._('ci_TeamNote')|e }}</p>
    </div>

    <div class="ui bottom attached tab segment" data-tab="lines">
        <div class="ci-section-head"><h4 class="ui header">{{ t._('ci_InboundByLine')|e }}</h4><div class="ci-section-actions"></div></div>
        <div class="ci-chart" id="ci-chart-lines"></div>
        <table class="ui very basic selectable sortable table ci-table" id="ci-lines-table"></table>
        <div class="ci-section-head"><h4 class="ui header">{{ t._('ci_ByTrunk')|e }}</h4><div class="ci-section-actions"></div></div>
        <table class="ui very basic selectable sortable table ci-table" id="ci-trunks-table"></table>
    </div>

    <div class="ui bottom attached tab segment" data-tab="queues">
        <div class="ci-section-head"><h4 class="ui header">{{ t._('ci_Queues')|e }}</h4><div class="ci-section-actions"></div></div>
        <table class="ui very basic selectable sortable table ci-table" id="ci-queues-table"></table>
        <div class="ui two column stackable grid">
            <div class="column">
                <div class="ci-section-head"><h4 class="ui header">{{ t._('ci_WaitBands')|e }}</h4><div class="ci-section-actions"></div></div>
                <div class="ci-chart ci-chart-medium" id="ci-chart-waits"></div>
            </div>
            <div class="column">
                <div class="ci-section-head"><h4 class="ui header">{{ t._('ci_IvrFunnel')|e }}</h4><div class="ci-section-actions"></div></div>
                <div class="ci-chart ci-chart-medium" id="ci-chart-funnel"></div>
            </div>
        </div>
        <div class="ci-section-head"><h4 class="ui header">{{ t._('ci_IvrKeys')|e }}</h4><div class="ci-section-actions"></div></div>
        <table class="ui very basic selectable sortable table ci-table" id="ci-ivr-table"></table>
    </div>

    <div class="ui bottom attached tab segment" data-tab="callers">
        <div class="ci-kpi-grid ci-kpi-grid-5" id="ci-callers-kpis"></div>
        <div class="ci-section-head"><h4 class="ui header">{{ t._('ci_NotReturned')|e }} <span class="ci-subheader" id="ci-not-returned-note"></span></h4><div class="ci-section-actions"></div></div>
        <table class="ui very basic selectable sortable table ci-table" id="ci-not-returned-table"></table>
        <div class="ci-section-head"><h4 class="ui header">{{ t._('ci_TopCallers')|e }}</h4><div class="ci-section-actions"></div></div>
        <table class="ui very basic selectable sortable table ci-table" id="ci-top-callers-table"></table>
    </div>

    <div class="ui bottom attached tab segment" data-tab="flows">
        <div class="ci-section-head"><h4 class="ui header">{{ t._('ci_FlowsHeader')|e }} <span class="ci-subheader" id="ci-flows-note"></span></h4><div class="ci-section-actions"></div></div>
        <div class="ci-chart ci-chart-flows" id="ci-chart-flows"></div>
        <p class="ci-note">{{ t._('ci_FlowsNote')|e }}</p>
    </div>

    <div class="ui bottom attached tab segment" data-tab="explore">
        <div class="ui form">
            <div class="ci-explore-controls">
                <div class="field">
                    <label for="ci-x-metric">{{ t._('ci_XMetric')|e }}</label>
                    <select id="ci-x-metric" class="ui search dropdown"></select>
                </div>
                <div class="field">
                    <label for="ci-x-breakdown">{{ t._('ci_XBreakdown')|e }}</label>
                    <select id="ci-x-breakdown" class="ui dropdown"><option value="">{{ t._('ci_XNoBreakdown')|e }}</option></select>
                </div>
                <div class="field">
                    <label for="ci-x-interval">{{ t._('ci_XInterval')|e }}</label>
                    <select id="ci-x-interval" class="ui dropdown">
                        <option value="auto">{{ t._('ci_XAuto')|e }}</option>
                        <option value="none">{{ t._('ci_XWholeRange')|e }}</option>
                        <option value="hour">{{ t._('ci_XHour')|e }}</option>
                        <option value="day">{{ t._('ci_XDay')|e }}</option>
                        <option value="week">{{ t._('ci_XWeek')|e }}</option>
                        <option value="month">{{ t._('ci_XMonth')|e }}</option>
                    </select>
                </div>
                <div class="field">
                    <label for="ci-x-chart">{{ t._('ci_XChart')|e }}</label>
                    <select id="ci-x-chart" class="ui dropdown">
                        <option value="line">{{ t._('ci_XLine')|e }}</option>
                        <option value="bar">{{ t._('ci_XBar')|e }}</option>
                        <option value="stacked">{{ t._('ci_XStacked')|e }}</option>
                        <option value="pie">{{ t._('ci_XPie')|e }}</option>
                        <option value="table">{{ t._('ci_XTable')|e }}</option>
                    </select>
                </div>
            </div>
            <div class="ci-explore-reports">
                <select id="ci-x-report" class="ui search dropdown"><option value="">{{ t._('ci_XSavedReports')|e }}</option></select>
                <button class="ui basic button" type="button" id="ci-x-save"><i class="save icon"></i> {{ t._('ci_XSave')|e }}</button>
                <button class="ui basic button" type="button" id="ci-x-delete" disabled><i class="trash icon"></i> {{ t._('ci_XDelete')|e }}</button>
            </div>
        </div>
        <div class="ci-hidden" id="ci-x-message"></div>
        <div class="ci-section-head"><span class="ci-note" id="ci-x-note"></span><div class="ci-section-actions"></div></div>
        <div class="ci-chart ci-chart-tall" id="ci-chart-explore"></div>
        <table class="ui very basic selectable sortable table ci-table ci-hidden" id="ci-explore-table"></table>
    </div>

    <div class="ui bottom attached tab segment" data-tab="settings">
        <form class="ui form" id="ci-settings-form">
            <div class="three fields">
                <div class="field">
                    <label for="ci-service-level">{{ t._('ci_SettingServiceLevel')|e }}</label>
                    <input type="number" min="1" max="600" step="1" id="ci-service-level" name="service_level_seconds" required>
                    <small class="ci-note">{{ t._('ci_SettingServiceLevelHint')|e }}</small>
                </div>
                <div class="field">
                    <label for="ci-callback-window">{{ t._('ci_SettingCallbackWindow')|e }}</label>
                    <input type="number" min="1" max="720" step="1" id="ci-callback-window" name="callback_window_hours" required>
                    <small class="ci-note">{{ t._('ci_SettingCallbackWindowHint')|e }}</small>
                </div>
                <div class="field">
                    <label for="ci-backfill-speed">{{ t._('ci_SettingBackfillSpeed')|e }}</label>
                    <select id="ci-backfill-speed" name="backfill_speed" class="ui dropdown">
                        <option value="slow">{{ t._('ci_SpeedSlow')|e }}</option>
                        <option value="normal">{{ t._('ci_SpeedNormal')|e }}</option>
                        <option value="fast">{{ t._('ci_SpeedFast')|e }}</option>
                    </select>
                    <small class="ci-note">{{ t._('ci_SettingBackfillSpeedHint')|e }}</small>
                </div>
            </div>
            <button class="ui primary button" type="submit" id="ci-settings-save">{{ t._('ci_Save')|e }}</button>
        </form>
        <div class="ci-hidden" id="ci-settings-message"></div>

        <div class="ci-section-head"><h4 class="ui header">{{ t._('ci_RebuildHeader')|e }}</h4><div class="ci-section-actions"></div></div>
        <p class="ci-note">{{ t._('ci_RebuildNote')|e }}</p>
        <button class="ui button" type="button" id="ci-rebuild"><i class="redo icon"></i> {{ t._('ci_Rebuild')|e }}</button>
        <div class="ci-hidden" id="ci-rebuild-message"></div>

        <div class="ci-section-head"><h4 class="ui header">{{ t._('ci_BackupHeader')|e }}</h4><div class="ci-section-actions"></div></div>
        <p class="ci-note">{{ t._('ci_BackupNote')|e }}</p>
        <button class="ui button" type="button" id="ci-export-totals"><i class="download icon"></i> {{ t._('ci_ExportTotals')|e }}</button>
        <button class="ui button" type="button" id="ci-import-totals"><i class="upload icon"></i> {{ t._('ci_ImportTotals')|e }}</button>
        <input type="file" id="ci-import-file" accept=".csv,text/csv" class="ci-hidden">
        <div class="ci-hidden" id="ci-backup-message"></div>
    </div>

    <div class="ui large modal" id="ci-drill">
        <i class="close icon"></i>
        <div class="header" id="ci-drill-title"></div>
        <div class="scrolling content">
            <table class="ui very basic compact table" id="ci-drill-table">
                <thead>
                <tr>
                    <th>{{ t._('ci_Time')|e }}</th>
                    <th>{{ t._('ci_Direction')|e }}</th>
                    <th>{{ t._('ci_From')|e }}</th>
                    <th>{{ t._('ci_To')|e }}</th>
                    <th>{{ t._('ci_Outcome')|e }}</th>
                    <th class="right aligned">{{ t._('ci_Wait')|e }}</th>
                    <th class="right aligned">{{ t._('ci_Talk')|e }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="actions">
            <span class="ci-drill-count" id="ci-drill-count"></span>
            <button class="ui button" id="ci-drill-prev">{{ t._('ci_Previous')|e }}</button>
            <button class="ui button" id="ci-drill-next">{{ t._('ci_Next')|e }}</button>
        </div>
    </div>
</div>
