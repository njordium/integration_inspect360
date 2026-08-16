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
			:title="emptyTitle"
			:description="emptyDescription">
			<template #icon>
				<component :is="headerIconComponent" :size="48" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<ul class="rows">
				<li v-for="v in items" :key="v.id" class="row">
					<a class="row-main" :href="link('/vendors/' + v.id)" target="_blank" rel="noopener">
						<div class="row-title">{{ v.org_name || t('integration_inspect360', '(unnamed)') }}</div>
						<div class="row-sub">
							<span v-if="v.city">{{ v.city }}</span>
							<span v-if="v.city && v.country"> · </span>
							<span v-if="v.country">{{ v.country }}</span>
							<span v-if="v.org_number" class="dim"> · {{ v.org_number }}</span>
						</div>
					</a>
					<div class="row-side">
						<span class="status" :class="'status--' + v.status">{{ prettyStatus(v.status) }}</span>
						<div v-if="hasFlags(v)" class="flags">
							<span v-if="v.critical_supplier_flag" class="flag flag--critical" :title="t('integration_inspect360', 'Critical supplier')">C</span>
							<span v-if="v.ict_provider_flag" class="flag flag--ict" :title="t('integration_inspect360', 'ICT provider')">ICT</span>
							<span v-if="v.data_processor_flag" class="flag flag--dp" :title="t('integration_inspect360', 'Data processor')">DP</span>
							<span v-if="v.aml_regulated" class="flag flag--aml" :title="t('integration_inspect360', 'AML regulated')">AML</span>
						</div>
					</div>
				</li>
			</ul>

			<div class="footer">
				<a v-if="total > items.length" :href="link('/vendors' + (statusFilter ? '?status=' + statusFilter : ''))" target="_blank" rel="noopener" class="show-all">
					{{ t('integration_inspect360', 'Show all ({total})', { total }) }}
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
import AccountPlusIcon from 'vue-material-design-icons/AccountPlus.vue'
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import CogOutlineIcon from 'vue-material-design-icons/CogOutline.vue'
import LinkOffIcon from 'vue-material-design-icons/LinkOff.vue'
import ShieldCheckIcon from 'vue-material-design-icons/ShieldCheck.vue'
import RefreshIntervalPicker from '../components/RefreshIntervalPicker.vue'
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
		NcEmptyContent,
		NcLoadingIcon,
		RefreshIntervalPicker,
		AccountPlusIcon,
		AlertCircleOutlineIcon,
		CogOutlineIcon,
		LinkOffIcon,
		ShieldCheckIcon,
	},

	props: {
		endpoint: { type: String, required: true },     // e.g. 'vendors/approved' or 'vendors/added'
		widgetKey: { type: String, required: true },    // e.g. 'inspect360_approved_vendors'
		variant: { type: String, default: 'approved' }, // 'approved' | 'added' — drives icon + empty text + link filter
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

		hasFlags(v) {
			return v.critical_supplier_flag || v.ict_provider_flag || v.data_processor_flag || v.aml_regulated
		},

		prettyStatus(s) {
			return STATUS_LABELS[s] || s || ''
		},

		link(path) {
			return (this.instanceUrl || '') + path
		},

		async onRefreshChange(seconds) {
			this.refreshIntervalSeconds = seconds
			this.autoRefresh?.setIntervalMs(seconds * 1000)
			try {
				await axios.put(
					generateUrl('/apps/integration_inspect360/widget/' + this.widgetKey + '/refresh-interval'),
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
		display: flex;
		flex-direction: column;
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

	.status {
		display: inline-block;
		padding: 1px 8px;
		border-radius: 10px;
		font-size: 11px;
		font-weight: 500;
		background: var(--color-background-hover);
		color: var(--color-main-text);

		&.status--approved     { background: color-mix(in srgb, var(--color-success) 20%, transparent); color: var(--color-success); }
		&.status--under_review { background: color-mix(in srgb, var(--color-warning) 20%, transparent); color: var(--color-warning); }
		&.status--draft        { background: color-mix(in srgb, var(--color-primary-element) 15%, transparent); color: var(--color-primary-element); }
		&.status--archived     { opacity: 0.7; }
	}

	.flags {
		display: flex;
		gap: 2px;
	}

	.flag {
		display: inline-block;
		padding: 0 5px;
		font-size: 9px;
		font-weight: 700;
		border-radius: 3px;
		background: var(--color-background-hover);
		color: var(--color-text-maxcontrast);
		line-height: 14px;

		&.flag--critical { background: var(--color-error); color: white; }
		&.flag--ict      { background: var(--color-primary-element); color: white; }
		&.flag--dp       { background: var(--color-info, #6b7280); color: white; }
		&.flag--aml      { background: var(--color-warning); color: white; }
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
