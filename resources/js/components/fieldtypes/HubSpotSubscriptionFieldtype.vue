<script setup>
import { Fieldtype } from '@statamic/cms';
import { Description, Select, injectPublishContext } from '@statamic/cms/ui';
import { computed, watch } from 'vue';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { isReadOnly, update, expose } = Fieldtype.use(emit, props);
defineExpose(expose);

const { values } = injectPublishContext();

const hubspotForm = computed(() => values.value?.form);
const consent = computed(() => props.config.forms?.[hubspotForm.value]?.consent);

const options = computed(() =>
    (consent.value?.subscription_ids ?? []).map((id) => ({
        value: id,
        label: props.config.subscriptions?.[id] ?? id,
    })),
);

watch(hubspotForm, () => {
    if (props.value && !options.value.some((option) => option.value === props.value)) {
        update(null);
    }
});
</script>

<template>
    <Description v-if="options.length === 1" :text="options[0].label" />

    <Select
        v-else
        :options
        :model-value="value"
        :read-only="isReadOnly"
        :placeholder="__('forms-pro::messages.hubspot_connection_subscription_placeholder')"
        @update:model-value="update($event)"
    />
</template>
