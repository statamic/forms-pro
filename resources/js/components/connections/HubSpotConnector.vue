<script setup lang="ts">
import { computed } from 'vue';
import { ConnectionFields, ConnectionList, ConnectionRules, ConnectionSummary } from '@statamic/cms';
import { Alert, Badge, Description, Heading, Icon, Label } from '@statamic/cms/ui';
import HubSpotSetupInstructions from './HubSpotSetupInstructions.vue';

type Submission = {
    id: string;
    form?: string;
    consent_to_process_field?: string;
    communications?: unknown[];
    legitimate_interest_subscription?: number;
};

defineEmits(['update:modelValue']);

const props = defineProps({
    form: Object,
    modelValue: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    blueprint: Object,
    meta: { type: Object, default: () => ({}) },
    defaults: Object,
    hubspotForms: { type: [Object, Array], default: () => ({}) },
    isConfigured: Boolean,
    apiError: String,
});

const hubspotForm = (submission: Submission) => props.hubspotForms?.[submission.form];

const extraValues = computed<Record<string, object>>(() =>
    Object.fromEntries(
        props.modelValue.map((submission: Submission) => [
            submission.id,
            { consent_type: hubspotForm(submission)?.consent?.type ?? 'none' },
        ]),
    ),
);

const warnings = (submission: Submission): string[] => {
    const hubspot = hubspotForm(submission);
    const consent = hubspot?.consent;

    return [
        consent?.type === 'explicit_consent_to_process' &&
            !submission.consent_to_process_field &&
            'forms-pro::messages.hubspot_consent_to_process_warning',
        consent &&
            !['none', 'legitimate_interest'].includes(consent.type) &&
            Boolean(consent.checkboxes?.length) &&
            !(submission.communications ?? []).length &&
            'forms-pro::messages.hubspot_communications_warning',
        consent?.type === 'legitimate_interest' &&
            (consent.subscription_ids ?? []).length > 1 &&
            !submission.legitimate_interest_subscription &&
            'forms-pro::messages.hubspot_subscription_warning',
        hubspot?.captcha && 'forms-pro::messages.hubspot_captcha_warning',
        hubspot && !hubspot.has_email && 'forms-pro::messages.hubspot_no_email_warning',
    ].filter(Boolean);
};
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

        <Label :text="__('Forms')" class="mb-2" />

        <ConnectionList
            :model-value="modelValue"
            :errors
            :defaults
            :blueprint
            :meta
            name="hubspot-connection"
            :add-label="__('Add HubSpot Form')"
            :description="__('forms-pro::messages.hubspot_instructions')"
            :delete-heading="__('Delete HubSpot Form')"
            :delete-description="__('forms-pro::messages.hubspot_delete_confirmation')"
            @update:model-value="$emit('update:modelValue', $event)"
        >
            <template #header="{ item: submission, collapsed }">
                <Badge size="lg" pill color="white" class="px-3 text-gray-950 gap-1">
                    <Icon name="forms-pro::hubspot" class="size-3.5 me-1" />
                    {{ hubspotForm(submission)?.name ?? __('New HubSpot Form') }}
                </Badge>
                <ConnectionSummary v-show="collapsed" :conditions="submission.conditions" />
            </template>

            <template #default="{ item: submission }">
                <ConnectionRules
                    v-model:conditions="submission.conditions"
                    :always-label="__('Always send')"
                    :if-label="__('Send if...')"
                >
                    <template #then>
                        <ConnectionFields :extra-values="extraValues[submission.id]">
                            <template #before>
                                <Alert
                                    v-for="warning in warnings(submission)"
                                    :key="warning"
                                    variant="warning"
                                    icon="alert-warning-exclamation-mark"
                                    class="mb-4"
                                >
                                    <Description :text="__(warning)" />
                                </Alert>
                            </template>
                        </ConnectionFields>
                    </template>
                </ConnectionRules>
            </template>
        </ConnectionList>
    </div>

    <HubSpotSetupInstructions v-else />
</template>
