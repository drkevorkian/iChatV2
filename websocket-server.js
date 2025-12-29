/**
 * Sentinel Chat Platform - WebSocket Server
 * 
 * Real-time WebSocket server for instant messaging and presence updates.
 * Connects to the PHP backend database for message persistence.
 * 
 * Security: Validates API secrets and user sessions before allowing connections.
 * 
 * Run with: node websocket-server.js
 * Or use PM2: pm2 start websocket-server.js --name sentinel-ws
 */

const WebSocket = require('ws');
const http = require('http');
const mysql = require('mysql2/promise');
const url = require('url');
const crypto = require('crypto');

// Configuration
const WS_PORT = process.env.WS_PORT || 8420; // Default to 8420 (secondary server)
const DB_HOST = process.env.DB_HOST || '127.0.0.1';
const DB_PORT = process.env.DB_PORT || 3306;
const DB_NAME = process.env.DB_NAME || 'sentinel_temp';

// Credentials will be loaded from PHP API on startup
let DB_USER = null;
let DB_PASSWORD = null;
let API_SECRET = null;

// Store connected clients: Map<userHandle, Set<WebSocket>>
const connectedClients = new Map();
// Store room subscriptions: Map<roomId, Set<WebSocket>>
const roomSubscriptions = new Map();
// Store WebSocket metadata: Map<WebSocket, {userHandle, roomId, lastPing}>
const wsMetadata = new Map();

// Server start time for uptime tracking
let serverStartTime = null;

// Create HTTP server
const server = http.createServer();

// Create WebSocket server
const wss = new WebSocket.Server({ 
    server,
    perMessageDeflate: false // Disable compression for better performance
});

// Database connection pool
let dbPool = null;

/**
 * Fetch credentials from PHP API
 */
async function fetchCredentials() {
    try {
        const http = require('http');
        const credentialsUrl = process.env.CREDENTIALS_URL || 'http://localhost/iChat/api/websocket-credentials.php?action=get';
        
        return new Promise((resolve, reject) => {
            const req = http.get(credentialsUrl, (res) => {
                let data = '';
                
                res.on('data', (chunk) => {
                    data += chunk;
                });
                
                res.on('end', () => {
                    try {
                        const response = JSON.parse(data);
                        if (response.success && response.credentials) {
                            resolve(response.credentials);
                        } else {
                            reject(new Error(response.error || 'Failed to fetch credentials'));
                        }
                    } catch (error) {
                        reject(new Error('Failed to parse credentials response: ' + error.message));
                    }
                });
            });
            
            req.on('error', (error) => {
                reject(new Error('Failed to fetch credentials: ' + error.message));
            });
            
            req.setTimeout(5000, () => {
                req.destroy();
                reject(new Error('Credentials request timed out'));
            });
        });
    } catch (error) {
        throw new Error('Error fetching credentials: ' + error.message);
    }
}

/**
 * Initialize database connection pool
 */
async function initDatabase() {
    try {
        // Fetch credentials from PHP API if not set via environment
        if (!DB_USER || !DB_PASSWORD) {
            console.log('[CRED] Fetching credentials from PHP API...');
            try {
                const credentials = await fetchCredentials();
                DB_USER = credentials.db_user || process.env.DB_USER || 'root';
                DB_PASSWORD = credentials.db_password || process.env.DB_PASSWORD || '';
                API_SECRET = credentials.api_secret || process.env.API_SHARED_SECRET || 'change-me-now';
                
                // Validate we have a password
                if (!DB_PASSWORD || DB_PASSWORD === '') {
                    throw new Error('Database password is empty');
                }
                
                console.log('[CRED] Credentials loaded successfully');
            } catch (error) {
                console.error('[CRED] Failed to fetch credentials:', error.message);
                console.log('[CRED] Falling back to environment variables...');
                DB_USER = process.env.DB_USER || 'root';
                DB_PASSWORD = process.env.DB_PASSWORD || '';
                API_SECRET = process.env.API_SHARED_SECRET || 'change-me-now';
                
                // If still no password, try to get from config defaults
                if (!DB_PASSWORD || DB_PASSWORD === '') {
                    console.error('[CRED] No database password available. Please set DB_PASSWORD environment variable or apply patch 021 and configure credentials.');
                    throw new Error('Database password is required but not configured');
                }
            }
        }
        
        dbPool = mysql.createPool({
            host: DB_HOST,
            port: DB_PORT,
            database: DB_NAME,
            user: DB_USER,
            password: DB_PASSWORD,
            charset: 'utf8mb4',
            waitForConnections: true,
            connectionLimit: 10,
            queueLimit: 0
        });
        
        // Test connection
        const connection = await dbPool.getConnection();
        await connection.ping();
        connection.release();
        
        console.log(`[DB] Connected to database: ${DB_NAME}`);
    } catch (error) {
        console.error('[DB] Database connection failed:', error.message);
        process.exit(1);
    }
}

/**
 * Validate WebSocket connection
 */
async function validateConnection(req) {
    const parsedUrl = url.parse(req.url, true);
    const query = parsedUrl.query;
    
    // Get user handle and token from query parameters
    const userHandle = query.user_handle || '';
    const token = query.token || '';
    const apiSecret = query.api_secret || ''; // Legacy support
    
    if (!userHandle) {
        return { valid: false, reason: 'Missing user handle' };
    }
    
    // New token-based authentication (preferred)
    if (token) {
        try {
            const decoded = Buffer.from(token, 'base64').toString('utf8');
            const parts = decoded.split(':');
            
            if (parts.length !== 3) {
                return { valid: false, reason: 'Invalid token format' };
            }
            
            const [tokenUserHandle, expiresAt, tokenHash] = parts;
            const now = Math.floor(Date.now() / 1000);
            
            // Check if token is expired
            if (parseInt(expiresAt) < now) {
                return { valid: false, reason: 'Token expired' };
            }
            
            // Verify token hash
            const tokenData = tokenUserHandle + ':' + expiresAt + ':' + API_SECRET;
            const expectedHash = crypto.createHash('sha256').update(tokenData).digest('hex');
            
            if (!crypto.timingSafeEqual(Buffer.from(tokenHash), Buffer.from(expectedHash))) {
                return { valid: false, reason: 'Invalid token' };
            }
            
            // Verify user handle matches
            if (tokenUserHandle !== userHandle) {
                return { valid: false, reason: 'Token user mismatch' };
            }
            
            return { 
                valid: true, 
                userHandle: userHandle,
                roomId: query.room_id || 'lobby'
            };
        } catch (error) {
            return { valid: false, reason: 'Token validation error: ' + error.message };
        }
    }
    
    // Legacy API secret authentication (fallback)
    if (apiSecret) {
        const providedHash = crypto.createHash('sha256').update(apiSecret).digest('hex');
        const expectedHash = crypto.createHash('sha256').update(API_SECRET).digest('hex');
        
        if (!crypto.timingSafeEqual(Buffer.from(providedHash), Buffer.from(expectedHash))) {
            return { valid: false, reason: 'Invalid API secret' };
        }
        
        return { 
            valid: true, 
            userHandle: userHandle,
            roomId: query.room_id || 'lobby'
        };
    }
    
    return { valid: false, reason: 'Missing authentication (token or api_secret required)' };
}

/**
 * Broadcast message to all clients in a room
 */
function broadcastToRoom(roomId, message, excludeWs = null) {
    const subscribers = roomSubscriptions.get(roomId);
    if (!subscribers || subscribers.size === 0) {
        return;
    }
    
    const messageStr = JSON.stringify(message);
    let sentCount = 0;
    
    subscribers.forEach((ws) => {
        if (ws !== excludeWs && ws.readyState === WebSocket.OPEN) {
            try {
                ws.send(messageStr);
                sentCount++;
            } catch (error) {
                console.error(`[WS] Error sending to client:`, error.message);
            }
        }
    });
    
    console.log(`[WS] Broadcast to room "${roomId}": ${sentCount} clients`);
}

/**
 * Broadcast presence update to all clients in a room
 */
function broadcastPresenceUpdate(roomId, userHandle, status) {
    broadcastToRoom(roomId, {
        type: 'presence_update',
        room_id: roomId,
        user_handle: userHandle,
        status: status,
        timestamp: new Date().toISOString()
    });
}

/**
 * Subscribe WebSocket to a room
 */
function subscribeToRoom(ws, roomId) {
    if (!roomSubscriptions.has(roomId)) {
        roomSubscriptions.set(roomId, new Set());
    }
    roomSubscriptions.get(roomId).add(ws);
    console.log(`[WS] Client subscribed to room: ${roomId}`);
}

/**
 * Unsubscribe WebSocket from a room
 */
function unsubscribeFromRoom(ws, roomId) {
    const subscribers = roomSubscriptions.get(roomId);
    if (subscribers) {
        subscribers.delete(ws);
        if (subscribers.size === 0) {
            roomSubscriptions.delete(roomId);
        }
    }
}

/**
 * Handle new WebSocket connection
 */
wss.on('connection', async (ws, req) => {
    console.log('[WS] New connection attempt');
    
    // Validate connection
    const validation = await validateConnection(req);
    if (!validation.valid) {
        console.log(`[WS] Connection rejected: ${validation.reason}`);
        ws.close(1008, validation.reason);
        return;
    }
    
    const { userHandle, roomId } = validation;
    
    // Store WebSocket metadata
    wsMetadata.set(ws, {
        userHandle: userHandle,
        roomId: roomId,
        lastPing: Date.now()
    });
    
    // Add to connected clients
    if (!connectedClients.has(userHandle)) {
        connectedClients.set(userHandle, new Set());
    }
    connectedClients.get(userHandle).add(ws);
    
    // Subscribe to room
    subscribeToRoom(ws, roomId);
    
    // Broadcast presence update
    broadcastPresenceUpdate(roomId, userHandle, 'online');
    
    logToFile(`[WS] Client connected: ${userHandle} in room "${roomId}"`);
    
    // Send welcome message
    ws.send(JSON.stringify({
        type: 'connected',
        user_handle: userHandle,
        room_id: roomId,
        timestamp: new Date().toISOString()
    }));
    
    // Handle incoming messages
    ws.on('message', async (data) => {
        try {
            const message = JSON.parse(data.toString());
            await handleMessage(ws, message);
        } catch (error) {
            console.error('[WS] Error parsing message:', error.message);
            ws.send(JSON.stringify({
                type: 'error',
                message: 'Invalid message format'
            }));
        }
    });
    
    // Handle ping/pong for keepalive
    ws.on('pong', () => {
        const metadata = wsMetadata.get(ws);
        if (metadata) {
            metadata.lastPing = Date.now();
        }
    });
    
    // Handle connection close
    ws.on('close', () => {
        const metadata = wsMetadata.get(ws);
        if (metadata) {
            const { userHandle: handle, roomId: room } = metadata;
            
            // Remove from connected clients
            const userClients = connectedClients.get(handle);
            if (userClients) {
                userClients.delete(ws);
                if (userClients.size === 0) {
                    connectedClients.delete(handle);
                }
            }
            
            // Unsubscribe from room
            unsubscribeFromRoom(ws, room);
            
            // Broadcast presence update
            broadcastPresenceUpdate(room, handle, 'offline');
            
            logToFile(`[WS] Client disconnected: ${handle} from room "${room}"`);
        }
        
        wsMetadata.delete(ws);
    });
    
    // Handle errors
    ws.on('error', (error) => {
        console.error(`[WS] WebSocket error:`, error.message);
    });
});

/**
 * Get server statistics
 */
function getServerStats() {
    const now = Date.now();
    const uptimeMs = serverStartTime ? now - serverStartTime : 0;
    
    // Count unique connected users
    const uniqueUsers = connectedClients.size;
    
    // Count total connections (a user can have multiple tabs/devices)
    let totalConnections = 0;
    connectedClients.forEach((clients) => {
        totalConnections += clients.size;
    });
    
    // Get list of connected users
    const connectedUsers = Array.from(connectedClients.keys());
    
    // Count rooms with active subscriptions
    const activeRooms = roomSubscriptions.size;
    
    // Get room details
    const roomDetails = {};
    roomSubscriptions.forEach((subscribers, roomId) => {
        roomDetails[roomId] = subscribers.size;
    });
    
    // Format uptime
    const uptimeSeconds = Math.floor(uptimeMs / 1000);
    const days = Math.floor(uptimeSeconds / 86400);
    const hours = Math.floor((uptimeSeconds % 86400) / 3600);
    const minutes = Math.floor((uptimeSeconds % 3600) / 60);
    const seconds = uptimeSeconds % 60;
    
    let uptimeFormatted = '';
    if (days > 0) uptimeFormatted += `${days}d `;
    if (hours > 0) uptimeFormatted += `${hours}h `;
    if (minutes > 0) uptimeFormatted += `${minutes}m `;
    uptimeFormatted += `${seconds}s`;
    
    return {
        uptime: uptimeFormatted.trim(),
        uptime_seconds: uptimeSeconds,
        uptime_ms: uptimeMs,
        start_time: serverStartTime ? new Date(serverStartTime).toISOString() : null,
        connected_users: uniqueUsers,
        total_connections: totalConnections,
        active_rooms: activeRooms,
        users: connectedUsers,
        rooms: roomDetails
    };
}

/**
 * Handle incoming WebSocket messages
 */
async function handleMessage(ws, message) {
    const metadata = wsMetadata.get(ws);
    if (!metadata) {
        ws.close(1008, 'No metadata found');
        return;
    }
    
    const { userHandle, roomId } = metadata;
    
    switch (message.type) {
        case 'ping':
            // Respond to ping
            ws.send(JSON.stringify({ type: 'pong' }));
            break;
            
        case 'typing':
            // Handle typing indicator
            await handleTypingIndicator(ws, message, userHandle);
            break;
            
        case 'read_receipt':
            // Handle read receipt
            await handleReadReceipt(ws, message, userHandle);
            break;
            
        case 'get_stats':
            // Return server statistics
            ws.send(JSON.stringify({
                type: 'server_stats',
                stats: getServerStats(),
                timestamp: new Date().toISOString()
            }));
            break;
            
        case 'join_room':
            // Switch to a different room
            const newRoomId = message.room_id || 'lobby';
            unsubscribeFromRoom(ws, roomId);
            subscribeToRoom(ws, newRoomId);
            metadata.roomId = newRoomId;
            broadcastPresenceUpdate(roomId, userHandle, 'offline');
            broadcastPresenceUpdate(newRoomId, userHandle, 'online');
            
            ws.send(JSON.stringify({
                type: 'room_joined',
                room_id: newRoomId,
                timestamp: new Date().toISOString()
            }));
            break;
            
        case 'leave_room':
            // Leave current room
            unsubscribeFromRoom(ws, roomId);
            broadcastPresenceUpdate(roomId, userHandle, 'offline');
            break;
            
        case 'presence_update':
            // Update presence status
            broadcastPresenceUpdate(roomId, userHandle, message.status || 'online');
            break;
            
        case 'typing':
            // Handle typing indicator
            await handleTypingIndicator(ws, message, userHandle);
            break;
            
        case 'read_receipt':
            // Handle read receipt
            await handleReadReceipt(ws, message, userHandle);
            break;
            
        default:
            console.log(`[WS] Unknown message type: ${message.type}`);
    }
}

/**
 * Handle typing indicator
 */
async function handleTypingIndicator(ws, message, userHandle) {
    const conversationWith = message.conversation_with;
    const isTyping = message.is_typing !== false;
    
    if (!conversationWith) {
        ws.send(JSON.stringify({
            type: 'error',
            message: 'conversation_with is required'
        }));
        return;
    }
    
    // Update database
    if (dbPool) {
        try {
            const connection = await dbPool.getConnection();
            try {
                if (isTyping) {
                    await connection.execute(
                        'INSERT INTO typing_indicators (user_handle, conversation_with, is_typing, last_activity) VALUES (?, ?, TRUE, NOW()) ON DUPLICATE KEY UPDATE is_typing = TRUE, last_activity = NOW()',
                        [userHandle, conversationWith]
                    );
                } else {
                    await connection.execute(
                        'UPDATE typing_indicators SET is_typing = FALSE, last_activity = NOW() WHERE user_handle = ? AND conversation_with = ?',
                        [userHandle, conversationWith]
                    );
                }
            } finally {
                connection.release();
            }
        } catch (error) {
            console.error('[WS] Error updating typing indicator:', error.message);
        }
    }
    
    // Broadcast to conversation partner
    const partnerClients = connectedClients.get(conversationWith);
    if (partnerClients) {
        const typingMessage = JSON.stringify({
            type: 'typing',
            from_user: userHandle,
            is_typing: isTyping,
            timestamp: new Date().toISOString()
        });
        
        partnerClients.forEach((partnerWs) => {
            if (partnerWs.readyState === WebSocket.OPEN) {
                try {
                    partnerWs.send(typingMessage);
                } catch (error) {
                    console.error('[WS] Error sending typing indicator:', error.message);
                }
            }
        });
    }
}

/**
 * Handle read receipt
 */
async function handleReadReceipt(ws, message, userHandle) {
    const messageId = message.message_id;
    const fromUser = message.from_user;
    
    if (!messageId || !fromUser) {
        ws.send(JSON.stringify({
            type: 'error',
            message: 'message_id and from_user are required'
        }));
        return;
    }
    
    // Update database
    if (dbPool) {
        try {
            const connection = await dbPool.getConnection();
            try {
                await connection.execute(
                    'UPDATE im_messages SET read_at = NOW() WHERE id = ? AND to_user = ? AND from_user = ? AND read_at IS NULL',
                    [messageId, userHandle, fromUser]
                );
            } finally {
                connection.release();
            }
        } catch (error) {
            console.error('[WS] Error updating read receipt:', error.message);
        }
    }
    
    // Notify sender
    const senderClients = connectedClients.get(fromUser);
    if (senderClients) {
        const receiptMessage = JSON.stringify({
            type: 'read_receipt',
            message_id: messageId,
            read_by: userHandle,
            is_read: true,
            timestamp: new Date().toISOString()
        });
        
        senderClients.forEach((senderWs) => {
            if (senderWs.readyState === WebSocket.OPEN) {
                try {
                    senderWs.send(receiptMessage);
                } catch (error) {
                    console.error('[WS] Error sending read receipt:', error.message);
                }
            }
        });
    }
}

/**
 * Poll database for new messages and broadcast them
 */
async function pollAndBroadcastMessages() {
    if (!dbPool) {
        return;
    }
    
    try {
        // Get room messages that haven't been delivered yet
        const [roomMessages] = await dbPool.execute(`
            SELECT id, room_id, sender_handle, cipher_blob, filter_version, queued_at,
                   is_hidden, edited_at, edited_by, original_cipher_blob, hidden_by
            FROM temp_outbox
            WHERE delivered_at IS NULL 
              AND deleted_at IS NULL
              AND is_hidden = FALSE
            ORDER BY queued_at ASC
            LIMIT 100
        `);
        
        for (const row of roomMessages) {
            // Broadcast message to room
            broadcastToRoom(row.room_id, {
                type: 'new_message',
                message: {
                    id: row.id.toString(),
                    room_id: row.room_id,
                    sender_handle: row.sender_handle,
                    cipher_blob: row.cipher_blob,
                    filter_version: row.filter_version,
                    queued_at: row.queued_at,
                    is_hidden: row.is_hidden ? 1 : 0,
                    edited_at: row.edited_at,
                    edited_by: row.edited_by,
                    original_cipher_blob: row.original_cipher_blob,
                    hidden_by: row.hidden_by
                },
                timestamp: new Date().toISOString()
            });
            
            // Mark as delivered after a short delay to ensure all clients received it
            setTimeout(async () => {
                try {
                    await dbPool.execute(
                        'UPDATE temp_outbox SET delivered_at = NOW() WHERE id = ?',
                        [row.id]
                    );
                } catch (error) {
                    console.error(`[DB] Error marking message as delivered:`, error.message);
                }
            }, 1000);
        }
        
        // Get IM messages that haven't been delivered yet (sent but not read)
        // We'll track delivered IMs by checking if they've been sent via WebSocket
        // For now, we'll send all unread sent messages
        const [imMessages] = await dbPool.execute(`
            SELECT id, from_user, to_user, cipher_blob, queued_at, status, read_at
            FROM im_messages
            WHERE status = 'sent'
            ORDER BY queued_at DESC
            LIMIT 100
        `);
        
        for (const row of imMessages) {
            // Broadcast IM to recipient if they're connected
            const recipientClients = connectedClients.get(row.to_user);
            if (recipientClients && recipientClients.size > 0) {
                const imMessage = {
                    type: 'new_im',
                    im: {
                        id: row.id.toString(),
                        from_user: row.from_user,
                        to_user: row.to_user,
                        cipher_blob: row.cipher_blob,
                        queued_at: row.queued_at,
                        status: row.status,
                        read_at: row.read_at
                    },
                    timestamp: new Date().toISOString()
                };
                
                // Send to all of recipient's connections
                recipientClients.forEach((ws) => {
                    if (ws.readyState === WebSocket.OPEN) {
                        try {
                            ws.send(JSON.stringify(imMessage));
                        } catch (error) {
                            console.error(`[WS] Error sending IM to client:`, error.message);
                        }
                    }
                });
                
                logToFile(`[WS] Broadcast IM from ${row.from_user} to ${row.to_user}`);
            }
            
            // Also notify sender that message was delivered (if they're connected)
            const senderClients = connectedClients.get(row.from_user);
            if (senderClients && senderClients.size > 0) {
                senderClients.forEach((ws) => {
                    if (ws.readyState === WebSocket.OPEN) {
                        try {
                            ws.send(JSON.stringify({
                                type: 'im_delivered',
                                im_id: row.id.toString(),
                                to_user: row.to_user,
                                timestamp: new Date().toISOString()
                            }));
                        } catch (error) {
                            console.error(`[WS] Error sending IM delivery confirmation:`, error.message);
                        }
                    }
                });
            }
        }
    } catch (error) {
        console.error('[DB] Error polling messages:', error.message);
    }
}

/**
 * Clean up stale connections (ping timeout)
 */
function cleanupStaleConnections() {
    const now = Date.now();
    const TIMEOUT = 60000; // 60 seconds
    
    wsMetadata.forEach((metadata, ws) => {
        if (now - metadata.lastPing > TIMEOUT) {
            logToFile(`[WS] Closing stale connection: ${metadata.userHandle}`);
            ws.close(1000, 'Connection timeout');
        }
    });
}

/**
 * Send ping to all connections
 */
function pingAllConnections() {
    wss.clients.forEach((ws) => {
        if (ws.readyState === WebSocket.OPEN) {
            ws.ping();
        }
    });
}

/**
 * Log to file (if log file path is set)
 */
function logToFile(message) {
    const timestamp = new Date().toISOString();
    const logMessage = `[${timestamp}] ${message}\n`;
    console.log(message);
    
    // Try to write to log file if path is set
    const fs = require('fs');
    const path = require('path');
    const logFilePath = path.join(__dirname, 'logs', 'websocket.log');
    
    try {
        // Ensure logs directory exists
        const logDir = path.dirname(logFilePath);
        if (!fs.existsSync(logDir)) {
            fs.mkdirSync(logDir, { recursive: true });
        }
        
        // Append to log file
        fs.appendFileSync(logFilePath, logMessage, 'utf8');
    } catch (error) {
        // Silently fail if log file can't be written
        // Console logging is sufficient for debugging
    }
}

/**
 * HTTP endpoint to get server stats (for admin API)
 */
function setupHttpEndpoint() {
    server.on('request', (req, res) => {
        const parsedUrl = url.parse(req.url, true);
        
        // CORS headers
        res.setHeader('Access-Control-Allow-Origin', '*');
        res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
        
        if (req.method === 'OPTIONS') {
            res.writeHead(200);
            res.end();
            return;
        }
        
        // Stats endpoint
        if (parsedUrl.pathname === '/stats' && req.method === 'GET') {
            const stats = getServerStats();
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({
                success: true,
                stats: stats
            }));
            return;
        }
        
        // Default: 404
        res.writeHead(404);
        res.end('Not Found');
    });
}

// Start server
async function start() {
    await initDatabase();
    
    // Record server start time
    serverStartTime = Date.now();
    
    // Setup HTTP endpoint for stats
    setupHttpEndpoint();
    
    server.listen(WS_PORT, () => {
        logToFile(`[WS] WebSocket server listening on port ${WS_PORT}`);
        logToFile(`[WS] Connect with: ws://localhost:${WS_PORT}?user_handle=USER&api_secret=SECRET&room_id=ROOM`);
        logToFile(`[WS] Stats endpoint: http://localhost:${WS_PORT}/stats`);
    });
    
    // Poll for new messages every 500ms
    setInterval(pollAndBroadcastMessages, 500);
    
    // Ping all connections every 30 seconds
    setInterval(pingAllConnections, 30000);
    
    // Clean up stale connections every 60 seconds
    setInterval(cleanupStaleConnections, 60000);
    
    // Log stats every 5 minutes
    setInterval(() => {
        const stats = getServerStats();
        logToFile(`[WS] Stats - Uptime: ${stats.uptime}, Users: ${stats.connected_users}, Connections: ${stats.total_connections}, Rooms: ${stats.active_rooms}`);
    }, 300000);
}

// Handle graceful shutdown
process.on('SIGTERM', () => {
    console.log('[WS] Shutting down gracefully...');
    wss.close(() => {
        if (dbPool) {
            dbPool.end();
        }
        server.close(() => {
            process.exit(0);
        });
    });
});

process.on('SIGINT', () => {
    console.log('[WS] Shutting down gracefully...');
    wss.close(() => {
        if (dbPool) {
            dbPool.end();
        }
        server.close(() => {
            process.exit(0);
        });
    });
});

// Start the server
start().catch((error) => {
    console.error('[WS] Failed to start server:', error);
    process.exit(1);
});

