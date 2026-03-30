/**
 * Audio Recorder - Component per registrazione audio need2talk
 * Sistema unificato per registrazione, visualizzazione e upload
 */

class AudioRecorder {
    constructor() {
        this.isRecording = false;
        this.mediaRecorder = null;
        this.audioChunks = [];
        this.recordedAudioBlob = null;
        this.startTime = null;
        this.timerInterval = null;

        // ENTERPRISE FIX #7: Geolocation GDPR-compliant
        this.userLocation = null; // Cached location (only if user consented)

        // Elementi DOM
        this.recordButton = document.getElementById('recordButton');
        this.recordButtonText = document.getElementById('recordButtonText');
        this.recordingTimer = document.getElementById('recordingTimer');
        this.audioVisualizer = document.getElementById('audioVisualizer');
        this.visualizerCanvas = document.getElementById('visualizerCanvas');
        this.audioPlayback = document.getElementById('audioPlayback');
        this.recordedAudio = document.getElementById('recordedAudio');
        this.recordingDetails = document.getElementById('recordingDetails');

        // Bottoni controllo
        this.rerecordButton = document.getElementById('rerecordButton');
        this.continueButton = document.getElementById('continueButton');
        this.backButton = document.getElementById('backButton');
        this.submitButton = document.getElementById('submitButton');

        // Audio context per visualizzazione
        this.audioContext = null;
        this.analyser = null;
        this.microphone = null;
        this.dataArray = null;
        this.animationId = null;

        this.init();
    }
    
    /**
     * Inizializzazione
     */
    init() {
        this.bindEvents();
        this.checkMicrophonePermission();
    }
    
    /**
     * Bind eventi
     */
    bindEvents() {
        // Controlli registrazione
        this.recordButton?.addEventListener('click', () => this.toggleRecording());
        this.rerecordButton?.addEventListener('click', () => this.resetRecording());
        this.continueButton?.addEventListener('click', () => this.showRecordingDetails());
        this.backButton?.addEventListener('click', () => this.hideRecordingDetails());
        
        // Submit form
        document.getElementById('recordingForm')?.addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleSubmit();
        });
    }
    
    /**
     * Verifica permessi microfono
     */
    async checkMicrophonePermission() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    sampleRate: 48000,
                    channelCount: 1,
                    echoCancellation: true,
                    noiseSuppression: false,  // ENTERPRISE V11.8: Disabled - too aggressive
                    autoGainControl: true
                }
            });

            // Microfono disponibile
            Need2Talk.Logger.info('AudioRecorder', 'Microfono autorizzato');
            stream.getTracks().forEach(track => track.stop());
            
        } catch (error) {
            Need2Talk.Logger.error('AudioRecorder', 'Errore accesso microfono', error);
            this.showMicrophoneError(error.message);
        }
    }
    
    /**
     * Toggle registrazione
     */
    async toggleRecording() {
        if (this.isRecording) {
            this.stopRecording();
        } else {
            await this.startRecording();
        }
    }
    
    /**
     * Avvia registrazione
     */
    async startRecording() {
        try {
            // Verifica rate limit
            if (!await this.checkRateLimit()) {
                return;
            }
            
            // Richiede accesso microfono
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    sampleRate: 48000,
                    channelCount: 1,
                    echoCancellation: true,
                    noiseSuppression: false,  // ENTERPRISE V11.8: Disabled - too aggressive
                    autoGainControl: true
                }
            });
            
            // Setup MediaRecorder
            this.mediaRecorder = new MediaRecorder(stream, {
                mimeType: this.getSupportedMimeType()
            });
            
            this.audioChunks = [];
            
            // Eventi MediaRecorder
            this.mediaRecorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    this.audioChunks.push(event.data);
                }
            };
            
            this.mediaRecorder.onstop = () => {
                this.processRecordedAudio();
                stream.getTracks().forEach(track => track.stop());
            };
            
            // Setup visualizzatore
            await this.setupAudioVisualizer(stream);
            
            // Avvia registrazione
            this.mediaRecorder.start(100); // Chunk ogni 100ms
            this.isRecording = true;
            this.startTime = Date.now();
            
            // Aggiorna UI
            this.updateRecordingUI();
            this.startTimer();
            this.showVisualizer();
            
            Need2Talk.Logger.info('AudioRecorder', 'Registrazione avviata');
            
        } catch (error) {
            Need2Talk.Logger.error('AudioRecorder', 'Errore avvio registrazione', error);
            this.showRecordingError(error.message);
        }
    }
    
    /**
     * Stop registrazione
     */
    stopRecording() {
        if (!this.isRecording || !this.mediaRecorder) {
            return;
        }
        
        this.mediaRecorder.stop();
        this.isRecording = false;
        
        // Stop timer e visualizzatore
        this.stopTimer();
        this.stopVisualizer();
        
        // Aggiorna UI
        this.updateRecordingUI();
        
        Need2Talk.Logger.info('AudioRecorder', 'Registrazione fermata');
    }
    
    /**
     * Processa audio registrato
     */
    processRecordedAudio() {
        if (this.audioChunks.length === 0) {
            this.showRecordingError('Nessun dato audio registrato');
            return;
        }
        
        // Crea blob audio
        this.recordedAudioBlob = new Blob(this.audioChunks, { 
            type: this.getSupportedMimeType() 
        });
        
        // Crea URL per riproduzione
        const audioUrl = URL.createObjectURL(this.recordedAudioBlob);
        this.recordedAudio.src = audioUrl;
        
        // Mostra controlli playback
        this.showAudioPlayback();
        
        // Trigger evento per validazione form
        document.dispatchEvent(new CustomEvent('audioRecorded', {
            detail: { blob: this.recordedAudioBlob }
        }));
        
        // Rendi disponibile globalmente per validazione
        window.recordedAudioBlob = this.recordedAudioBlob;
        
        Need2Talk.Logger.info('AudioRecorder', `Audio processato: ${this.recordedAudioBlob.size} bytes`);
    }
    
    /**
     * Setup visualizzatore audio
     */
    async setupAudioVisualizer(stream) {
        try {
            this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
            this.microphone = this.audioContext.createMediaStreamSource(stream);
            this.analyser = this.audioContext.createAnalyser();
            
            this.analyser.fftSize = 256;
            const bufferLength = this.analyser.frequencyBinCount;
            this.dataArray = new Uint8Array(bufferLength);
            
            this.microphone.connect(this.analyser);
            
        } catch (error) {
            Need2Talk.Logger.error('AudioRecorder', 'Errore setup visualizzatore', error);
        }
    }
    
    /**
     * Mostra visualizzatore
     */
    showVisualizer() {
        if (!this.visualizerCanvas || !this.analyser) {
            return;
        }
        
        this.audioVisualizer.classList.remove('hidden');
        this.drawVisualizer();
    }
    
    /**
     * Disegna visualizzatore
     */
    drawVisualizer() {
        if (!this.isRecording) {
            return;
        }
        
        const canvas = this.visualizerCanvas;
        const ctx = canvas.getContext('2d');
        const width = canvas.width;
        const height = canvas.height;
        
        this.animationId = requestAnimationFrame(() => this.drawVisualizer());
        
        this.analyser.getByteFrequencyData(this.dataArray);
        
        // Pulisci canvas
        ctx.fillStyle = 'rgba(31, 41, 55, 0.3)';
        ctx.fillRect(0, 0, width, height);
        
        // Disegna barre
        const barWidth = (width / this.dataArray.length) * 2.5;
        let barHeight;
        let x = 0;
        
        const gradient = ctx.createLinearGradient(0, 0, 0, height);
        gradient.addColorStop(0, '#8B5CF6');
        gradient.addColorStop(1, '#EC4899');
        
        for (let i = 0; i < this.dataArray.length; i++) {
            barHeight = (this.dataArray[i] / 255) * height * 0.8;
            
            ctx.fillStyle = gradient;
            ctx.fillRect(x, height - barHeight, barWidth, barHeight);
            
            x += barWidth + 1;
        }
    }
    
    /**
     * Stop visualizzatore
     */
    stopVisualizer() {
        if (this.animationId) {
            cancelAnimationFrame(this.animationId);
        }
        
        if (this.audioContext) {
            this.audioContext.close();
            this.audioContext = null;
        }
        
        this.audioVisualizer?.classList.add('hidden');
    }
    
    /**
     * Timer registrazione
     */
    startTimer() {
        this.recordingTimer.classList.remove('opacity-0');
        
        this.timerInterval = setInterval(() => {
            const elapsed = Date.now() - this.startTime;
            const seconds = Math.floor(elapsed / 1000);
            const minutes = Math.floor(seconds / 60);
            
            const display = `${minutes.toString().padStart(2, '0')}:${(seconds % 60).toString().padStart(2, '0')}`;
            this.recordingTimer.textContent = display;
            
            // Max 3 minuti
            if (seconds >= 180) {
                this.stopRecording();
            }
            
        }, 100);
    }
    
    /**
     * Stop timer
     */
    stopTimer() {
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
            this.timerInterval = null;
        }
        
        this.recordingTimer.classList.add('opacity-0');
    }
    
    /**
     * Aggiorna UI registrazione
     */
    updateRecordingUI() {
        if (this.isRecording) {
            this.recordButton.classList.add('recording');
            this.recordButtonText.textContent = 'Ferma Registrazione';
        } else {
            this.recordButton.classList.remove('recording');
            this.recordButtonText.textContent = 'Inizia Registrazione';
        }
    }
    
    /**
     * Mostra audio playback
     */
    showAudioPlayback() {
        this.audioPlayback.classList.remove('hidden');
        document.getElementById('recordingControls').classList.add('hidden');
    }
    
    /**
     * Reset registrazione
     */
    resetRecording() {
        // Reset stato
        this.recordedAudioBlob = null;
        window.recordedAudioBlob = null;
        this.audioChunks = [];
        
        // Reset UI
        this.audioPlayback.classList.add('hidden');
        document.getElementById('recordingControls').classList.remove('hidden');
        this.hideRecordingDetails();
        
        // Reset timer
        this.recordingTimer.textContent = '00:00';
        
        Need2Talk.Logger.info('AudioRecorder', 'Registrazione resetata');
    }
    
    /**
     * Mostra dettagli registrazione
     */
    showRecordingDetails() {
        this.recordingDetails.classList.remove('hidden');
        this.recordingDetails.classList.add('show');
        
        // Scroll smooth ai dettagli
        this.recordingDetails.scrollIntoView({ behavior: 'smooth' });
    }
    
    /**
     * Nascondi dettagli registrazione
     */
    hideRecordingDetails() {
        this.recordingDetails.classList.add('hidden');
        this.recordingDetails.classList.remove('show');
    }

    /**
     * ENTERPRISE FIX #7: Request geolocation (GDPR-compliant, opt-in)
     *
     * This method is called during upload preparation and requests
     * user's location ONLY if:
     * 1. User hasn't explicitly denied permission before
     * 2. Browser supports Geolocation API
     *
     * GDPR Compliance:
     * - Non-blocking: Upload continues even if location denied
     * - Opt-in: User must explicitly grant permission
     * - Transparent: User sees browser's permission prompt
     * - Documented: Privacy policy must mention location tracking
     *
     * @returns {Promise<string|null>} City name or null if denied/unavailable
     */
    async requestGeolocation() {
        // Check browser support
        if (!navigator.geolocation) {
            console.debug('[Geolocation] Not supported by browser');
            return null;
        }

        // Check if user already denied (stored in localStorage)
        if (localStorage.getItem('geolocation_denied') === 'true') {
            console.debug('[Geolocation] User previously denied');
            return null;
        }

        try {
            // Request location (browser shows permission prompt)
            const position = await new Promise((resolve, reject) => {
                navigator.geolocation.getCurrentPosition(resolve, reject, {
                    enableHighAccuracy: false, // Faster, less battery drain
                    timeout: 5000,             // 5s timeout (non-blocking)
                    maximumAge: 300000         // Cache 5min (avoid repeated requests)
                });
            });

            const { latitude, longitude } = position.coords;

            // Reverse geocoding with Nominatim (OpenStreetMap, GDPR-compliant)
            const response = await fetch(
                `https://nominatim.openstreetmap.org/reverse?lat=${latitude}&lon=${longitude}&format=json`,
                {
                    headers: {
                        'User-Agent': 'need2talk.it/1.0' // Required by Nominatim
                    }
                }
            );

            if (!response.ok) {
                console.warn('[Geolocation] Reverse geocoding failed');
                return null;
            }

            const data = await response.json();
            const city = data.address?.city || data.address?.town || data.address?.village || null;

            console.debug('[Geolocation] Location detected:', city);
            return city;

        } catch (error) {
            // User denied or error occurred
            if (error.code === 1) {
                // PERMISSION_DENIED
                localStorage.setItem('geolocation_denied', 'true');
                console.debug('[Geolocation] User denied permission');
            } else {
                console.warn('[Geolocation] Error:', error.message);
            }

            return null;
        }
    }

    /**
     * Gestisci submit form
     */
    async handleSubmit() {
        if (!this.recordedAudioBlob) {
            this.showError('Nessun audio registrato');
            return;
        }
        
        const formData = new FormData(document.getElementById('recordingForm'));
        
        try {
            // Mostra loading
            this.showSubmitLoading(true);

            // ENTERPRISE FIX #7: Request geolocation (non-blocking, GDPR-compliant)
            // Runs in parallel with audio encoding for performance
            const [audioBase64, location] = await Promise.all([
                this.blobToBase64(this.recordedAudioBlob),
                this.requestGeolocation() // Opt-in, returns null if denied
            ]);

            // Prepara payload
            const payload = {
                audio_data: audioBase64.split(',')[1], // Rimuovi prefix data:mime/type;base64,
                title: formData.get('title') || '',
                description: formData.get('description') || '',
                emotion_id: formData.get('emotion_id') || null,
                audio_type: formData.get('audio_type') || 'public',
                privacy_level: formData.get('privacy_level') || 'public',
                location: location // null if user denied or unavailable
            };
            
            // Upload
            const response = await fetch('/api/audio/upload', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': formData.get('csrf_token')
                },
                body: JSON.stringify(payload)
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showSuccess('Audio caricato con successo!');
                
                // Aggiorna rate limit
                if (result.data?.rate_limit) {
                    this.updateRateLimitUI(result.data.rate_limit);
                }
                
                // Redirect dopo successo
                setTimeout(() => {
                    window.location.href = '/profile';
                }, 2000);
                
            } else {
                this.showError(result.error || 'Errore durante il caricamento');
            }
            
        } catch (error) {
            Need2Talk.Logger.error('AudioRecorder', 'Errore upload', error);
            this.showError('Errore di connessione');
        } finally {
            this.showSubmitLoading(false);
        }
    }
    
    /**
     * Converti blob in base64
     */
    blobToBase64(blob) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = reject;
            reader.readAsDataURL(blob);
        });
    }
    
    /**
     * Verifica rate limit
     */
    async checkRateLimit() {
        try {
            const response = await fetch('/api/audio/rate-limit-check');
            const result = await response.json();
            
            if (!result.success || !result.data.allowed) {
                const waitMinutes = result.data?.wait_minutes || 0;
                this.showRateLimitError(waitMinutes);
                return false;
            }
            
            return true;
            
        } catch (error) {
            Need2Talk.Logger.error('AudioRecorder', 'Errore controllo rate limit', error);
            return true; // Permetti in caso di errore
        }
    }
    
    /**
     * Ottieni MIME type supportato
     */
    getSupportedMimeType() {
        const types = ['audio/webm', 'audio/ogg', 'audio/wav', 'audio/mp4'];
        
        for (const type of types) {
            if (MediaRecorder.isTypeSupported(type)) {
                return type;
            }
        }
        
        return 'audio/webm'; // Fallback
    }
    
    /**
     * Aggiorna UI rate limit
     */
    updateRateLimitUI(rateLimitData) {
        if (window.updateRateLimitDisplay) {
            window.updateRateLimitDisplay(rateLimitData);
        }
    }
    
    /**
     * Mostra loading submit
     */
    showSubmitLoading(show) {
        const button = this.submitButton;
        const text = document.getElementById('submitButtonText');
        
        if (show) {
            button.disabled = true;
            text.textContent = '⏳ Caricamento...';
            button.classList.add('opacity-50');
        } else {
            button.disabled = false;
            text.textContent = '🚀 Pubblica Audio';
            button.classList.remove('opacity-50');
        }
    }
    
    /**
     * Mostra messaggi
     */
    showError(message) {
        this.showMessage(message, 'error');
    }
    
    showSuccess(message) {
        this.showMessage(message, 'success');
    }
    
    showMessage(message, type) {
        // TODO: Implementare toast notifications
        if (type === 'error') {
            alert('Errore: ' + message);
        } else {
            alert(message);
        }
    }
    
    showMicrophoneError(message) {
        this.showError('Microfono non accessibile: ' + message);
    }
    
    showRecordingError(message) {
        this.showError('Errore registrazione: ' + message);
    }
    
    showRateLimitError(waitMinutes) {
        this.showError(`Devi aspettare ancora ${waitMinutes} minuti prima di registrare di nuovo.`);
    }
}

// Inizializza quando DOM è pronto
document.addEventListener('DOMContentLoaded', () => {
    window.audioRecorder = new AudioRecorder();
});