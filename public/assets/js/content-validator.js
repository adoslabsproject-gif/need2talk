/**
 * Content Validator - JavaScript Client
 * 
 * Sistema di validazione in tempo reale per need2talk
 * - Validazione nickname in registrazione
 * - Filtro profanity per descrizioni audio
 * - Validazione commenti
 * - Prevenzione spam
 */

class ContentValidator {
    constructor() {
        this.apiBase = '/api/validate';
        this.debounceTimer = null;
        this.debounceDelay = 500; // 500ms
        this.cache = new Map(); // Cache per evitare richieste duplicate
    }

    /**
     * Inizializza validazione automatica sui form
     */
    init() {
        this.initNicknameValidation();
        this.initDescriptionValidation();
        this.initCommentValidation();
        this.initFormSubmitValidation();
    }

    /**
     * Validazione nickname in tempo reale (registrazione)
     */
    initNicknameValidation() {
        const nicknameInput = document.querySelector('input[name="nickname"]');
        if (!nicknameInput) return;

        const indicator = this.createValidationIndicator();
        nicknameInput.parentNode.appendChild(indicator);

        nicknameInput.addEventListener('input', (e) => {
            const nickname = e.target.value.trim();
            
            if (nickname.length === 0) {
                this.hideIndicator(indicator);
                return;
            }

            if (nickname.length < 3) {
                this.showIndicator(indicator, 'warning', 'Almeno 3 caratteri');
                return;
            }

            this.debounceValidation(() => {
                this.validateNickname(nickname, indicator);
            });
        });
    }

    /**
     * Validazione descrizione audio
     */
    initDescriptionValidation() {
        const descriptionInputs = document.querySelectorAll('textarea[name="description"], textarea[data-validate="description"]');
        
        descriptionInputs.forEach(textarea => {
            const indicator = this.createValidationIndicator();
            const counter = this.createCharacterCounter(1000);
            
            textarea.parentNode.appendChild(indicator);
            textarea.parentNode.appendChild(counter);

            textarea.addEventListener('input', (e) => {
                const description = e.target.value;
                
                // Aggiorna contatore caratteri
                this.updateCharacterCounter(counter, description.length, 1000);
                
                if (description.length === 0) {
                    this.hideIndicator(indicator);
                    return;
                }

                this.debounceValidation(() => {
                    this.validateDescription(description, indicator);
                });
            });
        });
    }

    /**
     * Validazione commenti
     */
    initCommentValidation() {
        const commentInputs = document.querySelectorAll('textarea[name="comment"], textarea[data-validate="comment"]');
        
        commentInputs.forEach(textarea => {
            const indicator = this.createValidationIndicator();
            const counter = this.createCharacterCounter(500);
            
            textarea.parentNode.appendChild(indicator);
            textarea.parentNode.appendChild(counter);

            textarea.addEventListener('input', (e) => {
                const comment = e.target.value;
                
                // Aggiorna contatore caratteri
                this.updateCharacterCounter(counter, comment.length, 500);
                
                if (comment.length === 0) {
                    this.hideIndicator(indicator);
                    return;
                }

                if (comment.length < 3) {
                    this.showIndicator(indicator, 'warning', 'Almeno 3 caratteri');
                    return;
                }

                this.debounceValidation(() => {
                    this.validateComment(comment, indicator);
                });
            });
        });
    }

    /**
     * Validazione completa prima dell'invio form
     */
    initFormSubmitValidation() {
        const forms = document.querySelectorAll('form[data-validate="true"]');
        
        forms.forEach(form => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                
                if (await this.validateForm(form)) {
                    form.submit();
                }
            });
        });
    }

    /**
     * Validazione nickname via API
     */
    async validateNickname(nickname, indicator) {
        const cacheKey = `nickname_${nickname}`;
        
        if (this.cache.has(cacheKey)) {
            const result = this.cache.get(cacheKey);
            this.displayNicknameResult(result, indicator);
            return;
        }

        this.showIndicator(indicator, 'loading', 'Controllo disponibilità...');

        try {
            const response = await fetch(`${this.apiBase}/nickname`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ nickname })
            });

            const result = await response.json();
            this.cache.set(cacheKey, result);
            this.displayNicknameResult(result, indicator);

        } catch (error) {
            console.error('Nickname validation error:', error);
            this.showIndicator(indicator, 'error', 'Errore di connessione');
        }
    }

    /**
     * Validazione descrizione audio via API
     */
    async validateDescription(description, indicator) {
        if (!this.isAuthenticated()) {
            this.showIndicator(indicator, 'warning', 'Accedi per validazione completa');
            return;
        }

        this.showIndicator(indicator, 'loading', 'Controllo contenuto...');

        try {
            const response = await fetch(`${this.apiBase}/description`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.getCsrfToken()
                },
                body: JSON.stringify({ description })
            });

            const result = await response.json();
            this.displayContentResult(result, indicator);

        } catch (error) {
            console.error('Description validation error:', error);
            this.showIndicator(indicator, 'error', 'Errore di validazione');
        }
    }

    /**
     * Validazione commento via API
     */
    async validateComment(comment, indicator) {
        if (!this.isAuthenticated()) {
            this.showIndicator(indicator, 'warning', 'Accedi per commentare');
            return;
        }

        this.showIndicator(indicator, 'loading', 'Controllo commento...');

        try {
            const response = await fetch(`${this.apiBase}/comment`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.getCsrfToken()
                },
                body: JSON.stringify({ comment })
            });

            const result = await response.json();
            this.displayContentResult(result, indicator);

        } catch (error) {
            console.error('Comment validation error:', error);
            this.showIndicator(indicator, 'error', 'Errore di validazione');
        }
    }

    /**
     * Validazione completa form
     */
    async validateForm(form) {
        const formData = new FormData(form);
        const errors = [];
        let isValid = true;

        // Valida nickname se presente
        const nickname = formData.get('nickname');
        if (nickname) {
            const result = await this.validateNicknameSync(nickname);
            if (!result.valid || !result.available) {
                errors.push('Nickname non valido o non disponibile');
                isValid = false;
            }
        }

        // Valida descrizione se presente
        const description = formData.get('description');
        if (description) {
            const result = await this.validateDescriptionSync(description);
            if (!result.valid) {
                errors.push('Descrizione non valida: ' + result.errors.join(', '));
                isValid = false;
            }
        }

        // Mostra errori se presenti
        if (!isValid) {
            this.showFormErrors(form, errors);
        }

        return isValid;
    }

    /**
     * Crea indicatore di validazione
     */
    createValidationIndicator() {
        const indicator = document.createElement('div');
        indicator.className = 'validation-indicator hidden mt-2 text-sm';
        return indicator;
    }

    /**
     * Crea contatore caratteri
     */
    createCharacterCounter(max) {
        const counter = document.createElement('div');
        counter.className = 'character-counter text-right text-sm text-gray-400 mt-1';
        counter.textContent = `0/${max}`;
        return counter;
    }

    /**
     * Aggiorna contatore caratteri
     */
    updateCharacterCounter(counter, current, max) {
        counter.textContent = `${current}/${max}`;
        
        if (current > max) {
            counter.className = 'character-counter text-right text-sm text-red-400 mt-1';
        } else if (current > max * 0.9) {
            counter.className = 'character-counter text-right text-sm text-yellow-400 mt-1';
        } else {
            counter.className = 'character-counter text-right text-sm text-gray-400 mt-1';
        }
    }

    /**
     * Mostra indicatore con stato
     */
    showIndicator(indicator, type, message) {
        indicator.className = `validation-indicator mt-2 text-sm ${this.getIndicatorClasses(type)}`;
        indicator.innerHTML = `${this.getIndicatorIcon(type)} ${message}`;
        indicator.classList.remove('hidden');
    }

    /**
     * Nascondi indicatore
     */
    hideIndicator(indicator) {
        indicator.classList.add('hidden');
    }

    /**
     * Ottieni classi CSS per indicatore
     */
    getIndicatorClasses(type) {
        const classes = {
            'success': 'text-green-400',
            'error': 'text-red-400',
            'warning': 'text-yellow-400',
            'loading': 'text-blue-400',
            'info': 'text-purple-400'
        };
        return classes[type] || 'text-gray-400';
    }

    /**
     * Ottieni icona per indicatore
     */
    getIndicatorIcon(type) {
        const icons = {
            'success': '✅',
            'error': '❌',
            'warning': '⚠️',
            'loading': '🔄',
            'info': 'ℹ️'
        };
        return icons[type] || '•';
    }

    /**
     * Visualizza risultato validazione nickname
     */
    displayNicknameResult(result, indicator) {
        if (result.valid && result.available) {
            this.showIndicator(indicator, 'success', 'Nickname disponibile!');
        } else if (!result.available) {
            this.showIndicator(indicator, 'error', 'Nickname già in uso');
        } else {
            this.showIndicator(indicator, 'error', result.errors.join(', '));
        }
    }

    /**
     * Visualizza risultato validazione contenuto
     */
    displayContentResult(result, indicator) {
        if (result.valid) {
            if (result.warnings && result.warnings.length > 0) {
                this.showIndicator(indicator, 'warning', result.warnings.join(', '));
            } else {
                this.showIndicator(indicator, 'success', 'Contenuto valido');
            }
        } else {
            this.showIndicator(indicator, 'error', result.errors.join(', '));
        }
    }

    /**
     * Debounce per validazione
     */
    debounceValidation(callback) {
        clearTimeout(this.debounceTimer);
        this.debounceTimer = setTimeout(callback, this.debounceDelay);
    }

    /**
     * Validazione sincrona nickname (per form submit)
     */
    async validateNicknameSync(nickname) {
        const response = await fetch(`${this.apiBase}/nickname`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ nickname })
        });
        return await response.json();
    }

    /**
     * Validazione sincrona descrizione (per form submit)
     */
    async validateDescriptionSync(description) {
        const response = await fetch(`${this.apiBase}/description`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.getCsrfToken()
            },
            body: JSON.stringify({ description })
        });
        return await response.json();
    }

    /**
     * Mostra errori form
     */
    showFormErrors(form, errors) {
        // Rimuovi errori precedenti
        const existingErrors = form.querySelectorAll('.form-error');
        existingErrors.forEach(error => error.remove());

        // Aggiungi nuovi errori
        const errorDiv = document.createElement('div');
        errorDiv.className = 'form-error bg-red-600/20 border border-red-500/50 rounded-lg p-4 mb-6';
        errorDiv.innerHTML = `
            <h4 class="text-red-300 font-semibold mb-2">Errori di validazione:</h4>
            <ul class="text-red-300 text-sm list-disc list-inside">
                ${errors.map(error => `<li>${error}</li>`).join('')}
            </ul>
        `;

        form.insertBefore(errorDiv, form.firstChild);
        
        // Scroll to errors
        errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    /**
     * Controlla se utente è autenticato
     */
    isAuthenticated() {
        // Assume che ci sia un meta tag o variabile globale per lo stato auth
        return document.querySelector('meta[name="user-authenticated"]') !== null ||
               window.userAuthenticated === true;
    }

    /**
     * Ottieni CSRF token
     */
    getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }
}

// Inizializza validatore quando DOM è pronto
document.addEventListener('DOMContentLoaded', function() {
    const validator = new ContentValidator();
    validator.init();
    
    // Rendi disponibile globalmente per uso manuale
    window.contentValidator = validator;
});

// Export per moduli
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ContentValidator;
}