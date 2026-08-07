<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue';
import axios from 'axios';
import { nanoid as uniqid } from 'nanoid';
import { keys } from '@statamic/cms/api';
import { ConnectionList, ConnectionLogic, conditionsSummary, deepClone } from '@statamic/cms';
import { Alert, Badge, Button, Description, Heading, Icon, Label, PublishContainer, PublishFields, PublishFieldsProvider, Subheading } from '@statamic/cms/ui';
import SlackSetupInstructions from './SlackSetupInstructions.vue';

interface SlackNotification {
    id: string;
    enabled: boolean;
    conditions: { _id: string; field: string; operator: string; value: string }[];
    values: object;
    meta: object;
}

const props = defineProps({
    form: Object,
    config: Array,
    action: String,
    blueprint: Object,
    notifications: Object,
    defaults: Object,
    isConfigured: Boolean,
    apiError: String,
});

const dirtyKey = 'slack-connection';

const errors = ref<object>({});
const saving = ref<boolean>(false);
const saveBinding = ref<ReturnType<typeof keys.bindGlobal> | null>(null);

const notifications = ref<SlackNotification[]>(props.config.map((config: object): SlackNotification => ({
    id: config.id,
    enabled: config.enabled ?? true,
    conditions: (config.conditions ?? []).map((condition: object) => ({ ...condition, _id: uniqid() })),
    values: props.notifications[config.id]?.values,
    meta: props.notifications[config.id]?.meta,
})));

const addNotification = (): void => notifications.value.push({
    id: uniqid(),
    enabled: true,
    conditions: [],
    values: deepClone(props.defaults.values),
    meta: deepClone(props.defaults.meta),
});

const duplicateNotification = (notification: SlackNotification) => {
    const index = notifications.value.indexOf(notification);

    notifications.value.splice(index + 1, 0, {
        id: uniqid(),
        enabled: notification.enabled,
        conditions: notification.conditions.map((condition) => ({ ...condition, _id: uniqid() })),
        values: deepClone(notification.values),
        meta: deepClone(notification.meta),
    });
};

const removeNotification = (notification: SlackNotification) => (notifications.value = notifications.value.filter((item) => item !== notification));

const channelName = (notification: SlackNotification): string => props.defaults.meta.channel.options.find((channel) => channel.value === notification.values.channel)?.label;

const hasError = (index: number) => Object.keys(errors.value).some((key) => key.startsWith(`configs.${index}.`));

const rowErrors = (index: number) =>
    Object.entries(errors.value)
        .filter(([key]) => key.startsWith(`configs.${index}.`))
        .reduce((fields, [key, messages]) => {
            const handle = key.replace(`configs.${index}.`, '').split('.')[0];
            fields[handle] = [...(fields[handle] ?? []), ...messages];
            return fields;
        }, {});

const save = (): void => {
    if (saving.value) return;

    errors.value = {};
    saving.value = true;

    axios
        .patch(props.action, {
            configs: notifications.value.map(({ values, meta, ...config }) => ({ ...config, ...values })),
        })
        .then(() => {
            Statamic.$dirty.remove(dirtyKey);
            Statamic.$toast.success(__('Saved'));
        })
        .catch((e) => {
            if (e.response?.status === 422) {
                errors.value = e.response.data.errors;
                Statamic.$toast.error(e.response.data.message);
            } else {
                Statamic.$toast.error(__('Something went wrong'));
            }
        })
        .finally(() => (saving.value = false));
};

watch(notifications, () => Statamic.$dirty.add(dirtyKey), { deep: true });

onMounted(() => {
    saveBinding.value = keys.bindGlobal(['mod+s'], (e) => {
        e.preventDefault();
        save();
    });
});

onUnmounted(() => {
    Statamic.$dirty.remove(dirtyKey);
    saveBinding.value?.destroy();
});
</script>

<template>
    <Teleport v-if="isConfigured" to="#form-layout-actions">
        <Button variant="primary" :aria-label="__('Save')" :disabled="saving" @click="save">
            <Icon name="save" class="sm:hidden" />
            <span class="hidden sm:inline">{{ __('Save') }}</span>
        </Button>
    </Teleport>

    <div v-if="isConfigured">
        <Alert v-if="apiError" variant="error" icon="alert-warning-exclamation-mark" class="mb-6">
            <Heading :text="__('forms-pro::messages.slack_connection_api_error')" />
            <Description :text="__(apiError)" class="mt-1" />
        </Alert>

        <Label :text="__('Notifications')" class="mb-2" />

        <ConnectionList
            v-model="notifications"
            :add-label="__('Add Notification')"
            :empty-heading="__('No notifications yet')"
            :empty-description="__('forms-pro::messages.slack_connection_description')"
            :delete-heading="__('Delete Slack Notification')"
            :delete-description="__('forms-pro::messages.slack_connection_delete_confirmation')"
            :has-error
            @add="addNotification"
            @duplicate="duplicateNotification"
            @remove="removeNotification"
        >
            <template #header="{ item: notification, collapsed }">
                <Badge size="lg" pill color="white" class="px-3 text-gray-950 gap-1">
                    <Icon name="forms-pro::slack" class="size-3.5 me-1" />
                    {{ channelName(notification) ?? __('New Notification') }}
                </Badge>
                <Subheading v-show="collapsed" class="overflow-hidden text-ellipsis whitespace-nowrap gap-1.5!">
                    <span class="truncate">{{ conditionsSummary(notification.conditions) ?? notification.values.heading }}</span>
                </Subheading>
            </template>

            <template #default="{ item: notification, index }">
                <ConnectionLogic
                    v-model:conditions="notification.conditions"
                    :always-label="__('Always send')"
                    :if-label="__('Send if...')"
                >
                    <template #then>
                        <div class="rounded-lg border border-gray-300 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                            <PublishContainer
                                :name="`slack-connection-${notification.id}`"
                                :blueprint="blueprint"
                                v-model="notification.values"
                                :meta="notification.meta"
                                :errors="rowErrors(index)"
                                :track-dirty-state="false"
                            >
                                <PublishFieldsProvider :fields="blueprint.tabs[0].sections[0].fields">
                                    <PublishFields />
                                </PublishFieldsProvider>
                            </PublishContainer>
                        </div>
                    </template>
                </ConnectionLogic>
            </template>
        </ConnectionList>
    </div>

    <SlackSetupInstructions v-else />
</template>
