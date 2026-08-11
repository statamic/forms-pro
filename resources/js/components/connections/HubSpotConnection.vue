<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, useTemplateRef } from 'vue';
import axios from 'axios';
import { keys } from '@statamic/cms/api';
import { Alert, Button, Description, Heading, Icon, PublishContainer, PublishFields, PublishFieldsProvider } from '@statamic/cms/ui';
import HubSpotSetupInstructions from './HubSpotSetupInstructions.vue';

const props = defineProps({
    form: Object,
    config: Object,
    action: String,
    blueprint: Object,
    values: Object,
    meta: Object,
    hubspotForms: { type: [Object, Array], default: () => ({}) },
    isConfigured: Boolean,
    apiError: String,
});

const container = useTemplateRef('container');

const errors = ref<object>({});
const saving = ref<boolean>(false);
const values = ref<object>({ ...props.values });
const saveBinding = ref<ReturnType<typeof keys.bindGlobal> | null>(null);

const hubspotForm = computed(() => props.hubspotForms?.[values.value.form]);

const extraValues = computed<object>(() => ({
    consent_type: hubspotForm.value?.consent?.type ?? 'none',
}));

const missingConsent = computed<boolean>(() => {
    const consent = hubspotForm.value?.consent;

    if (!consent || ['none', 'legitimate_interest'].includes(consent.type)) return false;

    if (consent.type === 'explicit_consent_to_process' && !values.value.consent_to_process_field) return true;

    return Boolean(consent.checkboxes?.length) && !(values.value.communications ?? []).length;
});

const missingSubscription = computed<boolean>(() => {
    const consent = hubspotForm.value?.consent;

    return (
        consent?.type === 'legitimate_interest' &&
        (consent.subscription_ids ?? []).length > 1 &&
        !values.value.legitimate_interest_subscription
    );
});

const save = (): void => {
    if (saving.value) return;

    errors.value = {};
    saving.value = true;

    axios
        .patch(props.action, container.value.visibleValues)
        .then(() => {
            container.value.clearDirtyState();
            Statamic.$toast.success(__('Saved'));
        })
        .catch((e) => {
            if (e.response?.status === 422) {
                errors.value = e.response.data.errors;
                Statamic.$toast.error(e.response.data.message);
            } else {
                Statamic.$toast.error(__('Something went wrong'));
            }
        })
        .finally(() => (saving.value = false));
};

onMounted(() => {
    saveBinding.value = keys.bindGlobal(['mod+s'], (e) => {
        e.preventDefault();
        save();
    });
});

onUnmounted(() => saveBinding.value?.destroy());
</script>

<template>
    <Teleport v-if="isConfigured" to="#form-layout-actions">
        <Button variant="primary" :aria-label="__('Save')" :disabled="saving" @click="save">
            <Icon name="save" class="sm:hidden" />
            <span class="hidden sm:inline">{{ __('Save') }}</span>
        </Button>
    </Teleport>

    <div v-if="isConfigured">
        <Alert v-if="apiError" variant="error" icon="alert-warning-exclamation-mark" class="mb-6">
            <Heading :text="__('forms-pro::messages.hubspot_connection_api_error')" />
            <Description :text="__(apiError)" class="mt-1" />
        </Alert>

        <Alert v-else-if="!Object.keys(hubspotForms).length" variant="warning" icon="alert-warning-exclamation-mark" class="mb-6">
            <Description :text="__('forms-pro::messages.hubspot_connection_no_forms')" />
        </Alert>

        <Alert v-if="missingConsent" variant="warning" icon="alert-warning-exclamation-mark" class="mb-6">
            <Description :text="__('forms-pro::messages.hubspot_connection_consent_warning')" />
        </Alert>

        <Alert v-if="missingSubscription" variant="warning" icon="alert-warning-exclamation-mark" class="mb-6">
            <Description :text="__('forms-pro::messages.hubspot_connection_subscription_warning')" />
        </Alert>

        <Alert v-if="hubspotForm?.captcha" variant="warning" icon="alert-warning-exclamation-mark" class="mb-6">
            <Description :text="__('forms-pro::messages.hubspot_connection_captcha_warning')" />
        </Alert>

        <Alert v-if="hubspotForm && !hubspotForm.has_email" variant="warning" icon="alert-warning-exclamation-mark" class="mb-6">
            <Description :text="__('forms-pro::messages.hubspot_connection_no_email_warning')" />
        </Alert>

        <PublishContainer
	        ref="container"
	        :blueprint
	        v-model="values"
	        :meta
	        :errors
	        :extra-values="extraValues"
        >
            <PublishFieldsProvider :fields="blueprint.tabs[0].sections[0].fields">
                <PublishFields />
            </PublishFieldsProvider>
        </PublishContainer>
    </div>

    <HubSpotSetupInstructions v-else />
</template>
