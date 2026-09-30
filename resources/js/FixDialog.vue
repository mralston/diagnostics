<script setup>
import { nextTick, onMounted, reactive, ref } from 'vue';

const props = defineProps({
    // { title, label, description, questions }
    meta: { type: Object, required: true },
    // Validation messages keyed by question name.
    errors: { type: Object, default: () => ({}) },
    busy: { type: Boolean, default: false },
    // A message about the whole attempt, such as a fix that stopped itself.
    message: { type: String, default: null },
    copy: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['submit', 'cancel']);

const dialog = ref(null);
const answers = reactive({});

for (const q of props.meta.questions ?? []) {
    if (q.type === 'checkbox') answers[q.name] = !!q.default;
    else answers[q.name] = q.default === null || q.default === undefined ? '' : String(q.default);
}

const id = (q) => `dx-fix-${props.meta.result_id}-${q.name}`;
const errorFor = (q) => {
    const e = props.errors?.[q.name];
    return Array.isArray(e) ? e[0] : e ?? null;
};

function submit() {
    if (!props.busy) emit('submit', { ...answers });
}

function cancel() {
    if (!props.busy) emit('cancel');
}

onMounted(async () => {
    await nextTick();
    dialog.value?.showModal?.();
});
</script>

<template>
    <dialog ref="dialog" class="dx-dialog" :aria-labelledby="`dx-fix-title-${meta.result_id}`" @cancel.prevent="cancel">
        <form class="dx-dialog__form" novalidate @submit.prevent="submit">
            <div class="dx-dialog__head">
                <h3 :id="`dx-fix-title-${meta.result_id}`" class="dx-dialog__title">{{ meta.label }}</h3>
                <p class="dx-dialog__check">{{ meta.title }}</p>
            </div>
            <p v-if="meta.description" class="dx-dialog__desc">{{ meta.description }}</p>

            <div class="dx-dialog__fields">
                <div v-for="q in meta.questions" :key="q.name" class="dx-field" :class="{ 'dx-field--error': errorFor(q) }">
                    <template v-if="q.type === 'checkbox'">
                        <label class="dx-field__check">
                            <input :id="id(q)" v-model="answers[q.name]" type="checkbox" :disabled="busy">
                            <span>{{ q.label }}</span>
                        </label>
                    </template>
                    <template v-else-if="q.type === 'radio'">
                        <fieldset class="dx-field__set">
                            <legend class="dx-field__label">{{ q.label }}<span v-if="q.required" class="dx-field__req" aria-hidden="true"> *</span></legend>
                            <label v-for="o in q.options" :key="o.value" class="dx-field__check">
                                <input v-model="answers[q.name]" type="radio" :name="id(q)" :value="o.value" :disabled="busy">
                                <span>{{ o.label }}</span>
                            </label>
                        </fieldset>
                    </template>
                    <template v-else>
                        <label :for="id(q)" class="dx-field__label">{{ q.label }}<span v-if="q.required" class="dx-field__req" aria-hidden="true"> *</span></label>
                        <div class="dx-field__control">
                            <select v-if="q.type === 'select'" :id="id(q)" v-model="answers[q.name]" :disabled="busy" :required="q.required">
                                <option value="" :disabled="q.required">{{ q.placeholder ?? copy.choose ?? 'Choose…' }}</option>
                                <option v-for="o in q.options" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </select>
                            <textarea v-else-if="q.type === 'textarea'" :id="id(q)" v-model="answers[q.name]" rows="3" :placeholder="q.placeholder" :disabled="busy" :required="q.required"></textarea>
                            <input
                                v-else
                                :id="id(q)"
                                v-model="answers[q.name]"
                                :type="q.type"
                                :step="q.type === 'number' ? 'any' : undefined"
                                :placeholder="q.placeholder"
                                :disabled="busy"
                                :required="q.required"
                            >
                            <span v-if="q.suffix" class="dx-field__suffix">{{ q.suffix }}</span>
                        </div>
                    </template>
                    <p v-if="q.help" class="dx-field__help">{{ q.help }}</p>
                    <p v-if="errorFor(q)" class="dx-field__error" role="alert">{{ errorFor(q) }}</p>
                </div>
            </div>

            <p v-if="message" class="dx-dialog__message" role="alert">{{ message }}</p>

            <div class="dx-dialog__actions">
                <button type="button" class="dx-btn dx-btn--quiet" :disabled="busy" @click="cancel">{{ copy.cancel ?? 'Cancel' }}</button>
                <button type="submit" class="dx-btn" :disabled="busy">{{ busy ? (copy.applying ?? 'Fixing…') : meta.label }}</button>
            </div>
        </form>
    </dialog>
</template>
