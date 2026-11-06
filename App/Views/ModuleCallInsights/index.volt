{# Dashboard shell. No CDR data is rendered here: charts load it as JSON (plan: Security). #}
<div class="ui placeholder segment" id="call-insights">
    <div class="ui icon header">
        <i class="chart bar outline icon"></i>
        {{ t._('ci_CollectingData') }}
    </div>
    <p>{{ t._('ci_CollectingDataHint') }}</p>
</div>
