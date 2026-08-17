<script setup lang="ts">
import { router, Head } from '@statamic/cms/inertia';
import { PublishContainer, PublishSections, TabProvider, Button } from '@statamic/cms/ui';
import AutomagicFormLayout from './Layout.vue';
import Turnstile from './Turnstile.vue';
import { ref, computed, useTemplateRef, nextTick, watch, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';

defineOptions({ layout: AutomagicFormLayout });

type Page = {
    id: string;
    display: string;
    button_label: string;
    previous_page_label: string;
};

const props = defineProps<{
    action: string;
    previousPageUrl?: string;
    title: string;
    pages: Page[];
    currentPage?: Page;
    blueprint: object;
    values: object;
    meta: object;
    turnstileSiteKey?: string;
}>();

const errors = ref<object>({});
const loading = ref<boolean>(false);
const meta = ref<object>(props.meta);
const values = ref<object>(props.values);
const turnstileToken = ref<string | null>(null);
const container = useTemplateRef<InstanceType<typeof PublishContainer>>('container');
const turnstileWidget = useTemplateRef<InstanceType<typeof Turnstile>>('turnstileWidget');

const waitingForTurnstile = computed<boolean>(() => !!props.turnstileSiteKey && !turnstileToken.value);
const isFirstPage = computed<boolean>(() => props.currentPage.id === props.pages[0].id);
const allPagesHaveCustomLabels = computed<boolean>(() => props.pages.every((page: Page) => page.display));

const titleClasses = computed<string[]>(() => [
    'text-center',
    allPagesHaveCustomLabels.value
        ? 'text-2xs text-gray-500 uppercase tracking-[0.05em] mb-1'
        : 'text-3xl leading-[1.25] font-medium antialiased text-center mb-8',
]);

const fileFields = computed<string[]>(() =>
    props.blueprint.tabs
        .flatMap((tab) => tab.sections)
        .flatMap((section) => section.fields)
        .filter((field) => ['assets', 'files', 'form_upload'].includes(field.type))
        .map((field) => field.handle),
);

const appendToFormData = (formData: FormData, key: string, value: unknown): void => {
    if (value === null || value === undefined) return;

    if (value instanceof File) {
        formData.append(key, value);
    } else if (Array.isArray(value)) {
        value.forEach((item) => appendToFormData(formData, `${key}[]`, item));
    } else if (typeof value === 'object') {
        Object.entries(value).forEach(([subKey, subValue]) =>
            appendToFormData(formData, `${key}[${subKey}]`, subValue),
        );
    } else if (typeof value === 'boolean') {
        formData.append(key, value ? '1' : '0');
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

    const formData = toFormData(values.value);

    if (turnstileToken.value) {
        formData.append('cf-turnstile-response', turnstileToken.value);
    }

    axios
        .post(props.action, formData)
        .then((response) => {
            container.value.clearDirtyState();
            nextTick(() => router.visit(response.data.redirect));
        })
        .catch((error) => {
            const status = error.response?.status;

            if (status === 403) {
                container.value.clearDirtyState();
                nextTick(() => router.visit(error.response.data.redirect));
                return;
            }

            if (status === 422) {
                errors.value = error.response.data.errors;

                if (errors.value['cf-turnstile-response']) {
                    turnstileWidget.value?.reset();
                }

                return;
            }

            if (status === 429) {
                Statamic.$toast.error(__('Too many attempts. Please wait a moment and try again.'));
                return;
            }

            Statamic.$toast.error(__('Something went wrong'));
        })
        .finally(() => (loading.value = false));
};

const clearErrorsFor = (fields: string[]): void => {
    errors.value = Object.fromEntries(
        Object.entries(errors.value).filter(
            ([key]) => !fields.some((field) => key === field || key.startsWith(`${field}.`)),
        ),
    );
};

let validationTimeout: ReturnType<typeof setTimeout>;
let validationController: AbortController;

// Fields stay pending until a request actually validates them. Rescheduling the debounce with only
// the latest field would otherwise drop the previous one, leaving it silently unvalidated.
const pendingFields = new Set<string>();

const validate = (): void => {
    // An in-flight request can resolve and clear everything before the debounce fires.
    if (!pendingFields.size) return;

    validationController?.abort();
    validationController = new AbortController();

    const fields = [...pendingFields];
    const payload = Object.fromEntries(
        Object.entries(values.value).filter(([handle]) => !fileFields.value.includes(handle)),
    );

    axios
        .post(props.action, payload, {
            headers: {
                Precognition: true,
                'Precognition-Validate-Only': fields.join(','),
            },
            signal: validationController.signal,
        })
        .then(() => {
            fields.forEach((handle) => pendingFields.delete(handle));
            clearErrorsFor(fields);
        })
        .catch((error) => {
            // Silent by design. The fields stay pending, and submitting validates everything anyway.
            if (axios.isCancel(error) || error.response?.status !== 422) return;

            fields.forEach((handle) => pendingFields.delete(handle));
            clearErrorsFor(fields);
            errors.value = { ...errors.value, ...error.response.data.errors };
        });
};

const snapshot = Object.fromEntries(
    Object.entries(props.values).map(([handle, value]) => [handle, JSON.stringify(value)]),
);

watch(
    values,
    (current) => {
        const changed = Object.keys(current).filter(
            (handle) => !fileFields.value.includes(handle) && JSON.stringify(current[handle]) !== snapshot[handle],
        );

        if (!changed.length) return;

        changed.forEach((handle) => {
            snapshot[handle] = JSON.stringify(current[handle]);
            pendingFields.add(handle);
        });

        clearTimeout(validationTimeout);
        validationTimeout = setTimeout(validate, 1000);
    },
    { deep: true },
);

let submitBinding;

onMounted(() => {
    submitBinding = Statamic.$keys.bindGlobal('mod+enter', (e) => {
        e.preventDefault();
        if (!loading.value && !waitingForTurnstile.value) submit();
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

    <div class="relative mx-auto max-w-[55rem] items-center justify-center">
        <div :key="currentPage.id" class="pt-12 pb-12">
            <h1 :class="titleClasses">{{ __(title) }}</h1>
            <h2 v-if="currentPage.display" class="text-3xl leading-[1.25] font-medium antialiased text-center mb-8">
                {{ __(currentPage.display) }}
            </h2>

            <PublishContainer ref="container" :blueprint :meta :errors v-model="values">
                <template v-for="tab in blueprint.tabs" :key="tab.handle">
                    <div v-if="tab.handle === currentPage.id">
                        <TabProvider :tab>
                            <PublishSections />
                        </TabProvider>
                    </div>
                </template>
            </PublishContainer>

            <template v-if="turnstileSiteKey">
                <Turnstile
                    ref="turnstileWidget"
                    :site-key="turnstileSiteKey"
                    class="mt-10 flex justify-center"
                    @token="turnstileToken = $event"
                />
                <p v-if="errors['cf-turnstile-response']" class="mt-2 text-center text-sm text-red-500">
                    {{ errors['cf-turnstile-response'][0] }}
                </p>
            </template>

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
                    class="border-0! ring-0! shadow-none!"
                    style="--theme-color-primary: var(--automagic-brand, var(--theme-color-gray-950))"
                    :disabled="loading || waitingForTurnstile"
                    :loading="loading"
                    :text="__(currentPage.button_label)"
                    @click="submit"
                />
            </div>
        </div>
    </div>
</template>
