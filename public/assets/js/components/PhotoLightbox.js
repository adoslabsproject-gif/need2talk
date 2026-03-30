/**
 * PhotoLightbox - Enterprise Gallery Lightbox
 *
 * Features:
 * - HD photo viewing (full 1920px resolution)
 * - Post metadata display
 * - Keyboard navigation (ESC to close, arrows for gallery)
 * - Smooth animations
 * - Mobile-optimized
 * - Lazy loading
 * - Performance: <50ms open time
 *
 * @package need2talk/Lightning
 * @version 1.0.0
 */

class PhotoLightbox {
    constructor() {
        this.isOpen = false;
        this.currentPostId = null;
        this.currentPhotoUrl = null;
        this.overlay = null;
        this.imageElement = null;
        this.gallery = []; // Array of photos for navigation
        this.currentIndex = 0; // Current photo index in gallery

        // ENTERPRISE: Comments pagination state (max 10 per page)
        this.commentsPerPage = 10;
        this.commentsOffset = 0;
        this.hasMoreComments = false;
        this.loadedComments = [];

        this.init();
    }

    /**
     * Initialize lightbox
     */
    init() {
        this.createDOM();
        this.attachEventListeners();
    }

    /**
     * Create lightbox DOM structure
     * ENTERPRISE V8.0: Desktop layout preserved, mobile-responsive added
     */
    createDOM() {
        // Create overlay
        this.overlay = document.createElement('div');
        this.overlay.id = 'photo-lightbox';
        this.overlay.className = 'fixed inset-0 bg-black/95 z-[100] hidden items-center justify-center p-4';
        this.overlay.style.backdropFilter = 'blur(10px)';

        this.overlay.innerHTML = `
            <!-- ENTERPRISE V8.0: Close button - ALWAYS visible at top-right corner -->
            <button id="lightbox-close-global"
                    class="fixed top-4 right-4 z-[120] p-3 bg-red-600 hover:bg-red-500 rounded-full transition-all shadow-xl border-2 border-white">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>

            <!-- Content container - DESKTOP: horizontal layout (photo LEFT, comments RIGHT), MOBILE: vertical stacked -->
            <div id="lightbox-content-container" class="relative flex flex-col lg:flex-row gap-4 w-full max-w-[1600px] max-h-[92vh] lg:h-[92vh] lg:w-[96vw] overflow-hidden">

                <!-- Photo Container - DESKTOP: 55% width LEFT side, MOBILE: full width with limited height -->
                <div class="flex items-center justify-center relative bg-black/40 rounded-xl overflow-hidden w-full lg:w-[55%] lg:min-w-[400px] lg:h-full flex-shrink-0 lightbox-photo-mobile" id="lightbox-photo-container">
                    <img id="lightbox-image"
                         src=""
                         alt="Photo"
                         class="max-w-full max-h-[35vh] lg:max-h-full object-contain rounded-lg shadow-2xl shadow-purple-500/20"
                         style="animation: fadeIn 0.3s ease-out;">

                    <!-- Navigation arrows -->
                    <button id="lightbox-prev"
                            class="hidden absolute left-4 top-1/2 -translate-y-1/2 p-4 bg-gray-900/80 hover:bg-gray-800 rounded-full transition-colors group">
                        <svg class="w-6 h-6 text-gray-400 group-hover:text-white transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                    </button>
                    <button id="lightbox-next"
                            class="hidden absolute right-4 top-1/2 -translate-y-1/2 p-4 bg-gray-900/80 hover:bg-gray-800 rounded-full transition-colors group">
                        <svg class="w-6 h-6 text-gray-400 group-hover:text-white transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </button>

                    <!-- Photo counter -->
                    <div id="lightbox-counter" class="hidden absolute bottom-4 left-1/2 -translate-x-1/2 px-4 py-2 bg-gray-900/80 rounded-full text-white text-sm">
                        <span id="lightbox-current-index">1</span> / <span id="lightbox-total-count">1</span>
                    </div>
                </div>

                <!-- Sidebar: Post info + Comments - DESKTOP: 45% RIGHT side with scroll, MOBILE: scrollable area -->
                <div id="lightbox-sidebar" class="flex flex-col bg-gray-800/95 backdrop-blur-sm rounded-xl overflow-hidden relative w-full lg:w-[45%] lg:min-w-[400px] lg:h-full flex-1 lightbox-sidebar-mobile">
                    <div id="lightbox-sidebar-scroll" class="p-4 lg:p-6 overflow-y-auto flex-1" style="-webkit-overflow-scrolling: touch; overscroll-behavior: contain;">
                        <!-- Post info -->
                        <div id="lightbox-post-info" class="mb-4 lg:mb-6">
                            <h3 id="lightbox-post-title" class="text-lg lg:text-xl font-bold text-white mb-2"></h3>
                            <!-- Description (audio_description from audio_files) -->
                            <p id="lightbox-post-description" class="text-gray-300 text-sm mb-3 hidden"></p>
                            <div class="flex items-center text-sm text-gray-400 mb-4">
                                <span id="lightbox-post-date"></span>
                                <span class="mx-2">•</span>
                                <span id="lightbox-post-emotion"></span>
                            </div>
                            <!-- ENTERPRISE GALAXY V4.7: Reactions display (same style as post) -->
                            <div id="lightbox-reactions-container" class="flex items-center gap-2 text-sm text-gray-300 flex-wrap">
                                <!-- Reactions rendered dynamically here -->
                            </div>
                            <div class="flex items-center gap-4 text-sm text-gray-300 mt-2">
                                <div class="flex items-center">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                                    </svg>
                                    <span id="lightbox-comments-count">0</span>
                                </div>
                                <div class="flex items-center">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <span id="lightbox-plays-count">0</span>
                                </div>
                            </div>
                        </div>

                        <!-- Comments section -->
                        <div class="border-t border-gray-700 pt-4 lg:pt-6">
                            <h4 class="text-lg font-semibold text-white mb-4">Commenti</h4>
                            <div id="lightbox-comments" class="space-y-4">
                                <!-- Comments loaded here -->
                            </div>
                            <div id="lightbox-comments-empty" class="hidden text-center py-8 text-gray-400 text-sm">
                                Nessun commento ancora
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Loading spinner -->
            <div id="lightbox-loading" class="absolute inset-0 flex items-center justify-center hidden">
                <div class="w-12 h-12 border-4 border-purple-500 border-t-transparent rounded-full animate-spin"></div>
            </div>
        `;

        document.body.appendChild(this.overlay);
        this.imageElement = document.getElementById('lightbox-image');
        this.loadingSpinner = document.getElementById('lightbox-loading');
        this.prevBtn = document.getElementById('lightbox-prev');
        this.nextBtn = document.getElementById('lightbox-next');
        this.counterElement = document.getElementById('lightbox-counter');
    }

    /**
     * Attach event listeners
     */
    attachEventListeners() {
        // ENTERPRISE V8.0: Global close button (always visible - top right corner)
        const closeBtn = document.getElementById('lightbox-close-global');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.close());
        }

        // Click on overlay (outside image) to close
        this.overlay.addEventListener('click', (e) => {
            if (e.target === this.overlay) {
                this.close();
            }
        });

        // Navigation buttons
        if (this.prevBtn) {
            this.prevBtn.addEventListener('click', () => this.previous());
        }
        if (this.nextBtn) {
            this.nextBtn.addEventListener('click', () => this.next());
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (!this.isOpen) return;

            switch(e.key) {
                case 'Escape':
                    this.close();
                    break;
                case 'ArrowLeft':
                    this.previous();
                    break;
                case 'ArrowRight':
                    this.next();
                    break;
            }
        });
    }

    /**
     * ENTERPRISE V8.0: Apply desktop layout adjustments
     * Tailwind classes handle the main layout (55%/45% split)
     * This function adds overflow behavior for desktop sidebar scrolling
     *
     * V8.1 (2025-12-01): Fixed mobile scroll - removed overflowY: 'visible' that was blocking scroll
     */
    applyDesktopLayout() {
        const sidebar = document.getElementById('lightbox-sidebar');
        const sidebarScroll = document.getElementById('lightbox-sidebar-scroll');

        // Check if desktop (lg breakpoint = 1024px)
        const isDesktop = window.innerWidth >= 1024;

        if (isDesktop && sidebarScroll) {
            // Desktop: sidebar content scrolls independently
            sidebarScroll.style.maxHeight = 'calc(92vh - 2rem)';
            sidebarScroll.style.overflowY = 'auto';
        } else if (sidebarScroll) {
            // Mobile: KEEP overflow-y-auto for scrolling (CSS handles max-height)
            // DO NOT set overflowY: 'visible' - it blocks scrolling!
            sidebarScroll.style.overflowY = 'auto';
        }
    }

    /**
     * ENTERPRISE V8.1: Hide floating recorder on mobile when lightbox is open
     * The FAB button can interfere with lightbox scroll on mobile devices
     */
    hideFloatingRecorderOnMobile() {
        const isMobile = window.innerWidth < 1024;
        const fabButton = document.getElementById('floating-recorder-button');

        if (isMobile && fabButton) {
            fabButton.dataset.hiddenByLightbox = 'true';
            fabButton.style.display = 'none';
        }
    }

    /**
     * ENTERPRISE V8.1: Show floating recorder when lightbox closes
     */
    showFloatingRecorder() {
        const fabButton = document.getElementById('floating-recorder-button');

        if (fabButton && fabButton.dataset.hiddenByLightbox === 'true') {
            fabButton.style.display = '';
            delete fabButton.dataset.hiddenByLightbox;
        }
    }

    /**
     * Open lightbox with photo (basic mode - fetches data from API)
     *
     * @param {string} photoUrl - Relative photo URL (without /storage/uploads/ prefix)
     * @param {number} postId - Post ID for future gallery navigation
     */
    async open(photoUrl, postId = null) {
        this.currentPhotoUrl = photoUrl;
        this.currentPostId = postId;

        // Show photo immediately
        this.showPhotoDirectly(photoUrl);

        // Show overlay
        this.overlay.classList.remove('hidden');
        this.overlay.classList.add('flex');
        document.body.style.overflow = 'hidden';
        this.isOpen = true;

        // ENTERPRISE V8.0: Apply proper layout based on viewport
        this.applyDesktopLayout();

        // ENTERPRISE V8.1: Hide floating recorder on mobile (prevents scroll interference)
        this.hideFloatingRecorderOnMobile();

        // Load post data from API
        if (postId) {
            await this.loadPostData(postId);
        }
    }

    /**
     * ENTERPRISE GALAXY: Open lightbox with FULL post data from feed
     * Avoids API call by using data already available in FeedManager
     *
     * @param {string} photoUrl - Photo URL (can include /storage/uploads/ prefix)
     * @param {number} postId - Post ID
     * @param {Object} post - FULL post object from FeedManager.posts array
     */
    openWithPostData(photoUrl, postId, post) {
        this.currentPhotoUrl = photoUrl;
        this.currentPostId = postId;

        // Show photo immediately
        this.showPhotoDirectly(photoUrl);

        // Show overlay
        this.overlay.classList.remove('hidden');
        this.overlay.classList.add('flex');
        document.body.style.overflow = 'hidden';
        this.isOpen = true;

        // ENTERPRISE V8.0: Apply proper layout based on viewport
        this.applyDesktopLayout();

        // ENTERPRISE V8.1: Hide floating recorder on mobile (prevents scroll interference)
        this.hideFloatingRecorderOnMobile();

        // Render post info from feed data (NO API call!)
        this.renderFullPostInfo(post);

        // Load comments from API (comments are not in feed response)
        if (postId) {
            this.loadCommentsOnly(postId);
        }
    }

    /**
     * Show photo directly without gallery lookup
     * @param {string} photoUrl - Photo URL
     */
    showPhotoDirectly(photoUrl) {
        // Ensure URL has correct prefix (handle storage, asset paths, and data URIs)
        const fullUrl = (photoUrl.startsWith('/storage/') || photoUrl.startsWith('/assets/') || photoUrl.startsWith('data:'))
            ? photoUrl
            : `/storage/uploads/${photoUrl}`;

        this.imageElement.src = fullUrl;
        this.imageElement.style.opacity = '0';

        this.imageElement.onload = () => {
            this.imageElement.style.opacity = '1';
            this.imageElement.style.transition = 'opacity 0.3s ease-out';
        };

        this.imageElement.onerror = () => {
            console.error('PhotoLightbox: Failed to load image', fullUrl);
        };
    }

    /**
     * ENTERPRISE GALAXY: Render FULL post info from feed data
     * Uses the exact field names from the backend response
     *
     * V4.7 (2025-11-29): Shows individual reactions with emoji + count (like post)
     *
     * @param {Object} post - Full post object from feed
     */
    renderFullPostInfo(post) {
        const title = document.getElementById('lightbox-post-title');
        const description = document.getElementById('lightbox-post-description');
        const date = document.getElementById('lightbox-post-date');
        const emotion = document.getElementById('lightbox-post-emotion');
        const reactionsContainer = document.getElementById('lightbox-reactions-container');
        const comments = document.getElementById('lightbox-comments-count');
        const plays = document.getElementById('lightbox-plays-count');

        // Title: audio_title from audio_files table
        // ENTERPRISE V7.0 (2025-11-30): Clickable @mentions in title
        if (title) {
            const titleText = post.audio_title || post.title || 'Senza titolo';
            const taggedUsers = post.tagged_users || [];
            title.innerHTML = this.formatTextWithMentions(titleText, taggedUsers);
        }

        // Description: audio_description from audio_files OR content from audio_posts
        // ENTERPRISE V7.0 (2025-11-30): Clickable @mentions in description
        if (description) {
            const descText = post.audio_description || post.content || '';
            const taggedUsers = post.tagged_users || [];
            if (descText && descText.trim()) {
                description.innerHTML = this.formatTextWithMentions(descText, taggedUsers);
                description.classList.remove('hidden');
            } else {
                description.innerHTML = '';
                description.classList.add('hidden');
            }
        }

        // Date
        if (date) {
            date.textContent = this.formatDate(post.created_at);
        }

        // Emotion: from emotion object
        if (emotion && post.emotion) {
            emotion.textContent = `${post.emotion.icon_emoji || ''} ${post.emotion.name_it || ''}`.trim();
        } else if (emotion) {
            emotion.textContent = '';
        }

        // ENTERPRISE V4.7: Render individual reactions (same style as post)
        if (reactionsContainer) {
            reactionsContainer.innerHTML = this.renderReactions(post.reaction_stats || {});
        }

        if (comments) {
            comments.textContent = post.comment_count || 0;
        }
        if (plays) {
            plays.textContent = post.listen_count || 0;
        }
    }

    /**
     * ENTERPRISE V4.7: Render individual reactions with emoji + count
     * Same style as ReactionPicker inline display
     *
     * @param {Object} reactionStats - {emotion_id: count}
     * @returns {string} HTML for reactions display
     */
    renderReactions(reactionStats) {
        // Emotion definitions (same as ReactionPicker)
        const emotions = {
            1: { name: 'Gioia', icon: '😊', color: 'text-yellow-400' },
            2: { name: 'Meraviglia', icon: '✨', color: 'text-orange-400' },
            3: { name: 'Amore', icon: '❤️', color: 'text-red-400' },
            4: { name: 'Gratitudine', icon: '🙏', color: 'text-green-400' },
            5: { name: 'Speranza', icon: '🌟', color: 'text-blue-400' },
            6: { name: 'Tristezza', icon: '😢', color: 'text-blue-300' },
            7: { name: 'Rabbia', icon: '😠', color: 'text-red-500' },
            8: { name: 'Ansia', icon: '😰', color: 'text-purple-400' },
            9: { name: 'Paura', icon: '😨', color: 'text-gray-400' },
            10: { name: 'Solitudine', icon: '😔', color: 'text-indigo-400' },
        };

        // Get reactions sorted by count (descending)
        const reactionsArray = Object.entries(reactionStats || {})
            .map(([id, count]) => ({ id: parseInt(id, 10), count }))
            .filter(e => e.count > 0)
            .sort((a, b) => b.count - a.count);

        if (reactionsArray.length === 0) {
            return '<span class="text-gray-500 text-sm">Nessuna reazione</span>';
        }

        // Render each reaction
        return reactionsArray.map(({ id, count }) => {
            const emotion = emotions[id];
            if (!emotion) return '';

            return `
                <div class="flex items-center px-2 py-1 bg-gray-700/50 rounded-lg" title="${emotion.name}: ${count}">
                    <span class="text-lg mr-1">${emotion.icon}</span>
                    <span class="text-xs ${emotion.color} font-medium">${count}</span>
                </div>
            `;
        }).join('');
    }

    /**
     * Load user's photo gallery for navigation
     */
    async loadGallery() {
        try {
            // ENTERPRISE V8.2: CSRF header auto-injected by csrf.js wrapper for POST/PUT/DELETE
            // GET requests don't need CSRF protection
            const response = await fetch('/api/audio/photos/recent?limit=20', {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.success && data.photos) {
                this.gallery = data.photos;
                this.updateNavigationUI();
            }

        } catch (error) {
            console.error('PhotoLightbox: Failed to load gallery', error);
            this.gallery = [];
        }
    }

    /**
     * Show photo at specific index
     *
     * @param {number} index - Photo index in gallery
     */
    showPhoto(index) {
        if (index < 0 || index >= this.gallery.length) return;

        this.currentIndex = index;
        const photo = this.gallery[index];

        // Show loading spinner
        this.loadingSpinner.classList.remove('hidden');
        this.imageElement.style.opacity = '0';

        // Set full-size photo URL
        this.imageElement.src = `/storage/uploads/${photo.url}`;
        this.currentPhotoUrl = photo.url;
        this.currentPostId = photo.post_id;

        // Hide loading when image loads
        this.imageElement.onload = () => {
            this.loadingSpinner.classList.add('hidden');
            this.imageElement.style.opacity = '1';
            this.imageElement.style.transition = 'opacity 0.3s ease-out';
        };

        // Error handling
        this.imageElement.onerror = () => {
            console.error('PhotoLightbox: Failed to load image', photo.url);
            this.loadingSpinner.classList.add('hidden');
        };

        // Update navigation UI
        this.updateNavigationUI();

        // Load post data for this photo
        if (photo.post_id) {
            this.loadPostData(photo.post_id);
        }
    }

    /**
     * Navigate to previous photo
     */
    previous() {
        if (this.currentIndex > 0) {
            this.showPhoto(this.currentIndex - 1);
        }
    }

    /**
     * Navigate to next photo
     */
    next() {
        if (this.currentIndex < this.gallery.length - 1) {
            this.showPhoto(this.currentIndex + 1);
        }
    }

    /**
     * Update navigation UI (arrows, counter)
     */
    updateNavigationUI() {
        const hasMultiplePhotos = this.gallery.length > 1;

        // Show/hide navigation arrows
        if (this.prevBtn) {
            if (hasMultiplePhotos && this.currentIndex > 0) {
                this.prevBtn.classList.remove('hidden');
            } else {
                this.prevBtn.classList.add('hidden');
            }
        }

        if (this.nextBtn) {
            if (hasMultiplePhotos && this.currentIndex < this.gallery.length - 1) {
                this.nextBtn.classList.remove('hidden');
            } else {
                this.nextBtn.classList.add('hidden');
            }
        }

        // Update counter
        if (this.counterElement) {
            if (hasMultiplePhotos) {
                this.counterElement.classList.remove('hidden');
                document.getElementById('lightbox-current-index').textContent = this.currentIndex + 1;
                document.getElementById('lightbox-total-count').textContent = this.gallery.length;
            } else {
                this.counterElement.classList.add('hidden');
            }
        }
    }

    /**
     * Load post data and comments from API
     *
     * @param {number} postId - Post ID
     */
    async loadPostData(postId) {
        try {
            // ENTERPRISE V8.2: GET doesn't need CSRF
            const response = await fetch(`/api/audio/${postId}`, {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.success && data.post) {
                // ENTERPRISE V4.3 (2025-12-08): Use renderFullPostInfo for consistent field handling
                this.renderFullPostInfo(data.post);
                this.renderComments(data.comments || []);
            }

        } catch (error) {
            console.error('PhotoLightbox: Failed to load post data', error);
        }
    }

    /**
     * Render post information in sidebar
     *
     * ENTERPRISE V4.11 (2025-11-30): Fixed to handle API response structure
     * API returns: { title, stats: { comments, listens, shares }, reaction_stats: {emotion_id: count} }
     * Feed returns: { audio_title, comments_count, plays_count, reaction_stats }
     *
     * V6.3 (2025-11-30): Added reaction_stats rendering (was missing!)
     *
     * @param {Object} post - Post data
     */
    renderPostInfo(post) {
        const title = document.getElementById('lightbox-post-title');
        const description = document.getElementById('lightbox-post-description');
        const date = document.getElementById('lightbox-post-date');
        const emotion = document.getElementById('lightbox-post-emotion');
        const reactionsContainer = document.getElementById('lightbox-reactions-container');
        const comments = document.getElementById('lightbox-comments-count');
        const plays = document.getElementById('lightbox-plays-count');

        // ENTERPRISE: Handle both API and feed data structures
        const postTitle = post.title || post.audio_title || 'Senza titolo';
        const postContent = post.content || '';
        const postStats = post.stats || {};
        const taggedUsers = post.tagged_users || [];

        // ENTERPRISE V4.2 (2025-12-08): Fixed field names to match API response
        // API and FeedManager use: comment_count, listen_count (NOT comments_count, plays_count)
        // Comments: API uses stats.comments, FeedManager uses comment_count
        const commentsCount = postStats.comments ?? post.comment_count ?? 0;
        // Plays/Listens: API uses stats.listens, FeedManager uses listen_count
        const playsCount = postStats.listens ?? post.listen_count ?? 0;

        // ENTERPRISE V7.0 (2025-11-30): Clickable @mentions in title
        if (title) title.innerHTML = this.formatTextWithMentions(postTitle, taggedUsers);

        // ENTERPRISE V7.0 (2025-11-30): Clickable @mentions in description
        if (description) {
            if (postContent) {
                description.innerHTML = this.formatTextWithMentions(postContent, taggedUsers);
                description.classList.remove('hidden');
            } else {
                description.classList.add('hidden');
            }
        }

        if (date) date.textContent = this.formatDate(post.created_at);
        if (emotion && post.emotion) {
            emotion.textContent = `${post.emotion.emoji} ${post.emotion.label}`;
        }

        // ENTERPRISE V6.3 (2025-11-30): Render reactions (was missing!)
        if (reactionsContainer) {
            reactionsContainer.innerHTML = this.renderReactions(post.reaction_stats || {});
        }

        if (comments) comments.textContent = commentsCount;
        if (plays) plays.textContent = playsCount;
    }

    /**
     * ENTERPRISE V4: Render post info from data passed from feed
     * Avoids API call by using data already available in the feed
     *
     * @param {Object} postData - Post data from feed data attributes
     */
    renderPostInfoFromData(postData) {
        const title = document.getElementById('lightbox-post-title');
        const date = document.getElementById('lightbox-post-date');
        const emotion = document.getElementById('lightbox-post-emotion');
        const likes = document.getElementById('lightbox-likes-count');
        const comments = document.getElementById('lightbox-comments-count');
        const plays = document.getElementById('lightbox-plays-count');

        // ENTERPRISE V7.0 (2025-11-30): Clickable @mentions in title
        const taggedUsers = postData.tagged_users || [];
        if (title) title.innerHTML = this.formatTextWithMentions(postData.audio_title || 'Senza titolo', taggedUsers);
        if (date) date.textContent = this.formatDate(postData.created_at);
        if (emotion) emotion.textContent = postData.emotion_text || '';
        if (likes) likes.textContent = postData.likes_count || 0;
        if (comments) comments.textContent = postData.comments_count || 0;
        if (plays) plays.textContent = postData.plays_count || 0;
    }

    /**
     * ENTERPRISE V4: Load only comments from API with pagination
     * Used when post data is passed from feed but comments need fresh load
     *
     * @param {number} postId - Post ID
     * @param {boolean} append - Append to existing comments (for "load more")
     */
    async loadCommentsOnly(postId, append = false) {
        try {
            // ENTERPRISE: Reset pagination on new post
            if (!append) {
                this.commentsOffset = 0;
                this.loadedComments = [];
            }

            // ENTERPRISE V4: Use correct comments endpoint with pagination
            // ENTERPRISE V8.2: GET doesn't need CSRF
            const response = await fetch(`/api/comments/post/${postId}?limit=${this.commentsPerPage}&offset=${this.commentsOffset}`, {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                const newComments = data.comments || [];

                // ENTERPRISE: Update pagination state
                this.hasMoreComments = data.has_more || false;
                this.commentsOffset += newComments.length;

                // Append or replace comments
                if (append) {
                    this.loadedComments = [...this.loadedComments, ...newComments];
                } else {
                    this.loadedComments = newComments;
                }

                this.renderComments(this.loadedComments);
            }

        } catch (error) {
            console.error('PhotoLightbox: Failed to load comments', error);
            // Show empty state on error
            this.renderComments([]);
        }
    }

    /**
     * ENTERPRISE: Load more comments (pagination)
     * Called by "Carica altri commenti" button
     */
    loadMoreComments() {
        if (this.currentPostId && this.hasMoreComments) {
            this.loadCommentsOnly(this.currentPostId, true);
        }
    }

    /**
     * Render comments in lightbox sidebar
     *
     * ENTERPRISE V6.3 (2025-11-30): UNIFIED with CommentManager style
     * Full functionality: reply, edit, delete, like - identical to post comments
     *
     * @param {Array} comments - Comments array
     */
    renderComments(comments) {
        const container = document.getElementById('lightbox-comments');
        const empty = document.getElementById('lightbox-comments-empty');

        if (!container) return;

        if (!comments || comments.length === 0) {
            container.innerHTML = this.renderCommentForm();
            empty?.classList.remove('hidden');
            return;
        }

        empty?.classList.add('hidden');

        // Build HTML with comment form + comments
        let html = this.renderCommentForm();
        html += '<div class="space-y-4 mt-4">';

        comments.forEach(comment => {
            html += this.renderSingleComment(comment, false);
        });

        html += '</div>';

        // Add "Load more comments" button if there are more
        if (this.hasMoreComments) {
            html += `
                <div class="text-center mt-4 pt-4 border-t border-gray-700/50">
                    <button onclick="window.photoLightbox.loadMoreComments()"
                            class="px-4 py-2 text-purple-400 hover:text-purple-300 text-sm font-medium transition-colors flex items-center gap-2 mx-auto">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                        <span>Carica altri commenti</span>
                    </button>
                </div>
            `;
        }

        container.innerHTML = html;
    }

    /**
     * ENTERPRISE V6.3: Render comment form (identical to CommentManager)
     */
    renderCommentForm(parentCommentId = null) {
        if (!this.currentPostId) return '';

        const currentUser = window.need2talk?.user || {};
        // ENTERPRISE V10.155: Use normalizeAvatarUrl helper (mirrors PHP get_avatar_url())
        const rawUserAvatar = currentUser.avatar || currentUser.avatar_url || '';
        const avatar = this.normalizeAvatarUrl(rawUserAvatar);
        const formId = parentCommentId ? `lightbox-reply-form-${parentCommentId}` : `lightbox-comment-form-${this.currentPostId}`;

        return `
            <form id="${formId}" class="flex items-start space-x-3 ${parentCommentId ? 'mt-3 ml-11' : ''}"
                  onsubmit="event.preventDefault(); window.photoLightbox.submitComment(${this.currentPostId}, ${parentCommentId || 'null'}, this)">
                <img src="${this.escapeHtml(avatar)}"
                     alt="Tu"
                     class="w-8 h-8 rounded-full flex-shrink-0"
                     onerror="this.src='/assets/img/default-avatar.png'; this.onerror=null;">
                <div class="flex-1 relative">
                    <div class="relative">
                        <textarea name="text"
                                  placeholder="${parentCommentId ? 'Scrivi una risposta...' : 'Scrivi un commento...'}"
                                  maxlength="500"
                                  rows="1"
                                  class="w-full px-4 py-2 pr-12 bg-gray-700/50 border border-gray-600 rounded-xl text-white placeholder-gray-400 focus:ring-2 focus:ring-purple-500 focus:border-transparent resize-none text-sm"
                                  oninput="this.style.height = 'auto'; this.style.height = Math.min(this.scrollHeight, 120) + 'px';"></textarea>
                        <button type="submit"
                                class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-purple-400 hover:text-purple-300 transition-colors"
                                title="Invia">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </form>
        `;
    }

    /**
     * ENTERPRISE V6.3: Render single comment (identical to CommentManager.renderComment)
     */
    renderSingleComment(comment, isReply = false) {
        const currentUserUuid = window.need2talk?.user?.uuid || '';

        // Normalize data structure
        const authorUuid = comment.author?.uuid || comment.author_uuid || comment.user_uuid || '';
        const authorNickname = comment.author?.nickname || comment.nickname || comment.user_nickname || 'Utente';
        const commentText = comment.text || comment.comment_text || '';

        // ENTERPRISE V10.155: Use normalizeAvatarUrl helper (mirrors PHP get_avatar_url())
        const rawAvatar = comment.author?.avatar_url || comment.avatar_url || comment.user_avatar || '';
        const authorAvatar = this.normalizeAvatarUrl(rawAvatar);

        const isOwner = authorUuid === currentUserUuid || comment.is_owner === true;
        const hasLiked = comment.user_liked || false;
        const likeCount = comment.like_count || 0;
        const replyCount = comment.reply_count || 0;
        const isEdited = comment.is_edited || false;
        const timeAgo = this.formatDate(comment.created_at);

        // ENTERPRISE V6.3: Extract mentioned users for highlighting (same as CommentManager)
        const mentionedUsers = comment.mentioned_users || [];

        return `
            <div class="lightbox-comment-item ${isReply ? 'ml-11 mt-3' : ''}" data-comment-id="${comment.id}">
                <div class="flex items-start space-x-3">
                    <a href="/u/${this.escapeHtml(authorUuid)}" class="flex-shrink-0">
                        <img src="${this.escapeHtml(authorAvatar)}"
                             alt="${this.escapeHtml(authorNickname)}"
                             class="w-8 h-8 rounded-full hover:ring-2 hover:ring-purple-500 transition-all"
                             onerror="this.src='/assets/img/default-avatar.png'; this.onerror=null;">
                    </a>
                    <div class="flex-1 min-w-0">
                        <div class="bg-gray-700/50 rounded-xl px-4 py-2">
                            <div class="flex items-center space-x-2">
                                <a href="/u/${this.escapeHtml(authorUuid)}"
                                   class="font-semibold text-white text-sm hover:text-purple-400 transition-colors">
                                    ${this.escapeHtml(authorNickname)}
                                </a>
                                ${isEdited ? '<span class="text-xs text-gray-500">(modificato)</span>' : ''}
                            </div>
                            <p class="text-gray-300 text-sm mt-1 break-words lightbox-comment-text">${this.formatTextWithMentions(commentText, mentionedUsers)}</p>
                        </div>

                        <!-- Comment Actions -->
                        <div class="flex items-center space-x-4 mt-1 text-xs text-gray-400 px-2">
                            <span>${timeAgo}</span>

                            <!-- Like Button -->
                            <button onclick="window.photoLightbox.toggleCommentLike(${comment.id})"
                                    class="lightbox-like-btn flex items-center space-x-1 hover:text-pink-400 transition-colors ${hasLiked ? 'text-pink-500' : ''}"
                                    data-liked="${hasLiked}">
                                <svg class="w-4 h-4 ${hasLiked ? 'fill-current' : ''}" fill="${hasLiked ? 'currentColor' : 'none'}" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                                </svg>
                                <span class="lightbox-like-count">${likeCount > 0 ? likeCount : ''}</span>
                            </button>

                            <!-- Reply Button -->
                            <button onclick="window.photoLightbox.showReplyForm(${comment.id}, ${isReply}, '${this.escapeHtml(authorNickname)}')"
                                    class="hover:text-purple-400 transition-colors">
                                Rispondi
                            </button>

                            ${isOwner ? `
                                <!-- Edit Button -->
                                <button onclick="window.photoLightbox.editComment(${comment.id})"
                                        class="hover:text-purple-400 transition-colors">
                                    Modifica
                                </button>

                                <!-- Delete Button -->
                                <button onclick="window.photoLightbox.deleteComment(${comment.id})"
                                        class="hover:text-red-400 transition-colors">
                                    Elimina
                                </button>
                            ` : ''}
                        </div>

                        <!-- Reply Form Container -->
                        <div id="lightbox-reply-container-${comment.id}" class="hidden"></div>

                        <!-- Replies Container -->
                        ${!isReply && replyCount > 0 ? `
                            <div id="lightbox-replies-${comment.id}" class="mt-2">
                                <button onclick="window.photoLightbox.toggleReplies(${comment.id}, ${replyCount})"
                                        class="text-xs text-purple-400 hover:text-purple-300 flex items-center space-x-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                                    </svg>
                                    <span id="lightbox-replies-text-${comment.id}">Mostra ${replyCount} ${replyCount === 1 ? 'risposta' : 'risposte'}</span>
                                </button>
                                <div id="lightbox-replies-list-${comment.id}" class="hidden space-y-2"></div>
                            </div>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * ENTERPRISE V6.3: Submit comment from lightbox
     */
    async submitComment(postId, parentCommentId, form) {
        const textarea = form.querySelector('textarea[name="text"]');
        const text = textarea.value.trim();

        if (!text) {
            if (window.showToast) window.showToast('Il commento non può essere vuoto', 'warning');
            return;
        }

        const submitBtn = form.querySelector('button[type="submit"]');
        const originalHTML = submitBtn.innerHTML;
        submitBtn.innerHTML = '<svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>';
        submitBtn.disabled = true;
        textarea.disabled = true;

        try {
            // ENTERPRISE V8.2: CSRF auto-injected by csrf.js wrapper
            const response = await fetch('/api/comments', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    post_id: postId,
                    text: text,
                    parent_comment_id: parentCommentId
                })
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Errore nel salvataggio');
            }

            // Clear form
            textarea.value = '';
            textarea.style.height = 'auto';

            // Add comment to DOM
            if (parentCommentId) {
                // Reply - hide form and add to replies
                const replyContainer = document.getElementById(`lightbox-reply-container-${parentCommentId}`);
                if (replyContainer) {
                    replyContainer.classList.add('hidden');
                    replyContainer.innerHTML = '';
                }

                // Add reply to replies list
                let repliesContainer = document.getElementById(`lightbox-replies-list-${parentCommentId}`);
                if (!repliesContainer) {
                    const replyFormContainer = document.getElementById(`lightbox-reply-container-${parentCommentId}`);
                    repliesContainer = document.createElement('div');
                    repliesContainer.id = `lightbox-replies-list-${parentCommentId}`;
                    repliesContainer.className = 'space-y-2';
                    if (replyFormContainer) {
                        replyFormContainer.insertAdjacentElement('afterend', repliesContainer);
                    }
                }

                if (repliesContainer) {
                    repliesContainer.classList.remove('hidden');
                    repliesContainer.insertAdjacentHTML('beforeend', this.renderSingleComment(data.comment, true));
                }
            } else {
                // Top-level comment - add to list
                const commentsList = document.querySelector('#lightbox-comments .space-y-4');
                if (commentsList) {
                    commentsList.insertAdjacentHTML('afterbegin', this.renderSingleComment(data.comment, false));
                }

                // Hide empty state
                document.getElementById('lightbox-comments-empty')?.classList.add('hidden');
            }

            // Update comment count in lightbox
            const countEl = document.getElementById('lightbox-comments-count');
            if (countEl) {
                countEl.textContent = parseInt(countEl.textContent || 0) + 1;
            }

            if (window.showToast) {
                window.showToast(parentCommentId ? 'Risposta aggiunta!' : 'Commento aggiunto!', 'success');
            }

        } catch (error) {
            console.error('PhotoLightbox: Submit comment failed', error);
            if (window.showToast) window.showToast(error.message || 'Errore nel salvataggio', 'error');
        } finally {
            submitBtn.innerHTML = originalHTML;
            submitBtn.disabled = false;
            textarea.disabled = false;
        }
    }

    /**
     * ENTERPRISE V6.3: Show reply form
     */
    showReplyForm(commentId, isReply = false, replyToNickname = '') {
        let rootCommentId = commentId;

        if (isReply) {
            const replyElement = document.querySelector(`[data-comment-id="${commentId}"]`);
            if (replyElement) {
                const repliesContainer = replyElement.closest('[id^="lightbox-replies-list-"]');
                if (repliesContainer) {
                    const match = repliesContainer.id.match(/lightbox-replies-list-(\d+)/);
                    if (match) rootCommentId = parseInt(match[1]);
                }
            }
        }

        const container = document.getElementById(`lightbox-reply-container-${rootCommentId}`);
        if (!container) return;

        // Hide other reply forms
        document.querySelectorAll('[id^="lightbox-reply-container-"]').forEach(el => {
            if (el.id !== `lightbox-reply-container-${rootCommentId}`) {
                el.classList.add('hidden');
                el.innerHTML = '';
            }
        });

        // Toggle if already visible
        if (!container.classList.contains('hidden') && !replyToNickname) {
            container.classList.add('hidden');
            container.innerHTML = '';
            return;
        }

        container.innerHTML = this.renderCommentForm(rootCommentId);
        container.classList.remove('hidden');

        // Pre-fill @mention
        const textarea = container.querySelector('textarea');
        if (textarea && replyToNickname) {
            textarea.value = `@${replyToNickname} `;
            textarea.setSelectionRange(textarea.value.length, textarea.value.length);
        }
        textarea?.focus();
    }

    /**
     * ENTERPRISE V8.3: Toggle comment like with debounce lock
     *
     * Prevents race conditions from rapid clicking:
     * - Locks button during API call
     * - Visual feedback (opacity) when locked
     * - Auto-unlock on success/failure
     */
    async toggleCommentLike(commentId) {
        const commentEl = document.querySelector(`[data-comment-id="${commentId}"]`);
        if (!commentEl) return;

        const likeBtn = commentEl.querySelector('.lightbox-like-btn');

        // ENTERPRISE: Prevent rapid clicks with lock
        if (likeBtn.dataset.pending === 'true') {
            return;
        }

        const likeCountEl = likeBtn.querySelector('.lightbox-like-count');
        const isLiked = likeBtn.dataset.liked === 'true';
        const currentCount = parseInt(likeCountEl.textContent) || 0;

        // Lock button during API call
        likeBtn.dataset.pending = 'true';
        likeBtn.style.opacity = '0.5';
        likeBtn.style.pointerEvents = 'none';

        // Optimistic update
        const newCount = isLiked ? currentCount - 1 : currentCount + 1;
        likeBtn.dataset.liked = (!isLiked).toString();
        likeCountEl.textContent = newCount > 0 ? newCount : '';

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
            // ENTERPRISE V8.2: CSRF auto-injected by csrf.js wrapper
            const response = await fetch(`/api/comments/${commentId}/like`, {
                method: isLiked ? 'DELETE' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include'
            });

            const data = await response.json();
            if (!data.success) throw new Error(data.error);

            // Update with server's authoritative count
            if (data.like_count !== undefined) {
                likeCountEl.textContent = data.like_count > 0 ? data.like_count : '';
            }

            // Update liked state from server response
            if (data.liked !== undefined) {
                likeBtn.dataset.liked = data.liked.toString();
                if (data.liked) {
                    likeBtn.classList.add('text-pink-500');
                    svg.classList.add('fill-current');
                    svg.setAttribute('fill', 'currentColor');
                } else {
                    likeBtn.classList.remove('text-pink-500');
                    svg.classList.remove('fill-current');
                    svg.setAttribute('fill', 'none');
                }
            }

        } catch (error) {
            // Rollback to original state
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
            console.error('PhotoLightbox: Toggle like failed', error);
        } finally {
            // ENTERPRISE: Always unlock button (success or failure)
            likeBtn.dataset.pending = 'false';
            likeBtn.style.opacity = '';
            likeBtn.style.pointerEvents = '';
        }
    }

    /**
     * ENTERPRISE V6.3: Edit comment
     */
    editComment(commentId) {
        const commentEl = document.querySelector(`[data-comment-id="${commentId}"]`);
        if (!commentEl) return;

        const textEl = commentEl.querySelector('.lightbox-comment-text');
        const currentText = textEl.textContent;
        const container = textEl.parentElement;

        const originalHTML = container.innerHTML;
        container.innerHTML = `
            <textarea class="lightbox-edit-textarea w-full px-3 py-2 bg-gray-600 border border-gray-500 rounded-lg text-white text-sm resize-none"
                      rows="2" maxlength="500">${this.escapeHtml(currentText)}</textarea>
            <div class="flex items-center justify-end mt-2 space-x-2">
                <button onclick="window.photoLightbox.cancelEdit(${commentId})"
                        class="px-3 py-1 text-xs text-gray-400 hover:text-gray-300">Annulla</button>
                <button onclick="window.photoLightbox.saveEdit(${commentId})"
                        class="px-3 py-1 text-xs bg-purple-600 hover:bg-purple-700 text-white rounded-lg">Salva</button>
            </div>
        `;

        container.dataset.originalHtml = originalHTML;
        container.querySelector('textarea').focus();
    }

    /**
     * ENTERPRISE V6.3: Cancel edit
     */
    cancelEdit(commentId) {
        const commentEl = document.querySelector(`[data-comment-id="${commentId}"]`);
        if (!commentEl) return;

        const container = commentEl.querySelector('.bg-gray-700\\/50');
        if (container?.dataset.originalHtml) {
            container.innerHTML = container.dataset.originalHtml;
            delete container.dataset.originalHtml;
        }
    }

    /**
     * ENTERPRISE V6.3: Save edit
     */
    async saveEdit(commentId) {
        const commentEl = document.querySelector(`[data-comment-id="${commentId}"]`);
        if (!commentEl) return;

        const container = commentEl.querySelector('.bg-gray-700\\/50');
        const textarea = container.querySelector('.lightbox-edit-textarea');
        const newText = textarea.value.trim();

        if (!newText) {
            if (window.showToast) window.showToast('Il commento non può essere vuoto', 'warning');
            return;
        }

        try {
            // ENTERPRISE V8.2: CSRF auto-injected by csrf.js wrapper
            const response = await fetch(`/api/comments/${commentId}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ text: newText })
            });

            const data = await response.json();
            if (!data.success) throw new Error(data.error);

            const nickname = data.comment?.nickname || '';
            container.innerHTML = `
                <div class="flex items-center space-x-2">
                    <span class="font-semibold text-white text-sm">${this.escapeHtml(nickname)}</span>
                    <span class="text-xs text-gray-500">(modificato)</span>
                </div>
                <p class="text-gray-300 text-sm mt-1 break-words lightbox-comment-text">${this.escapeHtml(newText)}</p>
            `;
            delete container.dataset.originalHtml;

            if (window.showToast) window.showToast('Commento modificato', 'success');

        } catch (error) {
            console.error('PhotoLightbox: Save edit failed', error);
            if (window.showToast) window.showToast(error.message || 'Errore nel salvataggio', 'error');
        }
    }

    /**
     * ENTERPRISE V6.3: Delete comment
     */
    async deleteComment(commentId) {
        if (!confirm('Sei sicuro di voler eliminare questo commento?')) return;

        try {
            // ENTERPRISE V8.2: CSRF auto-injected by csrf.js wrapper
            const response = await fetch(`/api/comments/${commentId}`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include'
            });

            const data = await response.json();
            if (!data.success) throw new Error(data.error);

            const commentEl = document.querySelector(`[data-comment-id="${commentId}"]`);
            if (commentEl) {
                commentEl.classList.add('opacity-50', 'transition-opacity');
                setTimeout(() => commentEl.remove(), 200);
            }

            const countEl = document.getElementById('lightbox-comments-count');
            if (countEl) {
                const newCount = Math.max(0, parseInt(countEl.textContent || 0) - 1);
                countEl.textContent = newCount;
            }

            if (window.showToast) window.showToast('Commento eliminato', 'success');

        } catch (error) {
            console.error('PhotoLightbox: Delete comment failed', error);
            if (window.showToast) window.showToast(error.message || 'Errore nell\'eliminazione', 'error');
        }
    }

    /**
     * Toggle replies visibility (show/hide)
     *
     * ENTERPRISE V6.3: Uses renderSingleComment for unified rendering
     *
     * @param {number} commentId - Parent comment ID
     * @param {number} replyCount - Number of replies
     */
    async toggleReplies(commentId, replyCount) {
        const repliesContainer = document.getElementById(`lightbox-replies-list-${commentId}`);
        const textSpan = document.getElementById(`lightbox-replies-text-${commentId}`);

        if (!repliesContainer) return;

        const isVisible = !repliesContainer.classList.contains('hidden');

        // If visible, hide it
        if (isVisible) {
            repliesContainer.classList.add('hidden');
            if (textSpan) textSpan.textContent = `Mostra ${replyCount} ${replyCount === 1 ? 'risposta' : 'risposte'}`;
            return;
        }

        // If already loaded, just show
        if (repliesContainer.innerHTML.trim() !== '') {
            repliesContainer.classList.remove('hidden');
            if (textSpan) textSpan.textContent = `Nascondi ${replyCount === 1 ? 'risposta' : 'risposte'}`;
            return;
        }

        // Show loading state
        if (textSpan) textSpan.textContent = 'Caricamento...';

        try {
            // ENTERPRISE V8.2: GET doesn't need CSRF
            const response = await fetch(`/api/comments/${commentId}/replies`, {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.success && data.replies) {
                // ENTERPRISE V6.3: Use renderSingleComment for unified reply rendering
                repliesContainer.innerHTML = data.replies.map(reply => this.renderSingleComment(reply, true)).join('');

                repliesContainer.classList.remove('hidden');
                if (textSpan) textSpan.textContent = `Nascondi ${replyCount === 1 ? 'risposta' : 'risposte'}`;
            }

        } catch (error) {
            console.error('PhotoLightbox: Failed to load replies', error);
            if (textSpan) textSpan.textContent = 'Errore caricamento';
        }
    }

    /**
     * Legacy method for backwards compatibility
     * @deprecated Use toggleReplies instead
     */
    loadReplies(commentId) {
        // Get reply count from button text or default to 1
        const textSpan = document.getElementById(`lightbox-replies-text-${commentId}`);
        const match = textSpan?.textContent?.match(/(\d+)/);
        const replyCount = match ? parseInt(match[1]) : 1;
        this.toggleReplies(commentId, replyCount);
    }

    /**
     * Format date for display
     *
     * @param {string} dateString - ISO date string
     * @returns {string} Formatted date
     */
    formatDate(dateString) {
        if (!dateString) return '';

        const date = new Date(dateString);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);

        if (diffMins < 1) return 'Ora';
        if (diffMins < 60) return `${diffMins}min fa`;
        if (diffHours < 24) return `${diffHours}h fa`;
        if (diffDays < 7) return `${diffDays}g fa`;

        return date.toLocaleDateString('it-IT', { day: 'numeric', month: 'short' });
    }

    /**
     * HTML escape helper
     *
     * @param {string} text - Text to escape
     * @returns {string} Escaped HTML
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * ENTERPRISE V10.155: Normalize avatar URL (mirrors PHP get_avatar_url())
     *
     * Database avatar_url formats:
     * - Google OAuth: "https://lh3.googleusercontent.com/..." (passthrough)
     * - Local upload: "avatars/123/avatar_123_1234567890.webp" (relative)
     * - Null/empty: Default avatar
     *
     * @param {string} avatarUrl - Avatar URL from API response
     * @returns {string} - Full avatar URL for <img src="">
     */
    normalizeAvatarUrl(avatarUrl) {
        // Empty → default avatar
        if (!avatarUrl) {
            return '/assets/img/default-avatar.png';
        }

        // External (Google OAuth, CDN) → passthrough
        if (avatarUrl.startsWith('https://') || avatarUrl.startsWith('http://')) {
            return avatarUrl;
        }

        // Already prefixed → passthrough
        if (avatarUrl.startsWith('/storage/uploads/')) {
            return avatarUrl;
        }

        // Legacy: just /storage/ prefix → passthrough
        if (avatarUrl.startsWith('/storage/')) {
            return avatarUrl;
        }

        // Already starts with /assets/ (default avatar) → passthrough
        if (avatarUrl.startsWith('/assets/')) {
            return avatarUrl;
        }

        // Relative path → add /storage/uploads/ prefix
        return '/storage/uploads/' + avatarUrl.replace(/^\/+/, '');
    }

    /**
     * ENTERPRISE V10.155: Generate audio placeholder as data URI SVG
     *
     * Used when audio post has no photo attached.
     * Benefits over static PNG:
     * - Zero HTTP requests (inline data URI)
     * - Scalable (vector graphics)
     * - Theme-consistent (purple gradient)
     * - Cacheable by browser
     *
     * @returns {string} Data URI for SVG placeholder
     */
    getAudioPlaceholderDataUri() {
        // SVG with gradient background and centered microphone icon
        // ENTERPRISE V10.156: Fixed icon centering
        const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400" fill="none">
            <defs>
                <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" style="stop-color:#6366f1"/>
                    <stop offset="100%" style="stop-color:#a855f7"/>
                </linearGradient>
            </defs>
            <rect width="400" height="400" rx="16" fill="url(#bg)"/>
            <g fill="white" fill-opacity="0.9">
                <!-- Microphone body (centered at x=200) -->
                <rect x="160" y="80" width="80" height="120" rx="40" />
                <!-- Stand arc -->
                <path d="M140 160 v20 a60 60 0 0 0 120 0 v-20 h-16 v20 a44 44 0 0 1 -88 0 v-20 z"/>
                <!-- Stand pole -->
                <rect x="192" y="235" width="16" height="45"/>
                <!-- Stand base -->
                <rect x="160" y="275" width="80" height="16" rx="4"/>
            </g>
            <text x="200" y="340" text-anchor="middle" fill="white" fill-opacity="0.7" font-family="system-ui, sans-serif" font-size="18" font-weight="500">Audio Post</text>
        </svg>`;

        // Encode to base64 for data URI
        return 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(svg)));
    }

    /**
     * ENTERPRISE V10.155: Generate deleted post placeholder as data URI SVG
     *
     * Used when post has been deleted but accessed via notification/link.
     *
     * @returns {string} Data URI for SVG placeholder
     */
    getDeletedPostPlaceholderDataUri() {
        const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400" fill="none">
            <defs>
                <linearGradient id="bg-deleted" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" style="stop-color:#374151"/>
                    <stop offset="100%" style="stop-color:#1f2937"/>
                </linearGradient>
            </defs>
            <rect width="400" height="400" rx="16" fill="url(#bg-deleted)"/>
            <g transform="translate(150, 120)" fill="white" fill-opacity="0.5">
                <path d="M100 20H0V0h25V-20h50V0h25v20zM10 30v90c0 11 9 20 20 20h40c11 0 20-9 20-20V30H10zm25 70V50h10v50H35zm20 0V50h10v50H55z"/>
            </g>
            <text x="200" y="300" text-anchor="middle" fill="white" fill-opacity="0.5" font-family="system-ui, sans-serif" font-size="16" font-weight="500">Post eliminato</text>
        </svg>`;

        return 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(svg)));
    }

    /**
     * ENTERPRISE V6.3: Format comment text with @mention highlighting
     * Same as CommentManager.formatTextWithMentions for consistency
     *
     * @param {string} text - Comment text
     * @param {Array} mentionedUsers - Array of mentioned user objects
     * @returns {string} HTML with highlighted mentions
     */
    formatTextWithMentions(text, mentionedUsers = []) {
        if (!text) return '';
        if (!mentionedUsers || mentionedUsers.length === 0) {
            return this.escapeHtml(text);
        }

        // Replace @mentions with links
        let formatted = this.escapeHtml(text);
        mentionedUsers.forEach(user => {
            const regex = new RegExp(`@${user.nickname}(?![a-zA-Z0-9_])`, 'gi');
            formatted = formatted.replace(regex,
                `<a href="/u/${this.escapeHtml(user.uuid)}" class="text-purple-400 hover:text-purple-300 font-medium">@${this.escapeHtml(user.nickname)}</a>`
            );
        });

        return formatted;
    }

    /**
     * ENTERPRISE V4.10 (2025-11-30): Open lightbox by post ID only
     *
     * Used by notification system when user clicks on a reaction/comment notification.
     * Loads the post data from API and opens the lightbox with the photo.
     *
     * @param {number} postId - Post ID to load
     * @param {Object} options - Optional parameters
     * @param {number} options.commentId - Comment ID to scroll to (for comment notifications)
     */
    async openByPostId(postId, options = {}) {
        if (!postId) {
            return false;
        }

        // Store options for later use
        this.pendingScrollToComment = options.commentId || null;

        // Show overlay with loading state
        this.overlay.classList.remove('hidden');
        this.overlay.classList.add('flex');
        document.body.style.overflow = 'hidden';
        this.isOpen = true;

        // ENTERPRISE V8.1: Hide floating recorder on mobile (prevents scroll interference)
        this.hideFloatingRecorderOnMobile();

        // Show loading spinner on image
        this.imageElement.src = '';
        this.imageElement.alt = 'Caricamento...';

        try {
            // ENTERPRISE V8.2: GET doesn't need CSRF
            const response = await fetch(`/api/audio/${postId}`, {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.success && data.post) {
                const post = data.post;

                // Get photo URL from post
                const photoUrl = post.photo_url || post.image_url;
                if (photoUrl) {
                    this.currentPhotoUrl = photoUrl;
                    this.currentPostId = postId;
                    this.showPhotoDirectly(photoUrl);
                } else {
                    // ENTERPRISE V10.155: No photo - show inline SVG placeholder (zero HTTP requests)
                    this.imageElement.src = this.getAudioPlaceholderDataUri();
                    this.imageElement.alt = 'Audio Post';
                    this.imageElement.style.opacity = '1';
                    this.currentPostId = postId;
                }

                // ENTERPRISE V4.3 (2025-12-08): Use renderFullPostInfo for consistent field handling
                this.renderFullPostInfo(post);

                // Render comments
                if (data.comments) {
                    this.renderComments(data.comments);

                    // Scroll to specific comment if requested
                    if (this.pendingScrollToComment) {
                        setTimeout(() => {
                            const commentEl = document.getElementById(`lightbox-comment-${this.pendingScrollToComment}`);
                            if (commentEl) {
                                commentEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                // Highlight the comment briefly
                                commentEl.classList.add('ring-2', 'ring-purple-500', 'ring-opacity-50');
                                setTimeout(() => {
                                    commentEl.classList.remove('ring-2', 'ring-purple-500', 'ring-opacity-50');
                                }, 3000);
                            }
                            this.pendingScrollToComment = null;
                        }, 300);
                    }
                }

                return true;
            } else {
                throw new Error(data.error || 'Post not found');
            }

        } catch (error) {
            console.error('PhotoLightbox: Failed to load post by ID', error);

            // ENTERPRISE V6.4 (2025-11-30): Show "post deleted" message instead of silent close
            this.showDeletedPostMessage();
            return false;
        }
    }

    /**
     * Show message when post is deleted/not found
     * ENTERPRISE V6.4: Provides user feedback for deleted posts accessed via notifications
     */
    showDeletedPostMessage() {
        // ENTERPRISE V10.155: Update lightbox content with inline SVG placeholder (zero HTTP requests)
        if (this.imageElement) {
            this.imageElement.src = this.getDeletedPostPlaceholderDataUri();
            this.imageElement.alt = 'Post non disponibile';
            this.imageElement.style.opacity = '1';
            this.imageElement.style.maxWidth = '200px';
        }

        // Show message in info section
        const infoSection = document.getElementById('lightbox-post-info');
        if (infoSection) {
            infoSection.innerHTML = `
                <div class="flex flex-col items-center justify-center py-8 text-center">
                    <svg class="w-16 h-16 text-gray-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    <h3 class="text-lg font-semibold text-white mb-2">Post non più disponibile</h3>
                    <p class="text-gray-400 text-sm mb-4">Questo contenuto è stato eliminato dall'autore.</p>
                    <button onclick="window.photoLightbox.close()"
                            class="px-4 py-2 bg-purple-600 hover:bg-purple-500 text-white rounded-lg transition-colors">
                        Chiudi
                    </button>
                </div>
            `;
        }

        // Hide comments section
        const commentsSection = document.getElementById('lightbox-comments-container');
        if (commentsSection) {
            commentsSection.style.display = 'none';
        }

        // Auto-close after 3 seconds
        setTimeout(() => {
            if (this.isOpen) {
                this.close();
            }
        }, 3000);
    }

    /**
     * Close lightbox
     *
     * ENTERPRISE V8.1: Restores floating recorder visibility on mobile
     */
    close() {
        // ENTERPRISE V8.1: Restore floating recorder visibility on mobile
        this.showFloatingRecorder();

        // Fade out animation
        this.overlay.style.opacity = '0';
        this.overlay.style.transition = 'opacity 0.2s ease-out';

        setTimeout(() => {
            this.overlay.classList.add('hidden');
            this.overlay.classList.remove('flex');
            this.overlay.style.opacity = '1';

            // Restore body scroll
            document.body.style.overflow = '';

            this.isOpen = false;
            this.currentPhotoUrl = null;
            this.currentPostId = null;
        }, 200);
    }
}

// Auto-initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.photoLightbox = new PhotoLightbox();
    });
} else {
    window.photoLightbox = new PhotoLightbox();
}

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PhotoLightbox;
}
