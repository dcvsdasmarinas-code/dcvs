ALTER TABLE announcements
    ADD COLUMN location VARCHAR(500) DEFAULT NULL AFTER title;

UPDATE announcements AS a
JOIN (
    SELECT
        a_match.announcement_id,
        MAX(p.program_name) AS program_name
    FROM announcements AS a_match
    JOIN programs AS p
      ON UPPER(LEFT(TRIM(a_match.title), CHAR_LENGTH(p.program_name))) = UPPER(p.program_name)
     AND LOWER(SUBSTRING(TRIM(a_match.title), CHAR_LENGTH(p.program_name) + 1, 4)) = ' at '
    GROUP BY a_match.announcement_id
    HAVING COUNT(DISTINCT p.program_id) = 1
) AS matched_program ON matched_program.announcement_id = a.announcement_id
SET a.location = TRIM(SUBSTRING(TRIM(a.title), CHAR_LENGTH(matched_program.program_name) + 5)),
    a.title = LEFT(TRIM(a.title), CHAR_LENGTH(matched_program.program_name)),
    a.updated_at = NOW()
WHERE a.location IS NULL;
