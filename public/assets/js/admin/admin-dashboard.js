/**
 * need2talk Admin Dashboard - Enterprise Real-time Monitoring
 * 
 * SISTEMA MONITORING ENTERPRISE:
 * - Real-time metrics updates
 * - Chart.js integration per performance graphs
 * - Error monitoring e logging centralizzato
 * - Auto-refresh intelligente
 * - Sistema alert management
 * - Health check automation
 */

class AdminDashboard {
    constructor() {
        this.adminUrl = document.querySelector('[data-admin-url]')?.dataset.adminUrl || '';
        this.refreshInterval = null;
        this.charts = {};
        this.lastUpdate = Date.now();
        this.refreshRate = 30000; // 30 secondi
        this.isVisible = true;
        
        this.init();
    }
    
    init() {
        Need2Talk.Logger.info('Admin Dashboard', '📊 Initializing enterprise monitoring dashboard');
        
        // Bind events
        this.bindEvents();
        
        // Initialize charts
        this.initializeCharts();
        
        // Start real-time updates
        this.startRealTimeUpdates();
        
        // Handle page visibility
        this.handlePageVisibility();
        
        Need2Talk.Logger.info('Admin Dashboard', '✅ Dashboard initialized successfully');
    }
    
    bindEvents() {
        // Logout button
        const logoutBtn = document.querySelector('[data-action="logout"]');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', this.handleLogout.bind(this));
        }
        
        // Backup button
        const backupBtn = document.querySelector('[data-action="backup"]');
        if (backupBtn) {
            backupBtn.addEventListener('click', this.handleBackup.bind(this));
        }
        
        // Health check button
        const healthBtn = document.querySelector('[data-action="health-check"]');
        if (healthBtn) {
            healthBtn.addEventListener('click', this.handleHealthCheck.bind(this));
        }
        
        // Refresh button
        const refreshBtn = document.querySelector('[data-action="refresh"]');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', this.handleManualRefresh.bind(this));
        }
    }
    
    initializeCharts() {
        // Response Time Chart
        this.initResponseTimeChart();
        
        // Request Rate Chart
        this.initRequestRateChart();
        
        Need2Talk.Logger.info('Admin Dashboard', '📈 Performance charts initialized');
    }
    
    initResponseTimeChart() {
        const ctx = document.getElementById('responseTimeChart');
        if (!ctx) return;
        
        this.charts.responseTime = new Chart(ctx, {
            type: 'line',
            data: {
                labels: this.generateTimeLabels(24),
                datasets: [{
                    label: 'Response Time (ms)',
                    data: this.generateMockData(24, 50, 200),
                    borderColor: 'rgb(59, 130, 246)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(17, 24, 39, 0.95)',
                        titleColor: '#f9fafb',
                        bodyColor: '#f9fafb',
                        borderColor: '#374151',
                        borderWidth: 1
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(156, 163, 175, 0.2)'
                        },
                        ticks: {
                            callback: function(value) {
                                return value + 'ms';
                            }
                        }
                    }
                }
            }
        });
    }
    
    initRequestRateChart() {
        const ctx = document.getElementById('requestRateChart');
        if (!ctx) return;
        
        this.charts.requestRate = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: this.generateTimeLabels(12),
                datasets: [{
                    label: 'Requests/min',
                    data: this.generateMockData(12, 20, 100),
                    backgroundColor: 'rgba(34, 197, 94, 0.8)',
                    borderColor: 'rgb(34, 197, 94)',
                    borderWidth: 2,
                    borderRadius: 4,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(17, 24, 39, 0.95)',
                        titleColor: '#f9fafb',
                        bodyColor: '#f9fafb'
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(156, 163, 175, 0.2)'
                        },
                        ticks: {
                            callback: function(value) {
                                return value + '/min';
                            }
                        }
                    }
                }
            }
        });
    }
    
    startRealTimeUpdates() {
        // Initial update
        this.updateMetrics();
        
        // Set up interval
        this.refreshInterval = setInterval(() => {
            if (this.isVisible) {
                this.updateMetrics();
            }
        }, this.refreshRate);
        
        Need2Talk.Logger.info('Admin Dashboard', `🔄 Real-time updates started (${this.refreshRate/1000}s interval)`);
    }
    
    async updateMetrics() {
        try {
            const response = await fetch(`${this.adminUrl}/api/realtime`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const result = await response.json();
            
            if (result.success) {
                this.updateUI(result.data);
                this.lastUpdate = Date.now();
            } else {
                throw new Error(result.error || 'Unknown API error');
            }
            
        } catch (error) {
            Need2Talk.Logger.error('Admin Dashboard', '🚨 Failed to update metrics', { 
                error: error.message,
                url: `${this.adminUrl}/api/realtime`
            });
            
            this.showConnectionError();
        }
    }
    
    updateUI(data) {
        // Update metric values
        this.updateElement('activeUsers', data.active_users);
        this.updateElement('avgResponseTime', `${Math.round(data.avg_response_time)}ms`);
        this.updateElement('dbConnections', `${data.db_connections.utilization_percent}%`);
        this.updateElement('cacheHitRatio', `${Math.round(data.cache_hit_ratio)}%`);
        
        // Update database pool color based on utilization
        this.updateConnectionPoolStatus(data.db_connections.utilization_percent);
        
        // Update last refresh time
        this.updateLastRefreshTime();
        
        // Clear any error states
        this.clearConnectionError();
    }
    
    updateElement(id, value) {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = value;
        }
    }
    
    updateConnectionPoolStatus(utilization) {
        const element = document.getElementById('dbConnections');
        if (!element) return;
        
        // Remove existing color classes
        element.className = element.className.replace(/text-(green|yellow|red)-600/g, '');
        
        // Add color class based on utilization
        if (utilization < 60) {
            element.classList.add('text-green-600');
        } else if (utilization < 80) {
            element.classList.add('text-yellow-600');
        } else {
            element.classList.add('text-red-600');
        }
    }
    
    updateLastRefreshTime() {
        const element = document.querySelector('[data-last-refresh]');
        if (element) {
            element.textContent = new Date().toLocaleTimeString('it-IT');
        }
    }
    
    showConnectionError() {
        const statusElement = document.querySelector('.admin-status-badge');
        if (statusElement) {
            statusElement.innerHTML = '🔴 Errore Connessione';
            statusElement.className = statusElement.className.replace('bg-green-100', 'bg-red-100')
                .replace('text-green-800', 'text-red-800');
        }
    }
    
    clearConnectionError() {
        const statusElement = document.querySelector('.admin-status-badge');
        if (statusElement && statusElement.textContent.includes('Errore')) {
            statusElement.innerHTML = '🟢 Sistema Operativo';
            statusElement.className = statusElement.className.replace('bg-red-100', 'bg-green-100')
                .replace('text-red-800', 'text-green-800');
        }
    }
    
    async handleBackup() {
        const button = event.target;
        const originalText = button.textContent;
        
        try {
            // Update button state
            button.disabled = true;
            button.innerHTML = '<span class="admin-loading"><span class="admin-spinner"></span> Creando backup...</span>';
            
            Need2Talk.Logger.info('Admin Dashboard', '💾 Starting database backup');
            
            const response = await fetch(`${this.adminUrl}/backup`, { 
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            const result = await response.json();
            
            if (result.success) {
                Need2Talk.Logger.info('Admin Dashboard', '✅ Backup completed successfully', result);
                this.showAlert(`✅ Backup creato con successo!\nFile: ${result.file}\nDimensioni: ${result.size}`, 'success');
            } else {
                throw new Error(result.error || 'Backup failed');
            }
            
        } catch (error) {
            Need2Talk.Logger.error('Admin Dashboard', '❌ Backup failed', { error: error.message });
            this.showAlert(`❌ Errore durante il backup: ${error.message}`, 'error');
        } finally {
            button.disabled = false;
            button.textContent = originalText;
        }
    }
    
    async handleHealthCheck() {
        const button = event.target;
        const originalText = button.textContent;
        
        try {
            button.disabled = true;
            button.innerHTML = '<span class="admin-loading"><span class="admin-spinner"></span> Verificando sistema...</span>';
            
            Need2Talk.Logger.info('Admin Dashboard', '🏥 Starting health check');
            
            const response = await fetch(`${this.adminUrl}/health`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            const result = await response.json();
            
            if (result.overall_health === 'healthy') {
                Need2Talk.Logger.info('Admin Dashboard', '✅ System health check passed');
                this.showAlert('✅ Sistema in salute!\n\nTutti i componenti funzionano correttamente.', 'success');
            } else {
                const issues = Object.entries(result.components)
                    .filter(([, status]) => !status)
                    .map(([component]) => component);
                    
                Need2Talk.Logger.warning('Admin Dashboard', '⚠️ Health check found issues', { issues });
                this.showAlert(`⚠️ Problemi rilevati!\n\nComponenti con problemi: ${issues.join(', ')}`, 'warning');
            }
            
        } catch (error) {
            Need2Talk.Logger.error('Admin Dashboard', '❌ Health check failed', { error: error.message });
            this.showAlert('❌ Errore durante il health check', 'error');
        } finally {
            button.disabled = false;
            button.textContent = originalText;
        }
    }
    
    async handleLogout() {
        if (!confirm('Sei sicuro di voler effettuare il logout?')) {
            return;
        }
        
        try {
            Need2Talk.Logger.info('Admin Dashboard', '🚪 Logging out');
            
            await fetch(`${this.adminUrl}/logout`, { 
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
        } catch (error) {
            Need2Talk.Logger.warning('Admin Dashboard', '⚠️ Logout request failed, redirecting anyway', { error: error.message });
        } finally {
            // Always redirect, even if request fails
            window.location.href = this.adminUrl;
        }
    }
    
    handleManualRefresh() {
        Need2Talk.Logger.info('Admin Dashboard', '🔄 Manual refresh triggered');
        this.updateMetrics();
    }
    
    handlePageVisibility() {
        document.addEventListener('visibilitychange', () => {
            this.isVisible = !document.hidden;
            
            if (this.isVisible) {
                Need2Talk.Logger.info('Admin Dashboard', '👁️ Page became visible, resuming updates');
                this.updateMetrics(); // Immediate update when page becomes visible
            } else {
                Need2Talk.Logger.info('Admin Dashboard', '👁️ Page hidden, updates continue in background');
            }
        });
    }
    
    showAlert(message, type = 'info') {
        // Use browser alert for now - could be enhanced with custom modal
        alert(message);
    }
    
    generateTimeLabels(hours) {
        const labels = [];
        const now = new Date();
        
        for (let i = hours - 1; i >= 0; i--) {
            const time = new Date(now - i * 60 * 60 * 1000);
            labels.push(time.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' }));
        }
        
        return labels;
    }
    
    generateMockData(count, min, max) {
        return Array.from({ length: count }, () => 
            Math.floor(Math.random() * (max - min + 1)) + min
        );
    }
    
    destroy() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
        
        // Destroy charts
        Object.values(this.charts).forEach(chart => {
            if (chart && typeof chart.destroy === 'function') {
                chart.destroy();
            }
        });
        
        Need2Talk.Logger.info('Admin Dashboard', '🗑️ Dashboard destroyed');
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    if (document.querySelector('[data-page="admin-dashboard"]')) {
        window.adminDashboard = new AdminDashboard();
    }
});

// Handle page unload
window.addEventListener('beforeunload', () => {
    if (window.adminDashboard) {
        window.adminDashboard.destroy();
    }
});

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AdminDashboard;
}