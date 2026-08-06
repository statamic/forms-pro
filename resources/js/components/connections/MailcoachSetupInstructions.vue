<script setup>
import {
	Description,
	Heading,
	Input
} from '@statamic/cms/ui';

const stepNumber = 'mt-px flex size-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300';

const envVariables = `FORMS_PRO_MAILCOACH_API_URL=https://your-team.mailcoach.app/api
FORMS_PRO_MAILCOACH_TOKEN=mc_yourtoken`;
</script>

<template>
	<div>
		<Heading size="lg" :text="__('forms-pro::messages.mailcoach_setup_heading')" />
		<Description class="mt-1.5" :text="__('forms-pro::messages.mailcoach_setup_intro')" />

		<ol class="mt-6 space-y-6">
			<li class="flex gap-4">
				<span :class="stepNumber" aria-hidden="true">1</span>
				<div class="flex-1">
					<p>{{ __('forms-pro::messages.mailcoach_setup_token') }}</p>
				</div>
			</li>
			<li class="flex gap-4">
				<span :class="stepNumber" aria-hidden="true">2</span>
				<div class="flex-1 space-y-3">
					<p v-html="__('forms-pro::messages.mailcoach_setup_env', { file: '<code>.env</code>' })" />
					<pre class="overflow-x-auto rounded-lg border border-gray-200 bg-gray-50 p-4 text-xs text-gray-800 dark:border-white/10 dark:bg-gray-950/40 dark:text-gray-300"><code>{{ envVariables }}</code></pre>
					<Description :text="__('forms-pro::messages.mailcoach_setup_developer')" />
				</div>
			</li>
		</ol>
	</div>
</template>
