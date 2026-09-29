<script setup>
import { computed, ref, watch } from 'vue';
import CheckRow from './CheckRow.vue';
import { useDiagnosticsRun } from './useDiagnosticsRun.js';

const props = defineProps({
    suite: { type: String, required: true },
    subjectId: { type: [String, Number], required: true },
    // Overrides the label the suite was registered with.
    label: { type: String, default: null },
    baseUrl: { type: String, default: '/diagnostics' },
    // A Laravel Echo instance. Undefined means window.Echo; null forces polling.
    echo: { type: Object, default: undefined },
    pollInterval: { type: Number, default: 2000 },
    // Start a run as soon as the panel loads when there is no fresh one.
    autoRun: { type: Boolean, default: false },
    // Open a specific run instead of the latest.
    runId: { type: [String, Number], default: null },
    canRun: { type: Boolean, default: true },
    // Hide passed rows by default.
    hidePassed: { type: Boolean, default: false },
    // (finding, result) => ({ href, label }) | null
    findingLink: { type: Function, default: null },
    // Override any string. See DEFAULT_COPY.
    copy: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['loaded', 'started', 'completed']);

const DEFAULT_COPY = {
    run: 'Run :label',
    runAgain: 'Run again',
    checking: 'Checking… :done of :total',
    queued: 'Queued',
    running: 'running…',
    neverRun: ':total checks across :categories areas. Not run yet.',
    lastRun: 'last run :when',
    allPassed: 'all passed',
    problems: ':failed :failedWord, :warnings :warningsWord',
    problemWord: 'problem',
    problemsWord: 'problems',
    warningWord: 'warning',
    warningsWord: 'warnings',
    statusReady: 'Ready',
    statusAttention: 'Needs attention',
    statusWarnings: 'Passed with warnings',
    statusErrored: 'Could not finish',
    statusRunning: 'Running',
    statusQueued: 'Queued',
    statusStale: 'Out of date',
    waiting: 'Waiting for a background worker to pick this up. It will start on its own.',
    stale: 'The record has changed since this run. Run again to check the current version.',
    abandoned: 'This run stopped before it finished. Run again to get a complete result.',
    showPassed: 'Show passed',
    hidePassed: 'Hide passed',
    completedIn: 'Completed in :duration',
    startedAt: 'Started :when',
    takesSeconds: 'Takes a few seconds. You can carry on while it runs.',
    loadError: 'The checks could not be loaded.',
    startError: 'The run could not be started.',
    passed: 'passed',
    warnings: 'warnings',
    failed: 'failed',
    skipped: 'skipped',
    errored: 'errored',
    allOk: 'all ok',
    ofFailed: ':count of :total failed',
    downgraded: 'Advisory: this check cannot fail a run.',
    moreFindings: 'and :count more',
};

const t = (key, replacements = {}) => {
    let text = props.copy[key] ?? DEFAULT_COPY[key] ?? key;
    for (const [name, value] of Object.entries(replacements)) {
        text = text.split(`:${name}`).join(String(value));
    }
    return text;
};

const diag = useDiagnosticsRun({
    suite: props.suite,
    subjectId: props.subjectId,
    baseUrl: props.baseUrl,
    echo: props.echo,
    pollInterval: props.pollInterval,
    autoLoad: false,
});

const showPassed = ref(!props.hidePassed);
const label = computed(() => props.label ?? diag.suite.value?.label ?? '');
const run = diag.run;
const stale = computed(() => diag.isComplete.value && diag.gate.value && diag.gate.value.run_id === run.value?.id && !diag.gate.value.fresh);

const mode = computed(() => {
    if (!run.value) return 'never';
    if (run.value.status === 'abandoned') return 'abandoned';
    if (diag.waitingForWorker.value) return 'waiting';
    if (diag.isRunning.value) return run.value.status === 'pending' && diag.completed.value === 0 ? 'queued' : 'running';
    return 'done';
});

const statusPill = computed(() => {
    if (mode.value === 'running') return { cls: 'run', text: t('statusRunning') };
    if (mode.value === 'queued' || mode.value === 'waiting') return { cls: 'run', text: t('statusQueued') };
    if (mode.value === 'abandoned') return { cls: 'err', text: t('statusErrored') };
    if (mode.value !== 'done') return null;
    if (stale.value) return { cls: 'stale', text: t('statusStale') };
    switch (run.value.outcome) {
        case 'passed': return { cls: 'ok', text: t('statusReady') };
        case 'passed_with_warnings': return { cls: 'warn', text: t('statusWarnings') };
        case 'failed': return { cls: 'bad', text: t('statusAttention') };
        default: return { cls: 'err', text: t('statusErrored') };
    }
});

const subline = computed(() => {
    const total = diag.total.value;
    const counts = diag.counts.value;
    if (mode.value === 'never') {
        const categories = new Set(diag.checks.value.map((c) => c.category)).size;
        return t('neverRun', { total, categories });
    }
    if (mode.value === 'running' || mode.value === 'queued' || mode.value === 'waiting') {
        return t('checking', { done: diag.completed.value, total });
    }
    const when = run.value.finished_at ? t('lastRun', { when: formatWhen(run.value.finished_at) }) : '';
    const problems = counts.failed + counts.errored;
    const summary = problems === 0 && counts.warning === 0
        ? t('allPassed')
        : t('problems', {
            failed: problems,
            failedWord: problems === 1 ? t('problemWord') : t('problemsWord'),
            warnings: counts.warning,
            warningsWord: counts.warning === 1 ? t('warningWord') : t('warningsWord'),
        });
    return [`${total} checks`, summary, when].filter(Boolean).join(' · ');
});

const groups = computed(() => diag.categories.value.map((group) => {
    const items = group.items.map((item) => (item.status ? item : { ...item, status: 'pending', id: `check-${item.position}` }));
    const failed = items.filter((i) => i.outcome === 'failed' || i.outcome === 'errored').length;
    const warnings = items.filter((i) => i.outcome === 'warning').length;
    const done = items.filter((i) => i.status === 'completed').length;
    let note = '';
    let noteCls = '';
    if (mode.value === 'done' || mode.value === 'abandoned') {
        if (failed) { note = t('ofFailed', { count: failed, total: items.length }); noteCls = 'bad'; }
        else if (warnings) { note = `${warnings} ${warnings === 1 ? t('warningWord') : t('warningsWord')}`; noteCls = 'warn'; }
        else note = t('allOk');
    } else if (mode.value !== 'never') {
        note = `${done} of ${items.length}`;
    }
    const visible = showPassed.value || mode.value !== 'done'
        ? items
        : items.filter((i) => i.outcome !== 'passed');
    return { name: group.name, items: visible, note, noteCls, hiddenAll: visible.length === 0 };
}));

const passedCount = computed(() => diag.counts.value.passed);

function formatWhen(iso) {
    const date = new Date(iso);
    const now = new Date();
    const time = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    if (date.toDateString() === now.toDateString()) return `${time} today`;
    return `${date.toLocaleDateString()} ${time}`;
}

function formatDuration(ms) {
    if (ms === null || ms === undefined) return '';
    return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`;
}

async function start() {
    await diag.start();
    if (run.value) emit('started', run.value);
}

let completedFor = null;
watch(() => [run.value?.id, run.value?.status], () => {
    if (run.value && run.value.status === 'completed' && completedFor !== run.value.id) {
        const wasWatching = completedFor !== null || diag.transport.value !== 'none';
        completedFor = run.value.id;
        if (wasWatching) {
            emit('completed', run.value);
            diag.load();
        }
    }
});

(async () => {
    if (props.runId) {
        await diag.load();
        await diag.open(props.runId);
    } else {
        await diag.load();
    }
    completedFor = run.value?.status === 'completed' ? run.value.id : null;
    emit('loaded', { suite: diag.suite.value, run: run.value, gate: diag.gate.value });
    if (props.autoRun && props.canRun && (!diag.gate.value || !diag.gate.value.fresh) && !diag.isRunning.value) {
        start();
    }
})();

defineExpose({ start, refresh: diag.refresh, reload: diag.load, state: diag });
</script>

<template>
    <section class="dx" :aria-busy="diag.isRunning.value ? 'true' : 'false'">
        <header class="dx-head">
            <div class="dx-head__text">
                <h2 class="dx-title">{{ label }}</h2>
                <p class="dx-sub">{{ subline }}</p>
            </div>
            <span v-if="statusPill" class="dx-status" :class="statusPill.cls">{{ statusPill.text }}</span>
            <slot name="header-actions" :run="run" :state="diag" />
            <button
                v-if="canRun"
                type="button"
                class="dx-btn"
                :disabled="diag.isRunning.value || diag.starting.value || diag.loading.value"
                @click="start"
            >{{ run ? t('runAgain') : t('run', { label }) }}</button>
        </header>

        <div v-if="diag.isRunning.value" class="dx-progress" role="progressbar" :aria-valuenow="diag.completed.value" aria-valuemin="0" :aria-valuemax="diag.total.value">
            <i :style="{ width: Math.round(diag.progress.value * 100) + '%' }"></i>
        </div>

        <div v-if="diag.error.value" class="dx-notice dx-notice--error" role="alert">
            {{ run ? t('startError') : t('loadError') }}
        </div>
        <div v-else-if="mode === 'waiting'" class="dx-notice" role="status">{{ t('waiting') }}</div>
        <div v-else-if="mode === 'abandoned'" class="dx-notice" role="status">{{ t('abandoned') }}</div>
        <div v-else-if="stale" class="dx-notice" role="status">{{ t('stale') }}</div>

        <template v-if="mode === 'never'">
            <slot name="empty" :checks="diag.checks.value" />
        </template>
        <div v-else class="dx-body">
            <template v-for="group in groups" :key="group.name">
                <div class="dx-cat">
                    <span>{{ group.name }}</span>
                    <small :class="group.noteCls">{{ group.note }}</small>
                </div>
                <CheckRow
                    v-for="item in group.items"
                    :key="item.id"
                    :item="item"
                    :copy="{ running: t('running'), downgraded: t('downgraded'), moreFindings: t('moreFindings') }"
                    :finding-link="findingLink"
                >
                    <template #extra="{ item: row }"><slot name="result-extra" :result="row" /></template>
                </CheckRow>
            </template>
        </div>

        <footer class="dx-foot">
            <slot name="footer" :run="run" :state="diag">
                <template v-if="mode === 'never'">
                    <span>{{ t('takesSeconds') }}</span>
                </template>
                <template v-else>
                    <span class="dx-foot__counts">
                        <span class="dx-k" style="--c: var(--dx-pass)"><b>{{ diag.counts.value.passed }}</b> {{ t('passed') }}</span>
                        <span v-if="diag.counts.value.warning" class="dx-k" style="--c: var(--dx-warn)"><b>{{ diag.counts.value.warning }}</b> {{ t('warnings') }}</span>
                        <span v-if="diag.counts.value.failed" class="dx-k" style="--c: var(--dx-fail)"><b>{{ diag.counts.value.failed }}</b> {{ t('failed') }}</span>
                        <span v-if="diag.counts.value.skipped" class="dx-k" style="--c: var(--dx-skip)"><b>{{ diag.counts.value.skipped }}</b> {{ t('skipped') }}</span>
                        <span v-if="diag.counts.value.errored" class="dx-k" style="--c: var(--dx-err)"><b>{{ diag.counts.value.errored }}</b> {{ t('errored') }}</span>
                    </span>
                    <span>
                        <template v-if="mode === 'done' && run.duration_ms !== null">{{ t('completedIn', { duration: formatDuration(run.duration_ms) }) }}</template>
                        <template v-else-if="run && run.created_at">{{ t('startedAt', { when: formatWhen(run.created_at) }) }}</template>
                        <template v-if="mode === 'done' && passedCount > 0">
                            ·
                            <button type="button" class="dx-toggle" @click="showPassed = !showPassed">{{ showPassed ? t('hidePassed') : t('showPassed') }}</button>
                        </template>
                    </span>
                </template>
            </slot>
        </footer>
    </section>
</template>

<style>
/*
 * Not scoped: the class names are namespaced with dx- instead, so a host can
 * restyle any part with ordinary CSS. Every colour, the font and the radius
 * come from custom properties with defaults here; override them on .dx or on
 * any ancestor.
 */
.dx {
    --dx-font: inherit;
    --dx-radius: 8px;
    --dx-fg: #1b2420;
    --dx-muted: #6b7570;
    --dx-surface: #fff;
    --dx-soft: #f4f6f4;
    --dx-border: #dfe3e0;
    --dx-accent: #2f5d8a;
    --dx-accent-fg: #fff;
    --dx-pass: #1f8a5b;
    --dx-warn: #c27a0e;
    --dx-fail: #c4342d;
    --dx-skip: #8a949d;
    --dx-err: #6d3fb5;

    font-family: var(--dx-font);
    color: var(--dx-fg);
    background: var(--dx-surface);
    border: 1px solid var(--dx-border);
    border-radius: var(--dx-radius);
    overflow: hidden;
    text-align: left;
    line-height: 1.45;
}
.dx *, .dx *::before, .dx *::after { box-sizing: border-box; }

.dx-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; padding: 14px 18px; border-bottom: 1px solid var(--dx-border); }
.dx-head__text { flex: 1 1 240px; min-width: 0; }
.dx .dx-title { font-family: var(--dx-font); font-size: 17px; font-weight: 600; line-height: 1.3; margin: 0; padding: 0; border: 0; color: var(--dx-fg); letter-spacing: -0.005em; }
.dx .dx-sub { font-size: 13px; color: var(--dx-muted); margin: 2px 0 0; }

.dx-status { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 999px; background: var(--dx-soft); white-space: nowrap; }
.dx-status::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
.dx-status.ok { color: var(--dx-pass); }
.dx-status.warn { color: var(--dx-warn); }
.dx-status.bad { color: var(--dx-fail); }
.dx-status.err { color: var(--dx-err); }
.dx-status.run { color: var(--dx-accent); }
.dx-status.stale { color: var(--dx-muted); }

.dx .dx-btn { font: 500 13px/1.2 var(--dx-font); color: var(--dx-accent-fg); background: var(--dx-accent); border: 1px solid var(--dx-accent); border-radius: 6px; padding: 7px 14px; cursor: pointer; margin: 0; }
.dx .dx-btn:hover:not(:disabled) { filter: brightness(1.08); }
.dx .dx-btn:disabled { opacity: 0.5; cursor: default; }
.dx .dx-btn:focus-visible, .dx .dx-toggle:focus-visible, .dx .dx-row__main:focus-visible { outline: 2px solid var(--dx-accent); outline-offset: 2px; }

.dx-progress { height: 4px; background: var(--dx-soft); }
.dx-progress i { display: block; height: 100%; background: var(--dx-accent); transition: width 0.3s ease; }

.dx-notice { padding: 10px 18px; font-size: 13px; color: var(--dx-muted); background: var(--dx-soft); border-bottom: 1px solid var(--dx-border); }
.dx-notice--error { color: var(--dx-fail); }

.dx-cat { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; padding: 12px 18px 4px; font-size: 11.5px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: var(--dx-muted); }
.dx-cat small { font-size: 12px; font-weight: 500; letter-spacing: 0; text-transform: none; }
.dx-cat small.bad { color: var(--dx-fail); }
.dx-cat small.warn { color: var(--dx-warn); }

.dx-row + .dx-row { border-top: 1px solid var(--dx-soft); }
.dx .dx-row__main { display: grid; grid-template-columns: 22px minmax(0, 1fr) auto 16px; align-items: center; gap: 10px; width: 100%; padding: 7px 18px; background: none; border: 0; margin: 0; font: inherit; font-size: 14px; color: inherit; text-align: left; cursor: default; }
.dx .dx-row--clickable .dx-row__main { cursor: pointer; }
.dx .dx-row--clickable .dx-row__main:hover { background: var(--dx-soft); }
.dx .dx-row__main:disabled { opacity: 1; color: inherit; }
.dx-row.pending .dx-row__title, .dx-row.running .dx-row__title { color: var(--dx-muted); }
.dx-row__title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dx-row__trailer { font-size: 12px; color: var(--dx-muted); font-variant-numeric: tabular-nums; white-space: nowrap; }
.dx-row__chev { color: var(--dx-muted); display: inline-flex; justify-content: center; transition: transform 0.15s; }
.dx-row--open .dx-row__chev { transform: rotate(180deg); }

.dx-ic { width: 20px; height: 20px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color: #fff; flex: none; }
.dx-ic.passed { background: var(--dx-pass); }
.dx-ic.warning { background: var(--dx-warn); }
.dx-ic.failed { background: var(--dx-fail); }
.dx-ic.skipped { background: var(--dx-skip); }
.dx-ic.errored { background: var(--dx-err); }
.dx-ic.pending { border: 2px dotted var(--dx-border); }
.dx-ic.running { border: 2px solid var(--dx-border); border-top-color: var(--dx-accent); animation: dx-spin 0.9s linear infinite; }
@keyframes dx-spin { to { transform: rotate(360deg); } }

.dx-find { margin: 0 18px 8px 50px; padding: 8px 12px; background: var(--dx-soft); border-radius: 6px; font-size: 13px; }
.dx-find > div + div { margin-top: 4px; }
.dx-find__summary { font-weight: 500; }
.dx-find__item { display: flex; flex-wrap: wrap; gap: 4px 10px; }
.dx .dx-find__link { color: var(--dx-accent); text-decoration: none; font-weight: 500; }
.dx .dx-find__link:hover { text-decoration: underline; }
.dx-find__more, .dx-find__note { color: var(--dx-muted); }
.dx-find__error { color: var(--dx-err); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; word-break: break-word; }

.dx-body { padding-bottom: 6px; }
.dx-foot { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px 16px; padding: 12px 18px; border-top: 1px solid var(--dx-border); font-size: 13px; color: var(--dx-muted); }
.dx-foot b { color: var(--dx-fg); font-weight: 600; }
.dx-foot__counts { display: inline-flex; flex-wrap: wrap; gap: 4px 14px; }
.dx-k::before { content: ""; display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; background: var(--c); }
.dx .dx-toggle { font: 500 12px var(--dx-font); color: var(--dx-muted); background: none; border: 0; padding: 0; margin: 0; cursor: pointer; text-decoration: underline; }

@media (max-width: 520px) {
    .dx .dx-row__main { grid-template-columns: 22px minmax(0, 1fr) 16px; }
    .dx-row__trailer { display: none; }
    .dx-find { margin-left: 18px; }
}
@media (prefers-reduced-motion: reduce) {
    .dx-ic.running { animation: none; }
    .dx-progress i, .dx-row__chev { transition: none; }
}
</style>
