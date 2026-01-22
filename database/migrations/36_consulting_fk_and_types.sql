-- ============================================
-- Migration 36: Consulting tables - align UNSIGNED types + add FK/indexes
-- Goal: make schema consistent (PK/FK type match) and add FK where safe.
-- Idempotent via information_schema checks + PREPARE.
-- ============================================

-- Ensure tables exist (migration 34/35 must be applied first)
-- NOTE: avoid returning result-sets (keeps one-click tools robust)
SET @tbl_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consulting_plans');
SET @cnx_m36_noop := 1;
SET @sql := IF(@tbl_exists = 0, 'SET @cnx_m36_noop = @cnx_m36_noop', 'SET @cnx_m36_noop = @cnx_m36_noop');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------
-- Type alignment helpers
-- ----------------------------

-- consulting_plans.id -> INT UNSIGNED
SET @col_unsigned := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plans'
    AND COLUMN_NAME = 'id'
    AND COLUMN_TYPE LIKE '%unsigned%'
);
SET @sql := IF(@col_unsigned = 0, 'ALTER TABLE consulting_plans MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT', 'SET @cnx_m36_noop = @cnx_m36_noop');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consulting_plans.client_tenant_id -> INT UNSIGNED
SET @col_unsigned := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plans'
    AND COLUMN_NAME = 'client_tenant_id'
    AND COLUMN_TYPE LIKE '%unsigned%'
);
SET @sql := IF(@col_unsigned = 0, 'ALTER TABLE consulting_plans MODIFY COLUMN client_tenant_id INT UNSIGNED NOT NULL', 'SET @cnx_m36_noop = @cnx_m36_noop');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consulting_plans.created_by_user_id -> INT UNSIGNED
SET @col_unsigned := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plans'
    AND COLUMN_NAME = 'created_by_user_id'
    AND COLUMN_TYPE LIKE '%unsigned%'
);
SET @sql := IF(@col_unsigned = 0, 'ALTER TABLE consulting_plans MODIFY COLUMN created_by_user_id INT UNSIGNED NOT NULL', 'SET @cnx_m36_noop = @cnx_m36_noop');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consulting_plan_items.id + plan_id -> INT UNSIGNED
SET @col_unsigned := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_items'
    AND COLUMN_NAME = 'id'
    AND COLUMN_TYPE LIKE '%unsigned%'
);
SET @sql := IF(@col_unsigned = 0, 'ALTER TABLE consulting_plan_items MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT', 'SET @cnx_m36_noop = @cnx_m36_noop');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_unsigned := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_items'
    AND COLUMN_NAME = 'plan_id'
    AND COLUMN_TYPE LIKE '%unsigned%'
);
SET @sql := IF(@col_unsigned = 0, 'ALTER TABLE consulting_plan_items MODIFY COLUMN plan_id INT UNSIGNED NOT NULL', 'SET @cnx_m36_noop = @cnx_m36_noop');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consulting_plan_task_links.id + plan_item_id + task_id -> INT UNSIGNED
SET @col_unsigned := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_task_links'
    AND COLUMN_NAME = 'id'
    AND COLUMN_TYPE LIKE '%unsigned%'
);
SET @sql := IF(@col_unsigned = 0, 'ALTER TABLE consulting_plan_task_links MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT', 'SET @cnx_m36_noop = @cnx_m36_noop');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_unsigned := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_task_links'
    AND COLUMN_NAME = 'plan_item_id'
    AND COLUMN_TYPE LIKE '%unsigned%'
);
SET @sql := IF(@col_unsigned = 0, 'ALTER TABLE consulting_plan_task_links MODIFY COLUMN plan_item_id INT UNSIGNED NOT NULL', 'SET @cnx_m36_noop = @cnx_m36_noop');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_unsigned := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_task_links'
    AND COLUMN_NAME = 'task_id'
    AND COLUMN_TYPE LIKE '%unsigned%'
);
SET @sql := IF(@col_unsigned = 0, 'ALTER TABLE consulting_plan_task_links MODIFY COLUMN task_id INT UNSIGNED NOT NULL', 'SET @cnx_m36_noop = @cnx_m36_noop');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consulting_activity_type_overrides.activity_type_id -> INT UNSIGNED
-- consulting_activity_types.id -> INT UNSIGNED (needed for FK correctness)
SET @col_unsigned := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND COLUMN_NAME = 'id'
    AND COLUMN_TYPE LIKE '%unsigned%'
);
SET @sql := IF(@col_unsigned = 0, 'ALTER TABLE consulting_activity_types MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT', 'SET @cnx_m36_noop = @cnx_m36_noop');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consulting_activity_type_overrides.id -> INT UNSIGNED (optional consistency)
SET @col_unsigned := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_type_overrides'
    AND COLUMN_NAME = 'id'
    AND COLUMN_TYPE LIKE '%unsigned%'
);
SET @sql := IF(@col_unsigned = 0, 'ALTER TABLE consulting_activity_type_overrides MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT', 'SET @cnx_m36_noop = @cnx_m36_noop');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_unsigned := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_type_overrides'
    AND COLUMN_NAME = 'activity_type_id'
    AND COLUMN_TYPE LIKE '%unsigned%'
);
SET @sql := IF(@col_unsigned = 0, 'ALTER TABLE consulting_activity_type_overrides MODIFY COLUMN activity_type_id INT UNSIGNED NOT NULL', 'SET @cnx_m36_noop = @cnx_m36_noop');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consulting_plan_consultants.plan_id -> INT UNSIGNED
SET @col_unsigned := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_consultants'
    AND COLUMN_NAME = 'plan_id'
    AND COLUMN_TYPE LIKE '%unsigned%'
);
SET @sql := IF(@col_unsigned = 0, 'ALTER TABLE consulting_plan_consultants MODIFY COLUMN plan_id INT UNSIGNED NOT NULL', 'SET @cnx_m36_noop = @cnx_m36_noop');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consulting_plan_schedule_drafts.id + plan_id -> INT UNSIGNED
SET @col_unsigned := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_schedule_drafts'
    AND COLUMN_NAME = 'id'
    AND COLUMN_TYPE LIKE '%unsigned%'
);
SET @sql := IF(@col_unsigned = 0, 'ALTER TABLE consulting_plan_schedule_drafts MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT', 'SET @cnx_m36_noop = @cnx_m36_noop');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_unsigned := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_schedule_drafts'
    AND COLUMN_NAME = 'plan_id'
    AND COLUMN_TYPE LIKE '%unsigned%'
);
SET @sql := IF(@col_unsigned = 0, 'ALTER TABLE consulting_plan_schedule_drafts MODIFY COLUMN plan_id INT UNSIGNED NOT NULL', 'SET @cnx_m36_noop = @cnx_m36_noop');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------
-- Foreign keys (add if missing)
-- ----------------------------

-- consulting_plans -> tenants/users
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plans'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_cp_client_tenant'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE consulting_plans ADD CONSTRAINT fk_cp_client_tenant FOREIGN KEY (client_tenant_id) REFERENCES tenants(id)',
  'SET @cnx_m36_noop = @cnx_m36_noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plans'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_cp_created_by'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE consulting_plans ADD CONSTRAINT fk_cp_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id)',
  'SET @cnx_m36_noop = @cnx_m36_noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consulting_plan_items -> consulting_plans
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_items'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_cpi_plan'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE consulting_plan_items ADD CONSTRAINT fk_cpi_plan FOREIGN KEY (plan_id) REFERENCES consulting_plans(id)',
  'SET @cnx_m36_noop = @cnx_m36_noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consulting_plan_task_links -> items/tasks
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_task_links'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_cptl_item'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE consulting_plan_task_links ADD CONSTRAINT fk_cptl_item FOREIGN KEY (plan_item_id) REFERENCES consulting_plan_items(id)',
  'SET @cnx_m36_noop = @cnx_m36_noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_task_links'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_cptl_task'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE consulting_plan_task_links ADD CONSTRAINT fk_cptl_task FOREIGN KEY (task_id) REFERENCES tasks(id)',
  'SET @cnx_m36_noop = @cnx_m36_noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consulting_activity_types -> tenants
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_cat_tenant'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE consulting_activity_types ADD CONSTRAINT fk_cat_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)',
  'SET @cnx_m36_noop = @cnx_m36_noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consulting_activity_type_overrides -> tenants + activity_types
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_type_overrides'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_catov_client'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE consulting_activity_type_overrides ADD CONSTRAINT fk_catov_client FOREIGN KEY (client_tenant_id) REFERENCES tenants(id)',
  'SET @cnx_m36_noop = @cnx_m36_noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_type_overrides'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_catov_type'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE consulting_activity_type_overrides ADD CONSTRAINT fk_catov_type FOREIGN KEY (activity_type_id) REFERENCES consulting_activity_types(id)',
  'SET @cnx_m36_noop = @cnx_m36_noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consulting_plan_consultants -> plans/users
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_consultants'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_cpc_plan'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE consulting_plan_consultants ADD CONSTRAINT fk_cpc_plan FOREIGN KEY (plan_id) REFERENCES consulting_plans(id)',
  'SET @cnx_m36_noop = @cnx_m36_noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_consultants'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_cpc_user'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE consulting_plan_consultants ADD CONSTRAINT fk_cpc_user FOREIGN KEY (user_id) REFERENCES users(id)',
  'SET @cnx_m36_noop = @cnx_m36_noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consulting_plan_schedule_drafts -> plans/users/tenants
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_schedule_drafts'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_cpsd_plan'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE consulting_plan_schedule_drafts ADD CONSTRAINT fk_cpsd_plan FOREIGN KEY (plan_id) REFERENCES consulting_plans(id)',
  'SET @cnx_m36_noop = @cnx_m36_noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_schedule_drafts'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_cpsd_assignee'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE consulting_plan_schedule_drafts ADD CONSTRAINT fk_cpsd_assignee FOREIGN KEY (assigned_user_id) REFERENCES users(id)',
  'SET @cnx_m36_noop = @cnx_m36_noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_schedule_drafts'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_cpsd_client'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE consulting_plan_schedule_drafts ADD CONSTRAINT fk_cpsd_client FOREIGN KEY (client_tenant_id) REFERENCES tenants(id)',
  'SET @cnx_m36_noop = @cnx_m36_noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


