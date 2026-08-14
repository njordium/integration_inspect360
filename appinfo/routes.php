<?php
/**
 * Nextcloud - Inspect360 integration
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 */

return [
    'routes' => [
        ['name' => 'config#oauthStart', 'url' => '/oauth-start', 'verb' => 'POST'],
        ['name' => 'config#oauthRedirect', 'url' => '/oauth-redirect', 'verb' => 'GET'],
        ['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
        ['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],
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
