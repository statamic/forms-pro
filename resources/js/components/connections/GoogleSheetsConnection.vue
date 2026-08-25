<script setup lang="ts">
import { Alert, Description, Heading, Input, Label, PublishContainer, PublishFields, PublishFieldsProvider } from '@statamic/cms/ui';
import GoogleSheetsSetupInstructions from './GoogleSheetsSetupInstructions.vue';

defineEmits(['update:modelValue']);

defineProps({
    form: Object,
    modelValue: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
    blueprint: Object,
    meta: { type: Object, default: () => ({}) },
    isConfigured: Boolean,
    serviceAccount: String,
    apiError: String,
});
</script>

<template>
    <div v-if="isConfigured">
        <Alert v-if="apiError" variant="error" icon="alert-warning-exclamation-mark" class="mb-6">
            <Heading :text="__('forms-pro::messages.google_sheets_api_error')" />
            <Description :text="__(apiError)" class="mt-1" />
        </Alert>

        <div v-if="serviceAccount" class="mb-6">
            <Label :text="__('Service Account')" />
            <Description :text="__('forms-pro::messages.google_sheets_service_account')" class="mt-1" />
            <Input :model-value="serviceAccount" read-only copyable class="mt-2 font-mono text-xs" />
        </div>

        <PublishContainer
            :errors
            :blueprint
            :model-value="modelValue"
            :meta
            :track-dirty-state="false"
            @update:model-value="$emit('update:modelValue', $event)"
        >
            <PublishFieldsProvider :fields="blueprint.tabs[0].sections[0].fields">
                <PublishFields />
            </PublishFieldsProvider>
        </PublishContainer>
    </div>

    <GoogleSheetsSetupInstructions v-else />
</template>
