-- Rollback script for test patch
-- This removes the test table created by the test patch

DROP TABLE IF EXISTS test_patch_table;

