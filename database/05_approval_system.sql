-- Module: CollaboraNexio Document Approval System
-- Version: 2025-09-25
-- Description: Adds document approval workflow and multi-tenant user access

USE collaboranexio;

-- ============================================
-- UPDATE ROLE ENUM IN USERS TABLE
-- ============================================
ALTER TABLE users
MODIFY COLUMN role ENUM('user', 'manager', 'admin', 'super_admin') DEFAULT 'user';

-- Update existing roles to new values
UPDATE users SET role = 'user' WHERE role = 'guest';
UPDATE users SET role = 'admin' WHERE role IN ('admin', 'administrator');

-- ============================================
-- ADD STATUS FIELD TO FILES TABLE
-- ============================================
ALTER TABLE files
ADD COLUMN status ENUM('bozza', 'in_approvazione', 'approvato', 'rifiutato') DEFAULT 'in_approvazione' AFTER is_public,
ADD COLUMN approved_by INT UNSIGNED NULL AFTER status,
ADD COLUMN approved_at TIMESTAMP NULL AFTER approved_by,
ADD COLUMN rejection_reason TEXT NULL AFTER approved_at,
ADD INDEX idx_file_status (status),
ADD CONSTRAINT fk_file_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL;

-- ============================================
-- TABLE: USER_TENANT_ACCESS (Multi-tenant access for Admin and Super Admin)
-- ============================================
CREATE TABLE IF NOT EXISTS user_tenant_access (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    tenant_id INT UNSIGNED NOT NULL,
    granted_by INT UNSIGNED NULL,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uk_user_tenant (user_id, tenant_id),
    INDEX idx_access_user (user_id),
    INDEX idx_access_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: DOCUMENT_APPROVALS (Approval history and workflow)
-- ============================================
CREATE TABLE IF NOT EXISTS document_approvals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    file_id INT UNSIGNED NOT NULL,
    requested_by INT UNSIGNED NOT NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
    action ENUM('approvato', 'rifiutato', 'in_attesa') DEFAULT 'in_attesa',
    comments TEXT NULL,
    version_at_approval INT UNSIGNED NULL,
    metadata JSON NULL,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_approval_tenant_status (tenant_id, action),
    INDEX idx_approval_file (file_id),
    INDEX idx_approval_reviewer (reviewed_by),
    INDEX idx_approval_requested (requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: APPROVAL_NOTIFICATIONS (Notify users about pending approvals)
-- ============================================
CREATE TABLE IF NOT EXISTS approval_notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    approval_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    notification_type ENUM('richiesta', 'approvato', 'rifiutato', 'promemoria') DEFAULT 'richiesta',
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (approval_id) REFERENCES document_approvals(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notif_user_unread (user_id, is_read),
    INDEX idx_notif_approval (approval_id),
    INDEX idx_notif_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- UPDATE EXISTING DATA
-- ============================================

-- Set all existing files to approved status (to maintain compatibility)
UPDATE files SET status = 'approvato' WHERE status IS NULL;

-- Add current users to user_tenant_access table based on their role
-- Admin and Super Admin users should have access to their tenant
INSERT INTO user_tenant_access (user_id, tenant_id)
SELECT DISTINCT u.id, u.tenant_id
FROM users u
WHERE u.role IN ('admin', 'super_admin')
AND NOT EXISTS (
    SELECT 1 FROM user_tenant_access uta
    WHERE uta.user_id = u.id AND uta.tenant_id = u.tenant_id
);

-- ============================================
-- CREATE STORED PROCEDURES
-- ============================================

DELIMITER //

-- Procedure to check if user can approve documents
CREATE PROCEDURE IF NOT EXISTS CanApproveDocuments(
    IN p_user_id INT,
    IN p_tenant_id INT,
    OUT p_can_approve BOOLEAN
)
BEGIN
    DECLARE user_role VARCHAR(20);
    DECLARE has_tenant_access BOOLEAN DEFAULT FALSE;

    -- Get user role
    SELECT role INTO user_role
    FROM users
    WHERE id = p_user_id AND deleted_at IS NULL;

    -- Check if user has access to the tenant
    IF user_role = 'super_admin' THEN
        SET has_tenant_access = TRUE;
    ELSEIF user_role IN ('admin', 'manager') THEN
        SELECT COUNT(*) > 0 INTO has_tenant_access
        FROM users
        WHERE id = p_user_id
        AND tenant_id = p_tenant_id
        AND deleted_at IS NULL;
    END IF;

    -- User can approve if they are manager, admin, or super_admin with tenant access
    SET p_can_approve = (user_role IN ('manager', 'admin', 'super_admin') AND has_tenant_access);
END //

-- Procedure to get user's accessible tenants
CREATE PROCEDURE IF NOT EXISTS GetUserTenants(
    IN p_user_id INT
)
BEGIN
    DECLARE user_role VARCHAR(20);

    SELECT role INTO user_role
    FROM users
    WHERE id = p_user_id AND deleted_at IS NULL;

    IF user_role = 'super_admin' THEN
        -- Super admin can access all tenants
        SELECT * FROM tenants WHERE status = 'active';
    ELSEIF user_role = 'admin' THEN
        -- Admin can access tenants explicitly granted
        SELECT DISTINCT t.*
        FROM tenants t
        INNER JOIN user_tenant_access uta ON t.id = uta.tenant_id
        WHERE uta.user_id = p_user_id
        AND t.status = 'active';
    ELSE
        -- Regular users and managers access only their assigned tenant
        SELECT t.*
        FROM tenants t
        INNER JOIN users u ON t.id = u.tenant_id
        WHERE u.id = p_user_id
        AND t.status = 'active';
    END IF;
END //

DELIMITER ;

-- ============================================
-- SAMPLE DATA FOR TESTING
-- ============================================

-- Add a super admin user (if not exists)
INSERT IGNORE INTO users (
    tenant_id, email, password_hash, first_name, last_name,
    role, status, email_verified_at
) VALUES (
    1, 'superadmin@collaboranexio.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'Super', 'Admin', 'super_admin', 'active', NOW()
);

-- Grant super admin access to all tenants (for testing)
INSERT IGNORE INTO user_tenant_access (user_id, tenant_id)
SELECT
    (SELECT id FROM users WHERE email = 'superadmin@collaboranexio.com'),
    id
FROM tenants;

-- Update some existing users to have proper roles
UPDATE users SET role = 'manager' WHERE id = 2 AND role != 'manager';
UPDATE users SET role = 'user' WHERE id IN (3, 5) AND role NOT IN ('user');

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'Document approval system created successfully' as Status,
       (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = 'collaboranexio'
        AND TABLE_NAME = 'files'
        AND COLUMN_NAME = 'status') as FileStatusAdded,
       (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = 'collaboranexio'
        AND TABLE_NAME IN ('document_approvals', 'user_tenant_access', 'approval_notifications')) as NewTablesCreated,
       NOW() as CompletedAt;