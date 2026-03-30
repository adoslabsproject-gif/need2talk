/**
 * ================================================================================
 * COMMENT MANAGER - ENTERPRISE GALAXY V4
 * ================================================================================
 *
 * Full-featured comment system with:
 * - 1-level replies (no deep nesting)
 * - Like system with optimistic UI updates
 * - Emoji support in comment text
 * - Lazy loading (load on section open)
 * - Pagination for large threads
 *
 * Performance Targets:
 * - Add comment: <50ms perceived latency
 * - Like/unlike: <30ms perceived latency
 * - Load comments: <100ms
 *
 * API Endpoints:
 * - POST   /api/comments                     - Create comment
 * - GET    /api/comments/post/{postId}       - Get post comments
 * - GET    /api/comments/{commentId}/replies - Get replies
 * - PUT    /api/comments/{commentId}         - Edit comment
 * - DELETE /api/comments/{commentId}         - Delete comment
 * - POST   /api/comments/{commentId}/like    - Like comment
 * - DELETE /api/comments/{commentId}/like    - Unlike comment
 *
 * @version 1.0.0
 * @author need2talk.it - Enterprise Galaxy
 * ================================================================================
 */

class CommentManager {
    constructor() {
        // State
        this.loadedPosts = new Set();       // Posts with comments already loaded
        this.comments = new Map();           // postId -> comments array
        this.loadingPosts = new Set();       // Posts currently loading comments

        // Pagination state per post
        this.pagination = new Map();         // postId -> { offset, hasMore }

        // Config
        this.config = {
            commentsPerPage: 10,
            repliesPerLoad: 50,
            maxCommentLength: 500,
            minCommentLength: 1
        };

        // Current user (from global) - ENTERPRISE SECURITY: Uses UUID only (no numeric ID exposed)
        this.currentUserUuid = window.need2talk?.user?.uuid || '';
        this.currentUserNickname = window.need2talk?.user?.nickname || '';
        this.currentUserAvatar = window.need2talk?.user?.avatar || '/assets/img/default-avatar.png';

        // ENTERPRISE V4 (2025-11-28): @mention autocomplete state
        this.friendsList = [];              // Cached friends for autocomplete
        this.friendsLoaded = false;         // Flag to avoid reloading
        this.mentionDropdown = null;        // Active dropdown element
        this.mentionStartPos = -1;          // Position where @ was typed
        this.mentionQuery = '';             // Current search query
        this.selectedMentionIndex = 0;      // Keyboard navigation index
    }

    // =========================================================================
    // @MENTION AUTOCOMPLETE - ENTERPRISE V4
    // =========================================================================

    /**
     * Load friends list for @mention autocomplete
     * Called lazily on first textarea focus
     */
    async loadFriendsList() {
        if (this.friendsLoaded) return;

        try {
            const response = await fetch('/api/friends', {
                credentials: 'include'
            });

            if (response.ok) {
                const data = await response.json();
                this.friendsList = data.friends || data || [];
                this.friendsLoaded = true;
            }
        } catch (error) {
            console.error('[CommentManager] Failed to load friends:', error);
        }
    }

    /**
     * Handle input in comment textarea for @mention detection
     * @param {HTMLTextAreaElement} textarea - The textarea element
     * @param {number} postId - Post ID for context
     */
    handleMentionInput(textarea, postId) {
        const text = textarea.value;
        const cursorPos = textarea.selectionStart;

        // Find if we're in a @mention context
        const textBefore = text.substring(0, cursorPos);
        const atMatch = textBefore.match(/@([a-zA-Z0-9_]{0,20})$/);

        if (atMatch) {
            this.mentionStartPos = cursorPos - atMatch[0].length;
            this.mentionQuery = atMatch[1].toLowerCase();
            this.showMentionDropdown(textarea, postId);
        } else {
            this.hideMentionDropdown();
        }
    }

    /**
     * Show @mention autocomplete dropdown
     * @param {HTMLTextAreaElement} textarea - The textarea
     * @param {number} postId - Post ID
     */
    showMentionDropdown(textarea, postId) {
        // Filter friends by query
        const filtered = this.friendsList.filter(friend =>
            friend.nickname.toLowerCase().includes(this.mentionQuery)
        ).slice(0, 5); // Max 5 suggestions

        if (filtered.length === 0) {
            this.hideMentionDropdown();
            return;
        }

        // Create or update dropdown
        this.hideMentionDropdown();

        this.mentionDropdown = document.createElement('div');
        this.mentionDropdown.className = 'mention-dropdown absolute bg-gray-800 border border-gray-600 rounded-lg shadow-xl z-50 max-h-48 overflow-y-auto';
        this.mentionDropdown.style.minWidth = '200px';

        filtered.forEach((friend, index) => {
            const option = document.createElement('div');
            option.className = `flex items-center space-x-2 px-3 py-2 cursor-pointer transition-colors ${
                index === this.selectedMentionIndex ? 'bg-purple-600' : 'hover:bg-gray-700'
            }`;
            option.innerHTML = `
                <img src="${friend.avatar_url || '/assets/img/default-avatar.png'}"
                     alt="${escapeHtml(friend.nickname)}"
                     class="w-6 h-6 rounded-full"
                     onerror="this.src='/assets/img/default-avatar.png'">
                <span class="text-white text-sm">${escapeHtml(friend.nickname)}</span>
            `;
            option.onclick = () => this.insertMention(textarea, friend.nickname);
            this.mentionDropdown.appendChild(option);
        });

        // Position dropdown below textarea
        const rect = textarea.getBoundingClientRect();
        const parent = textarea.closest('.relative');
        if (parent) {
            parent.appendChild(this.mentionDropdown);
            this.mentionDropdown.style.top = `${textarea.offsetHeight + 4}px`;
            this.mentionDropdown.style.left = '0';
        }

        // Store filtered list for keyboard navigation
        this.mentionDropdown._filtered = filtered;
    }

    /**
     * Hide @mention dropdown
     */
    hideMentionDropdown() {
        if (this.mentionDropdown) {
            this.mentionDropdown.remove();
            this.mentionDropdown = null;
        }
        this.selectedMentionIndex = 0;
    }

    /**
     * Insert selected @mention into textarea
     * @param {HTMLTextAreaElement} textarea - The textarea
     * @param {string} nickname - Friend's nickname
     */
    insertMention(textarea, nickname) {
        const text = textarea.value;
        const before = text.substring(0, this.mentionStartPos);
        const after = text.substring(textarea.selectionStart);

        textarea.value = `${before}@${nickname} ${after}`;

        // Position cursor after mention
        const newPos = this.mentionStartPos + nickname.length + 2;
        textarea.setSelectionRange(newPos, newPos);
        textarea.focus();

        this.hideMentionDropdown();
    }

    /**
     * Handle keyboard navigation in @mention dropdown
     * @param {KeyboardEvent} event - Keyboard event
     * @param {HTMLTextAreaElement} textarea - The textarea
     */
    handleMentionKeydown(event, textarea) {
        if (!this.mentionDropdown) return false;

        const filtered = this.mentionDropdown._filtered || [];
        if (filtered.length === 0) return false;

        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                this.selectedMentionIndex = Math.min(this.selectedMentionIndex + 1, filtered.length - 1);
                this.updateDropdownSelection();
                return true;

            case 'ArrowUp':
                event.preventDefault();
                this.selectedMentionIndex = Math.max(this.selectedMentionIndex - 1, 0);
                this.updateDropdownSelection();
                return true;

            case 'Enter':
            case 'Tab':
                event.preventDefault();
                this.insertMention(textarea, filtered[this.selectedMentionIndex].nickname);
                return true;

            case 'Escape':
                this.hideMentionDropdown();
                return true;
        }

        return false;
    }

    /**
     * Update dropdown selection highlight
     */
    updateDropdownSelection() {
        if (!this.mentionDropdown) return;

        const options = this.mentionDropdown.querySelectorAll('div');
        options.forEach((opt, index) => {
            opt.classList.toggle('bg-purple-600', index === this.selectedMentionIndex);
            opt.classList.toggle('hover:bg-gray-700', index !== this.selectedMentionIndex);
        });
    }

    /**
     * Format comment text with @mention highlighting
     * @param {string} text - Raw comment text
     * @param {Array} mentionedUsers - Array of mentioned users from API
     * @returns {string} HTML with highlighted mentions
     */
    formatTextWithMentions(text, mentionedUsers = []) {
        if (!text) return '';
        if (!mentionedUsers || mentionedUsers.length === 0) {
            return escapeHtml(text);
        }

        // Build nickname -> user map
        const nicknameMap = {};
        mentionedUsers.forEach(user => {
            nicknameMap[user.nickname.toLowerCase()] = user;
        });

        // Replace @mentions with links
        let formatted = escapeHtml(text);
        mentionedUsers.forEach(user => {
            const regex = new RegExp(`@${user.nickname}(?![a-zA-Z0-9_])`, 'gi');
            formatted = formatted.replace(regex,
                `<a href="/u/${escapeHtml(user.uuid)}" class="text-purple-400 hover:text-purple-300 font-medium">@${escapeHtml(user.nickname)}</a>`
            );
        });

        return formatted;
    }

    /**
     * Toggle comments section visibility
     * Loads comments on first open (lazy loading)
     *
     * @param {number} postId - Audio post ID
     */
    async toggle(postId) {
        // ENTERPRISE V6: Use getContainerId for custom container support
        const containerId = this.getContainerId(postId);
        const section = document.getElementById(containerId);
        if (!section) {
            console.error('[CommentManager] Comments section not found:', containerId);
            return;
        }

        // Toggle visibility
        if (!section.classList.contains('hidden')) {
            section.classList.add('hidden');
            return;
        }

        section.classList.remove('hidden');

        // Load comments if not already loaded
        if (!this.loadedPosts.has(postId) && !this.loadingPosts.has(postId)) {
            await this.loadComments(postId);
        }
    }

    /**
     * Load comments for a post into a custom container
     *
     * ENTERPRISE V6 (2025-11-30): Support for modal/lightbox comments
     * Used by AudioDayModal to load comments into modal-specific containers
     *
     * @param {number} postId - Audio post ID
     * @param {string} containerId - Custom container element ID
     */
    async loadCommentsForContainer(postId, containerId) {
        // Register custom container mapping
        if (!this.containerMappings) {
            this.containerMappings = new Map();
        }
        this.containerMappings.set(postId, containerId);

        // Reset pagination for fresh load
        this.pagination.delete(postId);
        this.loadedPosts.delete(postId);
        this.comments.delete(postId);

        // Delegate to loadComments
        await this.loadComments(postId, false);
    }

    /**
     * Get the container ID for a post (supports custom container mappings)
     *
     * @param {number} postId - Audio post ID
     * @returns {string} Container element ID
     */
    getContainerId(postId) {
        // Check for custom container mapping (modal/lightbox)
        if (this.containerMappings && this.containerMappings.has(postId)) {
            return this.containerMappings.get(postId);
        }
        // Default: standard comments-{postId} format
        return `comments-${postId}`;
    }

    /**
     * Load comments for a post
     *
     * @param {number} postId - Audio post ID
     * @param {boolean} append - Append to existing (pagination)
     */
    async loadComments(postId, append = false) {
        if (this.loadingPosts.has(postId)) return;

        this.loadingPosts.add(postId);
        // ENTERPRISE V6: Use getContainerId for custom container support
        const containerId = this.getContainerId(postId);
        const section = document.getElementById(containerId);

        if (!append) {
            section.innerHTML = `
                <div class="text-center text-gray-400 py-4">
                    <svg class="animate-spin h-5 w-5 mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span class="mt-2 block">Caricamento commenti...</span>
                </div>
            `;
        }

        try {
            const pag = this.pagination.get(postId) || { offset: 0, hasMore: true };
            const offset = append ? pag.offset : 0;

            const response = await api.get(`/api/comments/post/${postId}?limit=${this.config.commentsPerPage}&offset=${offset}`);

            if (!response.success) {
                throw new Error(response.error || 'Errore nel caricamento');
            }

            // Update pagination state
            const newOffset = offset + (response.comments?.length || 0);
            this.pagination.set(postId, {
                offset: newOffset,
                hasMore: response.has_more || false,
                total: response.total || 0
            });

            // Store comments
            if (append) {
                const existing = this.comments.get(postId) || [];
                this.comments.set(postId, [...existing, ...response.comments]);
            } else {
                this.comments.set(postId, response.comments || []);
            }

            // Mark as loaded
            this.loadedPosts.add(postId);

            // Render
            this.renderComments(postId, append);

        } catch (error) {
            console.error('[CommentManager] Load failed:', error);
            section.innerHTML = `
                <div class="text-center text-red-400 py-4">
                    <span>Errore nel caricamento dei commenti</span>
                    <button onclick="window.commentManager.loadComments(${postId})"
                            class="block mx-auto mt-2 text-purple-400 hover:text-purple-300">
                        Riprova
                    </button>
                </div>
            `;
        } finally {
            this.loadingPosts.delete(postId);
        }
    }

    /**
     * Render comments section HTML
     *
     * @param {number} postId - Audio post ID
     * @param {boolean} append - Append mode
     */
    renderComments(postId, append = false) {
        // ENTERPRISE V6: Use getContainerId for custom container support
        const containerId = this.getContainerId(postId);
        const section = document.getElementById(containerId);
        if (!section) return;

        const comments = this.comments.get(postId) || [];
        const pag = this.pagination.get(postId) || { hasMore: false, total: 0 };

        let html = '';

        // Comment form (always at top)
        html += this.renderCommentForm(postId);

        // Comments list
        if (comments.length === 0) {
            html += `
                <div class="text-center text-gray-400 py-6">
                    <svg class="w-12 h-12 mx-auto text-gray-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                    </svg>
                    <p>Nessun commento ancora</p>
                    <p class="text-sm text-gray-500 mt-1">Sii il primo a commentare!</p>
                </div>
            `;
        } else {
            html += '<div class="space-y-4 mt-4">';
            comments.forEach(comment => {
                html += this.renderComment(comment, postId);
            });
            html += '</div>';

            // Load more button
            if (pag.hasMore) {
                html += `
                    <div class="text-center mt-4">
                        <button onclick="window.commentManager.loadComments(${postId}, true)"
                                class="px-4 py-2 text-purple-400 hover:text-purple-300 text-sm font-medium">
                            Carica altri commenti...
                        </button>
                    </div>
                `;
            }
        }

        section.innerHTML = html;

        // Initialize emoji picker events
        this.initEmojiTriggers(section);
    }

    /**
     * Render comment form HTML
     *
     * ENTERPRISE V4.7 (2025-11-29): Added mentionPrefix for reply-to-reply
     *
     * @param {number} postId - Audio post ID
     * @param {number|null} parentCommentId - Parent comment ID for replies
     * @param {string} mentionPrefix - Optional @mention prefix (for replying to replies)
     * @returns {string} HTML
     */
    renderCommentForm(postId, parentCommentId = null, mentionPrefix = '') {
        const formId = parentCommentId ? `reply-form-${parentCommentId}` : `comment-form-${postId}`;
        const placeholder = parentCommentId ? 'Scrivi una risposta...' : 'Scrivi un commento...';

        return `
            <form id="${formId}" class="flex items-start space-x-3 ${parentCommentId ? 'mt-3 ml-11' : ''}"
                  onsubmit="event.preventDefault(); window.commentManager.submitComment(${postId}, ${parentCommentId || 'null'}, this)">
                <img src="${escapeHtml(this.currentUserAvatar)}"
                     alt="${escapeHtml(this.currentUserNickname)}"
                     class="w-8 h-8 rounded-full flex-shrink-0"
                     onerror="this.src='/assets/img/default-avatar.png'; this.onerror=null;">
                <div class="flex-1 relative">
                    <div class="relative">
                        <textarea name="text"
                                  placeholder="${placeholder}"
                                  maxlength="${this.config.maxCommentLength}"
                                  rows="1"
                                  class="w-full px-4 py-2 pr-20 bg-gray-700/50 border border-gray-600 rounded-xl text-white placeholder-gray-400 focus:ring-2 focus:ring-purple-500 focus:border-transparent resize-none text-sm"
                                  onfocus="window.commentManager.loadFriendsList()"
                                  oninput="this.style.height = 'auto'; this.style.height = Math.min(this.scrollHeight, 120) + 'px'; window.commentManager.handleMentionInput(this, ${postId})"
                                  onkeydown="if(window.commentManager.handleMentionKeydown(event, this)) return false;"></textarea>
                        <div class="absolute right-2 top-1/2 -translate-y-1/2 flex items-center space-x-1">
                            <button type="button"
                                    class="emoji-trigger p-1 text-xl hover:scale-110 transition-transform"
                                    title="Aggiungi emoji"
                                    data-target="${formId}">
                                😊
                            </button>
                            <button type="submit"
                                    class="p-1 text-purple-400 hover:text-purple-300 transition-colors"
                                    title="Invia">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div class="text-xs text-gray-500 mt-1 text-right">
                        <span class="char-count">0</span>/${this.config.maxCommentLength}
                    </div>
                </div>
            </form>
        `;
    }

    /**
     * Render single comment HTML
     *
     * @param {Object} comment - Comment data
     * @param {number} postId - Parent post ID
     * @param {boolean} isReply - Is this a reply
     * @returns {string} HTML
     */
    renderComment(comment, postId, isReply = false) {
        const timeAgo = typeof formatTimeAgo === 'function' ? formatTimeAgo(comment.created_at) : comment.created_at;

        // ENTERPRISE: Normalize data structure (API returns nested 'author' after create, flat after list)
        const authorUuid = comment.author?.uuid || comment.author_uuid || comment.user_uuid || '';
        const authorNickname = comment.author?.nickname || comment.nickname || '';
        const commentText = comment.text || comment.comment_text || '';

        // ENTERPRISE: Normalize avatar URL (add leading slash if missing, fallback to default)
        let authorAvatar = comment.author?.avatar_url || comment.avatar_url || '';
        if (authorAvatar && !authorAvatar.startsWith('/') && !authorAvatar.startsWith('http')) {
            authorAvatar = '/' + authorAvatar;
        }
        if (!authorAvatar) {
            authorAvatar = '/assets/img/default-avatar.png';
        }

        // ENTERPRISE SECURITY: Compare UUIDs, not numeric IDs
        const isOwner = authorUuid === this.currentUserUuid || comment.is_owner === true;
        const hasLiked = comment.user_liked || false;
        const likeCount = comment.like_count || 0;
        const replyCount = comment.reply_count || 0;
        const isEdited = comment.is_edited || false;
        // ENTERPRISE V4.13 (2025-11-30): Edit count for 3-edit limit (only sent to owners)
        const editCount = comment.edit_count ?? 0;
        const canEdit = isOwner && editCount < 3;

        // ENTERPRISE V4 (2025-11-28): Extract mentioned users for highlighting
        const mentionedUsers = comment.mentioned_users || [];

        let html = `
            <div class="comment-item ${isReply ? 'ml-11 mt-3' : ''}" data-comment-id="${comment.id}">
                <div class="flex items-start space-x-3">
                    <a href="/u/${escapeHtml(authorUuid)}" class="flex-shrink-0">
                        <img src="${escapeHtml(authorAvatar)}"
                             alt="${escapeHtml(authorNickname)}"
                             class="w-8 h-8 rounded-full hover:ring-2 hover:ring-purple-500 transition-all"
                             onerror="this.src='/assets/img/default-avatar.png'; this.onerror=null;">
                    </a>
                    <div class="flex-1 min-w-0">
                        <div class="bg-gray-700/50 rounded-xl px-4 py-2">
                            <div class="flex items-center space-x-2">
                                <a href="/u/${escapeHtml(authorUuid)}"
                                   class="font-semibold text-white text-sm hover:text-purple-400 transition-colors">
                                    ${escapeHtml(authorNickname)}
                                </a>
                                ${isEdited ? `
                                    <button onclick="window.commentManager.showEditHistory(${comment.id})"
                                            class="flex items-center space-x-1 text-xs text-gray-500 hover:text-purple-400 transition-colors"
                                            title="Visualizza cronologia modifiche">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        <span>(modificato)</span>
                                    </button>
                                ` : ''}
                            </div>
                            <p class="text-gray-300 text-sm mt-1 break-words comment-text">${this.formatTextWithMentions(commentText, mentionedUsers)}</p>
                        </div>

                        <!-- Comment Actions -->
                        <div class="flex items-center space-x-4 mt-1 text-xs text-gray-400 px-2">
                            <span>${timeAgo}</span>

                            <!-- Like Button -->
                            <button onclick="window.commentManager.toggleLike(${comment.id}, ${postId})"
                                    class="like-btn flex items-center space-x-1 hover:text-pink-400 transition-colors ${hasLiked ? 'text-pink-500' : ''}"
                                    data-liked="${hasLiked}">
                                <svg class="w-4 h-4 ${hasLiked ? 'fill-current' : ''}" fill="${hasLiked ? 'currentColor' : 'none'}" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                                </svg>
                                <span class="like-count">${likeCount > 0 ? likeCount : ''}</span>
                            </button>

                            <!-- Reply Button (works for both comments and replies) -->
                            <button onclick="window.commentManager.showReplyForm(${comment.id}, ${postId}, ${isReply}, '${escapeHtml(authorNickname)}')"
                                    class="hover:text-purple-400 transition-colors">
                                Rispondi
                            </button>

                            ${isOwner ? `
                                ${canEdit ? `
                                    <!-- Edit Button (hidden after 3 edits) -->
                                    <button onclick="window.commentManager.editComment(${comment.id}, ${postId})"
                                            class="hover:text-purple-400 transition-colors">
                                        Modifica
                                    </button>
                                ` : editCount >= 3 ? `
                                    <!-- Edit Limit Reached -->
                                    <span class="text-gray-500 cursor-not-allowed" title="Limite di 3 modifiche raggiunto">
                                        Modifica (max)
                                    </span>
                                ` : ''}

                                <!-- Delete Button -->
                                <button onclick="window.commentManager.confirmDeleteComment(${comment.id}, ${postId})"
                                        class="hover:text-red-400 transition-colors">
                                    Elimina
                                </button>
                            ` : ''}
                        </div>

                        <!-- Reply Form Container (hidden initially) -->
                        <div id="reply-container-${comment.id}" class="hidden"></div>

                        <!-- Replies Container -->
                        ${!isReply && replyCount > 0 ? `
                            <div id="replies-${comment.id}" class="mt-2">
                                <button onclick="window.commentManager.loadReplies(${comment.id}, ${postId})"
                                        class="text-xs text-purple-400 hover:text-purple-300 flex items-center space-x-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                                    </svg>
                                    <span>Mostra ${replyCount} ${replyCount === 1 ? 'risposta' : 'risposte'}</span>
                                </button>
                            </div>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;

        return html;
    }

    /**
     * Submit new comment
     *
     * @param {number} postId - Audio post ID
     * @param {number|null} parentCommentId - Parent comment ID for replies
     * @param {HTMLFormElement} form - Form element
     */
    async submitComment(postId, parentCommentId, form) {
        const textarea = form.querySelector('textarea[name="text"]');
        const text = textarea.value.trim();

        if (text.length < this.config.minCommentLength) {
            if (window.showToast) {
                window.showToast('Il commento non può essere vuoto', 'warning');
            }
            return;
        }

        if (text.length > this.config.maxCommentLength) {
            if (window.showToast) {
                window.showToast(`Il commento non può superare ${this.config.maxCommentLength} caratteri`, 'warning');
            }
            return;
        }

        // Disable form during submission
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalHTML = submitBtn.innerHTML;
        submitBtn.innerHTML = '<svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>';
        submitBtn.disabled = true;
        textarea.disabled = true;

        try {
            const response = await api.post('/api/comments', {
                post_id: postId,
                text: text,
                parent_comment_id: parentCommentId
            });

            if (!response.success) {
                throw new Error(response.error || 'Errore nel salvataggio');
            }

            // Clear form
            textarea.value = '';
            textarea.style.height = 'auto';
            form.querySelector('.char-count').textContent = '0';

            // If reply, hide reply form and add to replies
            if (parentCommentId) {
                const replyContainer = document.getElementById(`reply-container-${parentCommentId}`);
                if (replyContainer) {
                    replyContainer.classList.add('hidden');
                    replyContainer.innerHTML = '';
                }

                // ENTERPRISE V4 (2025-11-28): OPTIMISTIC UI for replies
                // Add reply to DOM - create container if first reply
                let repliesContainer = document.getElementById(`replies-${parentCommentId}`);

                if (!repliesContainer) {
                    // First reply - create the replies container
                    const parentComment = document.querySelector(`[data-comment-id="${parentCommentId}"]`);
                    if (parentComment) {
                        const replyFormContainer = document.getElementById(`reply-container-${parentCommentId}`);
                        repliesContainer = document.createElement('div');
                        repliesContainer.id = `replies-${parentCommentId}`;
                        repliesContainer.className = 'mt-2 space-y-2';

                        // Insert after reply form container
                        if (replyFormContainer) {
                            replyFormContainer.insertAdjacentElement('afterend', repliesContainer);
                        } else {
                            // Fallback: append to parent comment's content area
                            const contentArea = parentComment.querySelector('.flex-1.min-w-0');
                            if (contentArea) {
                                contentArea.appendChild(repliesContainer);
                            }
                        }
                    }
                }

                if (repliesContainer) {
                    const replyHtml = this.renderComment(response.comment, postId, true);
                    // Insert before the "load more replies" button or at end
                    const loadMoreBtn = repliesContainer.querySelector('button');
                    if (loadMoreBtn && loadMoreBtn.textContent.includes('Mostra')) {
                        loadMoreBtn.insertAdjacentHTML('beforebegin', replyHtml);
                    } else {
                        repliesContainer.insertAdjacentHTML('beforeend', replyHtml);
                    }
                }

                // Update reply count on parent comment
                this.updateReplyCount(parentCommentId, 1);

                // ENTERPRISE V5 (2025-11-29): Replies are comments too!
                // Update total comment count in post footer
                this.updateCommentCount(postId, 1);
            } else {
                // ENTERPRISE: OPTIMISTIC UI - Always render from response, NEVER reload from DB
                // Write-behind buffers the comment, DB might not have it yet
                // ENTERPRISE V6: Use getContainerId for custom container support
                const containerId = this.getContainerId(postId);
                const section = document.getElementById(containerId);
                let commentsList = section?.querySelector('.space-y-4');

                if (!commentsList) {
                    // No comments yet - remove empty state and create comments container
                    const emptyState = section?.querySelector('.text-center');
                    if (emptyState) {
                        emptyState.remove();
                    }

                    // Create the comments list container
                    const commentsContainer = document.createElement('div');
                    commentsContainer.className = 'space-y-4 mt-4';

                    // Insert after the comment form
                    const form = section?.querySelector('form');
                    if (form) {
                        form.insertAdjacentElement('afterend', commentsContainer);
                    } else {
                        section?.appendChild(commentsContainer);
                    }

                    commentsList = commentsContainer;
                }

                // Render and add the new comment
                const commentHtml = this.renderComment(response.comment, postId, false);
                commentsList.insertAdjacentHTML('afterbegin', commentHtml);

                // Store comment in local cache for consistency
                const existingComments = this.comments.get(postId) || [];
                existingComments.unshift(response.comment);
                this.comments.set(postId, existingComments);
                this.loadedPosts.add(postId);

                // Update comment count in post footer
                this.updateCommentCount(postId, 1);
            }

            if (window.showToast) {
                window.showToast(parentCommentId ? 'Risposta aggiunta!' : 'Commento aggiunto!', 'success');
            }

        } catch (error) {
            console.error('[CommentManager] Submit failed:', error);
            if (window.showToast) {
                window.showToast(error.message || 'Errore nel salvataggio', 'error');
            }
        } finally {
            submitBtn.innerHTML = originalHTML;
            submitBtn.disabled = false;
            textarea.disabled = false;
            textarea.focus();
        }
    }

    /**
     * Show reply form for a comment
     *
     * ENTERPRISE V4.7 (2025-11-29): Facebook-style reply to replies
     * When replying to a reply, the comment is attached to the ROOT comment
     * but pre-filled with @nickname of the user being replied to
     *
     * @param {number} commentId - Comment ID (could be root or reply)
     * @param {number} postId - Post ID
     * @param {boolean} isReply - Whether commentId is a reply (has parent)
     * @param {string} replyToNickname - Nickname of user being replied to
     */
    showReplyForm(commentId, postId, isReply = false, replyToNickname = '') {
        // ENTERPRISE V4.7: If replying to a reply, we need to find the ROOT comment
        // The reply form should be attached to ROOT, with @mention prefilled
        let rootCommentId = commentId;
        let mentionPrefix = '';

        // ENTERPRISE V4.8 (2025-11-29): ALWAYS pre-fill @mention when replying
        // Works for both top-level comments AND replies
        if (replyToNickname) {
            mentionPrefix = `@${replyToNickname} `;
        }

        if (isReply) {
            // Find the root comment by traversing up the DOM
            const replyElement = document.querySelector(`[data-comment-id="${commentId}"]`);
            if (replyElement) {
                // The reply is inside a replies container which is a sibling of the root comment
                // Structure: root-comment > ... > replies-{rootId} > reply-comment
                const repliesContainer = replyElement.closest('[id^="replies-"]');
                if (repliesContainer) {
                    const match = repliesContainer.id.match(/replies-(\d+)/);
                    if (match) {
                        rootCommentId = parseInt(match[1]);
                    }
                }
            }
        }

        const container = document.getElementById(`reply-container-${rootCommentId}`);
        if (!container) return;

        // Toggle if already visible AND same target
        if (!container.classList.contains('hidden') && !mentionPrefix) {
            container.classList.add('hidden');
            container.innerHTML = '';
            return;
        }

        // Hide other reply forms
        document.querySelectorAll('[id^="reply-container-"]').forEach(el => {
            if (el.id !== `reply-container-${rootCommentId}`) {
                el.classList.add('hidden');
                el.innerHTML = '';
            }
        });

        container.innerHTML = this.renderCommentForm(postId, rootCommentId, mentionPrefix);
        container.classList.remove('hidden');

        // Init emoji triggers
        this.initEmojiTriggers(container);

        // Focus textarea and position cursor after @mention
        const textarea = container.querySelector('textarea');
        if (textarea) {
            if (mentionPrefix) {
                textarea.value = mentionPrefix;
                textarea.setSelectionRange(mentionPrefix.length, mentionPrefix.length);
            }
            textarea.focus();
        }
    }

    /**
     * Load replies for a comment
     *
     * @param {number} commentId - Parent comment ID
     * @param {number} postId - Post ID
     */
    async loadReplies(commentId, postId) {
        const container = document.getElementById(`replies-${commentId}`);
        if (!container) return;

        container.innerHTML = `
            <div class="text-center text-gray-400 py-2">
                <svg class="animate-spin h-4 w-4 inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </div>
        `;

        try {
            const response = await api.get(`/api/comments/${commentId}/replies`);

            if (!response.success) {
                throw new Error(response.error || 'Errore nel caricamento');
            }

            let html = '';
            (response.replies || []).forEach(reply => {
                html += this.renderComment(reply, postId, true);
            });

            container.innerHTML = html || '<p class="text-xs text-gray-500 ml-11 mt-2">Nessuna risposta</p>';

        } catch (error) {
            console.error('[CommentManager] Load replies failed:', error);
            container.innerHTML = `
                <button onclick="window.commentManager.loadReplies(${commentId}, ${postId})"
                        class="text-xs text-red-400 hover:text-red-300">
                    Errore. Clicca per riprovare
                </button>
            `;
        }
    }

    /**
     * Toggle like on comment (optimistic UI)
     *
     * @param {number} commentId - Comment ID
     * @param {number} postId - Post ID
     */
    async toggleLike(commentId, postId) {
        const commentEl = document.querySelector(`[data-comment-id="${commentId}"]`);
        if (!commentEl) return;

        const likeBtn = commentEl.querySelector('.like-btn');
        const likeCountEl = likeBtn.querySelector('.like-count');
        const isLiked = likeBtn.dataset.liked === 'true';
        const currentCount = parseInt(likeCountEl.textContent) || 0;

        // Optimistic update
        const newCount = isLiked ? currentCount - 1 : currentCount + 1;
        likeBtn.dataset.liked = (!isLiked).toString();
        likeCountEl.textContent = newCount > 0 ? newCount : '';

        // Update heart icon
        const svg = likeBtn.querySelector('svg');
        if (isLiked) {
            likeBtn.classList.remove('text-pink-500');
            svg.classList.remove('fill-current');
            svg.setAttribute('fill', 'none');
        } else {
            likeBtn.classList.add('text-pink-500');
            svg.classList.add('fill-current');
            svg.setAttribute('fill', 'currentColor');
        }

        try {
            let response;
            if (isLiked) {
                response = await api.delete(`/api/comments/${commentId}/like`);
            } else {
                response = await api.post(`/api/comments/${commentId}/like`);
            }

            if (!response.success) {
                throw new Error(response.error || 'Errore');
            }

            // Update with server count (if different from optimistic)
            if (response.like_count !== undefined) {
                likeCountEl.textContent = response.like_count > 0 ? response.like_count : '';
            }

        } catch (error) {
            console.error('[CommentManager] Toggle like failed:', error);

            // Rollback optimistic update
            likeBtn.dataset.liked = isLiked.toString();
            likeCountEl.textContent = currentCount > 0 ? currentCount : '';

            if (isLiked) {
                likeBtn.classList.add('text-pink-500');
                svg.classList.add('fill-current');
                svg.setAttribute('fill', 'currentColor');
            } else {
                likeBtn.classList.remove('text-pink-500');
                svg.classList.remove('fill-current');
                svg.setAttribute('fill', 'none');
            }

            if (window.showToast) {
                window.showToast('Errore nel salvataggio', 'error');
            }
        }
    }

    /**
     * Edit comment
     *
     * @param {number} commentId - Comment ID
     * @param {number} postId - Post ID
     */
    editComment(commentId, postId) {
        const commentEl = document.querySelector(`[data-comment-id="${commentId}"]`);
        if (!commentEl) return;

        const textEl = commentEl.querySelector('.comment-text');
        const currentText = textEl.textContent;
        const container = textEl.parentElement;

        // Replace text with edit form
        const originalHTML = container.innerHTML;
        container.innerHTML = `
            <textarea class="edit-textarea w-full px-3 py-2 bg-gray-600 border border-gray-500 rounded-lg text-white text-sm resize-none"
                      rows="2"
                      maxlength="${this.config.maxCommentLength}">${escapeHtml(currentText)}</textarea>
            <div class="flex items-center justify-between mt-2">
                <span class="text-xs text-gray-500">
                    <span class="edit-char-count">${currentText.length}</span>/${this.config.maxCommentLength}
                </span>
                <div class="space-x-2">
                    <button onclick="window.commentManager.cancelEdit(${commentId}, ${postId})"
                            class="px-3 py-1 text-xs text-gray-400 hover:text-gray-300">
                        Annulla
                    </button>
                    <button onclick="window.commentManager.saveEdit(${commentId}, ${postId})"
                            class="px-3 py-1 text-xs bg-purple-600 hover:bg-purple-700 text-white rounded-lg">
                        Salva
                    </button>
                </div>
            </div>
        `;

        // Store original HTML
        container.dataset.originalHtml = originalHTML;

        // Focus textarea
        const textarea = container.querySelector('.edit-textarea');
        textarea.focus();
        textarea.setSelectionRange(textarea.value.length, textarea.value.length);

        // Char count update
        textarea.addEventListener('input', () => {
            container.querySelector('.edit-char-count').textContent = textarea.value.length;
        });
    }

    /**
     * Cancel edit mode
     *
     * @param {number} commentId - Comment ID
     * @param {number} postId - Post ID
     */
    cancelEdit(commentId, postId) {
        const commentEl = document.querySelector(`[data-comment-id="${commentId}"]`);
        if (!commentEl) return;

        const container = commentEl.querySelector('.bg-gray-700\\/50');
        if (container && container.dataset.originalHtml) {
            container.innerHTML = container.dataset.originalHtml;
            delete container.dataset.originalHtml;
        }
    }

    /**
     * Save edited comment
     *
     * @param {number} commentId - Comment ID
     * @param {number} postId - Post ID
     */
    async saveEdit(commentId, postId) {
        const commentEl = document.querySelector(`[data-comment-id="${commentId}"]`);
        if (!commentEl) return;

        const container = commentEl.querySelector('.bg-gray-700\\/50');
        const textarea = container.querySelector('.edit-textarea');
        const newText = textarea.value.trim();

        if (newText.length < this.config.minCommentLength) {
            if (window.showToast) {
                window.showToast('Il commento non può essere vuoto', 'warning');
            }
            return;
        }

        try {
            const response = await api.put(`/api/comments/${commentId}`, {
                text: newText
            });

            if (!response.success) {
                // ENTERPRISE V4.13 (2025-11-30): Handle max_edits_reached error
                if (response.error === 'max_edits_reached') {
                    if (window.showToast) {
                        window.showToast('Hai raggiunto il limite massimo di 3 modifiche', 'warning');
                    }
                    // Restore original and reload to show updated UI
                    this.cancelEdit(commentId, postId);
                    await this.loadComments(postId, false);
                    return;
                }
                throw new Error(response.error || 'Errore nel salvataggio');
            }

            // Update original HTML with new text and (modificato) tag
            container.innerHTML = `
                <div class="flex items-center space-x-2">
                    <span class="font-semibold text-white text-sm">${escapeHtml(response.comment?.nickname || '')}</span>
                    <span class="text-xs text-gray-500">(modificato)</span>
                </div>
                <p class="text-gray-300 text-sm mt-1 break-words comment-text">${escapeHtml(newText)}</p>
            `;
            delete container.dataset.originalHtml;

            if (window.showToast) {
                window.showToast('Commento modificato', 'success');
            }

            // ENTERPRISE V4.13 (2025-11-30): Reload comments to update edit_count in UI
            // This ensures "Modifica" button shows correctly after edit
            await this.loadComments(postId, false);

        } catch (error) {
            console.error('[CommentManager] Save edit failed:', error);
            if (window.showToast) {
                window.showToast(error.message || 'Errore nel salvataggio', 'error');
            }
        }
    }

    /**
     * Confirm delete comment
     *
     * @param {number} commentId - Comment ID
     * @param {number} postId - Post ID
     */
    confirmDeleteComment(commentId, postId) {
        if (confirm('Sei sicuro di voler eliminare questo commento?')) {
            this.deleteComment(commentId, postId);
        }
    }

    /**
     * Delete comment
     *
     * @param {number} commentId - Comment ID
     * @param {number} postId - Post ID
     */
    async deleteComment(commentId, postId) {
        try {
            const response = await api.delete(`/api/comments/${commentId}`);

            if (!response.success) {
                throw new Error(response.error || 'Errore nell\'eliminazione');
            }

            // Remove from DOM with animation
            const commentEl = document.querySelector(`[data-comment-id="${commentId}"]`);
            if (commentEl) {
                commentEl.classList.add('opacity-50', 'transition-opacity');
                setTimeout(() => {
                    commentEl.remove();

                    // Update comment count
                    this.updateCommentCount(postId, -1);

                    // Check if no comments left
                    // ENTERPRISE V6: Use getContainerId for custom container support
                    const containerId = this.getContainerId(postId);
                    const section = document.getElementById(containerId);
                    const remainingComments = section?.querySelectorAll('.comment-item').length || 0;
                    if (remainingComments === 0) {
                        this.loadedPosts.delete(postId);
                        this.pagination.delete(postId);
                        this.loadComments(postId);
                    }
                }, 200);
            }

            if (window.showToast) {
                window.showToast('Commento eliminato', 'success');
            }

        } catch (error) {
            console.error('[CommentManager] Delete failed:', error);
            if (window.showToast) {
                window.showToast(error.message || 'Errore nell\'eliminazione', 'error');
            }
        }
    }

    /**
     * Update comment count in post footer
     *
     * @param {number} postId - Post ID
     * @param {number} delta - Change amount (+1 or -1)
     */
    updateCommentCount(postId, delta) {
        const countEl = document.getElementById(`commentCount-${postId}`);
        if (countEl) {
            const current = parseInt(countEl.textContent.replace(/\D/g, '')) || 0;
            const newCount = Math.max(0, current + delta);
            countEl.textContent = typeof formatNumber === 'function' ? formatNumber(newCount) : newCount;

            // ENTERPRISE V9.6: Mark as optimistic pending to prevent double-counting
            // FeedManager.handleCounterUpdate will skip WebSocket delta for this element
            // The flag is cleared after the first WebSocket update is received
            countEl.dataset.optimisticPending = 'true';

            // Auto-clear flag after 5 seconds (safety net in case WebSocket update never arrives)
            setTimeout(() => {
                if (countEl.dataset.optimisticPending === 'true') {
                    delete countEl.dataset.optimisticPending;
                }
            }, 5000);
        }
    }

    /**
     * Update reply count on parent comment
     *
     * @param {number} commentId - Parent comment ID
     * @param {number} delta - Change amount
     */
    updateReplyCount(commentId, delta) {
        // This would update the "Mostra X risposte" button text
        // For now, just reload replies on next expand
        const container = document.getElementById(`replies-${commentId}`);
        if (container && !container.querySelector('.comment-item')) {
            // If replies weren't loaded yet, update the button text
            const btn = container.querySelector('button');
            if (btn && btn.textContent.includes('Mostra')) {
                const match = btn.textContent.match(/\d+/);
                if (match) {
                    const newCount = parseInt(match[0]) + delta;
                    btn.innerHTML = `
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                        </svg>
                        <span>Mostra ${newCount} ${newCount === 1 ? 'risposta' : 'risposte'}</span>
                    `;
                }
            }
        }
    }

    /**
     * Initialize emoji picker triggers in a container
     *
     * @param {HTMLElement} container - Container element
     */
    initEmojiTriggers(container) {
        container.querySelectorAll('.emoji-trigger').forEach(trigger => {
            trigger.addEventListener('click', () => {
                const targetFormId = trigger.dataset.target;
                const form = document.getElementById(targetFormId);
                if (form) {
                    const textarea = form.querySelector('textarea[name="text"]');
                    this.openEmojiPicker(textarea);
                }
            });
        });

        // Character count updates
        container.querySelectorAll('textarea[name="text"]').forEach(textarea => {
            const form = textarea.closest('form');
            const countEl = form?.querySelector('.char-count');
            if (countEl) {
                textarea.addEventListener('input', () => {
                    countEl.textContent = textarea.value.length;
                });
            }
        });
    }

    /**
     * Open emoji picker for a textarea
     *
     * @param {HTMLTextAreaElement} textarea - Target textarea
     */
    openEmojiPicker(textarea) {
        // ENTERPRISE V4: Check if EmojiData is available
        if (typeof window.EmojiData === 'undefined') {
            console.error('[CommentManager] EmojiData not loaded yet');
            if (window.showToast) {
                window.showToast('Caricamento emoji...', 'info', 1500);
            }
            // Try again in 500ms (defer loading might still be in progress)
            setTimeout(() => {
                if (typeof window.EmojiData !== 'undefined') {
                    this.openEmojiPicker(textarea);
                }
            }, 500);
            return;
        }

        // ENTERPRISE V4: Close any existing picker first (avoid duplicates)
        this.closeEmojiPicker();

        this._emojiTarget = textarea;

        // Create picker container
        let pickerContainer = document.getElementById('commentEmojiPicker');
        if (!pickerContainer) {
            pickerContainer = document.createElement('div');
            pickerContainer.id = 'commentEmojiPicker';
            document.body.appendChild(pickerContainer);
        }

        pickerContainer.innerHTML = window.EmojiData.generatePickerHTML();

        // Initialize events
        const pickerElement = pickerContainer.firstElementChild;
        if (!pickerElement) {
            console.error('[CommentManager] Failed to create emoji picker');
            return;
        }

        window.EmojiData.initPickerEvents(
            pickerElement,
            // On emoji select
            (emoji) => {
                if (this._emojiTarget) {
                    const start = this._emojiTarget.selectionStart || 0;
                    const end = this._emojiTarget.selectionEnd || 0;
                    const value = this._emojiTarget.value || '';
                    this._emojiTarget.value = value.substring(0, start) + emoji + value.substring(end);

                    const newPos = start + emoji.length;
                    this._emojiTarget.setSelectionRange(newPos, newPos);
                    this._emojiTarget.focus();

                    // Trigger input event for char count
                    this._emojiTarget.dispatchEvent(new Event('input'));
                }
                this.closeEmojiPicker();
            },
            // On close
            () => {
                this.closeEmojiPicker();
            }
        );
    }

    /**
     * Close emoji picker
     */
    closeEmojiPicker() {
        const picker = document.getElementById('commentEmojiPicker');
        if (picker) {
            picker.innerHTML = '';
        }
        this._emojiTarget = null;
    }

    // =========================================================================
    // EDIT HISTORY - ENTERPRISE V4 (2025-11-28)
    // =========================================================================

    /**
     * Show edit history popup for a comment
     *
     * @param {number} commentId - Comment ID
     */
    async showEditHistory(commentId) {
        try {
            // Show loading toast
            if (window.showToast) {
                window.showToast('Caricamento cronologia...', 'info', 1000);
            }

            const response = await api.get(`/api/comments/${commentId}/history`);

            if (!response.success) {
                throw new Error(response.error || 'Errore nel caricamento');
            }

            this.renderEditHistoryPopup(response);

        } catch (error) {
            console.error('[CommentManager] Load edit history failed:', error);
            if (window.showToast) {
                window.showToast('Errore nel caricamento cronologia', 'error');
            }
        }
    }

    /**
     * Render edit history popup/modal
     *
     * @param {Object} data - History data from API
     */
    renderEditHistoryPopup(data) {
        // Remove existing popup if any
        this.closeEditHistoryPopup();

        const popup = document.createElement('div');
        popup.id = 'editHistoryPopup';
        popup.className = 'fixed inset-0 z-50 flex items-center justify-center p-4';
        popup.innerHTML = `
            <!-- Backdrop -->
            <div class="absolute inset-0 bg-black/70" onclick="window.commentManager.closeEditHistoryPopup()"></div>

            <!-- Modal -->
            <div class="relative bg-gray-800 border border-gray-700 rounded-xl shadow-2xl max-w-lg w-full max-h-[80vh] overflow-hidden animate-fade-in">
                <!-- Header -->
                <div class="flex items-center justify-between px-4 py-3 border-b border-gray-700">
                    <h3 class="text-white font-semibold flex items-center space-x-2">
                        <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Cronologia modifiche</span>
                    </h3>
                    <button onclick="window.commentManager.closeEditHistoryPopup()"
                            class="text-gray-400 hover:text-white transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Content -->
                <div class="overflow-y-auto max-h-[60vh] p-4 space-y-4">
                    <!-- Current Version -->
                    <div class="bg-purple-900/30 border border-purple-700/50 rounded-lg p-3">
                        <div class="flex items-center space-x-2 mb-2">
                            <span class="text-xs font-medium text-purple-400 uppercase tracking-wide">Versione attuale</span>
                        </div>
                        <p class="text-gray-200 text-sm break-words">${escapeHtml(data.current_text)}</p>
                    </div>

                    ${data.history && data.history.length > 0 ? `
                        <!-- Previous Versions -->
                        <div class="space-y-3">
                            <span class="text-xs font-medium text-gray-500 uppercase tracking-wide">Versioni precedenti</span>
                            ${data.history.map((entry, index) => `
                                <div class="bg-gray-700/50 border border-gray-600 rounded-lg p-3">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-xs text-gray-500">
                                            ${typeof formatTimeAgo === 'function' ? formatTimeAgo(entry.edited_at) : entry.edited_at}
                                        </span>
                                        <span class="text-xs text-gray-600">#${data.history.length - index}</span>
                                    </div>
                                    <p class="text-gray-300 text-sm break-words">${escapeHtml(entry.text)}</p>
                                </div>
                            `).join('')}
                        </div>
                    ` : `
                        <p class="text-center text-gray-500 text-sm py-4">
                            Nessuna versione precedente salvata
                        </p>
                    `}
                </div>

                <!-- Footer -->
                <div class="px-4 py-3 border-t border-gray-700 text-center">
                    <span class="text-xs text-gray-500">
                        Le versioni precedenti sono conservate per trasparenza
                    </span>
                </div>
            </div>
        `;

        document.body.appendChild(popup);

        // Prevent body scroll
        document.body.style.overflow = 'hidden';

        // Close on Escape key
        document.addEventListener('keydown', this._handleEscapeKey = (e) => {
            if (e.key === 'Escape') {
                this.closeEditHistoryPopup();
            }
        });
    }

    /**
     * Close edit history popup
     */
    closeEditHistoryPopup() {
        const popup = document.getElementById('editHistoryPopup');
        if (popup) {
            popup.remove();
        }

        // Restore body scroll
        document.body.style.overflow = '';

        // Remove escape key listener
        if (this._handleEscapeKey) {
            document.removeEventListener('keydown', this._handleEscapeKey);
            this._handleEscapeKey = null;
        }
    }

    /**
     * Invalidate cache for a post (force reload on next open)
     *
     * @param {number} postId - Post ID
     */
    invalidate(postId) {
        this.loadedPosts.delete(postId);
        this.comments.delete(postId);
        this.pagination.delete(postId);
    }
}

// Global instance
window.commentManager = new CommentManager();

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = CommentManager;
}
