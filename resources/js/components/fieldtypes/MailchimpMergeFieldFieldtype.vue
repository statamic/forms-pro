<script setup>
import { Fieldtype } from '@statamic/cms';
import { Combobox, ErrorMessage, injectPublishContext, Icon } from '@statamic/cms/ui';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { isReadOnly, update, expose } = Fieldtype.use(emit, props);
defineExpose(expose);

const { values } = injectPublishContext();

const error = ref(null);
const loading = ref(false);
const mergeFields = ref([]);

const list = computed(() => values.value?.list);

const options = computed(() => {
    return mergeFields.value.map(({ tag, name }) => ({
        value: tag,
        label: name,
    }));
});

const placeholder = computed(() => {
    if (!list.value) return __('forms-pro::messages.mailchimp_merge_field_no_audience');
    if (loading.value) return __('Loading');

    return __('forms-pro::messages.mailchimp_merge_field_placeholder');
});

let latestFetch = 0;

const fetchMergeFields = () => {
    const fetch = ++latestFetch;

    error.value = null;
    mergeFields.value = [];

    if (!list.value) return;

    loading.value = true;

    axios.get(props.config.url, { params: { list: list.value } })
        .then(({ data }) => {
            if (fetch !== latestFetch) return;

            error.value = data.error;
            mergeFields.value = data.merge_fields;

            if (!data.error && props.value && !data.merge_fields.some((mergeField) => mergeField.tag === props.value)) {
                update(null);
            }
        })
        .catch(() => fetch === latestFetch && (error.value = __('Something went wrong')))
        .finally(() => fetch === latestFetch && (loading.value = false));
};

watch(list, fetchMergeFields, { immediate: true });
</script>

<template>
    <div class="space-y-2">
        <Combobox
            :id
            :model-value="value"
            :options
            :placeholder
            :label-html="true"
            :read-only="isReadOnly"
            :disabled="!list || loading"
            searchable
            @update:model-value="update"
        >
            <template #selected-option="{ option }">
                <div v-if="loading" class="flex items-center truncate gap-x-2">
                    <Icon name="loading" />
                    {{ __('Loading') }}
                </div>

                <div v-else class="flex items-center truncate">
                    {{ option.label }} (<code class="hidden-outside rounded-xs text-xs">{{ option.value }}</code>)
                </div>
            </template>
            <template #option="option">
                <div class="flex items-center truncate">
                    {{ option.label }} (<code class="hidden-outside rounded-xs text-xs">{{ option.value }}</code>)
                </div>
            </template>
        </Combobox>

        <ErrorMessage v-if="error" :text="error" class="text-red-500 dark:text-red-400" />
    </div>
</template>
