<script setup>
import { computed, ref, watch } from 'vue';
import OutcomeIcon from './OutcomeIcon.vue';

const props = defineProps({
    item: { type: Object, required: true },
    copy: { type: Object, default: () => ({}) },
    // Return {href, label} or null for a finding; lets the host link findings to its own pages.
    findingLink: { type: Function, default: null },
    // Whether this viewer may apply fixes, and whether a fix may be started now.
    canFix: { type: Boolean, default: false },
    fixDisabled: { type: Boolean, default: false },
    // This row's fix is being applied.
    fixing: { type: Boolean, default: false },
});

const emit = defineEmits(['fix']);

const offersFix = computed(() => props.canFix && props.item.fixable && props.item.status === 'completed');

const state = computed(() => {
    if (props.item.status === 'completed' && props.item.outcome) return props.item.outcome;
    return props.item.status ?? 'pending';
});

const findings = computed(() => props.item.findings ?? []);
// A skipped check's reason is already shown on the row, so it has nothing to expand.
const hasDetail = computed(() => {
    if (state.value === 'pending' || state.value === 'running') return false;
    if (findings.value.length > 0 || props.item.error) return true;
    return !!props.item.summary && state.value !== 'skipped' && state.value !== 'passed';
});
const openByDefault = computed(() => ['failed', 'warning', 'errored'].includes(state.value));
const open = ref(openByDefault.value);
watch(openByDefault, (value) => { open.value = value; });

const trailer = computed(() => {
    if (state.value === 'running') return props.copy.running ?? 'running…';
    if (state.value === 'pending') return '';
    if (state.value === 'skipped' && props.item.summary) return props.item.summary;
    if (props.item.fixed_at) return props.copy.fixedTrailer ?? 'fixed';
    if (props.item.duration_ms === null || props.item.duration_ms === undefined) return '';
    return props.item.duration_ms < 1000 ? `${props.item.duration_ms} ms` : `${(props.item.duration_ms / 1000).toFixed(1)} s`;
});

const hidden = computed(() => (props.item.findings_count ?? findings.value.length) - findings.value.length);

function toggle() {
    if (hasDetail.value) open.value = !open.value;
}
</script>

<template>
    <div class="dx-row" :class="[state, { 'dx-row--open': open, 'dx-row--clickable': hasDetail }]">
        <button type="button" class="dx-row__main" :aria-expanded="hasDetail ? open : undefined" :disabled="!hasDetail" @click="toggle">
            <OutcomeIcon :state="state" />
            <span class="dx-row__title">{{ item.title }}</span>
            <span class="dx-row__trailer">{{ trailer }}</span>
            <span class="dx-row__chev" aria-hidden="true">
                <svg v-if="hasDetail" viewBox="0 0 16 16" width="12" height="12"><path d="M5 6l3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
        </button>
        <div v-if="open && hasDetail" class="dx-find">
            <div v-if="item.summary && state !== 'skipped'" class="dx-find__summary">{{ item.summary }}</div>
            <div v-for="(finding, i) in findings" :key="i" class="dx-find__item">
                <span>{{ finding.message }}</span>
                <a v-if="findingLink && findingLink(finding, item)" :href="findingLink(finding, item).href" class="dx-find__link">{{ findingLink(finding, item).label }} ›</a>
            </div>
            <div v-if="hidden > 0" class="dx-find__more">{{ (copy.moreFindings ?? 'and :count more').replace(':count', hidden) }}</div>
            <div v-if="item.error" class="dx-find__error">{{ item.error }}</div>
            <div v-if="item.downgraded" class="dx-find__note">{{ copy.downgraded ?? 'This is a warning only. It will not count as a failure.' }}</div>
            <div v-if="offersFix" class="dx-find__fix">
                <button type="button" class="dx-btn dx-btn--small" :disabled="fixDisabled || fixing" @click="emit('fix', item)">
                    <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true"><path d="M10.6 2.2a3.4 3.4 0 0 0-4.3 4.3L2.4 10.4a1.4 1.4 0 0 0 2 2l3.9-3.9a3.4 3.4 0 0 0 4.3-4.3l-2 2-1.6-.4-.4-1.6 2-2z" fill="currentColor"/></svg><span>{{ fixing ? (copy.applying ?? 'Fixing…') : (item.fix_label ?? copy.fixIt ?? 'Fix it') }}</span>
                </button>
            </div>
            <slot name="extra" :item="item" />
        </div>
    </div>
</template>
