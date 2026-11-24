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
const properties = computed(() => props.config.forms?.[hubspotForm.value]?.properties ?? []);
const mapping = computed(() => Object.fromEntries((props.value ?? []).map(({ key, value }) => [key, value])));

const fieldFor = (name) => mapping.value[name] ?? null;

const save = (mapped) =>
    update(
        properties.value
            .filter((property) => mapped[property.name])
            .map((property) => ({ key: property.name, value: mapped[property.name] })),
    );

const setField = (name, handle) => save({ ...mapping.value, [name]: handle });

watch(hubspotForm, () => save(mapping.value));
</script>

<template>
    <Description v-if="!hubspotForm" :text="__('forms-pro::messages.hubspot_no_form')" />

    <table v-else class="grid-table">
        <thead>
            <tr>
                <th scope="col" width="50%" class="text-start">{{ __('HubSpot Field') }}</th>
                <th scope="col" width="50%" class="text-start">{{ __('Form Field') }}</th>
            </tr>
        </thead>

        <tbody>
            <tr v-for="property in properties" :key="property.name">
                <td class="grid-cell">
                    <div class="flex items-center gap-x-2">
                        <span>{{ property.label }}</span>
                        <Badge v-if="property.required" size="sm" color="red" :text="__('Required')" />
                    </div>
                </td>

                <td class="grid-cell">
                    <component
                        :is="`${hubspotFormFields.config.type}-fieldtype`"
                        :config="hubspotFormFields.config"
                        :meta="hubspotFormFields.meta"
                        :handle="property.name"
                        :value="fieldFor(property.name)"
                        :read-only="isReadOnly"
                        @update:value="setField(property.name, $event)"
                    />
                </td>
            </tr>
        </tbody>
    </table>
</template>
