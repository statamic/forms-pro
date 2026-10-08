<script setup lang="ts">
import { ConnectionList, ConnectionSummary } from '@statamic/cms';
import { Alert, Badge, Description, Heading, Icon, Label } from '@statamic/cms/ui';
import MailchimpSetupInstructions from './MailchimpSetupInstructions.vue';

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

const audienceName = (subscription: { list?: string }): string => {
    return props.defaults.meta.list.options?.find((audience) => audience.value === subscription.list)?.label;
};
</script>

<template>
    <div v-if="isConfigured">
        <Alert v-if="apiError" variant="error" icon="alert-warning-exclamation-mark" class="mb-6">
            <Heading :text="__('forms-pro::messages.mailchimp_api_error')" />
            <Description :text="__(apiError)" class="mt-1" />
        </Alert>

        <Label :text="__('Subscriptions')" class="mb-2" />

        <ConnectionList
            :model-value="modelValue"
            :errors
            :defaults
            :blueprint
            :meta
            name="mailchimp-connection"
            :add-label="__('Add Subscription')"
            :description="__('forms-pro::messages.mailchimp_instructions')"
            :delete-heading="__('Delete Mailchimp Subscription')"
            :delete-description="__('forms-pro::messages.mailchimp_delete_confirmation')"
            :always-label="__('Always subscribe')"
            :if-label="__('Subscribe if...')"
            @update:model-value="$emit('update:modelValue', $event)"
        >
            <template #header="{ item: subscription, collapsed }">
                <Badge size="lg" pill color="white" class="px-3 text-gray-950 gap-1">
                    <Icon name="forms-pro::mailchimp" class="size-3.5 me-1" />
                    {{ audienceName(subscription) ?? __('New Subscription') }}
                </Badge>
                <ConnectionSummary v-show="collapsed" :conditions="subscription.conditions" />
            </template>
        </ConnectionList>
    </div>

    <MailchimpSetupInstructions v-else />
</template>
