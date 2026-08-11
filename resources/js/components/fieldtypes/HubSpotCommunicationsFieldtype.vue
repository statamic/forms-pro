<script setup>
import { Fieldtype } from '@statamic/cms';
import { Badge, Description, injectPublishContext } from '@statamic/cms/ui';
import { computed, watch } from 'vue';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { isReadOnly, update, expose } = Fieldtype.use(emit, props);
defineExpose(expose);

const { values } = injectPublishContext();

const hubspotForm = computed(() => values.value?.form);
const hubspotFormFields = computed(() => props.meta.field);
const checkboxes = computed(() => props.config.forms?.[hubspotForm.value]?.consent?.checkboxes ?? []);
const mapping = computed(() => Object.fromEntries((props.value ?? []).map(({ subscription, field }) => [subscription, field])));

const fieldFor = (id) => mapping.value[id] ?? null;

const labelFor = (checkbox) => props.config.subscriptions?.[checkbox.id] ?? checkbox.label;

const save = (mapped) =>
    update(
        checkboxes.value
            .filter((checkbox) => mapped[checkbox.id])
            .map((checkbox) => ({ subscription: checkbox.id, field: mapped[checkbox.id] })),
    );

const setField = (id, handle) => save({ ...mapping.value, [id]: handle });

watch(hubspotForm, () => save(mapping.value));
</script>

<template>
    <Description v-if="!checkboxes.length" :text="__('forms-pro::messages.hubspot_connection_no_consent')" />

    <table v-else class="grid-table">
        <thead>
            <tr>
                <th scope="col" width="50%" class="text-start">{{ __('Subscription') }}</th>
                <th scope="col" width="50%" class="text-start">{{ __('Form Field') }}</th>
            </tr>
        </thead>

        <tbody>
            <tr v-for="checkbox in checkboxes" :key="checkbox.id">
                <td class="grid-cell">
                    <div class="flex items-center gap-x-2">
                        <span>{{ labelFor(checkbox) }}</span>
                        <Badge v-if="checkbox.required" size="sm" color="red" :text="__('Required')" />
                    </div>
                </td>

                <td class="grid-cell">
                    <component
                        :is="`${hubspotFormFields.config.type}-fieldtype`"
                        :config="hubspotFormFields.config"
                        :meta="hubspotFormFields.meta"
                        :handle="`${checkbox.id}`"
                        :value="fieldFor(checkbox.id)"
                        :read-only="isReadOnly"
                        @update:value="setField(checkbox.id, $event)"
                    />
                </td>
            </tr>
        </tbody>
    </table>
</template>
