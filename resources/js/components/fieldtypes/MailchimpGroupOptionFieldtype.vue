<script setup>
import { Fieldtype } from '@statamic/cms';
import { Combobox, Description, injectPublishContext } from '@statamic/cms/ui';
import { computed, watch } from 'vue';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { isReadOnly, update, expose } = Fieldtype.use(emit, props);
defineExpose(expose);

const { values } = injectPublishContext();

const row = computed(() => values.value?.groups?.[props.fieldPathPrefix.split('.').pop()]);

const options = computed(() => props.meta?.options?.[row.value?.field] ?? []);

watch(options, () => {
    if (props.value && !options.value.some((option) => option.value === props.value)) {
        update(null);
    }
});
</script>

<template>
    <Description
        v-if="!row?.field"
        :text="__('forms-pro::messages.mailchimp_group_option_no_field')"
    />
    <Description
        v-else-if="!options.length"
        :text="__('forms-pro::messages.mailchimp_group_option_when_checked')"
    />

    <Combobox
        v-else
        :id
        :model-value="value"
        :options
        :placeholder="__('forms-pro::messages.mailchimp_group_option_placeholder')"
        :read-only="isReadOnly"
        searchable
        @update:model-value="update"
    />
</template>
