-- Function: remove_accents
-- Version: 2025-10-12
-- Author: Database Architect
-- Description: Removes Italian accents and normalizes text for search optimization

USE collaboranexio;

-- Drop function if exists (for safe re-running)
DROP FUNCTION IF EXISTS remove_accents;

DELIMITER $$

CREATE FUNCTION remove_accents(input_text VARCHAR(255))
RETURNS VARCHAR(255)
DETERMINISTIC
NO SQL
COMMENT 'Removes Italian accents and normalizes text for search'
BEGIN
    DECLARE normalized_text VARCHAR(255);

    -- Start with input text
    SET normalized_text = input_text;

    -- Convert to lowercase first
    SET normalized_text = LOWER(normalized_text);

    -- Replace Italian accented vowels
    -- à → a
    SET normalized_text = REPLACE(normalized_text, 'à', 'a');

    -- è → e
    SET normalized_text = REPLACE(normalized_text, 'è', 'e');

    -- é → e
    SET normalized_text = REPLACE(normalized_text, 'é', 'e');

    -- ì → i
    SET normalized_text = REPLACE(normalized_text, 'ì', 'i');

    -- ò → o
    SET normalized_text = REPLACE(normalized_text, 'ò', 'o');

    -- ù → u
    SET normalized_text = REPLACE(normalized_text, 'ù', 'u');

    -- Replace apostrophes and hyphens with spaces for better search
    SET normalized_text = REPLACE(normalized_text, '''', ' ');
    SET normalized_text = REPLACE(normalized_text, '-', ' ');

    -- Trim and collapse multiple spaces to single space
    SET normalized_text = TRIM(normalized_text);
    WHILE LOCATE('  ', normalized_text) > 0 DO
        SET normalized_text = REPLACE(normalized_text, '  ', ' ');
    END WHILE;

    RETURN normalized_text;
END$$

DELIMITER ;

-- ============================================
-- VERIFICATION
-- ============================================
-- Test the function with various Italian municipality names
SELECT
    'Function test' as test_name,
    remove_accents('L''Aquila') as test1_result,
    remove_accents('Forlì-Cesena') as test2_result,
    remove_accents('Valle d''Aosta') as test3_result,
    remove_accents('Sant''Anastasia') as test4_result;

-- Expected results:
-- test1_result: 'l aquila'
-- test2_result: 'forli cesena'
-- test3_result: 'valle d aosta'
-- test4_result: 'sant anastasia'
