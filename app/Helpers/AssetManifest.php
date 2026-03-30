<?php

namespace Need2Talk\Helpers;

/**
 * AssetManifest - Enterprise Asset Management with Hashed Filenames
 *
 * Reads Vite manifest.json to map logical asset names to hashed filenames
 * for optimal browser caching (cache forever, invalidate via new filename)
 */
class AssetManifest
{
    private static ?array $manifest = null;

    /**
     * Load manifest file
     */
    private static function loadManifest(): void
    {
        if (self::$manifest !== null) {
            return;
        }

        $manifestPath = APP_ROOT . '/public/dist/.vite/manifest.json';

        if (!file_exists($manifestPath)) {
            // Development mode - manifest not built yet
            self::$manifest = [];

            return;
        }

        $json = file_get_contents($manifestPath);
        self::$manifest = json_decode($json, true) ?? [];
    }

    /**
     * Get hashed filename for an asset
     *
     * @param string $logicalPath Logical path like "app.css" or "js/main.js"
     * @return string Hashed path like "app.a3f4b29c.css" or original if not found
     */
    public static function get(string $logicalPath): string
    {
        self::loadManifest();

        // Normalize path (remove leading ./ or /)
        $logicalPath = ltrim($logicalPath, './');

        // Search manifest by logical path or by names array
        foreach (self::$manifest as $key => $entry) {
            // Direct match
            if ($key === $logicalPath || $key === 'src/' . $logicalPath) {
                if (isset($entry['file'])) {
                    return '/dist/' . $entry['file'];
                }
            }

            // Check names array (Vite generates this)
            if (isset($entry['names']) && in_array($logicalPath, $entry['names'])) {
                if (isset($entry['file'])) {
                    return '/dist/' . $entry['file'];
                }
            }
        }

        // Fallback to original path (development mode)
        return '/assets/' . $logicalPath;
    }

    /**
     * Clear manifest cache (useful after rebuild)
     */
    public static function clearCache(): void
    {
        self::$manifest = null;
    }
}
