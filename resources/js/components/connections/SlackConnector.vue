<script setup lang="ts">
import { ConnectionList, ConnectionSummary } from '@statamic/cms';
import { Alert, Badge, Description, Heading, Icon, Label } from '@statamic/cms/ui';
import SlackSetupInstructions from './SlackSetupInstructions.vue';

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

const channelName = (notification: { channel?: string }): string => {
    return props.defaults.meta.channel.options.find((channel) => channel.value === notification.channel)?.label;
};
</script>

<template>
    <div v-if="isConfigured">
        <Alert v-if="apiError" variant="error" icon="alert-warning-exclamation-mark" class="mb-6">
            <Heading :text="__('forms-pro::messages.slack_api_error')" />
            <Description :text="__(apiError)" class="mt-1" />
        </Alert>

        <Label :text="__('Notifications')" class="mb-2" />

        <ConnectionList
            :model-value="modelValue"
            :errors
            :defaults
            :blueprint
            :meta
            name="slack-connection"
            :add-label="__('Add Notification')"
            :description="__('forms-pro::messages.slack_instructions')"
            :delete-heading="__('Delete Slack Notification')"
            :delete-description="__('forms-pro::messages.slack_delete_confirmation')"
            :always-label="__('Always send')"
            :if-label="__('Send if...')"
            @update:model-value="$emit('update:modelValue', $event)"
        >
            <template #header="{ item: notification, collapsed }">
                <Badge size="lg" pill color="white" class="px-3 text-gray-950 gap-1">
                    <Icon name="forms-pro::slack" class="size-3.5 me-1" />
                    {{ channelName(notification) ?? __('New Notification') }}
                </Badge>
                <ConnectionSummary
                    v-show="collapsed"
                    :conditions="notification.conditions"
                    :fallback="notification.heading"
                />
            </template>
        </ConnectionList>
    </div>

    <SlackSetupInstructions v-else />
</template>
