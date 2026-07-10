-- crear_tablas.sql
-- Estructura completa para el formulario reducido de diagnóstico comercial de Pixels Studio.
-- Ejecutar desde phpMyAdmin o mediante instalar_base_datos.php.

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(60) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(30) NOT NULL DEFAULT 'super_admin',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `leads` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `fullname` VARCHAR(120) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `brand_instagram` VARCHAR(120) NOT NULL,
  `business_type` VARCHAR(80) NOT NULL,
  `business_type_other` VARCHAR(120) NULL,
  `services_needed` TEXT NOT NULL,
  `main_objective` VARCHAR(120) NOT NULL,
  `message` TEXT NULL,
  `source_platform` VARCHAR(80) NULL,
  `utm_source` VARCHAR(80) NULL,
  `utm_medium` VARCHAR(80) NULL,
  `utm_campaign` VARCHAR(120) NULL,
  `utm_content` VARCHAR(160) NULL,
  `utm_term` VARCHAR(160) NULL,
  `ad_name` VARCHAR(180) NULL,
  `ad_id` VARCHAR(120) NULL,
  `gclid` VARCHAR(180) NULL,
  `fbclid` VARCHAR(180) NULL,
  `landing_url` TEXT NULL,
  `referrer` TEXT NULL,
  `sales_status` VARCHAR(50) NOT NULL DEFAULT 'nuevo_lead',
  `notes` TEXT NULL,
  `reminder_at` DATETIME NULL,
  `reminder_note` VARCHAR(255) NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `ip` VARCHAR(64) NULL,
  `user_agent` VARCHAR(255) NULL,
  `whatsapp_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `whatsapp_status` VARCHAR(32) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_phone` (`phone`),
  KEY `idx_brand_instagram` (`brand_instagram`),
  KEY `idx_business_type` (`business_type`),
  KEY `idx_main_objective` (`main_objective`),
  KEY `idx_source_platform` (`source_platform`),
  KEY `idx_utm_campaign` (`utm_campaign`),
  KEY `idx_utm_content` (`utm_content`),
  KEY `idx_ad_name` (`ad_name`),
  KEY `idx_sales_status` (`sales_status`),
  KEY `idx_reminder_at` (`reminder_at`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
