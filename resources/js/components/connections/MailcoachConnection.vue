<script setup lang="ts">
import { Alert, Description, Heading, PublishContainer, PublishFields, PublishFieldsProvider } from '@statamic/cms/ui';
import MailcoachSetupInstructions from './MailcoachSetupInstructions.vue';

defineProps({
    form: Object,
    modelValue: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
    blueprint: Object,
    meta: Object,
    isConfigured: Boolean,
    apiError: String,
});
</script>

<template>
    <div v-if="isConfigured">
        <Alert v-if="apiError" variant="error" icon="alert-warning-exclamation-mark" class="mb-6">
            <Heading :text="__('forms-pro::messages.mailcoach_api_error')" />
            <Description :text="__(apiError)" class="mt-1" />
        </Alert>

        <PublishContainer :errors :blueprint :model-value="modelValue" :meta :track-dirty-state="false">
            <PublishFieldsProvider :fields="blueprint.tabs[0].sections[0].fields">
                <PublishFields />
            </PublishFieldsProvider>
        </PublishContainer>
    </div>

    <MailcoachSetupInstructions v-else />
</template>
