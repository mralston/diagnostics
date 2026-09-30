<script setup>
defineProps({
    // pending | running | passed | warning | failed | skipped | errored
    state: { type: String, required: true },
});
</script>

<template>
    <span class="dx-ic" :class="state" :aria-label="state" role="img">
        <svg v-if="state === 'passed'" viewBox="0 0 16 16" width="11" height="11" aria-hidden="true"><path d="M3 8.5l3 3 7-7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <svg v-else-if="state === 'failed'" viewBox="0 0 16 16" width="10" height="10" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
        <svg v-else-if="state === 'warning'" viewBox="0 0 16 16" width="10" height="10" aria-hidden="true"><path d="M8 3v6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/><circle cx="8" cy="12.5" r="1.4" fill="currentColor"/></svg>
        <svg v-else-if="state === 'skipped'" viewBox="0 0 16 16" width="10" height="10" aria-hidden="true"><path d="M3.5 8h9" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
        <svg v-else-if="state === 'errored'" viewBox="0 0 16 16" width="10" height="10" aria-hidden="true"><path d="M8 3v6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/><circle cx="8" cy="12.5" r="1.4" fill="currentColor"/></svg>
    </span>
</template>
