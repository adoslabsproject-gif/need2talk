<?php

namespace Need2Talk\Core;

use Need2Talk\Services\Logger;
use Need2Talk\Services\SecureSessionManager;

/**
 * BaseController - Essential controller functionality (ENTERPRISE UNIFIED)
 *
 * ENTERPRISE GALAXY: Migrated to unified SecureSessionManager
 * - Redis primary storage (instant session lookup)
 * - PostgreSQL audit trail (GDPR compliance)
 * - Multi-level database cache (L1/L2/L3)
 * - No custom session caching (relies on enterprise DB cache)
 *
 * Clean, focused base controller with lazy user loading
 *
 * @property array|null $user Lazy-loaded user data via __get() magic method
 */
abstract class BaseController
{
    private ?array $_user = null;

    private bool $userLoaded = false;

    public function __construct()
    {
        // ENTERPRISE LAZY LOADING: Don't validate session until needed
        // This allows homepage to have ZERO queries
    }

    /**
     * ENTERPRISE: Magic getter for lazy loading user
     * Intercepts $this->user access and loads on-demand
     *
     * ENTERPRISE UNIFIED: Uses SecureSessionManager + multi-level DB cache
     * - Redis session validation (instant)
     * - L1/L2/L3 database cache (prevents duplicate queries)
     *
     * @return mixed User array or null
     */
    public function __get(string $name): mixed
    {
        if ($name === 'user') {
            if (!$this->userLoaded) {
                try {
                    $this->_user = $this->getCurrentUser();
                } catch (\Exception $e) {
                    $this->_user = null;
                }
                $this->userLoaded = true;
            }

            return $this->_user;
        }

        return null;
    }

    protected function getCurrentUser(): ?array
    {
        // ENTERPRISE GALAXY: Use SecureSessionManager for conditional session start
        // This ensures session is only started when truly needed
        SecureSessionManager::ensureSessionStarted();

        $userId = null;

        // Check authenticated session (Redis)
        // ENTERPRISE GALAXY (2025-01-23 REFACTORING): Delegate to current_user() helper
        // This replaces legacy database query + $_SESSION['user'] with Redis L1 cache
        $user = current_user();

        if ($user) {
            // ENTERPRISE FIX: Transform relative avatar_url to absolute URL
            // Database stores relative paths (avatars/123/...), views need full URLs
            if (isset($user['avatar_url']) && !str_starts_with($user['avatar_url'], 'http')) {
                // Local upload: add /storage/uploads/ prefix
                if (!str_starts_with($user['avatar_url'], '/')) {
                    $user['avatar_url'] = '/storage/uploads/' . $user['avatar_url'];
                }
            }
        }

        return $user ?: null;
    }

    protected function requireAuth(): array
    {
        // ENTERPRISE: Force user load for authentication check
        $user = $this->user; // This triggers __get() lazy load

        if (!$user) {
            // ENTERPRISE: API-aware authentication
            // APIs must return 401 JSON, not redirect HTML
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';

            $isApiRequest = str_starts_with($requestUri, '/api/') ||
                           str_contains($acceptHeader, 'application/json');

            if ($isApiRequest) {
                // API request → return 401 JSON
                $this->json([
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Authentication required',
                ], 401);
            } else {
                // Web request → redirect to login
                $this->redirect(url('login'));
            }
        }

        return $user;
    }

    /**
     * Check if user is authenticated
     *
     * @return bool True if user is logged in
     */
    protected function isAuthenticated(): bool
    {
        return $this->user !== null;
    }

    /**
     * Get current user ID
     *
     * @return int|null User ID or null if not authenticated
     */
    protected function getUserId(): ?int
    {
        return $this->user['id'] ?? null;
    }

    /**
     * Get current user UUID (ENTERPRISE UUID-based system)
     *
     * @return string|null User UUID or null if not authenticated
     */
    protected function getUserUuid(): ?string
    {
        return $this->user['uuid'] ?? null;
    }

    protected function getInput(string $key, $default = null)
    {
        // Check POST first
        if (isset($_POST[$key])) {
            return $_POST[$key];
        }

        // Check JSON input
        $jsonInput = $this->getJsonInput();

        if (isset($jsonInput[$key])) {
            return $jsonInput[$key];
        }

        // Check GET as fallback
        if (isset($_GET[$key])) {
            return $_GET[$key];
        }

        return $default;
    }

    protected function getJsonInput(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);

        return $input ?: [];
    }

    protected function json(array $data, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');

        // ENTERPRISE: Add custom headers (e.g., Cache-Control, Expires)
        foreach ($headers as $key => $value) {
            header("{$key}: {$value}");
        }

        // ENTERPRISE FIX: Use JSON options to handle UTF-8 properly (emoji, special chars)
        // JSON_UNESCAPED_UNICODE: Keep UTF-8 characters as-is (don't escape emoji)
        // JSON_INVALID_UTF8_SUBSTITUTE: Replace invalid UTF-8 with � instead of failing
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    /**
     * Alias per json() - per compatibility con AdminController
     */
    protected function jsonResponse(array $data, int $status = 200, array $headers = []): void
    {
        $this->json($data, $status, $headers);
    }

    /**
     * Alias per view() - per compatibility con AdminController
     */
    protected function render(string $template, array $data = []): void
    {
        $this->view($template, $data);
    }

    protected function redirect(string $url): void
    {
        header("Location: {$url}");
        exit;
    }

    protected function validateCsrf(): void
    {
        // ENTERPRISE: CSRF validation is handled by CsrfMiddleware
        // This method is kept for backward compatibility but no longer validates
        // The middleware has already validated the token before reaching this controller
    }

    protected function view(string $template, array $data = [], ?string $layout = null): void
    {
        // ENTERPRISE: Only pass user to view if explicitly provided or if already loaded
        // This prevents lazy loading on pages that don't need user data
        if (!isset($data['user']) && $this->userLoaded) {
            $data['user'] = $this->_user;
        } elseif (!isset($data['user'])) {
            $data['user'] = null; // Provide null instead of triggering lazy load
        }

        // ENTERPRISE GALAXY: Generate CSRF token ONLY if session is active
        // Public routes without session don't need CSRF (no forms)
        // This prevents auto-starting session on every page load
        if (session_status() === PHP_SESSION_ACTIVE) {
            try {
                $data['csrfToken'] = csrf_token();
            } catch (\Exception $e) {
                $data['csrfToken'] = bin2hex(random_bytes(32));
            }
        } else {
            // No session = no CSRF token needed (public page without forms)
            $data['csrfToken'] = null;
        }

        // Logica intelligente per trovare le views
        $possiblePaths = [
            APP_ROOT . '/app/Views/pages/' . str_replace('.', '/', $template) . '.php',
            // Poi prova il path diretto con dot notation
            APP_ROOT . '/app/Views/' . str_replace('.', '/', $template) . '.php',
            // Infine prova con /index.php per directories
            APP_ROOT . '/app/Views/' . str_replace('.', '/', $template) . '/index.php',
        ];

        foreach ($possiblePaths as $viewPath) {
            if (file_exists($viewPath)) {
                extract($data);

                // ENTERPRISE TIPS: Capture output and set Content-Length to avoid chunked encoding issues
                ob_start();
                include $viewPath;
                $viewContent = ob_get_contents();
                ob_end_clean();

                // ENTERPRISE: Layout support for post-login pages
                // If layout is specified, wrap view content in layout
                if ($layout !== null) {
                    $layoutPath = APP_ROOT . '/app/Views/layouts/' . $layout . '.php';
                    if (file_exists($layoutPath)) {
                        // Pass view content to layout as $content variable
                        $content = $viewContent;

                        // Extract data again for layout (includes $content now)
                        extract($data);

                        ob_start();
                        include $layoutPath;
                        $content = ob_get_contents();
                        ob_end_clean();
                    } else {
                        // Layout not found, use view content as-is
                        $content = $viewContent;

                        // Log warning in development
                        if (env('APP_ENV') === 'development') {
                            Logger::warning('Layout not found, rendering view without layout', [
                                'layout' => $layout,
                                'layout_path' => $layoutPath,
                                'view' => $template,
                            ]);
                        }
                    }
                } else {
                    // No layout specified, use view content as-is
                    $content = $viewContent;
                }

                // ENTERPRISE: Add debugbar to HTML content if enabled
                // CRITICAL FIX (2025-12-08): Initialize BEFORE tracking views!
                if (class_exists('\Need2Talk\Services\DebugbarService')) {
                    // Debugbar initialization (respects admin settings)
                    \Need2Talk\Services\DebugbarService::initialize();
                }

                // CRITICAL: Track view for DebugBar (AFTER initialization!)
                if (function_exists('debugbar_add_view')) {
                    debugbar_add_view($template, $data);
                }

                // ENTERPRISE: Add debugbar to HTML content if enabled
                if (class_exists('\Need2Talk\Services\DebugbarService')) {

                    if (function_exists('debugbar_render') && function_exists('debugbar_render_head')) {
                        $debugbarHead = debugbar_render_head();
                        $debugbarBody = debugbar_render();

                        if (!empty($debugbarHead) || !empty($debugbarBody)) {
                            // Add debugbar CSS to head
                            if (!empty($debugbarHead)) {
                                $content = str_replace('</head>', $debugbarHead . "\n</head>", $content);
                            }

                            // Add debugbar JS before closing body
                            if (!empty($debugbarBody)) {
                                $content = str_replace('</body>', $debugbarBody . "\n</body>", $content);
                            }
                        }
                    }
                }

                // Set Content-Type with UTF-8 charset for proper encoding
                header('Content-Type: text/html; charset=UTF-8');
                // Set Content-Length header to prevent Transfer-Encoding: chunked
                header('Content-Length: ' . strlen($content));
                echo $content;

                // ENTERPRISE: Cache the complete HTML page AFTER rendering
                if (class_exists('\EarlyPageCache')) {
                    \EarlyPageCache::set($content);
                }

                return;
            }
        }

        // Debug dettagliato per development
        if ($_ENV['APP_ENV'] === 'development') {
            echo "<h1>View not found: {$template}</h1>";
            echo '<h3>Tried paths:</h3><ul>';

            foreach ($possiblePaths as $path) {
                echo '<li>' . htmlspecialchars($path) . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<h1>Page not found</h1>';
        }
    }
}
