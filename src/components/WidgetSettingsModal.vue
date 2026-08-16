<template>
	<NcModal size="small" @close="onCancel">
		<div class="i360-modal">
			<h2 class="i360-modal__title">
				{{ t('integration_inspect360', 'Widget settings') }}
			</h2>

			<section class="i360-modal__section">
				<h4>{{ t('integration_inspect360', 'Refresh frequency') }}</h4>
				<RefreshIntervalPicker v-model="draftSeconds" />
			</section>

			<section v-if="showMaxItems" class="i360-modal__section">
				<h4>{{ t('integration_inspect360', 'Records to show') }}</h4>
				<MaxItemsPicker v-model="draftMaxItems" />
			</section>

			<div class="i360-modal__actions">
				<NcButton @click="onCancel">
					{{ t('integration_inspect360', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="saving"
					@click="onSave">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="16" />
						<ContentSaveIcon v-else :size="16" />
					</template>
					{{ t('integration_inspect360', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'
import MaxItemsPicker from './MaxItemsPicker.vue'
import RefreshIntervalPicker from './RefreshIntervalPicker.vue'

/**
 * Shared widget-settings modal used by all four Inspect360 widgets.
 * Mirrors the integration_forgejo_gitea pattern: NcModal with draft
 * settings, Cancel / Save actions, saving spinner on the primary button.
 *
 * The "Records to show" section is rendered only when maxItems is a
 * number — the Overview widget passes null (fixed 4-tile layout, no
 * records-count concept), the three list widgets pass a real value.
 *
 * Emits:
 *  - close: user cancelled (or closed via backdrop / escape).
 *  - save({refreshSeconds, maxItems}): user confirmed. maxItems is null
 *    when the widget didn't opt in via the prop.
 */
export default {
	name: 'WidgetSettingsModal',
	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		RefreshIntervalPicker,
		MaxItemsPicker,
		ContentSaveIcon,
	},

	props: {
		refreshSeconds: { type: Number, default: 300 },
		maxItems: { type: Number, default: null },
		saving: { type: Boolean, default: false },
	},

	emits: ['close', 'save'],
	data() {
		return {
			draftSeconds: this.refreshSeconds,
			draftMaxItems: this.maxItems ?? 10,
		}
	},

	computed: {
		showMaxItems() {
			return this.maxItems !== null && this.maxItems !== undefined
		},
	},

	watch: {
		refreshSeconds(v) {
			this.draftSeconds = v
		},

		maxItems(v) {
			if (v !== null && v !== undefined) {
				this.draftMaxItems = v
			}
		},
	},

	methods: {
		onCancel() {
			this.draftSeconds = this.refreshSeconds
			this.draftMaxItems = this.maxItems ?? 10
			this.$emit('close')
		},

		onSave() {
			this.$emit('save', {
				refreshSeconds: this.draftSeconds,
				maxItems: this.showMaxItems ? this.draftMaxItems : null,
			})
		},
	},
}
</script>

<style scoped lang="scss">
.i360-modal {
	padding: 20px 24px 16px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.i360-modal__title {
	margin: 0;
	font-size: 18px;
}

.i360-modal__section {
	display: flex;
	flex-direction: column;
	gap: 8px;

	h4 {
		margin: 0;
		font-size: 13px;
		font-weight: 600;
		color: var(--color-text-maxcontrast);
		text-transform: uppercase;
		letter-spacing: 0.4px;
	}
}

.i360-modal__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
