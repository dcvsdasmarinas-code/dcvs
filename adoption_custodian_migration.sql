-- Record the resident who adopted each rescued pet listing.
ALTER TABLE pet_listings
    ADD COLUMN adopted_by INT(11) NULL AFTER adopted_at,
    ADD KEY idx_pet_listings_adopted_by (adopted_by),
    ADD CONSTRAINT fk_pet_listings_adopted_by
        FOREIGN KEY (adopted_by) REFERENCES users(user_id) ON DELETE SET NULL;

-- Backfill adoptions that were registered as resident-owned pets before adopted_by was added.
UPDATE pet_listings pl
SET pl.adopted_by = (
    SELECT adopted_pet.owner_id
    FROM pets adopted_pet
    JOIN adoption_listings adopted_listing ON adopted_listing.pet_id = adopted_pet.pet_id
    WHERE LOWER(TRIM(adopted_pet.pet_name)) = LOWER(TRIM(pl.name))
      AND LOWER(TRIM(adopted_pet.pet_type)) = LOWER(TRIM(pl.species))
    ORDER BY adopted_listing.created_at DESC, adopted_listing.listing_id DESC
    LIMIT 1
)
WHERE pl.status = 'adopted'
  AND pl.adopted_by IS NULL
  AND EXISTS (
      SELECT 1
      FROM pets adopted_pet
      JOIN adoption_listings adopted_listing ON adopted_listing.pet_id = adopted_pet.pet_id
      WHERE LOWER(TRIM(adopted_pet.pet_name)) = LOWER(TRIM(pl.name))
        AND LOWER(TRIM(adopted_pet.pet_type)) = LOWER(TRIM(pl.species))
  );
