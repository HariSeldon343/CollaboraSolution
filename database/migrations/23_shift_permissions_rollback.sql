-- Rollback Migration 23: Remove per-tenant shift permissions settings
-- Date: 2025-12-20

ALTER TABLE tenants
  DROP COLUMN shift_permissions;

