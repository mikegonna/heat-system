ALTER TABLE `pmr_records`
    ADD COLUMN `fu_date` DATETIME DEFAULT NULL AFTER `record_datetime`;

UPDATE `pmr_records`
SET `fu_date` = `record_datetime`
WHERE `fu_date` IS NULL;
