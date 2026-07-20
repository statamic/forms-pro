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
    document.documentElement.classList.remove('dark');

    document.body.classList.add(
        'bg-white',
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

<template>
    <div
        data-background
        class="relative flex min-h-screen flex-col before:pointer-events-none before:absolute before:inset-0 before:z-0 before:content-[''] before:bg-[image:var(--pattern-bg)]"
        :style="styles"
    >
	    <div class="relative z-10 w-full shrink-0">
		    <div v-if="progress" class="h-1.5 w-full bg-gray-200">
			    <div
				    id="progress-bar"
				    class="h-full rounded-r-full bg-[var(--automagic-brand,var(--color-indigo-600))] transition-[width] duration-300 ease-out"
				    :style="{ width: `${progress}%` }"
			    />
		    </div>
		    <div class="flex justify-between px-2.5 py-1.5">
			    <div>
				    <a v-if="settings.logoUrl" :href="site.url" target="_blank" rel="noopener" class="inline-block pt-1.5">
					    <img id="logo" :src="settings.logoUrl" :alt="site.name" class="h-8 w-auto" />
				    </a>
			    </div>
			    <p v-if="progress" id="progress-bar-label" class="text-center text-[0.8rem] text-gray-600">{{ __('forms-pro::messages.automagic_form_progress', { progress }) }}</p>
		    </div>
	    </div>

        <div class="relative z-10 flex-1">
            <slot />
        </div>
    </div>
</template>
