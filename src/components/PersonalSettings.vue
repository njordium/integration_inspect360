<template>
	<div id="inspect360_prefs" class="section">
		<h2>
			<a class="icon icon-inspect360" />
			{{ t('integration_inspect360', 'Inspect360 integration') }}
		</h2>

		<p class="settings-hint">
			{{ t('integration_inspect360', 'Instance:') }}
			<code>{{ state.instance_url }}</code>
		</p>

		<div v-if="!state.connected" class="signin-form">
			<p class="settings-hint">
				{{ t('integration_inspect360', 'Sign in to Inspect360 with your account. Only the returned refresh token is stored.') }}
			</p>

			<div class="grid-form">
				<label for="i360-email">
					<span class="icon icon-mail" />
					{{ t('integration_inspect360', 'Email') }}
				</label>
				<NcTextField
					id="i360-email"
					v-model="email"
					type="email"
					autocomplete="username"
					:disabled="loading"
					:placeholder="t('integration_inspect360', 'you@example.com')" />

				<label for="i360-password">
					<span class="icon icon-password" />
					{{ t('integration_inspect360', 'Password') }}
				</label>
				<NcPasswordField
					id="i360-password"
					v-model="password"
					autocomplete="current-password"
					:disabled="loading"
					@keyup.enter="onSignIn" />
			</div>

			<div class="actions">
				<NcButton
					variant="primary"
					:disabled="!canSubmit"
					@click="onSignIn">
					<template #icon>
						<LoginIcon :size="20" />
					</template>
					{{ t('integration_inspect360', 'Sign in') }}
				</NcButton>
			</div>

			<NcNoteCard v-if="policyBlock" type="warning" class="policy-note">
				{{ policyBlockMessage }}
			</NcNoteCard>
		</div>

		<div v-else class="connected-view">
			<div class="actions">
				<span class="connected">
					<CheckCircleIcon :size="20" class="connected-icon" />
					<span>
						{{ t('integration_inspect360', 'Connected as {email}', { email: state.email }) }}
						<span v-if="state.role" class="role-chip">{{ formattedRole }}</span>
					</span>
				</span>
				<NcButton variant="secondary" :disabled="loading" @click="onDisconnect">
					<template #icon>
						<LogoutIcon :size="20" />
					</template>
					{{ t('integration_inspect360', 'Disconnect') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import LoginIcon from 'vue-material-design-icons/Login.vue'
import LogoutIcon from 'vue-material-design-icons/Logout.vue'

export default {
	name: 'PersonalSettings',
	components: {
		NcButton,
		NcNoteCard,
		NcPasswordField,
		NcTextField,
		CheckCircleIcon,
		LoginIcon,
		LogoutIcon,
	},

	data() {
		const s = loadState('integration_inspect360', 'user-config', {})
		return {
			state: {
				connected: !!s.connected,
				email: s.email || '',
				role: s.role || '',
				instance_url: s.instance_url || '',
			},

			email: '',
			password: '',
			loading: false,
			policyBlock: null,
		}
	},

	computed: {
		canSubmit() {
			return !this.loading
				&& this.email.trim().length > 0
				&& this.password.length > 0
		},

		formattedRole() {
			// snake_case → Title Case, matches the human-readable role names
			// (Vendor Manager, Compliance Manager, …) from Inspect360.
			return (this.state.role || '')
				.split('_')
				.filter(Boolean)
				.map((w) => w.charAt(0).toUpperCase() + w.slice(1))
				.join(' ')
		},

		policyBlockMessage() {
			const b = this.policyBlock
			if (b === 'mfa_required') {
				return t('integration_inspect360', 'This account has multi-factor authentication enabled. MFA-protected accounts are not supported in this release. Connect a service account without MFA, or wait for a future release.')
			}
			if (b === 'mfa_enrollment_required') {
				return t('integration_inspect360', 'This account must enrol in multi-factor authentication before it can be used. Complete enrolment in Inspect360, then try connecting again from an MFA-exempt account.')
			}
			if (b === 'must_change_password') {
				return t('integration_inspect360', 'This account has a pending action in Inspect360. Complete it there, then sign in again here.')
			}
			if (b === 'admin_not_configured') {
				return t('integration_inspect360', 'The Inspect360 instance URL has not been set by your administrator. Ask them to configure it under Administration → Connected accounts.')
			}
			if (b === 'rate_limited') {
				return t('integration_inspect360', 'Too many sign-in attempts. Wait a few minutes and try again.')
			}
			return ''
		},
	},

	methods: {
		async onSignIn() {
			if (!this.canSubmit) { return }
			this.loading = true
			this.policyBlock = null
			try {
				const response = await axios.post(
					generateUrl('/apps/integration_inspect360/login'),
					{ email: this.email.trim(), password: this.password },
				)
				const data = response.data || {}
				if (data.status === 'ok') {
					this.state.connected = true
					this.state.email = data.email || this.email.trim()
					this.state.role = data.role || ''
					this.password = ''
					showSuccess(t('integration_inspect360', 'Connected to Inspect360.'))
				} else {
					this.policyBlock = data.status
				}
			} catch (e) {
				const status = e?.response?.data?.status
				const httpStatus = e?.response?.status
				if (status === 'invalid_credentials' || httpStatus === 401) {
					showError(t('integration_inspect360', 'Sign-in failed. Please verify your credentials and try again.'))
				} else if (status && status !== 'ok') {
					this.policyBlock = status
				} else {
					showError(t('integration_inspect360', 'Sign-in failed. Please try again.'))
				}
			} finally {
				this.loading = false
			}
		},

		async onDisconnect() {
			this.loading = true
			try {
				await axios.post(generateUrl('/apps/integration_inspect360/disconnect'))
				this.state.connected = false
				this.state.email = ''
				this.state.role = ''
				showSuccess(t('integration_inspect360', 'Disconnected.'))
			} catch {
				showError(t('integration_inspect360', 'Failed to disconnect.'))
			} finally {
				this.loading = false
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
		margin: 12px 0;
		color: var(--color-text-maxcontrast);

		code {
			padding: 2px 6px;
			background: var(--color-background-hover);
			border-radius: var(--border-radius);
			font-size: 13px;
		}
	}

	.grid-form {
		display: grid;
		grid-template-columns: max-content 1fr;
		column-gap: 12px;
		row-gap: 10px;
		align-items: center;
		max-width: 480px;
		margin-top: 12px;

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

	.actions {
		display: flex;
		align-items: center;
		gap: 12px;
		margin-top: 16px;
		flex-wrap: wrap;

		.connected {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			font-weight: 500;

			.connected-icon {
				color: var(--color-success);
			}

			.role-chip {
				display: inline-block;
				margin-inline-start: 6px;
				padding: 1px 8px;
				border-radius: 10px;
				background: var(--color-background-hover);
				font-size: 12px;
				font-weight: 400;
			}
		}
	}

	.policy-note {
		margin-top: 16px;
	}
}
</style>
