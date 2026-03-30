<!-- ENTERPRISE GALAXY: ML SECURITY DASHBOARD -->
<h2 class="enterprise-title mb-8 flex items-center">
    <i class="fas fa-brain mr-3"></i>
    ML Security & DDoS Protection
    <span class="text-xs px-2 py-1 rounded ml-3" style="background: rgba(34, 197, 94, 0.2); color: #4ade80; font-weight: 600;">ENTERPRISE AI</span>
</h2>

<!-- Status Cards -->
<div class="stats-grid mb-8">
    <div class="stat-card" style="border-left: 4px solid <?= ($ml_stats['learning_status'] ?? 'warming_up') === 'mature' ? '#22c55e' : '#f59e0b' ?>;">
        <span class="stat-value"><?= ucfirst($ml_stats['learning_status'] ?? 'N/A') ?></span>
        <div class="stat-label">
            <i class="fas fa-graduation-cap mr-2"></i>ML Model Status
        </div>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= number_format($ml_stats['training_samples'] ?? 0) ?></span>
        <div class="stat-label">
            <i class="fas fa-database mr-2"></i>Training Samples
        </div>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= number_format($ml_stats['true_positives'] ?? 0) ?></span>
        <div class="stat-label">
            <i class="fas fa-check-circle mr-2"></i>True Positives
        </div>
    </div>
    <div class="stat-card" style="border-left: 4px solid <?= ($ddos_status['throttle_level'] ?? 0) > 2 ? '#ef4444' : '#22c55e' ?>;">
        <span class="stat-value"><?= $ddos_status['load_percent'] ?? 0 ?>%</span>
        <div class="stat-label">
            <i class="fas fa-server mr-2"></i>Server Load
        </div>
    </div>
</div>

<!-- Two Column Layout -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">

    <!-- ML Configuration -->
    <div class="card">
        <h3 class="flex items-center mb-4">
            <i class="fas fa-cog mr-3 text-purple-400"></i>ML Configuration
        </h3>
        <form id="ml-config-form" class="space-y-4">
            <div class="flex items-center justify-between p-3 rounded" style="background: rgba(147, 51, 234, 0.1);">
                <label class="text-gray-300">ML Enabled</label>
                <label class="switch">
                    <input type="checkbox" name="ml_enabled" <?= ($ml_config['ml_enabled'] ?? true) ? 'checked' : '' ?>>
                    <span class="slider"></span>
                </label>
            </div>

            <div class="flex items-center justify-between p-3 rounded" style="background: rgba(147, 51, 234, 0.1);">
                <label class="text-gray-300">Auto Learn</label>
                <label class="switch">
                    <input type="checkbox" name="auto_learn" <?= ($ml_config['auto_learn'] ?? true) ? 'checked' : '' ?>>
                    <span class="slider"></span>
                </label>
            </div>

            <div class="p-3 rounded" style="background: rgba(147, 51, 234, 0.1);">
                <label class="text-gray-300 block mb-2">ML Weight (vs Rules): <?= round(($ml_config['ml_weight'] ?? 0.4) * 100) ?>%</label>
                <input type="range" name="ml_weight" min="0" max="100" value="<?= round(($ml_config['ml_weight'] ?? 0.4) * 100) ?>" class="w-full">
            </div>

            <div class="p-3 rounded" style="background: rgba(147, 51, 234, 0.1);">
                <label class="text-gray-300 block mb-2">Block Threshold: <?= round(($ml_config['block_threshold'] ?? 0.75) * 100) ?>%</label>
                <input type="range" name="block_threshold" min="50" max="95" value="<?= round(($ml_config['block_threshold'] ?? 0.75) * 100) ?>" class="w-full">
            </div>

            <div class="p-3 rounded" style="background: rgba(147, 51, 234, 0.1);">
                <label class="text-gray-300 block mb-2">Ban Threshold: <?= round(($ml_config['ban_threshold'] ?? 0.90) * 100) ?>%</label>
                <input type="range" name="ban_threshold" min="70" max="99" value="<?= round(($ml_config['ban_threshold'] ?? 0.90) * 100) ?>" class="w-full">
            </div>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary flex-1">
                    <i class="fas fa-save mr-2"></i>Save Config
                </button>
                <button type="button" onclick="retrainModel()" class="btn btn-warning">
                    <i class="fas fa-sync-alt mr-2"></i>Retrain
                </button>
            </div>
        </form>
    </div>

    <!-- DDoS Status -->
    <div class="card">
        <h3 class="flex items-center mb-4">
            <i class="fas fa-shield-virus mr-3 text-red-400"></i>DDoS Protection Status
        </h3>

        <div class="space-y-4">
            <div class="flex items-center justify-between p-3 rounded" style="background: rgba(239, 68, 68, 0.1);">
                <span class="text-gray-300">Status</span>
                <span class="px-3 py-1 rounded text-sm font-bold <?= ($ddos_status['enabled'] ?? false) ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' ?>">
                    <?= ($ddos_status['enabled'] ?? false) ? 'ACTIVE' : 'DISABLED' ?>
                </span>
            </div>

            <div class="flex items-center justify-between p-3 rounded" style="background: rgba(239, 68, 68, 0.1);">
                <span class="text-gray-300">Throttle Level</span>
                <span class="px-3 py-1 rounded text-sm font-bold" style="background: <?= getThrottleColor($ddos_status['throttle_level'] ?? 0) ?>;">
                    <?= strtoupper($ddos_status['throttle_name'] ?? 'normal') ?>
                </span>
            </div>

            <div class="flex items-center justify-between p-3 rounded" style="background: rgba(239, 68, 68, 0.1);">
                <span class="text-gray-300">Requests/Second</span>
                <span class="text-white font-mono"><?= $ddos_status['requests_per_second'] ?? 0 ?> / <?= $ddos_status['limits']['requests_per_second'] ?? 500 ?></span>
            </div>

            <div class="flex items-center justify-between p-3 rounded" style="background: rgba(239, 68, 68, 0.1);">
                <span class="text-gray-300">Requests/Minute</span>
                <span class="text-white font-mono"><?= $ddos_status['requests_per_minute'] ?? 0 ?> / <?= $ddos_status['limits']['requests_per_minute'] ?? 20000 ?></span>
            </div>

            <!-- Load Bar -->
            <div class="p-3 rounded" style="background: rgba(239, 68, 68, 0.1);">
                <div class="flex justify-between mb-2">
                    <span class="text-gray-300">Server Load</span>
                    <span class="text-white font-mono"><?= $ddos_status['load_percent'] ?? 0 ?>%</span>
                </div>
                <div class="w-full bg-gray-700 rounded-full h-4">
                    <div class="h-4 rounded-full transition-all duration-500" style="width: <?= min(100, $ddos_status['load_percent'] ?? 0) ?>%; background: <?= getLoadColor($ddos_status['load_percent'] ?? 0) ?>;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Threat Categories Chart -->
<div class="card mb-8">
    <h3 class="flex items-center mb-4">
        <i class="fas fa-chart-pie mr-3 text-cyan-400"></i>Detected Threat Categories
    </h3>

    <?php if (!empty($ml_stats['categories'])): ?>
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <?php
        arsort($ml_stats['categories']);
        foreach ($ml_stats['categories'] as $category => $count):
            $color = match($category) {
                'SCANNER' => '#ef4444',
                'CMS_PROBE' => '#f59e0b',
                'CREDENTIAL_THEFT' => '#dc2626',
                'BRUTE_FORCE' => '#b91c1c',
                'BOT_SPOOFING' => '#7c3aed',
                'PATH_TRAVERSAL' => '#ec4899',
                'FAKE_BROWSER' => '#8b5cf6',
                default => '#6b7280',
            };
        ?>
        <div class="p-4 rounded-lg text-center" style="background: <?= $color ?>20; border: 1px solid <?= $color ?>50;">
            <div class="text-2xl font-bold" style="color: <?= $color ?>;"><?= number_format($count) ?></div>
            <div class="text-xs text-gray-400 mt-1"><?= str_replace('_', ' ', $category) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="text-center py-8 text-gray-500">
        <i class="fas fa-check-circle text-4xl mb-4 text-green-500"></i>
        <p>No threats detected yet. The ML model will categorize threats as they are detected.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Endpoint Rate Limits -->
<div class="card mb-8">
    <h3 class="flex items-center mb-4">
        <i class="fas fa-route mr-3 text-blue-400"></i>Endpoint Protection Status
    </h3>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Endpoint</th>
                    <th>Requests This Minute</th>
                    <th>Limit</th>
                    <th>Usage</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($endpoint_stats as $endpoint => $stats): ?>
                <tr>
                    <td class="font-mono text-sm text-cyan-400"><?= htmlspecialchars($endpoint) ?></td>
                    <td class="text-center"><?= $stats['requests_this_minute'] ?></td>
                    <td class="text-center text-gray-400"><?= $stats['limit_per_minute'] ?></td>
                    <td>
                        <div class="w-full bg-gray-700 rounded-full h-2">
                            <div class="h-2 rounded-full" style="width: <?= min(100, $stats['usage_percent']) ?>%; background: <?= $stats['usage_percent'] > 80 ? '#ef4444' : ($stats['usage_percent'] > 50 ? '#f59e0b' : '#22c55e') ?>;"></div>
                        </div>
                    </td>
                    <td class="text-center">
                        <?php if ($stats['usage_percent'] > 80): ?>
                            <span class="badge badge-danger">HIGH</span>
                        <?php elseif ($stats['usage_percent'] > 50): ?>
                            <span class="badge badge-warning">MEDIUM</span>
                        <?php else: ?>
                            <span class="badge badge-success">NORMAL</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- IP Proxy Validator Diagnostics -->
<div class="card mb-8">
    <h3 class="flex items-center mb-4">
        <i class="fas fa-network-wired mr-3 text-yellow-400"></i>Trusted Proxy Validator
        <span class="text-xs px-2 py-1 rounded ml-3" style="background: rgba(34, 197, 94, 0.2); color: #4ade80;">ANTI-SPOOFING</span>
    </h3>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="p-4 rounded" style="background: rgba(234, 179, 8, 0.1);">
            <div class="text-gray-400 text-sm mb-1">Your REMOTE_ADDR</div>
            <div class="font-mono text-yellow-400"><?= htmlspecialchars($proxy_diagnostics['remote_addr'] ?? 'unknown') ?></div>
        </div>
        <div class="p-4 rounded" style="background: rgba(234, 179, 8, 0.1);">
            <div class="text-gray-400 text-sm mb-1">Is Trusted Proxy?</div>
            <div class="font-mono <?= ($proxy_diagnostics['is_trusted_proxy'] ?? false) ? 'text-green-400' : 'text-red-400' ?>">
                <?= ($proxy_diagnostics['is_trusted_proxy'] ?? false) ? 'YES (X-Forwarded-For trusted)' : 'NO (Using REMOTE_ADDR)' ?>
            </div>
        </div>
        <div class="p-4 rounded" style="background: rgba(234, 179, 8, 0.1);">
            <div class="text-gray-400 text-sm mb-1">Validated Client IP</div>
            <div class="font-mono text-green-400 text-lg"><?= htmlspecialchars($proxy_diagnostics['validated_client_ip'] ?? 'unknown') ?></div>
        </div>
        <div class="p-4 rounded" style="background: rgba(234, 179, 8, 0.1);">
            <div class="text-gray-400 text-sm mb-1">Raw X-Forwarded-For</div>
            <div class="font-mono text-gray-300 text-sm"><?= htmlspecialchars($proxy_diagnostics['raw_x_forwarded_for'] ?? 'not set') ?></div>
        </div>
    </div>

    <div class="mt-4 p-4 rounded" style="background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3);">
        <div class="flex items-start">
            <i class="fas fa-shield-alt text-green-400 mr-3 mt-1"></i>
            <div>
                <div class="text-green-400 font-bold mb-1">IP Spoofing Protection Active</div>
                <div class="text-gray-400 text-sm">
                    X-Forwarded-For headers are only trusted when the request comes from known proxies
                    (Docker network, Cloudflare, localhost). Direct connections use the non-spoofable REMOTE_ADDR.
                    This prevents attackers from bypassing IP-based rate limits.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Currently Banned IPs -->
<div class="card">
    <h3 class="flex items-center mb-4">
        <i class="fas fa-ban mr-3 text-red-400"></i>Currently Banned IPs
        <span class="ml-auto text-sm text-gray-400"><?= count($banned_ips ?? []) ?> active bans</span>
    </h3>

    <?php if (!empty($banned_ips)): ?>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>IP Address</th>
                    <th>Type</th>
                    <th>Severity</th>
                    <th>Score</th>
                    <th>Banned At</th>
                    <th>Expires In</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($banned_ips, 0, 20) as $ban): ?>
                <tr>
                    <td class="font-mono text-red-400"><?= htmlspecialchars($ban['ip_address']) ?></td>
                    <td>
                        <span class="badge <?= $ban['ban_type'] === 'honeypot' ? 'badge-danger' : 'badge-warning' ?>">
                            <?= strtoupper($ban['ban_type']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?= $ban['severity'] === 'critical' ? 'badge-danger' : ($ban['severity'] === 'high' ? 'badge-warning' : 'badge-info') ?>">
                            <?= strtoupper($ban['severity']) ?>
                        </span>
                    </td>
                    <td class="text-center"><?= $ban['score'] ?></td>
                    <td class="text-gray-400 text-sm"><?= date('d/m H:i', strtotime($ban['banned_at'])) ?></td>
                    <td class="text-yellow-400"><?= $ban['hours_remaining'] ?>h</td>
                    <td>
                        <button onclick="unbanIP('<?= htmlspecialchars($ban['ip_address']) ?>')" class="btn btn-sm btn-secondary">
                            <i class="fas fa-unlock mr-1"></i>Unban
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="text-center py-8 text-gray-500">
        <i class="fas fa-check-circle text-4xl mb-4 text-green-500"></i>
        <p>No IPs currently banned.</p>
    </div>
    <?php endif; ?>
</div>

<style>
.switch {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 26px;
}
.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #374151;
    transition: .4s;
    border-radius: 26px;
}
.slider:before {
    position: absolute;
    content: "";
    height: 20px;
    width: 20px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}
input:checked + .slider {
    background-color: #22c55e;
}
input:checked + .slider:before {
    transform: translateX(24px);
}
</style>

<script nonce="<?= csp_nonce() ?>">
// ML Config Form
document.getElementById('ml-config-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const data = {
        ml_enabled: formData.get('ml_enabled') === 'on',
        auto_learn: formData.get('auto_learn') === 'on',
        ml_weight: parseInt(formData.get('ml_weight')) / 100,
        block_threshold: parseInt(formData.get('block_threshold')) / 100,
        ban_threshold: parseInt(formData.get('ban_threshold')) / 100,
    };

    try {
        const response = await fetch('api/ml-security/config', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();
        if (result.success) {
            showNotification('Configuration saved successfully', 'success');
        } else {
            showNotification(result.error || 'Failed to save configuration', 'error');
        }
    } catch (error) {
        showNotification('Network error: ' + error.message, 'error');
    }
});

async function retrainModel() {
    if (!confirm('This will retrain the ML model with all historical data. Continue?')) return;

    showNotification('Starting model retraining...', 'info');

    try {
        const response = await fetch('api/ml-security/retrain', {
            method: 'POST',
            headers: {
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content
            }
        });

        const result = await response.json();
        if (result.success) {
            showNotification(`Model retrained with ${result.total_samples} samples`, 'success');
            setTimeout(() => location.reload(), 2000);
        } else {
            showNotification(result.error || 'Retraining failed', 'error');
        }
    } catch (error) {
        showNotification('Network error: ' + error.message, 'error');
    }
}

async function unbanIP(ip) {
    if (!confirm(`Unban IP ${ip}?`)) return;

    try {
        const response = await fetch('api/ml-security/unban', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content
            },
            body: JSON.stringify({ ip })
        });

        const result = await response.json();
        if (result.success) {
            showNotification(`IP ${ip} unbanned`, 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification(result.error || 'Failed to unban IP', 'error');
        }
    } catch (error) {
        showNotification('Network error: ' + error.message, 'error');
    }
}

function showNotification(message, type) {
    const colors = {
        success: 'bg-green-500',
        error: 'bg-red-500',
        info: 'bg-blue-500',
        warning: 'bg-yellow-500'
    };

    const notification = document.createElement('div');
    notification.className = `fixed top-24 right-4 ${colors[type]} text-white px-6 py-3 rounded-lg shadow-lg z-50 animate-fade-in`;
    notification.textContent = message;
    document.body.appendChild(notification);

    setTimeout(() => notification.remove(), 5000);
}

// Auto-refresh every 30 seconds
setInterval(() => {
    fetch('api/ml-security/status')
        .then(r => r.json())
        .then(data => {
            // Update stats without full page reload
            console.log('Status update:', data);
        })
        .catch(() => {});
}, 30000);
</script>

<?php
// Helper function for throttle color
function getThrottleColor($level) {
    return match($level) {
        0 => 'rgba(34, 197, 94, 0.3)',
        1 => 'rgba(34, 197, 94, 0.5)',
        2 => 'rgba(234, 179, 8, 0.5)',
        3 => 'rgba(249, 115, 22, 0.5)',
        4 => 'rgba(239, 68, 68, 0.5)',
        5 => 'rgba(239, 68, 68, 0.8)',
        default => 'rgba(107, 114, 128, 0.5)',
    };
}

function getLoadColor($percent) {
    if ($percent >= 90) return '#ef4444';
    if ($percent >= 75) return '#f97316';
    if ($percent >= 50) return '#eab308';
    if ($percent >= 30) return '#22c55e';
    return '#22c55e';
}
?>
