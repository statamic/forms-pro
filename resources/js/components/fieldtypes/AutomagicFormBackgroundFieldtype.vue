<script setup>
import { Fieldtype } from '@statamic/cms';
import { backgrounds } from '../../pages/automagic-forms/backgrounds';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { expose, update, isReadOnly } = Fieldtype.use(emit, props);
defineExpose(expose);
</script>

<template>
	<div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
		<button
			v-for="background in backgrounds"
			:key="background.handle"
			type="button"
			:disabled="isReadOnly"
			:aria-pressed="background.handle === value"
			class="group flex cursor-pointer flex-col overflow-hidden rounded-lg border text-start disabled:cursor-default disabled:opacity-50"
			:class="background.handle === value ? 'border-blue-500 ring-2 ring-blue-500' : 'border-gray-300 hover:border-gray-400 dark:border-gray-700'"
			@click="update(background.handle)"
		>
			<span
				class="h-16 w-full bg-white bg-repeat"
				:style="{ backgroundImage: background.image ?? 'none' }"
			/>
			<span class="truncate border-t border-inherit px-2 py-1.5 text-2xs text-gray-700 dark:text-gray-300">
				{{ __(background.name) }}
			</span>
		</button>
	</div>
</template>
