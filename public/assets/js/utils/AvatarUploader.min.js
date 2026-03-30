/**
 * need2talk - AvatarUploader Utility
 * Enterprise Galaxy - Reusable avatar upload handler
 *
 * Purpose: Handle avatar uploads with preview, validation, progress tracking
 * Security: File type validation, size limits, XSS prevention
 * Performance: Image compression, WebP conversion (server-side via PhotoOptimizationService)
 */

/**
 * AvatarUploader Class
 * Reusable component for avatar upload with live preview
 */
class AvatarUploader {
    constructor(options = {}) {
        // Configuration
        this.inputElement = options.inputElement || null;
        this.previewElement = options.previewElement || null;
        this.uploadButton = options.uploadButton || null;
        this.endpoint = options.endpoint || '/settings/account/avatar';
        this.maxFileSize = options.maxFileSize || 2 * 1024 * 1024; // 2MB (avatar limit)
        this.allowedTypes = options.allowedTypes || ['image/jpeg', 'image/png', 'image/webp'];
        this.onSuccess = options.onSuccess || null;
        this.onError = options.onError || null;
        this.onProgress = options.onProgress || null;

        // State
        this.selectedFile = null;
        this.isUploading = false;

        // Initialize
        if (this.inputElement) {
            this.init();
        }
    }

    /**
     * Initialize uploader (bind events)
     */
    init() {
        if (!this.inputElement) {
            console.error('AvatarUploader: No input element provided');
            return;
        }

        // File input change event
        this.inputElement.addEventListener('change', (e) => {
            this.handleFileSelect(e);
        });

        // Upload button click (if provided)
        if (this.uploadButton) {
            this.uploadButton.addEventListener('click', (e) => {
                e.preventDefault();
                if (this.selectedFile) {
                    this.upload();
                } else {
                    this.inputElement.click(); // Open file picker
                }
            });
        }

        console.log('AvatarUploader: Initialized', {
            endpoint: this.endpoint,
            maxSize: this.maxFileSize,
            allowedTypes: this.allowedTypes
        });
    }

    /**
     * Handle file selection from input
     */
    handleFileSelect(event) {
        const file = event.target.files[0];

        if (!file) {
            console.warn('AvatarUploader: No file selected');
            return;
        }

        // Validate file
        const validation = this.validateFile(file);
        if (!validation.valid) {
            this.showError(validation.error);
            event.target.value = ''; // Reset input
            return;
        }

        // Store file
        this.selectedFile = file;

        // Show preview
        this.showPreview(file);

        // Auto-upload (optional)
        if (this.uploadButton) {
            // Wait for user to click upload button
            this.uploadButton.innerHTML = `
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <span>Salva Foto</span>
            `;
            this.uploadButton.disabled = false;
        } else {
            // Auto-upload immediately
            this.upload();
        }
    }

    /**
     * Validate file (type, size)
     */
    validateFile(file) {
        // Check file type
        if (!this.allowedTypes.includes(file.type)) {
            return {
                valid: false,
                error: `Invalid file type. Allowed: ${this.allowedTypes.join(', ')}`
            };
        }

        // Check file size
        if (file.size > this.maxFileSize) {
            const maxSizeMB = (this.maxFileSize / (1024 * 1024)).toFixed(1);
            return {
                valid: false,
                error: `File too large. Maximum size: ${maxSizeMB}MB`
            };
        }

        return { valid: true };
    }

    /**
     * Show image preview
     */
    showPreview(file) {
        if (!this.previewElement) {
            console.warn('AvatarUploader: No preview element provided');
            return;
        }

        // Use FileReader to load image
        const reader = new FileReader();

        reader.onload = (e) => {
            // Update preview image src
            this.previewElement.src = e.target.result;

            // Add fade-in animation
            this.previewElement.style.opacity = '0';
            setTimeout(() => {
                this.previewElement.style.transition = 'opacity 0.3s ease-in-out';
                this.previewElement.style.opacity = '1';
            }, 50);

            console.log('AvatarUploader: Preview updated');
        };

        reader.onerror = (error) => {
            console.error('AvatarUploader: Failed to read file', error);
            this.showError('Failed to load image preview');
        };

        reader.readAsDataURL(file);
    }

    /**
     * Upload avatar to server
     */
    async upload() {
        if (!this.selectedFile) {
            console.warn('AvatarUploader: No file selected');
            return;
        }

        if (this.isUploading) {
            console.warn('AvatarUploader: Upload already in progress');
            return;
        }

        this.isUploading = true;
        this.setUploadingState(true);

        // Create FormData
        const formData = new FormData();
        formData.append('avatar', this.selectedFile);

        try {
            console.log('AvatarUploader: Uploading to', this.endpoint);

            // Upload via ApiClient
            const response = await api.upload(this.endpoint, formData, {
                onProgress: this.onProgress
            });

            console.log('AvatarUploader: Upload successful', response);

            // Success callback
            if (this.onSuccess) {
                this.onSuccess(response);
            }

            // Show success notification
            if (window.showSuccess) {
                window.showSuccess(response.message || 'Avatar uploaded successfully!');
            }

            // Update preview with server-returned URL (if provided)
            if (response.avatar_url && this.previewElement) {
                this.previewElement.src = response.avatar_url;
            }

            // Update global user avatar (if exists)
            if (window.need2talk && window.need2talk.user && response.avatar_url) {
                window.need2talk.user.avatar = response.avatar_url;
            }

            // Reset state
            this.selectedFile = null;
            this.inputElement.value = '';

        } catch (error) {
            console.error('AvatarUploader: Upload failed', error);

            // Error callback
            if (this.onError) {
                this.onError(error);
            }

            // Show error notification
            this.showError(error.message || 'Failed to upload avatar. Please try again.');

        } finally {
            this.isUploading = false;
            this.setUploadingState(false);
        }
    }

    /**
     * Set uploading state (button text, spinner, etc.)
     */
    setUploadingState(uploading) {
        if (this.uploadButton) {
            if (uploading) {
                this.uploadButton.disabled = true;
                this.uploadButton.innerHTML = `
                    <svg class="animate-spin h-5 w-5 mr-2 inline-block" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Uploading...
                `;
            } else {
                this.uploadButton.disabled = false;
                this.uploadButton.innerHTML = `
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                    </svg>
                    <span>Seleziona Foto</span>
                `;
            }
        }

        // Disable file input during upload
        if (this.inputElement) {
            this.inputElement.disabled = uploading;
        }
    }

    /**
     * Show error message
     */
    showError(message) {
        if (window.showError) {
            window.showError(message);
        } else {
            alert(message); // Fallback
        }
    }

    /**
     * Destroy uploader (cleanup)
     */
    destroy() {
        if (this.inputElement) {
            this.inputElement.removeEventListener('change', this.handleFileSelect);
        }
        this.selectedFile = null;
        console.log('AvatarUploader: Destroyed');
    }
}

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { AvatarUploader };
}
