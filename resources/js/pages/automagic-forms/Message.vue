<script setup lang="ts">
import { Card } from '@statamic/cms/ui';

defineProps<{
	heading?: string;
	message?: string;
}>();
</script>

<template>
    <div class="relative mx-auto flex h-full max-w-lg items-center justify-center px-6 py-24">
        <Card inset class="w-full px-8 py-10 text-center">
            <h1 v-if="heading" class="text-2xl leading-[1.25] font-medium antialiased">{{ __(heading) }}</h1>
            <p v-if="message" class="text-lg leading-relaxed text-gray-600" :class="{ 'mt-3': heading }">{{ __(message) }}</p>
            <slot />
        </Card>
    </div>
</template>
