<template>
	<div class="i360-widget i360-list">
		<div class="i360-toolbar">
			<NcActions :forceMenu="true">
				<NcActionButton @click="fetch">
					<template #icon>
						<RefreshIcon :size="20" />
					</template>
					{{ t('integration_inspect360', 'Refresh now') }}
				</NcActionButton>
				<NcActionButton @click="showSettings = !showSettings">
					<template #icon>
						<CogOutlineIcon :size="20" />
					</template>
					{{ showSettings
						? t('integration_inspect360', 'Hide widget settings')
						: t('integration_inspect360', 'Widget settings') }}
				</NcActionButton>
			</NcActions>
		</div>

		<div v-if="showSettings" class="i360-settings">
			<label class="i360-settings__label">{{ t('integration_inspect360', 'Refresh interval') }}</label>
			<RefreshIntervalPicker
				:modelValue="refreshIntervalSeconds"
				@update:modelValue="onRefreshChange" />
		</div>

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
			:title="t('integration_inspect360', 'No assessments yet')"
			:description="t('integration_inspect360', 'Vendor assessments will appear here as they are opened.')">
			<template #icon>
				<ClipboardCheckIcon :size="48" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<ul class="i360-rows">
				<li v-for="a in items" :key="a.id" class="i360-row">
					<a class="i360-row__link" :href="link('/assessments/' + a.id)" target="_blank" rel="noopener">
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
					</a>
				</li>
			</ul>

			<a :href="link('/assessments')" target="_blank" rel="noopener" class="i360-more">
				{{ t('integration_inspect360', 'Show all assessments') }}
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
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import ClipboardCheckIcon from 'vue-material-design-icons/ClipboardCheck.vue'
import CogOutlineIcon from 'vue-material-design-icons/CogOutline.vue'
import LinkOffIcon from 'vue-material-design-icons/LinkOff.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import RefreshIntervalPicker from '../components/RefreshIntervalPicker.vue'
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
		NcEmptyContent,
		NcLoadingIcon,
		RefreshIntervalPicker,
		AlertCircleOutlineIcon,
		ClipboardCheckIcon,
		CogOutlineIcon,
		LinkOffIcon,
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
				const { data } = await axios.get(generateUrl('/apps/integration_inspect360/assessments/recent'))
				this.items = Array.isArray(data.items) ? data.items : []
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

		finalRisk(a) {
			return a.final_risk_level || a.combined_risk_level || a.basic_tprm_risk_level || null
		},

		relativeTime(iso) {
			if (!iso) return ''
			const then = new Date(iso).getTime()
			const now = Date.now()
			const diff = Math.max(0, now - then)
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

		async onRefreshChange(seconds) {
			this.refreshIntervalSeconds = seconds
			this.autoRefresh?.setIntervalMs(seconds * 1000)
			try {
				await axios.put(
					generateUrl('/apps/integration_inspect360/widget/' + WIDGET_KEY + '/refresh-interval'),
					{ seconds },
				)
			} catch { /* silent */ }
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

.i360-settings {
	padding: 6px 8px 10px;
	border-bottom: 1px solid var(--color-border);

	&__label {
		display: block;
		font-size: 11px;
		text-transform: uppercase;
		letter-spacing: 0.4px;
		color: var(--color-text-maxcontrast);
		margin-bottom: 4px;
	}
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
	max-height: 380px;
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
 * Solid, high-contrast risk chips — mapped to fixed hues (green/orange/
 * red/dark-red) rather than theme variables so severity reads at a
 * glance regardless of the user's Nextcloud theme.
 */
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
	display: block;
	padding: 6px 8px;
	font-size: 12px;
	color: var(--color-primary-element);
	text-decoration: none;
	border-top: 1px solid var(--color-border);
	margin-top: 4px;

	&:hover { text-decoration: underline; }
}
</style>
