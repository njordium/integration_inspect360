<template>
	<div class="i360-widget i360-list">
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
			:description="t('integration_inspect360', 'Sign in to Inspect360 from Personal Settings.')">
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

		<NcEmptyContent
			v-else-if="loaded && items.length === 0"
			:title="emptyTitle"
			:description="emptyDescription">
			<template #icon>
				<component :is="headerIconComponent" :size="48" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<ul class="i360-rows">
				<li v-for="v in items" :key="v.id" class="i360-row">
					<a class="i360-row__link" :href="link('/vendors/' + v.id)" target="_blank" rel="noopener">
						<div class="i360-row__top">
							<span class="i360-row__title">{{ v.org_name || t('integration_inspect360', '(unnamed)') }}</span>
							<span class="i360-chip" :class="'i360-chip--' + v.status">{{ prettyStatus(v.status) }}</span>
						</div>
						<div class="i360-row__meta">
							<span v-if="v.city" class="i360-row__meta-item">{{ v.city }}</span>
							<span v-if="v.country" class="i360-row__meta-item">{{ v.country }}</span>
							<span v-if="v.org_number" class="i360-row__meta-item i360-row__meta-item--dim">{{ v.org_number }}</span>
							<span v-if="v.critical_supplier_flag" class="i360-flag i360-flag--critical" :title="t('integration_inspect360', 'Critical supplier')">C</span>
							<span v-if="v.ict_provider_flag" class="i360-flag i360-flag--ict" :title="t('integration_inspect360', 'ICT provider')">ICT</span>
							<span v-if="v.data_processor_flag" class="i360-flag i360-flag--dp" :title="t('integration_inspect360', 'Data processor')">DP</span>
							<span v-if="v.aml_regulated" class="i360-flag i360-flag--aml" :title="t('integration_inspect360', 'AML regulated')">AML</span>
						</div>
					</a>
				</li>
			</ul>

			<a
				v-if="total > items.length"
				:href="link('/vendors' + (statusFilter ? '?status=' + statusFilter : ''))"
				target="_blank"
				rel="noopener"
				class="i360-more">
				<span>{{ t('integration_inspect360', 'Show all ({total})', { total }) }}</span>
				<OpenInNewIcon :size="14" />
			</a>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AccountPlusIcon from 'vue-material-design-icons/AccountPlus.vue'
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import LinkOffIcon from 'vue-material-design-icons/LinkOff.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import ShieldCheckIcon from 'vue-material-design-icons/ShieldCheck.vue'
import WidgetSettingsModal from '../components/WidgetSettingsModal.vue'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'

const STATUS_LABELS = {
	approved: 'Approved',
	draft: 'Draft',
	under_review: 'Under review',
	archived: 'Archived',
}

export default {
	name: 'VendorsListWidget',
	components: {
		NcActionButton,
		NcActions,
		NcEmptyContent,
		NcLoadingIcon,
		WidgetSettingsModal,
		AccountPlusIcon,
		AlertCircleOutlineIcon,
		CogIcon,
		LinkOffIcon,
		OpenInNewIcon,
		RefreshIcon,
		ShieldCheckIcon,
	},

	props: {
		endpoint: { type: String, required: true },
		widgetKey: { type: String, required: true },
		variant: { type: String, default: 'approved' },
	},

	data() {
		return {
			loading: false,
			loaded: false,
			notConnected: false,
			hardError: null,
			items: [],
			total: 0,
			instanceUrl: '',
			refreshIntervalSeconds: 300,
			showSettings: false,
			savingSettings: false,
			autoRefresh: null,
		}
	},

	computed: {
		headerIconComponent() {
			return this.variant === 'added' ? AccountPlusIcon : ShieldCheckIcon
		},

		statusFilter() {
			return this.variant === 'approved' ? 'approved' : ''
		},

		emptyTitle() {
			return this.variant === 'added'
				? t('integration_inspect360', 'No vendors added yet')
				: t('integration_inspect360', 'No approved vendors')
		},

		emptyDescription() {
			return this.variant === 'added'
				? t('integration_inspect360', 'Newly added vendors will appear here.')
				: t('integration_inspect360', 'Vendors that pass approval will appear here.')
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
				const { data } = await axios.get(generateUrl('/apps/integration_inspect360/' + this.endpoint))
				this.items = Array.isArray(data.items) ? data.items : []
				this.total = Number(data.total) || 0
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

		prettyStatus(s) {
			return STATUS_LABELS[s] || s || ''
		},

		link(path) {
			return (this.instanceUrl || '') + path
		},

		async onSaveSettings(seconds) {
			this.savingSettings = true
			try {
				await axios.put(
					generateUrl('/apps/integration_inspect360/widget/' + this.widgetKey + '/refresh-interval'),
					{ seconds },
				)
				this.refreshIntervalSeconds = seconds
				this.autoRefresh?.setIntervalMs(seconds * 1000)
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
	overflow: hidden;
	max-height: 500px;
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

.i360-rows {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	// No max-height / internal scroll — v0.3.2 caps rows at 7 upstream, so
	// the full list always fits and anything more goes via "Show all".
}

.i360-row {
	border-radius: var(--border-radius);

	&:hover {
		background: var(--color-background-hover);
	}
}

.i360-row__link {
	display: block;
	padding: 6px 8px;
	color: inherit;
	text-decoration: none;
	min-width: 0;
}

.i360-row__top {
	display: flex;
	align-items: center;
	gap: 6px;
	min-width: 0;
}

.i360-row__title {
	flex: 1;
	min-width: 0;
	font-weight: 500;
	font-size: 13px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.i360-row__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 6px;
	margin-top: 3px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	min-width: 0;
}

.i360-row__meta-item {
	white-space: nowrap;

	&--dim { opacity: 0.7; }
}

/*
 * Solid, high-contrast chips — the earlier translucent tint (~20% alpha) was
 * near-invisible on Nextcloud's grey widget background. Fixed hues over
 * theme variables so contrast is consistent in both light and dark mode.
 */
.i360-chip {
	flex-shrink: 0;
	display: inline-block;
	padding: 1px 8px;
	border-radius: 10px;
	font-size: 10px;
	font-weight: 600;
	line-height: 16px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	white-space: nowrap;

	&--approved     { background: #16a34a; color: white; border-color: transparent; }
	&--under_review { background: #ea580c; color: white; border-color: transparent; }
	&--draft        { background: #6b7280; color: white; border-color: transparent; }
	&--archived     { background: var(--color-background-hover); color: var(--color-text-maxcontrast); }
}

.i360-flag {
	flex-shrink: 0;
	display: inline-block;
	padding: 0 5px;
	font-size: 9px;
	font-weight: 700;
	border-radius: 3px;
	line-height: 14px;
	white-space: nowrap;

	&--critical { background: #dc2626; color: white; }
	&--ict      { background: #2563eb; color: white; }
	&--dp       { background: #7c3aed; color: white; }
	&--aml      { background: #d97706; color: white; }
}

.i360-more {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 4px;
	padding: 8px;
	font-size: 12px;
	color: var(--color-primary-element);
	text-decoration: none;
	border-top: 1px solid var(--color-border);
	margin-top: 4px;

	&:hover span { text-decoration: underline; }
}
</style>
