<script setup lang="ts">
import { computed } from 'vue';
import { ConnectionRows, ConnectionRules, ConditionsCollapsedSummary } from '@statamic/cms';
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
import TwilioSetupInstructions from './TwilioSetupInstructions.vue';

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

const hasSenders = computed<boolean>(() => (props.defaults.meta.from.options ?? []).length > 0);

const recipientLabel = (notification: { to?: string }): string => {
    return props.defaults.meta.to.options?.find((option) => option.value === notification.to)?.label ?? notification.to;
};
</script>

<template>
    <div v-if="isConfigured">
        <Alert v-if="apiError" variant="error" icon="alert-warning-exclamation-mark" class="mb-6">
            <Heading :text="__('forms-pro::messages.twilio_api_error')" />
            <Description :text="__(apiError)" class="mt-1" />
        </Alert>

        <Alert v-else-if="!hasSenders" variant="warning" icon="alert-warning-exclamation-mark" class="mb-6">
            <Description :text="__('forms-pro::messages.twilio_no_senders')" />
        </Alert>

        <Label v-if="modelValue.length" :text="__('Notifications')" class="mb-2" />

        <ConnectionRows
            :model-value="modelValue"
            :errors
            :defaults
            :add-label="__('Add Notification')"
            :empty-heading="__('No notifications yet')"
            :empty-description="__('forms-pro::messages.twilio_empty_description')"
            :delete-heading="__('Delete Twilio Notification')"
            :delete-description="__('forms-pro::messages.twilio_delete_confirmation')"
            @update:model-value="$emit('update:modelValue', $event)"
        >
            <template #header="{ item: notification, collapsed }">
                <Badge size="lg" pill color="white" class="px-3 text-gray-950 gap-1">
                    <Icon name="forms-pro::twilio" class="size-3.5 me-1" />
                    {{ recipientLabel(notification) ?? __('New Notification') }}
                </Badge>
                <ConditionsCollapsedSummary
                    v-show="collapsed"
                    :conditions="notification.conditions"
                    :fallback="notification.body"
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
                                :name="`twilio-connection-${notification.id}`"
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

    <TwilioSetupInstructions v-else />
</template>
