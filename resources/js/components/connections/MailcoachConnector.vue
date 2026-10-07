<script setup lang="ts">
import { ConnectionList, ConnectionRules, ConnectionSummary } from '@statamic/cms';
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
import MailcoachSetupInstructions from './MailcoachSetupInstructions.vue';

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

const listName = (subscription: { list?: string }): string => {
    return props.defaults.meta.list.options?.find((list) => list.value === subscription.list)?.label;
};
</script>

<template>
    <div v-if="isConfigured">
        <Alert v-if="apiError" variant="error" icon="alert-warning-exclamation-mark" class="mb-6">
            <Heading :text="__('forms-pro::messages.mailcoach_api_error')" />
            <Description :text="__(apiError)" class="mt-1" />
        </Alert>

        <Label :text="__('Subscriptions')" class="mb-2" />

        <ConnectionList
            :model-value="modelValue"
            :errors
            :defaults
            :add-label="__('Add Subscription')"
            :description="__('forms-pro::messages.mailcoach_instructions')"
            :delete-heading="__('Delete Mailcoach Subscription')"
            :delete-description="__('forms-pro::messages.mailcoach_delete_confirmation')"
            @update:model-value="$emit('update:modelValue', $event)"
        >
            <template #header="{ item: subscription, collapsed }">
                <Badge size="lg" pill color="white" class="px-3 text-gray-950 gap-1">
                    <Icon name="forms-pro::mailcoach" class="size-3.5 me-1" />
                    {{ listName(subscription) ?? __('New Subscription') }}
                </Badge>
                <ConnectionSummary v-show="collapsed" :conditions="subscription.conditions" />
            </template>

            <template #default="{ item: subscription, errors }">
                <ConnectionRules
                    v-model:conditions="subscription.conditions"
                    :always-label="__('Always subscribe')"
                    :if-label="__('Subscribe if...')"
                >
                    <template #then>
                        <div
                            class="rounded-lg border border-gray-300 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
                        >
                            <PublishContainer
                                :errors
                                :blueprint
                                :model-value="subscription"
                                :meta="meta[subscription.id] ?? defaults.meta"
                                :name="`mailcoach-connection-${subscription.id}`"
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
        </ConnectionList>
    </div>

    <MailcoachSetupInstructions v-else />
</template>
