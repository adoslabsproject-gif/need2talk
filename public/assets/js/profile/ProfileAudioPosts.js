/**
 * ================================================================================
 * PROFILE AUDIO POSTS - THUMBNAIL GALLERY (ENTERPRISE GALAXY V4)
 * ================================================================================
 *
 * PURPOSE:
 * Display user's audio posts as a responsive thumbnail grid.
 * Click thumbnail → opens PhotoLightbox with full gallery navigation.
 *
 * DESIGN (Silicon Valley Standard):
 * - Thumbnail grid (not day containers)
 * - Each thumbnail shows: photo, plays, comments, reactions
 * - Click → PhotoLightbox with prev/next navigation (like Facebook)
 * - Enterprise-grade (100k+ users)
 *
 * @version 4.0.0 - Thumbnail Gallery with PhotoLightbox
 * @author need2talk.it - AI-Orchestrated Development
 * ================================================================================
 */

class ProfileAudioPosts {
    constructor(containerId = 'userPostsContainer') {
        this.container = document.getElementById(containerId);
        if (!this.container) {
            return;
        }

        this.posts = window.userProfilePosts || [];
        this.isOwnProfile = window.isOwnProfile || false;

        // Emotion definitions for reaction display
        this.emotions = {
            1: { name: 'Gioia', icon: '😊' },
            2: { name: 'Meraviglia', icon: '✨' },
            3: { name: 'Amore', icon: '❤️' },
            4: { name: 'Gratitudine', icon: '🙏' },
            5: { name: 'Speranza', icon: '🌟' },
            6: { name: 'Tristezza', icon: '😢' },
            7: { name: 'Rabbia', icon: '😠' },
            8: { name: 'Ansia', icon: '😰' },
            9: { name: 'Paura', icon: '😨' },
            10: { name: 'Solitudine', icon: '😔' }
        };

        this.init();
    }

    init() {
        if (this.posts.length === 0) {
            this.renderEmptyState();
            return;
        }

        // Sort posts by date (newest first)
        this.posts.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));

        // Render thumbnail grid
        this.renderThumbnailGrid();
    }

    /**
     * Render empty state when no posts
     */
    renderEmptyState() {
        this.container.innerHTML = `
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <svg class="w-16 h-16 text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/>
                </svg>
                <h3 class="text-lg font-semibold text-gray-400 mb-2">Nessun audio pubblicato</h3>
                <p class="text-gray-500 text-sm">${this.isOwnProfile ? 'I tuoi audio appariranno qui' : 'Questo utente non ha ancora pubblicato audio'}</p>
            </div>
        `;
    }

    /**
     * Render responsive thumbnail grid
     */
    renderThumbnailGrid() {
        this.container.innerHTML = '';
        this.container.className = 'grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3 sm:gap-4';

        this.posts.forEach((post, index) => {
            const thumbnail = this.createThumbnail(post, index);
            this.container.appendChild(thumbnail);
        });
    }

    /**
     * Create single thumbnail element
     * @param {Object} post - Post data
     * @param {number} index - Index in posts array (for gallery navigation)
     */
    createThumbnail(post, index) {
        const thumbnail = document.createElement('div');
        thumbnail.className = 'group relative aspect-square rounded-xl overflow-hidden bg-gray-800/60 cursor-pointer hover:ring-2 hover:ring-purple-500/50 transition-all duration-300';
        thumbnail.dataset.postId = post.id;
        thumbnail.dataset.index = index;

        // Get first photo URL
        const photoUrl = this.getFirstPhotoUrl(post.photo_urls);
        const hasPhoto = !!photoUrl;

        // Stats
        const playCount = post.play_count || 0;
        const commentCount = post.comment_count || 0;

        // ENTERPRISE: Normalize reaction data from different sources
        // Feed uses: post.reaction_stats {emotion_id: count}
        // Profile uses: post.reactions.top_emotions {emotion_id: count}
        const reactionStats = post.reaction_stats || post.reactions?.top_emotions || {};

        // Get top 3 reactions (sorted by count)
        const topReactions = this.getTopReactions(reactionStats, 3);

        thumbnail.innerHTML = `
            <!-- Photo or placeholder -->
            ${hasPhoto ? `
                <img src="${this.escapeHtml(photoUrl)}"
                     alt="Audio post"
                     class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500"
                     loading="lazy"
                     onerror="this.parentElement.classList.add('no-photo'); this.style.display='none';">
            ` : `
                <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-purple-900/40 to-gray-800/60">
                    <svg class="w-12 h-12 text-purple-400/60" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M7 4a3 3 0 016 0v4a3 3 0 11-6 0V4zm4 10.93A7.001 7.001 0 0017 8a1 1 0 10-2 0A5 5 0 015 8a1 1 0 00-2 0 7.001 7.001 0 006 6.93V17H6a1 1 0 100 2h8a1 1 0 100-2h-3v-2.07z"></path>
                    </svg>
                </div>
            `}

            <!-- Overlay gradient (always visible on hover, stats always visible) -->
            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent opacity-100 group-hover:opacity-100 transition-opacity"></div>

            <!-- Stats overlay (bottom) -->
            <div class="absolute bottom-0 left-0 right-0 p-2 sm:p-3">
                <div class="flex items-center justify-between text-white text-xs sm:text-sm">
                    <!-- Left: Plays & Comments -->
                    <div class="flex items-center gap-2 sm:gap-3">
                        <!-- Plays -->
                        <div class="flex items-center gap-1" title="Ascolti">
                            <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M10 12a2 2 0 100-4 2 2 0 000 4z"></path>
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"></path>
                            </svg>
                            <span>${this.formatNumber(playCount)}</span>
                        </div>

                        <!-- Comments -->
                        <div class="flex items-center gap-1" title="Commenti">
                            <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"></path>
                            </svg>
                            <span>${this.formatNumber(commentCount)}</span>
                        </div>
                    </div>

                    <!-- Right: Top Reactions -->
                    ${topReactions.length > 0 ? `
                        <div class="flex items-center -space-x-1">
                            ${topReactions.map(r => `
                                <span class="text-sm sm:text-base" title="${this.emotions[r.id]?.name || ''}: ${r.count}">${r.icon || this.emotions[r.id]?.icon || ''}</span>
                            `).join('')}
                        </div>
                    ` : ''}
                </div>
            </div>

            <!-- Hover play icon (center) -->
            <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
                <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-full bg-purple-600/90 flex items-center justify-center shadow-lg transform scale-75 group-hover:scale-100 transition-transform">
                    <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white ml-1" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"></path>
                    </svg>
                </div>
            </div>
        `;

        // Click handler → open PhotoLightbox with gallery
        thumbnail.addEventListener('click', () => {
            this.openLightbox(post, index);
        });

        return thumbnail;
    }

    /**
     * Open PhotoLightbox with gallery navigation enabled
     * @param {Object} post - Post to display
     * @param {number} index - Index in posts array
     */
    openLightbox(post, index) {
        if (!window.photoLightbox) {
            return;
        }

        const photoUrl = this.getFirstPhotoUrl(post.photo_urls);

        // Setup gallery for navigation (all posts with photos)
        this.setupGalleryNavigation(index);

        // Open lightbox with full post data (no API call needed)
        // Use PhotoLightbox's built-in SVG placeholder for audio-only posts
        window.photoLightbox.openWithPostData(
            photoUrl || window.photoLightbox.getAudioPlaceholderDataUri(),
            post.id,
            this.normalizePostForLightbox(post)
        );
    }

    /**
     * Setup gallery navigation in PhotoLightbox
     * @param {number} currentIndex - Current post index
     */
    setupGalleryNavigation(currentIndex) {
        if (!window.photoLightbox) return;

        // Build gallery array with photo URLs and post IDs
        const galleryPosts = this.posts
            .map((post, idx) => {
                const photoUrl = this.getFirstPhotoUrl(post.photo_urls);
                return {
                    url: photoUrl ? photoUrl.replace('/storage/uploads/', '') : null,
                    post_id: post.id,
                    _fullPost: post, // Store full post for local access
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

        // Override showPhoto temporarily to use local post data
        this.overrideShowPhoto();
    }

    /**
     * Override PhotoLightbox's showPhoto to use local post data
     * Prevents unnecessary API calls when navigating
     */
    overrideShowPhoto() {
        if (!window.photoLightbox || window.photoLightbox._profileOverrideActive) return;

        const originalShowPhoto = window.photoLightbox.showPhoto.bind(window.photoLightbox);
        const self = this;

        window.photoLightbox.showPhoto = function(index) {
            if (index < 0 || index >= this.gallery.length) return;

            this.currentIndex = index;
            const photo = this.gallery[index];

            // Show loading spinner
            if (this.loadingSpinner) {
                this.loadingSpinner.classList.remove('hidden');
            }
            this.imageElement.style.opacity = '0';

            // Set photo URL - handle storage, asset paths, and data URIs
            const fullUrl = (photo.url.startsWith('/storage/') || photo.url.startsWith('/assets/') || photo.url.startsWith('data:'))
                ? photo.url
                : `/storage/uploads/${photo.url}`;
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

            // Use local post data if available (from profile gallery)
            if (photo._fullPost) {
                const normalizedPost = self.normalizePostForLightbox(photo._fullPost);
                this.renderFullPostInfo(normalizedPost);
                this.loadCommentsOnly(photo.post_id); // Comments still from API
            } else {
                // Fallback to API call
                this.loadPostData(photo.post_id);
            }
        };

        window.photoLightbox._profileOverrideActive = true;

        // Restore original on lightbox close
        const originalClose = window.photoLightbox.close.bind(window.photoLightbox);
        window.photoLightbox.close = function() {
            window.photoLightbox.showPhoto = originalShowPhoto;
            window.photoLightbox._profileOverrideActive = false;
            window.photoLightbox.close = originalClose;
            originalClose();
        };
    }

    /**
     * Normalize post data for PhotoLightbox.renderFullPostInfo
     *
     * ENTERPRISE V4.1 (2025-12-08): Convert top_emotions array to reaction_stats object
     * Feed uses reaction_stats: {emotion_id: count} - PhotoLightbox expects this format
     * Profile uses reactions.top_emotions: [{emotion_id, icon, count}, ...] - needs conversion
     *
     * @param {Object} post - Raw post data from profile
     * @returns {Object} Normalized post for lightbox
     */
    normalizePostForLightbox(post) {
        // ENTERPRISE: Convert top_emotions array to reaction_stats object format
        // PhotoLightbox.renderReactions() expects {emotion_id: count}
        let reactionStats = {};

        if (post.reaction_stats && typeof post.reaction_stats === 'object' && !Array.isArray(post.reaction_stats)) {
            // Already in correct format (from Feed)
            reactionStats = post.reaction_stats;
        } else if (post.reactions?.top_emotions && Array.isArray(post.reactions.top_emotions)) {
            // Convert top_emotions array to object format
            // [{emotion_id: 1, icon: '😊', count: 5}] → {1: 5}
            post.reactions.top_emotions.forEach(reaction => {
                const emotionId = parseInt(reaction.emotion_id, 10);
                const count = parseInt(reaction.count, 10) || 0;
                if (emotionId && count > 0) {
                    reactionStats[emotionId] = count;
                }
            });
        }

        return {
            id: post.id,
            audio_title: post.audio_title || post.title || 'Senza titolo',
            audio_description: post.audio_description || post.content || '',
            content: post.content || '',
            created_at: post.created_at,
            emotion: post.emotion || null,
            reaction_stats: reactionStats,
            comment_count: post.comment_count || 0,
            listen_count: post.play_count || 0,
            tagged_users: post.tagged_users || [],
            photo_urls: post.photo_urls
        };
    }

    /**
     * Get top N reactions sorted by count
     *
     * ENTERPRISE V4.1 (2025-12-08): Handle BOTH data formats:
     * - Object format: {emotion_id: count} (legacy)
     * - Array format: [{emotion_id, icon, count}, ...] (top_emotions from ReactionStatsService)
     *
     * @param {Object|Array} reactionStats - Reactions in either format
     * @param {number} limit - Max reactions to return
     * @returns {Array} Array of {id, count, icon}
     */
    getTopReactions(reactionStats, limit = 3) {
        if (!reactionStats) return [];

        let reactionsArray = [];

        // ENTERPRISE: Handle ARRAY format (top_emotions from backend)
        // Format: [{emotion_id: 1, icon: '😊', count: 5}, ...]
        if (Array.isArray(reactionStats)) {
            reactionsArray = reactionStats
                .map(r => ({
                    id: parseInt(r.emotion_id, 10),
                    count: parseInt(r.count, 10) || 0,
                    icon: r.icon || this.emotions[r.emotion_id]?.icon || ''
                }))
                .filter(r => r.count > 0);
        }
        // ENTERPRISE: Handle OBJECT format (legacy {emotion_id: count})
        else if (typeof reactionStats === 'object') {
            reactionsArray = Object.entries(reactionStats)
                .map(([id, count]) => ({
                    id: parseInt(id, 10),
                    count: parseInt(count, 10) || 0,
                    icon: this.emotions[parseInt(id, 10)]?.icon || ''
                }))
                .filter(r => r.count > 0);
        }

        return reactionsArray
            .sort((a, b) => b.count - a.count)
            .slice(0, limit);
    }

    /**
     * Get first photo URL from photo_urls field
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

    /**
     * Format number (1234 → 1.2k)
     */
    formatNumber(num) {
        if (num >= 1000000) {
            return (num / 1000000).toFixed(1) + 'M';
        }
        if (num >= 1000) {
            return (num / 1000).toFixed(1) + 'k';
        }
        return num.toString();
    }

    /**
     * Escape HTML (XSS protection)
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Auto-initialize
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        if (document.getElementById('userPostsContainer')) {
            window.profileAudioPosts = new ProfileAudioPosts();
        }
    });
} else {
    if (document.getElementById('userPostsContainer')) {
        window.profileAudioPosts = new ProfileAudioPosts();
    }
}
