<script setup>
import { Button, Description, Heading, Input } from '@statamic/cms/ui';
import ConnectionSetupStep from './ConnectionSetupStep.vue';

const projectUrl = 'https://console.cloud.google.com/projectcreate';
const apiUrl = 'https://console.cloud.google.com/apis/library/sheets.googleapis.com';
const serviceAccountsUrl = 'https://console.cloud.google.com/iam-admin/serviceaccounts';
const encodeCommand = 'base64 -i service-account.json';
const envVariable = 'FORMS_PRO_GOOGLE_SHEETS_CREDENTIALS=your-encoded-key';
</script>

<template>
    <div
        class="[&_code]:rounded-sm [&_code]:bg-gray-100 [&_code]:px-1 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-xs dark:[&_code]:bg-gray-800"
    >
        <Heading size="lg" :text="__('forms-pro::messages.google_sheets_setup_heading')" />
        <Description class="mt-1.5" :text="__('forms-pro::messages.google_sheets_setup_intro')" />

        <ol class="mt-6 space-y-6">
            <li class="flex gap-4">
                <ConnectionSetupStep>1</ConnectionSetupStep>
                <div class="flex-1 space-y-3">
                    <p>{{ __('forms-pro::messages.google_sheets_setup_project') }}</p>
                    <Button
                        size="sm"
                        icon="external-link"
                        :href="projectUrl"
                        target="_blank"
                        :text="__('Create a project')"
                    />
                </div>
            </li>
            <li class="flex gap-4">
                <ConnectionSetupStep>2</ConnectionSetupStep>
                <div class="flex-1 space-y-3">
                    <p>{{ __('forms-pro::messages.google_sheets_setup_api') }}</p>
                    <Button
                        size="sm"
                        icon="external-link"
                        :href="apiUrl"
                        target="_blank"
                        :text="__('Enable the Sheets API')"
                    />
                </div>
            </li>
            <li class="flex gap-4">
                <ConnectionSetupStep>3</ConnectionSetupStep>
                <div class="flex-1 space-y-3">
                    <p>{{ __('forms-pro::messages.google_sheets_setup_service_account') }}</p>
                    <Button
                        size="sm"
                        icon="external-link"
                        :href="serviceAccountsUrl"
                        target="_blank"
                        :text="__('Create a service account')"
                    />
                </div>
            </li>
            <li class="flex gap-4">
                <ConnectionSetupStep>4</ConnectionSetupStep>
                <div class="flex-1">
                    <p>{{ __('forms-pro::messages.google_sheets_setup_key') }}</p>
                </div>
            </li>
            <li class="flex gap-4">
                <ConnectionSetupStep>5</ConnectionSetupStep>
                <div class="flex-1 space-y-3">
                    <p>{{ __('forms-pro::messages.google_sheets_setup_env') }}</p>
                    <Input
                        :model-value="encodeCommand"
                        prepend="$"
                        prepend-variant="inline-subtle"
                        read-only
                        copyable
                        class="font-mono text-xs"
                    />
                    <p
                        v-html="__('forms-pro::messages.google_sheets_setup_env_paste', { file: '<code>.env</code>' })"
                    />
                    <Input :model-value="envVariable" read-only copyable class="font-mono text-xs" />
                    <Description :text="__('forms-pro::messages.google_sheets_setup_developer')" />
                </div>
            </li>
            <li class="flex gap-4">
                <ConnectionSetupStep>6</ConnectionSetupStep>
                <div class="flex-1">
                    <p
                        v-html="
                            __('forms-pro::messages.google_sheets_setup_share', {
                                suffix: '<code>.iam.gserviceaccount.com</code>',
                            })
                        "
                    />
                </div>
            </li>
        </ol>
    </div>
</template>
