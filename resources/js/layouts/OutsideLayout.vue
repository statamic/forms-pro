<script setup>
import { computed, onMounted } from 'vue';

const props = defineProps({
    progress: {
        type: Number,
        default: 20,
    },
});

const progressWidth = computed(() => `${props.progress}%`);

onMounted(() => {
    document.body.classList.add(
        'bg-white',
        'font-sans',
        'leading-normal',
        'scheme-light',
        'outside'
    );
});
</script>

<template>
    <div class="w-full">
        <div class="h-1.5 w-full bg-gray-200">
            <div
                id="progress-bar"
                class="h-full rounded-r-full bg-indigo-600 transition-[width] duration-300 ease-out"
                :style="{ width: progressWidth }"
            />
        </div>
        <div class="flex justify-between px-2.5 py-1.5">
            <div>logo here</div>
            <p id="progress-bar-label" class="text-center text-[0.8rem] text-gray-600">{{ progress }}% Complete</p>
        </div>
    </div>
    <div class="relative mx-auto max-w-[62rem] items-center justify-center">
        <slot />
    </div>
</template>
