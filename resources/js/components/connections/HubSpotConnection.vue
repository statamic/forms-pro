<script setup lang="ts">
import { computed } from 'vue';
import { Alert, Description, Heading, PublishContainer, PublishFields, PublishFieldsProvider } from '@statamic/cms/ui';
import HubSpotSetupInstructions from './HubSpotSetupInstructions.vue';

defineEmits(['update:modelValue']);

const props = defineProps({
    form: Object,
    modelValue: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
    blueprint: Object,
    meta: Object,
    hubspotForms: { type: [Object, Array], default: () => ({}) },
    isConfigured: Boolean,
    apiError: String,
});

const hubspotForm = computed(() => props.hubspotForms?.[props.modelValue.form]);

const extraValues = computed<object>(() => ({
    consent_type: hubspotForm.value?.consent?.type ?? 'none',
}));

const missingConsentToProcess = computed<boolean>(
    () =>
        hubspotForm.value?.consent?.type === 'explicit_consent_to_process' &&
        !props.modelValue.consent_to_process_field,
);

const missingCommunications = computed<boolean>(() => {
    const consent = hubspotForm.value?.consent;

    if (!consent || ['none', 'legitimate_interest'].includes(consent.type)) return false;

    return Boolean(consent.checkboxes?.length) && !(props.modelValue.communications ?? []).length;
});

const missingSubscription = computed<boolean>(() => {
    const consent = hubspotForm.value?.consent;

    return (
        consent?.type === 'legitimate_interest' &&
        (consent.subscription_ids ?? []).length > 1 &&
        !props.modelValue.legitimate_interest_subscription
    );
});
</script>

<template>
    <div v-if="isConfigured">
        <Alert v-if="apiError" variant="error" icon="alert-warning-exclamation-mark" class="mb-6">
            <Heading :text="__('forms-pro::messages.hubspot_api_error')" />
            <Description :text="__(apiError)" class="mt-1" />
        </Alert>

        <Alert
            v-else-if="!Object.keys(hubspotForms).length"
            variant="warning"
            icon="alert-warning-exclamation-mark"
            class="mb-6"
        >
            <Description :text="__('forms-pro::messages.hubspot_no_forms')" />
        </Alert>

        <Alert v-if="missingConsentToProcess" variant="warning" icon="alert-warning-exclamation-mark" class="mb-6">
            <Description :text="__('forms-pro::messages.hubspot_consent_to_process_warning')" />
        </Alert>

        <Alert v-if="missingCommunications" variant="warning" icon="alert-warning-exclamation-mark" class="mb-6">
            <Description :text="__('forms-pro::messages.hubspot_communications_warning')" />
        </Alert>

        <Alert v-if="missingSubscription" variant="warning" icon="alert-warning-exclamation-mark" class="mb-6">
            <Description :text="__('forms-pro::messages.hubspot_subscription_warning')" />
        </Alert>

        <Alert v-if="hubspotForm?.captcha" variant="warning" icon="alert-warning-exclamation-mark" class="mb-6">
            <Description :text="__('forms-pro::messages.hubspot_captcha_warning')" />
        </Alert>

        <Alert
            v-if="hubspotForm && !hubspotForm.has_email"
            variant="warning"
            icon="alert-warning-exclamation-mark"
            class="mb-6"
        >
            <Description :text="__('forms-pro::messages.hubspot_no_email_warning')" />
        </Alert>

        <PublishContainer
            :errors
            :blueprint
            :model-value="modelValue"
            :meta
            :extra-values="extraValues"
            :track-dirty-state="false"
            @update:model-value="$emit('update:modelValue', $event)"
        >
            <PublishFieldsProvider :fields="blueprint.tabs[0].sections[0].fields">
                <PublishFields />
            </PublishFieldsProvider>
        </PublishContainer>
    </div>

    <HubSpotSetupInstructions v-else />
</template>
