<template>
	<div class="inspect360-list">
		<div v-if="loading && !loaded" class="state-line">
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
			<ul class="rows">
				<li v-for="a in items" :key="a.id" class="row">
					<a class="row-main" :href="link('/assessments/' + a.id)" target="_blank" rel="noopener">
						<div class="row-title">{{ a.supplier_name || t('integration_inspect360', '(unnamed vendor)') }}</div>
						<div class="row-sub">
							<span>{{ prettyStatus(a.status) }}</span>
							<span v-if="a.current_screen" class="dim"> · {{ a.current_screen }}</span>
							<span v-if="a.updated_at" class="dim"> · {{ relativeTime(a.updated_at) }}</span>
						</div>
					</a>
					<div class="row-side">
						<span v-if="finalRisk(a)" class="risk" :class="'risk--' + finalRisk(a).toLowerCase()">
							{{ finalRisk(a) }}
						</span>
						<span v-if="a.decision" class="decision">{{ a.decision }}</span>
					</div>
				</li>
			</ul>

			<div class="footer">
				<a :href="link('/assessments')" target="_blank" rel="noopener" class="show-all">
					{{ t('integration_inspect360', 'Show all assessments') }}
				</a>
				<button class="settings-toggle" @click="showSettings = !showSettings">
					<CogOutlineIcon :size="16" />
					{{ t('integration_inspect360', 'Refresh') }}
				</button>
			</div>
			<div v-if="showSettings" class="settings-body">
				<RefreshIntervalPicker
					:modelValue="refreshIntervalSeconds"
					@update:modelValue="onRefreshChange" />
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import ClipboardCheckIcon from 'vue-material-design-icons/ClipboardCheck.vue'
import CogOutlineIcon from 'vue-material-design-icons/CogOutline.vue'
import LinkOffIcon from 'vue-material-design-icons/LinkOff.vue'
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
		NcEmptyContent,
		NcLoadingIcon,
		RefreshIntervalPicker,
		AlertCircleOutlineIcon,
		ClipboardCheckIcon,
		CogOutlineIcon,
		LinkOffIcon,
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
.inspect360-list {
	padding: 4px 8px 8px;

	.state-line {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 12px 4px;
		color: var(--color-text-maxcontrast);
	}

	.rows {
		list-style: none;
		padding: 0;
		margin: 0;
	}

	.row {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 8px 4px;
		border-bottom: 1px solid var(--color-border);

		&:last-child { border-bottom: none; }
	}

	.row-main {
		flex: 1;
		min-width: 0;
		text-decoration: none;
		color: inherit;
	}

	.row-title {
		font-weight: 500;
		font-size: 14px;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.row-sub {
		font-size: 12px;
		color: var(--color-text-maxcontrast);
		margin-top: 2px;

		.dim { opacity: 0.7; }
	}

	.row-side {
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		gap: 4px;
		flex-shrink: 0;
	}

	.risk {
		display: inline-block;
		padding: 1px 8px;
		border-radius: 10px;
		font-size: 11px;
		font-weight: 600;
		background: var(--color-background-hover);
		color: var(--color-main-text);
		text-transform: uppercase;
		letter-spacing: 0.3px;

		&.risk--low      { background: color-mix(in srgb, var(--color-success) 20%, transparent); color: var(--color-success); }
		&.risk--medium   { background: color-mix(in srgb, var(--color-warning) 20%, transparent); color: var(--color-warning); }
		&.risk--high     { background: color-mix(in srgb, var(--color-error) 20%, transparent); color: var(--color-error); }
		&.risk--critical { background: var(--color-error); color: white; }
	}

	.decision {
		font-size: 10px;
		color: var(--color-text-maxcontrast);
	}

	.footer {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
		margin-top: 8px;
		padding-top: 6px;
		border-top: 1px solid var(--color-border);
	}

	.show-all {
		font-size: 12px;
		color: var(--color-primary-element);
		text-decoration: none;

		&:hover { text-decoration: underline; }
	}

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
		margin-left: auto;

		&:hover { background: var(--color-background-hover); }
	}

	.settings-body {
		margin-top: 8px;
		max-width: 260px;
	}
}
</style>
