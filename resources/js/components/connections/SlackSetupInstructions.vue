<script setup>
import { Button, Description, Heading, Icon, Input } from '@statamic/cms/ui';
import { usePage } from '@statamic/cms/inertia';
import ConnectionSetupStep from './ConnectionSetupStep.vue';

const cmsName = usePage().props._statamic.cmsName;
const envVariable = 'FORMS_PRO_SLACK_TOKEN=xoxb-your-token';
const scopes = ['chat:write', 'chat:write.public', 'channels:read', 'channels:join', 'groups:read'];

const manifest = {
    display_information: { name: cmsName },
    features: { bot_user: { display_name: cmsName } },
    oauth_config: { scopes: { bot: scopes } },
};

const manifestYaml = `display_information:
  name: ${cmsName}
features:
  bot_user:
    display_name: ${cmsName}
oauth_config:
  scopes:
    bot: [${scopes.join(', ')}]`;

const createAppUrl = `https://api.slack.com/apps?new_app=1&manifest_json=${encodeURIComponent(JSON.stringify(manifest))}`;
</script>

<template>
    <div
        class="[&_code]:rounded-sm [&_code]:bg-gray-100 [&_code]:px-1 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-xs dark:[&_code]:bg-gray-800"
    >
        <Heading size="lg" :text="__('forms-pro::messages.slack_setup_heading')" />
        <Description class="mt-1.5" :text="__('forms-pro::messages.slack_setup_intro')" />

        <ol class="mt-6 space-y-6">
            <li class="flex gap-4">
                <ConnectionSetupStep>1</ConnectionSetupStep>
                <div class="flex-1 space-y-3">
                    <p>{{ __('forms-pro::messages.slack_setup_create_app') }}</p>
                    <Button
                        size="sm"
                        icon="external-link"
                        :href="createAppUrl"
                        target="_blank"
                        :text="__('Create Slack app')"
                    />

                    <details class="group">
                        <summary
                            class="flex cursor-pointer list-none items-center gap-1.5 text-sm text-gray-600 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200 [&::-webkit-details-marker]:hidden select-none"
                        >
                            <Icon
                                name="chevron-right"
                                class="size-3.5 transition-transform group-open:rotate-90"
                                aria-hidden="true"
                            />
                            {{ __('forms-pro::messages.slack_setup_manifest_toggle') }}
                        </summary>

                        <div class="mt-3 space-y-2">
                            <Description :text="__('forms-pro::messages.slack_setup_manifest')" />
                            <pre
                                class="overflow-x-auto rounded-lg border border-gray-200 bg-gray-50 p-4 text-xs text-gray-800 dark:border-white/10 dark:bg-gray-950/40 dark:text-gray-300"
                            ><code>{{ manifestYaml }}</code></pre>
                        </div>
                    </details>
                </div>
            </li>
            <li class="flex gap-4">
                <ConnectionSetupStep>2</ConnectionSetupStep>
                <div class="flex-1">
                    <p>{{ __('forms-pro::messages.slack_setup_install') }}</p>
                </div>
            </li>
            <li class="flex gap-4">
                <ConnectionSetupStep>3</ConnectionSetupStep>
                <div class="flex-1 space-y-3">
                    <p v-html="__('forms-pro::messages.slack_setup_token', { file: '<code>.env</code>' })" />
                    <Input :model-value="envVariable" read-only copyable class="font-mono text-xs" />
                    <Description
                        :text="
                            __('forms-pro::messages.slack_setup_developer', {
                                variable: '<code>FORMS_PRO_SLACK_TOKEN</code>',
                            })
                        "
                    />
                </div>
            </li>
        </ol>
    </div>
</template>
