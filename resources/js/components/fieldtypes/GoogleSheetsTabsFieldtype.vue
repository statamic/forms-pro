<script setup>
import { Fieldtype } from '@statamic/cms';
import { Combobox, injectPublishContext, Icon, ErrorMessage } from '@statamic/cms/ui';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { isReadOnly, update, expose } = Fieldtype.use(emit, props);
defineExpose(expose);

const { values } = injectPublishContext();

const spreadsheetUrl = 'https://docs.google.com/spreadsheets/d/';

const error = ref(null);
const loading = ref(false);
const tabs = ref(props.config.options ?? {});

const spreadsheet = computed(() =>
    values.value?.spreadsheet?.startsWith(spreadsheetUrl) ? values.value.spreadsheet : null,
);

const options = computed(() => Object.entries(tabs.value).map(([id, name]) => ({ value: Number(id), label: name })));

const placeholder = computed(() => {
    if (!spreadsheet.value) return __('forms-pro::messages.google_sheets_tab_no_spreadsheet');
    if (loading.value) return __('Loading');

    return __('forms-pro::messages.google_sheets_tab_placeholder');
});

let latestFetch = 0;

const fetchTabs = () => {
    const fetch = ++latestFetch;

    error.value = null;
    tabs.value = {};

    if (!spreadsheet.value) {
        if (props.value !== null) update(null);

        return;
    }

    loading.value = true;

    axios
        .get(props.config.url, { params: { spreadsheet: spreadsheet.value } })
        .then(({ data }) => {
            if (fetch !== latestFetch) return;

            error.value = data.error;
            tabs.value = data.tabs;

            if (!data.error && props.value !== null && !Object.hasOwn(data.tabs, props.value)) {
                update(null);
            }
        })
        .catch(() => fetch === latestFetch && (error.value = __('Something went wrong')))
        .finally(() => fetch === latestFetch && (loading.value = false));
};

watch(spreadsheet, fetchTabs);
</script>

<template>
    <div class="space-y-2">
        <Combobox
            :id
            :model-value="value"
            :options
            :placeholder
            :read-only="isReadOnly"
            :disabled="!spreadsheet || loading"
            searchable
            @update:model-value="update"
        >
            <template #selected-option="{ option }">
                <div v-if="loading" class="flex items-center truncate gap-x-2">
                    <Icon name="loading" />
                    {{ __('Loading') }}
                </div>

                <div v-else class="truncate">{{ option.label }}</div>
            </template>
        </Combobox>

        <ErrorMessage v-if="error" :text="error" class="text-red-500 dark:text-red-400" />
    </div>
</template>
