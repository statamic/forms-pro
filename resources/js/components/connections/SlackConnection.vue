<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import axios from 'axios';
import { nanoid as uniqid } from 'nanoid';
import { keys } from '@statamic/cms/api';
import { ConnectionList, ConnectionLogic } from '@statamic/cms';
import { Alert, Badge, Button, Checkbox, CheckboxGroup, Combobox, Description, DropdownItem, Field, Heading, Icon, Input, Label, Subheading, Switch } from '@statamic/cms/ui';
import SlackSetupInstructions from "./SlackSetupInstructions.vue";

const props = defineProps({
    form: Object,
    config: { type: Array, default: () => [] },
    action: String,
    testUrl: String,
    configured: Boolean,
    fields: { type: Array, default: () => [] },
    channels: { type: Array, default: () => [] },
    connectionError: String,
});

const dirtyKey = 'slack-connection';

const errors = ref({});
const saving = ref(false);
const testing = ref(null);
const saveBinding = ref(null);

const notifications = ref(props.config.map((notification) => ({
    enabled: true,
    include_fields: true,
    link_to_submission: true,
    ...notification,
    conditions: (notification.conditions ?? []).map((condition) => ({ ...condition, _id: uniqid() })),
})));

const channelOptions = computed(() => props.channels.map((channel) => ({ ...channel, name: `#${channel.name}` })));

const selectedFields = (notification) => notification.fields ?? props.fields.map((field) => field.handle);

const selectFields = (notification, handles) => {
    notification.fields = handles.length === props.fields.length ? null : handles;
};

const addNotification = () => notifications.value.push({
    id: uniqid(),
    enabled: true,
    include_fields: true,
    link_to_submission: true,
    conditions: [],
});

const duplicateNotification = (notification) => {
    const index = notifications.value.indexOf(notification);

    notifications.value.splice(index + 1, 0, {
        ...notification,
        id: uniqid(),
        conditions: notification.conditions.map((condition) => ({ ...condition, _id: uniqid() })),
    });
};

const removeNotification = (notification) =>
    (notifications.value = notifications.value.filter((item) => item !== notification));

const channelSelected = (notification, id) => {
    notification.channel = id;
    notification.channel_name = props.channels.find((channel) => channel.id === id)?.name;
};

// TODO: Duplicated from ConnectionLogic.vue. Core doesn't export it, and its copy reads
// `suggestableFields` from the Inertia page props, which an addon bundle can't reach.
// Delete this once core exports it and accepts the fields as an argument.
const conditionsSummary = (conditions) => {
    const operatorLabels = {
        equals: __('equals'),
        not: __('does not equal'),
        contains: __('contains'),
        contains_any: __('contains any of'),
    };

    const fieldDisplay = (handle) => props.fields.find((field) => field.handle === handle)?.display ?? handle;

    const filtered = (conditions ?? []).filter((condition) => condition.field);

    if (filtered.length === 0) return null;

    return filtered
        .map((condition, index) => {
            const prefix = index === 0 ? __('if') : __(condition.join === 'or' ? 'or' : 'and');
            const operator = operatorLabels[condition.operator] ?? condition.operator;
            return `${prefix} ${fieldDisplay(condition.field)} ${operator} ${condition.value ?? ''}`.trim();
        })
        .join(' ');
};

const error = (index, handle) => errors.value[`configs.${index}.${handle}`]?.[0];
const hasError = (index) => Object.keys(errors.value).some((key) => key.startsWith(`configs.${index}.`));

const save = () => {
    if (saving.value) return;

    errors.value = {};
    saving.value = true;

    axios
        .patch(props.action, { configs: notifications.value })
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
    <Teleport v-if="configured" to="#form-layout-actions">
        <Button variant="primary" :aria-label="__('Save')" :disabled="saving" @click="save">
            <Icon name="save" class="sm:hidden" />
            <span class="hidden sm:inline">{{ __('Save') }}</span>
        </Button>
    </Teleport>

    <div v-if="configured">
        <Alert v-if="connectionError" variant="error" class="mb-6">
            <Heading :text="__('forms-pro::messages.slack_connection_unavailable')" />
            <Description :text="connectionError" class="mt-1" />
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
                    {{ notification.channel_name ? `#${notification.channel_name}` : __('New Notification') }}
                </Badge>
                <Subheading v-show="collapsed" class="overflow-hidden text-ellipsis whitespace-nowrap gap-1.5!">
                    <span class="truncate">{{ conditionsSummary(notification.conditions) ?? notification.message }}</span>
                </Subheading>
            </template>

            <template #default="{ item: notification, index }">
                <ConnectionLogic
                    v-model:conditions="notification.conditions"
                    :always-label="__('Always send')"
                    :if-label="__('Send if...')"
                >
                    <template #then>
                        <div class="space-y-6 rounded-lg border border-gray-300 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                            <Field
                                :label="__('Channel')"
                                :instructions="__('forms-pro::messages.slack_connection_channel_instructions')"
                                required
                                :error="error(index, 'channel')"
                            >
                                <Combobox
                                    searchable
                                    option-label="name"
                                    option-value="id"
                                    :options="channelOptions"
                                    :model-value="notification.channel"
                                    :placeholder="__('Select a channel...')"
                                    @update:model-value="channelSelected(notification, $event)"
                                />
                            </Field>

	                        <!-- TODO: Replace with autocomplete editor once its merged (https://github.com/statamic/cms/pull/15055) -->
                            <Field
                                :label="__('Heading')"
                                :error="error(index, 'heading')"
                            >
                                <Input v-model="notification.heading" />
                            </Field>

	                        <div class="flex">
		                        <Field
			                        class="w-1/2"
			                        :label="__('Include Submitted Fields')"
			                        :instructions="__('forms-pro::messages.slack_connection_include_fields_instructions')"
		                        >
			                        <Switch v-model="notification.include_fields" />
		                        </Field>

		                        <Field
			                        class="w-1/2"
			                        :label="__('Link to Submission')"
			                        :instructions="__('forms-pro::messages.slack_connection_link_to_submission_instructions')"
		                        >
			                        <Switch v-model="notification.link_to_submission" />
		                        </Field>
	                        </div>

                            <Field
                                v-if="notification.include_fields"
                                :label="__('Fields to Include')"
                                :instructions="__('forms-pro::messages.slack_connection_fields_instructions')"
                                :error="error(index, 'fields')"
                            >
                                <CheckboxGroup
                                    appearance="chips"
                                    :model-value="selectedFields(notification)"
                                    @update:model-value="selectFields(notification, $event)"
                                >
                                    <Checkbox
                                        v-for="field in fields"
                                        :key="field.handle"
                                        :value="field.handle"
                                        :label="field.display"
                                    />
                                </CheckboxGroup>
                            </Field>
                        </div>
                    </template>
                </ConnectionLogic>
            </template>
        </ConnectionList>
    </div>

	<SlackSetupInstructions v-else />
</template>
