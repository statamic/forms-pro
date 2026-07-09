<script setup>
import { Fieldtype } from '@statamic/cms';
import { Combobox, Field, Input } from '@statamic/cms/ui';
import { computed, onBeforeUnmount, ref } from 'vue';
import { formatFor } from '../../frontend/address/formats';
import providers from '../../frontend/address/autocomplete/providers';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { isReadOnly, update, expose } = Fieldtype.use(emit, props);
defineExpose(expose);

const DEBOUNCE_MS = 250;
const MIN_QUERY_LENGTH = 3;
const FULL_WIDTH_FIELDS = ['line_1', 'line_2'];
const ADDRESS_FIELDS = ['line_1', 'line_2', 'city', 'region', 'postcode', 'country'];

const suggestions = ref([]);
const highlightedIndex = ref(-1);
const autocompleteOpen = ref(false);
const autocompleteLoading = ref(false);

let timeout = null;

const listboxId = computed(() => `${props.id}-line_1-suggestions`);
const autocomplete = computed(() => props.meta.autocomplete ?? null);
const showAutocomplete = computed(() => autocompleteOpen.value && suggestions.value.length > 0);
const format = computed(() => formatFor(props.value?.country || props.meta.defaultCountry));

const autocompleteProvider = computed(() => {
	if (isReadOnly.value || !autocomplete.value) return null;

	return providers[autocomplete.value.provider]?.() ?? null;
});

const addressFields = computed(() => {
	const handles = ADDRESS_FIELDS.filter((handle) => {
		if (handle === 'postcode') return format.value.postcode !== null;
		if (handle === 'region') return format.value.region !== null;

		return true;
	});

	return handles.map((handle) => {
		return {
			handle,
			id: `${props.id}-${handle}`,
			label: props.meta.labels[format.value[handle] ?? handle],
			width: widthClass(handle, handles),
		};
	});
});

const countryOptions = computed(() => {
    return Object.entries(props.meta.countries ?? {}).map(([value, label]) => ({ value, label }));
});

function widthClass(handle, handles) {
	if (FULL_WIDTH_FIELDS.includes(handle)) return 'col-span-2';

	const halfWidthHandles = handles.filter((halfHandle) => !FULL_WIDTH_FIELDS.includes(halfHandle));
	const danglingHandle = halfWidthHandles.length % 2 === 1 ? halfWidthHandles[halfWidthHandles.length - 1] : null;

	return handle === danglingHandle ? 'col-span-2' : 'col-span-1';
}

function closeAutocomplete() {
	autocompleteOpen.value = false;
	highlightedIndex.value = -1;
}

function onFocus() {
	if (suggestions.value.length) autocompleteOpen.value = true;
}

function suggest(value) {
	clearTimeout(timeout);

	const query = (value ?? '').trim();

	if (!autocompleteProvider.value || query.length < MIN_QUERY_LENGTH) {
		suggestions.value = [];
		closeAutocomplete();

		return;
	}

	timeout = setTimeout(async () => {
		suggestions.value = await autocompleteProvider.value.suggest(query, autocomplete.value).catch(() => []);
		highlightedIndex.value = -1;
		autocompleteOpen.value = true;
	}, DEBOUNCE_MS);
}

function lineOneUpdated(newValue) {
	valueUpdated('line_1', newValue);
	suggest(newValue);
}

async function select(suggestion) {
	closeAutocomplete();
	autocompleteLoading.value = true;

	const address = await autocompleteProvider.value.retrieve(suggestion, autocomplete.value)
		.catch(() => null)
		.finally(() => autocompleteLoading.value = false);

	if (!address) return;

	const picked = Object.fromEntries(ADDRESS_FIELDS.map((handle) => [handle, address[handle] || null]));
	update({ ...(props.value ?? {}), ...picked });
}

function onKeydown(e) {
	if (!showAutocomplete.value) return;

	if (e.key === 'ArrowDown') {
		e.preventDefault();
		highlightedIndex.value = Math.min(highlightedIndex.value + 1, suggestions.value.length - 1);
	} else if (e.key === 'ArrowUp') {
		e.preventDefault();
		highlightedIndex.value = Math.max(highlightedIndex.value - 1, 0);
	} else if (e.key === 'Enter' && highlightedIndex.value >= 0) {
		e.preventDefault();
		select(suggestions.value[highlightedIndex.value]);
	} else if (e.key === 'Escape') {
		closeAutocomplete();
	}
}

const valueUpdated = (handle, newValue) => update({ ...(props.value ?? {}), [handle]: newValue });

onBeforeUnmount(() => clearTimeout(timeout));
</script>

<template>
    <div class="grid grid-cols-2 gap-4 mt-1">
        <Field
            v-for="addressField in addressFields"
            :key="addressField.id"
            :id="addressField.id"
            :class="addressField.width"
            :label="addressField.label"
            :read-only="isReadOnly"
        >
	        <div v-if="addressField.handle === 'line_1'" class="relative" @focusin="onFocus" @focusout="closeAutocomplete">
		        <Input
			        :id="addressField.id"
			        :model-value="value[addressField.handle]"
			        :read-only="isReadOnly"
			        :input-attrs="{
				        role: 'combobox',
				        autocomplete: 'off',
				        'aria-autocomplete': 'list',
				        'aria-controls': listboxId,
				        'aria-expanded': showAutocomplete,
				        'aria-activedescendant': highlightedIndex >= 0 ? `${listboxId}-option-${highlightedIndex}` : undefined,
			        }"
			        :loading="autocompleteLoading"
			        @update:model-value="lineOneUpdated"
			        @keydown="onKeydown"
		        />
		        <div
			        v-show="showAutocomplete"
			        class="shadow-ui-sm z-(--z-index-above) absolute mt-1 w-full overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-800"
			        @mousedown.prevent
		        >
			        <ul :id="listboxId" role="listbox" class="max-h-[300px] overflow-y-auto p-2">
				        <li
					        v-for="(suggestion, index) in suggestions"
					        :key="suggestion.id"
					        :id="`${listboxId}-option-${index}`"
					        role="option"
					        :aria-selected="index === highlightedIndex"
					        :disabled="autocompleteLoading"
					        class="relative flex w-full cursor-pointer select-none items-center gap-2 overflow-hidden rounded-lg px-2 py-2 text-sm antialiased text-gray-900 hover:bg-gray-100 aria-selected:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 dark:aria-selected:bg-gray-700"
					        @mousedown="select(suggestion)"
				        >
					        {{ suggestion.label }}
				        </li>
			        </ul>
			        <div
				        class="flex items-center bg-body-bg border-t border-gray-200 px-4 py-2.5 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400"
				        v-html="autocompleteProvider?.attribution"
			        />
		        </div>
	        </div>
            <Combobox
                v-else-if="addressField.handle === 'country'"
                :id="addressField.id"
                clearable
                searchable
                :options="countryOptions"
                :read-only="isReadOnly"
                :placeholder="null"
                :model-value="value[addressField.handle]"
                @update:model-value="valueUpdated(addressField.handle, $event)"
            />
            <Input
                v-else
                :id="addressField.id"
                :model-value="value[addressField.handle]"
                :read-only="isReadOnly"
                @update:model-value="valueUpdated(addressField.handle, $event)"
            />
        </Field>
    </div>
</template>
