/**
 * ChatEncryptionService.js - Enterprise E2E Encryption
 *
 * Provides end-to-end encryption for direct messages using:
 * - ECDH P-256 for key exchange
 * - AES-256-GCM for message encryption
 * - HKDF for key derivation
 *
 * SECURITY ARCHITECTURE:
 * 1. User generates ECDH key pair on first use
 * 2. Public key is stored on server
 * 3. Private key stays in browser (IndexedDB)
 * 4. Conversation key derived via ECDH + HKDF
 * 5. Messages encrypted client-side before sending
 * 6. Escrow key encrypted with server public key for moderation
 *
 * IMPORTANT: This is hybrid E2E - server can decrypt if escrow is released
 * for moderation purposes. Full E2E would prevent all moderation.
 *
 * @package Need2Talk
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 * @version 1.0.0
 */

class ChatEncryptionService {
    static DB_NAME = 'need2talk_chat_crypto';
    static DB_VERSION = 1;
    static STORE_NAME = 'keys';

    #db = null;
    #keyPair = null;
    #conversationKeys = new Map();  // conversationUuid -> {aesKey, otherKeyHash}
    #initialized = false;
    #initPromise = null;  // ENTERPRISE V10.179: Promise lock for concurrent init calls
    #userUuid = null;  // ENTERPRISE V10.185: User-specific key isolation
    #isNewDevice = false;  // ENTERPRISE V11.9: Track if key was just generated (new device)

    constructor() {
        // Check for Web Crypto API support
        if (!window.crypto || !window.crypto.subtle) {
            throw new Error('Web Crypto API not supported');
        }
    }

    /**
     * ENTERPRISE V10.185: Get the key storage ID for current user
     * Each user has their own key pair in IndexedDB, preventing cross-user key leaks
     * @returns {string}
     */
    get #keyStorageId() {
        if (!this.#userUuid) {
            throw new Error('User UUID not set - encryption service not properly initialized');
        }
        return `user_key_pair_${this.#userUuid}`;
    }

    /**
     * Initialize encryption service
     * ENTERPRISE V10.179: Uses promise lock to prevent race conditions
     * @returns {Promise<boolean>}
     */
    async initialize() {
        // Already initialized - fast path
        if (this.#initialized) return true;

        // Initialization in progress - wait for existing promise
        if (this.#initPromise) {
            return this.#initPromise;
        }

        // Start new initialization and store promise
        this.#initPromise = this.#doInitialize();
        return this.#initPromise;
    }

    /**
     * Actual initialization logic (called only once)
     * @private
     */
    async #doInitialize() {
        try {
            // ENTERPRISE V10.185: Get user UUID for per-user key isolation
            // Wait up to 5 seconds for user data to be available
            this.#userUuid = await this.#waitForUserUuid(5000);
            if (!this.#userUuid) {
                throw new Error('User UUID not available - user may not be logged in');
            }
            console.log('[ChatEncryption] Initializing for user:', this.#userUuid.substring(0, 8) + '...');

            // Open IndexedDB
            this.#db = await this.#openDatabase();

            // Load or generate key pair for THIS USER specifically
            this.#keyPair = await this.#loadKeyPair();

            if (!this.#keyPair) {
                // ENTERPRISE V11.9: New device - generating fresh keys
                // Old messages encrypted with previous key won't be decryptable
                this.#isNewDevice = true;
                console.log('[ChatEncryption] New device detected - generating fresh key pair');

                // Clear any stale conversation key cache
                this.#conversationKeys.clear();

                this.#keyPair = await this.#generateKeyPair();
                await this.#saveKeyPair(this.#keyPair);

                // Upload public key to server
                await this.#uploadPublicKey();
            } else {
                this.#isNewDevice = false;
                // ENTERPRISE V10.183: Verify browser key matches server key
                // This prevents desync issues from manual key manipulation
                await this.#verifyKeySync();
            }

            this.#initialized = true;
            return true;

        } catch (error) {
            console.error('[ChatEncryption] Initialization failed:', error);
            this.#initPromise = null;  // Allow retry on failure
            return false;
        }
    }

    /**
     * ENTERPRISE V10.185: Wait for user UUID to be available
     * The window.need2talk object is set by the layout after login
     * @param {number} timeout - Maximum time to wait in ms
     * @returns {Promise<string|null>}
     */
    async #waitForUserUuid(timeout = 5000) {
        // Check if already available
        const uuid = window.need2talk?.user?.uuid;
        if (uuid) {
            return uuid;
        }

        // Wait with polling
        return new Promise((resolve) => {
            const startTime = Date.now();
            const interval = setInterval(() => {
                const uuid = window.need2talk?.user?.uuid;
                if (uuid) {
                    clearInterval(interval);
                    resolve(uuid);
                } else if (Date.now() - startTime > timeout) {
                    clearInterval(interval);
                    console.warn('[ChatEncryption] Timeout waiting for user UUID');
                    resolve(null);
                }
            }, 100);
        });
    }

    /**
     * Open IndexedDB database
     * @returns {Promise<IDBDatabase>}
     */
    #openDatabase() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(ChatEncryptionService.DB_NAME, ChatEncryptionService.DB_VERSION);

            request.onerror = () => reject(request.error);
            request.onsuccess = () => resolve(request.result);

            request.onupgradeneeded = (event) => {
                const db = event.target.result;

                if (!db.objectStoreNames.contains(ChatEncryptionService.STORE_NAME)) {
                    db.createObjectStore(ChatEncryptionService.STORE_NAME, { keyPath: 'id' });
                }
            };
        });
    }

    /**
     * Generate ECDH key pair
     * @returns {Promise<CryptoKeyPair>}
     */
    async #generateKeyPair() {
        return crypto.subtle.generateKey(
            {
                name: 'ECDH',
                namedCurve: 'P-256'
            },
            true,  // extractable (for export)
            ['deriveKey', 'deriveBits']
        );
    }

    /**
     * Load key pair from IndexedDB
     * ENTERPRISE V10.185: Now uses user-specific key ID
     * @returns {Promise<CryptoKeyPair|null>}
     */
    async #loadKeyPair() {
        return new Promise((resolve, reject) => {
            const transaction = this.#db.transaction([ChatEncryptionService.STORE_NAME], 'readonly');
            const store = transaction.objectStore(ChatEncryptionService.STORE_NAME);
            // ENTERPRISE V10.185: Load key for THIS user specifically, not a global key
            const request = store.get(this.#keyStorageId);

            request.onerror = () => reject(request.error);
            request.onsuccess = async () => {
                const data = request.result;
                if (!data) {
                    resolve(null);
                    return;
                }

                try {
                    // Import private key
                    const privateKey = await crypto.subtle.importKey(
                        'jwk',
                        data.privateKey,
                        { name: 'ECDH', namedCurve: 'P-256' },
                        true,
                        ['deriveKey', 'deriveBits']
                    );

                    // Import public key
                    const publicKey = await crypto.subtle.importKey(
                        'jwk',
                        data.publicKey,
                        { name: 'ECDH', namedCurve: 'P-256' },
                        true,
                        []
                    );

                    resolve({ privateKey, publicKey });
                } catch (e) {
                    // Stored keys corrupted - will regenerate
                    resolve(null);
                }
            };
        });
    }

    /**
     * Save key pair to IndexedDB
     * ENTERPRISE V10.185: Now uses user-specific key ID
     * @param {CryptoKeyPair} keyPair
     * @returns {Promise<void>}
     */
    async #saveKeyPair(keyPair) {
        // Export keys to JWK format
        const privateKeyJwk = await crypto.subtle.exportKey('jwk', keyPair.privateKey);
        const publicKeyJwk = await crypto.subtle.exportKey('jwk', keyPair.publicKey);

        return new Promise((resolve, reject) => {
            const transaction = this.#db.transaction([ChatEncryptionService.STORE_NAME], 'readwrite');
            const store = transaction.objectStore(ChatEncryptionService.STORE_NAME);

            // ENTERPRISE V10.185: Save with user-specific ID to prevent cross-user key leaks
            const request = store.put({
                id: this.#keyStorageId,
                userUuid: this.#userUuid,  // Store for debugging/auditing
                privateKey: privateKeyJwk,
                publicKey: publicKeyJwk,
                createdAt: new Date().toISOString()
            });

            request.onerror = () => reject(request.error);
            request.onsuccess = () => resolve();
        });
    }

    /**
     * ENTERPRISE V10.183: Verify browser key is synced with server
     * Fixes desync issues from manual key manipulation or failed uploads
     * @returns {Promise<void>}
     */
    async #verifyKeySync() {
        try {
            // Get our public key hash
            const myPublicKeyJwk = await crypto.subtle.exportKey('jwk', this.#keyPair.publicKey);
            const myHash = await this.#hashJwk(myPublicKeyJwk);

            // Fetch what server thinks our key is
            const response = await fetch('/api/user/encryption-key', {
                credentials: 'same-origin'
            });

            if (!response.ok) return;

            const data = await response.json();
            const serverKeyJwk = data.data?.ecdh_public_key;

            if (!serverKeyJwk) {
                // Server has no key - upload ours
                await this.#uploadPublicKey();
                return;
            }

            // Parse server key and calculate hash
            const serverKey = typeof serverKeyJwk === 'string' ? JSON.parse(serverKeyJwk) : serverKeyJwk;
            const serverHash = await this.#hashJwk(serverKey);

            if (myHash !== serverHash) {
                // Keys don't match - re-upload browser key to server
                await this.#uploadPublicKey();
            }
        } catch (error) {
            // Non-critical - silently ignore sync failures
        }
    }

    /**
     * Upload public key to server
     * @returns {Promise<void>}
     */
    async #uploadPublicKey() {
        const publicKeyJwk = await crypto.subtle.exportKey('jwk', this.#keyPair.publicKey);

        // CSRF token is automatically added by csrf.js fetch wrapper - DO NOT add manually
        const response = await fetch('/api/user/encryption-key', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                public_key: JSON.stringify(publicKeyJwk)
            })
        });

        if (!response.ok) {
            throw new Error('Failed to upload public key');
        }
    }

    /**
     * Get or derive conversation key
     * ENTERPRISE V11.9: Now validates cached key against current other user's public key
     * If other user regenerated their key, cache is invalidated and key re-derived
     *
     * @param {string} conversationUuid
     * @param {string} otherUserPublicKeyJwk - JWK string of other user's public key
     * @param {boolean} forceRefresh - Force re-fetch of other user's key (for retry after decryption failure)
     * @returns {Promise<CryptoKey>}
     */
    async #getConversationKey(conversationUuid, otherUserPublicKeyJwk = null, forceRefresh = false) {
        // ENTERPRISE V11.9: Always fetch fresh key from server to detect key changes
        // This is critical for multi-device support
        let freshOtherKeyJwk = otherUserPublicKeyJwk;

        if (!freshOtherKeyJwk || forceRefresh) {
            // Fetch from server
            const response = await fetch(`/api/chat/dm/${conversationUuid}/key`, {
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error('Failed to get conversation key');
            }

            const data = await response.json();
            freshOtherKeyJwk = data.data?.other_user_public_key;
        }

        if (!freshOtherKeyJwk) {
            throw new Error('Other user has not set up encryption');
        }

        // Parse JWK if string
        const otherKeyJwk = typeof freshOtherKeyJwk === 'string'
            ? JSON.parse(freshOtherKeyJwk)
            : freshOtherKeyJwk;

        // ENTERPRISE V11.9: Calculate hash of BOTH users' public keys
        // Cache is only valid if BOTH keys match (handles multi-device key regeneration)
        const otherKeyHash = await this.#hashJwk(otherKeyJwk);
        const myPublicKeyJwk = await crypto.subtle.exportKey('jwk', this.#keyPair.publicKey);
        const myKeyHash = await this.#hashJwk(myPublicKeyJwk);

        // Check cache - verify BOTH key hashes match!
        const cached = this.#conversationKeys.get(conversationUuid);
        if (cached &&
            cached.otherKeyHash === otherKeyHash &&
            cached.myKeyHash === myKeyHash &&
            !forceRefresh) {
            // Cached key is still valid (neither user has changed their key)
            return cached.aesKey;
        }

        // Key changed or not cached - need to re-derive
        if (cached) {
            if (cached.otherKeyHash !== otherKeyHash) {
                console.log('[ChatEncryption] Other user key changed, re-deriving conversation key');
            }
            if (cached.myKeyHash !== myKeyHash) {
                console.log('[ChatEncryption] My key changed, re-deriving conversation key');
            }
        }

        // Import other user's public key
        const otherPublicKey = await crypto.subtle.importKey(
            'jwk',
            otherKeyJwk,
            { name: 'ECDH', namedCurve: 'P-256' },
            false,
            []
        );

        // Derive shared secret using ECDH
        const sharedBits = await crypto.subtle.deriveBits(
            {
                name: 'ECDH',
                public: otherPublicKey
            },
            this.#keyPair.privateKey,
            256
        );

        // Derive AES key using HKDF
        const sharedKeyMaterial = await crypto.subtle.importKey(
            'raw',
            sharedBits,
            { name: 'HKDF' },
            false,
            ['deriveKey']
        );

        // Use conversation UUID as salt for key derivation
        const salt = new TextEncoder().encode(conversationUuid);

        const aesKey = await crypto.subtle.deriveKey(
            {
                name: 'HKDF',
                hash: 'SHA-256',
                salt: salt,
                info: new TextEncoder().encode('need2talk-chat-e2e')
            },
            sharedKeyMaterial,
            { name: 'AES-GCM', length: 256 },
            false,
            ['encrypt', 'decrypt']
        );

        // Cache the key WITH both key hashes for validation
        this.#conversationKeys.set(conversationUuid, {
            aesKey,
            otherKeyHash,
            myKeyHash
        });

        return aesKey;
    }

    /**
     * ENTERPRISE V11.9: Invalidate cached conversation key
     * Call this when decryption fails to force re-derivation
     * @param {string} conversationUuid
     */
    invalidateConversationKey(conversationUuid) {
        if (this.#conversationKeys.has(conversationUuid)) {
            this.#conversationKeys.delete(conversationUuid);
            console.log('[ChatEncryption] Invalidated conversation key cache for:', conversationUuid);
        }
    }

    /**
     * Encrypt a message
     * @param {string} plaintext
     * @param {string} conversationUuid
     * @returns {Promise<{ciphertext: string, iv: string, tag: string}>}
     */
    async encryptMessage(plaintext, conversationUuid) {
        const key = await this.#getConversationKey(conversationUuid);

        // Generate random IV (12 bytes for GCM)
        const iv = crypto.getRandomValues(new Uint8Array(12));

        // Encode plaintext
        const encodedPlaintext = new TextEncoder().encode(plaintext);

        // Encrypt with AES-GCM
        const cipherBuffer = await crypto.subtle.encrypt(
            {
                name: 'AES-GCM',
                iv: iv,
                tagLength: 128  // 16 bytes auth tag
            },
            key,
            encodedPlaintext
        );

        // Split ciphertext and tag (GCM appends tag to ciphertext)
        const cipherArray = new Uint8Array(cipherBuffer);
        const ciphertext = cipherArray.slice(0, -16);
        const tag = cipherArray.slice(-16);

        return {
            ciphertext: this.#arrayToBase64(ciphertext),
            iv: this.#arrayToBase64(iv),
            tag: this.#arrayToBase64(tag)
        };
    }

    /**
     * Decrypt a message
     * @param {string} ciphertextBase64
     * @param {string} ivBase64
     * @param {string} tagBase64
     * @param {string} conversationUuid
     * @returns {Promise<string>}
     */
    async decryptMessage(ciphertextBase64, ivBase64, tagBase64, conversationUuid) {
        const key = await this.#getConversationKey(conversationUuid);

        const ciphertext = this.#base64ToArray(ciphertextBase64);
        const iv = this.#base64ToArray(ivBase64);
        const tag = this.#base64ToArray(tagBase64);

        // Combine ciphertext and tag (GCM expects them together)
        const cipherWithTag = new Uint8Array(ciphertext.length + tag.length);
        cipherWithTag.set(ciphertext, 0);
        cipherWithTag.set(tag, ciphertext.length);

        // Decrypt
        const plainBuffer = await crypto.subtle.decrypt(
            {
                name: 'AES-GCM',
                iv: iv,
                tagLength: 128
            },
            key,
            cipherWithTag
        );

        return new TextDecoder().decode(plainBuffer);
    }

    // ========================================================================
    // FILE ENCRYPTION (AUDIO) - TRUE E2E
    // ========================================================================

    /**
     * Encrypt a file (audio) for DM
     * Uses the same ECDH-derived conversation key as text messages
     *
     * TRUE E2E: Private key NEVER leaves the browser
     * Server sees only encrypted blob - cannot decrypt
     *
     * @param {Blob|File} file - File to encrypt
     * @param {string} conversationUuid - Conversation UUID for key derivation
     * @returns {Promise<{encryptedBlob: Blob, iv: string, tag: string}>}
     */
    async encryptFile(file, conversationUuid) {
        if (!file || !(file instanceof Blob)) {
            throw new Error('Invalid file: must be Blob or File');
        }

        const key = await this.#getConversationKey(conversationUuid);

        // Generate random IV (12 bytes for GCM)
        const iv = crypto.getRandomValues(new Uint8Array(12));

        // Read file as ArrayBuffer
        const fileBytes = await file.arrayBuffer();

        // Encrypt with AES-GCM
        const cipherBuffer = await crypto.subtle.encrypt(
            {
                name: 'AES-GCM',
                iv: iv,
                tagLength: 128  // 16 bytes auth tag
            },
            key,
            fileBytes
        );

        // GCM appends tag to ciphertext - split them
        const cipherArray = new Uint8Array(cipherBuffer);
        const ciphertext = cipherArray.slice(0, -16);
        const tag = cipherArray.slice(-16);

        // Create encrypted blob (application/octet-stream since it's encrypted)
        const encryptedBlob = new Blob([ciphertext], { type: 'application/octet-stream' });

        return {
            encryptedBlob,
            iv: this.#arrayToBase64(iv),
            tag: this.#arrayToBase64(tag)
        };
    }

    /**
     * Decrypt a file (audio) from DM
     * Uses the same ECDH-derived conversation key as text messages
     *
     * TRUE E2E: Decryption happens entirely client-side
     *
     * @param {Blob} encryptedBlob - Encrypted file blob
     * @param {string} ivBase64 - Base64 IV
     * @param {string} tagBase64 - Base64 auth tag
     * @param {string} conversationUuid - Conversation UUID for key derivation
     * @param {string} mimeType - Original MIME type (e.g., 'audio/webm')
     * @returns {Promise<Blob>} Decrypted file blob
     */
    async decryptFile(encryptedBlob, ivBase64, tagBase64, conversationUuid, mimeType = 'audio/webm') {
        if (!encryptedBlob || !(encryptedBlob instanceof Blob)) {
            throw new Error('Invalid encrypted blob');
        }

        if (!ivBase64 || !tagBase64) {
            throw new Error('IV and tag are required for decryption');
        }

        const key = await this.#getConversationKey(conversationUuid);

        const iv = this.#base64ToArray(ivBase64);
        const tag = this.#base64ToArray(tagBase64);

        // Read encrypted bytes
        const encryptedBytes = new Uint8Array(await encryptedBlob.arrayBuffer());

        // Combine ciphertext and tag (GCM expects them together)
        const cipherWithTag = new Uint8Array(encryptedBytes.length + tag.length);
        cipherWithTag.set(encryptedBytes, 0);
        cipherWithTag.set(tag, encryptedBytes.length);

        // Decrypt
        const plainBuffer = await crypto.subtle.decrypt(
            {
                name: 'AES-GCM',
                iv: iv,
                tagLength: 128
            },
            key,
            cipherWithTag
        );

        // Create decrypted blob with original MIME type
        return new Blob([plainBuffer], { type: mimeType });
    }

    /**
     * Export public key for sharing
     * @returns {Promise<string>}
     */
    async exportPublicKey() {
        if (!this.#keyPair) {
            throw new Error('Key pair not initialized');
        }

        const jwk = await crypto.subtle.exportKey('jwk', this.#keyPair.publicKey);
        return JSON.stringify(jwk);
    }

    /**
     * Get fingerprint of public key (for verification)
     * @returns {Promise<string>}
     */
    async getKeyFingerprint() {
        if (!this.#keyPair) {
            throw new Error('Key pair not initialized');
        }

        const publicKeyRaw = await crypto.subtle.exportKey('raw', this.#keyPair.publicKey);
        const hashBuffer = await crypto.subtle.digest('SHA-256', publicKeyRaw);
        const hashArray = new Uint8Array(hashBuffer);

        // Format as hex pairs separated by colons (like SSH fingerprint)
        return Array.from(hashArray.slice(0, 16))
            .map(b => b.toString(16).padStart(2, '0'))
            .join(':');
    }

    /**
     * Clear encryption data for current user (for logout/account deletion)
     * ENTERPRISE V10.185: Only clears THIS user's key, not other users' keys
     */
    async clearAll() {
        this.#conversationKeys.clear();
        this.#keyPair = null;

        if (this.#db && this.#userUuid) {
            return new Promise((resolve, reject) => {
                const transaction = this.#db.transaction([ChatEncryptionService.STORE_NAME], 'readwrite');
                const store = transaction.objectStore(ChatEncryptionService.STORE_NAME);
                // ENTERPRISE V10.185: Only delete current user's key, preserve other users' keys
                const request = store.delete(this.#keyStorageId);

                request.onerror = () => reject(request.error);
                request.onsuccess = () => resolve();
            });
        }
    }

    /**
     * Regenerate key pair (invalidates all existing conversations)
     */
    async regenerateKeys() {
        await this.clearAll();
        this.#keyPair = await this.#generateKeyPair();
        await this.#saveKeyPair(this.#keyPair);
        await this.#uploadPublicKey();
    }

    // ========================================================================
    // UTILITIES
    // ========================================================================

    /**
     * Convert Uint8Array to base64
     * @param {Uint8Array} array
     * @returns {string}
     */
    #arrayToBase64(array) {
        return btoa(String.fromCharCode.apply(null, array));
    }

    /**
     * Convert base64 to Uint8Array
     * @param {string} base64
     * @returns {Uint8Array}
     */
    #base64ToArray(base64) {
        const binary = atob(base64);
        const array = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
            array[i] = binary.charCodeAt(i);
        }
        return array;
    }

    /**
     * ENTERPRISE V10.181: Hash JWK for debugging/comparison
     * Creates short hash of public key for logging
     * @param {Object} jwk - JWK object
     * @returns {Promise<string>} Short hash (first 8 chars of SHA-256)
     */
    async #hashJwk(jwk) {
        const str = JSON.stringify({ x: jwk.x, y: jwk.y });  // Only public components
        const data = new TextEncoder().encode(str);
        const hashBuffer = await crypto.subtle.digest('SHA-256', data);
        const hashArray = new Uint8Array(hashBuffer);
        return Array.from(hashArray.slice(0, 4)).map(b => b.toString(16).padStart(2, '0')).join('');
    }

    /**
     * Check if encryption is supported
     * @returns {boolean}
     */
    static isSupported() {
        return !!(window.crypto && window.crypto.subtle && window.indexedDB);
    }

    // ========================================================================
    // GETTERS
    // ========================================================================

    get isInitialized() { return this.#initialized; }
    get hasKeyPair() { return !!this.#keyPair; }
    /** ENTERPRISE V10.185: Expose user UUID for debugging (truncated for privacy) */
    get userUuid() { return this.#userUuid ? this.#userUuid.substring(0, 8) + '...' : null; }
    /** ENTERPRISE V11.9: Check if this is a new device (key was just generated) */
    get isNewDevice() { return this.#isNewDevice; }
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ChatEncryptionService;
}

// Also make globally available
window.ChatEncryptionService = ChatEncryptionService;

// ENTERPRISE V10.179: Create singleton with awaitable promise
// This ensures ALL components can await initialization before use
// Usage: const encryption = await window.chatEncryptionReady;
window.chatEncryptionReady = (async function() {
    if (!window.chatEncryptionInstance) {
        window.chatEncryptionInstance = new ChatEncryptionService();
        try {
            await window.chatEncryptionInstance.initialize();
        } catch (err) {
            console.error('[ChatEncryption] Failed to initialize:', err);
            throw err;
        }
    }
    return window.chatEncryptionInstance;
})();

