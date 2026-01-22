-- Migration 23: Add per-tenant shift permissions settings
-- Adds a JSON TEXT column to store who can manage shifts and approve shift requests.
--
-- NOTE: super_admin is always allowed; stored JSON is for admin/manager/user toggles.
--
-- Date: 2025-12-20

ALTER TABLE tenants
  ADD COLUMN shift_permissions TEXT NULL DEFAULT NULL
  AFTER tenant_role_assignment_roles;

