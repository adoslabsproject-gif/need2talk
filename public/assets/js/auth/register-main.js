/**
 * =================================================================
 * REGISTRATION PAGE MAIN - Entry Point
 * =================================================================
 * Main entry point for registration page functionality
 * Imports and initializes all required components
 * =================================================================
 */

import { createRegistrationComponent } from '../components/registration-form.js';

/**
 * Main registration page function for Alpine.js
 * This function is called by Alpine.js when x-data="registerPageData()" is initialized
 */
window.registerPageData = function() {
    return createRegistrationComponent();
};

/**
 * Initialize page-level functionality
 */
document.addEventListener('DOMContentLoaded', () => {
    // Any additional page-level initialization can go here
    Need2Talk.Logger.info('RegisterMain', 'Registration page loaded successfully');
});