<?php

namespace Need2Talk\Services\Security;

use Need2Talk\Services\Logger;

/**
 * ENTERPRISE GALAXY: Advanced ML Threat Detection Engine
 *
 * Sistema ML avanzato con:
 * - Training da log storici
 * - Multiple threat categories
 * - Configurazione parametri runtime
 * - Statistiche real-time
 * - Model versioning e backup
 * - Ensemble di classificatori
 *
 * ALGORITMI:
 * 1. Naive Bayes (veloce, baseline)
 * 2. Pattern Frequency (n-gram su path/UA)
 * 3. Behavioral Scoring (rate, errors, diversity)
 * 4. Temporal Analysis (ora del giorno, burst detection)
 *
 * CONFIGURAZIONE (Redis key: ml_threat:config):
 * - ml_enabled: bool (default: true)
 * - ml_weight: float 0-1 (default: 0.4)
 * - learning_rate: float (default: 0.1)
 * - decay_factor: float (default: 0.995)
 * - min_confidence: float (default: 0.7)
 * - auto_learn: bool (default: true)
 * - block_threshold: float (default: 0.75)
 * - ban_threshold: float (default: 0.90)
 *
 * @version 2.0.0
 */
class AdvancedMLThreatEngine
{
    private const REDIS_PREFIX = 'ml_threat:';
    private const CONFIG_KEY = 'ml_threat:config';
    private const STATS_KEY = 'ml_threat:stats';
    private const MODEL_VERSION = '2.1.0';
    private const REDIS_DB = 3;

    private ?\Redis $redis = null;

    // Default configuration
    private array $config = [
        'ml_enabled' => true,
        'ml_weight' => 0.4,           // 40% ML, 60% rules
        'learning_rate' => 0.1,
        'decay_factor' => 0.995,
        'min_confidence' => 0.7,
        'auto_learn' => true,
        'block_threshold' => 0.75,
        'ban_threshold' => 0.90,
        'high_confidence_override' => true,
        'high_confidence_threshold' => 0.85,
    ];

    // Model weights
    private array $featureWeights = [];
    private array $categoryWeights = [];
    private array $patternFrequencies = [];

    // Statistics
    private array $stats = [
        'total_requests' => 0,
        'threats_detected' => 0,
        'false_positives' => 0,
        'true_positives' => 0,
        'training_samples' => 0,
        'last_training' => null,
        'last_decay' => 0,           // ENTERPRISE v6.7: Track periodic decay timing
        'model_version' => self::MODEL_VERSION,
        'categories' => [],
    ];

    /**
     * Threat categories with their signatures
     */
    private const THREAT_CATEGORIES = [
        'SCANNER' => [
            'weight' => 1.0,
            'signatures' => ['sqlmap', 'nikto', 'nmap', 'masscan', 'burp', 'acunetix', 'censys', 'shodan'],
            'paths' => [],
        ],
        'CMS_PROBE' => [
            'weight' => 0.9,
            'signatures' => [],
            'paths' => ['/wp-admin', '/wp-login', '/wp-includes', '/phpmyadmin', '/administrator', '/adminer'],
        ],
        'CREDENTIAL_THEFT' => [
            'weight' => 1.0,
            'signatures' => [],
            'paths' => ['/.env', '/.aws', '/.git', '/credentials', '/secrets', '/.ssh'],
        ],
        'BRUTE_FORCE' => [
            'weight' => 0.95,
            'signatures' => [],
            'paths' => ['/auth/login', '/api/auth'],
            'behavioral' => ['high_login_failures', 'rapid_requests'],
        ],
        'BOT_SPOOFING' => [
            'weight' => 1.0,
            'signatures' => ['facebookexternalhit', 'twitterbot', 'googlebot'],
            'paths' => [],
            'requires_dns_check' => true,
        ],
        'PATH_TRAVERSAL' => [
            'weight' => 1.0,
            'signatures' => ['../', '%2e%2e', '....//'],
            'paths' => [],
        ],
        'NULL_UA' => [
            'weight' => 1.0,
            'signatures' => [],
            'paths' => [],
            'behavioral' => ['empty_user_agent'],
        ],
        'FAKE_BROWSER' => [
            'weight' => 0.9,
            'signatures' => ['MSIE 9', 'MSIE 10', 'Chrome/70', 'Chrome/80', 'Windows NT 5'],
            'paths' => [],
        ],
        'CSRF_ATTACK' => [
            'weight' => 0.8,
            'signatures' => [],
            'paths' => [],
            'behavioral' => ['csrf_failure', 'missing_referer'],
        ],
    ];

    /**
     * Extended feature set (25 features)
     */
    private const FEATURES = [
        // User-Agent features
        'ua_is_scanner',
        'ua_is_empty',
        'ua_is_fake',
        'ua_is_bot',
        'ua_has_automation',
        'ua_version_impossible',

        // Path features
        'path_is_sensitive',
        'path_is_cms',
        'path_is_config',
        'path_has_traversal',
        'path_has_extension_php',
        'path_has_backup_ext',

        // Behavioral features
        'rate_high',
        'rate_burst',
        'error_rate_high',
        'endpoint_diversity_high',
        'login_failures',
        'csrf_failures',

        // Request features
        'method_unusual',
        'referer_missing_on_post',
        'query_suspicious',
        'content_type_wrong',

        // Temporal features
        'hour_suspicious',
        'weekend_request',
        'request_interval_regular',
    ];

    public function __construct()
    {
        $this->initRedis();
        $this->loadConfig();
        $this->loadModel();
        $this->loadStats();
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
            Logger::security('error', 'Advanced ML Engine: Redis connection failed', [
                'error' => $e->getMessage(),
            ]);
            $this->redis = null;

            return false;
        }
    }

    /**
     * Load configuration from Redis
     */
    private function loadConfig(): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $configJson = $this->redis->get(self::CONFIG_KEY);
            if ($configJson) {
                $savedConfig = json_decode($configJson, true);
                $this->config = array_merge($this->config, $savedConfig);
            }
        } catch (\Exception $e) {
            // Use defaults
        }
    }

    /**
     * Save configuration to Redis
     */
    public function saveConfig(): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $this->redis->set(self::CONFIG_KEY, json_encode($this->config));
        } catch (\Exception $e) {
            Logger::security('error', 'Failed to save ML config', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Update configuration parameter
     */
    public function setConfig(string $key, $value): bool
    {
        if (!array_key_exists($key, $this->config)) {
            return false;
        }

        $this->config[$key] = $value;
        $this->saveConfig();

        Logger::security('warning', 'ML Config updated', ['key' => $key, 'value' => $value]);

        return true;
    }

    /**
     * Load model weights from Redis
     */
    private function loadModel(): void
    {
        if (!$this->redis) {
            $this->initializeDefaultModel();
            return;
        }

        try {
            $modelJson = $this->redis->get(self::REDIS_PREFIX . 'model');
            if ($modelJson) {
                $model = json_decode($modelJson, true);
                $this->featureWeights = $model['feature_weights'] ?? [];
                $this->categoryWeights = $model['category_weights'] ?? [];
                $this->patternFrequencies = $model['pattern_frequencies'] ?? [];
            } else {
                $this->initializeDefaultModel();
            }
        } catch (\Exception $e) {
            $this->initializeDefaultModel();
        }
    }

    /**
     * Initialize default model weights
     *
     * ENTERPRISE v6.7: Enhanced default weights based on security best practices
     * These provide strong initial detection even before training
     */
    private function initializeDefaultModel(): void
    {
        // Feature weights (prior probabilities)
        foreach (self::FEATURES as $feature) {
            $this->featureWeights[$feature] = [
                'threat' => 0.5,
                'safe' => 0.5,
                'count' => 0,
            ];
        }

        // ENTERPRISE v6.7: Comprehensive high-risk features with security-informed priors
        // These weights are based on real-world attack patterns observed in production
        $highRiskFeatures = [
            // User-Agent features (critical for scanner detection)
            'ua_is_scanner' => 0.98,         // sqlmap, nikto, etc. = almost certain threat
            'ua_is_empty' => 0.92,           // Empty UA = very suspicious
            'ua_is_fake' => 0.90,            // Fake/impossible browser versions
            'ua_is_bot' => 0.65,             // Bots can be legitimate or malicious
            'ua_has_automation' => 0.85,     // curl, python-requests, etc.
            'ua_version_impossible' => 0.88, // Chrome/1.0, etc.

            // Path features (critical for attack detection)
            'path_has_traversal' => 0.99,    // ../ = almost certain attack
            'path_is_sensitive' => 0.92,     // .env, .git, etc.
            'path_is_cms' => 0.80,           // wp-admin, phpmyadmin probes
            'path_is_config' => 0.90,        // config.php, settings.php
            'path_has_backup_ext' => 0.88,   // .bak, .old, .sql

            // Behavioral features
            // ENTERPRISE v6.9 RECALIBRATION: Lowered behavioral weights
            // Behavioral signals alone are weak indicators — they only become meaningful
            // when combined with other features (suspicious UA, sensitive paths, etc.)
            // A high rate alone just means an active SPA user, NOT a threat.
            'rate_high' => 0.45,             // High request rate (was 0.75 — too aggressive for SPA)
            'rate_burst' => 0.50,            // Burst patterns (was 0.85 — SPA page loads are bursts)
            'error_rate_high' => 0.75,       // Many 404s = still strong scanner signal
            'endpoint_diversity_high' => 0.40, // Many endpoints (was 0.78 — SPA navigation is diverse)
            'login_failures' => 0.82,        // Multiple login failures = brute force (unchanged)
            'csrf_failures' => 0.85,         // CSRF failures = attack tool (unchanged)

            // Request features
            'method_unusual' => 0.70,        // PUT/DELETE/TRACE on non-API routes
            'referer_missing_on_post' => 0.60, // Missing referer on POST = suspicious
            'query_suspicious' => 0.88,      // SQL injection, XSS patterns

            // Temporal features (less indicative alone)
            'hour_suspicious' => 0.55,       // 2-5 AM = slightly more suspicious
            'request_interval_regular' => 0.72, // Robot-like timing
        ];

        foreach ($highRiskFeatures as $feature => $threatProb) {
            if (isset($this->featureWeights[$feature])) {
                $this->featureWeights[$feature]['threat'] = $threatProb;
                $this->featureWeights[$feature]['safe'] = 1 - $threatProb;
            }
        }

        // Category weights
        foreach (self::THREAT_CATEGORIES as $category => $data) {
            $this->categoryWeights[$category] = [
                'base_weight' => $data['weight'],
                'learned_weight' => $data['weight'],
                'detections' => 0,
            ];
        }

        // Pattern frequencies (empty initially)
        $this->patternFrequencies = [
            'threat_paths' => [],
            'threat_uas' => [],
            'safe_paths' => [],
            'safe_uas' => [],
        ];
    }

    /**
     * Save model to Redis
     */
    private function saveModel(): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $model = [
                'version' => self::MODEL_VERSION,
                'updated_at' => time(),
                'feature_weights' => $this->featureWeights,
                'category_weights' => $this->categoryWeights,
                'pattern_frequencies' => $this->patternFrequencies,
            ];

            $this->redis->set(
                self::REDIS_PREFIX . 'model',
                json_encode($model),
                ['ex' => 86400 * 30] // 30 days
            );
        } catch (\Exception $e) {
            Logger::security('error', 'Failed to save ML model', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Load statistics
     */
    private function loadStats(): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $statsJson = $this->redis->get(self::STATS_KEY);
            if ($statsJson) {
                $this->stats = array_merge($this->stats, json_decode($statsJson, true));
            }
        } catch (\Exception $e) {
            // Use defaults
        }
    }

    /**
     * Save statistics
     */
    private function saveStats(): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $this->redis->set(self::STATS_KEY, json_encode($this->stats));
        } catch (\Exception $e) {
            // Silent fail
        }
    }

    /**
     * Analyze a request and return comprehensive threat assessment
     */
    public function analyze(array $requestData): array
    {
        if (!$this->config['ml_enabled']) {
            return [
                'ml_enabled' => false,
                'score' => 0,
                'action' => 'ALLOW',
            ];
        }

        $this->stats['total_requests']++;

        // Extract all features
        $features = $this->extractFeatures($requestData);

        // Calculate probability using Naive Bayes
        $probability = $this->calculateProbability($features);

        // Detect threat category
        $category = $this->detectCategory($requestData, $features);

        // Calculate confidence
        $confidence = $this->calculateConfidence($features, $category);

        // Determine action
        $action = $this->determineAction($probability, $confidence, $category);

        // Update stats
        if ($probability >= $this->config['block_threshold']) {
            $this->stats['threats_detected']++;
            if ($category) {
                $this->stats['categories'][$category] = ($this->stats['categories'][$category] ?? 0) + 1;
            }
        }

        $this->saveStats();

        return [
            'ml_enabled' => true,
            'score' => round($probability, 4),
            'confidence' => round($confidence, 4),
            'category' => $category,
            'action' => $action,
            'features' => $features,
            'config' => [
                'ml_weight' => $this->config['ml_weight'],
                'block_threshold' => $this->config['block_threshold'],
                'ban_threshold' => $this->config['ban_threshold'],
            ],
            'should_learn' => $this->config['auto_learn'] && in_array($action, ['BLOCK', 'BAN']),
        ];
    }

    /**
     * Extract all 25 features from request
     */
    private function extractFeatures(array $data): array
    {
        $ip = $data['ip'] ?? '';
        $path = strtolower($data['path'] ?? '/');
        $ua = strtolower($data['user_agent'] ?? '');
        $method = strtoupper($data['method'] ?? 'GET');
        $query = strtolower($data['query'] ?? '');
        $referer = $data['referer'] ?? '';

        $features = [];

        // User-Agent features
        $features['ua_is_scanner'] = $this->checkScanner($ua);
        $features['ua_is_empty'] = empty($ua) ? 1.0 : 0.0;
        $features['ua_is_fake'] = $this->checkFakeUA($ua);
        $features['ua_is_bot'] = $this->checkBot($ua);
        $features['ua_has_automation'] = $this->checkAutomation($ua);
        $features['ua_version_impossible'] = $this->checkImpossibleVersion($ua);

        // Path features
        $features['path_is_sensitive'] = $this->checkSensitivePath($path);
        $features['path_is_cms'] = $this->checkCMSPath($path);
        $features['path_is_config'] = $this->checkConfigPath($path);
        $features['path_has_traversal'] = $this->checkTraversal($path);
        $features['path_has_extension_php'] = str_ends_with($path, '.php') ? 0.3 : 0.0;
        $features['path_has_backup_ext'] = $this->checkBackupExtension($path);

        // Behavioral features (from Redis)
        $features['rate_high'] = $this->getRequestRate($ip);
        $features['rate_burst'] = $this->detectBurst($ip);
        $features['error_rate_high'] = $this->getErrorRate($ip);
        $features['endpoint_diversity_high'] = $this->getEndpointDiversity($ip);
        $features['login_failures'] = $this->getLoginFailures($ip);
        $features['csrf_failures'] = $this->getCSRFFailures($ip);

        // Request features
        $features['method_unusual'] = in_array($method, ['PUT', 'DELETE', 'PATCH', 'TRACE']) ? 0.5 : 0.0;
        $features['referer_missing_on_post'] = ($method === 'POST' && empty($referer)) ? 0.4 : 0.0;
        $features['query_suspicious'] = $this->checkSuspiciousQuery($query);
        $features['content_type_wrong'] = 0.0; // TODO: implement

        // Temporal features
        $hour = (int) date('H');
        $features['hour_suspicious'] = ($hour >= 2 && $hour <= 5) ? 0.3 : 0.0;
        $features['weekend_request'] = (date('N') >= 6) ? 0.1 : 0.0;
        $features['request_interval_regular'] = $this->checkRegularInterval($ip);

        return $features;
    }

    /**
     * Calculate threat probability using weighted feature scoring
     *
     * ENTERPRISE v6.7 FIX: Changed from Naive Bayes to weighted scoring
     *
     * RATIONALE:
     * Naive Bayes assumes feature independence and penalizes absence of features.
     * This is wrong for security: absence of "ua_is_scanner" shouldn't make
     * a request LESS likely to be a threat (most attacks don't use known scanners).
     *
     * NEW ALGORITHM:
     * 1. Only ACTIVE features (value > 0) contribute to threat score
     * 2. Score = sum(feature_value * feature_threat_weight) / sum(active_weights)
     * 3. This gives a normalized 0-1 threat probability based on what IS present
     */
    private function calculateProbability(array $features): float
    {
        $threatScore = 0.0;
        $totalWeight = 0.0;
        $activeFeatureCount = 0;

        foreach ($features as $name => $value) {
            if (!isset($this->featureWeights[$name])) {
                continue;
            }

            // Only consider active features (value > 0.1 threshold)
            if ($value < 0.1) {
                continue;
            }

            $weights = $this->featureWeights[$name];
            $threatWeight = $weights['threat'];

            // Contribution = feature_activation * threat_weight
            $contribution = $value * $threatWeight;
            $threatScore += $contribution;
            $totalWeight += $threatWeight;
            $activeFeatureCount++;
        }

        // No active features = no threat signal
        if ($activeFeatureCount === 0 || $totalWeight < 0.01) {
            return 0.0;
        }

        // Normalize to 0-1 range
        // Average threat contribution across active features
        $normalizedScore = $threatScore / $totalWeight;

        // Apply confidence boost for multiple active features
        // More active threat features = higher confidence
        $featureBoost = min(1.0, $activeFeatureCount / 3.0); // Max boost at 3+ features
        $finalScore = $normalizedScore * (0.7 + 0.3 * $featureBoost);

        return max(0.0, min(1.0, $finalScore));
    }

    /**
     * Detect threat category
     */
    private function detectCategory(array $data, array $features): ?string
    {
        $path = strtolower($data['path'] ?? '');
        $ua = strtolower($data['user_agent'] ?? '');

        $scores = [];

        foreach (self::THREAT_CATEGORIES as $category => $config) {
            $score = 0;

            // Check signatures in UA
            foreach ($config['signatures'] as $sig) {
                if (str_contains($ua, strtolower($sig))) {
                    $score += 0.5;
                }
            }

            // Check paths
            foreach ($config['paths'] as $p) {
                if (str_contains($path, $p)) {
                    $score += 0.5;
                }
            }

            // Check behavioral triggers
            if (isset($config['behavioral'])) {
                foreach ($config['behavioral'] as $behavior) {
                    $featureKey = $this->mapBehaviorToFeature($behavior);
                    if ($featureKey && ($features[$featureKey] ?? 0) > 0.5) {
                        $score += 0.3;
                    }
                }
            }

            if ($score > 0) {
                $scores[$category] = $score * $config['weight'];
            }
        }

        if (empty($scores)) {
            return null;
        }

        arsort($scores);

        return array_key_first($scores);
    }

    /**
     * Map behavioral trigger to feature name
     */
    private function mapBehaviorToFeature(string $behavior): ?string
    {
        $map = [
            'high_login_failures' => 'login_failures',
            'rapid_requests' => 'rate_burst',
            'empty_user_agent' => 'ua_is_empty',
            'csrf_failure' => 'csrf_failures',
            'missing_referer' => 'referer_missing_on_post',
        ];

        return $map[$behavior] ?? null;
    }

    /**
     * Calculate confidence based on feature activation and training
     */
    private function calculateConfidence(array $features, ?string $category): float
    {
        // Base confidence from training
        $trainConfidence = min(1.0, $this->stats['training_samples'] / 500);

        // Feature activation confidence
        $activeCount = count(array_filter($features, fn ($v) => $v > 0.5));
        $featureConfidence = min(1.0, $activeCount / 5);

        // Category confidence
        $categoryConfidence = $category ? 0.2 : 0.0;

        return ($trainConfidence * 0.4) + ($featureConfidence * 0.4) + ($categoryConfidence * 0.2);
    }

    /**
     * Determine action based on score and config
     */
    private function determineAction(float $probability, float $confidence, ?string $category): string
    {
        // High confidence override
        if (
            $this->config['high_confidence_override'] &&
            $confidence >= $this->config['high_confidence_threshold'] &&
            $probability >= $this->config['ban_threshold']
        ) {
            return 'BAN';
        }

        if ($probability >= $this->config['ban_threshold']) {
            return 'BAN';
        }

        if ($probability >= $this->config['block_threshold']) {
            return 'BLOCK';
        }

        if ($probability >= 0.5) {
            return 'CHALLENGE';
        }

        if ($probability >= 0.3) {
            return 'MONITOR';
        }

        return 'ALLOW';
    }

    /**
     * Learn from a confirmed threat or safe request
     *
     * ENTERPRISE v6.7 FIX: Decay is now applied PERIODICALLY (hourly) instead of per-sample
     * This prevents weight degradation to 0 after thousands of samples.
     *
     * ALGORITHM:
     * 1. Extract features from request
     * 2. Update weights using exponential moving average (EMA)
     * 3. Apply decay only if 1 hour has passed since last decay
     * 4. Save model and stats
     */
    public function learn(array $requestData, bool $isThreat, string $source = 'auto'): void
    {
        if (!$this->config['auto_learn'] && $source === 'auto') {
            return;
        }

        $features = $this->extractFeatures($requestData);
        $alpha = $this->config['learning_rate'];

        // ENTERPRISE v6.7 FIX: Apply decay PERIODICALLY (every hour) instead of per-sample
        // This prevents weight collapse to 0.5 after thousands of samples
        $this->applyPeriodicDecay();

        // Update weights from this sample using EMA (Exponential Moving Average)
        foreach ($features as $name => $value) {
            if (!isset($this->featureWeights[$name])) {
                continue;
            }

            if ($isThreat) {
                // For threats: increase threat weight proportional to feature activation
                // EMA formula: new_weight = (1 - alpha) * old_weight + alpha * new_value
                $this->featureWeights[$name]['threat'] =
                    (1 - $alpha) * $this->featureWeights[$name]['threat'] + $alpha * max(0.1, $value);
            } else {
                // For safe requests: increase safe weight
                $this->featureWeights[$name]['safe'] =
                    (1 - $alpha) * $this->featureWeights[$name]['safe'] + $alpha * max(0.1, $value);
            }

            // Clamp weights to valid range [0.01, 0.99]
            $this->featureWeights[$name]['threat'] = max(0.01, min(0.99, $this->featureWeights[$name]['threat']));
            $this->featureWeights[$name]['safe'] = max(0.01, min(0.99, $this->featureWeights[$name]['safe']));

            $this->featureWeights[$name]['count']++;
        }

        // Update pattern frequencies
        $path = $requestData['path'] ?? '';
        $ua = $requestData['user_agent'] ?? '';

        if ($isThreat) {
            $this->updatePatternFrequency('threat_paths', $path);
            $this->updatePatternFrequency('threat_uas', $ua);
            $this->stats['true_positives']++;
        } else {
            $this->updatePatternFrequency('safe_paths', $path);
            $this->updatePatternFrequency('safe_uas', $ua);
        }

        $this->stats['training_samples']++;
        $this->stats['last_training'] = time();

        $this->saveModel();
        $this->saveStats();
    }

    /**
     * Apply decay periodically (every hour) to prevent stale weights
     *
     * ENTERPRISE v6.7: Moved decay from per-sample to periodic application
     * This allows weights to stabilize and actually learn patterns
     */
    private function applyPeriodicDecay(): void
    {
        $decayInterval = 3600; // 1 hour in seconds
        $lastDecay = $this->stats['last_decay'] ?? 0;
        $now = time();

        if (($now - $lastDecay) < $decayInterval) {
            return; // Not time for decay yet
        }

        $decay = $this->config['decay_factor'];

        // Apply gentle decay toward 0.5 (neutral)
        foreach ($this->featureWeights as $name => &$weights) {
            $weights['threat'] = 0.5 + ($weights['threat'] - 0.5) * $decay;
            $weights['safe'] = 0.5 + ($weights['safe'] - 0.5) * $decay;
        }

        $this->stats['last_decay'] = $now;
    }

    /**
     * Update pattern frequency
     */
    private function updatePatternFrequency(string $type, string $pattern): void
    {
        if (empty($pattern)) {
            return;
        }

        // Limit to 1000 patterns per type
        if (!isset($this->patternFrequencies[$type])) {
            $this->patternFrequencies[$type] = [];
        }

        $key = md5($pattern);
        $this->patternFrequencies[$type][$key] = ($this->patternFrequencies[$type][$key] ?? 0) + 1;

        // Prune if too large
        if (count($this->patternFrequencies[$type]) > 1000) {
            arsort($this->patternFrequencies[$type]);
            $this->patternFrequencies[$type] = array_slice($this->patternFrequencies[$type], 0, 800, true);
        }
    }

    /**
     * Train model from historical security logs
     */
    public function trainFromLogs(array $logEntries): array
    {
        $trained = 0;
        $errors = 0;

        foreach ($logEntries as $entry) {
            try {
                $requestData = $this->parseLogEntry($entry);
                if (!$requestData) {
                    continue;
                }

                $isThreat = $this->classifyLogEntry($entry);
                $this->learn($requestData, $isThreat, 'historical');
                $trained++;
            } catch (\Exception $e) {
                $errors++;
            }
        }

        Logger::security('warning', 'ML Engine: Historical training completed', [
            'trained' => $trained,
            'errors' => $errors,
            'total_samples' => $this->stats['training_samples'],
        ]);

        return [
            'trained' => $trained,
            'errors' => $errors,
            'total_samples' => $this->stats['training_samples'],
        ];
    }

    /**
     * Parse a log entry into request data
     */
    private function parseLogEntry(string $entry): ?array
    {
        // Extract JSON from log entry
        if (preg_match('/\{.*\}/', $entry, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json) {
                return [
                    'ip' => $json['ip'] ?? '',
                    'path' => $json['path'] ?? $json['uri'] ?? '/',
                    'user_agent' => $json['user_agent'] ?? '',
                    'method' => $json['method'] ?? 'GET',
                    'query' => '',
                    'referer' => $json['referer'] ?? '',
                ];
            }
        }

        return null;
    }

    /**
     * Classify a log entry as threat or safe
     */
    private function classifyLogEntry(string $entry): bool
    {
        // Threat indicators in log
        $threatIndicators = [
            'CRITICAL',
            'banned',
            'BANNED',
            'blocked',
            'BLOCKED',
            'HONEYPOT',
            'vulnerability scanning',
            'fake_user_agent',
            'null_user_agent',
            'bot_spoofing',
            'scanner_user_agent',
            'critical_path',
        ];

        foreach ($threatIndicators as $indicator) {
            if (str_contains($entry, $indicator)) {
                return true;
            }
        }

        // Safe indicators
        $safeIndicators = [
            'Login initiated',
            '2FA verification successful',
            'ADMIN: Session',
        ];

        foreach ($safeIndicators as $indicator) {
            if (str_contains($entry, $indicator)) {
                return false;
            }
        }

        // Default: WARNING level logs are suspicious but not confirmed threats
        if (str_contains($entry, 'WARNING')) {
            return false; // Don't learn from ambiguous
        }

        return str_contains($entry, 'ERROR');
    }

    /**
     * Get model statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'model_version' => self::MODEL_VERSION,
            'feature_count' => count($this->featureWeights),
            'category_count' => count(self::THREAT_CATEGORIES),
            'config' => $this->config,
            'learning_status' => $this->getLearningStatus(),
        ]);
    }

    /**
     * Get learning status
     */
    public function getLearningStatus(): string
    {
        $samples = $this->stats['training_samples'];

        if ($samples < 50) {
            return 'warming_up';
        }
        if ($samples < 200) {
            return 'learning';
        }
        if ($samples < 500) {
            return 'improving';
        }

        return 'mature';
    }

    /**
     * Export model for backup
     */
    public function exportModel(): array
    {
        return [
            'version' => self::MODEL_VERSION,
            'exported_at' => date('c'),
            'feature_weights' => $this->featureWeights,
            'category_weights' => $this->categoryWeights,
            'pattern_frequencies' => $this->patternFrequencies,
            'stats' => $this->stats,
            'config' => $this->config,
        ];
    }

    /**
     * Import model from backup
     */
    public function importModel(array $data): bool
    {
        if (!isset($data['feature_weights'])) {
            return false;
        }

        $this->featureWeights = $data['feature_weights'];
        $this->categoryWeights = $data['category_weights'] ?? [];
        $this->patternFrequencies = $data['pattern_frequencies'] ?? [];

        if (isset($data['stats'])) {
            $this->stats = array_merge($this->stats, $data['stats']);
        }

        $this->saveModel();
        $this->saveStats();

        return true;
    }

    /**
     * Reset model to defaults
     */
    public function resetModel(): void
    {
        $this->initializeDefaultModel();
        $this->stats = [
            'total_requests' => 0,
            'threats_detected' => 0,
            'false_positives' => 0,
            'true_positives' => 0,
            'training_samples' => 0,
            'last_training' => null,
            'last_decay' => 0,
            'model_version' => self::MODEL_VERSION,
            'categories' => [],
        ];

        $this->saveModel();
        $this->saveStats();

        Logger::security('warning', 'ML Engine: Model reset to defaults with enhanced v6.7 weights');
    }

    /**
     * Train model from database historical data
     *
     * Sources:
     * 1. security_events table (587+ records) - Contains all security events
     * 2. vulnerability_scan_bans table (103+ records) - Confirmed malicious IPs
     * 3. Log files (parsed for additional context)
     *
     * @param \PDO $pdo Database connection
     * @return array Training results
     */
    public function trainFromDatabase(object $pdo): array
    {
        $results = [
            'security_events' => ['trained' => 0, 'errors' => 0],
            'vulnerability_bans' => ['trained' => 0, 'errors' => 0],
            'total_trained' => 0,
            'total_errors' => 0,
            'previous_samples' => $this->stats['training_samples'],
        ];

        // 1. Train from vulnerability_scan_bans (confirmed threats)
        // Columns: ip_address, user_agent, paths_accessed (JSON), scan_patterns (JSON),
        //          request_method, referer, score, banned_at
        try {
            $stmt = $pdo->query("
                SELECT ip_address, user_agent, paths_accessed, scan_patterns,
                       request_method, referer, score, banned_at
                FROM vulnerability_scan_bans
                ORDER BY banned_at ASC
            ");

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                try {
                    // Parse paths_accessed JSON array to get first path
                    $paths = json_decode($row['paths_accessed'] ?? '[]', true);
                    $path = is_array($paths) && !empty($paths) ? $paths[0] : '/';

                    $requestData = [
                        'ip' => $row['ip_address'] ?? '',
                        'user_agent' => $row['user_agent'] ?? '',
                        'path' => $path,
                        'method' => $row['request_method'] ?? 'GET',
                        'query' => '',
                        'referer' => $row['referer'] ?? '',
                    ];

                    // All bans are confirmed threats
                    $this->learn($requestData, true, 'database_ban');
                    $results['vulnerability_bans']['trained']++;
                } catch (\Exception $e) {
                    $results['vulnerability_bans']['errors']++;
                }
            }
        } catch (\Exception $e) {
            Logger::security('error', 'ML Training: Failed to query vulnerability_scan_bans', [
                'error' => $e->getMessage(),
            ]);
        }

        // 2. Train from security_events (mixed threats/safe — noise filtered by classifier)
        $skippedNoise = 0;
        try {
            $stmt = $pdo->query("
                SELECT channel, level, message, context, ip_address, user_agent, created_at
                FROM security_events
                ORDER BY created_at ASC
            ");

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                try {
                    $context = json_decode($row['context'] ?? '{}', true);

                    // Classify: true=threat, false=safe, null=skip (noise)
                    $classification = $this->classifySecurityEvent($row);

                    if ($classification === null) {
                        $skippedNoise++;
                        continue; // Skip noisy records — don't contaminate the model
                    }

                    $requestData = [
                        'ip' => $row['ip_address'] ?? '',
                        'user_agent' => $row['user_agent'] ?? '',
                        'path' => $context['request_uri'] ?? $context['path'] ?? $context['uri'] ?? '/',
                        'method' => $context['http_method'] ?? $context['method'] ?? 'GET',
                        'query' => $context['query_string'] ?? '',
                        'referer' => $context['referer'] ?? '',
                    ];

                    $this->learn($requestData, $classification, 'database_event');
                    $results['security_events']['trained']++;
                } catch (\Exception $e) {
                    $results['security_events']['errors']++;
                }
            }
        } catch (\Exception $e) {
            Logger::security('error', 'ML Training: Failed to query security_events', [
                'error' => $e->getMessage(),
            ]);
        }

        // Calculate totals
        $results['total_trained'] = $results['vulnerability_bans']['trained']
                                  + $results['security_events']['trained'];
        $results['total_errors'] = $results['vulnerability_bans']['errors']
                                 + $results['security_events']['errors'];
        $results['skipped_noise'] = $skippedNoise;
        $results['new_samples'] = $this->stats['training_samples'];

        Logger::security('warning', 'ML Engine: Database training completed', $results);

        return $results;
    }

    /**
     * Classify a security event record as threat or safe for ML training.
     *
     * ENTERPRISE v2.2: Noise-aware classifier.
     * Approach: SKIP noise first → classify definite threats → classify safe → default safe.
     *
     * Returns: true = threat, false = safe, null = SKIP (don't train on this record)
     *
     * @return bool|null true=threat, false=safe, null=skip (noise)
     */
    private function classifySecurityEvent(array $row): ?bool
    {
        $message = strtolower($row['message'] ?? '');
        $level = strtolower($row['level'] ?? 'info');
        $userAgent = strtolower($row['user_agent'] ?? '');

        $context = json_decode($row['context'] ?? '{}', true);
        $eventType = strtolower($context['event_type'] ?? '');

        // =====================================================================
        // PHASE 0: SKIP NOISE — Events that teach nothing useful to the model
        // =====================================================================

        // ML meta-events (self-referential — training on training data = feedback loop)
        if (str_contains($message, 'ml learning') || str_contains($message, 'ml engine')) {
            return null;
        }

        // Our own internal bots (not threats, not real users — just noise)
        if (str_contains($userAgent, 'need2talk-cachewarmup')
            || str_contains($userAgent, 'need2talk-healthcheck')
            || str_contains($userAgent, 'need2talk-cron')
            || str_contains($message, 'cachewarmup')
        ) {
            return null;
        }

        // Legitimate bot false positives (OpenAI bots from IPs not yet in our list)
        if (str_contains($message, 'openai bot ua from non-openai ip')
            || str_contains($message, 'bot ua from non-official ip')
            || str_contains($message, 'bot verified')
            || str_contains($message, 'legitimate bot')
        ) {
            return null;
        }

        // Application errors (not attacks — DB errors, PHP errors, network errors)
        if (str_contains($message, 'critical_error')
            || str_contains($message, 'network_error')
            || str_contains($message, 'password change failed')
            || str_contains($message, 'database connection')
        ) {
            return null;
        }

        // User operations (normal business logic, not security events)
        if (str_contains($message, 'account deletion scheduled')
            || str_contains($message, 'account hard deleted')
            || str_contains($message, 'cookie_consent')
            || str_contains($message, 'consents already adopted')
            || str_contains($message, 'anonymous consents adopted')
            || str_contains($message, 'email change')
            || str_contains($message, 'duplicate verification')
        ) {
            return null;
        }

        // In-app browser false positives (WhatsApp/Instagram with bot UA from user IP)
        if (str_contains($message, 'in-app browser')
            || str_contains($message, 'likely in-app')
        ) {
            return null;
        }

        // Generic "threat detected" from old ML v1 with bad calibration — too noisy
        // These were the 1420 records from before v2.1 recalibration
        if ($message === 'security shield: threat detected') {
            // Only trust if score was high enough to actually ban
            $score = (float)($context['score'] ?? $context['combined_score'] ?? 0);
            if ($score < 0.7) {
                return null; // Low-confidence old detection = noise
            }
            // High-score detections are probably real threats
            return true;
        }

        // =====================================================================
        // PHASE 1: DEFINITE THREATS — High-confidence attack signals
        // =====================================================================

        // Event type based (from context JSON)
        $threatEventTypes = [
            'vulnerability_scan', 'scanner_detected', 'honeypot_hit', 'honeypot',
            'brute_force', 'sql_injection', 'xss_attempt', 'path_traversal',
            'csrf_attack', 'bot_spoofing', 'fake_user_agent', 'null_user_agent',
            'credential_theft', 'critical_path',
        ];

        foreach ($threatEventTypes as $type) {
            if (str_contains($eventType, $type)) {
                return true;
            }
        }

        // Message-based threats (patterns that appear in our actual log messages)
        $threatMessagePatterns = [
            'honeypot',          // Honeypot trap triggered
            'bot spoofing',      // Fake bot user-agent detected
            'null user-agent',   // No UA = automated tool
            'ip automatically banned',  // Auto-ban triggered
            'request blocked - banned', // Blocked by ban list
            'suspicious scanning',      // Multi-404 scanning
            'scanner_user_agent',       // Known scanner UA
            'cms_scan',                 // WordPress/CMS probing
            'critical_path',            // Sensitive path access
            'exploit',                  // Exploit attempt
            'injection',                // SQL/code injection
            'traversal',                // Path traversal
            'malicious',                // Malicious activity
        ];

        foreach ($threatMessagePatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        // SUSPICIOUS_URI and SUSPICIOUS_USER_AGENT from REQUEST_THREAT
        if (str_contains($message, 'request_threat: suspicious_uri')
            || str_contains($message, 'request_threat: suspicious_user_agent')
        ) {
            return true;
        }

        // CSRF failures (real attack attempts, not expired tokens)
        // Only classify as threat if NOT from a known Italian IP (could be user with stale tab)
        if (str_contains($message, 'csrf validation failed') || str_contains($message, 'csrf failure')) {
            $ip = $row['ip_address'] ?? '';
            $countryCode = $context['country_code'] ?? '';
            // Foreign IP + CSRF = likely automated attack
            if ($countryCode !== 'IT' && $countryCode !== '') {
                return true;
            }
            // Italian IP CSRF — ambiguous, skip to avoid noise
            return null;
        }

        // =====================================================================
        // PHASE 2: DEFINITE SAFE — Known non-threat patterns
        // =====================================================================

        $safePatterns = [
            'login_success', 'login_initiated', '2fa_success', 'session_created',
            'admin_access', 'api_success', 'whitelisted ip', 'ip bypassed',
            'bot verified', 'rate limit: within limits',
        ];

        foreach ($safePatterns as $pattern) {
            if (str_contains($message, $pattern) || str_contains($eventType, $pattern)) {
                return false;
            }
        }

        // =====================================================================
        // PHASE 3: DEFAULT — Unknown events, skip to avoid contamination
        // =====================================================================
        return null;
    }

    /**
     * Full training from all sources (database + logs)
     *
     * @param \PDO $pdo Database connection
     * @param string $logDirectory Path to log files
     * @return array Complete training results
     */
    public function fullTraining(object $pdo, string $logDirectory = ''): array
    {
        $results = [
            'database' => null,
            'logs' => null,
            'total_trained' => 0,
            'model_status' => '',
        ];

        // Train from database first
        $results['database'] = $this->trainFromDatabase($pdo);

        // Then train from log files if directory provided
        if (!empty($logDirectory) && is_dir($logDirectory)) {
            $logEntries = $this->collectLogEntries($logDirectory);
            $results['logs'] = $this->trainFromLogs($logEntries);
        }

        $results['total_trained'] = $this->stats['training_samples'];
        $results['model_status'] = $this->getLearningStatus();

        Logger::security('warning', 'ML Engine: Full training completed', [
            'total_samples' => $results['total_trained'],
            'status' => $results['model_status'],
        ]);

        return $results;
    }

    /**
     * Collect log entries from all security log files
     */
    private function collectLogEntries(string $directory): array
    {
        $entries = [];

        // Find all security log files
        $pattern = $directory . '/security-*.log';
        $files = glob($pattern);

        // Also check for older date-based patterns
        $pattern2 = $directory . '/security_*.log';
        $files = array_merge($files, glob($pattern2));

        foreach ($files as $file) {
            if (!is_readable($file)) {
                continue;
            }

            $handle = fopen($file, 'r');
            if (!$handle) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (!empty($line) && str_contains($line, '{')) {
                    $entries[] = $line;
                }
            }

            fclose($handle);
        }

        return $entries;
    }

    // ========================================
    // Feature Detection Methods
    // ========================================

    private function checkScanner(string $ua): float
    {
        $scanners = ['sqlmap', 'nikto', 'nmap', 'masscan', 'burp', 'acunetix', 'nessus', 'wpscan', 'dirbuster', 'gobuster', 'ffuf', 'censys', 'shodan', 'zgrab'];

        foreach ($scanners as $s) {
            if (str_contains($ua, $s)) {
                return 1.0;
            }
        }

        return 0.0;
    }

    private function checkFakeUA(string $ua): float
    {
        $fake = ['msie 9', 'msie 10', 'msie 11', 'chrome/70', 'chrome/80', 'chrome/90', 'firefox/70', 'firefox/80', 'windows nt 5', 'windows 98'];

        foreach ($fake as $f) {
            if (str_contains($ua, $f)) {
                return 1.0;
            }
        }

        return 0.0;
    }

    private function checkBot(string $ua): float
    {
        if (str_contains($ua, 'bot') || str_contains($ua, 'crawler') || str_contains($ua, 'spider')) {
            return 0.3; // Not necessarily malicious
        }

        return 0.0;
    }

    private function checkAutomation(string $ua): float
    {
        $auto = ['curl/', 'wget/', 'python-', 'libwww-', 'java/', 'httpclient', 'axios', 'node-fetch'];

        foreach ($auto as $a) {
            if (str_contains($ua, $a)) {
                return 0.5;
            }
        }

        return 0.0;
    }

    private function checkImpossibleVersion(string $ua): float
    {
        // Chrome below 100 is impossible in 2026
        if (preg_match('/chrome\/(\d+)/', $ua, $m)) {
            if ((int) $m[1] < 100) {
                return 1.0;
            }
        }

        return 0.0;
    }

    private function checkSensitivePath(string $path): float
    {
        // ENTERPRISE v6.7: Comprehensive sensitive path detection
        // Includes Spring actuator, cloud metadata, and framework-specific endpoints
        $sensitive = [
            // Configuration files
            '/.env', '/.git', '/.aws', '/.ssh', '/credentials',
            // Common exploit targets
            '/backup', '/dump', '/phpinfo', '/info.php',
            // Spring Boot/Java actuator endpoints (CRITICAL - common attack vector)
            '/actuator', '/actuator/env', '/actuator/health', '/actuator/heapdump',
            '/actuator/configprops', '/actuator/beans', '/actuator/mappings',
            // Cloud metadata endpoints
            '/metadata', '/latest/meta-data', '/169.254.169.254',
            // Other common probes
            '/debug', '/trace', '/metrics', '/status', '/health',
            // Server-status endpoints
            '/server-status', '/server-info',
            // CVE targets
            '/solr', '/manager', '/console', '/jolokia',
        ];

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
        $config = ['/config.', '/database.', '/settings.', '/secrets', '.yml', '.yaml', '.json'];

        foreach ($config as $c) {
            if (str_contains($path, $c)) {
                return 0.7;
            }
        }

        return 0.0;
    }

    private function checkTraversal(string $path): float
    {
        if (str_contains($path, '../') || str_contains($path, '..\\') || str_contains($path, '%2e%2e')) {
            return 1.0;
        }

        return 0.0;
    }

    private function checkBackupExtension(string $path): float
    {
        $backup = ['.bak', '.backup', '.old', '.orig', '.save', '.swp', '~'];

        foreach ($backup as $b) {
            if (str_ends_with($path, $b)) {
                return 0.8;
            }
        }

        return 0.0;
    }

    private function checkSuspiciousQuery(string $query): float
    {
        $suspicious = ['union', 'select', '1=1', 'or 1', '<script', 'javascript:', 'onerror', 'onload'];

        foreach ($suspicious as $s) {
            if (str_contains($query, $s)) {
                return 0.9;
            }
        }

        return 0.0;
    }

    // Behavioral features (Redis-based)
    // ENTERPRISE v6.9 RECALIBRATION: Thresholds raised for modern SPA traffic patterns
    // A normal SPA page load generates 10-15 concurrent API calls (feed, notifications,
    // friends, status, etc.) and active navigation touches 30+ endpoints in 5 minutes.
    // Previous thresholds (burst/10, rate/100, endpoints/20) triggered false positives
    // on EVERY authenticated user's normal navigation.
    private function getRequestRate(string $ip): float
    {
        if (!$this->redis) {
            return 0.0;
        }
        $count = (int) $this->redis->get(self::REDIS_PREFIX . "rate:{$ip}");

        // 500 requests in 5 min = score 1.0 (was 100 — too low for SPA)
        // Normal SPA user: ~50-150 requests/5min → score 0.1-0.3 (harmless)
        // Scanner: 300-500+ requests/5min → score 0.6-1.0 (detected)
        return min(1.0, $count / 500);
    }

    private function detectBurst(string $ip): float
    {
        if (!$this->redis) {
            return 0.0;
        }
        $burst = (int) $this->redis->get(self::REDIS_PREFIX . "burst:{$ip}");

        // 50 requests in 1 min = score 1.0 (was 10 — SPA page load hits 10+ easily)
        // Normal SPA page load: 10-15 requests → score 0.2-0.3 (harmless)
        // Scanner burst: 40-50+ in 1 min → score 0.8-1.0 (detected)
        return min(1.0, $burst / 50);
    }

    private function getErrorRate(string $ip): float
    {
        if (!$this->redis) {
            return 0.0;
        }
        $errors = (int) $this->redis->get(self::REDIS_PREFIX . "errors:{$ip}");

        // 20 errors in 5 min = score 1.0 (was 10 — reasonable increase)
        // Normal user might get 1-2 404s → score 0.05-0.1
        // Scanner probing: 15-20+ 404s → score 0.75-1.0 (detected)
        return min(1.0, $errors / 20);
    }

    private function getEndpointDiversity(string $ip): float
    {
        if (!$this->redis) {
            return 0.0;
        }
        $count = (int) $this->redis->sCard(self::REDIS_PREFIX . "endpoints:{$ip}");

        // 80 different endpoints in 5 min = score 1.0 (was 20 — SPA hits 15+ normally)
        // Normal SPA navigation: 15-30 endpoints → score 0.19-0.38 (harmless)
        // Scanner reconnaissance: 60-80+ endpoints → score 0.75-1.0 (detected)
        return min(1.0, $count / 80);
    }

    private function getLoginFailures(string $ip): float
    {
        if (!$this->redis) {
            return 0.0;
        }
        $failures = (int) $this->redis->get(self::REDIS_PREFIX . "login_fail:{$ip}");

        return min(1.0, $failures / 5);
    }

    private function getCSRFFailures(string $ip): float
    {
        if (!$this->redis) {
            return 0.0;
        }
        $failures = (int) $this->redis->get(self::REDIS_PREFIX . "csrf_fail:{$ip}");

        return min(1.0, $failures / 3);
    }

    private function checkRegularInterval(string $ip): float
    {
        // TODO: Implement interval detection
        return 0.0;
    }

    /**
     * Track behavioral metrics
     */
    public function trackBehavior(string $ip, string $type, string $value = ''): void
    {
        if (!$this->redis) {
            return;
        }

        $window = 300; // 5 minutes

        switch ($type) {
            case 'request':
                $key = self::REDIS_PREFIX . "rate:{$ip}";
                $this->redis->incr($key);
                $this->redis->expire($key, $window);
                break;

            case 'error':
                $key = self::REDIS_PREFIX . "errors:{$ip}";
                $this->redis->incr($key);
                $this->redis->expire($key, $window);
                break;

            case 'endpoint':
                $key = self::REDIS_PREFIX . "endpoints:{$ip}";
                $this->redis->sAdd($key, $value);
                $this->redis->expire($key, $window);
                break;

            case 'login_failure':
                $key = self::REDIS_PREFIX . "login_fail:{$ip}";
                $this->redis->incr($key);
                $this->redis->expire($key, 3600); // 1 hour
                break;

            case 'csrf_failure':
                $key = self::REDIS_PREFIX . "csrf_fail:{$ip}";
                $this->redis->incr($key);
                $this->redis->expire($key, 3600);
                break;

            case 'burst':
                $key = self::REDIS_PREFIX . "burst:{$ip}";
                $this->redis->incr($key);
                $this->redis->expire($key, 60); // 1 minute burst window
                break;
        }
    }
}
