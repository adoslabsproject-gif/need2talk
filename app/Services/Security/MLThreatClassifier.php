<?php

namespace Need2Talk\Services\Security;

use Need2Talk\Services\Logger;

/**
 * ENTERPRISE GALAXY: Machine Learning Threat Classifier
 *
 * Inspired by adoslabsproject-gif/enterprise-security-shield architecture
 * Implements Online Learning Naive Bayes classifier for threat detection
 *
 * ARCHITECTURE:
 * - Online Learning: Model learns continuously from real security events
 * - Feature Extraction: Extracts 15+ features from each request
 * - Probability Scoring: Calculates threat probability 0.0-1.0
 * - Decay Mechanism: Old patterns lose relevance over time (concept drift)
 * - Redis Persistence: Weights survive restarts
 *
 * INTEGRATION:
 * - Works alongside rule-based AntiVulnerabilityScanningMiddleware
 * - ML score contributes 40% to final threat assessment
 * - High confidence (>85%) triggers immediate action
 *
 * LEARNING SOURCES:
 * - Auto-bans from AntiVulnerabilityScanningMiddleware
 * - Honeypot hits
 * - CSRF failures
 * - Rate limit violations
 * - Manual admin bans
 *
 * @author need2talk Enterprise Team
 * @version 1.0.0
 */
class MLThreatClassifier
{
    private const REDIS_PREFIX = 'ml_threat:';
    private const WEIGHT_DECAY = 0.995; // Decay factor for old patterns
    private const MIN_SAMPLES_FOR_MATURE = 500;
    private const MIN_SAMPLES_FOR_LEARNING = 50;
    private const HIGH_CONFIDENCE_THRESHOLD = 0.85;
    private const REDIS_DB = 3; // Same as rate limiting

    private ?\Redis $redis = null;
    private array $featureWeights = [];
    private int $totalSamples = 0;
    private int $threatSamples = 0;
    private int $safeSamples = 0;

    /**
     * Threat categories for classification
     */
    private const THREAT_CATEGORIES = [
        'SCANNER' => ['weight' => 1.0, 'patterns' => ['nmap', 'nikto', 'sqlmap', 'masscan']],
        'CMS_PROBE' => ['weight' => 0.9, 'patterns' => ['wp-admin', 'wp-login', 'phpmyadmin', 'admin.php']],
        'CREDENTIAL_THEFT' => ['weight' => 1.0, 'patterns' => ['.env', '.aws', 'credentials', '.git']],
        'BRUTE_FORCE' => ['weight' => 0.95, 'patterns' => ['login_failure', 'auth_failed', 'password_reset']],
        'IOT_EXPLOIT' => ['weight' => 1.0, 'patterns' => ['gpon', 'zte', 'dlink', 'netgear']],
        'PATH_TRAVERSAL' => ['weight' => 1.0, 'patterns' => ['../', '%2e%2e', '....//']],
        'SQL_INJECTION' => ['weight' => 1.0, 'patterns' => ['union', 'select', '1=1', 'or 1']],
        'XSS_ATTEMPT' => ['weight' => 0.9, 'patterns' => ['<script', 'javascript:', 'onerror=']],
    ];

    /**
     * Feature extractors - each returns a value 0.0-1.0
     */
    private const FEATURES = [
        'user_agent_suspicious',      // Known scanner UA
        'user_agent_missing',         // Empty/missing UA
        'user_agent_fake',            // Impossible browser version
        'path_sensitive',             // Sensitive file path
        'path_cms',                   // CMS scanning path
        'path_config',                // Config file path
        'path_traversal',             // Path traversal attempt
        'request_rate_high',          // High request rate from IP
        'error_rate_high',            // High 404/403 rate
        'multiple_endpoints',         // Hitting many different endpoints
        'query_suspicious',           // Suspicious query string
        'referer_missing',            // Missing referer on POST
        'method_unusual',             // Unusual HTTP method
        'hour_suspicious',            // Request at unusual hour
        'geo_suspicious',             // From suspicious country
    ];

    public function __construct()
    {
        $this->initRedis();
        $this->loadWeights();
    }

    /**
     * Initialize Redis connection
     */
    private function initRedis(): bool
    {
        if ($this->redis !== null) {
            return true;
        }

        try {
            $this->redis = new \Redis();
            $host = $_ENV['REDIS_HOST'] ?? 'redis';
            $port = (int) ($_ENV['REDIS_PORT'] ?? 6379);
            $password = $_ENV['REDIS_PASSWORD'] ?? null;

            $this->redis->connect($host, $port, 2.0);

            if ($password) {
                $this->redis->auth($password);
            }

            $this->redis->select(self::REDIS_DB);

            return true;
        } catch (\Exception $e) {
            Logger::error('ML Classifier: Redis connection failed', [
                'error' => $e->getMessage(),
            ]);
            $this->redis = null;

            return false;
        }
    }

    /**
     * Load weights from Redis
     */
    private function loadWeights(): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $weightsJson = $this->redis->get(self::REDIS_PREFIX . 'weights');

            if ($weightsJson) {
                $data = json_decode($weightsJson, true);
                $this->featureWeights = $data['weights'] ?? [];
                $this->totalSamples = $data['total_samples'] ?? 0;
                $this->threatSamples = $data['threat_samples'] ?? 0;
                $this->safeSamples = $data['safe_samples'] ?? 0;
            } else {
                // Initialize with default weights
                $this->initializeDefaultWeights();
            }
        } catch (\Exception $e) {
            Logger::error('ML Classifier: Failed to load weights', [
                'error' => $e->getMessage(),
            ]);
            $this->initializeDefaultWeights();
        }
    }

    /**
     * Initialize default weights based on security knowledge
     */
    private function initializeDefaultWeights(): void
    {
        foreach (self::FEATURES as $feature) {
            $this->featureWeights[$feature] = [
                'threat' => 0.5,  // Prior probability
                'safe' => 0.5,
            ];
        }

        // Set known high-risk features
        $this->featureWeights['user_agent_suspicious']['threat'] = 0.95;
        $this->featureWeights['path_sensitive']['threat'] = 0.90;
        $this->featureWeights['path_traversal']['threat'] = 0.98;
        $this->featureWeights['query_suspicious']['threat'] = 0.85;

        $this->totalSamples = 0;
        $this->threatSamples = 0;
        $this->safeSamples = 0;
    }

    /**
     * Save weights to Redis
     */
    private function saveWeights(): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $data = [
                'weights' => $this->featureWeights,
                'total_samples' => $this->totalSamples,
                'threat_samples' => $this->threatSamples,
                'safe_samples' => $this->safeSamples,
                'updated_at' => time(),
            ];

            $this->redis->set(
                self::REDIS_PREFIX . 'weights',
                json_encode($data),
                ['ex' => 86400 * 30] // 30 days TTL
            );
        } catch (\Exception $e) {
            Logger::error('ML Classifier: Failed to save weights', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Classify a request and return threat assessment
     *
     * @param array $requestData Request data with ip, path, user_agent, method, etc.
     * @return array Classification result with score, confidence, category, action
     */
    public function classify(array $requestData): array
    {
        $features = $this->extractFeatures($requestData);
        $probability = $this->calculateThreatProbability($features);
        $confidence = $this->calculateConfidence($features);
        $category = $this->detectThreatCategory($requestData);

        // Determine action based on probability and confidence
        $action = $this->determineAction($probability, $confidence);

        return [
            'probability' => round($probability, 4),
            'confidence' => round($confidence, 4),
            'category' => $category,
            'action' => $action,
            'features' => $features,
            'learning_status' => $this->getLearningStatus(),
            'is_high_confidence' => $confidence >= self::HIGH_CONFIDENCE_THRESHOLD,
        ];
    }

    /**
     * Extract features from request data
     *
     * @param array $requestData
     * @return array Feature values 0.0-1.0
     */
    private function extractFeatures(array $requestData): array
    {
        $ip = $requestData['ip'] ?? '';
        $path = strtolower($requestData['path'] ?? '/');
        $userAgent = strtolower($requestData['user_agent'] ?? '');
        $method = strtoupper($requestData['method'] ?? 'GET');
        $query = strtolower($requestData['query'] ?? '');
        $referer = $requestData['referer'] ?? '';

        $features = [];

        // User-Agent analysis
        $features['user_agent_suspicious'] = $this->checkSuspiciousUserAgent($userAgent);
        $features['user_agent_missing'] = empty($userAgent) ? 1.0 : 0.0;
        $features['user_agent_fake'] = $this->checkFakeUserAgent($userAgent);

        // Path analysis
        $features['path_sensitive'] = $this->checkSensitivePath($path);
        $features['path_cms'] = $this->checkCMSPath($path);
        $features['path_config'] = $this->checkConfigPath($path);
        $features['path_traversal'] = $this->checkPathTraversal($path);

        // Behavioral analysis (from Redis)
        $features['request_rate_high'] = $this->checkRequestRate($ip);
        $features['error_rate_high'] = $this->checkErrorRate($ip);
        $features['multiple_endpoints'] = $this->checkEndpointDiversity($ip);

        // Request analysis
        $features['query_suspicious'] = $this->checkSuspiciousQuery($query);
        $features['referer_missing'] = ($method === 'POST' && empty($referer)) ? 0.7 : 0.0;
        $features['method_unusual'] = in_array($method, ['PUT', 'DELETE', 'PATCH', 'OPTIONS'], true) ? 0.3 : 0.0;

        // Temporal analysis
        $hour = (int) date('H');
        $features['hour_suspicious'] = ($hour >= 2 && $hour <= 5) ? 0.2 : 0.0; // 2-5 AM suspicious

        // Geo analysis (placeholder - would need GeoIP)
        $features['geo_suspicious'] = 0.0; // TODO: Implement GeoIP check

        return $features;
    }

    /**
     * Calculate threat probability using Naive Bayes
     */
    private function calculateThreatProbability(array $features): float
    {
        // Prior probabilities
        $priorThreat = max(0.01, $this->threatSamples / max(1, $this->totalSamples));
        $priorSafe = 1.0 - $priorThreat;

        // Start with priors in log space
        $logProbThreat = log($priorThreat);
        $logProbSafe = log($priorSafe);

        // Multiply by likelihood of each feature
        foreach ($features as $name => $value) {
            if (!isset($this->featureWeights[$name])) {
                continue;
            }

            $threatLikelihood = $this->featureWeights[$name]['threat'] ?? 0.5;
            $safeLikelihood = $this->featureWeights[$name]['safe'] ?? 0.5;

            // Weighted contribution based on feature value
            $threatLikelihood = $value * $threatLikelihood + (1 - $value) * (1 - $threatLikelihood);
            $safeLikelihood = $value * $safeLikelihood + (1 - $value) * (1 - $safeLikelihood);

            // Avoid log(0)
            $threatLikelihood = max(0.001, min(0.999, $threatLikelihood));
            $safeLikelihood = max(0.001, min(0.999, $safeLikelihood));

            $logProbThreat += log($threatLikelihood);
            $logProbSafe += log($safeLikelihood);
        }

        // Convert back from log space and normalize
        $maxLog = max($logProbThreat, $logProbSafe);
        $probThreat = exp($logProbThreat - $maxLog);
        $probSafe = exp($logProbSafe - $maxLog);

        $total = $probThreat + $probSafe;

        return $probThreat / $total;
    }

    /**
     * Calculate classification confidence
     */
    private function calculateConfidence(array $features): float
    {
        // Base confidence from sample count
        $sampleConfidence = min(1.0, $this->totalSamples / self::MIN_SAMPLES_FOR_MATURE);

        // Feature activation strength
        $activeFeatures = array_filter($features, fn ($v) => $v > 0.5);
        $featureConfidence = min(1.0, count($activeFeatures) / 5);

        // Combined confidence
        return ($sampleConfidence * 0.6) + ($featureConfidence * 0.4);
    }

    /**
     * Detect threat category based on patterns
     */
    private function detectThreatCategory(array $requestData): ?string
    {
        $path = strtolower($requestData['path'] ?? '');
        $userAgent = strtolower($requestData['user_agent'] ?? '');
        $query = strtolower($requestData['query'] ?? '');

        $combined = $path . ' ' . $userAgent . ' ' . $query;

        foreach (self::THREAT_CATEGORIES as $category => $data) {
            foreach ($data['patterns'] as $pattern) {
                if (str_contains($combined, $pattern)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * Determine action based on probability and confidence
     */
    private function determineAction(float $probability, float $confidence): string
    {
        // High confidence threat -> immediate block
        if ($probability >= 0.9 && $confidence >= self::HIGH_CONFIDENCE_THRESHOLD) {
            return 'BAN';
        }

        if ($probability >= 0.8 && $confidence >= 0.7) {
            return 'BLOCK';
        }

        if ($probability >= 0.6) {
            return 'CHALLENGE'; // Could trigger CAPTCHA
        }

        if ($probability >= 0.4) {
            return 'MONITOR';
        }

        return 'ALLOW';
    }

    /**
     * Learn from a security event (online learning)
     *
     * @param array $requestData Request data
     * @param bool $isThreat True if this was a confirmed threat
     * @param string $source Source of learning (ban, honeypot, csrf, etc.)
     */
    public function learn(array $requestData, bool $isThreat, string $source = 'auto'): void
    {
        $features = $this->extractFeatures($requestData);

        // Apply decay to existing weights
        $this->applyDecay();

        // Update weights based on this sample
        foreach ($features as $name => $value) {
            if (!isset($this->featureWeights[$name])) {
                $this->featureWeights[$name] = ['threat' => 0.5, 'safe' => 0.5];
            }

            // Update using exponential moving average
            $alpha = 0.1; // Learning rate

            if ($isThreat) {
                $this->featureWeights[$name]['threat'] =
                    (1 - $alpha) * $this->featureWeights[$name]['threat'] + $alpha * $value;
            } else {
                $this->featureWeights[$name]['safe'] =
                    (1 - $alpha) * $this->featureWeights[$name]['safe'] + $alpha * $value;
            }
        }

        // Update sample counts
        $this->totalSamples++;
        if ($isThreat) {
            $this->threatSamples++;
        } else {
            $this->safeSamples++;
        }

        // Persist to Redis
        $this->saveWeights();

        // Log learning event
        Logger::info('ML Classifier: Learned from event', [
            'is_threat' => $isThreat,
            'source' => $source,
            'total_samples' => $this->totalSamples,
            'learning_status' => $this->getLearningStatus(),
        ]);
    }

    /**
     * Apply decay to old patterns (concept drift handling)
     */
    private function applyDecay(): void
    {
        foreach ($this->featureWeights as $name => &$weights) {
            // Move weights towards 0.5 (uncertainty)
            $weights['threat'] = 0.5 + ($weights['threat'] - 0.5) * self::WEIGHT_DECAY;
            $weights['safe'] = 0.5 + ($weights['safe'] - 0.5) * self::WEIGHT_DECAY;
        }
    }

    /**
     * Get current learning status
     */
    public function getLearningStatus(): string
    {
        if ($this->totalSamples < self::MIN_SAMPLES_FOR_LEARNING) {
            return 'warming_up';
        }

        if ($this->totalSamples < self::MIN_SAMPLES_FOR_MATURE) {
            return 'learning';
        }

        return 'mature';
    }

    /**
     * Get model statistics
     */
    public function getStats(): array
    {
        return [
            'total_samples' => $this->totalSamples,
            'threat_samples' => $this->threatSamples,
            'safe_samples' => $this->safeSamples,
            'learning_status' => $this->getLearningStatus(),
            'feature_count' => count($this->featureWeights),
            'threat_ratio' => $this->totalSamples > 0
                ? round($this->threatSamples / $this->totalSamples, 4)
                : 0,
        ];
    }

    /**
     * Export model weights for backup
     */
    public function exportWeights(): array
    {
        return [
            'version' => '1.0.0',
            'exported_at' => date('c'),
            'weights' => $this->featureWeights,
            'stats' => $this->getStats(),
        ];
    }

    /**
     * Import model weights from backup
     */
    public function importWeights(array $data): bool
    {
        if (!isset($data['weights']) || !is_array($data['weights'])) {
            return false;
        }

        $this->featureWeights = $data['weights'];
        $this->totalSamples = $data['stats']['total_samples'] ?? 0;
        $this->threatSamples = $data['stats']['threat_samples'] ?? 0;
        $this->safeSamples = $data['stats']['safe_samples'] ?? 0;

        $this->saveWeights();

        return true;
    }

    // ========================================
    // Feature Detection Methods
    // ========================================

    private function checkSuspiciousUserAgent(string $ua): float
    {
        $scanners = ['sqlmap', 'nikto', 'nmap', 'masscan', 'burp', 'acunetix', 'wpscan', 'dirbuster', 'gobuster', 'ffuf'];

        foreach ($scanners as $scanner) {
            if (str_contains($ua, $scanner)) {
                return 1.0;
            }
        }

        // Suspicious patterns
        if (str_contains($ua, 'curl/') || str_contains($ua, 'wget/')) {
            return 0.4; // Automated but not necessarily malicious
        }

        return 0.0;
    }

    private function checkFakeUserAgent(string $ua): float
    {
        // Impossible browser versions in 2026
        $fakePatterns = [
            'MSIE 9.0', 'MSIE 10.0', 'MSIE 11.0',
            'Chrome/70.', 'Chrome/80.', 'Chrome/90.',
            'Firefox/70.', 'Firefox/80.',
            'Windows NT 5.', 'Windows 98',
        ];

        foreach ($fakePatterns as $pattern) {
            if (str_contains($ua, $pattern)) {
                return 1.0;
            }
        }

        return 0.0;
    }

    private function checkSensitivePath(string $path): float
    {
        $sensitive = ['/.env', '/.git', '/.aws', '/backup', '/dump.sql', '/phpinfo', '/info.php'];

        foreach ($sensitive as $s) {
            if (str_contains($path, $s)) {
                return 1.0;
            }
        }

        return 0.0;
    }

    private function checkCMSPath(string $path): float
    {
        $cms = ['/wp-admin', '/wp-login', '/wp-content', '/administrator', '/phpmyadmin', '/adminer'];

        foreach ($cms as $c) {
            if (str_contains($path, $c)) {
                return 0.9;
            }
        }

        return 0.0;
    }

    private function checkConfigPath(string $path): float
    {
        $config = ['/config.', '/database.', '/settings.', '/credentials', '/secrets'];

        foreach ($config as $c) {
            if (str_contains($path, $c)) {
                return 0.8;
            }
        }

        return 0.0;
    }

    private function checkPathTraversal(string $path): float
    {
        if (str_contains($path, '../') || str_contains($path, '..\\')) {
            return 1.0;
        }

        if (str_contains($path, '%2e%2e') || str_contains($path, '....//')) {
            return 1.0;
        }

        return 0.0;
    }

    private function checkSuspiciousQuery(string $query): float
    {
        $suspicious = ['union', 'select', '1=1', 'or 1', '<script', 'javascript:', 'onerror'];

        foreach ($suspicious as $s) {
            if (str_contains($query, $s)) {
                return 0.9;
            }
        }

        return 0.0;
    }

    private function checkRequestRate(string $ip): float
    {
        if (!$this->redis) {
            return 0.0;
        }

        try {
            $key = self::REDIS_PREFIX . "rate:{$ip}";
            $count = (int) $this->redis->get($key);

            // More than 100 requests in 5 minutes is suspicious
            return min(1.0, $count / 100);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    private function checkErrorRate(string $ip): float
    {
        if (!$this->redis) {
            return 0.0;
        }

        try {
            $key = self::REDIS_PREFIX . "errors:{$ip}";
            $count = (int) $this->redis->get($key);

            // More than 10 404/403 errors in 5 minutes is suspicious
            return min(1.0, $count / 10);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    private function checkEndpointDiversity(string $ip): float
    {
        if (!$this->redis) {
            return 0.0;
        }

        try {
            $key = self::REDIS_PREFIX . "endpoints:{$ip}";
            $count = (int) $this->redis->sCard($key);

            // Hitting more than 20 different endpoints in 5 minutes is suspicious
            return min(1.0, $count / 20);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /**
     * Track request for behavioral analysis
     */
    public function trackRequest(string $ip, string $path, int $statusCode): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $window = 300; // 5 minutes

            // Track request rate
            $rateKey = self::REDIS_PREFIX . "rate:{$ip}";
            $this->redis->incr($rateKey);
            $this->redis->expire($rateKey, $window);

            // Track error rate
            if ($statusCode >= 400) {
                $errorKey = self::REDIS_PREFIX . "errors:{$ip}";
                $this->redis->incr($errorKey);
                $this->redis->expire($errorKey, $window);
            }

            // Track endpoint diversity
            $endpointKey = self::REDIS_PREFIX . "endpoints:{$ip}";
            $this->redis->sAdd($endpointKey, $path);
            $this->redis->expire($endpointKey, $window);
        } catch (\Exception $e) {
            // Silent fail - tracking is non-critical
        }
    }
}
