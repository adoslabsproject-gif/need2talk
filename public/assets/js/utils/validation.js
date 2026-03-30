/**
 * =================================================================
 * VALIDATION UTILITIES - Form Validation Functions
 * =================================================================
 * Reusable validation functions for forms
 * =================================================================
 */

/**
 * Email validation utility
 */
export const validateEmail = (email) => {
    if (!email || !email.trim()) {
        return { valid: false, error: 'L\'email è obbligatoria' };
    }

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email.trim())) {
        return { valid: false, error: 'Formato email non valido' };
    }

    return { valid: true, error: null };
};

/**
 * Nickname validation utility
 */
export const validateNickname = (nickname) => {
    if (!nickname || !nickname.trim()) {
        return { valid: false, error: 'Il nickname è obbligatorio' };
    }

    const cleanNickname = nickname.trim();

    if (cleanNickname.length < 3) {
        return { valid: false, error: 'Il nickname deve essere di almeno 3 caratteri' };
    }

    if (cleanNickname.length > 30) {
        return { valid: false, error: 'Il nickname non può superare i 30 caratteri' };
    }

    const nicknameRegex = /^[a-zA-Z0-9_-]+$/;
    if (!nicknameRegex.test(cleanNickname)) {
        return { valid: false, error: 'Solo lettere, numeri, trattini e underscore' };
    }

    return { valid: true, error: null };
};

/**
 * Password strength calculation
 */
export const calculatePasswordStrength = (password) => {
    const requirements = {
        minLength: password.length >= 8,
        hasUpper: /[A-Z]/.test(password),
        hasLower: /[a-z]/.test(password),
        hasNumber: /\d/.test(password),
        hasSpecial: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)
    };

    let score = 0;
    if (requirements.minLength) score += 20;
    if (requirements.hasUpper) score += 20;
    if (requirements.hasLower) score += 20;
    if (requirements.hasNumber) score += 20;
    if (requirements.hasSpecial) score += 20;

    return {
        score,
        requirements,
        text: getPasswordStrengthText(score),
        color: getPasswordStrengthColor(score),
        bg: getPasswordStrengthBg(score)
    };
};

/**
 * Password validation
 */
export const validatePassword = (password, strengthScore) => {
    if (!password) {
        return { valid: false, error: 'La password è obbligatoria' };
    }

    if (password.length < 8) {
        return { valid: false, error: 'La password deve essere di almeno 8 caratteri' };
    }

    if (strengthScore < 50) {
        return { valid: false, error: 'Password troppo debole. Aggiungi maiuscole, numeri o caratteri speciali' };
    }

    return { valid: true, error: null };
};

/**
 * Password confirmation validation
 */
export const validatePasswordConfirmation = (password, confirmation) => {
    if (!confirmation) {
        return { valid: false, error: 'Conferma la password' };
    }

    if (confirmation !== password) {
        return { valid: false, error: 'Le password non corrispondono' };
    }

    return { valid: true, error: null };
};

/**
 * Age validation (18+ requirement)
 */
export const validateAge = (month, year) => {
    const monthNum = parseInt(month);
    const yearNum = parseInt(year);
    
    if (!monthNum || !yearNum) {
        return { valid: false, error: 'Seleziona mese e anno di nascita', age: 0 };
    }

    // Calculate age
    const today = new Date();
    const birthDate = new Date(yearNum, monthNum - 1, 1);
    const age = today.getFullYear() - birthDate.getFullYear();
    const monthDiff = today.getMonth() - birthDate.getMonth();
    
    const calculatedAge = monthDiff < 0 ? age - 1 : age;

    if (calculatedAge < 18) {
        return { valid: false, error: 'Devi avere almeno 18 anni per registrarti', age: calculatedAge };
    }

    return { valid: true, error: null, age: calculatedAge };
};

/**
 * Helper functions for password strength
 */
function getPasswordStrengthText(score) {
    if (score === 0) return 'Inserisci password';
    if (score < 40) return 'Molto debole';
    if (score < 60) return 'Debole';
    if (score < 80) return 'Buona';
    return 'Molto sicura';
}

function getPasswordStrengthColor(score) {
    if (score === 0) return 'text-gray-400';
    if (score < 40) return 'text-red-500';
    if (score < 60) return 'text-orange-500';
    if (score < 80) return 'text-yellow-500';
    return 'text-green-500';
}

function getPasswordStrengthBg(score) {
    if (score === 0) return 'bg-gray-600';
    if (score < 40) return 'bg-red-500';
    if (score < 60) return 'bg-orange-500';
    if (score < 80) return 'bg-yellow-500';
    return 'bg-green-500';
}