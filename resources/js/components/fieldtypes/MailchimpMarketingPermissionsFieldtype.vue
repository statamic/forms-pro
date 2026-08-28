<script setup>
import { Fieldtype } from '@statamic/cms';
import { Description, ErrorMessage, Icon, injectPublishContext } from '@statamic/cms/ui';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { isReadOnly, update, expose } = Fieldtype.use(emit, props);
defineExpose(expose);

const { values } = injectPublishContext();

const error = ref(null);
const state = ref(null);
const loading = ref(false);
const permissions = ref([]);

const list = computed(() => values.value?.list);
const formFields = computed(() => props.meta.field);
const mapping = computed(() => new Map((props.value ?? []).map(({ permission, field }) => [permission, field])));

const fieldFor = (id) => mapping.value.get(id) ?? null;

const setField = (id, handle) => {
    const updated = new Map(mapping.value);

    if (handle) {
        updated.set(id, handle);
    } else {
        updated.delete(id);
    }

    update(
        permissions.value
            .filter((permission) => updated.has(permission.id))
            .map((permission) => ({ permission: permission.id, field: updated.get(permission.id) })),
    );
};

let latestFetch = 0;

const fetchPermissions = () => {
    const fetch = ++latestFetch;

    error.value = null;
    state.value = null;
    permissions.value = [];

    if (!list.value) return;

    loading.value = true;

    axios
        .get(props.config.url, { params: { list: list.value } })
        .then(({ data }) => {
            if (fetch !== latestFetch) return;

            error.value = data.error;
            state.value = data.state;
            permissions.value = data.permissions;

            if (data.state === 'ok') {
                const pruned = (props.value ?? []).filter(({ permission }) =>
                    data.permissions.some(({ id }) => id === permission),
                );

                if (pruned.length !== (props.value ?? []).length) {
                    update(pruned.length ? pruned : null);
                }
            }
        })
        .catch(() => fetch === latestFetch && (error.value = __('Something went wrong')))
        .finally(() => fetch === latestFetch && (loading.value = false));
};

watch(list, fetchPermissions, { immediate: true });
</script>

<template>
    <Description v-if="!list" :text="__('forms-pro::messages.mailchimp_marketing_permissions_no_audience')" />
    <div v-else-if="loading" class="flex items-center truncate gap-x-2">
        <Icon name="loading" />
        <Description :text="__('Loading')" />
    </div>
    <Description
        v-else-if="state === 'not_enabled'"
        :text="__('forms-pro::messages.mailchimp_marketing_permissions_not_enabled')"
    />
    <Description
        v-else-if="state === 'no_contacts'"
        :text="__('forms-pro::messages.mailchimp_marketing_permissions_no_contacts')"
    />
    <ErrorMessage v-else-if="error" :text="error" class="text-red-500 dark:text-red-400" />

    <table v-else-if="state === 'ok'" class="grid-table">
        <thead>
            <tr>
                <th scope="col" width="50%" class="text-start">{{ __('Permission') }}</th>
                <th scope="col" width="50%" class="text-start">{{ __('Form Field') }}</th>
            </tr>
        </thead>

        <tbody>
            <tr v-for="permission in permissions" :key="permission.id">
                <td class="grid-cell">{{ permission.text }}</td>

                <td class="grid-cell">
                    <component
                        :is="`${formFields.config.type}-fieldtype`"
                        :config="formFields.config"
                        :meta="formFields.meta"
                        :handle="permission.id"
                        :value="fieldFor(permission.id)"
                        :read-only="isReadOnly"
                        @update:value="setField(permission.id, $event)"
                    />
                </td>
            </tr>
        </tbody>
    </table>
</template>
