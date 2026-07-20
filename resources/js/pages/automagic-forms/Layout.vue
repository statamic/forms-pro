<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { findBackground } from './backgrounds';

type Site = {
	name: string;
	handle: string;
	url: string;
	lang: string;
	direction: string;
}

type Settings = {
	logoUrl?: string;
	brandColor?: string;
	background?: string;
}

const props = defineProps<{
	site: Site;
	settings: Settings;
	progress?: number;
}>();

const background = computed(() => findBackground(props.settings.background));

const styles = computed(() => ({
    '--pattern-bg': background.value?.image ?? 'none',
    ...(props.settings.brandColor && { '--automagic-brand': props.settings.brandColor }),
}));

onMounted(() => {
    document.body.classList.add(
        'bg-white',
        'dark:bg-gray-900',
        'font-sans',
        'leading-normal',
        'scheme-light',
        'outside'
    );

    document.head
        .querySelectorAll('link[rel~="icon"], link[rel="apple-touch-icon"]')
        .forEach((link) => link.remove());

    const favicon = document.createElement('link');
    favicon.rel = 'icon';
    favicon.href = 'data:,';
    document.head.appendChild(favicon);
});
</script>

<style>
.dark [data-background]::before {
    opacity: 0.035;
}
</style>

<template>
    <div
        data-background
        class="relative min-h-screen before:pointer-events-none before:absolute before:inset-0 before:z-0 before:content-[''] before:bg-[image:var(--pattern-bg)]"
        :style="styles"
    >
        <template v-if="progress">
            <div class="fixed inset-x-0 top-0 z-20 h-1.5 w-full bg-gray-200 dark:bg-gray-950">
                <div
                    id="progress-bar"
                    class="h-full rounded-r-full bg-[var(--automagic-brand,var(--color-indigo-600))] transition-[width] duration-300 ease-out"
                    :style="{ width: `${progress}%` }"
                />
            </div>

            <div class="fixed inset-x-0 top-1.5 z-20 flex justify-end px-2.5 py-1.5">
                <p id="progress-bar-label" class="text-center text-[0.8rem] text-gray-600">{{ __('forms-pro::messages.automagic_form_progress', { progress }) }}</p>
            </div>
        </template>

        <div class="fixed inset-x-0 top-1.5 z-20 px-2.5 py-1.5">
            <a v-if="settings.logoUrl" :href="site.url" target="_blank" rel="noopener" class="inline-block">
                <img id="logo" :src="settings.logoUrl" :alt="site.name" class="h-8 w-auto" />
            </a>
        </div>

        <slot />
    </div>
</template>
