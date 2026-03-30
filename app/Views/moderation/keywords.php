<?php
/**
 * Moderation Portal Keywords Management - need2talk Enterprise
 *
 * Gestione parole proibite per censura automatica
 *
 * @package Need2Talk\Views\Moderation
 */

use Need2Talk\Services\Moderation\ModerationSecurityService;

$modBaseUrl = ModerationSecurityService::generateModerationUrl();
$keywords = $keywords ?? [];
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem;">
    <h1 style="font-size: 1.75rem; font-weight: 700; color: #ffffff;">
        Keyword Management
    </h1>

    <button onclick="openAddKeywordModal()" class="mod-btn mod-btn-primary">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
            <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z"/>
        </svg>
        Add Keyword
    </button>
</div>

<!-- Info Card -->
<div class="mod-card" style="background: rgba(217, 70, 239, 0.05); border-color: rgba(217, 70, 239, 0.2);">
    <div style="display: flex; gap: 1rem; align-items: flex-start;">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="#d946ef" viewBox="0 0 16 16" style="flex-shrink: 0; margin-top: 0.25rem;">
            <path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm.93-9.412-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/>
        </svg>
        <div>
            <h3 style="font-weight: 600; color: #f0abfc; margin-bottom: 0.5rem;">Automatic Content Censorship</h3>
            <p style="color: #9ca3af; font-size: 0.875rem; line-height: 1.6;">
                Keywords added here will be automatically replaced with <code style="background: rgba(0,0,0,0.3); padding: 0.125rem 0.25rem; border-radius: 0.25rem;">***</code> in chat messages, post titles, descriptions, and comments.
                The system supports exact match, contains, regex, and fuzzy matching (including leet speak variations).
            </p>
        </div>
    </div>
</div>

<!-- Keywords List -->
<div class="mod-card">
    <div class="mod-card-header">
        <h2 class="mod-card-title">Active Keywords (<?= count($keywords) ?>)</h2>
    </div>

    <?php if (empty($keywords)): ?>
    <div style="text-align: center; padding: 3rem; color: #6b7280;">
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" viewBox="0 0 16 16" style="margin: 0 auto 1rem; opacity: 0.5;">
            <path d="M2 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v13.5a.5.5 0 0 1-.777.416L8 13.101l-5.223 2.815A.5.5 0 0 1 2 15.5V2zm2-1a1 1 0 0 0-1 1v12.566l4.723-2.482a.5.5 0 0 1 .554 0L13 14.566V2a1 1 0 0 0-1-1H4z"/>
        </svg>
        <p>No keywords configured yet</p>
        <p style="font-size: 0.875rem; margin-top: 0.5rem;">Click "Add Keyword" to start building your blacklist</p>
    </div>
    <?php else: ?>
    <table class="mod-table">
        <thead>
            <tr>
                <th>Keyword</th>
                <th>Match Type</th>
                <th>Applies To</th>
                <th>Replacement</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($keywords as $kw): ?>
            <tr>
                <td>
                    <code style="background: rgba(239, 68, 68, 0.1); color: #fca5a5; padding: 0.25rem 0.5rem; border-radius: 0.25rem;">
                        <?= htmlspecialchars($kw['keyword']) ?>
                    </code>
                </td>
                <td>
                    <span class="mod-badge mod-badge-info">
                        <?= ucfirst($kw['match_type'] ?? 'contains') ?>
                    </span>
                </td>
                <td style="font-size: 0.75rem; color: #9ca3af;">
                    <?php
                    $applies = [];
                    if ($kw['applies_to_titles'] ?? true) $applies[] = 'Titles';
                    if ($kw['applies_to_posts'] ?? true) $applies[] = 'Posts';
                    if ($kw['applies_to_comments'] ?? true) $applies[] = 'Comments';
                    echo implode(', ', $applies) ?: 'Chat only';
                    ?>
                </td>
                <td>
                    <code style="background: rgba(34, 197, 94, 0.1); color: #6ee7b7; padding: 0.25rem 0.5rem; border-radius: 0.25rem;">
                        <?= htmlspecialchars($kw['replacement'] ?? '***') ?>
                    </code>
                </td>
                <td style="font-size: 0.875rem; color: #6b7280;">
                    <?= date('d/m/Y', strtotime($kw['created_at'] ?? 'now')) ?>
                </td>
                <td>
                    <button onclick="deleteKeyword(<?= $kw['id'] ?>, '<?= htmlspecialchars($kw['keyword']) ?>')"
                            class="mod-btn mod-btn-danger" style="padding: 0.25rem 0.5rem;">
                        Delete
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Add Keyword Modal -->
<div id="addKeywordModal" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.8); z-index: 100; align-items: center; justify-content: center;">
    <div style="background: #171717; border: 1px solid rgba(217, 70, 239, 0.2); border-radius: 0.75rem; padding: 1.5rem; max-width: 500px; width: 90%;">
        <h3 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 1.5rem; color: #ffffff;">
            Add Keyword
        </h3>

        <form id="addKeywordForm">
            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.875rem; color: #9ca3af; margin-bottom: 0.5rem;">
                    Keyword <span style="color: #ef4444;">*</span>
                </label>
                <input type="text" id="keywordValue" name="keyword" required
                       style="width: 100%; padding: 0.5rem; background: #0f0f0f; border: 1px solid rgba(217, 70, 239, 0.2); border-radius: 0.5rem; color: #ffffff;"
                       placeholder="Enter word or phrase to block">
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.875rem; color: #9ca3af; margin-bottom: 0.5rem;">Match Type</label>
                <select id="keywordMatchType" name="match_type"
                        style="width: 100%; padding: 0.5rem; background: #0f0f0f; border: 1px solid rgba(217, 70, 239, 0.2); border-radius: 0.5rem; color: #ffffff;">
                    <option value="contains">Contains (matches anywhere in text)</option>
                    <option value="exact">Exact (whole word only)</option>
                    <option value="regex">Regex (advanced pattern)</option>
                    <option value="fuzzy">Fuzzy (includes leet speak)</option>
                </select>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.875rem; color: #9ca3af; margin-bottom: 0.5rem;">Replacement</label>
                <input type="text" id="keywordReplacement" name="replacement" value="***"
                       style="width: 100%; padding: 0.5rem; background: #0f0f0f; border: 1px solid rgba(217, 70, 239, 0.2); border-radius: 0.5rem; color: #ffffff;"
                       placeholder="***">
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.875rem; color: #9ca3af; margin-bottom: 0.5rem;">Apply To</label>
                <div style="display: flex; flex-wrap: wrap; gap: 1rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: #d1d5db;">
                        <input type="checkbox" name="applies_to_titles" value="1" checked
                               style="width: 1rem; height: 1rem; accent-color: #d946ef;">
                        Post Titles
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: #d1d5db;">
                        <input type="checkbox" name="applies_to_posts" value="1" checked
                               style="width: 1rem; height: 1rem; accent-color: #d946ef;">
                        Descriptions
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: #d1d5db;">
                        <input type="checkbox" name="applies_to_comments" value="1" checked
                               style="width: 1rem; height: 1rem; accent-color: #d946ef;">
                        Comments
                    </label>
                </div>
                <p style="font-size: 0.75rem; color: #6b7280; margin-top: 0.5rem;">
                    Note: Keywords always apply to chat messages automatically.
                </p>
            </div>

            <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                <button type="button" onclick="closeAddKeywordModal()" class="mod-btn mod-btn-secondary">Cancel</button>
                <button type="submit" class="mod-btn mod-btn-primary">Add Keyword</button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
    const modBaseUrl = '<?= htmlspecialchars($modBaseUrl) ?>';

    function openAddKeywordModal() {
        document.getElementById('addKeywordForm').reset();
        document.getElementById('addKeywordModal').style.display = 'flex';
    }

    function closeAddKeywordModal() {
        document.getElementById('addKeywordModal').style.display = 'none';
    }

    document.getElementById('addKeywordForm').addEventListener('submit', async function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        const data = {
            keyword: formData.get('keyword'),
            match_type: formData.get('match_type'),
            replacement: formData.get('replacement') || '***',
            applies_to_titles: formData.get('applies_to_titles') === '1',
            applies_to_posts: formData.get('applies_to_posts') === '1',
            applies_to_comments: formData.get('applies_to_comments') === '1',
        };

        try {
            const response = await fetch(`${modBaseUrl}/api/keywords`, {
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
                closeAddKeywordModal();
                showToast('Keyword added successfully', 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(result.error || 'Failed to add keyword', 'error');
            }
        } catch (error) {
            showToast('Request failed', 'error');
        }
    });

    async function deleteKeyword(id, keyword) {
        if (!confirm(`Delete keyword "${keyword}"? This cannot be undone.`)) return;

        try {
            const response = await fetch(`${modBaseUrl}/api/keywords/${id}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-Token': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const result = await response.json();

            if (result.success) {
                showToast('Keyword deleted', 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(result.error || 'Failed to delete keyword', 'error');
            }
        } catch (error) {
            showToast('Request failed', 'error');
        }
    }
</script>
