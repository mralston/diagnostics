import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { createClient } from './client.js';

const TERMINAL = ['completed', 'abandoned'];

/**
 * The headless core of the panel: loads the suite and its latest run, starts
 * runs, and keeps the run up to date over Echo when an instance is available,
 * falling back to polling. Build your own UI on this if the shipped panel
 * does not fit.
 *
 * @param {object} options
 * @param {string} options.suite            Suite key, e.g. "listing-check".
 * @param {string|number} options.subjectId The subject's key.
 * @param {string} [options.baseUrl]        API prefix, default "/diagnostics".
 * @param {object|null} [options.echo]      A Laravel Echo instance; defaults to window.Echo.
 * @param {number} [options.pollInterval]   Milliseconds between polls when Echo is unavailable.
 * @param {number} [options.refreshInterval] Milliseconds between safety refreshes while Echo is connected.
 * @param {boolean} [options.autoLoad]      Load the suite immediately (default true).
 */
export function useDiagnosticsRun(options) {
    const baseUrl = options.baseUrl ?? '/diagnostics';
    const pollInterval = options.pollInterval ?? 2000;
    const refreshInterval = options.refreshInterval ?? 15000;
    const echo = options.echo === undefined
        ? (typeof window !== 'undefined' ? window.Echo ?? null : null)
        : options.echo;

    const client = createClient(baseUrl);

    const suite = ref(null);
    const checks = ref([]);
    const run = ref(null);
    const gate = ref(null);
    const loading = ref(false);
    const starting = ref(false);
    const error = ref(null);
    const transport = ref('none'); // 'echo' | 'poll' | 'none'
    const fixing = ref(null); // id of the result whose fix is being applied
    const waitedSince = ref(null);

    const timers = reactive({ poll: null, refresh: null, wait: null });
    let channel = null;
    let channelRunId = null;

    const results = computed(() => (run.value?.results ?? []).slice().sort((a, b) => a.position - b.position));
    const isRunning = computed(() => !!run.value && !TERMINAL.includes(run.value.status));
    const isComplete = computed(() => !!run.value && run.value.status === 'completed');
    const total = computed(() => run.value?.total_checks ?? checks.value.length);
    const counts = computed(() => {
        const c = { passed: 0, warning: 0, failed: 0, skipped: 0, errored: 0 };
        for (const r of results.value) {
            if (r.outcome && c[r.outcome] !== undefined) c[r.outcome]++;
        }
        return c;
    });
    const completed = computed(() => results.value.filter((r) => r.status === 'completed').length);
    const progress = computed(() => (total.value ? completed.value / total.value : 0));
    const anyStarted = computed(() => results.value.some((r) => r.status !== 'pending'));
    const waitingForWorker = computed(() => {
        if (!run.value || run.value.status !== 'pending' || anyStarted.value) return false;
        if (run.value.waiting_for_worker) return true;
        return waitedSince.value !== null && Date.now() - waitedSince.value > 10000;
    });

    const categories = computed(() => {
        const order = [];
        const groups = new Map();
        const source = results.value.length ? results.value : checks.value;
        for (const item of source) {
            if (!groups.has(item.category)) {
                groups.set(item.category, []);
                order.push(item.category);
            }
            groups.get(item.category).push(item);
        }
        return order.map((name) => ({ name, items: groups.get(name) }));
    });

    async function load() {
        loading.value = true;
        error.value = null;
        try {
            const data = await client.suite(options.suite, options.subjectId);
            suite.value = data.suite;
            checks.value = data.checks;
            gate.value = data.gate;
            setRun(data.latest_run);
        } catch (e) {
            error.value = e;
        } finally {
            loading.value = false;
        }
    }

    async function start() {
        if (starting.value) return;
        starting.value = true;
        error.value = null;
        try {
            const data = await client.start(options.suite, options.subjectId);
            setRun(data);
        } catch (e) {
            error.value = e;
        } finally {
            starting.value = false;
        }
    }

    async function refresh() {
        if (!run.value) return;
        try {
            const data = await client.run(run.value.id);
            mergeRun(data);
        } catch (e) {
            // A transient refresh failure is not worth surfacing; the next tick retries.
        }
    }

    async function open(runId) {
        loading.value = true;
        try {
            setRun(await client.run(runId));
        } catch (e) {
            error.value = e;
        } finally {
            loading.value = false;
        }
    }

    /** What the fix for a result will ask. Resolves to { label, description, questions }. */
    function fixQuestions(result) {
        return client.fixQuestions(run.value.id, result.id);
    }

    /**
     * Applies the fix for a result. Resolves to { fix, result, run, gate } and takes the
     * returned run and gate as current. Rejects on invalid answers (422), with the errors
     * keyed by question name on error.errors.
     */
    async function applyFix(result, answers = {}) {
        if (fixing.value !== null) return null;
        fixing.value = result.id;
        try {
            const data = await client.fix(run.value.id, result.id, answers);
            setRun(data.run);
            gate.value = data.gate;
            return data;
        } catch (e) {
            const body = e.response?.data ?? e.data ?? null;
            e.status = e.status ?? e.response?.status;
            e.errors = body?.errors ?? null;
            e.serverMessage = body?.message ?? null;
            throw e;
        } finally {
            fixing.value = null;
        }
    }

    function setRun(data) {
        run.value = data;
        waitedSince.value = data && data.status === 'pending' ? Date.now() : null;
        syncTransport();
    }

    function mergeRun(data) {
        if (!run.value || run.value.id !== data.id) {
            setRun(data);
            return;
        }
        run.value = { ...run.value, ...data };
        syncTransport();
    }

    function applyResult(update) {
        if (!run.value) return;
        const list = run.value.results ?? [];
        const index = list.findIndex((r) => r.id === update.result_id);
        if (index === -1) return;
        const merged = { ...list[index] };
        if (update.status) merged.status = update.status;
        if (update.outcome !== undefined) merged.outcome = update.outcome;
        if (update.downgraded !== undefined) merged.downgraded = update.downgraded;
        if (update.summary !== undefined) merged.summary = update.summary;
        if (update.duration_ms !== undefined) merged.duration_ms = update.duration_ms;
        if (update.findings) merged.findings = update.findings;
        if (update.findings_count !== undefined) merged.findings_count = update.findings_count;
        if (update.started_at) merged.started_at = update.started_at;
        const results = list.slice();
        results[index] = merged;
        run.value = { ...run.value, results, status: run.value.status === 'pending' ? 'running' : run.value.status };
    }

    function syncTransport() {
        if (!isRunning.value) {
            stopPolling();
            stopRefresh();
            leaveChannel();
            transport.value = 'none';
            return;
        }
        if (echo && run.value) {
            if (channelRunId !== run.value.id) {
                leaveChannel();
                joinChannel(run.value.id);
            }
            return;
        }
        startPolling();
    }

    function joinChannel(runId) {
        try {
            channel = echo.private(`diagnostics.run.${runId}`);
            channelRunId = runId;
            transport.value = 'echo';
            channel
                .listen('.CheckStarted', (e) => applyResult({ ...e, status: 'running' }))
                .listen('.CheckCompleted', (e) => applyResult({ ...e, status: 'completed' }))
                .listen('.RunCompleted', () => refresh())
                .error(() => {
                    leaveChannel();
                    startPolling();
                });
            // Close the gap between the run starting and the subscription landing.
            refresh();
            startRefresh();
        } catch (e) {
            leaveChannel();
            startPolling();
        }
    }

    function leaveChannel() {
        if (channel && echo && channelRunId !== null) {
            try {
                echo.leave(`diagnostics.run.${channelRunId}`);
            } catch (e) {
                // Nothing to do; the channel is gone either way.
            }
        }
        channel = null;
        channelRunId = null;
        stopRefresh();
    }

    function startPolling() {
        transport.value = 'poll';
        if (timers.poll) return;
        timers.poll = setInterval(refresh, pollInterval);
    }

    function stopPolling() {
        if (timers.poll) {
            clearInterval(timers.poll);
            timers.poll = null;
        }
    }

    function startRefresh() {
        if (timers.refresh || refreshInterval <= 0) return;
        timers.refresh = setInterval(refresh, refreshInterval);
    }

    function stopRefresh() {
        if (timers.refresh) {
            clearInterval(timers.refresh);
            timers.refresh = null;
        }
    }

    // Re-evaluate "waiting for a worker" once a second while nothing has started.
    watch([isRunning, anyStarted], ([running, started]) => {
        if (running && !started) {
            if (!timers.wait) {
                timers.wait = setInterval(() => {
                    // Touch the ref so the computed re-runs against Date.now().
                    waitedSince.value = waitedSince.value ?? Date.now();
                    if (run.value) run.value = { ...run.value };
                }, 1000);
            }
        } else if (timers.wait) {
            clearInterval(timers.wait);
            timers.wait = null;
        }
    }, { immediate: true });

    onBeforeUnmount(() => {
        stopPolling();
        stopRefresh();
        leaveChannel();
        if (timers.wait) clearInterval(timers.wait);
    });

    if (options.autoLoad !== false) {
        load();
    }

    return {
        suite,
        checks,
        run,
        gate,
        results,
        categories,
        counts,
        completed,
        total,
        progress,
        isRunning,
        isComplete,
        waitingForWorker,
        loading,
        starting,
        error,
        transport,
        fixing,
        fixQuestions,
        applyFix,
        load,
        start,
        refresh,
        open,
    };
}
