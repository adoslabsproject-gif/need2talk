/**
 * WebM Duration Fix - need2talk Enterprise Galaxy
 *
 * Fixes missing duration metadata in WebM files created by MediaRecorder.
 * Chrome's MediaRecorder creates WebM files without duration in the header
 * (because duration is unknown at recording start). This causes:
 * - audio.duration returning Infinity or NaN
 * - Seeking not working properly
 * - Some browsers refusing to play the file
 *
 * This utility injects the correct duration into the WebM EBML structure.
 *
 * Based on WebM/EBML spec: https://www.matroska.org/technical/elements.html
 * Inspired by fix-webm-duration but rewritten for enterprise use.
 *
 * @author Claude Code (AI-Orchestrated Development)
 * @version 1.0.0 Enterprise Galaxy
 * @license MIT
 */

class WebMDurationFix {
    /**
     * EBML Element IDs (as hex bytes)
     * WebM uses variable-length integer encoding (VINT)
     */
    static EBML_IDS = {
        EBML: 0x1A45DFA3,
        Segment: 0x18538067,
        Info: 0x1549A966,
        Duration: 0x4489,
        TimestampScale: 0x2AD7B1,
        Cluster: 0x1F43B675,
        Timestamp: 0xE7,
        SimpleBlock: 0xA3,
        BlockGroup: 0xA0
    };

    /**
     * Fix duration in a WebM blob
     *
     * @param {Blob} blob - Original WebM blob from MediaRecorder
     * @param {number} duration - Duration in seconds
     * @param {Function} [callback] - Optional callback(fixedBlob) - if not provided, returns Promise
     * @returns {Promise<Blob>} Fixed WebM blob with duration metadata
     */
    static async fix(blob, duration, callback) {
        try {
            const arrayBuffer = await blob.arrayBuffer();
            const fixedBuffer = this.injectDuration(arrayBuffer, duration);
            const fixedBlob = new Blob([fixedBuffer], { type: blob.type || 'audio/webm' });

            if (callback && typeof callback === 'function') {
                callback(fixedBlob);
                return fixedBlob;
            }

            return fixedBlob;
        } catch (error) {
            console.error('[WebMDurationFix] Failed to fix duration:', error);
            // Return original blob on error (graceful degradation)
            if (callback && typeof callback === 'function') {
                callback(blob);
            }
            return blob;
        }
    }

    /**
     * Inject duration into WebM ArrayBuffer
     *
     * @param {ArrayBuffer} buffer - Original WebM data
     * @param {number} durationSec - Duration in seconds
     * @returns {ArrayBuffer} Fixed WebM data
     */
    static injectDuration(buffer, durationSec) {
        const data = new Uint8Array(buffer);

        // Find the Info element in the Segment
        const infoPosition = this.findElement(data, this.EBML_IDS.Info);
        if (infoPosition === -1) {
            console.warn('[WebMDurationFix] Info element not found, returning original');
            return buffer;
        }

        // Find TimestampScale to calculate duration in WebM units
        // Default is 1000000 (1ms precision)
        let timestampScale = 1000000;
        const scalePos = this.findElement(data, this.EBML_IDS.TimestampScale, infoPosition);
        if (scalePos !== -1) {
            const scaleData = this.readElementData(data, scalePos);
            if (scaleData) {
                timestampScale = this.readVINTValue(scaleData);
            }
        }

        // Calculate duration in WebM units (nanoseconds / timestampScale)
        // Duration element stores value as float64
        const durationNs = durationSec * 1000000000; // seconds to nanoseconds
        const durationWebM = durationNs / timestampScale;

        // Check if Duration element already exists
        const durationPos = this.findElement(data, this.EBML_IDS.Duration, infoPosition);

        if (durationPos !== -1) {
            // Duration exists - update it in place if possible
            return this.updateDuration(data, durationPos, durationWebM);
        } else {
            // Duration doesn't exist - inject it after Info header
            return this.insertDuration(data, infoPosition, durationWebM);
        }
    }

    /**
     * Find an EBML element by ID
     *
     * @param {Uint8Array} data - WebM data
     * @param {number} elementId - Element ID to find
     * @param {number} [startOffset=0] - Start searching from this offset
     * @returns {number} Position of element or -1 if not found
     */
    static findElement(data, elementId, startOffset = 0) {
        // Convert element ID to bytes
        const idBytes = this.intToBytes(elementId);
        const idLen = idBytes.length;

        for (let i = startOffset; i < data.length - idLen; i++) {
            let match = true;
            for (let j = 0; j < idLen; j++) {
                if (data[i + j] !== idBytes[j]) {
                    match = false;
                    break;
                }
            }
            if (match) {
                return i;
            }
        }

        return -1;
    }

    /**
     * Read element data after ID and size
     *
     * @param {Uint8Array} data - WebM data
     * @param {number} elementPos - Position of element ID
     * @returns {Uint8Array|null} Element data or null
     */
    static readElementData(data, elementPos) {
        try {
            // Skip element ID (variable length)
            let pos = elementPos;
            const idLen = this.getVINTLength(data[pos]);
            pos += idLen;

            // Read size (VINT)
            const sizeLen = this.getVINTLength(data[pos]);
            const size = this.readVINT(data, pos);
            pos += sizeLen;

            // Return data
            return data.slice(pos, pos + size);
        } catch (e) {
            return null;
        }
    }

    /**
     * Update existing Duration element
     *
     * @param {Uint8Array} data - Original data
     * @param {number} durationPos - Position of Duration element
     * @param {number} durationValue - New duration value
     * @returns {ArrayBuffer} Updated data
     */
    static updateDuration(data, durationPos, durationValue) {
        const result = new Uint8Array(data);

        // Skip Duration ID (2 bytes for 0x4489)
        let pos = durationPos + 2;

        // Read size to know how many bytes for the float
        const sizeLen = this.getVINTLength(result[pos]);
        const size = this.readVINT(result, pos);
        pos += sizeLen;

        // Write float64 duration (8 bytes)
        if (size === 8) {
            const floatBytes = this.floatToBytes(durationValue);
            for (let i = 0; i < 8; i++) {
                result[pos + i] = floatBytes[i];
            }
        } else if (size === 4) {
            // float32
            const floatBytes = this.float32ToBytes(durationValue);
            for (let i = 0; i < 4; i++) {
                result[pos + i] = floatBytes[i];
            }
        }

        return result.buffer;
    }

    /**
     * Insert Duration element into Info section
     *
     * @param {Uint8Array} data - Original data
     * @param {number} infoPos - Position of Info element
     * @param {number} durationValue - Duration value
     * @returns {ArrayBuffer} Data with Duration inserted
     */
    static insertDuration(data, infoPos, durationValue) {
        // Skip Info ID
        let pos = infoPos;
        const idLen = this.getVINTLength(data[pos]);
        pos += idLen;

        // Read Info size
        const sizePos = pos;
        const sizeLen = this.getVINTLength(data[pos]);
        const infoSize = this.readVINT(data, pos);
        pos += sizeLen;

        // Create Duration element (ID + size + float64)
        // Duration ID: 0x4489 (2 bytes)
        // Size: 0x88 (1 byte = 8, VINT encoded)
        // Value: 8 bytes float64
        const durationElement = new Uint8Array(11);
        durationElement[0] = 0x44; // Duration ID high byte
        durationElement[1] = 0x89; // Duration ID low byte
        durationElement[2] = 0x88; // Size = 8 (VINT: 0x80 | 8)

        const floatBytes = this.floatToBytes(durationValue);
        for (let i = 0; i < 8; i++) {
            durationElement[3 + i] = floatBytes[i];
        }

        // Create new buffer with Duration inserted after Info header
        const insertPos = pos; // Right after Info size
        const newSize = data.length + durationElement.length;
        const result = new Uint8Array(newSize);

        // Copy data before insert position
        result.set(data.slice(0, insertPos), 0);

        // Insert Duration element
        result.set(durationElement, insertPos);

        // Copy rest of data
        result.set(data.slice(insertPos), insertPos + durationElement.length);

        // Update Info element size
        const newInfoSize = infoSize + durationElement.length;
        this.writeVINT(result, sizePos, newInfoSize, sizeLen);

        // Also need to update Segment size if it's not "unknown"
        const segmentPos = this.findElement(result, this.EBML_IDS.Segment);
        if (segmentPos !== -1) {
            const segIdLen = this.getVINTLength(result[segmentPos]);
            const segSizePos = segmentPos + segIdLen;
            const segSizeLen = this.getVINTLength(result[segSizePos]);
            const segSize = this.readVINT(result, segSizePos);

            // Only update if size is not "unknown" (0x01FFFFFFFFFFFFFF)
            if (segSize < 0x00FFFFFFFFFFFFFF) {
                const newSegSize = segSize + durationElement.length;
                this.writeVINT(result, segSizePos, newSegSize, segSizeLen);
            }
        }

        return result.buffer;
    }

    /**
     * Convert integer to big-endian bytes (removing leading zeros)
     */
    static intToBytes(value) {
        const bytes = [];
        while (value > 0) {
            bytes.unshift(value & 0xFF);
            value = Math.floor(value / 256);
        }
        return bytes.length > 0 ? bytes : [0];
    }

    /**
     * Get VINT (Variable Integer) length from first byte
     */
    static getVINTLength(firstByte) {
        for (let i = 0; i < 8; i++) {
            if (firstByte & (0x80 >> i)) {
                return i + 1;
            }
        }
        return 1;
    }

    /**
     * Read VINT value
     */
    static readVINT(data, pos) {
        const len = this.getVINTLength(data[pos]);
        let value = data[pos] & (0xFF >> len);

        for (let i = 1; i < len; i++) {
            value = value * 256 + data[pos + i];
        }

        return value;
    }

    /**
     * Read VINT value from Uint8Array
     */
    static readVINTValue(data) {
        let value = 0;
        for (let i = 0; i < data.length; i++) {
            value = value * 256 + data[i];
        }
        return value;
    }

    /**
     * Write VINT value
     */
    static writeVINT(data, pos, value, length) {
        // Write value in big-endian, with VINT marker in first byte
        const bytes = [];
        let v = value;
        for (let i = 0; i < length; i++) {
            bytes.unshift(v & 0xFF);
            v = Math.floor(v / 256);
        }

        // Add VINT marker to first byte
        bytes[0] |= (0x80 >> (length - 1));

        for (let i = 0; i < length; i++) {
            data[pos + i] = bytes[i];
        }
    }

    /**
     * Convert float64 to big-endian bytes
     */
    static floatToBytes(value) {
        const buffer = new ArrayBuffer(8);
        const view = new DataView(buffer);
        view.setFloat64(0, value, false); // big-endian
        return new Uint8Array(buffer);
    }

    /**
     * Convert float32 to big-endian bytes
     */
    static float32ToBytes(value) {
        const buffer = new ArrayBuffer(4);
        const view = new DataView(buffer);
        view.setFloat32(0, value, false); // big-endian
        return new Uint8Array(buffer);
    }
}

// Export for use
if (typeof module !== 'undefined' && module.exports) {
    module.exports = WebMDurationFix;
}

// Also expose globally
window.WebMDurationFix = WebMDurationFix;
