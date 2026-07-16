<script setup lang="ts">
import { Head } from '@statamic/cms/inertia';
import { Button } from '@statamic/cms/ui';
import AutomagicFormLayout from '../../layouts/AutomagicFormLayout.vue';

defineOptions({ layout: AutomagicFormLayout });

defineProps<{
    title: string;
    heading?: string;
    message?: string;
    showReset: boolean;
    resetLabel?: string;
    resetUrl: string;
}>();
</script>

<template>
    <Head :title="__(title)" />

    <div class="relative mx-auto max-w-[55rem] items-center justify-center">
        <div class="pt-12 pb-12 text-center">
            <h1 class="text-3xl leading-[1.25] font-medium antialiased mb-8">{{ __(heading) }}</h1>
            <p v-if="message" class="text-lg leading-relaxed text-gray-600">{{ __(message) }}</p>

            <div v-if="showReset" class="mt-10 flex justify-center">
                <Button
                    :href="resetUrl"
                    variant="primary"
                    class="border-0! dark:border-0! ring-0! shadow-none!"
                    style="--theme-color-primary: var(--theme-color-gray-950)"
                    :text="__(resetLabel)"
                />
            </div>
        </div>
    </div>
</template>
