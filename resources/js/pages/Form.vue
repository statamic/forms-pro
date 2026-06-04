<script setup lang="ts">
import { Head } from '@statamic/cms/inertia';
import { PublishContainer, PublishSections, TabProvider, Button } from '@statamic/cms/ui';
import OutsideLayout from '../layouts/OutsideLayout.vue';
import { ref, computed } from 'vue';

defineOptions({ layout: OutsideLayout });

const props = defineProps<{
	title: string;
	pages: array;
	blueprint: object;
	values: object;
	meta: object;
}>();

const meta = ref(props.meta);
const values = ref(props.values);

const currentPageId = ref(props.pages[0].id);
const currentPage = computed(() => props.pages.find((page) => page.id === currentPageId.value));

const isFirstPage = computed(() => currentPageId.value === props.pages[0].id);
const isLastPage = computed(() => currentPageId.value === props.pages[props.pages.length - 1].id);

const previousPage = () => {
	// TODO: Actual, real-life logic here.

	let currentPageIndex = props.pages.findIndex((page) => page.id === currentPageId.value);
	let previousPage = props.pages.find((page) => page.id === props.pages[currentPageIndex - 1].id);

	currentPageId.value = previousPage.id;
};

const submit = () => {
	// TODO: Actual, real-life logic here.

	if (!isLastPage.value) {
		let currentPageIndex = props.pages.findIndex((page) => page.id === currentPageId.value);
		let nextPage = props.pages.find((page) => page.id === props.pages[currentPageIndex + 1].id);

		currentPageId.value = nextPage.id;
	}
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
					'font-bold': page.id === currentPage.id,
				}"
			>{{ page.display }}</li>
		</ul>

		<PublishContainer
			:blueprint
			:meta
			v-model="values"
		>
			<template
				v-for="tab in blueprint.tabs"
				:key="tab.handle"
			>
				<div v-if="tab.handle === currentPage.id">
					<TabProvider :tab>
						<PublishSections />
					</TabProvider>
				</div>
			</template>
		</PublishContainer>

		<div class="mt-10 flex gap-2.5">
			<Button
				v-if="!isFirstPage && currentPage.previous_page_label"
				variant="filled"
				icon="chevron-left"
				class="ps-3"
				:text="__(currentPage.previous_page_label)"
				@click="previousPage"
			/>
			<Button
				variant="primary"
				class="border-0! dark:border-0! ring-0! shadow-none!"
				style="--theme-color-primary: var(--theme-color-gray-950)"
				:text="currentPage.button_label ?? (isLastPage ? __('Submit') : __('Next Page'))"
				@click="submit"
			/>
		</div>
	</div>
</template>
