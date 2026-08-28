<script setup>
import { Button, Description, Heading } from '@statamic/cms/ui';

const stepNumber =
    'mt-px flex size-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300';

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
                <span :class="stepNumber" aria-hidden="true">1</span>
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
                <span :class="stepNumber" aria-hidden="true">2</span>
                <div class="flex-1 space-y-3">
                    <p v-html="__('forms-pro::messages.hubspot_setup_env', { file: '<code>.env</code>' })" />
                    <pre
                        class="overflow-x-auto rounded-lg border border-gray-200 bg-gray-50 p-4 text-xs text-gray-800 dark:border-white/10 dark:bg-gray-950/40 dark:text-gray-300"
                    ><code>{{ envVariables }}</code></pre>
                    <Description :text="__('forms-pro::messages.hubspot_setup_developer')" />
                </div>
            </li>
            <li class="flex gap-4">
                <span :class="stepNumber" aria-hidden="true">3</span>
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
