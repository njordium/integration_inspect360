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

		// Widget-facing API — Inspect360-specific endpoints, all still on the
		// carryover Forgejo/Gitea shape and pending replacement in Phase B.
		['name' => 'inspect360API#getRepos', 'url' => '/repos', 'verb' => 'GET'],
		['name' => 'inspect360API#getItems', 'url' => '/items', 'verb' => 'GET'],
		['name' => 'inspect360API#getIssues', 'url' => '/issues', 'verb' => 'GET'],
		['name' => 'inspect360API#getCommits', 'url' => '/commits', 'verb' => 'GET'],
		['name' => 'inspect360API#getMilestones', 'url' => '/milestones', 'verb' => 'GET'],
		['name' => 'inspect360API#getRepoStats', 'url' => '/repo-stats', 'verb' => 'GET'],
		['name' => 'inspect360API#getReviewRequests', 'url' => '/review-requests', 'verb' => 'GET'],
		['name' => 'inspect360API#getHeatmap', 'url' => '/heatmap', 'verb' => 'GET'],
		['name' => 'inspect360API#getStats', 'url' => '/stats', 'verb' => 'GET'],
		['name' => 'inspect360API#getNotifications', 'url' => '/notifications', 'verb' => 'GET'],
		['name' => 'inspect360API#markNotificationRead', 'url' => '/notifications/{threadId}', 'verb' => 'PATCH'],
		['name' => 'inspect360API#getInspect360Url', 'url' => '/url', 'verb' => 'GET'],
		['name' => 'inspect360API#getInspect360Avatar', 'url' => '/avatar', 'verb' => 'GET'],
	]
];
