<script setup lang="ts">
import { onMounted, onUnmounted, ref, useTemplateRef } from 'vue';
import axios from 'axios';
import { keys } from '@statamic/cms/api';
import { Alert, Button, Description, Heading, Icon, Input, Label, PublishContainer, PublishFields, PublishFieldsProvider } from '@statamic/cms/ui';
import GoogleSheetsSetupInstructions from './GoogleSheetsSetupInstructions.vue';

const props = defineProps({
    form: Object,
    config: Object,
    action: String,
    blueprint: Object,
    values: Object,
    meta: Object,
    isConfigured: Boolean,
    serviceAccount: String,
    apiError: String,
});

const container = useTemplateRef('container');

const errors = ref<object>({});
const saving = ref<boolean>(false);
const values = ref<object>({ ...props.values });
const saveBinding = ref<ReturnType<typeof keys.bindGlobal> | null>(null);

const save = (): void => {
    if (saving.value) return;

    errors.value = {};
    saving.value = true;

    axios
        .patch(props.action, values.value)
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
            <Heading :text="__('forms-pro::messages.google_sheets_connection_api_error')" />
            <Description :text="__(apiError)" class="mt-1" />
        </Alert>

        <div v-if="serviceAccount" class="mb-6">
            <Label :text="__('Service Account')" />
            <Description :text="__('forms-pro::messages.google_sheets_connection_service_account')" class="mt-1" />
            <Input :model-value="serviceAccount" read-only copyable class="mt-2 font-mono text-xs" />
        </div>

        <PublishContainer ref="container" :blueprint v-model="values" :meta :errors>
            <PublishFieldsProvider :fields="blueprint.tabs[0].sections[0].fields">
                <PublishFields />
            </PublishFieldsProvider>
        </PublishContainer>
    </div>

    <GoogleSheetsSetupInstructions v-else />
</template>
