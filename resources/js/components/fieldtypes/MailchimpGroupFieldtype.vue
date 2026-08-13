<script setup>
import { Fieldtype } from '@statamic/cms';
import { Combobox, ErrorMessage, Icon, injectPublishContext } from '@statamic/cms/ui';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { isReadOnly, update, expose } = Fieldtype.use(emit, props);
defineExpose(expose);

const { values } = injectPublishContext();

const error = ref(null);
const loading = ref(false);
const groups = ref([]);

const list = computed(() => values.value?.list);

const taken = computed(() => {
    const index = props.fieldPathPrefix.split('.').pop();

    return (values.value?.groups ?? [])
        .filter((row, row_index) => String(row_index) !== index)
        .map((row) => row.group);
});

const options = computed(() => {
    return groups.value
        .filter(({ id }) => !taken.value.includes(id))
        .map(({ id, name, category }) => ({
            value: id,
            label: `${category} → ${name}`,
        }));
});

const placeholder = computed(() => {
    if (!list.value) return __('forms-pro::messages.mailchimp_group_no_audience');
    if (loading.value) return __('Loading');
    if (groups.value.length && !options.value.length) return __('forms-pro::messages.mailchimp_group_all_mapped');

    return __('forms-pro::messages.mailchimp_group_placeholder');
});

let latestFetch = 0;

const fetchGroups = () => {
    const fetch = ++latestFetch;

    error.value = null;
    groups.value = [];

    if (!list.value) return;

    loading.value = true;

    axios.get(props.config.url, { params: { list: list.value } })
        .then(({ data }) => {
            if (fetch !== latestFetch) return;

            error.value = data.error;
            groups.value = data.groups;

            if (!data.error && props.value && !data.groups.some((group) => group.id === props.value)) {
                update(null);
            }
        })
        .catch(() => fetch === latestFetch && (error.value = __('Something went wrong')))
        .finally(() => fetch === latestFetch && (loading.value = false));
};

watch(list, fetchGroups, { immediate: true });
</script>

<template>
    <div class="space-y-2">
        <Combobox
            :id
            :model-value="value"
            :options
            :placeholder
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

                <div v-else class="truncate">{{ option.label }}</div>
            </template>
        </Combobox>

        <ErrorMessage v-if="error" :text="error" class="text-red-500 dark:text-red-400" />
    </div>
</template>
