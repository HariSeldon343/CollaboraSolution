-- Module: ITALIAN_MUNICIPALITIES_PHASE_1
-- Version: 2025-10-12
-- Author: Database Architect
-- Description: Phase 1 - Add normalized search columns and statistical data to italian_municipalities
-- OpenSpec: COLLAB-2025-003

USE collaboranexio;

-- ============================================
-- PHASE 1: ADD NORMALIZED COLUMNS
-- ============================================

-- Add new columns for search optimization and statistical data
ALTER TABLE italian_municipalities
    ADD COLUMN IF NOT EXISTS name_normalized VARCHAR(100) DEFAULT NULL
        COMMENT 'Lowercase, accent-free municipality name for fast searching',
    ADD COLUMN IF NOT EXISTS name_ascii VARCHAR(100) DEFAULT NULL
        COMMENT 'ASCII-only municipality name (no special characters)',
    ADD COLUMN IF NOT EXISTS region VARCHAR(50) DEFAULT NULL
        COMMENT 'Italian region name (denormalized from provinces)',
    ADD COLUMN IF NOT EXISTS population INT UNSIGNED DEFAULT 0
        COMMENT 'Current population from ISTAT data',
    ADD COLUMN IF NOT EXISTS area_km2 DECIMAL(10,2) DEFAULT 0.00
        COMMENT 'Municipality area in square kilometers',
    ADD COLUMN IF NOT EXISTS istat_updated_at DATE DEFAULT NULL
        COMMENT 'Last update date from ISTAT database';

-- ============================================
-- INDEXES FOR SEARCH OPTIMIZATION
-- ============================================

-- Check and create FULLTEXT index if not exists
SET @fulltext_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE table_schema = 'collaboranexio'
    AND table_name = 'italian_municipalities'
    AND index_name = 'ft_municipality_search'
);

-- Create FULLTEXT index for natural language search
-- Note: MySQL 8.0 requires the table to use InnoDB (already configured)
SET @create_fulltext = IF(@fulltext_exists = 0,
    'CREATE FULLTEXT INDEX ft_municipality_search ON italian_municipalities(name, name_normalized)',
    'SELECT "FULLTEXT index already exists" as status'
);

PREPARE stmt FROM @create_fulltext;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for normalized name search (B-tree for exact/prefix matching)
CREATE INDEX IF NOT EXISTS idx_name_normalized
    ON italian_municipalities(name_normalized);

-- Add composite index for province + normalized name (common validation query pattern)
CREATE INDEX IF NOT EXISTS idx_province_name_normalized
    ON italian_municipalities(province_code, name_normalized);

-- Add index for region filtering (denormalized from provinces)
CREATE INDEX IF NOT EXISTS idx_region
    ON italian_municipalities(region);

-- Add index for population-based queries (demographics)
CREATE INDEX IF NOT EXISTS idx_population
    ON italian_municipalities(population);

-- ============================================
-- POPULATE NORMALIZED COLUMNS
-- ============================================

-- Load the remove_accents function (must be created separately)
-- This migration assumes the function exists

-- Populate name_normalized using the remove_accents function
UPDATE italian_municipalities
SET name_normalized = remove_accents(name)
WHERE name_normalized IS NULL;

-- Populate name_ascii by removing all non-ASCII characters
-- Replace common Italian apostrophes and special chars
UPDATE italian_municipalities
SET name_ascii = TRIM(
    REPLACE(
        REPLACE(
            REPLACE(
                REPLACE(
                    REPLACE(
                        REPLACE(LOWER(name), 'à', 'a'),
                        'è', 'e'
                    ),
                    'é', 'e'
                ),
                'ì', 'i'
            ),
            'ò', 'o'
        ),
        'ù', 'u'
    )
)
WHERE name_ascii IS NULL;

-- Denormalize region from italian_provinces
UPDATE italian_municipalities m
INNER JOIN italian_provinces p ON m.province_code = p.code
SET m.region = p.region
WHERE m.region IS NULL;

-- ============================================
-- ADD CONSTRAINTS
-- ============================================

-- Ensure name_normalized is always populated for new records
-- Note: MySQL doesn't support CHECK constraints with function calls in older versions
-- This is enforced via application logic and triggers

-- Add trigger to auto-populate normalized columns on INSERT
DROP TRIGGER IF EXISTS trg_municipality_before_insert;

DELIMITER $$

CREATE TRIGGER trg_municipality_before_insert
BEFORE INSERT ON italian_municipalities
FOR EACH ROW
BEGIN
    -- Auto-populate name_normalized
    IF NEW.name_normalized IS NULL THEN
        SET NEW.name_normalized = remove_accents(NEW.name);
    END IF;

    -- Auto-populate name_ascii
    IF NEW.name_ascii IS NULL THEN
        SET NEW.name_ascii = TRIM(
            REPLACE(
                REPLACE(
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(LOWER(NEW.name), 'à', 'a'),
                                'è', 'e'
                            ),
                            'é', 'e'
                        ),
                        'ì', 'i'
                    ),
                    'ò', 'o'
                ),
                'ù', 'u'
            )
        );
    END IF;

    -- Auto-populate region from province
    IF NEW.region IS NULL THEN
        SET NEW.region = (
            SELECT region
            FROM italian_provinces
            WHERE code = NEW.province_code
            LIMIT 1
        );
    END IF;
END$$

DELIMITER ;

-- Add trigger to auto-update normalized columns on UPDATE
DROP TRIGGER IF EXISTS trg_municipality_before_update;

DELIMITER $$

CREATE TRIGGER trg_municipality_before_update
BEFORE UPDATE ON italian_municipalities
FOR EACH ROW
BEGIN
    -- Re-calculate name_normalized if name changed
    IF NEW.name != OLD.name OR NEW.name_normalized IS NULL THEN
        SET NEW.name_normalized = remove_accents(NEW.name);
    END IF;

    -- Re-calculate name_ascii if name changed
    IF NEW.name != OLD.name OR NEW.name_ascii IS NULL THEN
        SET NEW.name_ascii = TRIM(
            REPLACE(
                REPLACE(
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(LOWER(NEW.name), 'à', 'a'),
                                'è', 'e'
                            ),
                            'é', 'e'
                        ),
                        'ì', 'i'
                    ),
                    'ò', 'o'
                ),
                'ù', 'u'
            )
        );
    END IF;

    -- Re-calculate region if province_code changed
    IF NEW.province_code != OLD.province_code OR NEW.region IS NULL THEN
        SET NEW.region = (
            SELECT region
            FROM italian_provinces
            WHERE code = NEW.province_code
            LIMIT 1
        );
    END IF;
END$$

DELIMITER ;

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- Verify columns were added
SELECT
    'Column verification' as test_type,
    COUNT(*) as total_municipalities,
    COUNT(name_normalized) as normalized_count,
    COUNT(name_ascii) as ascii_count,
    COUNT(region) as region_count,
    COUNT(CASE WHEN population > 0 THEN 1 END) as populated_count,
    COUNT(CASE WHEN area_km2 > 0 THEN 1 END) as area_count
FROM italian_municipalities;

-- Verify indexes were created
SELECT
    'Index verification' as test_type,
    TABLE_NAME,
    INDEX_NAME,
    INDEX_TYPE,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME = 'italian_municipalities'
AND INDEX_NAME IN (
    'ft_municipality_search',
    'idx_name_normalized',
    'idx_province_name_normalized',
    'idx_region',
    'idx_population'
)
GROUP BY TABLE_NAME, INDEX_NAME, INDEX_TYPE;

-- Test normalized search functionality
SELECT
    'Search test' as test_type,
    name as original_name,
    name_normalized,
    name_ascii,
    region,
    province_code
FROM italian_municipalities
WHERE name_normalized LIKE '%aquila%'
   OR name_normalized LIKE '%forli%'
   OR name_normalized LIKE '%valle d%'
LIMIT 10;

-- Test FULLTEXT search (if index was created)
SELECT
    'FULLTEXT search test' as test_type,
    name,
    name_normalized,
    province_code,
    MATCH(name, name_normalized) AGAINST('roma' IN NATURAL LANGUAGE MODE) as relevance_score
FROM italian_municipalities
WHERE MATCH(name, name_normalized) AGAINST('roma' IN NATURAL LANGUAGE MODE)
ORDER BY relevance_score DESC
LIMIT 5;

-- Regional statistics
SELECT
    'Regional distribution' as test_type,
    region,
    COUNT(*) as municipality_count,
    COUNT(DISTINCT province_code) as province_count
FROM italian_municipalities
GROUP BY region
ORDER BY municipality_count DESC
LIMIT 10;

-- ============================================
-- MIGRATION SUMMARY
-- ============================================
SELECT
    'Migration completed successfully' as status,
    'Phase 1: Normalized columns and indexes' as phase,
    NOW() as execution_time,
    (SELECT COUNT(*) FROM italian_municipalities) as total_municipalities,
    (SELECT COUNT(DISTINCT region) FROM italian_municipalities) as total_regions;
