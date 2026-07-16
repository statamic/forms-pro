<script setup lang="ts">
import { router, Head } from '../../../../../cms/packages/cms/types/bootstrap/cms/inertia';
import { PublishContainer, PublishSections, TabProvider, Button } from '../../../../../cms/packages/cms/types/bootstrap/cms/ui';
import AutomagicFormLayout from '../../layouts/AutomagicFormLayout.vue';
import { ref, computed, useTemplateRef, nextTick, watch, onMounted, onBeforeUnmount } from 'vue';
import axios from "axios";

defineOptions({ layout: AutomagicFormLayout });

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
    progress?: number;
}>();

const errors = ref<object>({});
const loading = ref<boolean>(false);
const meta = ref<object>(props.meta);
const values = ref<object>(props.values);
const container = useTemplateRef<InstanceType<typeof PublishContainer>>('container');

const progressWidth = computed<string>(() => `${props.progress ?? 20}%`);
const isFirstPage = computed<boolean>(() => props.currentPage.id === props.pages[0].id);
const isLastPage = computed<boolean>(() => props.currentPage.id === props.pages[props.pages.length - 1].id);
const allPagesHaveCustomLabels = computed<boolean>(() => props.pages.every((page: Page) => page.display));

const titleClasses = computed<array[string]>(() => [
    'text-center',
    allPagesHaveCustomLabels.value
        ? 'text-2xs text-gray-500 uppercase tracking-[0.05em] mb-1'
        : 'text-3xl leading-[1.25] font-medium antialiased text-center mb-8',
]);

const fileFields = computed<string[]>(() =>
	props.blueprint.tabs
		.flatMap(tab => tab.sections)
		.flatMap(section => section.fields)
		.filter(field => ['assets', 'files', 'form_upload'].includes(field.type))
		.map(field => field.handle),
);

const appendToFormData = (formData: FormData, key: string, value: unknown): void => {
	if (value === null || value === undefined) return;

	if (value instanceof File) {
		formData.append(key, value);
	} else if (Array.isArray(value)) {
		value.forEach(item => appendToFormData(formData, `${key}[]`, item));
	} else if (typeof value === 'object') {
		Object.entries(value).forEach(([subKey, subValue]) => appendToFormData(formData, `${key}[${subKey}]`, subValue));
	} else {
		formData.append(key, String(value));
	}
};

const toFormData = (values: object): FormData => {
	const formData = new FormData();

	Object.entries(values).forEach(([key, value]) => appendToFormData(formData, key, value));

	return formData;
};

const submit = () => {
	loading.value = true;

	axios.post(props.action, toFormData(values.value))
		.then(response => {
			container.value.clearDirtyState();
			nextTick(() => router.visit(response.data.redirect));
		})
		.catch(error => {
			errors.value = error.response.data.errors;
		})
		.finally(() => loading.value = false);
};

const clearErrorsFor = (fields: string[]): void => {
	errors.value = Object.fromEntries(
		Object.entries(errors.value).filter(([key]) => !fields.some(field => key === field || key.startsWith(`${field}.`))),
	);
};

let validationTimeout: ReturnType<typeof setTimeout>;
let validationController: AbortController;

const validate = (fields: string[]): void => {
	validationController?.abort();
	validationController = new AbortController();

	const payload = Object.fromEntries(Object.entries(values.value).filter(([handle]) => !fileFields.value.includes(handle)));

	axios.post(props.action, payload, {
		headers: {
			Precognition: true,
			'Precognition-Validate-Only': fields.join(','),
		},
		signal: validationController.signal,
	})
		.then(() => clearErrorsFor(fields))
		.catch(error => {
			if (axios.isCancel(error) || error.response?.status !== 422) return;

			clearErrorsFor(fields);
			errors.value = { ...errors.value, ...error.response.data.errors };
		});
};

const snapshot = Object.fromEntries(Object.entries(props.values).map(([handle, value]) => [handle, JSON.stringify(value)]));

watch(values, (current) => {
	const changed = Object.keys(current).filter(
		handle => ! fileFields.value.includes(handle) && JSON.stringify(current[handle]) !== snapshot[handle],
	);

	if (! changed.length) return;

	changed.forEach(handle => snapshot[handle] = JSON.stringify(current[handle]));

	clearTimeout(validationTimeout);
	validationTimeout = setTimeout(() => validate(changed), 400);
}, { deep: true });

let submitBinding;

onMounted(() => {
	submitBinding = Statamic.$keys.bindGlobal('mod+enter', (e) => {
		e.preventDefault();
		if (! loading.value) submit();
	});
});

onBeforeUnmount(() => {
	submitBinding?.destroy();
	clearTimeout(validationTimeout);
	validationController?.abort();
});
</script>

<template>
    <Head :title="__(title)" />

    <div class="fixed inset-x-0 top-0 z-20 h-1.5 w-full bg-gray-200 dark:bg-gray-950">
        <div
            id="progress-bar"
            class="h-full rounded-r-full bg-indigo-600 transition-[width] duration-300 ease-out"
            :style="{ width: progressWidth }"
        />
    </div>

    <div class="fixed inset-x-0 top-1.5 z-20 flex justify-end px-2.5 py-1.5">
        <p id="progress-bar-label" class="text-center text-[0.8rem] text-gray-600">{{ progress ?? 20 }}% Complete</p>
    </div>

    <div class="relative mx-auto max-w-[55rem] items-center justify-center">
        <div :key="currentPage.id" class="pt-12 pb-12">
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
                    :loading="loading"
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
                    :loading="loading"
                    :text="currentPage.button_label ?? (isLastPage ? __('Submit') : __('Next Page'))"
                    @click="submit"
                />
            </div>
        </div>
    </div>
</template>
