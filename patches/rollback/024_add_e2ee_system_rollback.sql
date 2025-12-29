-- Rollback Patch 024: Remove E2EE System
-- WARNING: This will delete all encryption keys and key exchanges
-- Only use if you're sure you want to remove E2EE functionality

ALTER TABLE im_messages 
    DROP COLUMN IF EXISTS encryption_type,
    DROP COLUMN IF EXISTS nonce;

DROP TABLE IF EXISTS typing_indicators;
DROP TABLE IF EXISTS key_exchanges;
DROP TABLE IF EXISTS user_keys;

