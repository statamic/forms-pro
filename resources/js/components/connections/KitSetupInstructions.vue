<script setup>
import { Button, Description, Heading, Input } from '@statamic/cms/ui';
import ConnectionSetupStep from './ConnectionSetupStep.vue';

const developerSettingsUrl = 'https://app.kit.com/account_settings/developer_settings';
const envVariables = `FORMS_PRO_KIT_API_KEY=kit_your-key`;
</script>

<template>
    <div>
        <Heading size="lg" :text="__('forms-pro::messages.kit_setup_heading')" />
        <Description class="mt-1.5" :text="__('forms-pro::messages.kit_setup_intro')" />

        <ol class="mt-6 space-y-6">
            <li class="flex gap-4">
                <ConnectionSetupStep>1</ConnectionSetupStep>
                <div class="flex-1 space-y-3">
                    <p>{{ __('forms-pro::messages.kit_setup_key') }}</p>
                    <Button
                        size="sm"
                        icon="external-link"
                        :href="developerSettingsUrl"
                        target="_blank"
                        :text="__('Add an API Key')"
                    />
                </div>
            </li>
            <li class="flex gap-4">
                <ConnectionSetupStep>2</ConnectionSetupStep>
                <div class="flex-1 space-y-3">
                    <p v-html="__('forms-pro::messages.kit_setup_env', { file: '<code>.env</code>' })" />
                    <Input :model-value="envVariables" read-only copyable class="font-mono text-xs" />
                    <Description :text="__('forms-pro::messages.kit_setup_developer')" />
                </div>
            </li>
        </ol>
    </div>
</template>
