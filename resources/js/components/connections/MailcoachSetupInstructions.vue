<script setup>
import { Description, Heading, Textarea } from '@statamic/cms/ui';
import ConnectionSetupStep from './ConnectionSetupStep.vue';

const envVariables = `FORMS_PRO_MAILCOACH_API_URL=https://your-team.mailcoach.app/api
FORMS_PRO_MAILCOACH_TOKEN=mc_yourtoken`;
</script>

<template>
    <div>
        <Heading size="lg" :text="__('forms-pro::messages.mailcoach_setup_heading')" />
        <Description class="mt-1.5" :text="__('forms-pro::messages.mailcoach_setup_intro')" />

        <ol class="mt-6 space-y-6">
            <li class="flex gap-4">
                <ConnectionSetupStep step="1" />
                <div class="flex-1">
                    <p>{{ __('forms-pro::messages.mailcoach_setup_token') }}</p>
                </div>
            </li>
            <li class="flex gap-4">
                <ConnectionSetupStep step="2" />
                <div class="flex-1 space-y-3">
                    <p v-html="__('forms-pro::messages.mailcoach_setup_env', { file: '<code>.env</code>' })" />
                    <Textarea
                        :model-value="envVariables"
                        read-only
                        copyable
                        :rows="2"
                        resize="none"
                        class="[&_textarea]:font-mono [&_textarea]:text-xs"
                    />
                    <Description :text="__('forms-pro::messages.mailcoach_setup_developer')" />
                </div>
            </li>
        </ol>
    </div>
</template>
