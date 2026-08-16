<template>
	<NcModal size="small" @close="onCancel">
		<div class="i360-modal">
			<h2 class="i360-modal__title">{{ t('integration_inspect360', 'Widget settings') }}</h2>

			<section class="i360-modal__section">
				<h4>{{ t('integration_inspect360', 'Refresh frequency') }}</h4>
				<RefreshIntervalPicker v-model="draftSeconds" />
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
import RefreshIntervalPicker from './RefreshIntervalPicker.vue'

/**
 * Shared widget-settings modal used by all four Inspect360 widgets.
 * Mirrors the integration_forgejo_gitea pattern: NcModal with a draft
 * refresh-cadence value, Cancel/Save actions, and a saving spinner on
 * the primary button.
 *
 * Emits:
 *  - close: user cancelled (or closed via backdrop / escape).
 *  - save(seconds): user confirmed; parent persists and closes.
 */
export default {
	name: 'WidgetSettingsModal',
	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		RefreshIntervalPicker,
		ContentSaveIcon,
	},
	props: {
		refreshSeconds: { type: Number, default: 300 },
		saving: { type: Boolean, default: false },
	},
	emits: ['close', 'save'],
	data() {
		return {
			draftSeconds: this.refreshSeconds,
		}
	},
	watch: {
		refreshSeconds(v) {
			this.draftSeconds = v
		},
	},
	methods: {
		onCancel() {
			this.draftSeconds = this.refreshSeconds
			this.$emit('close')
		},
		onSave() {
			this.$emit('save', this.draftSeconds)
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
