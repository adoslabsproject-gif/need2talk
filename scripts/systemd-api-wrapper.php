#!/usr/bin/env php
<?php
/**
 * Systemd API Wrapper - Enterprise Galaxy
 *
 * Script che gira SULL'HOST (non nel container) e accetta comandi systemd
 * via HTTP da PHP container. Questo bypassa il problema container/host.
 *
 * Usage: Viene chiamato via nginx reverse proxy da /api/host/systemd/*
 */

// SECURITY: Solo da localhost
if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' &&
    ($_SERVER['REMOTE_ADDR'] ?? '') !== '::1') {
    http_response_code(403);
    die(json_encode(['error' => 'Access denied']));
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$service = 'need2talk-email-workers';

$allowedActions = ['status', 'start', 'stop', 'restart', 'enable', 'disable', 'is-active', 'is-enabled', 'logs'];

if (!in_array($action, $allowedActions)) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid action']));
}

// Execute systemctl command
switch ($action) {
    case 'status':
        exec("systemctl status $service --no-pager", $output, $code);
        // Parse status
        $isActive = false;
        $isEnabled = false;
        $uptime = 'Unknown';

        foreach ($output as $line) {
            if (strpos($line, 'Active:') !== false) {
                $isActive = (strpos($line, 'active (running)') !== false);
                if (preg_match('/since (.+?);/', $line, $matches)) {
                    $since = strtotime($matches[1]);
                    $diff = time() - $since;
                    $hours = floor($diff / 3600);
                    $minutes = floor(($diff % 3600) / 60);
                    $uptime = $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
                }
            }
        }

        exec("systemctl is-enabled $service", $enabledOutput, $enabledCode);
        $isEnabled = ($enabledCode === 0);

        // Count workers in Docker
        exec("docker exec need2talk_php ps aux | grep email-worker.php | grep -v grep | wc -l", $workerCount);
        $workerCount = (int) trim($workerCount[0] ?? 0);

        // Docker stats
        exec("docker stats need2talk_php --no-stream --format '{{.MemUsage}}|{{.CPUPerc}}'", $statsOutput);
        $stats = explode('|', $statsOutput[0] ?? '0 / 0|0%');

        // Recent logs
        exec("journalctl -u $service -n 5 --no-pager", $logs);

        echo json_encode([
            'success' => true,
            'status' => $isActive ? 'running' : 'stopped',
            'active' => $isActive,
            'enabled' => $isEnabled,
            'workers' => $workerCount,
            'uptime' => $uptime,
            'memory' => trim($stats[0]),
            'cpu' => trim($stats[1]),
            'service_name' => $service,
            'restart_policy' => 'always',
            'restart_delay' => '10s',
            'recent_logs' => $logs,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
        break;

    case 'start':
        exec("systemctl start $service 2>&1", $output, $code);
        sleep(2);
        echo json_encode([
            'success' => ($code === 0),
            'message' => $code === 0 ? 'Service started' : 'Failed to start: ' . implode("\n", $output),
        ]);
        break;

    case 'stop':
        exec("systemctl stop $service 2>&1", $output, $code);
        sleep(2);
        echo json_encode([
            'success' => ($code === 0),
            'message' => $code === 0 ? 'Service stopped' : 'Failed to stop: ' . implode("\n", $output),
        ]);
        break;

    case 'restart':
        exec("systemctl restart $service 2>&1", $output, $code);
        sleep(2);
        echo json_encode([
            'success' => ($code === 0),
            'message' => $code === 0 ? 'Service restarted' : 'Failed to restart: ' . implode("\n", $output),
        ]);
        break;

    case 'enable':
        exec("systemctl enable $service 2>&1", $output, $code);
        echo json_encode([
            'success' => ($code === 0),
            'message' => $code === 0 ? 'Auto-start enabled' : 'Failed to enable: ' . implode("\n", $output),
        ]);
        break;

    case 'disable':
        exec("systemctl disable $service 2>&1", $output, $code);
        echo json_encode([
            'success' => ($code === 0),
            'message' => $code === 0 ? 'Auto-start disabled' : 'Failed to disable: ' . implode("\n", $output),
        ]);
        break;

    case 'is-active':
        exec("systemctl is-active $service", $output, $code);
        echo json_encode([
            'success' => true,
            'active' => ($code === 0 && trim($output[0]) === 'active'),
        ]);
        break;

    case 'is-enabled':
        exec("systemctl is-enabled $service", $output, $code);
        echo json_encode([
            'success' => true,
            'enabled' => ($code === 0 && trim($output[0]) === 'enabled'),
        ]);
        break;

    case 'logs':
        $lines = (int) ($_GET['lines'] ?? 50);
        exec("journalctl -u $service -n $lines --no-pager", $logs);
        echo json_encode([
            'success' => true,
            'logs' => $logs,
            'lines' => count($logs),
        ]);
        break;
}
