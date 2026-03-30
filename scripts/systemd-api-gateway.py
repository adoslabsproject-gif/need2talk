#!/usr/bin/env python3
"""
Systemd API Gateway - Enterprise Galaxy
Python micro-API running on HOST to control systemd services from Docker container
Zero external dependencies - stdlib only
"""

from http.server import BaseHTTPRequestHandler, HTTPServer
import json
import subprocess
import time
from urllib.parse import urlparse, parse_qs

SERVICE_NAME = 'need2talk-email-workers'
ALLOWED_HOSTS = ['127.0.0.1', '::1', 'localhost']
ALLOWED_SUBNETS = ['172.18.']  # Docker bridge network

class SystemdAPIHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        # Security: Only localhost and Docker containers
        client_ip = self.client_address[0]

        # Check if IP is in allowed hosts or subnets
        allowed = (client_ip in ALLOWED_HOSTS or
                   any(client_ip.startswith(subnet) for subnet in ALLOWED_SUBNETS))

        if not allowed:
            self.send_error(403, f"Access denied from {client_ip}")
            return

        # Parse URL
        parsed = urlparse(self.path)
        params = parse_qs(parsed.query)
        action = params.get('action', [''])[0]

        allowed_actions = ['status', 'start', 'stop', 'restart', 'enable', 'disable', 'is-active', 'is-enabled', 'logs']

        if action not in allowed_actions:
            self.send_json_response({'error': 'Invalid action'}, 400)
            return

        try:
            result = self.handle_action(action, params)
            self.send_json_response(result, 200)
        except Exception as e:
            self.send_json_response({'error': str(e)}, 500)

    def handle_action(self, action, params):
        if action == 'status':
            return self.get_status()
        elif action == 'start':
            return self.start_service()
        elif action == 'stop':
            return self.stop_service()
        elif action == 'restart':
            return self.restart_service()
        elif action == 'enable':
            return self.enable_service()
        elif action == 'disable':
            return self.disable_service()
        elif action == 'is-active':
            return self.is_active()
        elif action == 'is-enabled':
            return self.is_enabled()
        elif action == 'logs':
            lines = int(params.get('lines', ['50'])[0])
            return self.get_logs(lines)

    def get_status(self):
        # Get systemctl status
        result = subprocess.run(['systemctl', 'status', SERVICE_NAME, '--no-pager'],
                              capture_output=True, text=True)

        # Parse status
        is_active = False
        uptime = 'Unknown'

        for line in result.stdout.split('\n'):
            if 'Active:' in line:
                # ENTERPRISE FIX: Accept both "active (running)" and "active (exited)"
                is_active = 'active (running)' in line or 'active (exited)' in line
                # Extract uptime
                if 'since' in line:
                    import re
                    match = re.search(r'since (.+?);', line)
                    if match:
                        since_str = match.group(1)
                        try:
                            since = time.mktime(time.strptime(since_str, '%a %Y-%m-%d %H:%M:%S %Z'))
                            diff = int(time.time() - since)
                            hours = diff // 3600
                            minutes = (diff % 3600) // 60
                            uptime = f"{hours}h {minutes}m" if hours > 0 else f"{minutes}m"
                        except:
                            pass

        # Check if enabled
        enabled_result = subprocess.run(['systemctl', 'is-enabled', SERVICE_NAME],
                                       capture_output=True, text=True)
        is_enabled = (enabled_result.returncode == 0)

        # Count workers in Docker
        docker_result = subprocess.run(['docker', 'exec', 'need2talk_php', 'ps', 'aux'],
                                      capture_output=True, text=True)
        worker_count = sum(1 for line in docker_result.stdout.split('\n')
                          if 'email-worker.php' in line and 'grep' not in line)

        # Get Docker stats
        stats_result = subprocess.run(['docker', 'stats', 'need2talk_php', '--no-stream',
                                      '--format', '{{.MemUsage}}|{{.CPUPerc}}'],
                                     capture_output=True, text=True)
        memory, cpu = '0 / 0', '0%'
        if stats_result.returncode == 0 and '|' in stats_result.stdout:
            memory, cpu = stats_result.stdout.strip().split('|')

        # Get recent logs
        logs_result = subprocess.run(['journalctl', '-u', SERVICE_NAME, '-n', '5', '--no-pager'],
                                    capture_output=True, text=True)
        recent_logs = [line for line in logs_result.stdout.split('\n') if line.strip()]

        return {
            'success': True,
            'status': 'running' if is_active else 'stopped',
            'active': is_active,
            'enabled': is_enabled,
            'workers': worker_count,
            'uptime': uptime,
            'memory': memory,
            'cpu': cpu,
            'service_name': SERVICE_NAME,
            'restart_policy': 'always',
            'restart_delay': '10s',
            'recent_logs': recent_logs,
            'timestamp': time.strftime('%Y-%m-%d %H:%M:%S')
        }

    def start_service(self):
        result = subprocess.run(['systemctl', 'start', SERVICE_NAME],
                              capture_output=True, text=True)
        time.sleep(2)
        return {
            'success': result.returncode == 0,
            'message': 'Service started' if result.returncode == 0 else f'Failed: {result.stderr}'
        }

    def stop_service(self):
        result = subprocess.run(['systemctl', 'stop', SERVICE_NAME],
                              capture_output=True, text=True)
        time.sleep(2)
        return {
            'success': result.returncode == 0,
            'message': 'Service stopped' if result.returncode == 0 else f'Failed: {result.stderr}'
        }

    def restart_service(self):
        result = subprocess.run(['systemctl', 'restart', SERVICE_NAME],
                              capture_output=True, text=True)
        time.sleep(2)
        return {
            'success': result.returncode == 0,
            'message': 'Service restarted' if result.returncode == 0 else f'Failed: {result.stderr}'
        }

    def enable_service(self):
        result = subprocess.run(['systemctl', 'enable', SERVICE_NAME],
                              capture_output=True, text=True)
        return {
            'success': result.returncode == 0,
            'message': 'Auto-start enabled' if result.returncode == 0 else f'Failed: {result.stderr}'
        }

    def disable_service(self):
        result = subprocess.run(['systemctl', 'disable', SERVICE_NAME],
                              capture_output=True, text=True)
        return {
            'success': result.returncode == 0,
            'message': 'Auto-start disabled' if result.returncode == 0 else f'Failed: {result.stderr}'
        }

    def is_active(self):
        result = subprocess.run(['systemctl', 'is-active', SERVICE_NAME],
                              capture_output=True, text=True)
        return {
            'success': True,
            'active': result.returncode == 0 and result.stdout.strip() == 'active'
        }

    def is_enabled(self):
        result = subprocess.run(['systemctl', 'is-enabled', SERVICE_NAME],
                              capture_output=True, text=True)
        return {
            'success': True,
            'enabled': result.returncode == 0 and result.stdout.strip() == 'enabled'
        }

    def get_logs(self, lines=50):
        result = subprocess.run(['journalctl', '-u', SERVICE_NAME, '-n', str(lines), '--no-pager'],
                              capture_output=True, text=True)
        logs = [line for line in result.stdout.split('\n') if line.strip()]
        return {
            'success': True,
            'logs': logs,
            'lines': len(logs)
        }

    def send_json_response(self, data, status_code):
        self.send_response(status_code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Access-Control-Allow-Origin', '*')
        self.end_headers()
        self.wfile.write(json.dumps(data).encode())

    def log_message(self, format, *args):
        # Disable default logging (too verbose)
        pass

def run_server(port=9999):
    server = HTTPServer(('127.0.0.1', port), SystemdAPIHandler)
    print(f'🚀 Systemd API Gateway listening on http://0.0.0.0:{port}')
    print(f'🔒 Security: Localhost + Docker bridge (172.18.0.0/16)')
    print(f'🎯 Service: {SERVICE_NAME}')
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print('\n🛑 Shutting down...')
        server.shutdown()

if __name__ == '__main__':
    run_server()
