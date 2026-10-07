<script setup lang="ts">
import { ConnectionList, ConnectionRules, ConnectionSummary } from '@statamic/cms';
import {
    Alert,
    Badge,
    Description,
    Heading,
    Icon,
    Input,
    Label,
    PublishContainer,
    PublishFields,
    PublishFieldsProvider,
} from '@statamic/cms/ui';
import GoogleSheetsSetupInstructions from './GoogleSheetsSetupInstructions.vue';

defineEmits(['update:modelValue']);

const props = defineProps({
    form: Object,
    modelValue: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    blueprint: Object,
    meta: { type: Object, default: () => ({}) },
    defaults: Object,
    isConfigured: Boolean,
    serviceAccount: String,
    apiError: String,
});

const tabName = (sheet: { id: string; tab?: number }): string => props.meta[sheet.id]?.tab?.options?.[sheet.tab];
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

        <Label :text="__('Spreadsheets')" class="mb-2" />

        <ConnectionList
            :model-value="modelValue"
            :errors
            :defaults
            :add-label="__('Add Spreadsheet')"
            :description="__('forms-pro::messages.google_sheets_instructions')"
            :delete-heading="__('Delete Spreadsheet')"
            :delete-description="__('forms-pro::messages.google_sheets_delete_confirmation')"
            @update:model-value="$emit('update:modelValue', $event)"
        >
            <template #header="{ item: sheet, collapsed }">
                <Badge size="lg" pill color="white" class="px-3 text-gray-950 gap-1">
                    <Icon name="forms-pro::google-sheets" class="size-3.5 me-1" />
                    {{ tabName(sheet) ?? __('New Spreadsheet') }}
                </Badge>
                <ConnectionSummary v-show="collapsed" :conditions="sheet.conditions" />
            </template>

            <template #default="{ item: sheet, errors }">
                <ConnectionRules
                    v-model:conditions="sheet.conditions"
                    :always-label="__('Always add a row')"
                    :if-label="__('Add a row if...')"
                >
                    <template #then>
                        <div
                            class="rounded-lg border border-gray-300 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
                        >
                            <PublishContainer
                                :errors
                                :blueprint
                                :model-value="sheet"
                                :meta="meta[sheet.id] ?? defaults.meta"
                                :name="`google-sheets-connection-${sheet.id}`"
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

    <GoogleSheetsSetupInstructions v-else />
</template>
