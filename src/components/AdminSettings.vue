<template>
	<div id="inspect360_prefs" class="section">
		<h2>
			<a class="icon icon-inspect360" />
			{{ t('integration_inspect360', 'Inspect360 integration') }}
		</h2>

		<p class="settings-hint">
			{{ t('integration_inspect360', 'Set the Inspect360 instance address your users will connect to. The default is the Njordium demo instance.') }}
		</p>

		<div class="grid-form">
			<label for="instance_url">
				<span class="icon icon-link" />
				{{ t('integration_inspect360', 'Instance address') }}
			</label>
			<NcTextField
				id="instance_url"
				v-model="state.instance_url"
				:placeholder="defaultInstanceUrl"
				@input="onFieldChange" />
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { delay } from '../utils.js'

export default {
	name: 'AdminSettings',
	components: {
		NcTextField,
	},

	data() {
		const initial = loadState('integration_inspect360', 'admin-config', {})
		return {
			state: {
				instance_url: initial.instance_url ?? '',
			},
			defaultInstanceUrl: initial.default_instance_url ?? 'https://ymir.njordium.io',
		}
	},

	methods: {
		onFieldChange() {
			delay(this.saveConfig, 2000)()
		},

		async saveConfig() {
			const payload = {
				instance_url: (this.state.instance_url ?? '').trim().replace(/\/+$/, ''),
			}
			try {
				await axios.put(generateUrl('/apps/integration_inspect360/admin-config'), { values: payload })
				showSuccess(t('integration_inspect360', 'Inspect360 admin settings saved'))
			} catch (e) {
				const code = e?.response?.data?.error
				if (code === 'invalid_instance_url' || code === 'invalid_instance_url_scheme') {
					showError(t('integration_inspect360', 'Invalid instance URL — include https:// and a host.'))
				} else {
					showError(t('integration_inspect360', 'Failed to save admin settings'))
				}
			}
		},
	},
}
</script>

<style scoped lang="scss">
#inspect360_prefs {
	max-width: 720px;

	h2 {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-bottom: 8px;

		a {
			display: inline-block;
			width: 24px;
			height: 24px;
			background-size: contain;
			background-repeat: no-repeat;
			background-position: center;
		}
	}

	.settings-hint {
		margin: 8px 0 16px;
		color: var(--color-text-maxcontrast);
	}

	.grid-form {
		display: grid;
		grid-template-columns: max-content 1fr;
		column-gap: 12px;
		row-gap: 10px;
		align-items: center;

		label {
			display: flex;
			align-items: center;
			gap: 6px;
			white-space: nowrap;

			.icon {
				display: inline-block;
				width: 20px;
				height: 20px;
			}
		}
	}
}
</style>
