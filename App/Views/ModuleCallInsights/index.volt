{#
  Call Insights dashboard shell. No CDR data is rendered here: the script
  loads it as JSON and inserts it as text only (plan: Security). Only
  translations go through Volt, escaped with |e.
#}
<div id="call-insights" class="ci-dashboard">

    <div class="ui grid ci-header">
        <div class="ui ten wide column">
            <div class="ui form">
                <div class="inline fields">
                    <div class="field">
                        <select id="ci-preset" class="ui dropdown">
                            <option value="today">{{ t._('ci_PresetToday')|e }}</option>
                            <option value="7">{{ t._('ci_PresetLast7')|e }}</option>
                            <option value="30" selected>{{ t._('ci_PresetLast30')|e }}</option>
                            <option value="month">{{ t._('ci_PresetThisMonth')|e }}</option>
                            <option value="90">{{ t._('ci_PresetLast90')|e }}</option>
                            <option value="365">{{ t._('ci_PresetLast365')|e }}</option>
                            <option value="custom">{{ t._('ci_PresetCustom')|e }}</option>
                        </select>
                    </div>
                    <div class="field"><input type="date" id="ci-from" aria-label="{{ t._('ci_From')|e }}"></div>
                    <div class="field"><input type="date" id="ci-to" aria-label="{{ t._('ci_To')|e }}"></div>
                    <div class="field">
                        <div class="ui checkbox" id="ci-compare-box">
                            <input type="checkbox" id="ci-compare">
                            <label for="ci-compare">{{ t._('ci_ComparePrevious')|e }}</label>
                        </div>
                    </div>
                </div>
                <div class="inline fields">
                    <div class="field">
                        <select id="ci-filter-direction" class="ui dropdown">
                            <option value="">{{ t._('ci_AllDirections')|e }}</option>
                            <option value="in">{{ t._('ci_Inbound')|e }}</option>
                            <option value="out">{{ t._('ci_Outbound')|e }}</option>
                            <option value="internal">{{ t._('ci_Internal')|e }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <select id="ci-filter-did" class="ui dropdown"><option value="">{{ t._('ci_AllLines')|e }}</option></select>
                    </div>
                    <div class="field">
                        <select id="ci-filter-queue" class="ui dropdown"><option value="">{{ t._('ci_AllQueues')|e }}</option></select>
                    </div>
                    <div class="field">
                        <select id="ci-filter-answered_by" class="ui dropdown"><option value="">{{ t._('ci_AllExtensions')|e }}</option></select>
                    </div>
                </div>
            </div>
        </div>
        <div class="ui six wide column right aligned">
            <div class="ui tiny statistic ci-live" id="ci-live">
                <div class="value" id="ci-live-value">–</div>
                <div class="label">{{ t._('ci_LiveNow')|e }}</div>
            </div>
            <div class="ci-asof" id="ci-asof"></div>
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
        <a class="item" data-tab="callers">{{ t._('ci_TabCallers')|e }}</a>
        <a class="item" data-tab="settings">{{ t._('ci_TabSettings')|e }}</a>
    </div>

    <div class="ui bottom attached tab segment active" data-tab="overview">
        <div class="ui eight doubling statistics ci-kpis" id="ci-kpis"></div>
        <h4 class="ui header">{{ t._('ci_CallsByOutcome')|e }}</h4>
        <div class="ci-chart" id="ci-chart-outcomes"></div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="traffic">
        <h4 class="ui header">{{ t._('ci_InboundByHour')|e }}</h4>
        <div class="ci-chart ci-chart-tall" id="ci-chart-heatmap"></div>
        <h4 class="ui header">{{ t._('ci_DailyVolume')|e }}</h4>
        <div class="ci-chart" id="ci-chart-calendar"></div>
        <h4 class="ui header">{{ t._('ci_PeakConcurrent')|e }} <span class="ci-subheader" id="ci-peak-note"></span></h4>
        <div class="ci-chart" id="ci-chart-concurrency"></div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="team">
        <table class="ui very basic selectable sortable table" id="ci-team">
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
                <th data-key="share">{{ t._('ci_ShareOfAnswered')|e }}</th>
            </tr>
            </thead>
            <tbody></tbody>
        </table>
        <p class="ci-note">{{ t._('ci_TeamNote')|e }}</p>
    </div>

    <div class="ui bottom attached tab segment" data-tab="lines">
        <h4 class="ui header">{{ t._('ci_InboundByLine')|e }}</h4>
        <div class="ci-chart" id="ci-chart-lines"></div>
        <table class="ui very basic selectable sortable table ci-table" id="ci-lines-table"></table>
        <h4 class="ui header">{{ t._('ci_ByTrunk')|e }}</h4>
        <table class="ui very basic selectable sortable table ci-table" id="ci-trunks-table"></table>
    </div>

    <div class="ui bottom attached tab segment" data-tab="queues">
        <h4 class="ui header">{{ t._('ci_Queues')|e }}</h4>
        <table class="ui very basic selectable sortable table ci-table" id="ci-queues-table"></table>
        <div class="ui two column stackable grid">
            <div class="column">
                <h4 class="ui header">{{ t._('ci_WaitBands')|e }}</h4>
                <div class="ci-chart" id="ci-chart-waits"></div>
            </div>
            <div class="column">
                <h4 class="ui header">{{ t._('ci_IvrFunnel')|e }}</h4>
                <div class="ci-chart" id="ci-chart-funnel"></div>
            </div>
        </div>
        <h4 class="ui header">{{ t._('ci_IvrKeys')|e }}</h4>
        <table class="ui very basic selectable sortable table ci-table" id="ci-ivr-table"></table>
    </div>

    <div class="ui bottom attached tab segment" data-tab="callers">
        <div class="ui five doubling statistics ci-kpis" id="ci-callers-kpis"></div>
        <h4 class="ui header">{{ t._('ci_NotReturned')|e }} <span class="ci-subheader" id="ci-not-returned-note"></span></h4>
        <table class="ui very basic selectable sortable table ci-table" id="ci-not-returned-table"></table>
        <h4 class="ui header">{{ t._('ci_TopCallers')|e }}</h4>
        <table class="ui very basic selectable sortable table ci-table" id="ci-top-callers-table"></table>
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

        <h4 class="ui header">{{ t._('ci_RebuildHeader')|e }}</h4>
        <p class="ci-note">{{ t._('ci_RebuildNote')|e }}</p>
        <button class="ui button" type="button" id="ci-rebuild"><i class="redo icon"></i> {{ t._('ci_Rebuild')|e }}</button>
        <div class="ci-hidden" id="ci-rebuild-message"></div>

        <h4 class="ui header">{{ t._('ci_BackupHeader')|e }}</h4>
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
