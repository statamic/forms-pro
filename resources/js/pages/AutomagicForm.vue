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
const allPagesHaveCustomLabels = computed(() => props.pages.every((page) => page.display));
const titleClasses = computed(() => [
    'text-center',
    allPagesHaveCustomLabels.value
        ? 'text-2xs text-gray-500 uppercase tracking-[0.05em] mb-1'
        : 'text-3xl leading-[1.25] font-medium antialiased text-center mb-8',
]);

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

    <div class="pt-4 pb-12">
        <h1 :class="titleClasses">{{ title }}</h1>
        <h2 v-if="currentPage.display" class="text-3xl leading-[1.25] font-medium antialiased text-center mb-8">{{ currentPage.display }}</h2>

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

        <div class="mt-10 flex justify-center gap-2.5">
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
