#!/usr/bin/env python3
"""
Sentinel Chat Platform - Python WebSocket Server (Primary)
Port: 4291

Spawns and monitors Node.js WebSocket server (port 8420) as secondary.
Reports status of both systems.
"""

import subprocess
import time
import threading
import json
import os
import sys
import signal
import http.server
import socketserver
from urllib.parse import urlparse, parse_qs
from http.server import HTTPServer, BaseHTTPRequestHandler
# websocket import not needed - this script only spawns Node.js
# import websocket
import _thread

# Configuration
PYTHON_WS_PORT = 4291
NODE_WS_PORT = 8420
NODE_SCRIPT = os.path.join(os.path.dirname(__file__), 'websocket-server.js')
LOG_FILE = os.path.join(os.path.dirname(__file__), 'logs', 'websocket-python.log')
PID_FILE = os.path.join(os.path.dirname(__file__), 'logs', 'websocket-python.pid')

# Global state
node_process = None
monitor_thread = None
server_start_time = time.time()
connected_clients = {}
room_subscriptions = {}
node_restart_count = 0  # Track Node.js restarts

def log_to_file(message):
    """Log message to file"""
    try:
        os.makedirs(os.path.dirname(LOG_FILE), exist_ok=True)
        with open(LOG_FILE, 'a', encoding='utf-8') as f:
            timestamp = time.strftime('%Y-%m-%d %H:%M:%S')
            f.write(f"[{timestamp}] {message}\n")
    except Exception as e:
        print(f"Log error: {e}")

def start_node_wss():
    """Spawn Node.js WebSocket server"""
    global node_process
    
    if node_process and node_process.poll() is None:
        log_to_file("Node.js process already running")
        return node_process
    
    log_to_file(f"Starting Node.js WebSocket server on port {NODE_WS_PORT}...")
    
    # Set environment variables for Node.js
    env = os.environ.copy()
    env['WS_PORT'] = str(NODE_WS_PORT)
    
    try:
        node_process = subprocess.Popen(
            ["node", NODE_SCRIPT],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            stdin=subprocess.PIPE,
            text=True,
            bufsize=1,
            universal_newlines=True,
            env=env,
            cwd=os.path.dirname(__file__)
        )
        
        # Write PID to file
        try:
            os.makedirs(os.path.dirname(PID_FILE), exist_ok=True)
            with open(PID_FILE, 'w') as f:
                f.write(str(node_process.pid))
        except Exception as e:
            log_to_file(f"Failed to write PID file: {e}")
        
        log_to_file(f"Node.js process started with PID: {node_process.pid}")
        return node_process
    except Exception as e:
        log_to_file(f"Failed to start Node.js process: {e}")
        return None

def monitor_node_process(proc):
    """Monitor Node.js process output (non-blocking)"""
    global node_process
    
    log_to_file("Node.js monitor thread started")
    
    while True:
        if proc is None:
            break
            
        # Read stdout
        line = proc.stdout.readline()
        if not line:
            if proc.poll() is not None:
                break
            time.sleep(0.1)
            continue
            
        if line.strip():
            log_to_file(f"[Node WSS] {line.strip()}")
    
    # Process exited
    exit_code = proc.poll()
    log_to_file(f"Node.js process exited with code {exit_code}")
    
    # Auto-restart logic (optional)
    if exit_code != 0:
        global node_restart_count
        node_restart_count += 1
        log_to_file(f"Node.js process crashed (exit code: {exit_code}), attempting restart in 5 seconds... (restart #{node_restart_count})")
        time.sleep(5)
        if node_process == proc:  # Only restart if it's still the current process
            start_node_wss()
            if node_process:
                monitor_node_process(node_process)

def get_server_stats():
    """Get statistics for both Python and Node.js servers"""
    global node_process, server_start_time, connected_clients, room_subscriptions, node_restart_count
    
    uptime_seconds = int(time.time() - server_start_time)
    hours = uptime_seconds // 3600
    minutes = (uptime_seconds % 3600) // 60
    seconds = uptime_seconds % 60
    uptime_str = f"{hours}h {minutes}m {seconds}s"
    
    node_running = node_process is not None and node_process.poll() is None
    node_pid = node_process.pid if node_process else None
    
    return {
        'python_server': {
            'running': True,
            'port': PYTHON_WS_PORT,
            'pid': os.getpid(),
            'uptime': uptime_str,
            'connected_clients': len(connected_clients),
            'active_rooms': len(room_subscriptions),
            'node_restarts': node_restart_count
        },
        'node_server': {
            'running': node_running,
            'port': NODE_WS_PORT,
            'pid': node_pid,
            'exit_code': node_process.poll() if node_process else None,
            'restart_count': node_restart_count
        }
    }

class StatsHandler(BaseHTTPRequestHandler):
    """HTTP handler for /stats endpoint"""
    def do_GET(self):
        if self.path == '/stats':
            stats = get_server_stats()
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            self.wfile.write(json.dumps({'success': True, 'stats': stats}).encode())
        else:
            self.send_response(404)
            self.end_headers()
    
    def log_message(self, format, *args):
        # Suppress HTTP server logs
        pass

def run_http_server():
    """Run HTTP server for stats endpoint"""
    try:
        httpd = HTTPServer(('localhost', PYTHON_WS_PORT), StatsHandler)
        log_to_file(f"Python HTTP server started on port {PYTHON_WS_PORT}")
        httpd.serve_forever()
    except Exception as e:
        log_to_file(f"HTTP server error: {e}")

def signal_handler(sig, frame):
    """Handle shutdown signals"""
    global node_process, monitor_thread
    
    log_to_file("Shutdown signal received")
    
    if node_process:
        log_to_file("Terminating Node.js process...")
        node_process.terminate()
        try:
            node_process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            log_to_file("Force killing Node.js process...")
            node_process.kill()
    
    sys.exit(0)

def main():
    """Main entry point"""
    global node_process, monitor_thread
    
    # Set up signal handlers
    signal.signal(signal.SIGINT, signal_handler)
    signal.signal(signal.SIGTERM, signal_handler)
    
    log_to_file("=" * 60)
    log_to_file("Python WebSocket Server Starting")
    log_to_file(f"Python WS Port: {PYTHON_WS_PORT}")
    log_to_file(f"Node.js WS Port: {NODE_WS_PORT}")
    log_to_file("=" * 60)
    
    # Start Node.js server
    node_process = start_node_wss()
    
    if not node_process:
        log_to_file("ERROR: Failed to start Node.js server")
        sys.exit(1)
    
    # Start monitoring thread
    monitor_thread = threading.Thread(target=monitor_node_process, args=(node_process,))
    monitor_thread.daemon = True
    monitor_thread.start()
    
    # Start HTTP server for stats
    http_thread = threading.Thread(target=run_http_server)
    http_thread.daemon = True
    http_thread.start()
    
    log_to_file("Python WebSocket server running...")
    log_to_file(f"Stats endpoint: http://localhost:{PYTHON_WS_PORT}/stats")
    log_to_file(f"Node.js WebSocket: ws://localhost:{NODE_WS_PORT}")
    
    # Keep main thread alive
    try:
        while True:
            time.sleep(1)
            # Check if Node.js process is still running
            if node_process.poll() is not None and monitor_thread.is_alive():
                # Process died, monitor thread should restart it
                pass
    except KeyboardInterrupt:
        signal_handler(None, None)

if __name__ == "__main__":
    main()

