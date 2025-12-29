-- Rollback: 008_add_room_requests
-- Description: Remove room request system tables
-- Author: Sentinel Chat Platform
-- Date: 2025-12-22

-- Drop room access control table
DROP TABLE IF EXISTS room_access;

-- Drop room requests table
DROP TABLE IF EXISTS room_requests;

