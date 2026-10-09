-- Store the date an animal is marked as adopted separately from general listing updates.
ALTER TABLE pet_listings
    ADD COLUMN adopted_at DATETIME NULL AFTER status;

UPDATE pet_listings
SET adopted_at = updated_at
WHERE status = 'adopted'
  AND adopted_at IS NULL
  AND updated_at IS NOT NULL;
