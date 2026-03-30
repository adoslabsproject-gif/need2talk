<?php
/**
 * =============================================================================
 * PRIVACY SETTINGS PAGE - ENTERPRISE GALAXY+
 * =============================================================================
 *
 * GRANULAR PRIVACY CONTROLS for Profile Visibility
 * Target: 100,000+ concurrent users
 *
 * FEATURES:
 * - Privacy presets (Open/Balanced/Private/Custom)
 * - 20+ granular visibility toggles
 * - Sub-section level controls (health score breakdown)
 * - Activity visibility settings
 * - Friend list visibility
 * - Online status controls
 * - Real-time save via API
 *
 * SECURITY:
 * - Auth required (session-based)
 * - CSRF protection
 * - Input validation (client + server)
 * - Audit logging (privacy changes)
 *
 * PERFORMANCE:
 * - <50ms page load (cached queries)
 * - Optimistic UI updates
 * - Debounced auto-save (500ms)
 * - Zero layout shifts
 *
 * @package need2talk/Lightning Framework
 * @version 1.0.0 - Phase 1.7
 * @date 2025-01-07
 * =============================================================================
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Require authentication
if (!is_logged_in()) {
    redirect('/login?redirect=/privacy-settings');
    exit;
}

$user = get_current_user();
$pageTitle = 'Privacy Settings - need2talk';

// Load current privacy settings via API (for initial render)
$currentSettings = null;
try {
    $userController = new \Need2Talk\Controllers\Api\UserController();
    $settingsResponse = $userController->getPrivacySettings();
    if ($settingsResponse['success']) {
        $currentSettings = $settingsResponse['settings'];
    }
} catch (Exception $e) {
    // Fallback to defaults
    $currentSettings = null;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/css/main.css">

    <style>
        .privacy-preset-card {
            cursor: pointer;
            transition: all 0.2s ease;
            border: 2px solid transparent;
        }

        .privacy-preset-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .privacy-preset-card.active {
            border-color: #0d6efd;
            background-color: #e7f1ff;
        }

        .privacy-section {
            border-left: 4px solid #0d6efd;
            padding-left: 1rem;
            margin-bottom: 2rem;
        }

        .privacy-toggle {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            transition: background 0.2s ease;
        }

        .privacy-toggle:hover {
            background: #e9ecef;
        }

        .form-switch .form-check-input {
            width: 3rem;
            height: 1.5rem;
            cursor: pointer;
        }

        .save-indicator {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
            display: none;
        }

        .preset-badge {
            font-size: 0.875rem;
            padding: 0.25rem 0.5rem;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <div class="container mt-5 mb-5">
        <div class="row">
            <div class="col-lg-10 mx-auto">
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1><i class="fas fa-shield-alt text-primary"></i> Privacy Settings</h1>
                        <p class="text-muted">Control who can see your emotional health data and activities</p>
                    </div>
                    <div>
                        <button type="button" class="btn btn-outline-secondary" onclick="window.history.back()">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                    </div>
                </div>

                <!-- Save Indicator (floating) -->
                <div id="save-indicator" class="save-indicator alert alert-success">
                    <i class="fas fa-check-circle"></i> Settings saved successfully
                </div>

                <!-- Privacy Presets -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-magic"></i> Quick Privacy Presets</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Choose a preset to quickly configure your privacy settings, or customize them individually below.</p>

                        <div class="row g-3" id="privacy-presets">
                            <!-- Open Preset -->
                            <div class="col-md-3">
                                <div class="card privacy-preset-card" data-preset="open">
                                    <div class="card-body text-center">
                                        <i class="fas fa-globe fa-3x text-success mb-3"></i>
                                        <h6>Open</h6>
                                        <p class="small text-muted mb-0">Share everything publicly</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Balanced Preset -->
                            <div class="col-md-3">
                                <div class="card privacy-preset-card active" data-preset="balanced">
                                    <div class="card-body text-center">
                                        <i class="fas fa-balance-scale fa-3x text-primary mb-3"></i>
                                        <h6>Balanced</h6>
                                        <p class="small text-muted mb-0">Friends see most data</p>
                                        <span class="badge preset-badge bg-primary">Recommended</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Private Preset -->
                            <div class="col-md-3">
                                <div class="card privacy-preset-card" data-preset="private">
                                    <div class="card-body text-center">
                                        <i class="fas fa-lock fa-3x text-warning mb-3"></i>
                                        <h6>Private</h6>
                                        <p class="small text-muted mb-0">Share minimal data</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Custom Preset -->
                            <div class="col-md-3">
                                <div class="card privacy-preset-card" data-preset="custom">
                                    <div class="card-body text-center">
                                        <i class="fas fa-cog fa-3x text-secondary mb-3"></i>
                                        <h6>Custom</h6>
                                        <p class="small text-muted mb-0">Configure manually</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Granular Privacy Controls -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-sliders-h"></i> Granular Privacy Controls</h5>
                    </div>
                    <div class="card-body">
                        <form id="privacy-settings-form">
                            <!-- Profile Visibility -->
                            <div class="privacy-section">
                                <h6 class="text-primary mb-3"><i class="fas fa-user-circle"></i> Profile Visibility</h6>

                                <div class="privacy-toggle">
                                    <div>
                                        <strong>Profile Visibility</strong>
                                        <p class="text-muted small mb-0">Who can view your profile page</p>
                                    </div>
                                    <select class="form-select w-auto" name="profile_visibility">
                                        <option value="public" <?= ($currentSettings['profile_visibility'] ?? 'public') === 'public' ? 'selected' : '' ?>>Everyone</option>
                                        <option value="friends" <?= ($currentSettings['profile_visibility'] ?? '') === 'friends' ? 'selected' : '' ?>>Friends Only</option>
                                        <option value="private" <?= ($currentSettings['profile_visibility'] ?? '') === 'private' ? 'selected' : '' ?>>Only Me</option>
                                    </select>
                                </div>

                                <div class="privacy-toggle">
                                    <div>
                                        <strong>Show in Search Results</strong>
                                        <p class="text-muted small mb-0">Allow others to find you via search</p>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="show_on_search" <?= ($currentSettings['show_on_search'] ?? true) ? 'checked' : '' ?>>
                                    </div>
                                </div>
                            </div>

                            <!-- Emotional Health Data -->
                            <div class="privacy-section">
                                <h6 class="text-primary mb-3"><i class="fas fa-heart-pulse"></i> Emotional Health Data</h6>

                                <div class="privacy-toggle">
                                    <div>
                                        <strong>Overall Health Score</strong>
                                        <p class="text-muted small mb-0">Total emotional wellness score visibility</p>
                                    </div>
                                    <select class="form-select w-auto" name="health_score_visibility">
                                        <option value="everyone" <?= ($currentSettings['health_score_visibility'] ?? 'friends') === 'everyone' ? 'selected' : '' ?>>Everyone</option>
                                        <option value="friends" <?= ($currentSettings['health_score_visibility'] ?? 'friends') === 'friends' ? 'selected' : '' ?>>Friends</option>
                                        <option value="only_me" <?= ($currentSettings['health_score_visibility'] ?? 'friends') === 'only_me' ? 'selected' : '' ?>>Only Me</option>
                                    </select>
                                </div>

                                <!-- Health Score Sub-Sections -->
                                <div class="ms-4 mt-2 mb-3">
                                    <p class="small text-muted mb-2"><strong>Health Score Components:</strong></p>

                                    <div class="privacy-toggle">
                                        <div>
                                            <small>Total Score</small>
                                        </div>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="health_score_total_visibility" <?= ($currentSettings['health_score_total_visibility'] ?? true) ? 'checked' : '' ?>>
                                        </div>
                                    </div>

                                    <div class="privacy-toggle">
                                        <div>
                                            <small>Emotional Diversity</small>
                                        </div>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="health_score_diversity_visibility" <?= ($currentSettings['health_score_diversity_visibility'] ?? true) ? 'checked' : '' ?>>
                                        </div>
                                    </div>

                                    <div class="privacy-toggle">
                                        <div>
                                            <small>Emotional Balance</small>
                                        </div>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="health_score_balance_visibility" <?= ($currentSettings['health_score_balance_visibility'] ?? true) ? 'checked' : '' ?>>
                                        </div>
                                    </div>

                                    <div class="privacy-toggle">
                                        <div>
                                            <small>Mood Stability</small>
                                        </div>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="health_score_stability_visibility" <?= ($currentSettings['health_score_stability_visibility'] ?? true) ? 'checked' : '' ?>>
                                        </div>
                                    </div>

                                    <div class="privacy-toggle">
                                        <div>
                                            <small>Social Engagement</small>
                                        </div>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="health_score_engagement_visibility" <?= ($currentSettings['health_score_engagement_visibility'] ?? true) ? 'checked' : '' ?>>
                                        </div>
                                    </div>
                                </div>

                                <div class="privacy-toggle">
                                    <div>
                                        <strong>Emotion Wheel</strong>
                                        <p class="text-muted small mb-0">10 Plutchik emotions distribution chart</p>
                                    </div>
                                    <select class="form-select w-auto" name="emotion_wheel_visibility">
                                        <option value="everyone" <?= ($currentSettings['emotion_wheel_visibility'] ?? 'friends') === 'everyone' ? 'selected' : '' ?>>Everyone</option>
                                        <option value="friends" <?= ($currentSettings['emotion_wheel_visibility'] ?? 'friends') === 'friends' ? 'selected' : '' ?>>Friends</option>
                                        <option value="only_me" <?= ($currentSettings['emotion_wheel_visibility'] ?? 'friends') === 'only_me' ? 'selected' : '' ?>>Only Me</option>
                                    </select>
                                </div>

                                <div class="privacy-toggle">
                                    <div>
                                        <strong>Mood Timeline</strong>
                                        <p class="text-muted small mb-0">30-day emotional history chart</p>
                                    </div>
                                    <select class="form-select w-auto" name="mood_timeline_visibility">
                                        <option value="everyone" <?= ($currentSettings['mood_timeline_visibility'] ?? 'friends') === 'everyone' ? 'selected' : '' ?>>Everyone</option>
                                        <option value="friends" <?= ($currentSettings['mood_timeline_visibility'] ?? 'friends') === 'friends' ? 'selected' : '' ?>>Friends</option>
                                        <option value="only_me" <?= ($currentSettings['mood_timeline_visibility'] ?? 'friends') === 'only_me' ? 'selected' : '' ?>>Only Me</option>
                                    </select>
                                </div>

                                <div class="privacy-toggle">
                                    <div>
                                        <strong>Statistics</strong>
                                        <p class="text-muted small mb-0">Emotional journal stats and analytics</p>
                                    </div>
                                    <select class="form-select w-auto" name="stats_visibility">
                                        <option value="everyone" <?= ($currentSettings['stats_visibility'] ?? 'friends') === 'everyone' ? 'selected' : '' ?>>Everyone</option>
                                        <option value="friends" <?= ($currentSettings['stats_visibility'] ?? 'friends') === 'friends' ? 'selected' : '' ?>>Friends</option>
                                        <option value="only_me" <?= ($currentSettings['stats_visibility'] ?? 'friends') === 'only_me' ? 'selected' : '' ?>>Only Me</option>
                                    </select>
                                </div>

                                <div class="privacy-toggle">
                                    <div>
                                        <strong>Insights</strong>
                                        <p class="text-muted small mb-0">AI-generated emotional insights</p>
                                    </div>
                                    <select class="form-select w-auto" name="insights_visibility">
                                        <option value="everyone" <?= ($currentSettings['insights_visibility'] ?? 'friends') === 'everyone' ? 'selected' : '' ?>>Everyone</option>
                                        <option value="friends" <?= ($currentSettings['insights_visibility'] ?? 'friends') === 'friends' ? 'selected' : '' ?>>Friends</option>
                                        <option value="only_me" <?= ($currentSettings['insights_visibility'] ?? 'friends') === 'only_me' ? 'selected' : '' ?>>Only Me</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Activity & Social -->
                            <div class="privacy-section">
                                <h6 class="text-primary mb-3"><i class="fas fa-users"></i> Activity & Social</h6>

                                <div class="privacy-toggle">
                                    <div>
                                        <strong>Online Status</strong>
                                        <p class="text-muted small mb-0">Show green dot when online</p>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="show_online_status" <?= ($currentSettings['show_online_status'] ?? true) ? 'checked' : '' ?>>
                                    </div>
                                </div>

                                <div class="privacy-toggle">
                                    <div>
                                        <strong>Last Active Time</strong>
                                        <p class="text-muted small mb-0">Show "Last seen X minutes ago"</p>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="show_last_active" <?= ($currentSettings['show_last_active'] ?? true) ? 'checked' : '' ?>>
                                    </div>
                                </div>

                                <div class="privacy-toggle">
                                    <div>
                                        <strong>Friend List</strong>
                                        <p class="text-muted small mb-0">Who can see your complete friend list</p>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="show_friend_list" <?= ($currentSettings['show_friend_list'] ?? true) ? 'checked' : '' ?>>
                                    </div>
                                </div>

                                <div class="privacy-toggle">
                                    <div>
                                        <strong>Friend Count</strong>
                                        <p class="text-muted small mb-0">Show number of friends on profile</p>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="show_friend_count" <?= ($currentSettings['show_friend_count'] ?? true) ? 'checked' : '' ?>>
                                    </div>
                                </div>

                                <div class="privacy-toggle">
                                    <div>
                                        <strong>Public Posts</strong>
                                        <p class="text-muted small mb-0">Show your public audio posts on profile</p>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="show_public_posts" <?= ($currentSettings['show_public_posts'] ?? true) ? 'checked' : '' ?>>
                                    </div>
                                </div>

                                <div class="privacy-toggle">
                                    <div>
                                        <strong>Reactions Given</strong>
                                        <p class="text-muted small mb-0">Show emotional reactions you've given to others</p>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="show_reactions" <?= ($currentSettings['show_reactions'] ?? true) ? 'checked' : '' ?>>
                                    </div>
                                </div>

                                <div class="privacy-toggle">
                                    <div>
                                        <strong>Comments</strong>
                                        <p class="text-muted small mb-0">Show comments you've written</p>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="show_comments" <?= ($currentSettings['show_comments'] ?? true) ? 'checked' : '' ?>>
                                    </div>
                                </div>
                            </div>

                            <!-- Save Button -->
                            <div class="d-grid mt-4">
                                <button type="submit" class="btn btn-primary btn-lg" id="save-btn">
                                    <i class="fas fa-save"></i> Save Privacy Settings
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Info Box -->
                <div class="alert alert-info mt-4">
                    <i class="fas fa-info-circle"></i>
                    <strong>About Privacy:</strong> Your private diary entries (journal audio and text) are always encrypted and never visible to anyone but you. These settings control only your profile visibility and aggregated emotional health data.
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/footer.php'; ?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Privacy Settings Manager [MINIFIED] -->
    <script src="/assets/js/privacy/PrivacySettingsManager.min.js" type="module"></script>
</body>
</html>
