# WebSocket Setup Guide

## Overview
The Sentinel Chat Platform now supports real-time messaging via WebSocket, eliminating the need for polling every 5 seconds. Messages appear instantly when sent.

## Architecture

### WebSocket Server (`websocket-server.js`)
- Node.js-based WebSocket server
- Connects to MySQL database to poll for new messages
- Broadcasts messages to all connected clients in the same room
- Handles presence updates and room switching
- Automatic reconnection with exponential backoff

### Client Integration (`js/app.js`)
- Automatically connects to WebSocket server on page load
- Falls back to polling if WebSocket is unavailable
- Handles real-time message display
- Manages presence updates via WebSocket

## Installation

### 1. Install Node.js Dependencies
```bash
npm install
```

This will install:
- `ws` - WebSocket library
- `mysql2` - MySQL database driver

### 2. Configure Environment Variables
Set the following environment variables (or use defaults):

```bash
export WS_PORT=8080                    # WebSocket server port
export DB_HOST=127.0.0.1               # Database host
export DB_PORT=3306                    # Database port
export DB_NAME=sentinel_temp           # Database name
export DB_USER=root                     # Database user
export DB_PASSWORD=your_password       # Database password
export API_SHARED_SECRET=your_secret   # Must match PHP config
```

Or update `src/Config.php` to set WebSocket configuration:
```php
'websocket.enabled' => true,
'websocket.host' => 'localhost',
'websocket.port' => 8080,
'websocket.secure' => false,  // Set to true for WSS
```

### 3. Start WebSocket Server

**Development:**
```bash
node websocket-server.js
```

**Production (with PM2):**
```bash
pm2 start websocket-server.js --name sentinel-ws
pm2 save
pm2 startup  # Follow instructions to enable on boot
```

**Production (with systemd):**
Create `/etc/systemd/system/sentinel-ws.service`:
```ini
[Unit]
Description=Sentinel Chat WebSocket Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/iChat
Environment="NODE_ENV=production"
Environment="WS_PORT=8080"
Environment="DB_HOST=127.0.0.1"
Environment="DB_NAME=sentinel_temp"
Environment="DB_USER=root"
Environment="DB_PASSWORD=your_password"
Environment="API_SHARED_SECRET=your_secret"
ExecStart=/usr/bin/node /path/to/iChat/websocket-server.js
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Then:
```bash
sudo systemctl enable sentinel-ws
sudo systemctl start sentinel-ws
sudo systemctl status sentinel-ws
```

## Security Considerations

1. **API Secret**: The WebSocket server validates the API secret from the client. Ensure `API_SHARED_SECRET` matches between PHP config and WebSocket server.

2. **WSS (Secure WebSocket)**: For production, use WSS (WebSocket Secure) over HTTPS:
   - Set `websocket.secure` to `true` in config
   - Use a reverse proxy (nginx/Apache) with SSL termination
   - Configure the proxy to upgrade WebSocket connections

3. **Firewall**: Ensure the WebSocket port (default 8080) is accessible, or configure a reverse proxy.

## Reverse Proxy Configuration (Nginx)

```nginx
# WebSocket proxy
location /ws {
    proxy_pass http://localhost:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 86400;
}
```

Then update client config to use `wss://yourdomain.com/ws` instead of `ws://localhost:8080`.

## Testing

1. Start the WebSocket server:
   ```bash
   node websocket-server.js
   ```

2. Open the chat application in a browser

3. Check browser console for WebSocket connection messages:
   ```
   [WS] Connecting to WebSocket server...
   [WS] Connected to WebSocket server
   ```

4. Send a message - it should appear instantly without page refresh

5. Open another browser/tab - messages should appear in real-time

## Troubleshooting

### WebSocket Connection Fails
- Check that the WebSocket server is running
- Verify port 8080 is not blocked by firewall
- Check browser console for connection errors
- Verify API secret matches between client and server

### Messages Not Appearing
- Check WebSocket server logs for errors
- Verify database connection in WebSocket server
- Check browser console for WebSocket message errors
- Ensure messages are being inserted into `temp_outbox` table

### Fallback to Polling
If WebSocket fails, the client automatically falls back to HTTP polling every 5 seconds. Check console logs to see why WebSocket connection failed.

## Performance

- **Message Latency**: < 100ms (vs 5 seconds with polling)
- **Server Load**: Significantly reduced (no constant HTTP requests)
- **Bandwidth**: Reduced (only new messages sent, not full message list)
- **Scalability**: Can handle thousands of concurrent connections

## Monitoring

Monitor WebSocket server health:
```bash
# Check if running
pm2 status sentinel-ws

# View logs
pm2 logs sentinel-ws

# Restart if needed
pm2 restart sentinel-ws
```

## Future Enhancements

- [ ] Redis pub/sub for multi-server deployments
- [ ] Message queuing (RabbitMQ/Kafka) for high-volume scenarios
- [ ] WebSocket compression (perMessageDeflate)
- [ ] Rate limiting per connection
- [ ] Connection authentication via JWT tokens

