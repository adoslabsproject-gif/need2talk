<?php

namespace Need2Talk\Controllers;

use Need2Talk\Services\Logger;
use Need2Talk\Services\EmailService;
use Need2Talk\Services\Moderation\ModerationSecurityService;

/**
 * AdminModeratorsController - Enterprise Moderators Management
 *
 * Handles CRUD operations for moderators from the Admin Panel.
 * Moderators access the separate Moderation Portal with their own URL.
 *
 * @package Need2Talk\Controllers
 */
class AdminModeratorsController
{
    /**
     * Get page data for the moderators management view
     */
    public function getPageData(): array
    {
        $pdo = db_pdo();

        // Get moderators list with stats
        $moderators = $this->getModeratorsWithStats($pdo);

        // Get summary stats
        $stats = $this->getModeratorStats($pdo);

        // Get current moderation portal URL
        $portalUrl = ModerationSecurityService::generateModerationUrl();

        return [
            'title' => 'Moderators Management',
            'moderators' => $moderators,
            'stats' => $stats,
            'portalUrl' => $portalUrl,
        ];
    }

    /**
     * API: Get moderators list
     */
    public function getList(): void
    {
        try {
            $pdo = db_pdo();
            $moderators = $this->getModeratorsWithStats($pdo);

            $this->jsonResponse(['success' => true, 'data' => $moderators]);
        } catch (\Exception $e) {
            Logger::error('Failed to get moderators list', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to load moderators'], 500);
        }
    }

    /**
     * API: Create new moderator
     */
    public function create(): void
    {
        try {
            $input = $this->getJsonInput();

            // Validate required fields
            $required = ['username', 'email', 'password', 'display_name'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    $this->jsonResponse(['success' => false, 'error' => "Missing required field: $field"], 400);
                    return;
                }
            }

            // Validate email format
            if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
                $this->jsonResponse(['success' => false, 'error' => 'Invalid email format'], 400);
                return;
            }

            // Validate username format (alphanumeric + underscore, 3-50 chars)
            if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $input['username'])) {
                $this->jsonResponse(['success' => false, 'error' => 'Username must be 3-50 characters, alphanumeric and underscore only'], 400);
                return;
            }

            // Validate password strength
            if (strlen($input['password']) < 12) {
                $this->jsonResponse(['success' => false, 'error' => 'Password must be at least 12 characters'], 400);
                return;
            }

            $pdo = db_pdo();

            // Check for duplicate username or email
            $stmt = $pdo->prepare("SELECT id FROM moderators WHERE username = :username OR email = :email");
            $stmt->execute(['username' => $input['username'], 'email' => $input['email']]);
            if ($stmt->fetch()) {
                $this->jsonResponse(['success' => false, 'error' => 'Username or email already exists'], 409);
                return;
            }

            // Hash password
            $passwordHash = password_hash($input['password'], PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 3,
            ]);

            // Get current admin ID from session
            $adminId = get_session('admin_id') ?? get_session('admin_user_id') ?? null;

            // Insert moderator
            $stmt = $pdo->prepare("
                INSERT INTO moderators (
                    username, email, password_hash, display_name,
                    can_view_rooms, can_ban_users, can_delete_messages,
                    can_manage_keywords, can_view_reports, can_resolve_reports,
                    can_escalate_reports, is_active, created_by_admin_id
                ) VALUES (
                    :username, :email, :password_hash, :display_name,
                    :can_view_rooms, :can_ban_users, :can_delete_messages,
                    :can_manage_keywords, :can_view_reports, :can_resolve_reports,
                    :can_escalate_reports, TRUE, :admin_id
                )
                RETURNING id, uuid
            ");

            $stmt->execute([
                'username' => $input['username'],
                'email' => $input['email'],
                'password_hash' => $passwordHash,
                'display_name' => $input['display_name'],
                'can_view_rooms' => ($input['can_view_rooms'] ?? true) ? 't' : 'f',
                'can_ban_users' => ($input['can_ban_users'] ?? true) ? 't' : 'f',
                'can_delete_messages' => ($input['can_delete_messages'] ?? true) ? 't' : 'f',
                'can_manage_keywords' => ($input['can_manage_keywords'] ?? false) ? 't' : 'f',
                'can_view_reports' => ($input['can_view_reports'] ?? true) ? 't' : 'f',
                'can_resolve_reports' => ($input['can_resolve_reports'] ?? true) ? 't' : 'f',
                'can_escalate_reports' => ($input['can_escalate_reports'] ?? true) ? 't' : 'f',
                'admin_id' => $adminId,
            ]);

            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Log action
            Logger::security('info', 'ADMIN: Moderator created', [
                'moderator_id' => $result['id'],
                'moderator_uuid' => $result['uuid'],
                'username' => $input['username'],
                'email' => $input['email'],
                'admin_id' => $adminId,
                'ip' => get_server('REMOTE_ADDR'),
            ]);

            // Send welcome email with credentials
            $this->sendWelcomeEmail(
                $input['email'],
                $input['display_name'],
                $input['password'],
                $input['can_view_rooms'] ?? true,
                $input['can_ban_users'] ?? true,
                $input['can_delete_messages'] ?? true,
                $input['can_manage_keywords'] ?? false,
                $input['can_view_reports'] ?? true,
                $input['can_resolve_reports'] ?? true,
                $input['can_escalate_reports'] ?? true
            );

            $this->jsonResponse([
                'success' => true,
                'message' => 'Moderator created successfully. Welcome email sent.',
                'data' => [
                    'id' => $result['id'],
                    'uuid' => $result['uuid'],
                ],
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to create moderator', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to create moderator: ' . $e->getMessage()], 500);
        }
    }

    /**
     * API: Update moderator
     */
    public function update(): void
    {
        try {
            $input = $this->getJsonInput();

            if (empty($input['id'])) {
                $this->jsonResponse(['success' => false, 'error' => 'Moderator ID required'], 400);
                return;
            }

            $pdo = db_pdo();

            // Build update query dynamically
            $updates = [];
            $params = ['id' => $input['id']];

            $allowedFields = [
                'display_name', 'can_view_rooms', 'can_ban_users', 'can_delete_messages',
                'can_manage_keywords', 'can_view_reports', 'can_resolve_reports', 'can_escalate_reports',
            ];

            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    if (strpos($field, 'can_') === 0) {
                        // Boolean fields
                        $updates[] = "$field = :$field";
                        $params[$field] = $input[$field] ? 't' : 'f';
                    } else {
                        $updates[] = "$field = :$field";
                        $params[$field] = $input[$field];
                    }
                }
            }

            if (empty($updates)) {
                $this->jsonResponse(['success' => false, 'error' => 'No fields to update'], 400);
                return;
            }

            $updates[] = "updated_at = NOW()";
            $sql = "UPDATE moderators SET " . implode(', ', $updates) . " WHERE id = :id";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // Log action
            $adminId = get_session('admin_id') ?? get_session('admin_user_id') ?? null;
            Logger::security('info', 'ADMIN: Moderator updated', [
                'moderator_id' => $input['id'],
                'updated_fields' => array_keys(array_diff_key($params, ['id' => 1])),
                'admin_id' => $adminId,
                'ip' => get_server('REMOTE_ADDR'),
            ]);

            $this->jsonResponse(['success' => true, 'message' => 'Moderator updated successfully']);
        } catch (\Exception $e) {
            Logger::error('Failed to update moderator', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to update moderator'], 500);
        }
    }

    /**
     * API: Toggle moderator suspension (is_active)
     * ENTERPRISE GALAXY: Sospensione temporanea - il moderatore può essere riattivato
     */
    public function toggleStatus(): void
    {
        try {
            $input = $this->getJsonInput();

            if (empty($input['id'])) {
                $this->jsonResponse(['success' => false, 'error' => 'Moderator ID required'], 400);
                return;
            }

            $pdo = db_pdo();
            $adminId = get_session('admin_id') ?? get_session('admin_user_id') ?? null;

            // Get current moderator status
            $stmt = $pdo->prepare("SELECT id, username, email, display_name, is_active FROM moderators WHERE id = :id AND deactivated_at IS NULL");
            $stmt->execute(['id' => $input['id']]);
            $moderator = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$moderator) {
                $this->jsonResponse(['success' => false, 'error' => 'Moderator not found or already deleted'], 404);
                return;
            }

            // Toggle is_active
            $newStatus = !$moderator['is_active'];

            $stmt = $pdo->prepare("UPDATE moderators SET is_active = :status, updated_at = NOW() WHERE id = :id");
            $stmt->execute(['id' => $input['id'], 'status' => $newStatus ? 't' : 'f']);

            // If suspending, terminate all sessions
            if (!$newStatus) {
                $stmt = $pdo->prepare("DELETE FROM moderator_sessions WHERE moderator_id = :id");
                $stmt->execute(['id' => $input['id']]);
            }

            // Log action
            Logger::security('info', 'ADMIN: Moderator ' . ($newStatus ? 'UNSUSPENDED' : 'SUSPENDED'), [
                'moderator_id' => $moderator['id'],
                'username' => $moderator['username'],
                'new_status' => $newStatus ? 'active' : 'suspended',
                'admin_id' => $adminId,
                'ip' => get_server('REMOTE_ADDR'),
            ]);

            $this->jsonResponse([
                'success' => true,
                'message' => $newStatus ? 'Moderator unsuspended' : 'Moderator suspended',
                'new_status' => $newStatus ? 'active' : 'suspended',
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to toggle moderator status', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to update status'], 500);
        }
    }

    /**
     * API: Soft delete moderator
     * ENTERPRISE GALAXY: Eliminazione soft - il moderatore viene disattivato permanentemente
     * ma i suoi dati rimangono nel database per audit trail
     */
    public function delete(): void
    {
        try {
            $input = $this->getJsonInput();

            if (empty($input['id'])) {
                $this->jsonResponse(['success' => false, 'error' => 'Moderator ID required'], 400);
                return;
            }

            $pdo = db_pdo();
            $adminId = get_session('admin_id') ?? get_session('admin_user_id') ?? null;
            $reason = $input['reason'] ?? 'Deleted by admin';

            // Get moderator info
            $stmt = $pdo->prepare("SELECT id, username, email, display_name FROM moderators WHERE id = :id AND deactivated_at IS NULL");
            $stmt->execute(['id' => $input['id']]);
            $moderator = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$moderator) {
                $this->jsonResponse(['success' => false, 'error' => 'Moderator not found or already deleted'], 404);
                return;
            }

            // 1. Delete all sessions
            $stmt = $pdo->prepare("DELETE FROM moderator_sessions WHERE moderator_id = :id");
            $stmt->execute(['id' => $input['id']]);

            // 2. Soft delete: set deactivated_at and is_active = false
            $stmt = $pdo->prepare("
                UPDATE moderators
                SET is_active = FALSE,
                    deactivated_at = NOW(),
                    deactivated_by_admin_id = :admin_id,
                    deactivation_reason = :reason,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => $input['id'],
                'admin_id' => $adminId,
                'reason' => $reason,
            ]);

            // Log action
            Logger::security('warning', 'ADMIN: Moderator SOFT DELETED', [
                'moderator_id' => $moderator['id'],
                'username' => $moderator['username'],
                'email' => $moderator['email'],
                'display_name' => $moderator['display_name'],
                'reason' => $reason,
                'deleted_by_admin_id' => $adminId,
                'ip' => get_server('REMOTE_ADDR'),
            ]);

            $this->jsonResponse([
                'success' => true,
                'message' => 'Moderator deleted successfully',
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to delete moderator', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to delete moderator'], 500);
        }
    }

    /**
     * API: Reset moderator password
     */
    public function resetPassword(): void
    {
        try {
            $input = $this->getJsonInput();

            if (empty($input['id']) || empty($input['new_password'])) {
                $this->jsonResponse(['success' => false, 'error' => 'Moderator ID and new password required'], 400);
                return;
            }

            if (strlen($input['new_password']) < 12) {
                $this->jsonResponse(['success' => false, 'error' => 'Password must be at least 12 characters'], 400);
                return;
            }

            $pdo = db_pdo();

            // Hash new password
            $passwordHash = password_hash($input['new_password'], PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 3,
            ]);

            // Update password
            $stmt = $pdo->prepare("
                UPDATE moderators
                SET password_hash = :password_hash,
                    failed_login_attempts = 0,
                    locked_until = NULL,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute(['id' => $input['id'], 'password_hash' => $passwordHash]);

            // Get moderator details for email
            $stmt = $pdo->prepare("SELECT email, display_name FROM moderators WHERE id = :id");
            $stmt->execute(['id' => $input['id']]);
            $moderator = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Terminate all sessions (force re-login)
            $stmt = $pdo->prepare("DELETE FROM moderator_sessions WHERE moderator_id = :id");
            $stmt->execute(['id' => $input['id']]);

            // Log action
            $adminId = get_session('admin_id') ?? get_session('admin_user_id') ?? null;
            Logger::security('info', 'ADMIN: Moderator password reset', [
                'moderator_id' => $input['id'],
                'admin_id' => $adminId,
                'ip' => get_server('REMOTE_ADDR'),
            ]);

            // Send password reset email
            if ($moderator) {
                $this->sendPasswordResetEmail(
                    $moderator['email'],
                    $moderator['display_name'],
                    $input['new_password']
                );
            }

            $this->jsonResponse(['success' => true, 'message' => 'Password reset successfully. Email sent.']);
        } catch (\Exception $e) {
            Logger::error('Failed to reset moderator password', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to reset password'], 500);
        }
    }

    /**
     * API: Get moderator activity log
     */
    public function getActivity(): void
    {
        try {
            $moderatorId = get_input('id') ?? $_GET['id'] ?? null;

            if (!$moderatorId) {
                $this->jsonResponse(['success' => false, 'error' => 'Moderator ID required'], 400);
                return;
            }

            $pdo = db_pdo();
            $limit = (int) (get_input('limit') ?? 50);
            $limit = min($limit, 200);

            $stmt = $pdo->prepare("
                SELECT
                    action_type,
                    target_user_id,
                    details,
                    ip_address,
                    created_at
                FROM moderation_actions_log
                WHERE moderator_id = :moderator_id
                ORDER BY created_at DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':moderator_id', $moderatorId, \PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            $activities = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->jsonResponse(['success' => true, 'data' => $activities]);
        } catch (\Exception $e) {
            Logger::error('Failed to get moderator activity', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to load activity'], 500);
        }
    }

    /**
     * API: Get current moderation portal URL
     */
    public function getPortalUrl(): void
    {
        try {
            $portalUrl = ModerationSecurityService::generateModerationUrl();
            $this->jsonResponse(['success' => true, 'url' => $portalUrl]);
        } catch (\Exception $e) {
            Logger::error('Failed to generate portal URL', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to generate URL'], 500);
        }
    }

    /**
     * Get moderators with their stats
     * ENTERPRISE GALAXY: Excludes soft-deleted moderators (deactivated_at IS NOT NULL)
     */
    private function getModeratorsWithStats($pdo): array
    {
        // ENTERPRISE: Query on single line to ensure consistent cache key hash
        // Excludes soft-deleted moderators (deactivated_at IS NOT NULL)
        $sql = "SELECT m.id, m.uuid, m.username, m.email, m.display_name, m.is_active, m.can_view_rooms, m.can_ban_users, m.can_delete_messages, m.can_manage_keywords, m.can_view_reports, m.can_resolve_reports, m.can_escalate_reports, m.last_login_at, m.last_action_at, m.login_count, m.failed_login_attempts, m.locked_until, m.created_at, COALESCE(actions.total_actions, 0) AS total_actions, COALESCE(bans.total_bans, 0) AS total_bans_issued FROM moderators m LEFT JOIN (SELECT moderator_id, COUNT(*) AS total_actions FROM moderation_actions_log WHERE moderator_id IS NOT NULL GROUP BY moderator_id) actions ON actions.moderator_id = m.id LEFT JOIN (SELECT banned_by_moderator_id, COUNT(*) AS total_bans FROM user_bans WHERE banned_by_moderator_id IS NOT NULL GROUP BY banned_by_moderator_id) bans ON bans.banned_by_moderator_id = m.id WHERE m.deactivated_at IS NULL ORDER BY m.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get moderator summary stats
     */
    private function getModeratorStats($pdo): array
    {
        // ENTERPRISE: Queries on single line for consistent cache keys
        $stmt = $pdo->query("SELECT COUNT(*) FROM moderators");
        $total = (int) $stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM moderators WHERE is_active = TRUE");
        $active = (int) $stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(DISTINCT moderator_id) FROM moderator_sessions WHERE expires_at > NOW() AND last_activity_at > NOW() - INTERVAL '30 minutes'");
        $online = (int) $stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM moderation_actions_log WHERE created_at > CURRENT_DATE AND moderator_id IS NOT NULL");
        $actionsToday = (int) $stmt->fetchColumn();

        return [
            'total' => $total,
            'active' => $active,
            'online' => $online,
            'actions_today' => $actionsToday,
        ];
    }

    /**
     * Get JSON input from request body
     */
    private function getJsonInput(): array
    {
        $json = file_get_contents('php://input');
        return json_decode($json, true) ?? [];
    }

    /**
     * Send JSON response
     */
    private function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Send welcome email to new moderator with credentials
     */
    private function sendWelcomeEmail(
        string $email,
        string $displayName,
        string $password,
        bool $canViewRooms,
        bool $canBanUsers,
        bool $canDeleteMessages,
        bool $canManageKeywords,
        bool $canViewReports,
        bool $canResolveReports,
        bool $canEscalateReports
    ): void {
        try {
            // Build permissions list for email
            $permissions = [];
            if ($canViewRooms) {
                $permissions[] = 'Visualizzare tutte le chat rooms';
            }
            if ($canBanUsers) {
                $permissions[] = 'Bannare utenti';
            }
            if ($canDeleteMessages) {
                $permissions[] = 'Eliminare messaggi';
            }
            if ($canManageKeywords) {
                $permissions[] = 'Gestire parole proibite';
            }
            if ($canViewReports) {
                $permissions[] = 'Visualizzare segnalazioni';
            }
            if ($canResolveReports) {
                $permissions[] = 'Risolvere segnalazioni';
            }
            if ($canEscalateReports) {
                $permissions[] = 'Escalare segnalazioni agli admin';
            }

            // Get portal URL
            $portalUrl = ModerationSecurityService::generateModerationUrl();

            // Render email template
            $htmlBody = $this->renderEmailTemplate('moderator-welcome', [
                'displayName' => $displayName,
                'username' => $email,
                'email' => $email,
                'password' => $password,
                'portalUrl' => $portalUrl,
                'permissions' => $permissions,
            ]);

            // Send email directly (like admin 2FA emails)
            $emailService = new EmailService();
            $emailService->send(
                $email,
                '🛡️ Benvenuto nel Team Moderazione - need2talk',
                $htmlBody
            );

            Logger::email('info', 'Moderator welcome email sent', [
                'email' => $email,
                'display_name' => $displayName,
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to send moderator welcome email', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - moderator creation succeeded, email is secondary
        }
    }

    /**
     * Send password reset email to moderator
     */
    private function sendPasswordResetEmail(
        string $email,
        string $displayName,
        string $newPassword
    ): void {
        try {
            // Get portal URL
            $portalUrl = ModerationSecurityService::generateModerationUrl();

            // Get admin name who reset the password
            $adminName = get_session('admin_username') ?? get_session('admin_display_name') ?? 'Admin';

            // Render email template
            $htmlBody = $this->renderEmailTemplate('moderator-password-reset', [
                'displayName' => $displayName,
                'email' => $email,
                'password' => $newPassword,
                'portalUrl' => $portalUrl,
                'resetBy' => $adminName,
            ]);

            // Send email directly (like admin 2FA emails)
            $emailService = new EmailService();
            $emailService->send(
                $email,
                '🔑 Password Resettata - Portale Moderazione need2talk',
                $htmlBody
            );

            Logger::email('info', 'Moderator password reset email sent', [
                'email' => $email,
                'display_name' => $displayName,
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to send moderator password reset email', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - password reset succeeded, email is secondary
        }
    }

    /**
     * API: Send portal URL to selected moderators
     * ENTERPRISE GALAXY: Manual bulk send - no automatic sending
     */
    public function sendPortalUrl(): void
    {
        try {
            $input = $this->getJsonInput();
            $moderatorIds = $input['moderator_ids'] ?? [];

            if (empty($moderatorIds) || !is_array($moderatorIds)) {
                $this->jsonResponse(['success' => false, 'error' => 'No moderators selected'], 400);
                return;
            }

            // Sanitize IDs
            $moderatorIds = array_map('intval', $moderatorIds);
            $moderatorIds = array_filter($moderatorIds, fn($id) => $id > 0);

            if (empty($moderatorIds)) {
                $this->jsonResponse(['success' => false, 'error' => 'Invalid moderator IDs'], 400);
                return;
            }

            $pdo = db_pdo();

            // Get moderator details
            $placeholders = implode(',', array_fill(0, count($moderatorIds), '?'));
            $stmt = $pdo->prepare("
                SELECT id, email, display_name, username
                FROM moderators
                WHERE id IN ($placeholders)
                  AND is_active = TRUE
                  AND deactivated_at IS NULL
            ");
            $stmt->execute($moderatorIds);
            $moderators = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($moderators)) {
                $this->jsonResponse(['success' => false, 'error' => 'No active moderators found'], 404);
                return;
            }

            // Get current portal URL
            $portalUrl = ModerationSecurityService::generateModerationUrl();
            $fullUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                     . '://' . ($_SERVER['HTTP_HOST'] ?? 'need2talk.it') . $portalUrl;

            // Send emails
            $sent = 0;
            $failed = 0;
            $emailService = new EmailService();

            foreach ($moderators as $mod) {
                try {
                    $htmlBody = $this->renderEmailTemplate('moderator-portal-url', [
                        'displayName' => $mod['display_name'] ?? $mod['username'],
                        'portalUrl' => $fullUrl,
                    ]);

                    $emailService->send(
                        $mod['email'],
                        '🔗 Link Portale Moderazione - need2talk',
                        $htmlBody
                    );

                    $sent++;

                    Logger::email('info', 'Portal URL email sent to moderator', [
                        'moderator_id' => $mod['id'],
                        'email' => $mod['email'],
                    ]);
                } catch (\Exception $e) {
                    $failed++;
                    Logger::error('Failed to send portal URL email', [
                        'moderator_id' => $mod['id'],
                        'email' => $mod['email'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Log admin action
            $adminId = get_session('admin_id') ?? get_session('admin_user_id') ?? null;
            Logger::security('info', 'ADMIN: Sent portal URL to moderators', [
                'admin_id' => $adminId,
                'moderator_ids' => $moderatorIds,
                'sent' => $sent,
                'failed' => $failed,
                'ip' => get_server('REMOTE_ADDR'),
            ]);

            if ($failed > 0) {
                $this->jsonResponse([
                    'success' => true,
                    'message' => "URL inviato a {$sent} moderatore/i. {$failed} invio/i fallito/i.",
                    'sent' => $sent,
                    'failed' => $failed,
                ]);
            } else {
                $this->jsonResponse([
                    'success' => true,
                    'message' => "URL inviato a {$sent} moderatore/i",
                    'sent' => $sent,
                ]);
            }
        } catch (\Exception $e) {
            Logger::error('Failed to send portal URL to moderators', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to send emails: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Render email template
     */
    private function renderEmailTemplate(string $templateName, array $data): string
    {
        $templatePath = APP_ROOT . '/app/Views/emails/' . $templateName . '.php';

        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Email template not found: {$templateName}");
        }

        // Extract data to local scope
        extract($data);

        // Capture output
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }
}
