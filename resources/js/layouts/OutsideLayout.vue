<script setup>
import { onMounted } from 'vue';

onMounted(() => {
    document.body.classList.add(
        'bg-gray-50',
        'dark:bg-gray-900',
        'font-sans',
        'leading-normal',
        'scheme-light',
        'p-2',
        'outside'
    );
});
</script>

<template>
    <div class="relative mx-auto max-w-3xl items-center justify-center">
        <slot />
    </div>
</template>
