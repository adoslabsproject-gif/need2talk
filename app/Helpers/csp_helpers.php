<?php
/**
 * CSP Nonce Helper Functions - Enterprise Security
 */

use Need2Talk\Security\CSPNonceGenerator;

if (!function_exists('csp_nonce')) {
    /**
     * Get current CSP nonce value for inline scripts/styles
     *
     * Returns the raw nonce value for use in nonce="" attributes
     * Example output: "abc123def456"
     *
     * @return string Raw nonce value
     */
    function csp_nonce(): string
    {
        $nonce = CSPNonceGenerator::getNonce();

        // ENTERPRISE: Auto-generate if middleware didn't run
        if ($nonce === null) {
            $nonce = CSPNonceGenerator::generate();
        }

        return $nonce;
    }
}

if (!function_exists('csp_nonce_attr')) {
    /**
     * Get full nonce attribute for inline scripts/styles
     *
     * Returns complete HTML attribute: nonce="abc123def456"
     * Useful for programmatic HTML generation
     *
     * Example output: nonce="abc123def456"
     *
     * @return string Complete nonce attribute
     */
    function csp_nonce_attr(): string
    {
        return CSPNonceGenerator::getNonceAttribute();
    }
}

if (!function_exists('csp_script_nonce')) {
    /**
     * Helper for script tags with nonce
     *
     * Usage: csp_script_nonce() inside script tag
     *
     * @return string Nonce value for scripts
     */
    function csp_script_nonce(): string
    {
        return csp_nonce();
    }
}

if (!function_exists('csp_style_nonce')) {
    /**
     * Helper for style tags with nonce
     *
     * Usage: csp_style_nonce() inside style tag
     *
     * @return string Nonce value for styles
     */
    function csp_style_nonce(): string
    {
        return csp_nonce();
    }
}
