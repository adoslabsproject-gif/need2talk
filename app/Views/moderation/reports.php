<?php
/**
 * Moderation Portal Audio Reports - need2talk Enterprise
 *
 * Enterprise audio post report management with:
 * - Risk-based prioritization
 * - Inline audio preview
 * - Warning email sending
 * - Soft delete (hide) content
 * - User moderation history
 *
 * @package Need2Talk\Views\Moderation
 */

use Need2Talk\Services\Moderation\ModerationSecurityService;

$modBaseUrl = ModerationSecurityService::generateModerationUrl();
$reports = $reports ?? [];
$stats = $stats ?? [];
$currentStatus = $_GET['status'] ?? 'pending';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem;">
    <h1 style="font-size: 1.75rem; font-weight: 700; color: #ffffff;">
        🎵 Audio Reports
    </h1>

    <div style="display: flex; gap: 0.5rem;">
        <button onclick="filterReports('pending')" class="mod-btn <?= $currentStatus === 'pending' ? 'mod-btn-primary' : 'mod-btn-secondary' ?>">
            ⏳ Pending (<?= $stats['pending'] ?? 0 ?>)
        </button>
        <button onclick="filterReports('escalated')" class="mod-btn <?= $currentStatus === 'escalated' ? 'mod-btn-danger' : 'mod-btn-secondary' ?>">
            🔺 Escalated (<?= $stats['escalated'] ?? 0 ?>)
        </button>
        <button onclick="filterReports('all')" class="mod-btn <?= $currentStatus === 'all' ? 'mod-btn-primary' : 'mod-btn-secondary' ?>">
            📋 All
        </button>
    </div>
</div>

<!-- Stats Grid -->
<div class="mod-stats-grid" style="margin-bottom: 2rem;">
    <div class="mod-stat-card">
        <div style="font-size: 2rem; font-weight: 700; color: #f59e0b;"><?= $stats['pending'] ?? 0 ?></div>
        <div class="mod-stat-label">Pending</div>
    </div>
    <div class="mod-stat-card">
        <div style="font-size: 2rem; font-weight: 700; color: #ef4444;"><?= $stats['escalated'] ?? 0 ?></div>
        <div class="mod-stat-label">Escalated</div>
    </div>
    <div class="mod-stat-card">
        <div style="font-size: 2rem; font-weight: 700; color: #22c55e;"><?= $stats['actioned'] ?? 0 ?></div>
        <div class="mod-stat-label">Action Taken</div>
    </div>
    <div class="mod-stat-card">
        <div style="font-size: 2rem; font-weight: 700; color: #6b7280;"><?= $stats['dismissed'] ?? 0 ?></div>
        <div class="mod-stat-label">Dismissed</div>
    </div>
    <div class="mod-stat-card">
        <div style="font-size: 2rem; font-weight: 700; color: #a855f7;"><?= $stats['high_risk_users'] ?? 0 ?></div>
        <div class="mod-stat-label">High Risk Users</div>
    </div>
</div>

<!-- Actions Help Panel -->
<details class="mod-card" style="margin-bottom: 1.5rem; cursor: pointer;">
    <summary style="padding: 1rem; font-weight: 600; color: #a855f7; display: flex; align-items: center; gap: 0.5rem;">
        <span>📖</span>
        <span>Guida Azioni Moderazione (click per espandere)</span>
    </summary>
    <div style="padding: 1rem; border-top: 1px solid rgba(255, 255, 255, 0.05);">
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
            <div style="background: rgba(107, 114, 128, 0.1); padding: 1rem; border-radius: 0.5rem; border-left: 3px solid #6b7280;">
                <strong style="color: #e5e7eb;">❌ Dismiss</strong>
                <p style="font-size: 0.875rem; color: #9ca3af; margin-top: 0.5rem;">
                    Chiude il report come "falso positivo". L'audio rimane visibile, nessuna azione presa contro l'autore.
                </p>
            </div>
            <div style="background: rgba(245, 158, 11, 0.1); padding: 1rem; border-radius: 0.5rem; border-left: 3px solid #f59e0b;">
                <strong style="color: #f59e0b;">⚠️ Send Warning</strong>
                <p style="font-size: 0.875rem; color: #9ca3af; margin-top: 0.5rem;">
                    Invia email di avvertimento all'autore <strong>immediatamente</strong>. Incrementa il risk score dell'utente. Il report viene chiuso come "action_taken".
                </p>
            </div>
            <div style="background: rgba(239, 68, 68, 0.1); padding: 1rem; border-radius: 0.5rem; border-left: 3px solid #ef4444;">
                <strong style="color: #ef4444;">🚫 Hide Content</strong>
                <p style="font-size: 0.875rem; color: #9ca3af; margin-top: 0.5rem;">
                    Soft-delete dell'audio (is_hidden = TRUE). L'audio non è più visibile nel feed ma può essere ripristinato con "Restore".
                </p>
            </div>
            <div style="background: rgba(239, 68, 68, 0.2); padding: 1rem; border-radius: 0.5rem; border-left: 3px solid #dc2626;">
                <strong style="color: #dc2626;">🔨 Ban User</strong>
                <p style="font-size: 0.875rem; color: #9ca3af; margin-top: 0.5rem;">
                    Ban granulare dell'utente per scope (global/chat/posts/comments). Termina le sessioni attive dell'utente.
                </p>
            </div>
            <div style="background: rgba(124, 58, 237, 0.1); padding: 1rem; border-radius: 0.5rem; border-left: 3px solid #7c3aed;">
                <strong style="color: #a855f7;">🔺 Escalate</strong>
                <p style="font-size: 0.875rem; color: #9ca3af; margin-top: 0.5rem;">
                    Marca il report come "escalated" per revisione da moderatori senior o admin. Utile per casi dubbi o gravi.
                </p>
            </div>
            <div style="background: rgba(34, 197, 94, 0.1); padding: 1rem; border-radius: 0.5rem; border-left: 3px solid #22c55e;">
                <strong style="color: #22c55e;">✅ Restore</strong>
                <p style="font-size: 0.875rem; color: #9ca3af; margin-top: 0.5rem;">
                    Ripristina un audio precedentemente nascosto. Compare solo per audio con is_hidden = TRUE.
                </p>
            </div>
        </div>
    </div>
</details>

<!-- Reports List -->
<div class="mod-card">
    <div class="mod-card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h2 class="mod-card-title">Reports Queue</h2>
        <span style="font-size: 0.875rem; color: #6b7280;">
            Sorted by: Risk Score ↓, then Date ↑
        </span>
    </div>

    <?php if (empty($reports)): ?>
    <div style="text-align: center; padding: 3rem; color: #6b7280;">
        <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="currentColor" viewBox="0 0 16 16" style="margin: 0 auto 1rem; opacity: 0.3;">
            <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
            <path d="M4.285 9.567a.5.5 0 0 1 .683.183A3.498 3.498 0 0 0 8 11.5a3.498 3.498 0 0 0 3.032-1.75.5.5 0 1 1 .866.5A4.498 4.498 0 0 1 8 12.5a4.498 4.498 0 0 1-3.898-2.25.5.5 0 0 1 .183-.683z"/>
            <path d="M7 6.5C7 7.328 6.552 8 6 8s-1-.672-1-1.5S5.448 5 6 5s1 .672 1 1.5zm4 0c0 .828-.448 1.5-1 1.5s-1-.672-1-1.5S9.448 5 10 5s1 .672 1 1.5z"/>
        </svg>
        <p style="font-size: 1.25rem; margin-bottom: 0.5rem;">No reports to review</p>
        <p style="font-size: 0.875rem;">The queue is empty. Great job keeping the community safe! 🎉</p>
    </div>
    <?php else: ?>
    <?php
    // ENTERPRISE GALAXY: Group reports by audio_id for cleaner UI
    // Each audio gets ONE card with all its reports listed inside
    $groupedReports = [];
    foreach ($reports as $report) {
        $audioId = $report['audio_id'];
        if (!isset($groupedReports[$audioId])) {
            $groupedReports[$audioId] = [
                'audio' => $report,  // First report has all audio info
                'reports' => [],
            ];
        }
        $groupedReports[$audioId]['reports'][] = $report;
    }
    ?>
    <div style="display: flex; flex-direction: column; gap: 1.5rem; padding: 1rem;">
        <?php foreach ($groupedReports as $audioId => $group): ?>
        <?php
        $audio = $group['audio'];
        $audioReports = $group['reports'];
        $totalReportsCount = count($audioReports);
        $riskScore = (int) ($audio['author_risk_score'] ?? 0);
        $riskColor = $riskScore >= 70 ? '#ef4444' : ($riskScore >= 40 ? '#f59e0b' : '#22c55e');
        $riskLabel = $riskScore >= 70 ? 'HIGH RISK' : ($riskScore >= 40 ? 'MEDIUM' : 'LOW');
        $hasEscalated = array_filter($audioReports, fn($r) => !empty($r['is_escalated']));
        $isHidden = !empty($audio['is_hidden']);
        $hasPending = array_filter($audioReports, fn($r) => ($r['status'] ?? 'pending') === 'pending');
        $firstPendingReport = !empty($hasPending) ? reset($hasPending) : null;
        ?>
        <div class="audio-report-card" id="audio-<?= $audioId ?>"
             style="background: <?= $hasEscalated ? 'rgba(239, 68, 68, 0.1)' : 'rgba(15, 15, 15, 0.5)' ?>; border: 1px solid <?= $hasEscalated ? 'rgba(239, 68, 68, 0.3)' : 'rgba(255, 255, 255, 0.05)' ?>; border-radius: 0.75rem; padding: 1.25rem;">

            <!-- Header Row -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                    <span class="mod-badge mod-badge-danger" style="font-weight: 700; font-size: 1rem;">
                        <?= $totalReportsCount ?> <?= $totalReportsCount === 1 ? 'Report' : 'Reports' ?>
                    </span>

                    <?php if ($hasEscalated): ?>
                    <span class="mod-badge mod-badge-danger" style="font-weight: 600;">🔺 ESCALATED</span>
                    <?php endif; ?>

                    <span class="mod-badge" style="background: <?= $riskColor ?>20; color: <?= $riskColor ?>; border: 1px solid <?= $riskColor ?>50;">
                        Author Risk: <?= $riskScore ?>/100 (<?= $riskLabel ?>)
                    </span>

                    <?php if ($isHidden): ?>
                    <span class="mod-badge" style="background: #6b728020; color: #6b7280;">🚫 Hidden</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Content Grid -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1rem;">
                <!-- Left: Audio Info -->
                <div>
                    <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.5rem;">REPORTED AUDIO</div>
                    <div style="background: rgba(0, 0, 0, 0.3); border-radius: 0.5rem; padding: 1rem;">
                        <div style="font-weight: 600; color: #e5e7eb; margin-bottom: 0.5rem;">
                            <?= htmlspecialchars($audio['audio_title'] ?? 'Untitled') ?>
                        </div>
                        <?php if (!empty($audio['audio_description'])): ?>
                        <div style="font-size: 0.875rem; color: #9ca3af; margin-bottom: 0.75rem;">
                            <?= htmlspecialchars(mb_substr($audio['audio_description'], 0, 150)) ?>...
                        </div>
                        <?php endif; ?>

                        <!-- Audio Player -->
                        <?php if (!empty($audio['audio_uuid'])): ?>
                        <audio controls preload="metadata" style="width: 100%; height: 40px; margin-top: 0.5rem;"
                               class="mod-audio-player" data-audio-uuid="<?= htmlspecialchars($audio['audio_uuid']) ?>">
                            <source src="/api/audio/<?= htmlspecialchars($audio['audio_uuid']) ?>/stream?proxy=1" type="audio/webm">
                        </audio>
                        <?php endif; ?>

                        <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.5rem;">
                            Posted: <?= date('d/m/Y', strtotime($audio['audio_created_at'])) ?>
                        </div>
                    </div>
                </div>

                <!-- Right: Author Info -->
                <div>
                    <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.5rem;">AUTHOR INFO</div>
                    <div style="background: rgba(0, 0, 0, 0.3); border-radius: 0.5rem; padding: 1rem;">
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;">
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #a855f7, #ec4899); display: flex; align-items: center; justify-content: center; font-weight: 600; color: white;">
                                <?= strtoupper(substr($audio['author_nickname'] ?? 'U', 0, 1)) ?>
                            </div>
                            <div>
                                <div style="font-weight: 600; color: #e5e7eb;">
                                    <?= htmlspecialchars($audio['author_nickname'] ?? 'Unknown') ?>
                                </div>
                                <div style="font-size: 0.75rem; color: #6b7280;">
                                    <?= htmlspecialchars($audio['author_email'] ?? '') ?>
                                </div>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; text-align: center;">
                            <div style="background: rgba(239, 68, 68, 0.1); padding: 0.5rem; border-radius: 0.25rem;">
                                <div style="font-weight: 600; color: #fca5a5;"><?= $audio['author_total_reports'] ?? 0 ?></div>
                                <div style="font-size: 0.65rem; color: #6b7280;">Reports</div>
                            </div>
                            <div style="background: rgba(245, 158, 11, 0.1); padding: 0.5rem; border-radius: 0.25rem;">
                                <div style="font-weight: 600; color: #fcd34d;"><?= $audio['author_warnings_count'] ?? 0 ?></div>
                                <div style="font-size: 0.65rem; color: #6b7280;">Warnings</div>
                            </div>
                            <div style="background: rgba(168, 85, 247, 0.1); padding: 0.5rem; border-radius: 0.25rem;">
                                <div style="font-weight: 600; color: #c4b5fd;"><?= $audio['author_reports_actioned'] ?? 0 ?></div>
                                <div style="font-size: 0.65rem; color: #6b7280;">Actioned</div>
                            </div>
                        </div>

                        <button onclick="viewUserHistory('<?= htmlspecialchars($audio['author_uuid']) ?>', '<?= htmlspecialchars($audio['author_nickname']) ?>')"
                                class="mod-btn mod-btn-secondary" style="width: 100%; margin-top: 0.75rem; padding: 0.5rem;">
                            📋 View Full History
                        </button>
                    </div>
                </div>
            </div>

            <!-- Reports History for this Audio -->
            <div style="margin-bottom: 1rem;">
                <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.5rem;">REPORT HISTORY (<?= $totalReportsCount ?>)</div>
                <div style="background: rgba(0, 0, 0, 0.2); border-radius: 0.5rem; max-height: 300px; overflow-y: auto;">
                    <?php foreach ($audioReports as $idx => $report): ?>
                    <div style="padding: 0.75rem; border-bottom: 1px solid rgba(255, 255, 255, 0.05); <?= $idx === count($audioReports) - 1 ? 'border-bottom: none;' : '' ?>"
                         id="report-<?= $report['report_id'] ?>">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                <span class="mod-badge mod-badge-warning" style="font-size: 0.75rem;">
                                    <?= ucfirst(str_replace('_', ' ', $report['reason'])) ?>
                                </span>
                                <?php if (!empty($report['is_escalated'])): ?>
                                <span class="mod-badge mod-badge-danger" style="font-size: 0.7rem;">🔺 Escalated</span>
                                <?php endif; ?>
                                <?php if (($report['status'] ?? 'pending') !== 'pending'): ?>
                                <span class="mod-badge <?= $report['status'] === 'action_taken' ? 'mod-badge-success' : 'mod-badge-secondary' ?>" style="font-size: 0.7rem;">
                                    <?= ucfirst(str_replace('_', ' ', $report['status'])) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <span style="font-size: 0.7rem; color: #6b7280;">
                                <?= date('d/m/Y H:i', strtotime($report['reported_at'])) ?>
                            </span>
                        </div>
                        <div style="font-size: 0.8rem; color: #9ca3af; margin-bottom: 0.25rem;">
                            Reported by: <span style="color: #c4b5fd;"><?= htmlspecialchars($report['reporter_nickname'] ?? 'Anonymous') ?></span>
                        </div>
                        <?php if (!empty($report['report_description'])): ?>
                        <div style="font-size: 0.8rem; color: #fca5a5; background: rgba(239, 68, 68, 0.05); padding: 0.5rem; border-radius: 0.25rem; margin-top: 0.5rem;">
                            "<?= htmlspecialchars($report['report_description']) ?>"
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($report['moderator_notes'])): ?>
                        <div style="font-size: 0.75rem; color: #9ca3af; font-style: italic; margin-top: 0.25rem;">
                            Mod notes: <?= htmlspecialchars($report['moderator_notes']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Action Buttons (operate on first pending report or audio) -->
            <?php if ($firstPendingReport): ?>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; border-top: 1px solid rgba(255, 255, 255, 0.05); padding-top: 1rem;">
                <button onclick="dismissReport(<?= $firstPendingReport['report_id'] ?>)"
                        class="mod-btn mod-btn-secondary" title="Dismiss first pending report as invalid">
                    ❌ Dismiss Report
                </button>

                <button onclick="openWarningModal(<?= $firstPendingReport['report_id'] ?>, '<?= htmlspecialchars($audio['author_uuid']) ?>', '<?= htmlspecialchars($audio['author_nickname']) ?>', '<?= htmlspecialchars($firstPendingReport['reason']) ?>', '<?= htmlspecialchars($audio['audio_title'] ?? '') ?>')"
                        class="mod-btn" style="background: #f59e0b; color: black;" title="Send warning email to author">
                    ⚠️ Send Warning
                </button>

                <?php if (!$isHidden): ?>
                <button onclick="hideContent(<?= $firstPendingReport['report_id'] ?>, <?= $audioId ?>)"
                        class="mod-btn mod-btn-danger" title="Hide (soft delete) the audio post">
                    🚫 Hide Content
                </button>
                <?php else: ?>
                <button onclick="unhideContent(<?= $audioId ?>)"
                        class="mod-btn" style="background: #22c55e; color: white;" title="Restore hidden content">
                    ✅ Restore
                </button>
                <?php endif; ?>

                <button onclick="openBanModal('<?= htmlspecialchars($audio['author_uuid']) ?>', '<?= htmlspecialchars($audio['author_nickname']) ?>')"
                        class="mod-btn mod-btn-danger" title="Ban the author">
                    🔨 Ban User
                </button>

                <?php if (!$hasEscalated): ?>
                <button onclick="escalateReport(<?= $firstPendingReport['report_id'] ?>)"
                        class="mod-btn" style="background: #7c3aed; color: white;" title="Escalate to senior moderators">
                    🔺 Escalate
                </button>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div style="border-top: 1px solid rgba(255, 255, 255, 0.05); padding-top: 1rem;">
                <span class="mod-badge mod-badge-success">✅ All reports reviewed</span>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Warning Modal -->
<div id="warningModal" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.9); z-index: 100; align-items: center; justify-content: center;">
    <div style="background: #171717; border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 0.75rem; padding: 1.5rem; max-width: 500px; width: 95%;">
        <h3 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 1rem; color: #f59e0b;">
            ⚠️ Send Warning Email
        </h3>

        <form id="warningForm">
            <input type="hidden" id="warningReportId" name="report_id">
            <input type="hidden" id="warningUserId" name="user_id">

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.875rem; color: #9ca3af; margin-bottom: 0.5rem;">Sending to:</label>
                <div id="warningRecipient" style="font-weight: 600; color: #e5e7eb;"></div>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.875rem; color: #9ca3af; margin-bottom: 0.5rem;">Warning Type</label>
                <select id="warningType" name="warning_type" required
                        style="width: 100%; padding: 0.75rem; background: #0f0f0f; border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 0.5rem; color: #ffffff;">
                    <option value="content_report">📋 Generic Content Report</option>
                    <option value="spam">📢 Spam</option>
                    <option value="harassment">🤬 Harassment/Bullying</option>
                    <option value="hate_speech">🚫 Hate Speech</option>
                    <option value="copyright">©️ Copyright Violation</option>
                    <option value="sexual_content">🔞 Sexual Content</option>
                    <option value="violence">⚔️ Violence</option>
                </select>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.875rem; color: #9ca3af; margin-bottom: 0.5rem;">
                    Custom Message (optional)
                </label>
                <textarea id="warningCustomMessage" name="custom_message" rows="3"
                          style="width: 100%; padding: 0.75rem; background: #0f0f0f; border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 0.5rem; color: #ffffff; resize: vertical;"
                          placeholder="Add additional context or instructions for the user..."></textarea>
            </div>

            <div style="background: rgba(245, 158, 11, 0.1); border-radius: 0.5rem; padding: 1rem; margin-bottom: 1.5rem;">
                <div style="font-size: 0.75rem; color: #f59e0b; margin-bottom: 0.5rem;">📧 Email Preview</div>
                <div style="font-size: 0.875rem; color: #9ca3af;">
                    A warning email will be sent immediately (not queued) to the user's email address.
                    This action will be logged and the user's warning count will increase.
                </div>
            </div>

            <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                <button type="button" onclick="closeWarningModal()" class="mod-btn mod-btn-secondary">Cancel</button>
                <button type="submit" class="mod-btn" style="background: #f59e0b; color: black;">
                    Send Warning
                </button>
            </div>
        </form>
    </div>
</div>

<!-- User History Modal -->
<div id="userHistoryModal" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.9); z-index: 100; align-items: center; justify-content: center;">
    <div style="background: #171717; border: 1px solid rgba(168, 85, 247, 0.3); border-radius: 0.75rem; padding: 1.5rem; max-width: 600px; width: 95%; max-height: 80vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3 style="font-size: 1.25rem; font-weight: 600; color: #a855f7;">
                📋 User Moderation History
            </h3>
            <button onclick="closeUserHistoryModal()" style="background: none; border: none; color: #6b7280; cursor: pointer; font-size: 1.5rem;">&times;</button>
        </div>
        <div id="userHistoryContent" style="color: #e5e7eb;">
            Loading...
        </div>
    </div>
</div>

<!-- Hide Content Modal -->
<div id="hideContentModal" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.9); z-index: 100; align-items: center; justify-content: center;">
    <div style="background: #171717; border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 0.75rem; padding: 1.5rem; max-width: 400px; width: 95%;">
        <h3 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 1rem; color: #ef4444;">
            🚫 Hide Content
        </h3>

        <form id="hideContentForm">
            <input type="hidden" id="hideReportId" name="report_id">
            <input type="hidden" id="hideAudioId" name="audio_id">

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.875rem; color: #9ca3af; margin-bottom: 0.5rem;">
                    Reason for hiding
                </label>
                <select id="hideReason" name="reason" required
                        style="width: 100%; padding: 0.75rem; background: #0f0f0f; border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 0.5rem; color: #ffffff;">
                    <option value="Community guideline violation">Community guideline violation</option>
                    <option value="Spam or promotional content">Spam or promotional content</option>
                    <option value="Harassment or bullying">Harassment or bullying</option>
                    <option value="Hate speech">Hate speech</option>
                    <option value="Copyright infringement">Copyright infringement</option>
                    <option value="Sexual content">Sexual content</option>
                    <option value="Violence">Violence</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div style="background: rgba(239, 68, 68, 0.1); border-radius: 0.5rem; padding: 1rem; margin-bottom: 1.5rem;">
                <div style="font-size: 0.875rem; color: #fca5a5;">
                    ⚠️ This will soft-delete the audio post. It won't be visible to users but can be restored later.
                </div>
            </div>

            <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                <button type="button" onclick="closeHideContentModal()" class="mod-btn mod-btn-secondary">Cancel</button>
                <button type="submit" class="mod-btn mod-btn-danger">Hide Content</button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
const modBaseUrl = '<?= htmlspecialchars($modBaseUrl) ?>';

function filterReports(status) {
    const url = new URL(window.location);
    if (status === 'all') {
        url.searchParams.delete('status');
        url.searchParams.set('status', 'all');
    } else if (status === 'escalated') {
        url.searchParams.set('status', 'pending');
        url.searchParams.set('escalated', '1');
    } else {
        url.searchParams.set('status', status);
        url.searchParams.delete('escalated');
    }
    window.location = url;
}

// Warning Modal
function openWarningModal(reportId, userId, nickname, reason, audioTitle) {
    document.getElementById('warningReportId').value = reportId;
    document.getElementById('warningUserId').value = userId;
    document.getElementById('warningRecipient').textContent = nickname;

    // Pre-select warning type based on report reason
    const typeMap = {
        'spam': 'spam',
        'harassment': 'harassment',
        'hate_speech': 'hate_speech',
        'violence': 'violence',
        'sexual_content': 'sexual_content',
        'copyright': 'copyright'
    };
    const warningType = typeMap[reason] || 'content_report';
    document.getElementById('warningType').value = warningType;

    document.getElementById('warningModal').style.display = 'flex';
}

function closeWarningModal() {
    document.getElementById('warningModal').style.display = 'none';
}

document.getElementById('warningForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.textContent = 'Sending...';

    const data = {
        report_id: document.getElementById('warningReportId').value,
        user_uuid: document.getElementById('warningUserId').value,  // ENTERPRISE: UUID not ID
        warning_type: document.getElementById('warningType').value,
        custom_message: document.getElementById('warningCustomMessage').value
    };

    try {
        const response = await fetch(`${modBaseUrl}/api/reports/send-warning`, {
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
            closeWarningModal();
            showToast('⚠️ Warning email sent successfully', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(result.error || 'Failed to send warning', 'error');
        }
    } catch (error) {
        showToast('Request failed', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Send Warning';
    }
});

// Hide Content Modal
function hideContent(reportId, audioId) {
    document.getElementById('hideReportId').value = reportId;
    document.getElementById('hideAudioId').value = audioId;
    document.getElementById('hideContentModal').style.display = 'flex';
}

function closeHideContentModal() {
    document.getElementById('hideContentModal').style.display = 'none';
}

document.getElementById('hideContentForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const data = {
        report_id: document.getElementById('hideReportId').value,
        audio_id: document.getElementById('hideAudioId').value,
        reason: document.getElementById('hideReason').value
    };

    try {
        const response = await fetch(`${modBaseUrl}/api/reports/hide-content`, {
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
            closeHideContentModal();
            showToast('🚫 Content hidden successfully', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(result.error || 'Failed to hide content', 'error');
        }
    } catch (error) {
        showToast('Request failed', 'error');
    }
});

async function unhideContent(audioId) {
    if (!confirm('Restore this hidden content? It will become visible again.')) return;

    try {
        const response = await fetch(`${modBaseUrl}/api/reports/unhide-content`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ audio_id: audioId })
        });

        const result = await response.json();

        if (result.success) {
            showToast('✅ Content restored', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(result.error || 'Failed to restore content', 'error');
        }
    } catch (error) {
        showToast('Request failed', 'error');
    }
}

// Dismiss Report
async function dismissReport(reportId) {
    if (!confirm('Dismiss this report as invalid/false? The content will remain visible.')) return;

    try {
        const response = await fetch(`${modBaseUrl}/api/reports/dismiss`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ report_id: reportId })
        });

        const result = await response.json();

        if (result.success) {
            showToast('Report dismissed', 'success');
            document.getElementById(`report-${reportId}`).style.opacity = '0.5';
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(result.error || 'Failed to dismiss report', 'error');
        }
    } catch (error) {
        showToast('Request failed', 'error');
    }
}

// Escalate Report
async function escalateReport(reportId) {
    const reason = prompt('Escalation reason (optional):');
    if (reason === null) return; // Cancelled

    try {
        const response = await fetch(`${modBaseUrl}/api/reports/escalate`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ report_id: reportId, reason: reason })
        });

        const result = await response.json();

        if (result.success) {
            showToast('🔺 Report escalated', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(result.error || 'Failed to escalate report', 'error');
        }
    } catch (error) {
        showToast('Request failed', 'error');
    }
}

// User History Modal - ENTERPRISE: Uses user_uuid, not user_id
async function viewUserHistory(userUuid, nickname) {
    document.getElementById('userHistoryModal').style.display = 'flex';
    document.getElementById('userHistoryContent').innerHTML = '<div style="text-align: center; padding: 2rem;">Loading...</div>';

    try {
        const response = await fetch(`${modBaseUrl}/api/users/${userUuid}/moderation-history`, {
            headers: {
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const result = await response.json();

        if (result.success) {
            renderUserHistory(result, nickname);
        } else {
            document.getElementById('userHistoryContent').innerHTML = `<div style="color: #ef4444;">Error: ${result.error}</div>`;
        }
    } catch (error) {
        document.getElementById('userHistoryContent').innerHTML = '<div style="color: #ef4444;">Failed to load history</div>';
    }
}

function renderUserHistory(data, nickname) {
    const stats = data.stats || {};
    const warnings = data.warnings || [];
    const bans = data.active_bans || [];
    const reports = data.recent_reports || [];

    let html = `
        <div style="margin-bottom: 1.5rem;">
            <h4 style="color: #a855f7; margin-bottom: 0.75rem;">${nickname}</h4>
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem;">
                <div style="background: rgba(239, 68, 68, 0.1); padding: 0.75rem; border-radius: 0.5rem; text-align: center;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: #fca5a5;">${stats.total_reports_received || 0}</div>
                    <div style="font-size: 0.75rem; color: #6b7280;">Reports</div>
                </div>
                <div style="background: rgba(245, 158, 11, 0.1); padding: 0.75rem; border-radius: 0.5rem; text-align: center;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: #fcd34d;">${stats.warnings_sent || 0}</div>
                    <div style="font-size: 0.75rem; color: #6b7280;">Warnings</div>
                </div>
                <div style="background: rgba(168, 85, 247, 0.1); padding: 0.75rem; border-radius: 0.5rem; text-align: center;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: #c4b5fd;">${stats.reports_actioned || 0}</div>
                    <div style="font-size: 0.75rem; color: #6b7280;">Actioned</div>
                </div>
                <div style="background: rgba(239, 68, 68, 0.2); padding: 0.75rem; border-radius: 0.5rem; text-align: center;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: #ef4444;">${stats.risk_score || 0}</div>
                    <div style="font-size: 0.75rem; color: #6b7280;">Risk Score</div>
                </div>
            </div>
        </div>
    `;

    // Active Bans
    if (bans.length > 0) {
        html += `<div style="margin-bottom: 1.5rem;">
            <h5 style="color: #ef4444; margin-bottom: 0.5rem;">🔨 Active Bans</h5>`;
        bans.forEach(ban => {
            html += `<div style="background: rgba(239, 68, 68, 0.1); padding: 0.5rem; border-radius: 0.25rem; margin-bottom: 0.25rem;">
                <strong>${ban.scope}</strong> - ${ban.reason}
                ${ban.expires_at ? `(Expires: ${new Date(ban.expires_at).toLocaleDateString()})` : '(Permanent)'}
            </div>`;
        });
        html += '</div>';
    }

    // Recent Warnings
    if (warnings.length > 0) {
        html += `<div style="margin-bottom: 1.5rem;">
            <h5 style="color: #f59e0b; margin-bottom: 0.5rem;">⚠️ Recent Warnings</h5>`;
        warnings.forEach(w => {
            html += `<div style="background: rgba(245, 158, 11, 0.1); padding: 0.5rem; border-radius: 0.25rem; margin-bottom: 0.25rem; font-size: 0.875rem;">
                <strong>${w.warning_type}</strong> (${w.severity}) - ${new Date(w.sent_at).toLocaleDateString()}
            </div>`;
        });
        html += '</div>';
    }

    // Recent Reports
    if (reports.length > 0) {
        html += `<div>
            <h5 style="color: #9ca3af; margin-bottom: 0.5rem;">📋 Recent Reports Against Content</h5>`;
        reports.forEach(r => {
            const statusColor = r.status === 'action_taken' ? '#22c55e' : (r.status === 'dismissed' ? '#6b7280' : '#f59e0b');
            html += `<div style="background: rgba(0, 0, 0, 0.3); padding: 0.5rem; border-radius: 0.25rem; margin-bottom: 0.25rem; font-size: 0.875rem;">
                <span style="color: ${statusColor};">${r.status}</span> - ${r.reason}
                ${r.audio_title ? ` on "${r.audio_title}"` : ''}
                <span style="color: #6b7280;"> - ${new Date(r.created_at).toLocaleDateString()}</span>
            </div>`;
        });
        html += '</div>';
    }

    document.getElementById('userHistoryContent').innerHTML = html;
}

function closeUserHistoryModal() {
    document.getElementById('userHistoryModal').style.display = 'none';
}

// Ban Modal - ENTERPRISE: Uses user_uuid, not user_id
function openBanModal(userUuid, nickname) {
    if (confirm(`Ban user "${nickname}"? You will be redirected to the Bans page.`)) {
        window.location.href = `${modBaseUrl}/bans?ban_user=${userUuid}`;
    }
}
</script>
