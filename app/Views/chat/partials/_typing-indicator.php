<?php
/**
 * Typing Indicator Partial
 *
 * Variables:
 * - $typers: array of typing users [{name, avatar}]
 *
 * @package Need2Talk
 * @since 2025-12-02
 */

$typers = $typers ?? [];
$count = count($typers);

if ($count === 0) {
    return;
}

$names = array_map(fn($t) => htmlspecialchars($t['name'] ?? 'Utente', ENT_QUOTES, 'UTF-8'), $typers);
?>

<div class="n2t-typing-indicator flex items-center text-gray-400 text-sm" aria-live="polite">
    <!-- Animated Dots -->
    <div class="n2t-typing-dots flex space-x-1 mr-2">
        <span class="w-2 h-2 bg-gray-500 rounded-full animate-bounce" style="animation-delay: 0ms;"></span>
        <span class="w-2 h-2 bg-gray-500 rounded-full animate-bounce" style="animation-delay: 150ms;"></span>
        <span class="w-2 h-2 bg-gray-500 rounded-full animate-bounce" style="animation-delay: 300ms;"></span>
    </div>

    <!-- Typing Text -->
    <span class="n2t-typing-text">
        <?php if ($count === 1): ?>
            <?= $names[0] ?> sta scrivendo...
        <?php elseif ($count === 2): ?>
            <?= $names[0] ?> e <?= $names[1] ?> stanno scrivendo...
        <?php elseif ($count === 3): ?>
            <?= $names[0] ?>, <?= $names[1] ?> e <?= $names[2] ?> stanno scrivendo...
        <?php else: ?>
            <?= $names[0] ?>, <?= $names[1] ?> e altri <?= $count - 2 ?> stanno scrivendo...
        <?php endif; ?>
    </span>
</div>
