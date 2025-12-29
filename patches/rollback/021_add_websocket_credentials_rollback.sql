-- Rollback Patch 021: Remove WebSocket Server Credentials Table
-- WARNING: This will delete all stored credentials

DROP TABLE IF EXISTS websocket_credentials;

