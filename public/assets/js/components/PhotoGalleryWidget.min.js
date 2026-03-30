/**
 * PhotoGalleryWidget - Sidebar Photo Gallery Widget
 *
 * Features:
 * - Shows last 5 uploaded photos with thumbnails
 * - Click to open PhotoLightbox
 * - Real-time updates when new photos uploaded
 * - Lazy loading thumbnails
 * - Performance: <50ms render time
 *
 * @package need2talk/Lightning
 * @version 1.0.0
 */

class PhotoGalleryWidget {
    constructor() {
        this.photos = [];
        this.container = null;
        this.isLoading = false;

        this.init();
    }

    /**
     * Initialize widget
     */
    async init() {
        this.createDOM();
        await this.loadPhotos();
    }

    /**
     * Create widget DOM structure
     */
    createDOM() {
        // Find container for photo gallery widget
        // Try sidebar first (dashboard layout), then main content container (profile page)
        let container = document.querySelector('main aside > div');

        if (!container) {
            // Profile page: body > main > main > div.bg-gray-800/50...
            container = document.querySelector('body > main > main > div.bg-gray-800\\/50.backdrop-blur-sm.rounded-xl.p-6.border.border-gray-700\\/50 > div');
        }

        if (!container) {
            // Silently skip if no suitable container found
            return;
        }

        // Create widget container
        this.container = document.createElement('div');
        this.container.className = 'bg-gray-800/50 backdrop-blur-sm rounded-xl p-6 border border-gray-700/50 mt-6';
        this.container.innerHTML = `
            <h3 class="text-lg font-bold text-white mb-4 flex items-center">
                <svg class="w-5 h-5 mr-2 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                Galleria Foto
            </h3>
            <div id="photoGalleryGrid" class="grid grid-cols-2 gap-2">
                <!-- Photos rendered here -->
            </div>
            <div id="photoGalleryLoading" class="text-center py-8 hidden">
                <div class="w-8 h-8 border-4 border-purple-500 border-t-transparent rounded-full animate-spin mx-auto"></div>
            </div>
            <div id="photoGalleryEmpty" class="text-center py-8 text-gray-400 text-sm hidden">
                <svg class="w-12 h-12 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                Nessuna foto ancora
            </div>
        `;

        container.appendChild(this.container);

        // Attach click handler for photo thumbnails
        this.container.addEventListener('click', async (e) => {
            const photoItem = e.target.closest('.photo-gallery-item');
            if (photoItem && window.photoLightbox) {
                const photoUrl = photoItem.dataset.photoUrl;
                const postId = parseInt(photoItem.dataset.postId, 10);
                if (photoUrl && postId) {
                    // Fetch full post data to show reactions, comments, counters
                    try {
                        // ENTERPRISE V8.2: GET doesn't need CSRF
                        const response = await fetch(`/api/audio/${postId}`, {
                            method: 'GET',
                            headers: { 'Content-Type': 'application/json' },
                            credentials: 'include'
                        });

                        if (response.ok) {
                            const data = await response.json();
                            if (data.success && data.post) {
                                // Use openWithPostData like FeedManager does
                                window.photoLightbox.openWithPostData(photoUrl, postId, data.post);
                            } else {
                                // Fallback to simple open
                                window.photoLightbox.open(photoUrl, postId);
                            }
                        } else {
                            window.photoLightbox.open(photoUrl, postId);
                        }
                    } catch (error) {
                        console.error('PhotoGalleryWidget: Failed to fetch post data', error);
                        window.photoLightbox.open(photoUrl, postId);
                    }
                }
            }
        });
    }

    /**
     * Load recent photos from API
     */
    async loadPhotos() {
        if (this.isLoading) return;
        this.isLoading = true;

        const grid = document.getElementById('photoGalleryGrid');
        const loading = document.getElementById('photoGalleryLoading');
        const empty = document.getElementById('photoGalleryEmpty');

        if (!grid) return;

        // Show loading
        loading?.classList.remove('hidden');
        grid.innerHTML = '';
        empty?.classList.add('hidden');

        try {
            // ENTERPRISE V8.2: GET doesn't need CSRF
            const response = await fetch('/api/audio/photos/recent?limit=5', {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.success && data.photos && data.photos.length > 0) {
                this.photos = data.photos;
                this.renderPhotos();
            } else {
                // No photos found
                empty?.classList.remove('hidden');
            }

        } catch (error) {
            console.error('PhotoGalleryWidget: Failed to load photos', error);
            // Show empty state on error
            empty?.classList.remove('hidden');
        } finally {
            loading?.classList.add('hidden');
            this.isLoading = false;
        }
    }

    /**
     * Render photos in grid
     */
    renderPhotos() {
        const grid = document.getElementById('photoGalleryGrid');
        if (!grid) return;

        grid.innerHTML = this.photos.map(photo => {
            // Ensure full path for lightbox (photo.url may be relative)
            const fullPhotoUrl = photo.url.startsWith('/') ? photo.url : `/storage/uploads/${photo.url}`;
            const fullThumbnail = photo.thumbnail.startsWith('/') ? photo.thumbnail : `/storage/uploads/${photo.thumbnail}`;

            return `
            <div class="photo-gallery-item aspect-square rounded-lg overflow-hidden border border-gray-700 cursor-pointer hover:border-purple-500/50 transition-colors group"
                 data-photo-url="${this.escapeHtml(fullPhotoUrl)}"
                 data-post-id="${photo.post_id}">
                <img src="${this.escapeHtml(fullThumbnail)}"
                     alt="${this.escapeHtml(photo.title || 'Photo')}"
                     class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-300"
                     loading="lazy"
                     onerror="this.parentElement.style.display='none'">
            </div>
        `}).join('');
    }

    /**
     * Refresh photos (called after new upload)
     */
    async refresh() {
        await this.loadPhotos();
    }

    /**
     * HTML escape helper
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Auto-initialize on DOM ready
// ENTERPRISE FIX: Only initialize on own profile or non-profile pages
// Do NOT show current user's photos on other users' profiles!
// ENTERPRISE FIX 2025-12-02: Skip on chat pages (they have their own sidebar)
function shouldInitPhotoGallery() {
    // Skip on other user's profile
    if (typeof window.isOwnProfile !== 'undefined' && window.isOwnProfile === false) {
        return false;
    }
    // Skip on chat pages
    if (window.location.pathname.startsWith('/chat')) {
        return false;
    }
    return true;
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        if (shouldInitPhotoGallery()) {
            window.photoGalleryWidget = new PhotoGalleryWidget();
        }
    });
} else {
    if (shouldInitPhotoGallery()) {
        window.photoGalleryWidget = new PhotoGalleryWidget();
    }
}

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PhotoGalleryWidget;
}
