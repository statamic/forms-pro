<script setup>
import { Button, Description, Heading, Textarea } from '@statamic/cms/ui';
import ConnectionSetupStep from './ConnectionSetupStep.vue';

const phoneNumbersUrl = 'https://console.twilio.com/us1/develop/phone-numbers/manage/search';
const geoPermissionsUrl = 'https://console.twilio.com/us1/develop/sms/settings/geo-permissions';
const apiKeysUrl = 'https://console.twilio.com/us1/account/keys-credentials/api-keys';
const envVariables = `FORMS_PRO_TWILIO_ACCOUNT_SID=ACyour-account-sid
FORMS_PRO_TWILIO_API_KEY=SKyour-key-sid
FORMS_PRO_TWILIO_API_SECRET=your-key-secret`;
</script>

<template>
    <div>
        <Heading size="lg" :text="__('forms-pro::messages.twilio_setup_heading')" />
        <Description class="mt-1.5" :text="__('forms-pro::messages.twilio_setup_intro')" />

        <ol class="mt-6 space-y-6">
            <li class="flex gap-4">
                <ConnectionSetupStep step="1" />
                <div class="flex-1 space-y-3">
                    <p>{{ __('forms-pro::messages.twilio_setup_number') }}</p>
                    <Button
                        size="sm"
                        icon="external-link"
                        :href="phoneNumbersUrl"
                        target="_blank"
                        :text="__('Buy a number')"
                    />
                </div>
            </li>
            <li class="flex gap-4">
                <ConnectionSetupStep step="2" />
                <div class="flex-1 space-y-3">
                    <p>{{ __('forms-pro::messages.twilio_setup_geo') }}</p>
                    <Button
                        size="sm"
                        icon="external-link"
                        :href="geoPermissionsUrl"
                        target="_blank"
                        :text="__('forms-pro::messages.twilio_setup_geo_button')"
                    />
                </div>
            </li>
            <li class="flex gap-4">
                <ConnectionSetupStep step="3" />
                <div class="flex-1 space-y-3">
                    <p>{{ __('forms-pro::messages.twilio_setup_key') }}</p>
                    <Button
                        size="sm"
                        icon="external-link"
                        :href="apiKeysUrl"
                        target="_blank"
                        :text="__('Create an API key')"
                    />
                </div>
            </li>
            <li class="flex gap-4">
                <ConnectionSetupStep step="4" />
                <div class="flex-1 space-y-3">
                    <p v-html="__('forms-pro::messages.twilio_setup_env', { file: '<code>.env</code>' })" />
                    <Textarea
                        :model-value="envVariables"
                        read-only
                        copyable
                        :rows="3"
                        resize="none"
                        class="[&_textarea]:font-mono [&_textarea]:text-xs"
                    />
                    <Description :text="__('forms-pro::messages.twilio_setup_developer')" />
                </div>
            </li>
        </ol>
    </div>
</template>
