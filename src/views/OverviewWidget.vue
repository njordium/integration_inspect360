<template>
	<div class="i360-widget i360-overview">
		<div class="i360-toolbar">
			<NcActions :forceMenu="true">
				<NcActionButton @click="openSettings">
					<template #icon>
						<CogIcon :size="20" />
					</template>
					{{ t('integration_inspect360', 'Widget settings') }}
				</NcActionButton>
				<NcActionButton @click="fetch">
					<template #icon>
						<RefreshIcon :size="20" />
					</template>
					{{ t('integration_inspect360', 'Refresh now') }}
				</NcActionButton>
			</NcActions>
		</div>

		<WidgetSettingsModal
			v-if="showSettings"
			:refreshSeconds="refreshIntervalSeconds"
			:saving="savingSettings"
			@close="showSettings = false"
			@save="onSaveSettings" />

		<div v-if="loading && !loaded" class="i360-status">
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

		<div v-else class="i360-tiles">
			<a class="i360-tile" :href="link('/vendors?status=approved')" target="_blank" rel="noopener">
				<div class="i360-tile__icon i360-tile__icon--approved">
					<CheckDecagramIcon :size="24" />
				</div>
				<div class="i360-tile__body">
					<div class="i360-tile__value">{{ tiles.approved_vendors }}</div>
					<div class="i360-tile__label">{{ t('integration_inspect360', 'Approved') }}</div>
				</div>
			</a>

			<a class="i360-tile" :href="link('/vendors')" target="_blank" rel="noopener">
				<div class="i360-tile__icon i360-tile__icon--total">
					<DomainIcon :size="24" />
				</div>
				<div class="i360-tile__body">
					<div class="i360-tile__value">{{ tiles.total_vendors }}</div>
					<div class="i360-tile__label">{{ t('integration_inspect360', 'Total Vendors') }}</div>
				</div>
			</a>

			<a class="i360-tile" :href="link('/vendors?status=under_review')" target="_blank" rel="noopener">
				<div class="i360-tile__icon i360-tile__icon--pending">
					<EyeOutlineIcon :size="24" />
				</div>
				<div class="i360-tile__body">
					<div class="i360-tile__value">{{ tiles.pending_review }}</div>
					<div class="i360-tile__label">{{ t('integration_inspect360', 'Pending Review') }}</div>
				</div>
			</a>

			<a class="i360-tile" :href="link('/services')" target="_blank" rel="noopener">
				<div class="i360-tile__icon i360-tile__icon--services">
					<PackageVariantIcon :size="24" />
				</div>
				<div class="i360-tile__body">
					<div class="i360-tile__value">{{ tiles.total_services }}</div>
					<div class="i360-tile__label">{{ t('integration_inspect360', 'Total Services') }}</div>
				</div>
			</a>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import DomainIcon from 'vue-material-design-icons/Domain.vue'
import EyeOutlineIcon from 'vue-material-design-icons/EyeOutline.vue'
import LinkOffIcon from 'vue-material-design-icons/LinkOff.vue'
import PackageVariantIcon from 'vue-material-design-icons/PackageVariant.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import WidgetSettingsModal from '../components/WidgetSettingsModal.vue'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'

const WIDGET_KEY = 'inspect360_overview'

export default {
	name: 'OverviewWidget',
	components: {
		NcActionButton,
		NcActions,
		NcEmptyContent,
		NcLoadingIcon,
		WidgetSettingsModal,
		AlertCircleOutlineIcon,
		CheckDecagramIcon,
		CogIcon,
		DomainIcon,
		EyeOutlineIcon,
		LinkOffIcon,
		PackageVariantIcon,
		RefreshIcon,
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
			savingSettings: false,
			autoRefresh: null,
		}
	},

	mounted() {
		this.fetch()
		this.autoRefresh = useAutoRefresh(this.fetch, this.refreshIntervalSeconds * 1000)
	},

	methods: {
		openSettings() {
			this.showSettings = true
		},

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

		async onSaveSettings(payload) {
			this.savingSettings = true
			try {
				await axios.put(
					generateUrl('/apps/integration_inspect360/widget/' + WIDGET_KEY + '/preferences'),
					{
						refresh_seconds: payload.refreshSeconds,
						max_items: payload.maxItems,  // will be null — Overview has fixed 4 tiles
					},
				)
				this.refreshIntervalSeconds = payload.refreshSeconds
				this.autoRefresh?.setIntervalMs(payload.refreshSeconds * 1000)
				this.showSettings = false
			} catch { /* silent — user can retry from within the still-open modal */ }
			finally {
				this.savingSettings = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.i360-widget {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 4px 0;
}

.i360-toolbar {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	min-height: 32px;
	margin-top: -8px;
	margin-bottom: -4px;
}

.i360-status {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 16px 8px;
	color: var(--color-text-maxcontrast);
}

.i360-tiles {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
	padding: 8px 4px 4px;
}

.i360-tile {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 10px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
	text-decoration: none;
	color: inherit;
	transition: background 120ms ease;
	overflow: hidden;

	&:hover, &:focus-visible {
		background: var(--color-primary-element-light);
		outline: none;
	}
}

.i360-tile__icon {
	flex-shrink: 0;
	width: 36px;
	height: 36px;
	display: grid;
	place-items: center;
	border-radius: var(--border-radius);
	color: white;

	&--approved { background: #16a34a; }
	&--total    { background: var(--color-primary-element); }
	&--pending  { background: #ea580c; }
	&--services { background: #4b5563; }
}

.i360-tile__body {
	min-width: 0;
}

.i360-tile__value {
	font-size: 20px;
	font-weight: 700;
	line-height: 1.1;
	font-variant-numeric: tabular-nums;
}

.i360-tile__label {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
</style>
