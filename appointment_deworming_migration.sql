ALTER TABLE appointments
    MODIFY appointment_type ENUM('adoption', 'kapon', 'vaccination', 'checkup', 'deworming') NOT NULL;