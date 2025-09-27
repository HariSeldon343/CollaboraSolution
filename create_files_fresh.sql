-- Create Files Table Fresh
SET FOREIGN_KEY_CHECKS = 0;

-- Drop existing if any
DROP TABLE IF EXISTS file_activity_logs;
DROP TABLE IF EXISTS file_permissions;
DROP VIEW IF EXISTS active_files;
DROP TABLE IF EXISTS files;

-- Create new files table
CREATE TABLE files (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500),
    file_type VARCHAR(50),
    file_size BIGINT DEFAULT 0,
    mime_type VARCHAR(100),
    is_folder BOOLEAN DEFAULT 0,
    folder_id INT UNSIGNED NULL,
    uploaded_by INT,
    original_name VARCHAR(255),
    is_public BOOLEAN DEFAULT 0,
    public_token VARCHAR(64),
    shared_with JSON,
    download_count INT DEFAULT 0,
    last_accessed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    INDEX idx_tenant (tenant_id),
    INDEX idx_folder (folder_id),
    INDEX idx_deleted (deleted_at),
    INDEX idx_name (name),
    INDEX idx_type (file_type),
    INDEX idx_uploaded_by (uploaded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add self-referencing foreign key for folder hierarchy
ALTER TABLE files ADD CONSTRAINT fk_files_folder
    FOREIGN KEY (folder_id) REFERENCES files(id) ON DELETE CASCADE;

-- Insert sample folders for each tenant
INSERT INTO files (tenant_id, name, is_folder, uploaded_by, file_path, file_type) VALUES
(1, 'Documents', 1, 1, '/documents', 'folder'),
(1, 'Images', 1, 1, '/images', 'folder'),
(1, 'Reports', 1, 1, '/reports', 'folder'),
(2, 'Documents', 1, 1, '/documents', 'folder'),
(2, 'Images', 1, 1, '/images', 'folder'),
(2, 'Reports', 1, 1, '/reports', 'folder');

-- Insert sample files
INSERT INTO files (tenant_id, name, file_path, file_type, file_size, mime_type, folder_id, uploaded_by, original_name) VALUES
(1, 'Report_Q1_2024.pdf', '/files/report_q1_2024.pdf', 'pdf', 2515968, 'application/pdf', 3, 1, 'Report_Q1_2024.pdf'),
(1, 'Analytics.xlsx', '/files/analytics.xlsx', 'xlsx', 876544, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 3, 1, 'Analytics.xlsx'),
(1, 'Presentation.pptx', '/files/presentation.pptx', 'pptx', 12894208, 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 1, 1, 'Presentation.pptx'),
(1, 'Logo.png', '/files/logo.png', 'png', 126976, 'image/png', 2, 1, 'Logo.png'),
(1, 'Meeting_Notes.docx', '/files/meeting_notes.docx', 'docx', 46080, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 1, 1, 'Meeting_Notes.docx');

-- Create view for active files
CREATE VIEW active_files AS
SELECT * FROM files
WHERE deleted_at IS NULL
ORDER BY is_folder DESC, name ASC;

SET FOREIGN_KEY_CHECKS = 1;

-- Show results
SELECT 'Files table created successfully!' as Status;
SELECT COUNT(*) as 'Total Records' FROM files;
SELECT COUNT(*) as 'Folders' FROM files WHERE is_folder = 1;
SELECT COUNT(*) as 'Files' FROM files WHERE is_folder = 0;