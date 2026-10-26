ALTER TABLE `pmr_records`
    ADD COLUMN `rte` TINYINT(1) DEFAULT NULL AFTER `pmr_status`;
