<script setup lang="ts">
import { Head } from '@statamic/cms/inertia';
import { Button } from '@statamic/cms/ui';
import AutomagicFormLayout from './Layout.vue';
import Message from './Message.vue';

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

    <Message :heading :message>
        <div v-if="showReset" class="mt-8 flex justify-center">
            <Button
                :href="resetUrl"
                variant="primary"
                class="border-0! ring-0! shadow-none!"
                style="--theme-color-primary: var(--automagic-brand, var(--theme-color-gray-950))"
                :text="__(resetLabel)"
            />
        </div>
    </Message>
</template>
