<script setup lang="ts">
import { Head } from '@statamic/cms/inertia';
import AutomagicFormLayout from './Layout.vue';
import Message from './Message.vue';

defineOptions({ layout: AutomagicFormLayout });

defineProps<{
    title: string;
    message: string;
}>();
</script>

<template>
    <Head :title="__(title)" />

    <Message :message />
</template>
