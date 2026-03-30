<?php
/**
 * Chat Message Partial - Single Message Template
 *
 * Variables expected:
 * - $message: array with uuid, content, sender_name, sender_avatar, timestamp, type, etc.
 * - $isOwn: bool - whether this message is from the current user
 * - $showSender: bool - whether to show sender info (false in DMs, true in rooms)
 *
 * @package Need2Talk
 * @since 2025-12-02
 * @updated 2025-12-06 - Enterprise Galaxy: Moderator support
 */

$messageUuid = htmlspecialchars($message['uuid'] ?? '', ENT_QUOTES, 'UTF-8');
$messageContent = htmlspecialchars($message['content'] ?? '', ENT_QUOTES, 'UTF-8');
$senderName = htmlspecialchars($message['sender_name'] ?? 'Utente', ENT_QUOTES, 'UTF-8');
$senderAvatar = htmlspecialchars($message['sender_avatar'] ?? '/assets/img/default-avatar.png', ENT_QUOTES, 'UTF-8');
$timestamp = $message['timestamp'] ?? $message['created_at'] ?? '';
$messageType = $message['type'] ?? 'text';
$isOwn = $isOwn ?? false;
$showSender = $showSender ?? true;
$status = $message['status'] ?? 'sent';

// ENTERPRISE GALAXY: Moderator detection
$isModerator = ($message['is_moderator'] ?? false) === true;
if ($isModerator) {
    $senderName = htmlspecialchars($message['sender_name'] ?? 'Moderatore', ENT_QUOTES, 'UTF-8');
}

// Format timestamp
$timeDisplay = '';
if ($timestamp) {
    $ts = is_numeric($timestamp) ? $timestamp : strtotime($timestamp);
    $timeDisplay = date('H:i', $ts);
}
?>

<div class="n2t-message <?= $isOwn ? 'n2t-message-own' : 'n2t-message-other' ?><?= $isModerator ? ' n2t-moderator-message' : '' ?>"
     data-message-uuid="<?= $messageUuid ?>"
     data-message-type="<?= $messageType ?>"
     <?= $isModerator ? 'data-moderator="true"' : '' ?>>

    <?php if (!$isOwn && $showSender): ?>
    <!-- Sender Avatar -->
    <?php if ($isModerator): ?>
    <!-- ENTERPRISE GALAXY: Moderator special avatar with shield icon -->
    <div class="n2t-message-avatar n2t-moderator-avatar shrink-0" title="Moderatore">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <linearGradient id="modGradient<?= $messageUuid ?>" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" style="stop-color:#d946ef"/>
                    <stop offset="100%" style="stop-color:#a855f7"/>
                </linearGradient>
            </defs>
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" fill="url(#modGradient<?= $messageUuid ?>)"/>
            <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </div>
    <?php else: ?>
    <div class="n2t-message-avatar shrink-0">
        <img src="<?= $senderAvatar ?>"
             alt="<?= $senderName ?>"
             class="w-8 h-8 rounded-full object-cover"
             loading="lazy">
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="n2t-message-content n2t-message-bubble<?= $isModerator ? ' n2t-moderator-bubble' : '' ?> <?= $isOwn ? 'bg-purple-600 text-white' : ($isModerator ? '' : 'bg-gray-700 text-gray-100') ?> rounded-2xl px-4 py-2 max-w-xs sm:max-w-md lg:max-w-lg">

        <?php if (!$isOwn && $showSender): ?>
        <!-- Sender Name -->
        <div class="n2t-message-sender text-xs font-medium <?= $isModerator ? 'n2t-moderator-name' : ($isOwn ? 'text-purple-200' : 'text-purple-400') ?> mb-1 flex items-center gap-1">
            <?= $senderName ?>
            <?php if ($isModerator): ?>
            <span class="n2t-mod-badge">MOD</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($messageType === 'text'): ?>
        <!-- Text Message -->
        <div class="n2t-message-text break-words whitespace-pre-wrap">
            <?= $messageContent ?>
        </div>

        <?php elseif ($messageType === 'audio'): ?>
        <!-- Audio Message -->
        <div class="n2t-message-audio">
            <div class="flex items-center space-x-3">
                <button class="n2t-audio-play p-2 rounded-full <?= $isOwn ? 'bg-purple-500 hover:bg-purple-400' : 'bg-gray-600 hover:bg-gray-500' ?> transition-colors"
                        data-audio-url="<?= htmlspecialchars($message['file_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <svg class="w-4 h-4 play-icon" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M8 5v14l11-7z"></path>
                    </svg>
                    <svg class="w-4 h-4 pause-icon hidden" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"></path>
                    </svg>
                </button>
                <div class="flex-1">
                    <div class="n2t-audio-waveform h-8 bg-gray-600/50 rounded"></div>
                </div>
                <span class="n2t-audio-duration text-xs opacity-70">
                    <?= isset($message['duration_seconds']) ? gmdate('i:s', (int)$message['duration_seconds']) : '0:00' ?>
                </span>
            </div>
        </div>

        <?php elseif ($messageType === 'image'): ?>
        <!-- Image Message -->
        <div class="n2t-message-image">
            <img src="<?= htmlspecialchars($message['file_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                 alt="Immagine"
                 class="rounded-lg max-w-full cursor-pointer hover:opacity-90 transition-opacity"
                 loading="lazy"
                 onclick="window.openImageLightbox && window.openImageLightbox(this.src)">
        </div>

        <?php elseif ($messageType === 'system'): ?>
        <!-- System Message -->
        <div class="n2t-message-system text-center text-xs text-gray-500 py-2">
            <?= $messageContent ?>
        </div>

        <?php endif; ?>

        <!-- Message Footer -->
        <div class="n2t-message-footer flex items-center justify-end space-x-1 mt-1">
            <span class="n2t-message-time text-xs opacity-60">
                <?= $timeDisplay ?>
            </span>
            <?php if ($isOwn && $messageType !== 'system'): ?>
            <!-- Read Receipt (for own messages) -->
            <span class="n2t-read-receipt" data-status="<?= $status ?>">
                <?php if ($status === 'read'): ?>
                <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <polyline points="18 6 7 17 2 12"></polyline>
                    <polyline points="22 6 11 17 8 14"></polyline>
                </svg>
                <?php elseif ($status === 'delivered'): ?>
                <svg class="w-4 h-4 opacity-60" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <polyline points="18 6 7 17 2 12"></polyline>
                    <polyline points="22 6 11 17 8 14"></polyline>
                </svg>
                <?php else: ?>
                <svg class="w-4 h-4 opacity-60" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
                <?php endif; ?>
            </span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!($message['is_system'] ?? false)): ?>
    <!-- Message Actions (hover) -->
    <div class="n2t-message-actions opacity-0 group-hover:opacity-100 transition-opacity">
        <?php if (!$isOwn): ?>
        <button class="n2t-action-report p-1 hover:bg-gray-700 rounded text-gray-400 hover:text-red-400" title="Segnala">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
            </svg>
        </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>
