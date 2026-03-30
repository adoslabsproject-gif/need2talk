<?php
/**
 * Moderation Portal Ban Management - need2talk Enterprise
 *
 * Gestione ban utenti con filtri per scope
 *
 * @package Need2Talk\Views\Moderation
 */

use Need2Talk\Services\Moderation\ModerationSecurityService;

$modBaseUrl = ModerationSecurityService::generateModerationUrl();
$bannedUsers = $bannedUsers ?? [];
$banCounts = $banCounts ?? [];
$currentScope = $currentScope ?? 'all';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem;">
    <h1 style="font-size: 1.75rem; font-weight: 700; color: #ffffff;">
        Ban Management
    </h1>
</div>

<!-- Ban Counts -->
<div class="mod-stats-grid">
    <div class="mod-stat-card" style="cursor: pointer; <?= $currentScope === 'all' ? 'border-color: #d946ef;' : '' ?>"
         onclick="filterByScope('all')">
        <div class="mod-stat-value"><?= array_sum($banCounts) ?></div>
        <div class="mod-stat-label">Total Active Bans</div>
    </div>
    <div class="mod-stat-card" style="cursor: pointer; <?= $currentScope === 'global' ? 'border-color: #ef4444;' : '' ?>"
         onclick="filterByScope('global')">
        <div style="font-size: 2rem; font-weight: 700; color: #ef4444;"><?= $banCounts['global'] ?? 0 ?></div>
        <div class="mod-stat-label">Global</div>
    </div>
    <div class="mod-stat-card" style="cursor: pointer; <?= $currentScope === 'chat' ? 'border-color: #fb923c;' : '' ?>"
         onclick="filterByScope('chat')">
        <div style="font-size: 2rem; font-weight: 700; color: #fb923c;"><?= $banCounts['chat'] ?? 0 ?></div>
        <div class="mod-stat-label">Chat</div>
    </div>
    <div class="mod-stat-card" style="cursor: pointer; <?= $currentScope === 'posts' ? 'border-color: #facc15;' : '' ?>"
         onclick="filterByScope('posts')">
        <div style="font-size: 2rem; font-weight: 700; color: #facc15;"><?= $banCounts['posts'] ?? 0 ?></div>
        <div class="mod-stat-label">Posts</div>
    </div>
    <div class="mod-stat-card" style="cursor: pointer; <?= $currentScope === 'comments' ? 'border-color: #22c55e;' : '' ?>"
         onclick="filterByScope('comments')">
        <div style="font-size: 2rem; font-weight: 700; color: #22c55e;"><?= $banCounts['comments'] ?? 0 ?></div>
        <div class="mod-stat-label">Comments</div>
    </div>
</div>

<!-- Banned Users List -->
<div class="mod-card">
    <div class="mod-card-header">
        <h2 class="mod-card-title">
            Active Bans
            <?php if ($currentScope !== 'all'): ?>
            <span class="mod-badge mod-badge-info" style="margin-left: 0.5rem;">
                <?= ucfirst($currentScope) ?>
            </span>
            <?php endif; ?>
        </h2>
    </div>

    <?php if (empty($bannedUsers)): ?>
    <div style="text-align: center; padding: 3rem; color: #6b7280;">
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" viewBox="0 0 16 16" style="margin: 0 auto 1rem; opacity: 0.5;">
            <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
            <path d="M4.285 9.567a.5.5 0 0 1 .683.183A3.498 3.498 0 0 0 8 11.5a3.498 3.498 0 0 0 3.032-1.75.5.5 0 1 1 .866.5A4.498 4.498 0 0 1 8 12.5a4.498 4.498 0 0 1-3.898-2.25.5.5 0 0 1 .183-.683zM7 6.5C7 7.328 6.552 8 6 8s-1-.672-1-1.5S5.448 5 6 5s1 .672 1 1.5zm4 0c0 .828-.448 1.5-1 1.5s-1-.672-1-1.5S9.448 5 10 5s1 .672 1 1.5z"/>
        </svg>
        <p>No active bans<?= $currentScope !== 'all' ? ' for ' . $currentScope : '' ?></p>
    </div>
    <?php else: ?>
    <table class="mod-table">
        <thead>
            <tr>
                <th>User</th>
                <th>Scope</th>
                <th>Type</th>
                <th>Reason</th>
                <th>Expires</th>
                <th>Banned By</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($bannedUsers as $ban): ?>
            <tr>
                <td>
                    <div style="font-weight: 500; color: #e5e7eb;">
                        <?= htmlspecialchars($ban['nickname'] ?? 'User #' . $ban['user_id']) ?>
                    </div>
                    <div style="font-size: 0.75rem; color: #6b7280;">
                        <?= htmlspecialchars($ban['email'] ?? '') ?>
                    </div>
                </td>
                <td>
                    <span class="mod-badge <?= getBanScopeBadge($ban['scope']) ?>">
                        <?= ucfirst($ban['scope']) ?>
                    </span>
                </td>
                <td>
                    <span class="mod-badge <?= $ban['ban_type'] === 'permanent' ? 'mod-badge-danger' : 'mod-badge-warning' ?>">
                        <?= ucfirst($ban['ban_type']) ?>
                    </span>
                </td>
                <td style="max-width: 200px;">
                    <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($ban['reason']) ?>">
                        <?= htmlspecialchars($ban['reason']) ?>
                    </div>
                </td>
                <td>
                    <?php if ($ban['expires_at']): ?>
                        <div style="font-size: 0.875rem;">
                            <?= date('d/m/Y', strtotime($ban['expires_at'])) ?>
                        </div>
                        <div style="font-size: 0.75rem; color: #6b7280;">
                            <?= date('H:i', strtotime($ban['expires_at'])) ?>
                        </div>
                    <?php else: ?>
                        <span style="color: #ef4444;">Never</span>
                    <?php endif; ?>
                </td>
                <td style="color: #9ca3af;">
                    <?= htmlspecialchars($ban['banned_by_moderator'] ?? 'Admin') ?>
                </td>
                <td>
                    <button onclick="unbanUser(<?= $ban['ban_id'] ?>, '<?= htmlspecialchars($ban['nickname'] ?? 'User') ?>')"
                            class="mod-btn mod-btn-secondary" style="padding: 0.25rem 0.5rem;">
                        Unban
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Unban Modal -->
<div id="unbanModal" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.8); z-index: 100; align-items: center; justify-content: center;">
    <div style="background: #171717; border: 1px solid rgba(217, 70, 239, 0.2); border-radius: 0.75rem; padding: 1.5rem; max-width: 400px; width: 90%;">
        <h3 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 1rem; color: #22c55e;">
            Unban User
        </h3>

        <form id="unbanForm">
            <input type="hidden" id="unbanId" name="ban_id">

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.875rem; color: #9ca3af; margin-bottom: 0.5rem;">User</label>
                <div id="unbanUserName" style="font-weight: 600; color: #ffffff;"></div>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.875rem; color: #9ca3af; margin-bottom: 0.5rem;">Reason for unban</label>
                <textarea id="unbanReason" name="reason" rows="3" required
                          style="width: 100%; padding: 0.5rem; background: #0f0f0f; border: 1px solid rgba(217, 70, 239, 0.2); border-radius: 0.5rem; color: #ffffff; resize: none;"
                          placeholder="Enter reason for lifting the ban..."></textarea>
            </div>

            <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                <button type="button" onclick="closeUnbanModal()" class="mod-btn mod-btn-secondary">Cancel</button>
                <button type="submit" class="mod-btn mod-btn-primary">Unban User</button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
    const modBaseUrl = '<?= htmlspecialchars($modBaseUrl) ?>';

    function filterByScope(scope) {
        const url = new URL(window.location);
        if (scope === 'all') {
            url.searchParams.delete('scope');
        } else {
            url.searchParams.set('scope', scope);
        }
        window.location = url;
    }

    function unbanUser(banId, nickname) {
        document.getElementById('unbanId').value = banId;
        document.getElementById('unbanUserName').textContent = nickname;
        document.getElementById('unbanReason').value = '';
        document.getElementById('unbanModal').style.display = 'flex';
    }

    function closeUnbanModal() {
        document.getElementById('unbanModal').style.display = 'none';
    }

    document.getElementById('unbanForm').addEventListener('submit', async function(e) {
        e.preventDefault();

        const data = {
            ban_id: document.getElementById('unbanId').value,
            reason: document.getElementById('unbanReason').value
        };

        try {
            const response = await fetch(`${modBaseUrl}/api/users/unban`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                closeUnbanModal();
                showToast('User unbanned successfully', 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(result.error || 'Failed to unban user', 'error');
            }
        } catch (error) {
            showToast('Request failed', 'error');
        }
    });
</script>

<?php
function getBanScopeBadge(string $scope): string
{
    return match ($scope) {
        'global' => 'mod-badge-danger',
        'chat' => 'mod-badge-warning',
        'posts' => 'mod-badge-info',
        'comments' => 'mod-badge-success',
        default => 'mod-badge-info',
    };
}
?>
