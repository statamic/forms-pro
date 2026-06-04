<script setup lang="ts">
import { Head } from '@statamic/cms/inertia';
import { PublishContainer, Button } from '@statamic/cms/ui';
import OutsideLayout from '../layouts/OutsideLayout.vue';
import { ref } from 'vue';

defineOptions({ layout: OutsideLayout });

const props = defineProps<{
	title: string;
	pages: array;
	page: object;
	blueprint: object;
	values: object;
	meta: object;
}>();

const meta = ref(props.meta);
const values = ref(props.values);

const previousPage = () => {
	// TODO: Magic goes here.
};

const submit = () => {
	// TODO: Magic goes here.
};
</script>

<template>
    <Head :title="title" />

	<div class="py-12">
		<h1 class="font-bold text-3xl mb-10">{{ title }}</h1>

		<ul class="flex flex-row items-center justify-between mb-10">
			<li
				v-for="page in pages"
				:key="page.id"
				:class="{
					'font-bold': page.id === page.id
				}"
			>{{ page.display }}</li>
		</ul>

		<PublishContainer
			class="mb-10"
			:blueprint
			:meta
			:values
		/>

		<div class="flex">
			<Button
				v-if="page.previous_page_label"
				variant="filled"
				icon="chevron-left"
				:data-editing-field="isInspectingAction ? '' : undefined"
				:data-editing-item="isInspectingAction ? '' : undefined"
				class="ps-3"
				:text="__(page.previous_page_label)"
			/>
			<Button
				variant="primary"
				:data-editing-field="isInspectingAction ? '' : undefined"
				:data-editing-item="isInspectingAction ? '' : undefined"
				class="border-0! dark:border-0! ring-0! shadow-none!"
				style="--theme-color-primary: var(--theme-color-gray-950)"
				:text="page.button_label ?? (isLastPage ? __('Submit') : __('Next Page'))"
			/>
		</div>
	</div>
</template>
