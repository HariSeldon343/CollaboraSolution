-- Calendar Events Tables Migration
-- Version: 1.0.0
-- Date: 2025-01-22

-- Drop existing tables if they exist (for clean migration)
DROP TABLE IF EXISTS event_attachments;
DROP TABLE IF EXISTS event_reminders;
DROP TABLE IF EXISTS event_participants;
DROP TABLE IF EXISTS event_resources;
DROP TABLE IF EXISTS events;
DROP TABLE IF EXISTS calendars;

-- Create calendars table
CREATE TABLE IF NOT EXISTS calendars (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    color VARCHAR(7) DEFAULT '#3B82F6',
    owner_id INT UNSIGNED NOT NULL,
    is_default BOOLEAN DEFAULT FALSE,
    visibility ENUM('private', 'public', 'team') DEFAULT 'private',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_tenant_owner (tenant_id, owner_id),
    INDEX idx_default (tenant_id, is_default),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create main events table
CREATE TABLE IF NOT EXISTS events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    calendar_id INT UNSIGNED,

    -- Core event data
    title VARCHAR(255) NOT NULL,
    description TEXT,
    location VARCHAR(500),

    -- Timing
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    all_day BOOLEAN DEFAULT FALSE,
    timezone VARCHAR(50) DEFAULT 'UTC',

    -- Recurrence
    recurrence_rule VARCHAR(500), -- RFC 5545 RRULE
    recurrence_end DATETIME,
    parent_event_id INT UNSIGNED,
    is_exception BOOLEAN DEFAULT FALSE,
    exception_date DATE,

    -- Categorization
    category ENUM('general', 'meeting', 'task', 'reminder', 'review', 'holiday', 'other') DEFAULT 'general',
    color VARCHAR(7) DEFAULT '#3788d8',

    -- Visibility and status
    visibility ENUM('private', 'public', 'team') DEFAULT 'private',
    status ENUM('confirmed', 'tentative', 'cancelled') DEFAULT 'confirmed',

    -- Meeting specific
    meeting_url VARCHAR(500),
    meeting_password VARCHAR(100),

    -- Metadata
    metadata JSON,

    -- Audit fields
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by INT UNSIGNED,

    PRIMARY KEY (id),
    INDEX idx_tenant_calendar (tenant_id, calendar_id),
    INDEX idx_dates (tenant_id, start_date, end_date),
    INDEX idx_category (tenant_id, category),
    INDEX idx_status (tenant_id, status),
    INDEX idx_parent (parent_event_id),
    INDEX idx_deleted (tenant_id, deleted_at),
    INDEX idx_recurrence (tenant_id, recurrence_rule),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create event participants table
CREATE TABLE IF NOT EXISTS event_participants (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,

    -- Response status
    status ENUM('pending', 'accepted', 'declined', 'tentative') DEFAULT 'pending',
    responded_at TIMESTAMP NULL,
    response_message TEXT,

    -- Role
    is_organizer BOOLEAN DEFAULT FALSE,
    is_required BOOLEAN DEFAULT TRUE,

    -- Notifications
    send_notifications BOOLEAN DEFAULT TRUE,

    -- Timestamps
    invited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY unique_participant (event_id, user_id),
    INDEX idx_user_status (user_id, status),
    INDEX idx_event (event_id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create event reminders table
CREATE TABLE IF NOT EXISTS event_reminders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,

    -- Reminder configuration
    type ENUM('email', 'notification', 'sms') DEFAULT 'notification',
    minutes_before INT UNSIGNED NOT NULL,

    -- Status
    sent_at TIMESTAMP NULL,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_event_sent (event_id, sent_at),
    INDEX idx_send_time (event_id, minutes_before),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create event attachments table
CREATE TABLE IF NOT EXISTS event_attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    file_id INT UNSIGNED NOT NULL,

    -- Metadata
    attached_by INT UNSIGNED NOT NULL,
    attached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY unique_attachment (event_id, file_id),
    INDEX idx_event (event_id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (attached_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create event resources table (for rooms, equipment, etc.)
CREATE TABLE IF NOT EXISTS event_resources (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    resource_id INT UNSIGNED NOT NULL,
    resource_type ENUM('room', 'equipment', 'vehicle', 'other') DEFAULT 'room',

    -- Booking details
    quantity INT DEFAULT 1,
    notes TEXT,

    -- Status
    status ENUM('pending', 'confirmed', 'cancelled') DEFAULT 'pending',

    -- Timestamps
    reserved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY unique_resource (event_id, resource_id, resource_type),
    INDEX idx_resource_availability (resource_id, resource_type, status),
    INDEX idx_event (event_id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create event exceptions table (for recurring events)
CREATE TABLE IF NOT EXISTS event_exceptions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_event_id INT UNSIGNED NOT NULL,
    exception_date DATE NOT NULL,

    -- Type of exception
    type ENUM('cancelled', 'modified') DEFAULT 'cancelled',

    -- If modified, store the modified event ID
    modified_event_id INT UNSIGNED,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY unique_exception (parent_event_id, exception_date),
    INDEX idx_parent_date (parent_event_id, exception_date),
    FOREIGN KEY (parent_event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (modified_event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create public holidays table
CREATE TABLE IF NOT EXISTS public_holidays (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    country VARCHAR(2) NOT NULL,
    date DATE NOT NULL,
    name VARCHAR(255) NOT NULL,
    type ENUM('public', 'bank', 'school', 'optional') DEFAULT 'public',
    is_nationwide BOOLEAN DEFAULT TRUE,
    region VARCHAR(100),

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY unique_holiday (country, date, name),
    INDEX idx_country_year (country, date),
    INDEX idx_type (country, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create activity logs table for calendar events
CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50),
    entity_id INT UNSIGNED,
    data JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_tenant_user (tenant_id, user_id),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_type (type),
    INDEX idx_created (created_at),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create notifications table if not exists
CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT,
    data JSON,
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_user_unread (user_id, is_read),
    INDEX idx_type (type),
    INDEX idx_priority (priority),
    INDEX idx_created (created_at),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default calendar for each existing user
INSERT INTO calendars (tenant_id, name, description, color, owner_id, is_default)
SELECT DISTINCT
    u.tenant_id,
    CONCAT(u.name, '''s Calendar'),
    'Personal calendar',
    '#3B82F6',
    u.id,
    TRUE
FROM users u
WHERE NOT EXISTS (
    SELECT 1 FROM calendars c
    WHERE c.owner_id = u.id AND c.is_default = TRUE
);

-- Insert some sample holidays for Italy (IT)
INSERT INTO public_holidays (country, date, name, type) VALUES
('IT', '2024-01-01', 'Capodanno', 'public'),
('IT', '2024-01-06', 'Epifania', 'public'),
('IT', '2024-04-25', 'Festa della Liberazione', 'public'),
('IT', '2024-05-01', 'Festa del Lavoro', 'public'),
('IT', '2024-06-02', 'Festa della Repubblica', 'public'),
('IT', '2024-08-15', 'Ferragosto', 'public'),
('IT', '2024-11-01', 'Ognissanti', 'public'),
('IT', '2024-12-08', 'Immacolata Concezione', 'public'),
('IT', '2024-12-25', 'Natale', 'public'),
('IT', '2024-12-26', 'Santo Stefano', 'public'),
('IT', '2025-01-01', 'Capodanno', 'public'),
('IT', '2025-01-06', 'Epifania', 'public'),
('IT', '2025-04-25', 'Festa della Liberazione', 'public'),
('IT', '2025-05-01', 'Festa del Lavoro', 'public'),
('IT', '2025-06-02', 'Festa della Repubblica', 'public'),
('IT', '2025-08-15', 'Ferragosto', 'public'),
('IT', '2025-11-01', 'Ognissanti', 'public'),
('IT', '2025-12-08', 'Immacolata Concezione', 'public'),
('IT', '2025-12-25', 'Natale', 'public'),
('IT', '2025-12-26', 'Santo Stefano', 'public')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Add indexes for performance
ALTER TABLE events ADD FULLTEXT(title, description, location);
ALTER TABLE calendars ADD FULLTEXT(name, description);