/*
  Purge calendar events (PRODUCTION).
  - Safe default is soft-delete (if columns exist).
  - Set the tenant you want to clean.

  Usage (phpMyAdmin):
    1) Set @tenant_id (or use @all_tenants = 1)
    2) Run the script
*/

SET @tenant_id = 0;      -- set to your tenant id (e.g. 11). If 0 and @all_tenants=1, will purge all tenants.
SET @all_tenants = 0;    -- set to 1 to purge ALL tenants (DANGEROUS).

-- Preview
SELECT tenant_id, COUNT(*) AS events_total
FROM events
WHERE (@all_tenants = 1 OR tenant_id = @tenant_id)
  AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
GROUP BY tenant_id
ORDER BY tenant_id;

START TRANSACTION;

-- Soft-delete events (recommended)
UPDATE events
SET deleted_at = NOW(),
    deleted_by = 0
WHERE (@all_tenants = 1 OR tenant_id = @tenant_id)
  AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00');

COMMIT;

-- Verification
SELECT tenant_id, COUNT(*) AS events_remaining_not_deleted
FROM events
WHERE (@all_tenants = 1 OR tenant_id = @tenant_id)
  AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
GROUP BY tenant_id
ORDER BY tenant_id;


