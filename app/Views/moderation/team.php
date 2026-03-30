<?php
/**
 * Moderation Portal - Team View
 *
 * ENTERPRISE GALAXY: Simplified read-only view of team members
 * - Shows active moderators with their permissions
 * - URL timer with copy functionality
 * - No management actions (handled by admin panel)
 *
 * @package Need2Talk\Views\Moderation
 */

use Need2Talk\Services\Moderation\ModerationSecurityService;

$modBaseUrl = ModerationSecurityService::generateModerationUrl();
$urlExpiresIn = ModerationSecurityService::getUrlExpiresInMinutes();
$urlExpiresAt = ModerationSecurityService::getUrlExpiresAt();
$moderators = $moderators ?? [];
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem;">
    <h1 style="font-size: 1.75rem; font-weight: 700; color: #ffffff;">
        Team Management
    </h1>
    <span style="font-size: 0.875rem; color: #6b7280;">
        Moderator changes are made via Admin Panel
    </span>
</div>

<!-- URL Timer Card -->
<div class="mod-card" style="background: linear-gradient(135deg, rgba(217, 70, 239, 0.1), rgba(139, 92, 246, 0.1)); border-color: var(--mod-primary);">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h3 style="font-size: 1rem; font-weight: 600; color: #f0abfc; margin-bottom: 0.5rem;">
                Current Portal URL
            </h3>
            <code id="currentUrl" style="background: rgba(0,0,0,0.3); padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.875rem; color: #e5e7eb; display: block; word-break: break-all;">
                <?= htmlspecialchars(ModerationSecurityService::generateModerationUrl(true)) ?>
            </code>
        </div>

        <div style="text-align: center;">
            <div style="font-size: 0.75rem; color: #9ca3af; margin-bottom: 0.25rem;">URL Expires In</div>
            <div id="urlTimer" style="font-size: 2rem; font-weight: 700; color: #f0abfc; font-family: monospace;">
                --:--
            </div>
            <div style="font-size: 0.625rem; color: #6b7280;">minutes</div>
        </div>

        <div>
            <button onclick="copyUrlToClipboard()" class="mod-btn mod-btn-secondary" style="padding: 0.375rem 0.75rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1v-1z"/>
                    <path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5h3zm-3-1A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3z"/>
                </svg>
                Copy URL
            </button>
        </div>
    </div>
</div>

<!-- Team Members List -->
<div class="mod-card">
    <div class="mod-card-header">
        <h2 class="mod-card-title">Team Members</h2>
        <span style="font-size: 0.75rem; color: #6b7280;">
            <?= count(array_filter($moderators, fn($m) => $m['is_active'])) ?> active
        </span>
    </div>

    <?php
    $activeModerators = array_filter($moderators, fn($m) => $m['is_active']);
    if (empty($activeModerators)): ?>
    <div style="text-align: center; padding: 3rem; color: #6b7280;">
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" viewBox="0 0 16 16" style="margin: 0 auto 1rem; opacity: 0.5;">
            <path d="M7 14s-1 0-1-1 1-4 5-4 5 3 5 4-1 1-1 1H7Zm4-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm-5.784 6A2.238 2.238 0 0 1 5 13c0-1.355.68-2.75 1.936-3.72A6.325 6.325 0 0 0 5 9c-4 0-5 3-5 4s1 1 1 1h4.216ZM4.5 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z"/>
        </svg>
        <p>No team members configured</p>
    </div>
    <?php else: ?>
    <div style="display: grid; gap: 0.75rem; padding: 0.5rem 0;">
        <?php foreach ($activeModerators as $mod): ?>
        <div style="display: flex; align-items: center; gap: 1rem; padding: 0.75rem 1rem; background: rgba(255,255,255,0.02); border-radius: 0.5rem; border: 1px solid rgba(255,255,255,0.05);">
            <!-- Avatar -->
            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #d946ef, #8b5cf6); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; flex-shrink: 0;">
                <?= strtoupper(substr($mod['display_name'] ?? $mod['username'], 0, 1)) ?>
            </div>

            <!-- Info -->
            <div style="flex: 1; min-width: 0;">
                <div style="color: #e5e7eb; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    <?= htmlspecialchars($mod['display_name'] ?? $mod['username']) ?>
                </div>
                <div style="font-size: 0.75rem; color: #6b7280;">
                    @<?= htmlspecialchars($mod['username']) ?>
                </div>
            </div>

            <!-- Permissions Badges -->
            <div style="display: flex; flex-wrap: wrap; gap: 0.25rem; justify-content: flex-end;">
                <?php if ($mod['can_view_rooms'] ?? false): ?>
                <span class="mod-badge mod-badge-info" style="font-size: 0.625rem; padding: 0.125rem 0.375rem;" title="View Rooms">👁️</span>
                <?php endif; ?>
                <?php if ($mod['can_ban_users'] ?? false): ?>
                <span class="mod-badge mod-badge-warning" style="font-size: 0.625rem; padding: 0.125rem 0.375rem;" title="Ban Users">🚫</span>
                <?php endif; ?>
                <?php if ($mod['can_delete_messages'] ?? false): ?>
                <span class="mod-badge mod-badge-danger" style="font-size: 0.625rem; padding: 0.125rem 0.375rem;" title="Delete Messages">🗑️</span>
                <?php endif; ?>
                <?php if ($mod['can_manage_keywords'] ?? false): ?>
                <span class="mod-badge mod-badge-success" style="font-size: 0.625rem; padding: 0.125rem 0.375rem;" title="Manage Keywords">📝</span>
                <?php endif; ?>
                <?php if ($mod['can_view_reports'] ?? false): ?>
                <span class="mod-badge mod-badge-info" style="font-size: 0.625rem; padding: 0.125rem 0.375rem;" title="View Reports">📋</span>
                <?php endif; ?>
                <?php if ($mod['can_resolve_reports'] ?? false): ?>
                <span class="mod-badge mod-badge-success" style="font-size: 0.625rem; padding: 0.125rem 0.375rem;" title="Resolve Reports">✅</span>
                <?php endif; ?>
            </div>

            <!-- Last Login -->
            <div style="text-align: right; font-size: 0.75rem; color: #6b7280; min-width: 70px;">
                <?php if (!empty($mod['last_login_at'])): ?>
                <?= date('d/m', strtotime($mod['last_login_at'])) ?>
                <div style="font-size: 0.625rem;"><?= date('H:i', strtotime($mod['last_login_at'])) ?></div>
                <?php else: ?>
                <span style="color: #4b5563;">Never</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Permissions Legend -->
<div class="mod-card" style="background: rgba(255,255,255,0.02);">
    <div class="mod-card-header">
        <h3 class="mod-card-title" style="font-size: 0.875rem;">Permissions Legend</h3>
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: 1rem; padding: 0.5rem 0; font-size: 0.75rem; color: #9ca3af;">
        <span>👁️ View Rooms</span>
        <span>🚫 Ban Users</span>
        <span>🗑️ Delete Messages</span>
        <span>📝 Manage Keywords</span>
        <span>📋 View Reports</span>
        <span>✅ Resolve Reports</span>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
    const urlExpiresAt = <?= $urlExpiresAt ?>;

    // Timer update every second
    function updateTimer() {
        const now = Math.floor(Date.now() / 1000);
        const remaining = urlExpiresAt - now;

        if (remaining <= 0) {
            location.reload();
            return;
        }

        const minutes = Math.floor(remaining / 60);
        const seconds = remaining % 60;
        document.getElementById('urlTimer').textContent =
            String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
    }

    updateTimer();
    setInterval(updateTimer, 1000);

    // Copy URL to clipboard
    function copyUrlToClipboard() {
        const url = document.getElementById('currentUrl').textContent.trim();
        navigator.clipboard.writeText(url).then(() => {
            showToast('URL copied to clipboard', 'success');
        });
    }
</script>
