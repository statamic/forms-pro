<script setup>
import { Fieldtype } from '@statamic/cms';
import { Input, Button } from '@statamic/cms/ui';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { expose } = Fieldtype.use(emit, props);
defineExpose(expose);
</script>

<template>
	<div class="flex items-center gap-2">
		<Input readonly copyable :model-value="meta.url" />
		<Button :href="meta.url" icon="external-link" target="_blank" :text="__('View')" />
	</div>
</template>