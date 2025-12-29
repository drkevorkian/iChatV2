-- Rollback Patch 023: Remove Audit Logging System
-- WARNING: This will delete all audit logs and retention policies
-- Only use if you're sure you want to remove all audit history

DROP TABLE IF EXISTS audit_retention_policy;
DROP TABLE IF EXISTS audit_log;

