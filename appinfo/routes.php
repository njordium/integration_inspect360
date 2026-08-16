<?php
/**
 * Nextcloud - Inspect360 integration
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 */

return [
	'routes' => [
		// Config surfaces — per-user preferences + admin settings.
		['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],

		// Authentication — password-login interim until Inspect360 exposes OAuth 2.0.
		['name' => 'config#credentialLogin', 'url' => '/login', 'verb' => 'POST'],
		['name' => 'config#disconnect', 'url' => '/disconnect', 'verb' => 'POST'],
		['name' => 'config#connectionStatus', 'url' => '/connection-status', 'verb' => 'GET'],

		// Widget-facing endpoints — one per dashboard widget, plus shared helpers.
		['name' => 'inspect360API#getOverview', 'url' => '/overview', 'verb' => 'GET'],
		['name' => 'inspect360API#getApprovedVendors', 'url' => '/vendors/approved', 'verb' => 'GET'],
		['name' => 'inspect360API#getAddedVendors', 'url' => '/vendors/added', 'verb' => 'GET'],
		['name' => 'inspect360API#getAssessed', 'url' => '/assessments/recent', 'verb' => 'GET'],
		['name' => 'inspect360API#getInstanceInfo', 'url' => '/instance-info', 'verb' => 'GET'],
		['name' => 'inspect360API#setRefreshInterval', 'url' => '/widget/{widgetKey}/refresh-interval', 'verb' => 'PUT'],
	]
];
