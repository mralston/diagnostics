<script setup>
import { computed, ref, watch } from 'vue';
import CheckRow from './CheckRow.vue';
import FixDialog from './FixDialog.vue';
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
    // Show a clean pass as a single "All checks passed" line, with the detail behind a link.
    summarisePass: { type: Boolean, default: true },
    // SVG markup for an icon beside the heading, drawn in the accent colour. Omit for none,
    // or use the "icon" slot for anything other than an SVG string.
    icon: { type: String, default: null },
    // Show fix buttons. Null follows the server, which asks the suite's authorizeFix.
    canFix: { type: Boolean, default: null },
    // Start a fresh run after a fix succeeds, exactly as the Run again button would, so every
    // check sees the change.
    rerunAfterFix: { type: Boolean, default: true },
});

const emit = defineEmits(['loaded', 'started', 'completed', 'fixed']);

const DEFAULT_COPY = {
    run: 'Run :label',
    runAgain: 'Run again',
    checking: 'Checking… :done of :total',
    queued: 'Queued',
    running: 'running…',
    neverRun: ':total checks across :categories areas. Takes a few seconds.',
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
    staleFixed: 'A fix has changed the record since this run. Run again to check everything against it.',
    abandoned: 'This run stopped before it finished. Run again to get a complete result.',
    showPassed: 'Show passed',
    hidePassed: 'Hide passed',
    completedIn: 'Completed in :duration',
    startedAt: 'Started :when',
    loadError: 'The checks could not be loaded.',
    startError: 'The run could not be started.',
    passed: 'passed',
    warnings: 'warnings',
    failed: 'failed',
    skipped: 'skipped',
    errored: 'errored',
    allOk: 'all ok',
    ofFailed: ':count of :total failed',
    downgraded: 'This is a warning only. It will not count as a failure.',
    moreFindings: 'and :count more',
    allPassedInCategory: 'All :count passed',
    allChecksPassed: 'All checks passed.',
    moreInfo: 'More info',
    lessInfo: 'Hide details',
    fixIt: 'Fix it',
    applying: 'Fixing…',
    cancel: 'Cancel',
    choose: 'Choose…',
    fixedTrailer: 'fixed',
    fixed: 'Fixed: :title.',
    fixNotResolved: 'The fix for ":title" ran, but the check still reports a problem.',
    fixFailed: 'Could not fix ":title": :message',
    fixError: 'The fix for ":title" could not be applied.',
    dismiss: 'Dismiss',
};

// Placeholders are whole words, so :failed never matches the start of :failedWord.
const t = (key, replacements = {}) => {
    const text = props.copy[key] ?? DEFAULT_COPY[key] ?? key;
    return text.replace(/:([A-Za-z_]+)/g, (match, name) => (name in replacements ? String(replacements[name]) : match));
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

// Fixes: the dialog for the fix being asked about, and a note about the last attempt.
const allowFix = computed(() => props.canFix ?? diag.suite.value?.can_fix ?? false);
const dialog = ref(null); // { meta, item, errors, message }
const fixNote = ref(null); // { cls, text }
const fixBusy = computed(() => diag.fixing.value !== null);

async function onFix(item) {
    if (fixBusy.value || diag.isRunning.value) return;
    fixNote.value = null;
    let meta;
    try {
        meta = await diag.fixQuestions(item);
    } catch (e) {
        fixNote.value = { cls: 'bad', text: t('fixError', { title: item.title }) };
        return;
    }
    if (!meta.questions || meta.questions.length === 0) {
        await applyFix(item, {});
        return;
    }
    dialog.value = { meta, item, errors: {}, message: null };
}

async function applyFix(item, answers) {
    let data;
    try {
        data = await diag.applyFix(item, answers);
    } catch (e) {
        if (e.status === 422 && dialog.value) {
            dialog.value = { ...dialog.value, errors: e.errors ?? {}, message: null };
            return;
        }
        const text = e.serverMessage && e.status === 409
            ? t('fixFailed', { title: item.title, message: e.serverMessage })
            : t('fixError', { title: item.title });
        if (dialog.value) dialog.value = { ...dialog.value, message: text };
        else fixNote.value = { cls: 'bad', text };
        return;
    }
    if (!data) return;

    const { fix } = data;
    // A fix that stopped itself keeps the dialog open with its reason, so the answers can be changed.
    if (fix.status === 'failed' && dialog.value) {
        dialog.value = { ...dialog.value, errors: {}, message: fix.message };
        return;
    }
    dialog.value = null;

    const rerun = fix.status === 'succeeded' && props.rerunAfterFix && props.canRun;
    if (fix.status === 'succeeded') {
        fixNote.value = fix.resolved
            ? { cls: 'ok', text: t('fixed', { title: item.title }) }
            : { cls: 'warn', text: t('fixNotResolved', { title: item.title }) };
    } else if (fix.status === 'failed') {
        fixNote.value = { cls: 'bad', text: t('fixFailed', { title: item.title, message: fix.message }) };
    } else {
        fixNote.value = { cls: 'bad', text: t('fixError', { title: item.title }) };
    }

    emit('fixed', fix, data.result, data.gate);

    if (rerun) {
        await start(true);
    }
}
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
    const hiddenAll = visible.length === 0;
    return { name: group.name, items: visible, note: hiddenAll ? '' : note, noteCls, hiddenAll, total: items.length };
}));

const passedCount = computed(() => diag.counts.value.passed);

// A clean, current pass collapses to one line. Opening the detail lasts until the next run.
const detailOpenFor = ref(null);
const cleanPass = computed(() => mode.value === 'done' && run.value?.outcome === 'passed' && !stale.value);
const showSummary = computed(() => props.summarisePass && cleanPass.value && detailOpenFor.value !== run.value?.id);
function toggleDetail() {
    detailOpenFor.value = detailOpenFor.value === run.value?.id ? null : run.value?.id;
}

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

async function start(keepNote = false) {
    if (keepNote !== true) fixNote.value = null;
    await diag.start();
    if (run.value) emit('started', run.value);
}

// "completed" fires for a run this panel watched finish, not for one that was already
// finished when the page loaded. It carries the gate as the server now sees it, so a page
// can follow the suite's rules without repeating them.
let liveRunId = null;
let completedFor = null;
watch(() => [run.value?.id, run.value?.status], async () => {
    if (!run.value) return;
    if (run.value.status !== 'completed') {
        liveRunId = run.value.id;
        return;
    }
    if (liveRunId === run.value.id && completedFor !== run.value.id) {
        completedFor = run.value.id;
        const finished = run.value;
        await diag.load();
        emit('completed', finished, diag.gate.value);
    }
});

(async () => {
    if (props.runId) {
        await diag.load();
        await diag.open(props.runId);
    } else {
        await diag.load();
    }
    emit('loaded', { suite: diag.suite.value, run: run.value, gate: diag.gate.value });
    if (props.autoRun && props.canRun && (!diag.gate.value || !diag.gate.value.fresh) && !diag.isRunning.value) {
        start();
    }
})();

defineExpose({ start, fix: onFix, refresh: diag.refresh, reload: diag.load, state: diag });
</script>

<template>
    <div class="dx" role="region" :aria-label="label" :aria-busy="diag.isRunning.value ? 'true' : 'false'">
        <div class="dx-head">
            <div v-if="icon || $slots.icon" class="dx-head__icon" aria-hidden="true">
                <slot name="icon"><span v-html="icon"></span></slot>
            </div>
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
        </div>

        <div v-if="diag.isRunning.value" class="dx-progress" role="progressbar" :aria-valuenow="diag.completed.value" aria-valuemin="0" :aria-valuemax="diag.total.value">
            <i :style="{ width: Math.round(diag.progress.value * 100) + '%' }"></i>
        </div>

        <div v-if="fixNote" class="dx-notice dx-notice--fix" :class="fixNote.cls" role="status">
            <span>{{ fixNote.text }}</span>
            <button type="button" class="dx-toggle" @click="fixNote = null">{{ t('dismiss') }}</button>
        </div>
        <div v-if="diag.error.value" class="dx-notice dx-notice--error" role="alert">
            {{ run ? t('startError') : t('loadError') }}
        </div>
        <div v-else-if="mode === 'waiting'" class="dx-notice" role="status">{{ t('waiting') }}</div>
        <div v-else-if="mode === 'abandoned'" class="dx-notice" role="status">{{ t('abandoned') }}</div>
        <div v-else-if="stale && !(fixNote && diag.isRunning.value)" class="dx-notice" role="status">{{ run?.fixed_at ? t('staleFixed') : t('stale') }}</div>

        <template v-if="mode === 'never'">
            <slot name="empty" :checks="diag.checks.value" />
        </template>
        <div v-else-if="showSummary" class="dx-pass">
            <svg class="dx-pass__tick" viewBox="0 0 48 48" aria-hidden="true">
                <circle cx="24" cy="24" r="22" fill="currentColor" />
                <path d="M14 24.5l7 7 13-14" fill="none" stroke="#fff" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <div class="dx-pass__text">
                <p class="dx-pass__title" role="status">{{ t('allChecksPassed') }}</p>
                <button type="button" class="dx-toggle dx-pass__more" @click="toggleDetail">{{ t('moreInfo') }}</button>
            </div>
        </div>
        <div v-else class="dx-body">
            <div v-if="summarisePass && cleanPass" class="dx-body__less">
                <button type="button" class="dx-toggle" @click="toggleDetail">{{ t('lessInfo') }}</button>
            </div>
            <template v-for="group in groups" :key="group.name">
                <div class="dx-cat">
                    <span>{{ group.name }}</span>
                    <small :class="group.noteCls">{{ group.note }}</small>
                </div>
                <div v-if="group.hiddenAll" class="dx-cat__passed">{{ t('allPassedInCategory', { count: group.total }) }}</div>
                <CheckRow
                    v-for="item in group.items"
                    :key="item.id"
                    :item="item"
                    :copy="{ running: t('running'), downgraded: t('downgraded'), moreFindings: t('moreFindings'), fixIt: t('fixIt'), applying: t('applying'), fixedTrailer: t('fixedTrailer') }"
                    :finding-link="findingLink"
                    :can-fix="allowFix && mode === 'done'"
                    :fix-disabled="fixBusy || dialog !== null"
                    :fixing="diag.fixing.value === item.id"
                    @fix="onFix"
                >
                    <template #extra="{ item: row }"><slot name="result-extra" :result="row" /></template>
                </CheckRow>
            </template>
        </div>

        <FixDialog
            v-if="dialog"
            :key="dialog.item.id"
            :meta="dialog.meta"
            :errors="dialog.errors"
            :message="dialog.message"
            :busy="fixBusy"
            :copy="{ cancel: t('cancel'), applying: t('applying'), choose: t('choose') }"
            @submit="(answers) => applyFix(dialog.item, answers)"
            @cancel="dialog = null"
        />

        <div v-if="mode !== 'never' || $slots.footer" class="dx-foot">
            <slot name="footer" :run="run" :state="diag">
                <template v-if="mode !== 'never'">
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
                        <template v-if="mode === 'done' && passedCount > 0 && !showSummary">
                            ·
                            <button type="button" class="dx-toggle" @click="showPassed = !showPassed">{{ showPassed ? t('hidePassed') : t('showPassed') }}</button>
                        </template>
                    </span>
                </template>
            </slot>
        </div>
    </div>
</template>

<style>
/*
 * Plain divs throughout, and no min-height, so a host's styles for section,
 * header or footer elements cannot reach the panel.
 *
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
    --dx-err: #c4342d;

    font-family: var(--dx-font);
    color: var(--dx-fg);
    background: var(--dx-surface);
    border: 1px solid var(--dx-border);
    border-radius: var(--dx-radius);
    overflow: hidden;
    height: auto;
    min-height: 0;
    text-align: left;
    line-height: 1.45;
}
.dx *, .dx *::before, .dx *::after { box-sizing: border-box; }

.dx-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; padding: 14px 18px; border-bottom: 1px solid var(--dx-border); }
.dx-head:last-child { border-bottom: 0; }
.dx-head__text { flex: 1 1 240px; min-width: 0; }
.dx-head__icon { flex: none; width: 40px; height: 40px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color: var(--dx-accent); background: color-mix(in srgb, var(--dx-accent) 12%, var(--dx-surface)); }
.dx-head__icon svg { width: 22px; height: 22px; display: block; }
.dx-head__icon > span { display: contents; }
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
.dx-notice--fix { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
.dx-notice--fix.ok { color: var(--dx-pass); }
.dx-notice--fix.warn { color: var(--dx-warn); }
.dx-notice--fix.bad { color: var(--dx-fail); }

.dx-cat { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; padding: 12px 18px 4px; font-size: 11.5px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: var(--dx-muted); }
.dx-cat small { font-size: 12px; font-weight: 500; letter-spacing: 0; text-transform: none; }
.dx-cat small.bad { color: var(--dx-fail); }
.dx-cat small.warn { color: var(--dx-warn); }
.dx-cat__passed { padding: 2px 18px 8px 50px; font-size: 13px; color: var(--dx-muted); }

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
.dx-ic.errored { background: transparent; color: var(--dx-err); box-shadow: inset 0 0 0 1.5px var(--dx-err); }
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
.dx-find__fix { margin-top: 8px !important; }
.dx .dx-btn--small { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; padding: 5px 11px; }
.dx .dx-btn--quiet { color: var(--dx-fg); background: var(--dx-surface); border-color: var(--dx-border); }
.dx-find__error { color: var(--dx-err); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; word-break: break-word; }

.dx-body { padding-bottom: 6px; }
.dx-body__less { display: flex; justify-content: flex-end; padding: 8px 18px 0; }
.dx-pass { display: flex; align-items: center; justify-content: center; gap: 18px; padding: 28px 18px; }
.dx-pass__tick { width: 56px; height: 56px; flex: none; color: var(--dx-pass); }
.dx-pass__text { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
.dx .dx-pass__title { margin: 0; font-size: 28px; font-weight: 600; line-height: 1.15; color: var(--dx-pass); letter-spacing: -0.01em; }
.dx .dx-pass__more { font-size: 13px; }
.dx-foot { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px 16px; padding: 12px 18px; border-top: 1px solid var(--dx-border); font-size: 13px; color: var(--dx-muted); }
.dx-foot b { color: var(--dx-fg); font-weight: 600; }
.dx-foot__counts { display: inline-flex; flex-wrap: wrap; gap: 4px 14px; }
.dx-k::before { content: ""; display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; background: var(--c); }
.dx .dx-toggle { font: 500 12px var(--dx-font); color: var(--dx-muted); background: none; border: 0; padding: 0; margin: 0; cursor: pointer; text-decoration: underline; }

/* The fix dialog: a native <dialog>, so it sits in the top layer above any host chrome. */
.dx-dialog { width: min(480px, calc(100vw - 32px)); max-height: calc(100vh - 32px); padding: 0; border: 1px solid var(--dx-border); border-radius: var(--dx-radius); background: var(--dx-surface); color: var(--dx-fg); font-family: var(--dx-font); box-shadow: 0 18px 50px rgba(0, 0, 0, 0.25); }
.dx-dialog::backdrop { background: rgba(20, 26, 23, 0.45); }
.dx-dialog__form { display: flex; flex-direction: column; gap: 14px; padding: 18px 20px; margin: 0; }
.dx .dx-dialog__title { margin: 0; font-size: 17px; font-weight: 600; line-height: 1.3; color: var(--dx-fg); }
.dx .dx-dialog__check { margin: 2px 0 0; font-size: 13px; color: var(--dx-muted); }
.dx .dx-dialog__desc { margin: 0; font-size: 14px; }
.dx-dialog__fields { display: flex; flex-direction: column; gap: 12px; }
.dx-field { display: flex; flex-direction: column; gap: 4px; }
.dx .dx-field__label { display: block; margin: 0; padding: 0; font-size: 13px; font-weight: 600; color: var(--dx-fg); }
.dx-field__req { color: var(--dx-fail); }
.dx-field__control { display: flex; align-items: center; gap: 8px; }
.dx .dx-field input:not([type="checkbox"]):not([type="radio"]), .dx .dx-field select, .dx .dx-field textarea { flex: 1 1 auto; width: 100%; min-width: 0; height: auto; margin: 0; padding: 7px 10px; font: 14px/1.4 var(--dx-font); color: var(--dx-fg); background: var(--dx-surface); border: 1px solid var(--dx-border); border-radius: 6px; box-shadow: none; }
.dx .dx-field input:focus, .dx .dx-field select:focus, .dx .dx-field textarea:focus { outline: 2px solid var(--dx-accent); outline-offset: 1px; border-color: var(--dx-accent); }
.dx-field--error input, .dx-field--error select, .dx-field--error textarea { border-color: var(--dx-fail) !important; }
.dx-field__suffix { font-size: 13px; color: var(--dx-muted); white-space: nowrap; }
.dx .dx-field__set { margin: 0; padding: 0; border: 0; display: flex; flex-direction: column; gap: 4px; }
.dx .dx-field__set legend { margin-bottom: 2px; border: 0; width: auto; }
.dx .dx-field__check { display: inline-flex; align-items: center; gap: 8px; margin: 0; font-size: 14px; font-weight: 400; cursor: pointer; }
.dx .dx-field__check input { margin: 0; }
.dx .dx-field__help { margin: 0; font-size: 12.5px; color: var(--dx-muted); }
.dx .dx-field__error, .dx .dx-dialog__message { margin: 0; font-size: 12.5px; color: var(--dx-fail); }
.dx-dialog__actions { display: flex; justify-content: flex-end; gap: 8px; }

@media (max-width: 520px) {
    .dx .dx-row__main { grid-template-columns: 22px minmax(0, 1fr) 16px; }
    .dx-row__trailer { display: none; }
    .dx-find { margin-left: 18px; }
    .dx-pass { gap: 12px; padding: 20px 16px; }
    .dx-pass__tick { width: 40px; height: 40px; }
    .dx .dx-pass__title { font-size: 21px; }
}
@media (prefers-reduced-motion: reduce) {
    .dx-ic.running { animation: none; }
    .dx-progress i, .dx-row__chev { transition: none; }
}
</style>
