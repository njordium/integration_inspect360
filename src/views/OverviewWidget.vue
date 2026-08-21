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
			<NcLoadingIcon :size="28" />
		</div>

		<div v-else-if="notConnected" class="i360-status">
			<LinkOffIcon :size="40" class="i360-status__icon" />
			<span>{{ t('integration_inspect360', 'Sign in to Inspect360 from Personal Settings.') }}</span>
		</div>

		<div v-else-if="hardError" class="i360-status i360-status--error">
			<AlertCircleOutlineIcon :size="40" class="i360-status__icon" />
			<span>{{ hardError }}</span>
		</div>

		<div v-else class="i360-tiles">
			<a
				v-for="tile in tileConfig"
				:key="tile.key"
				:href="tile.href ? link(tile.href) : '#'"
				:target="tile.href ? '_blank' : ''"
				:rel="tile.href ? 'noopener' : ''"
				class="i360-tile"
				:class="{ 'i360-tile--inert': !tile.href }">
				<div class="i360-tile__value">{{ tiles[tile.key] }}</div>
				<div class="i360-tile__label">{{ tile.label }}</div>
			</a>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import LinkOffIcon from 'vue-material-design-icons/LinkOff.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import WidgetSettingsModal from '../components/WidgetSettingsModal.vue'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'

const WIDGET_KEY = 'inspect360_overview'

export default {
	name: 'OverviewWidget',
	components: {
		NcActionButton,
		NcActions,
		NcLoadingIcon,
		WidgetSettingsModal,
		AlertCircleOutlineIcon,
		CogIcon,
		LinkOffIcon,
		RefreshIcon,
	},

	data() {
		return {
			loading: false,
			loaded: false,
			notConnected: false,
			hardError: null,
			tiles: {
				approved_vendors: 0,
				drafts: 0,
				pending_review: 0,
				archived: 0,
				active_vendors: 0,
				total_vendors: 0,
				total_services: 0,
				total_assessments: 0,
			},

			instanceUrl: '',
			refreshIntervalSeconds: 300,
			showSettings: false,
			savingSettings: false,
			autoRefresh: null,
		}
	},

	computed: {
		tileConfig() {
			return [
				{ key: 'approved_vendors', label: t('integration_inspect360', 'Approved vendors'), href: '/vendors?status=approved' },
				{ key: 'drafts', label: t('integration_inspect360', 'Draft vendors'), href: '/vendors?status=draft' },
				{ key: 'pending_review', label: t('integration_inspect360', 'Under review'), href: '/vendors?status=under_review' },
				{ key: 'archived', label: t('integration_inspect360', 'Archived'), href: '/vendors?status=archived' },
				{ key: 'active_vendors', label: t('integration_inspect360', 'Active vendors'), href: '/vendors' },
				{ key: 'total_vendors', label: t('integration_inspect360', 'Total vendors'), href: '/vendors' },
				{ key: 'total_services', label: t('integration_inspect360', 'Total services'), href: '/services' },
				{ key: 'total_assessments', label: t('integration_inspect360', 'Total assessments'), href: '/assessments' },
			]
		},
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
						max_items: payload.maxItems,
					},
				)
				this.refreshIntervalSeconds = payload.refreshSeconds
				this.autoRefresh?.setIntervalMs(payload.refreshSeconds * 1000)
				this.showSettings = false
			} catch { /* silent */ } finally {
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
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 12px;
	padding: 32px 12px;
	color: var(--color-text-maxcontrast);
	text-align: center;

	&__icon { opacity: 0.5; }
	// Error state keeps the icon red (signals "error") but text stays on
	// the main foreground colour — using `--color-error` for the text made
	// it read as pale pink on light theme and disappeared against the
	// widget's tinted background.
	&--error {
		color: var(--color-main-text);
		.i360-status__icon { color: var(--color-error); opacity: 1; }
	}
}

/*
 * Forgejo-style KPI grid — 2 cols x 4 rows. Each tile is a bordered
 * box with a big coloured number and a small greyed label below.
 * No per-tile icons, no per-tile colour differentiation — uniform
 * accent keeps the grid readable as a single at-a-glance surface.
 */
.i360-tiles {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
	padding: 8px 4px 4px;
}

.i360-tile {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 2px;
	padding: 10px 8px;
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	text-decoration: none;
	color: inherit;
	transition: background 120ms ease, border-color 120ms ease;
	min-height: 68px;

	&:hover, &:focus-visible {
		background: var(--color-background-hover);
		border-color: var(--color-primary-element);
		outline: none;
	}

	&--inert {
		pointer-events: none;
	}
}

.i360-tile__value {
	font-size: 26px;
	font-weight: 700;
	line-height: 1.1;
	color: #ea580c;
	font-variant-numeric: tabular-nums;
}

.i360-tile__label {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	text-align: center;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 100%;
}
</style>
