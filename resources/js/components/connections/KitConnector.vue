<script setup lang="ts">
import { ConnectionList, ConnectionSummary } from '@statamic/cms';
import { Alert, Badge, Description, Heading, Icon, Label } from '@statamic/cms/ui';
import KitSetupInstructions from './KitSetupInstructions.vue';

defineEmits(['update:modelValue']);

const props = defineProps({
    form: Object,
    modelValue: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    blueprint: Object,
    meta: { type: Object, default: () => ({}) },
    defaults: Object,
    isConfigured: Boolean,
    apiError: String,
});

const kitFormName = (subscription: { form?: number }): string => {
    return props.defaults.meta.form.options?.find((form) => String(form.value) === String(subscription.form))?.label;
};
</script>

<template>
    <div v-if="isConfigured">
        <Alert v-if="apiError" variant="error" icon="alert-warning-exclamation-mark" class="mb-6">
            <Heading :text="__('forms-pro::messages.kit_api_error')" />
            <Description :text="__(apiError)" class="mt-1" />
        </Alert>

        <Label :text="__('Subscriptions')" class="mb-2" />

        <ConnectionList
            :model-value="modelValue"
            :errors
            :defaults
            :blueprint
            :meta
            name="kit-connection"
            :add-label="__('Add Subscription')"
            :description="__('forms-pro::messages.kit_instructions')"
            :delete-heading="__('Delete Kit Subscription')"
            :delete-description="__('forms-pro::messages.kit_delete_confirmation')"
            :always-label="__('Always subscribe')"
            :if-label="__('Subscribe if...')"
            @update:model-value="$emit('update:modelValue', $event)"
        >
            <template #header="{ item: subscription, collapsed }">
                <Badge size="lg" pill color="white" class="px-3 text-gray-950 gap-1">
                    <Icon name="forms-pro::kit" class="size-3.5 me-1" />
                    {{ kitFormName(subscription) ?? __('Subscription') }}
                </Badge>
                <ConnectionSummary v-show="collapsed" :conditions="subscription.conditions" />
            </template>
        </ConnectionList>
    </div>

    <KitSetupInstructions v-else />
</template>
