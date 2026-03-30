<?php

namespace Need2Talk\Services\Security;

use Need2Talk\Services\Logger;

/**
 * ENTERPRISE GALAXY: Security Shield - Unified Threat Protection
 *
 * Orchestrates rule-based WAF + ML-based threat detection
 *
 * ARCHITECTURE:
 * 1. Rule-based scoring (AntiVulnerabilityScanningMiddleware patterns)
 * 2. ML classification (AdvancedMLThreatEngine - 21k+ trained samples)
 * 3. Weighted combination (60% rules + 40% ML)
 * 4. Action determination (ALLOW/MONITOR/CHALLENGE/BLOCK/BAN)
 *
 * AUTO-LEARNING:
 * - Every ban triggers ML learning (confirmed threat)
 * - Honeypot hits = confirmed threat
 * - CSRF failures = confirmed threat
 * - Rate limit violations = likely threat
 * - Legitimate bot verification = confirmed safe
 *
 * @version 2.0.0 - Now uses AdvancedMLThreatEngine (21k+ samples)
 */
class SecurityShield
{
    private AdvancedMLThreatEngine $mlEngine;

    // Weight distribution for final score
    private const RULE_WEIGHT = 0.6;  // 60% rule-based
    private const ML_WEIGHT = 0.4;    // 40% ML-based

    // Action thresholds
    // ENTERPRISE v6.9 RECALIBRATION: Raised MONITOR from 0.30 to 0.45
    // With 60/40 rule/ML split, a pure ML behavioral score of 0.8 produces
    // combined_score = 0.4*0.8 = 0.32, which was exceeding 0.30 MONITOR threshold
    // for every active SPA user. Now only scores above 0.45 trigger logging,
    // which requires either rule-based detection OR strong ML signals.
    private const THRESHOLD_BAN = 0.90;
    private const THRESHOLD_BLOCK = 0.75;
    private const THRESHOLD_CHALLENGE = 0.50;
    private const THRESHOLD_MONITOR = 0.45;

    public function __construct()
    {
        $this->mlEngine = new AdvancedMLThreatEngine();
    }

    /**
     * Analyze a request and return threat assessment
     *
     * @param array $requestData Request data
     * @param float $ruleScore Score from rule-based system (0-100 normalized to 0-1)
     * @return array Unified threat assessment
     */
    public function analyze(array $requestData, float $ruleScore = 0.0): array
    {
        // Normalize rule score to 0-1 (assuming max 100)
        $normalizedRuleScore = min(1.0, $ruleScore / 100);

        // Get ML analysis from trained engine (21k+ samples)
        $mlResult = $this->mlEngine->analyze($requestData);

        // Extract ML score (AdvancedMLThreatEngine returns different format)
        $mlScore = $mlResult['threat_score'] ?? $mlResult['score'] ?? 0.0;
        $mlConfidence = $mlResult['confidence'] ?? 0.5;
        $mlCategory = $mlResult['category'] ?? 'unknown';
        $isHighConfidence = ($mlConfidence >= 0.85);
        $mlAction = $mlResult['action'] ?? 'ALLOW';

        // Combine scores with weights
        $combinedScore = (self::RULE_WEIGHT * $normalizedRuleScore)
                       + (self::ML_WEIGHT * $mlScore);

        // High confidence ML can override
        if ($isHighConfidence && $mlScore >= 0.9) {
            // ML is very confident this is a threat - give it more weight
            $combinedScore = max($combinedScore, $mlScore * 0.95);
        }

        // Determine final action
        $action = $this->determineAction($combinedScore, $mlScore, $mlConfidence, $isHighConfidence, $mlAction);

        $result = [
            'combined_score' => round($combinedScore, 4),
            'rule_score' => round($normalizedRuleScore, 4),
            'ml_score' => round($mlScore, 4),
            'ml_confidence' => round($mlConfidence, 4),
            'ml_category' => $mlCategory,
            'action' => $action,
            'learning_status' => $mlResult['learning_status'] ?? 'unknown',
            'should_learn' => $action === 'BAN' || $action === 'BLOCK',
            'is_high_confidence' => $isHighConfidence,
            'ml_enabled' => $mlResult['ml_enabled'] ?? true,
        ];

        // Log high-threat detections
        if ($combinedScore >= self::THRESHOLD_MONITOR) {
            Logger::security('warning', 'SECURITY SHIELD: Threat detected', [
                'ip' => $requestData['ip'] ?? 'unknown',
                'path' => $requestData['path'] ?? '/',
                'combined_score' => $result['combined_score'],
                'rule_score' => $result['rule_score'],
                'ml_score' => $result['ml_score'],
                'action' => $action,
                'ml_category' => $mlCategory,
            ]);
        }

        return $result;
    }

    /**
     * Determine action based on combined score
     */
    private function determineAction(float $score, float $mlScore, float $mlConfidence, bool $isHighConfidence, string $mlAction): string
    {
        // High confidence ML override
        if ($isHighConfidence && $mlConfidence >= 0.9) {
            if ($mlAction === 'BAN' || $mlScore >= 0.95) {
                return 'BAN';
            }
            if ($mlAction === 'BLOCK' || $mlScore >= 0.85) {
                return 'BLOCK';
            }
        }

        // Score-based determination
        if ($score >= self::THRESHOLD_BAN) {
            return 'BAN';
        }
        if ($score >= self::THRESHOLD_BLOCK) {
            return 'BLOCK';
        }
        if ($score >= self::THRESHOLD_CHALLENGE) {
            return 'CHALLENGE';
        }
        if ($score >= self::THRESHOLD_MONITOR) {
            return 'MONITOR';
        }

        return 'ALLOW';
    }

    /**
     * Learn from a security event
     *
     * Call this when:
     * - AntiVulnerabilityScanningMiddleware bans an IP
     * - Honeypot is triggered
     * - CSRF validation fails repeatedly
     * - Rate limit is exceeded
     * - Admin manually bans an IP
     *
     * @param array $requestData Request data
     * @param bool $isThreat True if confirmed threat
     * @param string $source Source of the learning signal
     */
    public function learnFromEvent(array $requestData, bool $isThreat, string $source): void
    {
        // Learn using AdvancedMLThreatEngine
        $this->mlEngine->learn($requestData, $isThreat);

        Logger::security('warning', 'SECURITY SHIELD: ML learning from event', [
            'is_threat' => $isThreat,
            'source' => $source,
            'ip' => $requestData['ip'] ?? 'unknown',
            'path' => $requestData['path'] ?? '/',
            'training_samples' => $this->mlEngine->getStats()['training_samples'] ?? 0,
        ]);
    }

    /**
     * Track request for behavioral analysis
     */
    public function trackRequest(string $ip, string $path, int $statusCode): void
    {
        // Track behavioral patterns in ML engine
        $this->mlEngine->trackBehavior($ip, 'request', $path);

        // Track endpoint diversity
        $this->mlEngine->trackBehavior($ip, 'endpoint', $path);

        // Track burst (1-minute window for rapid request detection)
        $this->mlEngine->trackBehavior($ip, 'burst', $path);

        // Track errors (4xx/5xx status codes)
        if ($statusCode >= 400) {
            $this->mlEngine->trackBehavior($ip, 'error', (string)$statusCode);
        }
    }

    /**
     * Get ML model statistics
     */
    public function getMLStats(): array
    {
        return $this->mlEngine->getStats();
    }

    /**
     * Get ML configuration
     */
    public function getMLConfig(): array
    {
        return $this->mlEngine->getConfig();
    }

    /**
     * Quick threat check - returns true if request should be blocked
     *
     * @param array $requestData
     * @param float $ruleScore Current rule-based score
     * @return bool True if should block
     */
    public function shouldBlock(array $requestData, float $ruleScore): bool
    {
        $result = $this->analyze($requestData, $ruleScore);

        return in_array($result['action'], ['BLOCK', 'BAN'], true);
    }

    /**
     * Get contribution of ML to a decision
     *
     * @param float $ruleScore
     * @param float $mlScore
     * @return array Breakdown of contributions
     */
    public function getScoreBreakdown(float $ruleScore, float $mlScore): array
    {
        $normalizedRule = min(1.0, $ruleScore / 100);

        return [
            'rule_contribution' => round($normalizedRule * self::RULE_WEIGHT, 4),
            'ml_contribution' => round($mlScore * self::ML_WEIGHT, 4),
            'rule_weight_percent' => self::RULE_WEIGHT * 100,
            'ml_weight_percent' => self::ML_WEIGHT * 100,
        ];
    }
}
