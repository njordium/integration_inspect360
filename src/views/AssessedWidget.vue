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
			:maxItems="maxItems"
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

		<div v-else-if="!items.length" class="i360-status">
			<CheckCircleOutlineIcon :size="40" class="i360-status__icon" />
			<span>{{ t('integration_inspect360', 'No assessments yet — new ones will appear here.') }}</span>
		</div>

		<template v-else>
			<ul class="i360-rows">
				<li v-for="a in items" :key="a.id" class="i360-row">
					<a class="i360-row__link" :href="link('/assessments/' + a.id)" target="_blank" rel="noopener">
						<span class="i360-badge" :class="'i360-badge--' + badgeVariant(a.status, finalRisk(a))">
							<component :is="badgeIcon(a.status)" :size="16" />
						</span>
						<div class="i360-row__body">
							<div class="i360-row__top">
								<span class="i360-row__title">{{ a.supplier_name || t('integration_inspect360', '(unnamed vendor)') }}</span>
								<span v-if="finalRisk(a)" class="i360-risk" :class="'i360-risk--' + finalRisk(a).toLowerCase()">
									{{ finalRisk(a) }}
								</span>
							</div>
							<div class="i360-row__meta">
								<span class="i360-row__meta-item">{{ prettyStatus(a.status) }}</span>
								<span v-if="a.current_screen" class="i360-row__meta-item i360-row__meta-item--dim">{{ a.current_screen }}</span>
								<span v-if="a.updated_at" class="i360-row__meta-item i360-row__meta-item--dim">{{ relativeTime(a.updated_at) }}</span>
								<span v-if="a.decision" class="i360-chip i360-chip--decision">{{ a.decision }}</span>
							</div>
						</div>
					</a>
				</li>
			</ul>

			<a :href="link('/assessments')" target="_blank" rel="noopener" class="i360-more">
				<span>{{ t('integration_inspect360', 'Show all') }}</span>
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
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import CheckCircleOutlineIcon from 'vue-material-design-icons/CheckCircleOutline.vue'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import CloseCircleIcon from 'vue-material-design-icons/CloseCircle.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import LinkOffIcon from 'vue-material-design-icons/LinkOff.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import WidgetSettingsModal from '../components/WidgetSettingsModal.vue'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'

const WIDGET_KEY = 'inspect360_assessed'

const STATUS_LABELS = {
	in_progress: 'In progress',
	completed: 'Completed',
	pending_review: 'Pending review',
	approved: 'Approved',
	rejected: 'Rejected',
}

export default {
	name: 'AssessedWidget',
	components: {
		NcActionButton,
		NcActions,
		NcLoadingIcon,
		WidgetSettingsModal,
		AlertCircleOutlineIcon,
		CheckCircleIcon,
		CheckCircleOutlineIcon,
		ClockOutlineIcon,
		CloseCircleIcon,
		CogIcon,
		LinkOffIcon,
		OpenInNewIcon,
		RefreshIcon,
	},

	data() {
		return {
			loading: false,
			loaded: false,
			notConnected: false,
			hardError: null,
			items: [],
			instanceUrl: '',
			refreshIntervalSeconds: 300,
			maxItems: 10,
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
				const { data } = await axios.get(generateUrl('/apps/integration_inspect360/assessments/recent'))
				this.items = Array.isArray(data.items) ? data.items : []
				this.instanceUrl = data.instance_url || ''
				const newInterval = Number(data.config?.refresh_interval_seconds) || 300
				if (newInterval !== this.refreshIntervalSeconds) {
					this.refreshIntervalSeconds = newInterval
					this.autoRefresh?.setIntervalMs(newInterval * 1000)
				}
				const newMax = Number(data.config?.max_items) || 10
				if (newMax !== this.maxItems) {
					this.maxItems = newMax
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

		finalRisk(a) {
			return a.final_risk_level || a.combined_risk_level || a.basic_tprm_risk_level || null
		},

		badgeVariant(status, risk) {
			if (status === 'approved' || status === 'completed') return 'approved'
			if (status === 'rejected') return 'issues'
			if (risk && (risk.toLowerCase() === 'high' || risk.toLowerCase() === 'critical')) return 'issues'
			if (status === 'in_progress' || status === 'pending_review') return 'review'
			return 'draft'
		},

		badgeIcon(status) {
			if (status === 'approved' || status === 'completed') return CheckCircleIcon
			if (status === 'rejected') return CloseCircleIcon
			return ClockOutlineIcon
		},

		relativeTime(iso) {
			if (!iso) return ''
			const then = new Date(iso).getTime()
			if (!then) return ''
			const diff = Math.max(0, Date.now() - then)
			const min = Math.floor(diff / 60000)
			if (min < 1) return t('integration_inspect360', 'just now')
			if (min < 60) return t('integration_inspect360', '{n} min ago', { n: min })
			const h = Math.floor(min / 60)
			if (h < 24) return t('integration_inspect360', '{n}h ago', { n: h })
			const d = Math.floor(h / 24)
			if (d < 7) return t('integration_inspect360', '{n}d ago', { n: d })
			return new Date(iso).toLocaleDateString()
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
				if (payload.maxItems !== null) {
					this.maxItems = payload.maxItems
				}
				this.showSettings = false
				this.fetch()
			} catch { /* silent */ }
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
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 12px;
	padding: 32px 12px;
	color: var(--color-text-maxcontrast);
	text-align: center;

	&__icon {
		opacity: 0.5;
	}

	&--error {
		color: var(--color-error);

		.i360-status__icon { opacity: 1; }
	}
}

.i360-rows {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	// ~7 rows fit before the internal scrollbar appears — keeps "Show all"
	// inside the dashboard chrome for any max_items setting.
	max-height: 322px;
	overflow-y: auto;
	overflow-x: hidden;
}

.i360-row {
	border-radius: var(--border-radius);

	&:hover {
		background: var(--color-background-hover);
	}
}

.i360-row__link {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 6px 8px;
	color: inherit;
	text-decoration: none;
	min-width: 0;
}

.i360-badge {
	flex-shrink: 0;
	width: 28px;
	height: 28px;
	display: grid;
	place-items: center;
	border-radius: 50%;
	color: white;

	&--approved { background: #16a34a; }
	&--review   { background: #ea580c; }
	&--draft    { background: #6b7280; }
	&--archived { background: #4b5563; }
	&--issues   { background: #dc2626; }
}

.i360-row__body {
	flex: 1;
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
	margin-top: 2px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	min-width: 0;
}

.i360-row__meta-item {
	white-space: nowrap;

	&--dim { opacity: 0.7; }
}

.i360-risk {
	flex-shrink: 0;
	display: inline-block;
	padding: 1px 8px;
	border-radius: 10px;
	font-size: 10px;
	font-weight: 700;
	line-height: 16px;
	text-transform: uppercase;
	letter-spacing: 0.4px;
	white-space: nowrap;
	background: var(--color-background-hover);
	color: var(--color-main-text);

	&--low      { background: #16a34a; color: white; }
	&--medium   { background: #ea580c; color: white; }
	&--high     { background: #dc2626; color: white; }
	&--critical { background: #7f1d1d; color: white; }
}

.i360-chip {
	flex-shrink: 0;
	display: inline-block;
	padding: 1px 8px;
	border-radius: 10px;
	font-size: 10px;
	font-weight: 600;
	line-height: 16px;
	white-space: nowrap;

	&--decision { background: #2563eb; color: white; }
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
