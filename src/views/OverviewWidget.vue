<template>
	<div class="inspect360-overview">
		<div v-if="loading && !loaded" class="state-line">
			<NcLoadingIcon :size="20" />
			<span>{{ t('integration_inspect360', 'Loading…') }}</span>
		</div>

		<NcEmptyContent
			v-else-if="notConnected"
			:title="t('integration_inspect360', 'Not connected')"
			:description="t('integration_inspect360', 'Sign in to Inspect360 from Personal Settings → Connected accounts to see your vendors.')">
			<template #icon>
				<LinkOffIcon :size="48" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="hardError"
			:title="t('integration_inspect360', 'Inspect360 unavailable')"
			:description="hardError">
			<template #icon>
				<AlertCircleOutlineIcon :size="48" />
			</template>
		</NcEmptyContent>

		<div v-else class="tiles">
			<a class="tile" :href="link('/vendors?status=approved')" target="_blank" rel="noopener">
				<div class="tile-icon tile-icon--approved">
					<CheckDecagramIcon :size="28" />
				</div>
				<div class="tile-body">
					<div class="tile-value">{{ tiles.approved_vendors }}</div>
					<div class="tile-label">{{ t('integration_inspect360', 'Approved Vendors') }}</div>
				</div>
			</a>

			<a class="tile" :href="link('/vendors')" target="_blank" rel="noopener">
				<div class="tile-icon tile-icon--total">
					<DomainIcon :size="28" />
				</div>
				<div class="tile-body">
					<div class="tile-value">{{ tiles.total_vendors }}</div>
					<div class="tile-label">{{ t('integration_inspect360', 'Total Vendors') }}</div>
				</div>
			</a>

			<a class="tile" :href="link('/vendors?status=under_review')" target="_blank" rel="noopener">
				<div class="tile-icon tile-icon--pending">
					<EyeOutlineIcon :size="28" />
				</div>
				<div class="tile-body">
					<div class="tile-value">{{ tiles.pending_review }}</div>
					<div class="tile-label">{{ t('integration_inspect360', 'Pending Review') }}</div>
				</div>
			</a>

			<a class="tile" :href="link('/services')" target="_blank" rel="noopener">
				<div class="tile-icon tile-icon--services">
					<PackageVariantIcon :size="28" />
				</div>
				<div class="tile-body">
					<div class="tile-value">{{ tiles.total_services }}</div>
					<div class="tile-label">{{ t('integration_inspect360', 'Total Services') }}</div>
				</div>
			</a>
		</div>

		<div v-if="loaded" class="footer">
			<button class="settings-toggle" @click="showSettings = !showSettings">
				<CogOutlineIcon :size="16" />
				{{ t('integration_inspect360', 'Refresh interval') }}
			</button>
			<div v-if="showSettings" class="settings-body">
				<RefreshIntervalPicker
					:modelValue="refreshIntervalSeconds"
					@update:modelValue="onRefreshChange" />
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import CogOutlineIcon from 'vue-material-design-icons/CogOutline.vue'
import DomainIcon from 'vue-material-design-icons/Domain.vue'
import EyeOutlineIcon from 'vue-material-design-icons/EyeOutline.vue'
import LinkOffIcon from 'vue-material-design-icons/LinkOff.vue'
import PackageVariantIcon from 'vue-material-design-icons/PackageVariant.vue'
import RefreshIntervalPicker from '../components/RefreshIntervalPicker.vue'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'

const WIDGET_KEY = 'inspect360_overview'

export default {
	name: 'OverviewWidget',
	components: {
		NcEmptyContent,
		NcLoadingIcon,
		RefreshIntervalPicker,
		AlertCircleOutlineIcon,
		CheckDecagramIcon,
		CogOutlineIcon,
		DomainIcon,
		EyeOutlineIcon,
		LinkOffIcon,
		PackageVariantIcon,
	},

	data() {
		return {
			loading: false,
			loaded: false,
			notConnected: false,
			hardError: null,
			tiles: { approved_vendors: 0, total_vendors: 0, pending_review: 0, total_services: 0 },
			instanceUrl: '',
			refreshIntervalSeconds: 300,
			showSettings: false,
			autoRefresh: null,
		}
	},

	mounted() {
		this.fetch()
		this.autoRefresh = useAutoRefresh(this.fetch, this.refreshIntervalSeconds * 1000)
	},

	methods: {
		async fetch() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/integration_inspect360/overview'))
				this.tiles = data.tiles || this.tiles
				this.instanceUrl = data.instance_url || ''
				const newInterval = Number(data.config?.refresh_interval_seconds) || 300
				if (newInterval !== this.refreshIntervalSeconds) {
					this.refreshIntervalSeconds = newInterval
					this.autoRefresh?.setIntervalMs(newInterval * 1000)
				}
				this.notConnected = false
				this.hardError = null
			} catch (e) {
				const status = e?.response?.status
				const err = e?.response?.data?.error
				if (status === 401 || err === 'not_connected' || err === 'no_session') {
					this.notConnected = true
				} else if (err === 'admin_not_configured') {
					this.hardError = t('integration_inspect360', 'Administrator has not configured the Inspect360 instance URL.')
				} else {
					this.hardError = t('integration_inspect360', 'Could not reach the Inspect360 API.')
				}
			} finally {
				this.loading = false
				this.loaded = true
			}
		},

		link(path) {
			return (this.instanceUrl || '') + path
		},

		async onRefreshChange(seconds) {
			this.refreshIntervalSeconds = seconds
			this.autoRefresh?.setIntervalMs(seconds * 1000)
			try {
				await axios.put(
					generateUrl('/apps/integration_inspect360/widget/' + WIDGET_KEY + '/refresh-interval'),
					{ seconds },
				)
			} catch { /* silent — user can retry, and next fetch reads server-side value */ }
		},
	},
}
</script>

<style scoped lang="scss">
.inspect360-overview {
	padding: 8px;

	.state-line {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 12px 4px;
		color: var(--color-text-maxcontrast);
	}

	.tiles {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 8px;
	}

	.tile {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 12px;
		border-radius: var(--border-radius-large);
		background: var(--color-background-hover);
		text-decoration: none;
		color: inherit;
		transition: background 120ms ease;

		&:hover, &:focus-visible {
			background: var(--color-primary-element-light);
			outline: none;
		}
	}

	.tile-icon {
		flex-shrink: 0;
		width: 44px;
		height: 44px;
		display: grid;
		place-items: center;
		border-radius: var(--border-radius);
		color: white;

		&.tile-icon--approved { background: var(--color-success); }
		&.tile-icon--total    { background: var(--color-primary-element); }
		&.tile-icon--pending  { background: var(--color-warning); }
		&.tile-icon--services { background: var(--color-info, #6b7280); }
	}

	.tile-body {
		min-width: 0;
	}

	.tile-value {
		font-size: 22px;
		font-weight: 700;
		line-height: 1.1;
	}

	.tile-label {
		font-size: 12px;
		color: var(--color-text-maxcontrast);
		margin-top: 2px;
	}

	.footer {
		margin-top: 12px;
		padding-top: 8px;
		border-top: 1px solid var(--color-border);

		.settings-toggle {
			display: inline-flex;
			align-items: center;
			gap: 4px;
			padding: 4px 8px;
			background: transparent;
			border: none;
			color: var(--color-text-maxcontrast);
			font-size: 12px;
			cursor: pointer;
			border-radius: var(--border-radius);

			&:hover { background: var(--color-background-hover); }
		}

		.settings-body {
			margin-top: 8px;
			max-width: 260px;
		}
	}
}
</style>
