-- Store government ID uploads directly with the adoption application.

ALTER TABLE `adoption_applications`
  ADD COLUMN `government_id_file` varchar(100) DEFAULT NULL AFTER `date_of_birth`;
