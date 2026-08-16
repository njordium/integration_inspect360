<template>
	<NcSelect
		:modelValue="selected"
		:options="options"
		:clearable="false"
		:searchable="false"
		label="label"
		class="i360-max-items-picker"
		@update:modelValue="onChange" />
</template>

<script>
import NcSelect from '@nextcloud/vue/components/NcSelect'

export default {
	name: 'MaxItemsPicker',
	components: { NcSelect },
	props: {
		modelValue: { type: Number, default: 10 },
	},
	emits: ['update:modelValue'],
	computed: {
		options() {
			return [
				{ value: 5, label: t('integration_inspect360', '5 records') },
				{ value: 10, label: t('integration_inspect360', '10 records') },
				{ value: 20, label: t('integration_inspect360', '20 records') },
				{ value: 50, label: t('integration_inspect360', '50 records') },
				{ value: 100, label: t('integration_inspect360', '100 records') },
			]
		},
		selected() {
			return this.options.find((o) => o.value === this.modelValue)
				|| this.options.find((o) => o.value === 10)
		},
	},
	methods: {
		onChange(v) {
			if (v && typeof v === 'object' && 'value' in v) {
				this.$emit('update:modelValue', v.value)
			}
		},
	},
}
</script>

<style scoped>
.i360-max-items-picker {
	width: 100%;
}
</style>
