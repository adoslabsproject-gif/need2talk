/**
 * need2talk - Feed Manager
 * Enterprise Galaxy - Social feed management with infinite scroll
 *
 * Purpose: Load, render, and manage audio social feed
 * Features: Infinite scroll, like/unlike, comments, pagination
 * Performance: Virtual scrolling ready, optimized DOM updates
 */

class FeedManager {
    constructor(config) {
        this.config = {
            containerId: 'feedContainer',
            loadMoreBtnId: 'loadMoreBtn',
            loadingId: 'loadingMore',
            sortSelectId: 'feedSortSelect',
            apiEndpoint: '/api/audio/feed',
            perPage: 10,
            // ENTERPRISE: Disable infinite scroll - use "Load more" button instead (better UX control)
            infiniteScroll: false,
            infiniteScrollOffset: 500, // px before bottom (unused when infiniteScroll=false)
            ...config
        };

        // State
        this.posts = [];
        this.currentPage = 1;
        this.isLoading = false;
        this.hasMore = true;
        this.container = null;
        this.loadMoreBtn = null;
        this.sortSelect = null;

        // Initialize
        this.init();
    }

    /**
     * Initialize feed manager
     * @private
     */
    init() {
        this.container = document.getElementById(this.config.containerId);
        this.loadMoreBtn = document.getElementById(this.config.loadMoreBtnId);
        this.sortSelect = document.getElementById(this.config.sortSelectId);

        if (!this.container) {
            // SILENT: Container doesn't exist on this page (e.g., Profile page)
            // FeedManager is loaded globally but only used on Feed page
            return;
        }

        // Bind events
        this.bindEvents();

        // ENTERPRISE V10.9: Setup viewport tracking for real-time counter updates
        this.setupPostViewportTracking();

        // Load initial feed
        this.loadFeed(true).then(() => {
            // ENTERPRISE V4.14 (2025-11-30): Check URL params for direct post opening
            // Supports notification click redirect: /feed?post=123#comment-456
            this.checkUrlParamsForDirectOpen();
        });
    }

    /**
     * ENTERPRISE V4.14 (2025-11-30): Check URL params for direct post/comment opening
     * Enables notification click → redirect → auto-open lightbox workflow
     * @private
     */
    checkUrlParamsForDirectOpen() {
        const urlParams = new URLSearchParams(window.location.search);
        const postId = urlParams.get('post');

        if (!postId) return;

        const postIdInt = parseInt(postId, 10);
        if (isNaN(postIdInt) || postIdInt <= 0) return;

        // Extract comment ID from hash if present (#comment-123)
        let commentId = null;
        const hash = window.location.hash;
        if (hash && hash.startsWith('#comment-')) {
            commentId = parseInt(hash.replace('#comment-', ''), 10);
            if (isNaN(commentId)) commentId = null;
        }

        // Wait a bit for lightbox to initialize, then open
        setTimeout(() => {
            if (window.photoLightbox && typeof window.photoLightbox.openByPostId === 'function') {
                window.photoLightbox.openByPostId(postIdInt, { commentId: commentId });

                // Clean URL after opening (remove query params but keep path)
                const cleanUrl = window.location.pathname;
                window.history.replaceState({}, document.title, cleanUrl);
            } else {
                console.warn('[FeedManager] photoLightbox not available for direct open');
            }
        }, 500);
    }

    /**
     * Bind event handlers
     * @private
     */
    bindEvents() {
        // Sort change
        if (this.sortSelect) {
            this.sortSelect.addEventListener('change', () => {
                this.resetFeed();
            });
        }

        // Load more button
        if (this.loadMoreBtn) {
            this.loadMoreBtn.addEventListener('click', () => {
                this.loadMore();
            });
        }

        // Infinite scroll
        if (this.config.infiniteScroll) {
            window.addEventListener('scroll', throttle(() => {
                this.handleScroll();
            }, 200));
        }

        // Photo lightbox click handler (event delegation)
        // ENTERPRISE V4: Pass FULL post data to lightbox to avoid API call
        // ENTERPRISE V11.5: Gallery navigation with prev/next arrows (like profile)
        this.container.addEventListener('click', (e) => {
            const trigger = e.target.closest('.photo-lightbox-trigger');
            if (trigger && window.photoLightbox) {
                const photoUrl = trigger.dataset.photoUrl;
                const postId = parseInt(trigger.dataset.postId, 10);
                if (photoUrl) {
                    // ENTERPRISE GALAXY: Get FULL post object from memory
                    const post = this.posts.find(p => p.id === postId);
                    const postIndex = this.posts.findIndex(p => p.id === postId);

                    if (post) {
                        // Setup gallery navigation (enables prev/next arrows)
                        this.setupGalleryNavigation(postIndex);

                        // Pass the FULL post object - PhotoLightbox will extract what it needs
                        window.photoLightbox.openWithPostData(photoUrl, postId, post);

                        // ENTERPRISE V11.5: Update navigation UI AFTER opening
                        // (openWithPostData doesn't call updateNavigationUI)
                        setTimeout(() => {
                            window.photoLightbox.updateNavigationUI();
                        }, 100);
                    } else {
                        // Fallback: open without post data (will fetch from API)
                        console.warn('[FeedManager] Post not found in memory, opening without data');
                        window.photoLightbox.open(photoUrl, postId);
                    }
                }
            }
        });

        // Post menu button click handler (event delegation with stopPropagation)
        // CRITICAL: Using event delegation to properly handle click events
        // and prevent immediate closing due to document click listener
        this.container.addEventListener('click', (e) => {
            const menuBtn = e.target.closest('.post-menu-btn');
            if (menuBtn) {
                e.stopPropagation();
                e.preventDefault();
                const postId = parseInt(menuBtn.dataset.postId, 10);
                if (postId) {
                    this.showPostMenu(postId);
                }
            }
        });
    }

    /**
     * ENTERPRISE V10.9: Setup viewport tracking for real-time counter broadcasts
     * Uses IntersectionObserver to track visible posts and subscribe via WebSocket
     * @private
     */
    setupPostViewportTracking() {
        // State for tracking visible posts
        this.visiblePostIds = new Set();
        this.subscriptionTimeout = null;
        this.postObserver = null;

        // Create IntersectionObserver to track visible posts
        // ENTERPRISE FIX (2025-12-08): Removed console.log spam - was causing 50% CPU usage
        this.postObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                const postId = parseInt(entry.target.dataset.postId);
                if (!postId || isNaN(postId)) return;

                if (entry.isIntersecting) {
                    this.visiblePostIds.add(postId);
                } else {
                    this.visiblePostIds.delete(postId);
                }
            });

            // Debounced subscription update (2s)
            this.scheduleSubscriptionUpdate();
        }, {
            threshold: 0.1, // 10% visible triggers
            rootMargin: '100px' // Pre-fetch 100px before entering viewport
        });

        // Observe existing posts (if any already rendered)
        this.observeAllPosts();

        // Listen for counter updates from WebSocket
        window.addEventListener('n2t:postCounterUpdate', (e) => {
            this.handleCounterUpdate(e.detail);
        });

        // ENTERPRISE V10.9.1: Re-subscribe when WebSocket connects/reconnects
        // This ensures subscriptions are sent even if WS wasn't ready initially
        window.addEventListener('n2t:wsConnected', () => {
            // Small delay to ensure WS is fully ready
            setTimeout(() => this.sendPostSubscription(), 500);
        });
    }

    /**
     * Observe all posts in the feed container
     * @private
     */
    observeAllPosts() {
        if (!this.postObserver || !this.container) return;

        const postElements = this.container.querySelectorAll('[data-post-id]');

        postElements.forEach(el => {
            this.postObserver.observe(el);
        });

        // Force initial check after observing
        if (postElements.length > 0) {
            setTimeout(() => this.scheduleSubscriptionUpdate(), 100);
        }
    }

    /**
     * Schedule subscription update (debounced)
     * @private
     */
    scheduleSubscriptionUpdate() {
        if (this.subscriptionTimeout) {
            clearTimeout(this.subscriptionTimeout);
        }

        this.subscriptionTimeout = setTimeout(() => {
            this.sendPostSubscription();
        }, 2000); // 2s debounce
    }

    /**
     * Send post subscription to WebSocket server
     * @private
     *
     * ENTERPRISE V10.41: Fixed to use window.WebSocketManager (ROOT version)
     * Previously used Need2Talk.WebSocketManager which was never loaded.
     * Also fixed send() signature: ROOT uses send(data) not send(type, payload)
     */
    sendPostSubscription() {
        const wsManager = window.WebSocketManager;

        if (!wsManager?.isConnected) return;

        const postIds = Array.from(this.visiblePostIds).slice(0, 50); // Max 50 posts
        if (postIds.length === 0) return;

        wsManager.send({
            type: 'subscribe_posts',
            post_ids: postIds
        });
    }

    /**
     * Handle counter update from WebSocket
     * @private
     * @param {Object} detail Event detail {postId, counters, audioFileId, actor_user_uuid}
     */
    handleCounterUpdate(detail) {
        const { postId, counters, audioFileId } = detail;

        if (!postId || !counters) return;

        // ENTERPRISE V10.2 (2025-12-10): Skip broadcast if actor is current user
        // This prevents double-counting: user already applied optimistic update,
        // broadcast is for OTHER users viewing the same post
        // Uses UUID (not numeric ID) for security (prevent user enumeration)
        if (detail.actor_user_uuid && window.need2talk?.user?.uuid) {
            if (detail.actor_user_uuid === window.need2talk.user.uuid) {
                console.log(`[FeedManager] Skipping own broadcast for post ${postId} (actor=${detail.actor_user_uuid.substring(0, 8)}...)`);
                return;
            }
        }

        // Find post element
        const postEl = this.container?.querySelector(`[data-post-id="${postId}"]`);
        if (!postEl) return;

        // Update comment count
        if (counters.comment_count !== undefined) {
            const commentEl = postEl.querySelector(`#commentCount-${postId}`);
            if (commentEl) {
                // ENTERPRISE V9.6: Skip if pending optimistic update
                if (commentEl.dataset.optimisticPending === 'true') {
                    delete commentEl.dataset.optimisticPending;
                } else {
                    // Apply delta from WebSocket (update from OTHER users)
                    const current = parseInt(commentEl.textContent) || 0;
                    const newCount = Math.max(0, current + parseInt(counters.comment_count));
                    commentEl.textContent = formatNumber(newCount);

                    // Visual feedback
                    commentEl.classList.add('text-purple-400');
                    setTimeout(() => commentEl.classList.remove('text-purple-400'), 500);
                }
            }
        }

        // Update play/listen count
        if (counters.play_count !== undefined) {
            const playEl = postEl.querySelector(`#listenCount-${postId}`);
            if (playEl) {
                // ENTERPRISE V9.6: Skip if pending optimistic update
                if (playEl.dataset.optimisticPending === 'true') {
                    delete playEl.dataset.optimisticPending;
                } else {
                    // Apply delta from WebSocket (update from OTHER users)
                    const current = parseInt(playEl.textContent) || 0;
                    const newCount = Math.max(0, current + parseInt(counters.play_count));
                    playEl.textContent = formatNumber(newCount);

                    // Visual feedback
                    playEl.classList.add('text-green-400');
                    setTimeout(() => playEl.classList.remove('text-green-400'), 500);
                }
            }
        }

        // Also update in-memory post data (only if not optimistic-pending)
        const postIndex = this.posts.findIndex(p => p.id === postId);
        if (postIndex !== -1) {
            const commentEl = postEl?.querySelector(`#commentCount-${postId}`);
            const playEl = postEl?.querySelector(`#listenCount-${postId}`);

            // Only update if not skipped due to optimistic
            if (counters.comment_count !== undefined && commentEl?.dataset.optimisticPending !== 'true') {
                this.posts[postIndex].comment_count = (this.posts[postIndex].comment_count || 0) + parseInt(counters.comment_count);
            }
            if (counters.play_count !== undefined && playEl?.dataset.optimisticPending !== 'true') {
                this.posts[postIndex].listen_count = (this.posts[postIndex].listen_count || 0) + parseInt(counters.play_count);
            }
        }
    }

    /**
     * Handle scroll event for infinite loading
     * @private
     */
    handleScroll() {
        if (this.isLoading || !this.hasMore) return;

        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const scrollHeight = document.documentElement.scrollHeight;
        const clientHeight = document.documentElement.clientHeight;

        if (scrollTop + clientHeight >= scrollHeight - this.config.infiniteScrollOffset) {
            this.loadMore();
        }
    }

    /**
     * Reset feed (e.g., after sort change)
     * @public
     */
    resetFeed() {
        this.posts = [];
        this.currentPage = 1;
        this.hasMore = true;
        this.container.innerHTML = '';
        this.loadFeed(true);
    }

    /**
     * Prepend new post to feed (instant update after upload)
     * ENTERPRISE GALAXY: Twitter/Instagram pattern (NO reload needed)
     *
     * @public
     * @param {Object} postData - Full post data from upload response
     */
    prependPost(postData) {
        if (!postData || !postData.id) {
            console.error('FeedManager: Invalid post data for prepend', postData);
            return;
        }

        // Add to posts array (beginning)
        this.posts.unshift(postData);

        // Create DOM element
        const postElement = this.createPostElement(postData);

        // Prepend to container (top of feed)
        this.container.insertBefore(postElement, this.container.firstChild);

        // Animate entrance
        requestAnimationFrame(() => {
            postElement.classList.add('animate-slide-down');
        });
    }

    /**
     * Load more posts (pagination)
     * @public
     */
    loadMore() {
        this.currentPage++;
        this.loadFeed(false);
    }

    /**
     * Load feed from API
     * @private
     * @param {boolean} reset - Reset feed (clear existing posts)
     */
    async loadFeed(reset = false) {
        if (this.isLoading) return;
        if (!this.hasMore && !reset) return;

        this.isLoading = true;
        this.showLoading();

        try {
            const sortBy = this.sortSelect?.value || 'recent';
            const endpoint = `${this.config.apiEndpoint}?page=${this.currentPage}&per_page=${this.config.perPage}&sort=${sortBy}`;

            const response = await api.get(endpoint);

            if (!response.success) {
                throw new Error(response.message || 'Failed to load feed');
            }

            // Update state
            this.hasMore = response.pagination?.has_more || false;

            // Render posts
            if (reset) {
                this.posts = response.posts;
                this.container.innerHTML = '';
            } else {
                this.posts = [...this.posts, ...response.posts];
            }

            this.renderPosts(response.posts);

            // Hide skeleton on first load
            this.hideSkeleton();

            // Update load more button visibility
            this.updateLoadMoreButton();

        } catch (error) {
            console.error('FeedManager: Load failed', error);

            // ENTERPRISE: Initialize posts array if undefined (prevent crashes)
            if (!this.posts) {
                this.posts = [];
            }

            this.showError(error.message || 'Errore nel caricamento del feed');
        } finally {
            this.isLoading = false;
            this.hideLoading();
        }
    }

    /**
     * Render posts to DOM
     * @private
     * @param {Array} posts - Posts to render
     */
    renderPosts(posts) {
        if (!posts || posts.length === 0) {
            if (!this.posts || this.posts.length === 0) {
                this.showEmptyState();
            }
            return;
        }

        const fragment = document.createDocumentFragment();

        posts.forEach(post => {
            const postElement = this.createPostElement(post);
            fragment.appendChild(postElement);
        });

        this.container.appendChild(fragment);

        // ENTERPRISE V10.9: Observe new posts for viewport tracking
        this.observeAllPosts();
    }

    /**
     * ENTERPRISE: Prepend new post to feed (instant UI update like Twitter/Instagram)
     * @public
     * @param {Object} postData - Post data from upload response
     */
    prependPost(postData) {
        // Format post data to match feed structure
        // ENTERPRISE SECURITY: Use only UUID for user identification (NEVER numeric ID)
        const formattedPost = {
            id: postData.id,
            uuid: postData.uuid,
            author: {
                uuid: window.need2talk?.user?.uuid || '', // ENTERPRISE: UUID-only for ownership check
                nickname: postData.nickname || window.need2talk?.user?.nickname || 'Utente',
                avatar_url: postData.avatar_url || window.need2talk?.user?.avatar || '/assets/img/default-avatar.png'
            },
            post_type: postData.post_type || 'audio',
            content: postData.content || '',
            audio_file_id: postData.audio_file_id || null,
            audio_title: postData.audio_title || 'Senza titolo',
            audio_duration: postData.audio_duration || 0,
            audio_photo_url: postData.audio_photo_url || null,
            audio_photo_thumbnail: postData.audio_photo_thumbnail || null,
            audio_cdn_url: postData.audio_cdn_url || null,
            emotion: postData.emotion_id ? {
                id: postData.emotion_id,
                name_it: postData.emotion_name_it || '',
                icon_emoji: postData.emotion_icon_emoji || '',
                color_hex: postData.emotion_color_hex || '#8B5CF6'
            } : null,
            total_reactions: 0,
            user_reaction: null,
            reactions: {},
            comment_count: 0,  // ENTERPRISE: Field name matches API response
            listen_count: 0,   // ENTERPRISE GALAXY: Audio plays (80% threshold)
            created_at: new Date().toISOString(),
            user_liked: 0
        };

        // Hide empty state if visible
        const emptyState = this.container.querySelector('.empty-state');
        if (emptyState) {
            emptyState.remove();
        }

        // Create post element
        const postElement = this.createPostElement(formattedPost);

        // Prepend to container (insert at beginning)
        this.container.insertBefore(postElement, this.container.firstChild);

        // Add to posts array
        this.posts.unshift(formattedPost);
    }

    /**
     * Create post DOM element
     * @private
     * @param {Object} post - Post data
     * @returns {HTMLElement} Post element
     */
    createPostElement(post) {
        const div = document.createElement('div');
        div.className = 'bg-gray-800/50 backdrop-blur-sm rounded-xl p-6 border border-gray-700/50 fade-in';
        div.dataset.postId = post.id;

        const timeAgo = formatTimeAgo(post.created_at);
        const isLiked = post.user_liked > 0;
        const isOwner = post.author?.uuid === (window.need2talk?.user?.uuid || '');

        div.innerHTML = `
            <!-- Post Header -->
            <div class="flex items-start justify-between mb-4">
                <div class="flex items-center space-x-3">
                    <a href="/u/${escapeHtml(post.author.uuid || '')}" class="flex-shrink-0">
                        <img src="${escapeHtml(post.author.avatar_url || '/assets/img/default-avatar.png')}"
                             alt="${escapeHtml(post.author.nickname)}"
                             class="w-12 h-12 rounded-full border-2 border-purple-500 hover:border-pink-500 transition-colors"
                             loading="lazy">
                    </a>
                    <div>
                        <a href="/u/${escapeHtml(post.author.uuid || '')}" class="block group">
                            <h3 class="font-semibold text-white group-hover:text-purple-400 transition-colors">${escapeHtml(post.author.nickname)}</h3>
                        </a>
                        <div class="flex items-center space-x-2 text-sm text-gray-400">
                            <span>${timeAgo}</span>
                            ${post.emotion ? `
                                <span>•</span>
                                <span class="flex items-center">
                                    <span class="text-xl mr-1">${post.emotion.icon_emoji}</span>
                                    ${escapeHtml(post.emotion.name_it)}
                                </span>
                            ` : ''}
                        </div>
                    </div>
                </div>

                <!-- ENTERPRISE: Privacy Badge + Menu for own posts -->
                <div class="flex items-center gap-2">
                    ${isOwner ? `
                    <!-- Privacy Badge CLICKABLE (only for post owner) -->
                    <button type="button"
                            onclick="window.feedManager.openPrivacyModal(${post.id}, '${post.visibility || 'private'}', '${escapeHtml(post.audio_title || post.title || 'Senza titolo')}')"
                            class="px-3 py-1 rounded-full text-xs font-medium cursor-pointer hover:opacity-80 transition-opacity ${
                        (post.visibility || 'private') === 'public' ? 'bg-green-500/20 text-green-400' :
                        (post.visibility || 'private') === 'friends' ? 'bg-blue-500/20 text-blue-400' :
                        (post.visibility || 'private') === 'friends_of_friends' ? 'bg-indigo-500/20 text-indigo-400' :
                        'bg-purple-500/20 text-purple-400'
                    }">
                        ${(post.visibility || 'private') === 'public' ? '🌍 Pubblico' :
                          (post.visibility || 'private') === 'friends' ? '👥 Amici' :
                          (post.visibility || 'private') === 'friends_of_friends' ? '👥 Amici di amici' :
                          '🔒 Privato'}
                    </button>
                    ` : ''}

                    <!-- Post Menu (ENTERPRISE: wrapper div for dropdown positioning) -->
                    <div class="relative">
                        <button class="post-menu-btn p-2 hover:bg-gray-700 rounded-lg transition-colors" data-post-id="${post.id}">
                            <svg class="w-5 h-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Post Title (if exists) - ENTERPRISE V7.0: Clickable @mentions -->
            ${post.audio_title || post.title ? `
                <h4 class="text-white font-semibold text-lg mb-2">
                    ${linkifyMentions(post.audio_title || post.title, post.tagged_users || [])}
                </h4>
            ` : ''}

            <!-- Post Content (user text/emoji - editable via modal) -->
            ${post.content ? `
                <p class="text-gray-300 mb-4 text-sm">
                    ${escapeHtml(post.content)}
                </p>
            ` : ''}

            <!-- Cover Photo (ENTERPRISE GALAXY - HD Quality + Lightbox) -->
            ${post.audio_photo_url || post.audio_photo_thumbnail ? `
                <div class="mb-4 rounded-lg overflow-hidden border border-gray-700 cursor-pointer hover:border-purple-500/50 transition-colors group relative photo-lightbox-trigger"
                     data-photo-url="/storage/uploads/${escapeHtml(post.audio_photo_url)}"
                     data-post-id="${post.id}"
                     data-post-title="${escapeHtml(post.audio_title || post.title || 'Senza titolo')}"
                     data-post-date="${post.created_at || ''}"
                     data-post-emotion="${post.emotion ? escapeHtml(post.emotion.icon_emoji + ' ' + post.emotion.name_it) : ''}"
                     data-reactions-count="${post.total_reactions || 0}"
                     data-comments-count="${post.comment_count || 0}"
                     data-listens-count="${post.listen_count || 0}">
                    <img
                        src="/storage/uploads/${escapeHtml(post.audio_photo_url)}"
                        srcset="/storage/uploads/${escapeHtml(post.audio_photo_thumbnail)} 300w,
                                /storage/uploads/${escapeHtml(post.audio_photo_url)} 1920w"
                        sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 600px"
                        alt="${escapeHtml(post.audio_title || 'Cover photo')}"
                        class="w-full h-auto object-cover group-hover:scale-105 transition-transform duration-300"
                        loading="lazy"
                        onerror="this.parentElement.style.display='none'"
                    >
                    <div class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors flex items-center justify-center opacity-0 group-hover:opacity-100">
                        <span class="text-white text-4xl">🔍</span>
                    </div>
                </div>
            ` : ''}

            <!-- ENTERPRISE: Audio Player (custom UI - EnterpriseAudioPlayer.js) -->
            <!-- ENTERPRISE V11.5: Cache-buster using post creation timestamp to prevent stale audio -->
            <!-- ENTERPRISE V12.1: Removed hardcoded type="audio/webm" - server sends correct Content-Type (audio/mpeg for MP3) -->
            <div class="mb-4" id="audio-container-${post.id}">
                <audio preload="metadata" data-post-id="${post.id}" data-duration="${post.audio_duration || 0}">
                    <source src="/api/audio/${post.id}/stream?v=${new Date(post.created_at).getTime() || Date.now()}">
                    Il tuo browser non supporta la riproduzione audio.
                </audio>
            </div>

            <!-- Emotional Reactions Picker -->
            <div id="reactions-${post.id}" class="mb-4">
                <!-- ReactionPicker.js renders here -->
            </div>

            <!-- Post Actions -->
            <div class="flex items-center justify-between pt-4 border-t border-gray-700">
                <div class="flex items-center space-x-4">

                    <!-- Listen Count (ENTERPRISE GALAXY - 80% threshold tracking) -->
                    <div class="flex items-center space-x-2 px-4 py-2 text-gray-400" title="Ascolti completati (80%+)">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"></path>
                        </svg>
                        <span id="listenCount-${post.id}">${formatNumber(post.listen_count || 0)}</span>
                    </div>

                    <!-- Comment Button -->
                    <button onclick="window.feedManager.toggleComments(${post.id})"
                            class="flex items-center space-x-2 px-4 py-2 rounded-lg hover:bg-gray-700 transition-colors text-gray-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                        </svg>
                        <span id="commentCount-${post.id}">${formatNumber(post.comment_count || 0)}</span>
                    </button>

                    <!-- Share button: TODO Phase 2 -->

                </div>
            </div>

            <!-- Comments Section (Collapsed by default) -->
            <div id="comments-${post.id}" class="hidden mt-4 pt-4 border-t border-gray-700">
                <div class="text-center text-gray-400 py-4">Caricamento commenti...</div>
            </div>
        `;

        // ENTERPRISE: Initialize EnterpriseAudioPlayer (custom UI)
        const audioElement = div.querySelector('audio');
        if (audioElement && typeof EnterpriseAudioPlayer !== 'undefined') {
            // Defer initialization to next tick (DOM must be inserted first)
            setTimeout(() => {
                try {
                    const player = new EnterpriseAudioPlayer(audioElement, {
                        postId: post.id, // CRITICAL: Pass post ID for 80% tracking
                        isOwner: isOwner, // ENTERPRISE V10.2: Skip optimistic update for own posts
                        showVolume: true,
                        showDownload: false,
                        accentColor: '#8B5CF6',
                        enableTracking: true // Enable enterprise listen tracking
                    });

                    // Store player reference for later access
                    if (!this.audioPlayers) {
                        this.audioPlayers = {};
                    }
                    this.audioPlayers[post.id] = player;
                } catch (error) {
                    console.error(`[FeedManager] Failed to initialize EnterpriseAudioPlayer for post ${post.id}:`, error);
                }
            }, 0);

            // NOTE: Tracking now handled by EnterpriseAudioPlayer (80% threshold)
            // Old trackAudioPlay() removed - EnterpriseAudioPlayer calls /api/audio/{id}/track-listen
        } else if (!audioElement) {
            console.warn(`[FeedManager] Audio element not found for post ${post.id}`);
        } else {
            console.warn('[FeedManager] EnterpriseAudioPlayer class not loaded - falling back to native controls');
            if (audioElement) {
                audioElement.controls = true; // Fallback to native controls
            }
        }

        // Initialize ReactionPicker (defer to next tick for DOM insertion)
        setTimeout(() => {
            this.initReactionPicker(post);
        }, 0);

        return div;
    }

    /**
     * Initialize ReactionPicker for a post
     * @private
     * @param {Object} post - Post data
     */
    initReactionPicker(post) {
        const containerId = `reactions-${post.id}`;
        const container = document.getElementById(containerId);

        if (!container) {
            console.warn(`FeedManager: Reaction container #${containerId} not found`);
            return;
        }

        // Check if ReactionPicker class exists
        if (typeof ReactionPicker === 'undefined') {
            console.error('FeedManager: ReactionPicker class not loaded');
            return;
        }

        // Extract reaction stats and user reaction from post data
        const reactionStats = post.reaction_stats || {};
        const userReaction = post.user_reaction || null;

        // Create ReactionPicker instance
        try {
            const picker = new ReactionPicker({
                audioId: post.id,
                containerId: containerId,
                initialStats: reactionStats,
                userReaction: userReaction,

                // Callback when reaction changes
                onReactionChange: (emotionId, newStats) => {
                    // Update post data in memory
                    const postIndex = this.posts.findIndex(p => p.id === post.id);
                    if (postIndex !== -1) {
                        this.posts[postIndex].reaction_stats = newStats;
                        this.posts[postIndex].user_reaction = emotionId;
                    }
                },
            });

            // Store reference for later access (optional)
            if (!this.reactionPickers) {
                this.reactionPickers = {};
            }
            this.reactionPickers[post.id] = picker;

        } catch (error) {
            console.error('FeedManager: Failed to init ReactionPicker', error);
        }
    }

    /**
     * NOTE: toggleLike() removed - replaced with Emotional Reactions System
     * See: ReactionPicker component (implemented)
     * New endpoints:
     * - POST   /api/audio/{id}/react (add/update reaction)
     * - POST   /api/audio/{id}/unreact (remove reaction)
     * - GET    /api/audio/{id}/reactions (get reaction stats)
     */

    /**
     * Toggle comments section
     * ENTERPRISE GALAXY V4: Delegates to CommentManager for full functionality
     * @public
     * @param {number} postId - Post ID
     */
    async toggleComments(postId) {
        // Delegate to CommentManager (lazy loading, replies, likes, etc.)
        if (window.commentManager) {
            window.commentManager.toggle(postId);
        } else {
            console.error('FeedManager: CommentManager not loaded');
        }
    }

    // NOTE: trackAudioPlay() method REMOVED
    // Tracking now handled by EnterpriseAudioPlayer with 80% threshold
    // See: EnterpriseAudioPlayer.trackListenProgress() → POST /api/audio/{id}/track-listen

    /**
     * Show post menu dropdown (ENTERPRISE GALAXY)
     *
     * Displays contextual menu based on post ownership:
     * - Author: Edit, Delete
     * - Non-author: Hide, Report
     *
     * @public
     * @param {number} postId - Post ID
     */
    showPostMenu(postId) {
        const post = this.posts.find(p => p.id === postId);
        if (!post) return;

        const currentUserUuid = window.need2talk?.user?.uuid || '';
        const isAuthor = post.author?.uuid === currentUserUuid;

        this.closePostMenu();

        const postElement = document.querySelector(`#feedContainer [data-post-id="${postId}"]`);
        if (!postElement) return;

        const menuButton = postElement.querySelector('.post-menu-btn');
        if (!menuButton) return;

        // Create dropdown
        const dropdown = document.createElement('div');
        dropdown.id = 'postMenuDropdown';
        dropdown.className = 'absolute right-0 top-full mt-1 w-48 bg-gray-800 border border-gray-700 rounded-lg shadow-xl z-50 py-1 animate-fade-in';
        dropdown.dataset.postId = postId;

        if (isAuthor) {
            // Author menu: Edit, Delete
            dropdown.innerHTML = `
                <button onclick="window.feedManager.editPost(${postId})" class="w-full px-4 py-2 text-left text-gray-300 hover:bg-gray-700 flex items-center space-x-2 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    <span>Modifica</span>
                </button>
                <button onclick="window.feedManager.confirmDeletePost(${postId})" class="w-full px-4 py-2 text-left text-red-400 hover:bg-gray-700 flex items-center space-x-2 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    <span>Elimina</span>
                </button>
            `;
        } else {
            // Non-author menu: Hide, Report
            dropdown.innerHTML = `
                <button onclick="window.feedManager.hidePost(${postId})" class="w-full px-4 py-2 text-left text-gray-300 hover:bg-gray-700 flex items-center space-x-2 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                    </svg>
                    <span>Nascondi</span>
                </button>
                <button onclick="window.feedManager.showReportModal(${postId})" class="w-full px-4 py-2 text-left text-yellow-400 hover:bg-gray-700 flex items-center space-x-2 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <span>Segnala</span>
                </button>
            `;
        }

        menuButton.parentElement.appendChild(dropdown);

        setTimeout(() => {
            document.addEventListener('click', this.handleOutsideClick);
        }, 50);
    }

    /**
     * Close post menu dropdown
     * @public
     */
    closePostMenu() {
        const existing = document.getElementById('postMenuDropdown');
        if (existing) {
            existing.remove();
        }
        document.removeEventListener('click', this.handleOutsideClick);
    }

    /**
     * Handle outside click for dropdown
     * @private
     */
    handleOutsideClick = (e) => {
        const dropdown = document.getElementById('postMenuDropdown');
        if (dropdown && !dropdown.contains(e.target) && !e.target.closest('.post-menu-btn')) {
            this.closePostMenu();
        }
    }

    /**
     * Hide post from feed (non-author action)
     * @public
     * @param {number} postId - Post ID
     */
    async hidePost(postId) {
        this.closePostMenu();

        try {
            const response = await api.post(`/api/audio/${postId}/hide`);

            if (response.success) {
                // Remove post from DOM with animation
                const postElement = document.querySelector(`[data-post-id="${postId}"]`);
                if (postElement) {
                    postElement.classList.add('animate-fade-out');
                    setTimeout(() => {
                        postElement.remove();
                        // Remove from posts array
                        this.posts = this.posts.filter(p => p.id !== postId);

                        // Show empty state if no posts left
                        if (this.posts.length === 0) {
                            this.showEmptyState();
                        }
                    }, 300);
                }

                // Show toast notification
                if (window.showToast) {
                    window.showToast('Post nascosto dal tuo feed', 'success');
                }
            } else {
                throw new Error(response.message || 'Errore durante l\'operazione');
            }
        } catch (error) {
            console.error('FeedManager: Hide post failed', error);
            if (window.showToast) {
                window.showToast('Errore nel nascondere il post', 'error');
            }
        }
    }

    /**
     * Show report modal
     * @public
     * @param {number} postId - Post ID
     */
    showReportModal(postId) {
        this.closePostMenu();

        // Create modal
        const modal = document.createElement('div');
        modal.id = 'reportModal';
        modal.className = 'fixed inset-0 bg-black/70 flex items-center justify-center z-50 animate-fade-in';
        modal.innerHTML = `
            <div class="bg-gray-800 rounded-xl p-6 w-full max-w-md mx-4 border border-gray-700">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-bold text-white">Segnala post</h3>
                    <button onclick="window.feedManager.closeReportModal()" class="p-1 hover:bg-gray-700 rounded-lg transition-colors">
                        <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <p class="text-gray-400 text-sm mb-4">Seleziona il motivo della segnalazione:</p>

                <form id="reportForm" class="space-y-3">
                    <input type="hidden" name="postId" value="${postId}">

                    <label class="flex items-center space-x-3 p-3 bg-gray-700/50 rounded-lg cursor-pointer hover:bg-gray-700 transition-colors">
                        <input type="radio" name="reason" value="spam" class="w-4 h-4 text-purple-500 focus:ring-purple-500">
                        <span class="text-gray-300">Spam</span>
                    </label>

                    <label class="flex items-center space-x-3 p-3 bg-gray-700/50 rounded-lg cursor-pointer hover:bg-gray-700 transition-colors">
                        <input type="radio" name="reason" value="harassment" class="w-4 h-4 text-purple-500 focus:ring-purple-500">
                        <span class="text-gray-300">Molestie o bullismo</span>
                    </label>

                    <label class="flex items-center space-x-3 p-3 bg-gray-700/50 rounded-lg cursor-pointer hover:bg-gray-700 transition-colors">
                        <input type="radio" name="reason" value="hate_speech" class="w-4 h-4 text-purple-500 focus:ring-purple-500">
                        <span class="text-gray-300">Incitamento all'odio</span>
                    </label>

                    <label class="flex items-center space-x-3 p-3 bg-gray-700/50 rounded-lg cursor-pointer hover:bg-gray-700 transition-colors">
                        <input type="radio" name="reason" value="violence" class="w-4 h-4 text-purple-500 focus:ring-purple-500">
                        <span class="text-gray-300">Contenuti violenti</span>
                    </label>

                    <label class="flex items-center space-x-3 p-3 bg-gray-700/50 rounded-lg cursor-pointer hover:bg-gray-700 transition-colors">
                        <input type="radio" name="reason" value="sexual_content" class="w-4 h-4 text-purple-500 focus:ring-purple-500">
                        <span class="text-gray-300">Contenuti sessuali</span>
                    </label>

                    <label class="flex items-center space-x-3 p-3 bg-gray-700/50 rounded-lg cursor-pointer hover:bg-gray-700 transition-colors">
                        <input type="radio" name="reason" value="other" class="w-4 h-4 text-purple-500 focus:ring-purple-500">
                        <span class="text-gray-300">Altro</span>
                    </label>

                    <textarea name="description" placeholder="Descrizione aggiuntiva (opzionale)"
                              class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-lg text-white placeholder-gray-400 focus:ring-2 focus:ring-purple-500 focus:border-transparent resize-none"
                              rows="3"></textarea>

                    <div class="flex space-x-3 pt-2">
                        <button type="button" onclick="window.feedManager.closeReportModal()"
                                class="flex-1 px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg text-white font-medium transition-colors">
                            Annulla
                        </button>
                        <button type="submit"
                                class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg text-white font-medium transition-colors">
                            Invia segnalazione
                        </button>
                    </div>
                </form>
            </div>
        `;

        document.body.appendChild(modal);
        document.body.style.overflow = 'hidden';

        // Handle form submission
        const form = modal.querySelector('#reportForm');
        form.addEventListener('submit', (e) => this.submitReport(e));

        // Close on backdrop click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                this.closeReportModal();
            }
        });
    }

    /**
     * Close report modal
     * @public
     */
    closeReportModal() {
        const modal = document.getElementById('reportModal');
        if (modal) {
            modal.remove();
            document.body.style.overflow = '';
        }
    }

    /**
     * Submit report form
     * @private
     * @param {Event} e - Form submit event
     */
    async submitReport(e) {
        e.preventDefault();

        const form = e.target;
        const postId = form.querySelector('input[name="postId"]').value;
        const reason = form.querySelector('input[name="reason"]:checked')?.value;
        const description = form.querySelector('textarea[name="description"]').value;

        if (!reason) {
            if (window.showToast) {
                window.showToast('Seleziona un motivo', 'warning');
            }
            return;
        }

        try {
            const response = await api.post(`/api/audio/${postId}/report`, {
                reason: reason,
                description: description
            });

            if (response.success) {
                this.closeReportModal();
                if (window.showToast) {
                    window.showToast('Segnalazione inviata. Grazie!', 'success');
                }
            } else {
                throw new Error(response.message || 'Errore durante l\'invio');
            }
        } catch (error) {
            // ENTERPRISE GALAXY: Semantic error handling with appropriate UX
            // ApiClient throws ApiError with: code (from data.error), message, status, data
            // Extract the semantic error code from the response
            const errorCode = error.data?.error || error.code || 'unknown';
            const errorMessage = error.data?.message || error.message || 'Errore nell\'invio della segnalazione';

            // Map error codes to appropriate toast types and actions
            // These are EXPECTED business logic responses, NOT errors
            const expectedResponses = {
                'already_reported': {
                    type: 'info',
                    message: 'Hai già segnalato questo contenuto',
                    closeModal: true,
                    isError: false  // NOT an error, just info
                },
                'own_content': {
                    type: 'warning',
                    message: 'Non puoi segnalare i tuoi contenuti',
                    closeModal: true,
                    isError: false
                },
                'invalid_reason': {
                    type: 'warning',
                    message: 'Seleziona un motivo valido',
                    closeModal: false,
                    isError: false
                },
                'not_found': {
                    type: 'error',
                    message: 'Contenuto non trovato',
                    closeModal: true,
                    isError: true
                }
            };

            const handler = expectedResponses[errorCode] || {
                type: 'error',
                message: errorMessage,
                closeModal: false,
                isError: true
            };

            if (handler.closeModal) {
                this.closeReportModal();
            }

            if (window.showToast) {
                window.showToast(handler.message, handler.type);
            }

            // Only log ACTUAL errors, not expected business logic responses
            if (handler.isError) {
                console.error('FeedManager: Report failed', error);
            }
            // Expected responses like 'already_reported' are silently handled - no console spam
        }
    }

    /**
     * Edit post (author action)
     * ENTERPRISE GALAXY: Title + Content editing with Emoji Picker
     * @public
     * @param {number} postId - Post ID
     */
    editPost(postId) {
        this.closePostMenu();

        const post = this.posts.find(p => p.id === postId);
        if (!post) return;

        // Get current title (audio_title or title)
        const currentTitle = post.audio_title || post.title || '';
        const currentContent = post.content || '';

        // Create edit modal
        const modal = document.createElement('div');
        modal.id = 'editPostModal';
        modal.className = 'fixed inset-0 bg-black/70 flex items-center justify-center z-50 animate-fade-in';
        modal.innerHTML = `
            <div class="bg-gray-800 rounded-xl p-6 w-full max-w-md mx-4 border border-gray-700 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-bold text-white">Modifica post</h3>
                    <button type="button" class="edit-modal-close p-1 hover:bg-gray-700 rounded-lg transition-colors">
                        <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <p class="text-gray-400 text-sm mb-4">Modifica titolo e descrizione del tuo post. L'audio e la foto non possono essere cambiati.</p>

                <form id="editPostForm" class="space-y-4">
                    <input type="hidden" name="postId" value="${postId}">

                    <!-- Title Field with Emoji -->
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Titolo</label>
                        <div class="relative">
                            <input type="text" name="title"
                                   placeholder="Dai un titolo al tuo audio..."
                                   maxlength="100"
                                   class="w-full px-4 py-3 pr-12 bg-gray-700 border border-gray-600 rounded-lg text-white placeholder-gray-400 focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                   value="${escapeHtml(currentTitle)}">
                            <button type="button" class="emoji-trigger-title absolute right-3 top-1/2 -translate-y-1/2 text-2xl hover:scale-110 transition-transform" title="Aggiungi emoji">
                                😊
                            </button>
                        </div>
                        <div class="text-xs text-gray-500 mt-1 text-right">
                            <span class="title-char-count">${currentTitle.length}</span>/100
                        </div>
                    </div>

                    <!-- Content Field with Emoji -->
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Descrizione</label>
                        <div class="relative">
                            <textarea name="content" placeholder="Aggiungi una descrizione..."
                                      maxlength="500"
                                      class="w-full px-4 py-3 pr-12 bg-gray-700 border border-gray-600 rounded-lg text-white placeholder-gray-400 focus:ring-2 focus:ring-purple-500 focus:border-transparent resize-none"
                                      rows="4">${escapeHtml(currentContent)}</textarea>
                            <button type="button" class="emoji-trigger-content absolute right-3 top-3 text-2xl hover:scale-110 transition-transform" title="Aggiungi emoji">
                                😊
                            </button>
                        </div>
                        <div class="text-xs text-gray-500 mt-1 text-right">
                            <span class="content-char-count">${currentContent.length}</span>/500
                        </div>
                    </div>

                    <div class="flex space-x-3 pt-2">
                        <button type="button" class="edit-modal-cancel flex-1 px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg text-white font-medium transition-colors">
                            Annulla
                        </button>
                        <button type="submit"
                                class="flex-1 px-4 py-2 bg-purple-600 hover:bg-purple-700 rounded-lg text-white font-medium transition-colors">
                            Salva modifiche
                        </button>
                    </div>
                </form>
            </div>

            <!-- Emoji Picker Container (hidden by default) -->
            <div id="editEmojiPickerContainer" class="hidden"></div>
        `;

        document.body.appendChild(modal);
        document.body.style.overflow = 'hidden';

        // Get form elements
        const form = modal.querySelector('#editPostForm');
        const titleInput = form.querySelector('input[name="title"]');
        const contentTextarea = form.querySelector('textarea[name="content"]');
        const titleCharCount = form.querySelector('.title-char-count');
        const contentCharCount = form.querySelector('.content-char-count');

        // Character count updates
        titleInput.addEventListener('input', () => {
            titleCharCount.textContent = titleInput.value.length;
        });
        contentTextarea.addEventListener('input', () => {
            contentCharCount.textContent = contentTextarea.value.length;
        });

        // Handle form submission
        form.addEventListener('submit', (e) => this.submitEdit(e));

        // Close handlers
        modal.querySelector('.edit-modal-close').addEventListener('click', () => this.closeEditModal());
        modal.querySelector('.edit-modal-cancel').addEventListener('click', () => this.closeEditModal());

        // Close on backdrop click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                this.closeEditModal();
            }
        });

        // Emoji picker triggers
        const emojiTriggerTitle = modal.querySelector('.emoji-trigger-title');
        const emojiTriggerContent = modal.querySelector('.emoji-trigger-content');

        emojiTriggerTitle.addEventListener('click', () => {
            this.openEditEmojiPicker(titleInput);
        });

        emojiTriggerContent.addEventListener('click', () => {
            this.openEditEmojiPicker(contentTextarea);
        });

        // Focus title input
        setTimeout(() => {
            titleInput.focus();
            titleInput.setSelectionRange(titleInput.value.length, titleInput.value.length);
        }, 100);
    }

    /**
     * Open emoji picker for edit modal
     * @private
     * @param {HTMLElement} targetInput - Input/Textarea to insert emoji into
     */
    openEditEmojiPicker(targetInput) {
        // Store reference to target input
        this._editEmojiTarget = targetInput;

        // Check if EmojiData is available (must use window.EmojiData for cross-script access)
        if (typeof window.EmojiData === 'undefined') {
            console.error('FeedManager: EmojiData not loaded');
            return;
        }

        const container = document.getElementById('editEmojiPickerContainer');
        if (!container) return;

        // Generate and insert picker HTML
        container.innerHTML = window.EmojiData.generatePickerHTML();
        container.classList.remove('hidden');

        // Initialize picker events
        const pickerElement = container.firstElementChild;
        window.EmojiData.initPickerEvents(
            pickerElement,
            // On emoji select
            (emoji) => {
                if (this._editEmojiTarget) {
                    // Insert emoji at cursor position
                    const start = this._editEmojiTarget.selectionStart;
                    const end = this._editEmojiTarget.selectionEnd;
                    const value = this._editEmojiTarget.value;
                    this._editEmojiTarget.value = value.substring(0, start) + emoji + value.substring(end);

                    // Update cursor position
                    const newPos = start + emoji.length;
                    this._editEmojiTarget.setSelectionRange(newPos, newPos);
                    this._editEmojiTarget.focus();

                    // Trigger input event for char count update
                    this._editEmojiTarget.dispatchEvent(new Event('input'));
                }
                // Close picker after selection
                this.closeEditEmojiPicker();
            },
            // On close
            () => {
                this.closeEditEmojiPicker();
            }
        );
    }

    /**
     * Close emoji picker for edit modal
     * @private
     */
    closeEditEmojiPicker() {
        const container = document.getElementById('editEmojiPickerContainer');
        if (container) {
            container.innerHTML = '';
            container.classList.add('hidden');
        }
        this._editEmojiTarget = null;
    }

    /**
     * Close edit modal
     * @public
     */
    closeEditModal() {
        const modal = document.getElementById('editPostModal');
        if (modal) {
            modal.remove();
            document.body.style.overflow = '';
        }
    }

    /**
     * Open privacy change modal (ENTERPRISE GALAXY 2025-11-29)
     * Same modal as AudioDayModal for consistent UX
     * @public
     * @param {number} postId - Post ID
     * @param {string} currentPrivacy - Current privacy level
     * @param {string} postTitle - Post title for display
     */
    openPrivacyModal(postId, currentPrivacy, postTitle = 'Senza titolo') {
        // Remove existing modal if present
        const existingModal = document.getElementById('feedPrivacyModal');
        if (existingModal) existingModal.remove();

        // Privacy options (same as AudioDayModal)
        const privacyOptions = [
            { value: 'public', label: '🌍 Pubblico', desc: 'Tutti possono vedere' },
            { value: 'friends', label: '👥 Amici', desc: 'Solo i tuoi amici' },
            { value: 'friends_of_friends', label: '👥 Amici di amici', desc: 'Amici e amici dei tuoi amici' },
            { value: 'private', label: '🔒 Privato', desc: 'Solo tu' }
        ];

        // Create modal
        const modal = document.createElement('div');
        modal.id = 'feedPrivacyModal';
        modal.className = 'fixed inset-0 bg-black/70 flex items-center justify-center z-50 animate-fade-in';
        modal.style.backdropFilter = 'blur(4px)';

        modal.innerHTML = `
            <div class="bg-gray-800 rounded-xl p-6 w-full max-w-md mx-4 border border-gray-700">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-xl font-bold text-white">Modifica Privacy</h3>
                    <button type="button" class="privacy-modal-close text-gray-400 hover:text-white transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <p class="text-gray-400 text-sm mb-4">Chi può vedere "<span class="text-white font-medium">${this.escapeHtml(postTitle)}</span>"?</p>

                <form id="feedPrivacyForm" class="space-y-3">
                    <input type="hidden" name="postId" value="${postId}">

                    ${privacyOptions.map(opt => `
                        <label class="flex items-center p-3 rounded-lg cursor-pointer transition-all border ${
                            currentPrivacy === opt.value
                                ? 'bg-purple-500/20 border-purple-500'
                                : 'bg-gray-700/50 border-transparent hover:bg-gray-700'
                        }">
                            <input type="radio" name="privacy" value="${opt.value}" ${currentPrivacy === opt.value ? 'checked' : ''}
                                   class="w-4 h-4 text-purple-500 focus:ring-purple-500 mr-3">
                            <div>
                                <span class="text-white font-medium">${opt.label}</span>
                                <p class="text-gray-400 text-xs">${opt.desc}</p>
                            </div>
                        </label>
                    `).join('')}

                    <button type="submit"
                            class="w-full mt-4 px-4 py-3 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition-colors">
                        Salva
                    </button>
                </form>
            </div>
        `;

        document.body.appendChild(modal);
        document.body.style.overflow = 'hidden';

        // Event handlers
        modal.querySelector('.privacy-modal-close').addEventListener('click', () => this.closePrivacyModal());
        modal.addEventListener('click', (e) => {
            if (e.target === modal) this.closePrivacyModal();
        });

        // Form submit
        modal.querySelector('#feedPrivacyForm').addEventListener('submit', (e) => this.submitPrivacyChange(e));

        // Highlight selected option on change
        modal.querySelectorAll('input[name="privacy"]').forEach(radio => {
            radio.addEventListener('change', () => {
                modal.querySelectorAll('label').forEach(label => {
                    const input = label.querySelector('input');
                    if (input.checked) {
                        label.classList.add('bg-purple-500/20', 'border-purple-500');
                        label.classList.remove('border-transparent');
                    } else {
                        label.classList.remove('bg-purple-500/20', 'border-purple-500');
                        label.classList.add('border-transparent');
                    }
                });
            });
        });
    }

    /**
     * Close privacy modal
     * @public
     */
    closePrivacyModal() {
        const modal = document.getElementById('feedPrivacyModal');
        if (modal) {
            modal.remove();
            document.body.style.overflow = '';
        }
    }

    /**
     * Submit privacy change
     * @private
     * @param {Event} e - Form submit event
     */
    async submitPrivacyChange(e) {
        e.preventDefault();

        const form = e.target;
        const postId = form.querySelector('input[name="postId"]').value;
        const privacy = form.querySelector('input[name="privacy"]:checked')?.value;

        if (!privacy) return;

        try {
            const response = await api.patch(`/api/audio/${postId}/privacy`, {
                visibility: privacy
            });

            if (response.success) {
                // Update post in memory
                const postIndex = this.posts.findIndex(p => p.id == postId);
                if (postIndex !== -1) {
                    this.posts[postIndex].visibility = privacy;
                }

                // Update privacy badge in DOM
                const postElement = document.querySelector(`#feedContainer [data-post-id="${postId}"]`);
                if (postElement) {
                    const badge = postElement.querySelector('button[onclick*="openPrivacyModal"]');
                    if (badge) {
                        // Update badge text and colors
                        const labels = {
                            'public': '🌍 Pubblico',
                            'friends': '👥 Amici',
                            'friends_of_friends': '👥 Amici di amici',
                            'private': '🔒 Privato'
                        };
                        const colors = {
                            'public': 'bg-green-500/20 text-green-400',
                            'friends': 'bg-blue-500/20 text-blue-400',
                            'friends_of_friends': 'bg-indigo-500/20 text-indigo-400',
                            'private': 'bg-purple-500/20 text-purple-400'
                        };

                        badge.textContent = labels[privacy] || labels['private'];
                        badge.className = `px-3 py-1 rounded-full text-xs font-medium cursor-pointer hover:opacity-80 transition-opacity ${colors[privacy] || colors['private']}`;

                        // Update onclick to reflect new privacy
                        const title = this.posts[postIndex]?.audio_title || this.posts[postIndex]?.title || 'Senza titolo';
                        badge.setAttribute('onclick', `window.feedManager.openPrivacyModal(${postId}, '${privacy}', '${this.escapeHtml(title)}')`);
                    }
                }

                this.closePrivacyModal();
                if (window.showToast) {
                    window.showToast('Privacy aggiornata', 'success');
                }
            } else {
                throw new Error(response.message || 'Errore durante il salvataggio');
            }
        } catch (error) {
            console.error('[FeedManager] Privacy update failed:', error);
            if (window.showToast) {
                window.showToast(error.message || 'Errore nel salvataggio', 'error');
            }
        }
    }

    /**
     * Escape HTML to prevent XSS
     * @private
     * @param {string} str - String to escape
     * @returns {string} Escaped string
     */
    escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Submit edit form
     * ENTERPRISE GALAXY: Handles both title and content updates
     * @private
     * @param {Event} e - Form submit event
     */
    async submitEdit(e) {
        e.preventDefault();

        const form = e.target;
        const postId = form.querySelector('input[name="postId"]').value;
        const title = form.querySelector('input[name="title"]').value.trim();
        const content = form.querySelector('textarea[name="content"]').value.trim();

        try {
            const response = await api.patch(`/api/audio/${postId}`, {
                title: title,
                content: content
            });

            if (response.success) {
                // Update post in memory
                const postIndex = this.posts.findIndex(p => p.id == postId);
                if (postIndex !== -1) {
                    this.posts[postIndex].audio_title = title;
                    this.posts[postIndex].title = title;
                    this.posts[postIndex].content = content;
                }

                // Update DOM - use scoped selector to avoid photo gallery collision
                const postElement = document.querySelector(`#feedContainer [data-post-id="${postId}"]`);
                if (postElement) {
                    // Update title (h4 element)
                    const titleEl = postElement.querySelector('h4.text-white');
                    if (titleEl) {
                        titleEl.textContent = title || 'Audio senza titolo';
                    }

                    // Update content (p.text-gray-300 element)
                    const contentEl = postElement.querySelector('p.text-gray-300.mb-4');
                    if (contentEl) {
                        if (content) {
                            contentEl.textContent = content;
                            contentEl.classList.remove('hidden');
                        } else {
                            // Hide if empty
                            contentEl.textContent = '';
                            contentEl.classList.add('hidden');
                        }
                    } else if (content) {
                        // Add content element if it didn't exist
                        const titleElement = postElement.querySelector('h4.text-white');
                        if (titleElement) {
                            const newContentEl = document.createElement('p');
                            newContentEl.className = 'text-gray-300 mb-4 text-sm';
                            newContentEl.textContent = content;
                            titleElement.after(newContentEl);
                        }
                    }
                }

                this.closeEditModal();
                if (window.showToast) {
                    window.showToast('Post modificato con successo', 'success');
                }
            } else {
                throw new Error(response.message || 'Errore durante il salvataggio');
            }
        } catch (error) {
            console.error('FeedManager: Edit failed', error);
            if (window.showToast) {
                window.showToast(error.message || 'Errore nel salvataggio', 'error');
            }
        }
    }

    /**
     * Confirm delete post (author action)
     * @public
     * @param {number} postId - Post ID
     */
    confirmDeletePost(postId) {
        this.closePostMenu();

        // Create confirmation modal
        const modal = document.createElement('div');
        modal.id = 'deleteConfirmModal';
        modal.className = 'fixed inset-0 bg-black/70 flex items-center justify-center z-50 animate-fade-in';
        modal.innerHTML = `
            <div class="bg-gray-800 rounded-xl p-6 w-full max-w-sm mx-4 border border-gray-700">
                <div class="text-center">
                    <div class="w-16 h-16 mx-auto mb-4 bg-red-500/20 rounded-full flex items-center justify-center">
                        <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-white mb-2">Elimina post?</h3>
                    <p class="text-gray-400 text-sm mb-6">Questa azione non può essere annullata. Il post, l'audio e tutte le reazioni verranno eliminati.</p>

                    <div class="flex space-x-3">
                        <button onclick="window.feedManager.closeDeleteModal()"
                                class="flex-1 px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg text-white font-medium transition-colors">
                            Annulla
                        </button>
                        <button onclick="window.feedManager.deletePost(${postId})"
                                class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg text-white font-medium transition-colors">
                            Elimina
                        </button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        document.body.style.overflow = 'hidden';

        // Close on backdrop click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                this.closeDeleteModal();
            }
        });
    }

    /**
     * Close delete confirmation modal
     * @public
     */
    closeDeleteModal() {
        const modal = document.getElementById('deleteConfirmModal');
        if (modal) {
            modal.remove();
            document.body.style.overflow = '';
        }
    }

    /**
     * Delete post
     * @public
     * @param {number} postId - Post ID
     */
    async deletePost(postId) {
        this.closeDeleteModal();

        try {
            const response = await api.delete(`/api/audio/${postId}`);

            if (response.success) {
                // Remove post from DOM with animation
                const postElement = document.querySelector(`[data-post-id="${postId}"]`);
                if (postElement) {
                    postElement.classList.add('animate-fade-out');
                    setTimeout(() => {
                        postElement.remove();
                        // Remove from posts array
                        this.posts = this.posts.filter(p => p.id !== postId);

                        // Show empty state if no posts left
                        if (this.posts.length === 0) {
                            this.showEmptyState();
                        }
                    }, 300);
                }

                if (window.showToast) {
                    window.showToast('Post eliminato', 'success');
                }
            } else {
                throw new Error(response.message || 'Errore durante l\'eliminazione');
            }
        } catch (error) {
            console.error('FeedManager: Delete failed', error);
            if (window.showToast) {
                window.showToast(error.message || 'Errore nell\'eliminazione', 'error');
            }
        }
    }

    /**
     * Show loading indicator
     * @private
     */
    showLoading() {
        const loadingElement = document.getElementById(this.config.loadingId);
        if (loadingElement) {
            loadingElement.classList.remove('hidden');
        }
    }

    /**
     * Hide loading indicator
     * @private
     */
    hideLoading() {
        const loadingElement = document.getElementById(this.config.loadingId);
        if (loadingElement) {
            loadingElement.classList.add('hidden');
        }
    }

    /**
     * Hide skeleton loading
     * @private
     */
    hideSkeleton() {
        const skeleton = document.querySelector('.skeleton-feed');
        if (skeleton) {
            fadeOut(skeleton, 200);
            setTimeout(() => skeleton.remove(), 200);
        }
    }

    /**
     * Update load more button visibility
     * @private
     */
    updateLoadMoreButton() {
        if (this.loadMoreBtn) {
            if (this.hasMore) {
                this.loadMoreBtn.classList.remove('hidden');
            } else {
                this.loadMoreBtn.classList.add('hidden');
            }
        }
    }

    /**
     * Show empty state
     * @private
     */
    showEmptyState() {
        this.container.innerHTML = `
            <div class="bg-gray-800/50 backdrop-blur-sm rounded-xl p-12 border border-gray-700/50 text-center fade-in">
                <svg class="w-16 h-16 mx-auto text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                </svg>
                <h3 class="text-xl font-bold text-gray-400 mb-2">Nessun audio ancora</h3>
                <p class="text-gray-500 mb-6">Sii il primo a condividere la tua voce!</p>
                <button onclick="if(window.floatingRecorder){window.floatingRecorder.openModal()}else{console.error('FloatingRecorder not ready')}" class="px-6 py-3 bg-purple-600 hover:bg-purple-700 rounded-lg text-white font-semibold transition-colors">
                    Registra il tuo primo audio
                </button>
            </div>
        `;
    }

    /**
     * Show error message
     * @private
     * @param {string} message - Error message
     */
    showError(message) {
        this.container.innerHTML = `
            <div class="bg-red-500/20 border border-red-500/40 rounded-lg p-6 text-center fade-in">
                <svg class="w-12 h-12 mx-auto text-red-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <h3 class="text-xl font-bold text-white mb-2">Errore</h3>
                <p class="text-gray-300 mb-4">${escapeHtml(message)}</p>
                <button onclick="window.feedManager.resetFeed()" class="px-6 py-3 bg-purple-600 hover:bg-purple-700 rounded-lg text-white font-semibold transition-colors">
                    Riprova
                </button>
            </div>
        `;
    }

    /**
     * ENTERPRISE V11.5: Setup gallery navigation in PhotoLightbox
     * Enables prev/next arrows to navigate between feed posts (like profile gallery)
     *
     * @private
     * @param {number} currentIndex - Index of clicked post in this.posts array
     */
    setupGalleryNavigation(currentIndex) {
        if (!window.photoLightbox) {
            console.warn('[FeedManager] photoLightbox not available');
            return;
        }

        const self = this;

        // Build gallery array from all posts with photos
        const galleryPosts = this.posts
            .map((post, idx) => {
                const photoUrl = this.getFirstPhotoUrl(post.photo_urls);
                return {
                    url: photoUrl ? photoUrl.replace('/storage/uploads/', '') : null,
                    post_id: post.id,
                    _fullPost: post,
                    _index: idx
                };
            })
            .filter(item => item.url); // Only posts with photos

        // Set gallery array on PhotoLightbox
        window.photoLightbox.gallery = galleryPosts;

        // Find the current post's index in the filtered gallery
        const galleryIndex = galleryPosts.findIndex(item => item._index === currentIndex);
        window.photoLightbox.currentIndex = galleryIndex >= 0 ? galleryIndex : 0;

        // Update navigation UI (show prev/next buttons if multiple photos)
        window.photoLightbox.updateNavigationUI();

        // Override showPhoto to use local post data (avoids API calls when navigating)
        if (!window.photoLightbox._feedOverrideActive) {
            const originalShowPhoto = window.photoLightbox.showPhoto.bind(window.photoLightbox);

            window.photoLightbox.showPhoto = function(index) {
                if (index < 0 || index >= this.gallery.length) return;

                this.currentIndex = index;
                const photo = this.gallery[index];

                // Show loading spinner
                if (this.loadingSpinner) {
                    this.loadingSpinner.classList.remove('hidden');
                }
                this.imageElement.style.opacity = '0';

                // Set photo URL
                const fullUrl = photo.url.startsWith('/storage/') ? photo.url : `/storage/uploads/${photo.url}`;
                this.imageElement.src = fullUrl;
                this.currentPhotoUrl = photo.url;
                this.currentPostId = photo.post_id;

                // Hide loading when image loads
                this.imageElement.onload = () => {
                    if (this.loadingSpinner) {
                        this.loadingSpinner.classList.add('hidden');
                    }
                    this.imageElement.style.opacity = '1';
                    this.imageElement.style.transition = 'opacity 0.3s ease-out';
                };

                // Error handling
                this.imageElement.onerror = () => {
                    if (this.loadingSpinner) {
                        this.loadingSpinner.classList.add('hidden');
                    }
                };

                // Update navigation UI
                this.updateNavigationUI();

                // Use local post data if available
                if (photo._fullPost) {
                    this.renderFullPostInfo(photo._fullPost);
                    this.loadCommentsOnly(photo.post_id);
                } else {
                    // Fallback to API
                    this.loadPostData(photo.post_id);
                }
            };

            window.photoLightbox._feedOverrideActive = true;

            // Restore original on lightbox close
            const originalClose = window.photoLightbox.close.bind(window.photoLightbox);
            window.photoLightbox.close = function() {
                window.photoLightbox.showPhoto = originalShowPhoto;
                window.photoLightbox._feedOverrideActive = false;
                window.photoLightbox.close = originalClose;
                originalClose();
            };
        }
    }

    /**
     * Get first photo URL from photo_urls field
     * @private
     * @param {string|Array} photoUrls - JSON string or array of photo URLs
     * @returns {string|null} First photo URL or null
     */
    getFirstPhotoUrl(photoUrls) {
        if (!photoUrls) return null;

        try {
            const photos = typeof photoUrls === 'string'
                ? JSON.parse(photoUrls)
                : photoUrls;

            if (!Array.isArray(photos) || photos.length === 0) return null;

            let path = photos[0];

            // Handle absolute server path
            if (path.startsWith('/var/www/html/public/storage/uploads/')) {
                path = path.replace('/var/www/html/public/storage/uploads/', '');
            }

            // Handle absolute web path
            if (path.startsWith('/storage/uploads/')) {
                path = path.replace('/storage/uploads/', '');
            }

            return `/storage/uploads/${path}`;
        } catch (e) {
            return null;
        }
    }
}

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = FeedManager;
}
