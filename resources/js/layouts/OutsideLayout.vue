<script setup>
import { onMounted } from 'vue';

onMounted(() => {
    document.body.classList.add(
        'bg-white',
        'font-sans',
        'leading-normal',
        'scheme-light',
        'p-2',
        'outside'
    );
});
</script>

<template>
    <div class="relative mx-auto max-w-5xl items-center justify-center">
        <slot />
    </div>
</template>
