<?php
/**
 * Moderation Portal Action Log - need2talk Enterprise
 *
 * Audit trail di tutte le azioni dei moderatori
 *
 * @package Need2Talk\Views\Moderation
 */

use Need2Talk\Services\Moderation\ModerationSecurityService;

$modBaseUrl = ModerationSecurityService::generateModerationUrl();
$actions = $actions ?? [];
$pagination = $pagination ?? [];
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem;">
    <h1 style="font-size: 1.75rem; font-weight: 700; color: #ffffff;">
        Action Log
    </h1>

    <div style="display: flex; gap: 0.5rem;">
        <select id="actionTypeFilter" onchange="filterByAction()"
                style="padding: 0.5rem; background: #171717; border: 1px solid rgba(217, 70, 239, 0.2); border-radius: 0.5rem; color: #ffffff;">
            <option value="">All Actions</option>
            <option value="ban_user">Ban User</option>
            <option value="unban_user">Unban User</option>
            <option value="warn_user">Warn User</option>
            <option value="delete_message">Delete Message</option>
            <option value="add_keyword">Add Keyword</option>
            <option value="remove_keyword">Remove Keyword</option>
            <option value="resolve_report">Resolve Report</option>
            <option value="escalate_report">Escalate Report</option>
        </select>
    </div>
</div>

<!-- Actions List -->
<div class="mod-card">
    <div class="mod-card-header">
        <h2 class="mod-card-title">Moderation Actions</h2>
        <span style="font-size: 0.875rem; color: #6b7280;">
            Showing <?= count($actions) ?> of <?= $pagination['total'] ?? count($actions) ?>
        </span>
    </div>

    <?php if (empty($actions)): ?>
    <div style="text-align: center; padding: 3rem; color: #6b7280;">
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" viewBox="0 0 16 16" style="margin: 0 auto 1rem; opacity: 0.5;">
            <path d="M8.515 1.019A7 7 0 0 0 8 1V0a8 8 0 0 1 .589.022l-.074.997zm2.004.45a7.003 7.003 0 0 0-.985-.299l.219-.976c.383.086.76.2 1.126.342l-.36.933zm1.37.71a7.01 7.01 0 0 0-.439-.27l.493-.87a8.025 8.025 0 0 1 .979.654l-.615.789a6.996 6.996 0 0 0-.418-.302zm1.834 1.79a6.99 6.99 0 0 0-.653-.796l.724-.69c.27.285.52.59.747.91l-.818.576zm.744 1.352a7.08 7.08 0 0 0-.214-.468l.893-.45a7.976 7.976 0 0 1 .45 1.088l-.95.313a7.023 7.023 0 0 0-.179-.483zm.53 2.507a6.991 6.991 0 0 0-.1-1.025l.985-.17c.067.386.106.778.116 1.17l-1 .025zm-.131 1.538c.033-.17.06-.339.081-.51l.993.123a7.957 7.957 0 0 1-.23 1.155l-.964-.267c.046-.165.086-.332.12-.501zm-.952 2.379c.184-.29.346-.594.486-.908l.914.405c-.16.36-.345.706-.555 1.038l-.845-.535zm-.964 1.205c.122-.122.239-.248.35-.378l.758.653a8.073 8.073 0 0 1-.401.432l-.707-.707z"/>
            <path d="M8 1a7 7 0 1 0 4.95 11.95l.707.707A8.001 8.001 0 1 1 8 0v1z"/>
            <path d="M7.5 3a.5.5 0 0 1 .5.5v5.21l3.248 1.856a.5.5 0 0 1-.496.868l-3.5-2A.5.5 0 0 1 7 9V3.5a.5.5 0 0 1 .5-.5z"/>
        </svg>
        <p>No actions logged yet</p>
    </div>
    <?php else: ?>
    <table class="mod-table">
        <thead>
            <tr>
                <th style="width: 120px;">Time</th>
                <th>Action</th>
                <th>Moderator</th>
                <th>Target User</th>
                <th>Details</th>
                <th>IP</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($actions as $action): ?>
            <tr>
                <td style="white-space: nowrap;">
                    <div><?= date('d/m/Y', strtotime($action['created_at'] ?? 'now')) ?></div>
                    <div style="font-size: 0.75rem; color: #6b7280;">
                        <?= date('H:i:s', strtotime($action['created_at'] ?? 'now')) ?>
                    </div>
                </td>
                <td>
                    <span class="mod-badge <?= getActionBadgeClass($action['action_type'] ?? '') ?>">
                        <?= formatActionType($action['action_type'] ?? 'unknown') ?>
                    </span>
                </td>
                <td style="color: #e5e7eb;">
                    <?= htmlspecialchars($action['moderator_username'] ?? 'System') ?>
                </td>
                <td>
                    <?php if (!empty($action['target_nickname'])): ?>
                    <span style="color: #fca5a5;"><?= htmlspecialchars($action['target_nickname']) ?></span>
                    <?php elseif (!empty($action['target_user_id'])): ?>
                    <span style="color: #6b7280;">User #<?= $action['target_user_id'] ?></span>
                    <?php else: ?>
                    <span style="color: #6b7280;">-</span>
                    <?php endif; ?>
                </td>
                <td style="max-width: 300px;">
                    <?php if (!empty($action['details'])): ?>
                    <?php
                    $details = is_string($action['details']) ? json_decode($action['details'], true) : $action['details'];
                    if (is_array($details)):
                    ?>
                    <div style="font-size: 0.75rem; color: #9ca3af;">
                        <?php foreach (array_slice($details, 0, 3) as $key => $value): ?>
                        <span style="margin-right: 0.75rem;">
                            <strong><?= htmlspecialchars($key) ?>:</strong>
                            <?= is_string($value) ? htmlspecialchars(substr($value, 0, 50)) : json_encode($value) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <span style="color: #6b7280;"><?= htmlspecialchars(substr((string)$details, 0, 100)) ?></span>
                    <?php endif; ?>
                    <?php else: ?>
                    <span style="color: #6b7280;">-</span>
                    <?php endif; ?>
                </td>
                <td style="font-size: 0.75rem; color: #6b7280; font-family: monospace;">
                    <?= htmlspecialchars($action['ip_address'] ?? '-') ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if (($pagination['total_pages'] ?? 1) > 1): ?>
    <div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid rgba(217, 70, 239, 0.2);">
        <?php if (($pagination['current_page'] ?? 1) > 1): ?>
        <a href="?page=<?= ($pagination['current_page'] ?? 1) - 1 ?>" class="mod-btn mod-btn-secondary" style="padding: 0.25rem 0.75rem;">
            Previous
        </a>
        <?php endif; ?>

        <span style="padding: 0.25rem 0.75rem; color: #9ca3af;">
            Page <?= $pagination['current_page'] ?? 1 ?> of <?= $pagination['total_pages'] ?? 1 ?>
        </span>

        <?php if (($pagination['current_page'] ?? 1) < ($pagination['total_pages'] ?? 1)): ?>
        <a href="?page=<?= ($pagination['current_page'] ?? 1) + 1 ?>" class="mod-btn mod-btn-secondary" style="padding: 0.25rem 0.75rem;">
            Next
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script nonce="<?= csp_nonce() ?>">
    function filterByAction() {
        const actionType = document.getElementById('actionTypeFilter').value;
        const url = new URL(window.location);

        if (actionType) {
            url.searchParams.set('action', actionType);
        } else {
            url.searchParams.delete('action');
        }

        url.searchParams.delete('page'); // Reset to page 1
        window.location = url;
    }

    // Set current filter from URL
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const currentAction = urlParams.get('action');
        if (currentAction) {
            document.getElementById('actionTypeFilter').value = currentAction;
        }
    });
</script>

<?php
function getActionBadgeClass(string $action): string
{
    return match ($action) {
        'ban_user' => 'mod-badge-danger',
        'unban_user' => 'mod-badge-success',
        'delete_message', 'hide_message' => 'mod-badge-warning',
        'warn_user' => 'mod-badge-warning',
        'add_keyword', 'remove_keyword' => 'mod-badge-info',
        'resolve_report' => 'mod-badge-success',
        'escalate_report' => 'mod-badge-danger',
        default => 'mod-badge-info',
    };
}

function formatActionType(string $action): string
{
    return match ($action) {
        'ban_user' => 'Ban User',
        'unban_user' => 'Unban User',
        'warn_user' => 'Warn User',
        'delete_message' => 'Delete Message',
        'hide_message' => 'Hide Message',
        'add_keyword' => 'Add Keyword',
        'remove_keyword' => 'Remove Keyword',
        'resolve_report' => 'Resolve Report',
        'escalate_report' => 'Escalate Report',
        'release_escrow' => 'Release Escrow',
        default => ucwords(str_replace('_', ' ', $action)),
    };
}
?>
