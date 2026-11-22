<script lang="ts">
type TurnstileApi = {
    render: (element: HTMLElement, options: object) => string;
    reset: (widgetId: string) => void;
    remove: (widgetId: string) => void;
};

let api: Promise<TurnstileApi> | null = null;

const loadApi = (): Promise<TurnstileApi> =>
    (api ??= new Promise((resolve) => {
        (window as any).formsProTurnstileReady = () => resolve((window as any).turnstile);

        const script = document.createElement('script');
        script.src =
            'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=formsProTurnstileReady';
        script.async = true;
        document.head.appendChild(script);
    }));
</script>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, useTemplateRef } from 'vue';

const emit = defineEmits<{
    token: [token: string | null];
}>();

const props = defineProps<{
    siteKey: string;
}>();

const widget = useTemplateRef<HTMLDivElement>('widget');

let turnstile: TurnstileApi | null = null;
let widgetId: string | null = null;

const reset = (): void => {
    if (!widgetId) return;

    emit('token', null);
    turnstile?.reset(widgetId);
};

defineExpose({ reset });

onMounted(async () => {
    turnstile = await loadApi();

    widgetId = turnstile.render(widget.value!, {
        sitekey: props.siteKey,
        theme: 'light',
        callback: (token: string) => emit('token', token),
        'expired-callback': () => emit('token', null),
        'error-callback': () => emit('token', null),
    });
});

onBeforeUnmount(() => {
    if (widgetId) turnstile?.remove(widgetId);
});
</script>

<template>
    <div ref="widget"></div>
</template>
