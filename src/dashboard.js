/**
 * @copyright Copyright (c) 2026 Njordium
 * @license AGPL-3.0-or-later
 *
 * Single shared dashboard bundle. Registers every Inspect360 widget id.
 * All PHP widget classes point their load() at the same bundle name so this
 * file is loaded once regardless of how many widgets are on the dashboard.
 */
import { createApp } from 'vue'
import AssessedWidget from './views/AssessedWidget.vue'
import OverviewWidget from './views/OverviewWidget.vue'
import VendorsListWidget from './views/VendorsListWidget.vue'
import { applyGlobals } from './bootstrap.js'

const WIDGETS = [
	{ id: 'inspect360_overview', component: OverviewWidget, props: {} },
	{
		id: 'inspect360_approved_vendors',
		component: VendorsListWidget,
		props: { endpoint: 'vendors/approved', widgetKey: 'inspect360_approved_vendors', variant: 'approved' },
	},
	{
		id: 'inspect360_added_vendors',
		component: VendorsListWidget,
		props: { endpoint: 'vendors/added', widgetKey: 'inspect360_added_vendors', variant: 'added' },
	},
	{ id: 'inspect360_assessed', component: AssessedWidget, props: {} },
]

document.addEventListener('DOMContentLoaded', () => {
	for (const w of WIDGETS) {
		OCA.Dashboard.register(w.id, (el) => {
			const app = createApp(w.component, w.props)
			applyGlobals(app)
			app.mount(el)
		})
	}
})
