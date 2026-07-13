<script setup lang="ts">
import { router, Head } from '@statamic/cms/inertia';
import { PublishContainer, PublishSections, TabProvider, Button } from '@statamic/cms/ui';
import OutsideLayout from '../layouts/OutsideLayout.vue';
import { ref, computed, useTemplateRef, nextTick } from 'vue';
import axios from "axios";

defineOptions({ layout: OutsideLayout });

type Page = {
	id: string;
	display: string;
	button_label: string;
	previous_page_label: string;
}

const props = defineProps<{
	action: string;
	previousPageUrl?: string;
    title: string;
    pages: array<Page>;
	currentPage?: Page;
    blueprint: object;
    values: object;
    meta: object;
}>();

const errors = ref<object>({});
const loading = ref<boolean>(false);
const meta = ref<object>(props.meta);
const values = ref<object>(props.values);
const container = useTemplateRef<InstanceType<typeof PublishContainer>>('container');

const isFirstPage = computed<boolean>(() => props.currentPage.id === props.pages[0].id);
const isLastPage = computed<boolean>(() => props.currentPage.id === props.pages[props.pages.length - 1].id);
const allPagesHaveCustomLabels = computed<boolean>(() => props.pages.every((page: Page) => page.display));
const titleClasses = computed<array[string]>(() => [
    'text-center',
    allPagesHaveCustomLabels.value
        ? 'text-2xs text-gray-500 uppercase tracking-[0.05em] mb-1'
        : 'text-3xl leading-[1.25] font-medium antialiased text-center mb-8',
]);

const submit = () => {
	loading.value = true;

	axios.post(props.action, values.value)
		.then(response => {
			container.value.clearDirtyState();
			nextTick(() => router.visit(response.data.redirect));
		})
		.catch(error => {
			errors.value = error.response.data.errors;
		})
		.finally(() => loading.value = false);
};
</script>

<template>
    <Head :title="__(title)" />

    <div :key="currentPage.id" class="pt-4 pb-12">
        <h1 :class="titleClasses">{{ __(title) }}</h1>
        <h2 v-if="currentPage.display" class="text-3xl leading-[1.25] font-medium antialiased text-center mb-8">{{ __(currentPage.display) }}</h2>

        <PublishContainer
	        ref="container"
            :blueprint
            :meta
	        :errors
	        :read-only="loading"
            v-model="values"
        >
	        <template v-for="tab in blueprint.tabs" :key="tab.handle">
		        <div v-show="tab.handle === currentPage.id">
			        <TabProvider :tab>
				        <PublishSections />
			        </TabProvider>
		        </div>
	        </template>
        </PublishContainer>

        <div class="mt-10 flex justify-center gap-2.5">
            <Button
                v-if="!isFirstPage && currentPage.previous_page_label"
                :href="previousPageUrl"
                :disabled="loading"
                variant="filled"
                icon="chevron-left"
                class="ps-3"
                :text="__(currentPage.previous_page_label)"
            />
            <Button
                variant="primary"
                class="border-0! dark:border-0! ring-0! shadow-none!"
                style="--theme-color-primary: var(--theme-color-gray-950)"
                :disabled="loading"
                :text="currentPage.button_label ?? (isLastPage ? __('Submit') : __('Next Page'))"
                @click="submit"
            />
        </div>
    </div>
</template>
