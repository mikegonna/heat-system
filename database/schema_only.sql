
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `cases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hn` varchar(50) NOT NULL,
  `patient_id_card` varchar(13) NOT NULL,
  `patient_name` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `age` int(3) DEFAULT NULL,
  `weight` varchar(255) DEFAULT NULL,
  `height` varchar(255) DEFAULT NULL,
  `bmi` varchar(255) DEFAULT NULL,
  `whoname` varchar(255) DEFAULT NULL COMMENT 'ผู้นำส่ง',
  `whotel` varchar(100) DEFAULT NULL COMMENT 'เบอร์ผู้นำส่ง',
  `person_type` varchar(100) DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `congenital_disease` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `status_case` varchar(50) DEFAULT 'Open',
  `comment` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hn` (`hn`)
) ENGINE=InnoDB AUTO_INCREMENT=136 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_import`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `data_import` (
  `row_ID` int(11) NOT NULL AUTO_INCREMENT,
  `hn` varchar(100) NOT NULL,
  `person_ID` varchar(13) NOT NULL COMMENT 'เลขบัตรประชาชน',
  `Fname` varchar(255) NOT NULL,
  `work_located` varchar(255) NOT NULL COMMENT 'หน่วยที่ฝึก',
  `data_date` datetime NOT NULL DEFAULT current_timestamp(),
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0. ยังไม่รับเรื่อง 1.รับเรื่องแล้ว 2.ไม่ใช่ Heat',
  `status_detail` text NOT NULL COMMENT 'เหตุผลว่าไม่ใช่ Heat เพราะอะไร',
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`row_ID`)
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `er_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `er_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `case_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `cause_factor` varchar(255) DEFAULT NULL,
  `first_aid` varchar(10) DEFAULT NULL,
  `arrival_time` varchar(20) DEFAULT NULL,
  `hospital_arrival_datetime` datetime DEFAULT NULL,
  `ct_initial` decimal(4,1) DEFAULT NULL,
  `ct_30min` decimal(4,1) DEFAULT NULL,
  `ct_control` varchar(50) DEFAULT NULL,
  `FstDx` text DEFAULT NULL,
  `disposition` varchar(50) DEFAULT NULL,
  `death_cause` text DEFAULT NULL,
  `admit_type` varchar(20) DEFAULT NULL,
  `refer_hospital` varchar(100) DEFAULT NULL,
  `treatment_result` text DEFAULT NULL,
  `risk_factors` text DEFAULT NULL,
  `reporter_name` varchar(255) DEFAULT NULL,
  `visit_unit` varchar(100) NOT NULL DEFAULT '''''' COMMENT 'หน่วยงานที่ให้เยี่ยม เช่น CQI,PCU,COC',
  `er_discharge_date` datetime DEFAULT NULL,
  `recorded_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `case_id` (`case_id`),
  CONSTRAINT `er_details_ibfk_1` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=99 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gp_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gp_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `case_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `cause_factor` varchar(255) DEFAULT NULL,
  `first_aid` varchar(10) DEFAULT NULL,
  `arrival_time` varchar(20) DEFAULT NULL,
  `hospital_arrival_datetime` datetime DEFAULT NULL,
  `ct_initial` decimal(4,1) DEFAULT NULL,
  `ct_30min` decimal(4,1) DEFAULT NULL,
  `ct_control` varchar(50) DEFAULT NULL,
  `FstDx` text DEFAULT NULL,
  `disposition` varchar(50) DEFAULT NULL,
  `death_cause` text DEFAULT NULL,
  `admit_type` varchar(20) DEFAULT NULL,
  `refer_hospital` varchar(100) DEFAULT NULL,
  `treatment_result` text DEFAULT NULL,
  `risk_factors` text DEFAULT NULL,
  `reporter_name` varchar(255) DEFAULT NULL,
  `er_discharge_date` datetime NOT NULL,
  `recorded_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `case_id` (`case_id`),
  CONSTRAINT `fk_gp_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `home_visits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `home_visits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hn` varchar(50) NOT NULL,
  `an` varchar(50) NOT NULL,
  `checklist_obs` text DEFAULT NULL COMMENT 'เก็บค่า มีไข้, ทำกิจกรรม',
  `urine_color` varchar(100) DEFAULT NULL,
  `body_temperature` decimal(4,1) DEFAULT NULL COMMENT 'อุณหภูมิร่างกาย',
  `activity_details` varchar(255) DEFAULT NULL COMMENT 'รายละเอียดกิจกรรม',
  `symptoms` text DEFAULT NULL COMMENT 'เก็บอาการผิดปกติที่ติ๊กเลือก',
  `symptoms_other` text DEFAULT NULL COMMENT 'รายละเอียดอาการอื่นๆ',
  `recorder_name` varchar(255) NOT NULL,
  `record_datetime` datetime NOT NULL,
  `next_visit_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `hn` (`hn`),
  KEY `an` (`an`)
) ENGINE=InnoDB AUTO_INCREMENT=105 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `med_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `med_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hn` varchar(50) NOT NULL,
  `an` varchar(50) NOT NULL,
  `fu_date` datetime DEFAULT NULL,
  `med_status` varchar(255) DEFAULT NULL COMMENT 'ผลการประเมิน MED',
  `occupational` varchar(100) NOT NULL,
  `pmr` varchar(100) NOT NULL,
  `next_med_date` date DEFAULT NULL COMMENT 'วันนัด (ถ้ามี)',
  `med_note` text DEFAULT NULL COMMENT 'รายละเอียดเพิ่มเติม/Plan ส่งต่อ',
  `cpk` varchar(100) DEFAULT NULL,
  `bun` varchar(100) DEFAULT NULL,
  `cr` varchar(100) DEFAULT NULL,
  `eletroride` varchar(100) DEFAULT NULL,
  `recorder_name` varchar(255) NOT NULL,
  `record_datetime` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `hn` (`hn`),
  KEY `an` (`an`)
) ENGINE=InnoDB AUTO_INCREMENT=45 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pmr_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pmr_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hn` varchar(50) NOT NULL,
  `an` varchar(50) NOT NULL,
  `pmr_status` varchar(255) DEFAULT NULL,
  `next_pmr_date` date DEFAULT NULL,
  `pmr_note` text DEFAULT NULL,
  `recorder_name` varchar(255) NOT NULL,
  `record_datetime` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `hn` (`hn`),
  KEY `an` (`an`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user','viewer') DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ward_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ward_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `case_id` int(11) NOT NULL,
  `an` varchar(50) DEFAULT NULL,
  `admit_type` varchar(50) DEFAULT NULL,
  `admit_date` datetime DEFAULT current_timestamp(),
  `initial_diagnosis` text DEFAULT NULL COMMENT 'วินิจฉัยแรกรับ',
  `final_diagnosis` text DEFAULT NULL COMMENT 'วินิจฉัยสุดท้าย',
  `death_cause` text DEFAULT NULL COMMENT 'สาเหตุการตาย',
  `treatment_result` varchar(50) DEFAULT NULL COMMENT 'ผลการรักษา: Admit, Discharge, Refer, Dead',
  `status` varchar(50) DEFAULT 'Active',
  `discharge_date` datetime DEFAULT NULL,
  `discharge_status` varchar(50) DEFAULT NULL,
  `discharge_plan` varchar(50) DEFAULT NULL,
  `first_visit_date` date DEFAULT NULL,
  `discharge_detail` varchar(255) DEFAULT NULL,
  `doctor_name` varchar(255) DEFAULT NULL,
  `icu_core_temp_first` decimal(4,1) DEFAULT NULL COMMENT 'Core Temp แรกรับ',
  `icu_core_temp_30min` decimal(4,1) DEFAULT NULL COMMENT 'Core Temp นาทีที่ 30',
  `icu_control_temp` enum('Yes','No') DEFAULT NULL COMMENT 'Control Temp (Yes/No)',
  `icu_diagnosis` text DEFAULT NULL COMMENT 'วินิจฉัยแรกรับ ICU',
  `icu_time_symptoms` datetime DEFAULT NULL COMMENT 'เวลาเริ่มมีอาการ',
  `icu_time_hospital` datetime DEFAULT NULL COMMENT 'เวลาถึง รพ.',
  `icu_time_er_to_icu` varchar(100) DEFAULT NULL COMMENT 'ระยะเวลาจาก ER ถึง ICU',
  `visit_unit` varchar(100) DEFAULT '' COMMENT 'หน่วยงานที่ให้เยี่ยม เช่น CQI,PCU,COC',
  PRIMARY KEY (`id`),
  KEY `case_id` (`case_id`),
  CONSTRAINT `ward_details_ibfk_1` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=121 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ward_progress_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ward_progress_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `case_id` int(11) NOT NULL,
  `ward_detail_id` int(11) NOT NULL,
  `admit_type` varchar(20) DEFAULT NULL COMMENT 'Ward ICU, Ward RCU, Ward A4, Ward B3',
  `heat_status` varchar(50) DEFAULT NULL,
  `note_datetime` datetime DEFAULT current_timestamp(),
  `details` text DEFAULT NULL,
  `treatment_result` varchar(50) DEFAULT NULL,
  `doctor_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `case_id` (`case_id`),
  KEY `ward_detail_id` (`ward_detail_id`),
  CONSTRAINT `ward_progress_notes_ibfk_1` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ward_progress_notes_ibfk_2` FOREIGN KEY (`ward_detail_id`) REFERENCES `ward_details` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=225 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
