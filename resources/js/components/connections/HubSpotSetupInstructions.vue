<script setup>
import { Button, Description, Heading, Input } from '@statamic/cms/ui';
import ConnectionSetupStep from './ConnectionSetupStep.vue';

const serviceKeysUrl = 'https://app.hubspot.com/l/developer/keys';
const trackingCodeUrl = 'https://knowledge.hubspot.com/reports/install-the-hubspot-tracking-code';
const envVariables = `FORMS_PRO_HUBSPOT_ACCESS_TOKEN=pat-eu1-your-token`;
</script>

<template>
    <div>
        <Heading size="lg" :text="__('forms-pro::messages.hubspot_setup_heading')" />
        <Description class="mt-1.5" :text="__('forms-pro::messages.hubspot_setup_intro')" />

        <ol class="mt-6 space-y-6">
            <li class="flex gap-4">
                <ConnectionSetupStep step="1" />
                <div class="flex-1 space-y-3">
                    <p
                        v-html="
                            __('forms-pro::messages.hubspot_setup_key', {
                                forms: '<code>forms</code>',
                                communications: '<code>communication_preferences.read</code>',
                            })
                        "
                    />
                    <Button
                        size="sm"
                        icon="external-link"
                        :href="serviceKeysUrl"
                        target="_blank"
                        :text="__('Create a Service Key')"
                    />
                </div>
            </li>
            <li class="flex gap-4">
                <ConnectionSetupStep step="2" />
                <div class="flex-1 space-y-3">
                    <p v-html="__('forms-pro::messages.hubspot_setup_env', { file: '<code>.env</code>' })" />
                    <Input :model-value="envVariables" read-only copyable class="font-mono text-xs" />
                    <Description :text="__('forms-pro::messages.hubspot_setup_developer')" />
                </div>
            </li>
            <li class="flex gap-4">
                <ConnectionSetupStep step="3" />
                <div class="flex-1 space-y-3">
                    <p
                        class="[&_a]:underline [&_a:hover]:text-gray-700 dark:[&_a:hover]:text-gray-300"
                        v-html="__('forms-pro::messages.hubspot_setup_tracking', { url: trackingCodeUrl })"
                    />
                    <Description :text="__('forms-pro::messages.hubspot_setup_tracking_forms')" />
                </div>
            </li>
        </ol>
    </div>
</template>
