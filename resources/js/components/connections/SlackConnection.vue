<script setup lang="ts">
import { ConnectionRows, ConnectionRules, ConnectionRowSummary } from '@statamic/cms';
import {
    Alert,
    Badge,
    Description,
    Heading,
    Icon,
    Label,
    PublishContainer,
    PublishFields,
    PublishFieldsProvider,
} from '@statamic/cms/ui';
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

        <ConnectionRows
            :model-value="modelValue"
            :errors
            :defaults
            :add-label="__('Add Notification')"
            :description="__('forms-pro::messages.slack_instructions')"
            :delete-heading="__('Delete Slack Notification')"
            :delete-description="__('forms-pro::messages.slack_delete_confirmation')"
            @update:model-value="$emit('update:modelValue', $event)"
        >
            <template #header="{ item: notification, collapsed }">
                <Badge size="lg" pill color="white" class="px-3 text-gray-950 gap-1">
                    <Icon name="forms-pro::slack" class="size-3.5 me-1" />
                    {{ channelName(notification) ?? __('New Notification') }}
                </Badge>
                <ConnectionRowSummary
                    v-show="collapsed"
                    :conditions="notification.conditions"
                    :fallback="notification.heading"
                />
            </template>

            <template #default="{ item: notification, errors }">
                <ConnectionRules
                    v-model:conditions="notification.conditions"
                    :always-label="__('Always send')"
                    :if-label="__('Send if...')"
                >
                    <template #then>
                        <div
                            class="rounded-lg border border-gray-300 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
                        >
                            <PublishContainer
                                :errors
                                :blueprint
                                :model-value="notification"
                                :meta="meta[notification.id] ?? defaults.meta"
                                :name="`slack-connection-${notification.id}`"
                                :track-dirty-state="false"
                            >
                                <PublishFieldsProvider :fields="blueprint.tabs[0].sections[0].fields">
                                    <PublishFields />
                                </PublishFieldsProvider>
                            </PublishContainer>
                        </div>
                    </template>
                </ConnectionRules>
            </template>
        </ConnectionRows>
    </div>

    <SlackSetupInstructions v-else />
</template>
