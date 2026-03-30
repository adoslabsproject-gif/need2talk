<!-- Terminal Interface -->
<div class="dashboard-container dashboard-vertical">
    <div class="card" style="margin-bottom: 20px;">
        <h2>🖥️ CLI Terminal</h2>

        <div class="system-info" style="margin-bottom: 20px; padding: 15px; background: #1e293b; border: 1px solid #334155; border-radius: 8px;">
            <h4 style="color: #f1f5f9; margin-bottom: 12px; font-size: 16px;">System Information</h4>
            <?php foreach ($system_info as $key => $value) { ?>
                <div style="margin: 5px 0; color: #cbd5e1;">
                    <strong style="color: #94a3b8;"><?= htmlspecialchars($key) ?>:</strong> <?= htmlspecialchars($value) ?>
                </div>
            <?php } ?>
        </div>

        <!-- Quick Commands (2025-01-10 Enterprise Galaxy) -->
        <div style="margin-bottom: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
            <button style="background: linear-gradient(135deg, #9333ea 0%, #7e22ce 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="runDockerStatsMonitor()">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="flex-shrink: 0;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
                <span>Docker Stats Monitor (Live)</span>
            </button>
            <button style="background: linear-gradient(135deg, #0f766e 0%, #134e4a 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="runCommand('docker stats --no-stream')">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="flex-shrink: 0;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/>
                </svg>
                <span>Docker Stats (Snapshot)</span>
            </button>
            <button style="background: linear-gradient(135deg, #ea580c 0%, #c2410c 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="runCommand('free -h')">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="flex-shrink: 0;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
                </svg>
                <span>Memory Usage</span>
            </button>
        </div>

        <div class="terminal-interface">
            <div class="terminal-input" style="margin-bottom: 15px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="color: #28a745; font-weight: bold;">$</span>
                    <input type="text" id="command-input" placeholder="Enter command..."
                           style="flex: 1; padding: 8px; border: 1px solid rgb(75 85 99 / 0.5); border-radius: 4px; font-family: monospace; background: rgb(55 65 81 / 0.5); color: white;"
                           onkeypress="if(event.key==='Enter') executeCommand()">
                    <button class="btn btn-primary" onclick="executeCommand()">Execute</button>
                    <button class="btn btn-secondary" onclick="clearTerminal()">Clear</button>
                </div>
            </div>

            <div id="terminal-output" class="monitoring-output" style="min-height: 400px; max-height: 600px;">
                <p class="text-muted">Enter a command above and press Enter to execute...</p>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
// ============================================================================
// ENTERPRISE GALAXY: Terminal Functions (2025-01-10)
// ============================================================================

/**
 * Execute command from input field
 */
function executeCommand() {
    const input = document.getElementById('command-input');
    const command = input.value.trim();

    if (!command) {
        return;
    }

    // Clear input
    input.value = '';

    // Execute via runCommand()
    runCommand(command);
}

/**
 * Run a command (from input or quick button)
 * @param {string} command - Command to execute
 */
function runCommand(command) {
    const output = document.getElementById('terminal-output');
    const timestamp = new Date().toLocaleTimeString();

    // Add command to output
    const commandDiv = document.createElement('div');
    commandDiv.innerHTML = `<div style="color: #28a745; margin: 10px 0;"><strong>$ ${command}</strong></div>`;
    output.appendChild(commandDiv);

    // Add loading indicator
    const loadingDiv = document.createElement('div');
    loadingDiv.innerHTML = `<p style="color: rgb(156 163 175);">[${timestamp}] Executing...</p>`;
    output.appendChild(loadingDiv);
    output.scrollTop = output.scrollHeight;

    // Execute command
    fetch('terminal-exec', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `command=${encodeURIComponent(command)}`
    })
    .then(response => response.json())
    .then(data => {
        // Remove loading indicator
        output.removeChild(loadingDiv);

        // Add result
        const resultDiv = document.createElement('div');
        if (data.success) {
            resultDiv.innerHTML = `
                <pre style="color: rgb(74 222 128); background: rgb(17 24 39); padding: 10px; border-radius: 4px; margin: 5px 0 15px 0; white-space: pre-wrap; word-wrap: break-word;">${escapeHtml(data.output)}</pre>
                <div style="color: rgb(156 163 175); font-size: 11px; margin-bottom: 15px;">
                    Execution time: ${data.execution_time} | Working directory: ${data.working_directory}
                </div>
            `;
        } else {
            resultDiv.innerHTML = `
                <pre style="color: rgb(248 113 113); background: rgb(127 29 29); padding: 10px; border-radius: 4px; margin: 5px 0 15px 0;">Error: ${escapeHtml(data.error)}</pre>
            `;
        }

        output.appendChild(resultDiv);
        output.scrollTop = output.scrollHeight;
    })
    .catch(err => {
        // Remove loading indicator
        if (output.contains(loadingDiv)) {
            output.removeChild(loadingDiv);
        }

        // Add error
        const errorDiv = document.createElement('div');
        errorDiv.innerHTML = `
            <pre style="color: rgb(248 113 113); background: rgb(127 29 29); padding: 10px; border-radius: 4px; margin: 5px 0 15px 0;">Network error: ${escapeHtml(err.message)}</pre>
        `;

        output.appendChild(errorDiv);
        output.scrollTop = output.scrollHeight;
    });
}

/**
 * ENTERPRISE GALAXY: Run docker-stats-monitor.sh (snapshot mode)
 * Shows current container stats in terminal
 * UPDATED (2025-01-10):
 *   - Uses 'sh' for Alpine BusyBox compatibility (POSIX-compliant)
 *   - Uses RELATIVE PATH (terminalExec() chdir to APP_ROOT = /var/www/html in container)
 *   - Snapshot mode (1 iteration) for instant results (shell_exec waits for completion)
 */
function runDockerStatsMonitor() {
    const output = document.getElementById('terminal-output');
    const timestamp = new Date().toLocaleTimeString();

    // Add command to output
    const commandDiv = document.createElement('div');
    commandDiv.innerHTML = `
        <div style="color: #9333ea; margin: 10px 0;">
            <strong>$ sh scripts/docker-stats-monitor.sh 1</strong>
            <div style="color: rgb(156 163 175); font-size: 12px; margin-top: 5px;">
                Docker Stats Monitor (snapshot mode)...
            </div>
        </div>
    `;
    output.appendChild(commandDiv);

    // Add loading indicator
    const loadingDiv = document.createElement('div');
    loadingDiv.innerHTML = `<p style="color: rgb(156 163 175);">[${timestamp}] Executing monitoring script...</p>`;
    output.appendChild(loadingDiv);
    output.scrollTop = output.scrollHeight;

    // Execute command with RELATIVE PATH (APP_ROOT = /var/www/html in container)
    fetch('terminal-exec', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `command=${encodeURIComponent('sh scripts/docker-stats-monitor.sh 1')}`
    })
    .then(response => response.json())
    .then(data => {
        // Remove loading indicator
        output.removeChild(loadingDiv);

        // Add result
        const resultDiv = document.createElement('div');
        if (data.success) {
            resultDiv.innerHTML = `
                <pre style="color: rgb(74 222 128); background: rgb(17 24 39); padding: 15px; border-radius: 4px; margin: 5px 0 15px 0; white-space: pre-wrap; word-wrap: break-word; font-size: 13px; line-height: 1.5;">${escapeHtml(data.output)}</pre>
                <div style="color: rgb(156 163 175); font-size: 11px; margin-bottom: 15px;">
                    ✅ Monitoring completed | Execution time: ${data.execution_time}
                </div>
            `;
        } else {
            resultDiv.innerHTML = `
                <pre style="color: rgb(248 113 113); background: rgb(127 29 29); padding: 10px; border-radius: 4px; margin: 5px 0 15px 0;">Error: ${escapeHtml(data.error)}</pre>
            `;
        }

        output.appendChild(resultDiv);
        output.scrollTop = output.scrollHeight;
    })
    .catch(err => {
        // Remove loading indicator
        if (output.contains(loadingDiv)) {
            output.removeChild(loadingDiv);
        }

        // Add error
        const errorDiv = document.createElement('div');
        errorDiv.innerHTML = `
            <pre style="color: rgb(248 113 113); background: rgb(127 29 29); padding: 10px; border-radius: 4px; margin: 5px 0 15px 0;">Network error: ${escapeHtml(err.message)}</pre>
        `;

        output.appendChild(errorDiv);
        output.scrollTop = output.scrollHeight;
    });
}

/**
 * Clear terminal output
 */
function clearTerminal() {
    const output = document.getElementById('terminal-output');
    output.innerHTML = '<p class="text-muted">Enter a command above and press Enter to execute...</p>';
}

/**
 * Escape HTML to prevent XSS
 * @param {string} text - Text to escape
 * @returns {string} Escaped HTML
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Focus on command input when page loads
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('command-input').focus();
});
</script>