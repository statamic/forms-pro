<script setup>
import { Button, Description, Heading, Input } from '@statamic/cms/ui';

const stepNumber = 'mt-px flex size-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300';

const projectUrl = 'https://console.cloud.google.com/projectcreate';
const apiUrl = 'https://console.cloud.google.com/apis/library/sheets.googleapis.com';
const serviceAccountsUrl = 'https://console.cloud.google.com/iam-admin/serviceaccounts';
const encodeCommand = 'base64 -i service-account.json';
const envVariable = 'FORMS_PRO_GOOGLE_SHEETS_CREDENTIALS=your-encoded-key';
</script>

<template>
    <div class="[&_code]:rounded-sm [&_code]:bg-gray-100 [&_code]:px-1 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-xs dark:[&_code]:bg-gray-800">
        <Heading size="lg" :text="__('forms-pro::messages.google_sheets_setup_heading')" />
        <Description class="mt-1.5" :text="__('forms-pro::messages.google_sheets_setup_intro')" />

        <ol class="mt-6 space-y-6">
            <li class="flex gap-4">
                <span :class="stepNumber" aria-hidden="true">1</span>
                <div class="flex-1 space-y-3">
                    <p>{{ __('forms-pro::messages.google_sheets_setup_project') }}</p>
                    <Button size="sm" icon="external-link" :href="projectUrl" target="_blank" :text="__('Create a project')" />
                </div>
            </li>
            <li class="flex gap-4">
                <span :class="stepNumber" aria-hidden="true">2</span>
                <div class="flex-1 space-y-3">
                    <p>{{ __('forms-pro::messages.google_sheets_setup_api') }}</p>
                    <Button size="sm" icon="external-link" :href="apiUrl" target="_blank" :text="__('Enable the Sheets API')" />
                </div>
            </li>
            <li class="flex gap-4">
                <span :class="stepNumber" aria-hidden="true">3</span>
                <div class="flex-1 space-y-3">
                    <p>{{ __('forms-pro::messages.google_sheets_setup_service_account') }}</p>
                    <Button size="sm" icon="external-link" :href="serviceAccountsUrl" target="_blank" :text="__('Create a service account')" />
                </div>
            </li>
            <li class="flex gap-4">
                <span :class="stepNumber" aria-hidden="true">4</span>
                <div class="flex-1">
                    <p>{{ __('forms-pro::messages.google_sheets_setup_key') }}</p>
                </div>
            </li>
            <li class="flex gap-4">
                <span :class="stepNumber" aria-hidden="true">5</span>
                <div class="flex-1 space-y-3">
                    <p>{{ __('forms-pro::messages.google_sheets_setup_env') }}</p>
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-sm text-gray-400 select-none dark:text-gray-500" aria-hidden="true">$</span>
                        <Input :model-value="encodeCommand" read-only copyable class="flex-1 font-mono text-xs" />
                    </div>
                    <p v-html="__('forms-pro::messages.google_sheets_setup_env_paste', { file: '<code>.env</code>' })" />
                    <pre class="overflow-x-auto rounded-lg border border-gray-200 bg-gray-50 p-4 text-xs text-gray-800 dark:border-white/10 dark:bg-gray-950/40 dark:text-gray-300"><code>{{ envVariable }}</code></pre>
                    <Description :text="__('forms-pro::messages.google_sheets_setup_developer')" />
                </div>
            </li>
            <li class="flex gap-4">
                <span :class="stepNumber" aria-hidden="true">6</span>
                <div class="flex-1">
                    <p v-html="__('forms-pro::messages.google_sheets_setup_share', { suffix: '<code>.iam.gserviceaccount.com</code>' })" />
                </div>
            </li>
        </ol>
    </div>
</template>
