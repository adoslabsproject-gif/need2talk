<?php
/**
 * Moderation Portal Dashboard - need2talk Enterprise
 *
 * Dashboard con statistiche e azioni rapide
 *
 * @package Need2Talk\Views\Moderation
 */

use Need2Talk\Services\Moderation\ModerationSecurityService;

$modBaseUrl = ModerationSecurityService::generateModerationUrl();

// Stats are passed from controller
$stats = $stats ?? [];
$recentActions = $recentActions ?? [];
$pendingReports = $pendingReports ?? 0;
$activeBans = $activeBans ?? 0;
$onlineUsers = $onlineUsers ?? 0;
$activeRooms = $activeRooms ?? 0;
?>

<h1 style="font-size: 1.75rem; font-weight: 700; margin-bottom: 2rem; color: #ffffff;">
    Dashboard
</h1>

<!-- Stats Grid -->
<div class="mod-stats-grid">
    <div class="mod-stat-card">
        <div class="mod-stat-value"><?= number_format($onlineUsers) ?></div>
        <div class="mod-stat-label">Users Online</div>
    </div>
    <div class="mod-stat-card">
        <div class="mod-stat-value"><?= number_format($activeRooms) ?></div>
        <div class="mod-stat-label">Active Rooms</div>
    </div>
    <div class="mod-stat-card">
        <div class="mod-stat-value"><?= number_format($pendingReports) ?></div>
        <div class="mod-stat-label">Pending Reports</div>
    </div>
    <div class="mod-stat-card">
        <div class="mod-stat-value"><?= number_format($activeBans) ?></div>
        <div class="mod-stat-label">Active Bans</div>
    </div>
</div>

<!-- Quick Actions -->
<div class="mod-card">
    <div class="mod-card-header">
        <h2 class="mod-card-title">Quick Actions</h2>
    </div>

    <div style="display: flex; flex-wrap: wrap; gap: 1rem;">
        <a href="<?= htmlspecialchars($modBaseUrl) ?>/live" class="mod-btn mod-btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path d="M2 2.5a.5.5 0 0 0-1 0v11a.5.5 0 0 0 1 0v-11zm2-1a.5.5 0 0 1 .5.5v11a.5.5 0 0 1-1 0v-11a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v11a.5.5 0 0 0 1 0v-11zm2-1a.5.5 0 0 1 .5.5v11a.5.5 0 0 1-1 0v-11a.5.5 0 0 1 .5-.5zm3 1a.5.5 0 0 0-1 0v11a.5.5 0 0 0 1 0v-11zm2-1a.5.5 0 0 1 .5.5v11a.5.5 0 0 1-1 0v-11a.5.5 0 0 1 .5-.5z"/>
            </svg>
            Live Monitoring
        </a>

        <a href="<?= htmlspecialchars($modBaseUrl) ?>/reports" class="mod-btn mod-btn-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path d="M14.778.085A.5.5 0 0 1 15 .5V8a.5.5 0 0 1-.314.464L14.5 8l.186.464-.003.001-.006.003-.023.009a12.435 12.435 0 0 1-.397.15c-.264.095-.631.223-1.047.35-.816.252-1.879.523-2.71.523-.847 0-1.548-.28-2.158-.525l-.028-.01C7.68 8.71 7.14 8.5 6.5 8.5c-.7 0-1.638.23-2.437.477A19.626 19.626 0 0 0 3 9.342V15.5a.5.5 0 0 1-1 0V.5a.5.5 0 0 1 1 0v.282c.226-.079.496-.17.79-.26C4.606.272 5.67 0 6.5 0c.84 0 1.524.277 2.121.519l.043.018C9.286.788 9.828 1 10.5 1c.7 0 1.638-.23 2.437-.477a19.587 19.587 0 0 0 1.349-.476l.019-.007.004-.002h.001"/>
            </svg>
            View Reports (<?= $pendingReports ?>)
        </a>

        <a href="<?= htmlspecialchars($modBaseUrl) ?>/bans" class="mod-btn mod-btn-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                <path d="M11.354 4.646a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708l6-6a.5.5 0 0 1 .708 0z"/>
            </svg>
            Manage Bans
        </a>

        <a href="<?= htmlspecialchars($modBaseUrl) ?>/keywords" class="mod-btn mod-btn-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path d="M2.5 1a1 1 0 0 0-1 1v1a1 1 0 0 0 1 1H3v9a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V4h.5a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H10a1 1 0 0 0-1-1H7a1 1 0 0 0-1 1H2.5zm3 4a.5.5 0 0 1 .5.5v7a.5.5 0 0 1-1 0v-7a.5.5 0 0 1 .5-.5zM8 5a.5.5 0 0 1 .5.5v7a.5.5 0 0 1-1 0v-7A.5.5 0 0 1 8 5zm3 .5v7a.5.5 0 0 1-1 0v-7a.5.5 0 0 1 1 0z"/>
            </svg>
            Keywords
        </a>
    </div>
</div>

<!-- Ban Counts by Scope -->
<?php if (!empty($stats['banCounts'])): ?>
<div class="mod-card">
    <div class="mod-card-header">
        <h2 class="mod-card-title">Active Bans by Scope</h2>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 1rem;">
        <div style="text-align: center; padding: 1rem; background: rgba(239, 68, 68, 0.1); border-radius: 0.5rem;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #ef4444;">
                <?= $stats['banCounts']['global'] ?? 0 ?>
            </div>
            <div style="font-size: 0.75rem; color: #9ca3af; margin-top: 0.25rem;">Global</div>
        </div>
        <div style="text-align: center; padding: 1rem; background: rgba(251, 146, 60, 0.1); border-radius: 0.5rem;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #fb923c;">
                <?= $stats['banCounts']['chat'] ?? 0 ?>
            </div>
            <div style="font-size: 0.75rem; color: #9ca3af; margin-top: 0.25rem;">Chat</div>
        </div>
        <div style="text-align: center; padding: 1rem; background: rgba(250, 204, 21, 0.1); border-radius: 0.5rem;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #facc15;">
                <?= $stats['banCounts']['posts'] ?? 0 ?>
            </div>
            <div style="font-size: 0.75rem; color: #9ca3af; margin-top: 0.25rem;">Posts</div>
        </div>
        <div style="text-align: center; padding: 1rem; background: rgba(34, 197, 94, 0.1); border-radius: 0.5rem;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #22c55e;">
                <?= $stats['banCounts']['comments'] ?? 0 ?>
            </div>
            <div style="font-size: 0.75rem; color: #9ca3af; margin-top: 0.25rem;">Comments</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Recent Actions -->
<div class="mod-card">
    <div class="mod-card-header">
        <h2 class="mod-card-title">Recent Moderation Actions</h2>
        <a href="<?= htmlspecialchars($modBaseUrl) ?>/log" style="color: #d946ef; text-decoration: none; font-size: 0.875rem;">
            View All &rarr;
        </a>
    </div>

    <?php if (empty($recentActions)): ?>
    <div style="text-align: center; padding: 2rem; color: #6b7280;">
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" viewBox="0 0 16 16" style="margin: 0 auto 1rem; opacity: 0.5;">
            <path d="M8.515 1.019A7 7 0 0 0 8 1V0a8 8 0 0 1 .589.022l-.074.997zm2.004.45a7.003 7.003 0 0 0-.985-.299l.219-.976c.383.086.76.2 1.126.342l-.36.933zm1.37.71a7.01 7.01 0 0 0-.439-.27l.493-.87a8.025 8.025 0 0 1 .979.654l-.615.789a6.996 6.996 0 0 0-.418-.302zm1.834 1.79a6.99 6.99 0 0 0-.653-.796l.724-.69c.27.285.52.59.747.91l-.818.576zm.744 1.352a7.08 7.08 0 0 0-.214-.468l.893-.45a7.976 7.976 0 0 1 .45 1.088l-.95.313a7.023 7.023 0 0 0-.179-.483zm.53 2.507a6.991 6.991 0 0 0-.1-1.025l.985-.17c.067.386.106.778.116 1.17l-1 .025zm-.131 1.538c.033-.17.06-.339.081-.51l.993.123a7.957 7.957 0 0 1-.23 1.155l-.964-.267c.046-.165.086-.332.12-.501zm-.952 2.379c.184-.29.346-.594.486-.908l.914.405c-.16.36-.345.706-.555 1.038l-.845-.535zm-.964 1.205c.122-.122.239-.248.35-.378l.758.653a8.073 8.073 0 0 1-.401.432l-.707-.707z"/>
            <path d="M8 1a7 7 0 1 0 4.95 11.95l.707.707A8.001 8.001 0 1 1 8 0v1z"/>
            <path d="M7.5 3a.5.5 0 0 1 .5.5v5.21l3.248 1.856a.5.5 0 0 1-.496.868l-3.5-2A.5.5 0 0 1 7 9V3.5a.5.5 0 0 1 .5-.5z"/>
        </svg>
        <p>No recent moderation actions</p>
    </div>
    <?php else: ?>
    <table class="mod-table">
        <thead>
            <tr>
                <th>Time</th>
                <th>Action</th>
                <th>Moderator</th>
                <th>Target</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (array_slice($recentActions, 0, 10) as $action): ?>
            <tr>
                <td style="white-space: nowrap;">
                    <?= date('H:i', strtotime($action['created_at'] ?? 'now')) ?>
                </td>
                <td>
                    <span class="mod-badge <?= getActionBadgeClass($action['action_type'] ?? '') ?>">
                        <?= formatActionType($action['action_type'] ?? 'unknown') ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($action['moderator_username'] ?? 'System') ?></td>
                <td style="color: #d1d5db;">
                    <?= htmlspecialchars($action['target_nickname'] ?? $action['target_user_id'] ?? '-') ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php
// Helper functions
function getActionBadgeClass(string $action): string
{
    return match ($action) {
        'ban_user' => 'mod-badge-danger',
        'unban_user' => 'mod-badge-success',
        'delete_message' => 'mod-badge-warning',
        'warn_user' => 'mod-badge-warning',
        'add_keyword', 'remove_keyword' => 'mod-badge-info',
        'resolve_report' => 'mod-badge-success',
        'escalate_report' => 'mod-badge-warning',
        default => 'mod-badge-info',
    };
}

function formatActionType(string $action): string
{
    return match ($action) {
        'ban_user' => 'Ban',
        'unban_user' => 'Unban',
        'warn_user' => 'Warn',
        'delete_message' => 'Delete Msg',
        'hide_message' => 'Hide Msg',
        'add_keyword' => 'Add Keyword',
        'remove_keyword' => 'Rm Keyword',
        'resolve_report' => 'Resolved',
        'escalate_report' => 'Escalated',
        default => ucwords(str_replace('_', ' ', $action)),
    };
}
?>
