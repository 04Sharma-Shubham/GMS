-- GMS Gym Management System
-- MySQL 8+ / MariaDB 10.4+ import for phpMyAdmin.
-- Select your database first, then import this file.
-- Demo date anchor: 11 November 2026 (Asia/Kolkata).
-- Demo password for every sample login: Demo@12345
--   owner@pulseforge.demo       Gym Owner
--   manager@pulseforge.demo     Manager
--   desk@pulseforge.demo        Receptionist
--   trainer@pulseforge.demo     Trainer

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS cron_runs;
DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS reminder_logs;
DROP TABLE IF EXISTS whatsapp_message_logs;
DROP TABLE IF EXISTS whatsapp_templates;
DROP TABLE IF EXISTS whatsapp_settings;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS workout_assignments;
DROP TABLE IF EXISTS workout_plans;
DROP TABLE IF EXISTS attendance;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS invoice_items;
DROP TABLE IF EXISTS invoices;
DROP TABLE IF EXISTS member_subscriptions;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS expense_categories;
DROP TABLE IF EXISTS trainer_schedules;
DROP TABLE IF EXISTS staff_profiles;
DROP TABLE IF EXISTS members;
DROP TABLE IF EXISTS membership_plans;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS gym_settings;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS gyms;

CREATE TABLE gyms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(160) NOT NULL,
  logo_path VARCHAR(255) DEFAULT NULL,
  email VARCHAR(190) DEFAULT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  address_line VARCHAR(255) DEFAULT NULL,
  city VARCHAR(100) DEFAULT NULL,
  state VARCHAR(100) DEFAULT NULL,
  postal_code VARCHAR(20) DEFAULT NULL,
  country VARCHAR(80) NOT NULL DEFAULT 'India',
  gstin VARCHAR(20) DEFAULT NULL,
  tax_enabled TINYINT(1) NOT NULL DEFAULT 0,
  tax_label VARCHAR(40) NOT NULL DEFAULT 'GST',
  tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  invoice_prefix VARCHAR(20) NOT NULL DEFAULT 'INV',
  invoice_next_number INT UNSIGNED NOT NULL DEFAULT 1,
  invoice_footer TEXT NULL,
  invoice_terms TEXT NULL,
  currency_code CHAR(3) NOT NULL DEFAULT 'INR',
  currency_symbol VARCHAR(8) NOT NULL DEFAULT '₹',
  timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Kolkata',
  member_prefix VARCHAR(12) NOT NULL DEFAULT 'MBR',
  member_next_number INT UNSIGNED NOT NULL DEFAULT 1001,
  onboarding_step TINYINT UNSIGNED NOT NULL DEFAULT 1,
  onboarding_completed TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gyms_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','manager','receptionist','trainer') NOT NULL,
  avatar_path VARCHAR(255) DEFAULT NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_login_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_gym (gym_id),
  CONSTRAINT fk_users_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reset_token (token_hash),
  KEY idx_reset_user (user_id),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gym_settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  setting_key VARCHAR(80) NOT NULL,
  setting_value TEXT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gym_setting (gym_id, setting_key),
  CONSTRAINT fk_setting_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_profiles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  designation VARCHAR(120) DEFAULT NULL,
  salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  joining_date DATE DEFAULT NULL,
  bio TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_staff_user (user_id),
  KEY idx_staff_gym (gym_id),
  CONSTRAINT fk_staff_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE,
  CONSTRAINT fk_staff_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trainer_schedules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_schedule_user (gym_id, user_id),
  CONSTRAINT fk_schedule_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE,
  CONSTRAINT fk_schedule_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE membership_plans (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  duration_days INT UNSIGNED NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  description TEXT NULL,
  benefits TEXT NULL,
  is_personal_training TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_plans_gym (gym_id, status),
  CONSTRAINT fk_plans_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE,
  CONSTRAINT chk_plan_price CHECK (price >= 0 AND duration_days > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE members (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  member_code VARCHAR(32) NOT NULL,
  full_name VARCHAR(160) NOT NULL,
  photo_path VARCHAR(255) DEFAULT NULL,
  phone VARCHAR(30) NOT NULL,
  email VARCHAR(190) DEFAULT NULL,
  gender ENUM('female','male','other','undisclosed') NOT NULL DEFAULT 'undisclosed',
  date_of_birth DATE DEFAULT NULL,
  address VARCHAR(255) DEFAULT NULL,
  emergency_name VARCHAR(160) DEFAULT NULL,
  emergency_phone VARCHAR(30) DEFAULT NULL,
  registration_date DATE NOT NULL,
  trainer_id INT UNSIGNED DEFAULT NULL,
  notes TEXT NULL,
  status ENUM('active','expired','suspended','archived') NOT NULL DEFAULT 'active',
  whatsapp_opt_in TINYINT(1) NOT NULL DEFAULT 0,
  qr_token CHAR(48) NOT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_member_code (gym_id, member_code),
  UNIQUE KEY uq_member_qr (qr_token),
  KEY idx_members_status (gym_id, status),
  KEY idx_members_phone (gym_id, phone),
  KEY idx_members_trainer (trainer_id),
  KEY idx_members_joined (gym_id, registration_date),
  CONSTRAINT fk_members_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE,
  CONSTRAINT fk_members_trainer FOREIGN KEY (trainer_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_members_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE member_subscriptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  status ENUM('active','expired','frozen','cancelled','upgraded','downgraded') NOT NULL DEFAULT 'active',
  price DECIMAL(12,2) NOT NULL,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(12,2) NOT NULL,
  freeze_start DATE DEFAULT NULL,
  frozen_days INT UNSIGNED NOT NULL DEFAULT 0,
  change_type ENUM('new','renewal','upgrade','downgrade') NOT NULL DEFAULT 'new',
  previous_subscription_id INT UNSIGNED DEFAULT NULL,
  notes VARCHAR(500) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sub_member (gym_id, member_id, status),
  KEY idx_sub_end (gym_id, status, end_date),
  CONSTRAINT fk_sub_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE RESTRICT,
  CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES membership_plans (id) ON DELETE RESTRICT,
  CONSTRAINT fk_sub_prev FOREIGN KEY (previous_subscription_id) REFERENCES member_subscriptions (id) ON DELETE SET NULL,
  CONSTRAINT fk_sub_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  invoice_number VARCHAR(40) NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  subscription_id INT UNSIGNED NOT NULL,
  invoice_date DATE NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(12,2) NOT NULL,
  amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  balance_due DECIMAL(12,2) NOT NULL,
  status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  notes VARCHAR(500) DEFAULT NULL,
  pdf_path VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invoice_number (gym_id, invoice_number),
  KEY idx_invoice_member (gym_id, member_id),
  KEY idx_invoice_status (gym_id, status),
  KEY idx_invoice_date (gym_id, invoice_date),
  CONSTRAINT fk_invoice_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE,
  CONSTRAINT fk_invoice_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE RESTRICT,
  CONSTRAINT fk_invoice_sub FOREIGN KEY (subscription_id) REFERENCES member_subscriptions (id) ON DELETE RESTRICT,
  CONSTRAINT fk_invoice_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_invoice_balance CHECK (balance_due = total_amount - amount_paid),
  CONSTRAINT chk_invoice_nonneg CHECK (total_amount >= 0 AND amount_paid >= 0 AND balance_due >= 0 AND discount >= 0 AND tax_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  invoice_id INT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  unit_price DECIMAL(12,2) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_item_invoice (invoice_id),
  CONSTRAINT fk_item_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE,
  CONSTRAINT fk_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  invoice_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  subscription_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_date DATE NOT NULL,
  method ENUM('cash','upi','bank_transfer','card','other') NOT NULL,
  reference_no VARCHAR(120) DEFAULT NULL,
  notes VARCHAR(500) DEFAULT NULL,
  recorded_by INT UNSIGNED DEFAULT NULL,
  status ENUM('recorded','voided') NOT NULL DEFAULT 'recorded',
  void_reason VARCHAR(255) DEFAULT NULL,
  voided_by INT UNSIGNED DEFAULT NULL,
  voided_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pay_invoice (invoice_id),
  KEY idx_pay_date (gym_id, payment_date),
  KEY idx_pay_member (gym_id, member_id),
  CONSTRAINT fk_pay_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pay_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pay_sub FOREIGN KEY (subscription_id) REFERENCES member_subscriptions (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pay_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_pay_void FOREIGN KEY (voided_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_pay_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  check_in_at DATETIME NOT NULL,
  check_out_at DATETIME DEFAULT NULL,
  method ENUM('manual','qr') NOT NULL DEFAULT 'manual',
  recorded_by INT UNSIGNED DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  override_expired TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_att_day (gym_id, check_in_at),
  KEY idx_att_member (gym_id, member_id, check_in_at),
  CONSTRAINT fk_att_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE,
  CONSTRAINT fk_att_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE,
  CONSTRAINT fk_att_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expense_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_category (gym_id, name),
  CONSTRAINT fk_cat_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expenses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  expense_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  description VARCHAR(255) NOT NULL,
  receipt_path VARCHAR(255) DEFAULT NULL,
  payment_method ENUM('cash','upi','bank_transfer','card','other') NOT NULL DEFAULT 'cash',
  recorded_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_exp_date (gym_id, expense_date),
  CONSTRAINT fk_exp_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE,
  CONSTRAINT fk_exp_cat FOREIGN KEY (category_id) REFERENCES expense_categories (id) ON DELETE RESTRICT,
  CONSTRAINT fk_exp_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_exp_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED DEFAULT NULL,
  type VARCHAR(60) NOT NULL,
  title VARCHAR(180) NOT NULL,
  body VARCHAR(500) NOT NULL,
  link VARCHAR(255) DEFAULT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_notes (gym_id, user_id, is_read, created_at),
  CONSTRAINT fk_note_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE,
  CONSTRAINT fk_note_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  phone_number_id VARCHAR(40) DEFAULT NULL,
  business_account_id VARCHAR(40) DEFAULT NULL,
  access_token_encrypted TEXT NULL,
  app_secret_encrypted TEXT NULL,
  api_version VARCHAR(10) NOT NULL DEFAULT 'v21.0',
  demo_mode TINYINT(1) NOT NULL DEFAULT 1,
  webhook_verify_token VARCHAR(80) DEFAULT NULL,
  default_country_code VARCHAR(6) NOT NULL DEFAULT '91',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_gym (gym_id),
  CONSTRAINT fk_wa_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_templates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  template_key VARCHAR(40) NOT NULL,
  template_name VARCHAR(120) DEFAULT NULL,
  language_code VARCHAR(10) NOT NULL DEFAULT 'en',
  body_preview VARCHAR(500) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_template (gym_id, template_key),
  CONSTRAINT fk_tpl_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_message_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED DEFAULT NULL,
  template_key VARCHAR(40) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  payload_json JSON NULL,
  response_json JSON NULL,
  status ENUM('queued','sent','failed','demo') NOT NULL DEFAULT 'queued',
  wa_message_id VARCHAR(128) DEFAULT NULL,
  delivery_status VARCHAR(32) DEFAULT NULL,
  error_message VARCHAR(500) DEFAULT NULL,
  http_status SMALLINT DEFAULT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_retry_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_wa_status (gym_id, status, next_retry_at),
  KEY idx_wa_message (wa_message_id),
  CONSTRAINT fk_wal_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE,
  CONSTRAINT fk_wal_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reminder_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  reminder_type VARCHAR(40) NOT NULL,
  reference_key VARCHAR(64) NOT NULL,
  remind_on DATE NOT NULL,
  status ENUM('queued','sent','failed','skipped','demo') NOT NULL,
  message_log_id INT UNSIGNED DEFAULT NULL,
  detail VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reminder (gym_id, member_id, reminder_type, reference_key, remind_on),
  CONSTRAINT fk_rem_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE,
  CONSTRAINT fk_rem_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE,
  CONSTRAINT fk_rem_msg FOREIGN KEY (message_log_id) REFERENCES whatsapp_message_logs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED DEFAULT NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(60) NOT NULL,
  entity_id INT UNSIGNED DEFAULT NULL,
  old_values JSON NULL,
  new_values JSON NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_gym (gym_id, created_at),
  KEY idx_audit_entity (gym_id, entity_type, entity_id),
  CONSTRAINT fk_audit_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  action_key VARCHAR(190) NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rate (action_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE workout_plans (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  trainer_id INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_workout_gym (gym_id),
  CONSTRAINT fk_workout_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE,
  CONSTRAINT fk_workout_trainer FOREIGN KEY (trainer_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE workout_assignments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gym_id INT UNSIGNED NOT NULL,
  workout_plan_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  assigned_by INT UNSIGNED DEFAULT NULL,
  start_date DATE NOT NULL,
  notes VARCHAR(500) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_assign_member (gym_id, member_id),
  CONSTRAINT fk_assign_gym FOREIGN KEY (gym_id) REFERENCES gyms (id) ON DELETE CASCADE,
  CONSTRAINT fk_assign_plan FOREIGN KEY (workout_plan_id) REFERENCES workout_plans (id) ON DELETE CASCADE,
  CONSTRAINT fk_assign_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE,
  CONSTRAINT fk_assign_user FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cron_runs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_name VARCHAR(60) NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME DEFAULT NULL,
  status ENUM('running','success','failed') NOT NULL,
  message VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_cron_job (job_name, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Sample gym. Safe to delete by removing the PulseForge rows after you create your own gym.
INSERT INTO gyms (id, name, slug, email, phone, address_line, city, state, postal_code, country, gstin, tax_enabled, tax_label, tax_percent, invoice_prefix, invoice_next_number, invoice_footer, invoice_terms, currency_code, currency_symbol, timezone, member_prefix, member_next_number, onboarding_step, onboarding_completed, status, created_at, updated_at)
VALUES (1, 'PulseForge Athletics', 'pulseforge-demo', 'hello@pulseforge.demo', '02248902200', '18 Linking Road, Bandra West', 'Mumbai', 'Maharashtra', '400050', 'India', '27AAPFU1234R1Z5', 1, 'GST', 18.00, 'INV', 14, 'Thank you for training with PulseForge.', 'Payments are collected manually at the gym. This invoice is not an online payment receipt.', 'INR', '₹', 'Asia/Kolkata', 'PF', 1013, 6, 1, 'active', '2026-06-01 04:30:00', '2026-11-11 04:30:00');

INSERT INTO users (id, gym_id, name, email, phone, password_hash, role, status, created_at, updated_at) VALUES
(1, 1, 'Aanya Kapoor', 'owner@pulseforge.demo', '9800000001', '$2y$10$wl0KCyn6OTeIuG2Zn7va.ew1nduaUJYELX5efCJYfp8UcqtS1LCFK', 'owner', 'active', '2026-06-01 04:30:00', '2026-06-01 04:30:00'),
(2, 1, 'Rohit Deshmukh', 'manager@pulseforge.demo', '9800000002', '$2y$10$wl0KCyn6OTeIuG2Zn7va.ew1nduaUJYELX5efCJYfp8UcqtS1LCFK', 'manager', 'active', '2026-06-02 04:30:00', '2026-06-02 04:30:00'),
(3, 1, 'Pooja Nanda', 'desk@pulseforge.demo', '9800000003', '$2y$10$wl0KCyn6OTeIuG2Zn7va.ew1nduaUJYELX5efCJYfp8UcqtS1LCFK', 'receptionist', 'active', '2026-06-02 04:30:00', '2026-06-02 04:30:00'),
(4, 1, 'Rehan Seth', 'trainer@pulseforge.demo', '9800000004', '$2y$10$wl0KCyn6OTeIuG2Zn7va.ew1nduaUJYELX5efCJYfp8UcqtS1LCFK', 'trainer', 'active', '2026-06-03 04:30:00', '2026-06-03 04:30:00');

INSERT INTO staff_profiles (gym_id, user_id, designation, salary, commission_percent, joining_date, bio, created_at, updated_at) VALUES
(1, 1, 'Founder', 0.00, 0.00, '2026-06-01', 'Owns the floor and the books.', '2026-06-01 04:30:00', '2026-06-01 04:30:00'),
(1, 2, 'General Manager', 35000.00, 0.00, '2026-06-02', 'Runs daily operations.', '2026-06-02 04:30:00', '2026-06-02 04:30:00'),
(1, 3, 'Front Desk', 18000.00, 0.00, '2026-06-02', 'Check-in and payments.', '2026-06-02 04:30:00', '2026-06-02 04:30:00'),
(1, 4, 'Head Coach', 25000.00, 10.00, '2026-06-03', 'Strength and personal training.', '2026-06-03 04:30:00', '2026-06-03 04:30:00');

INSERT INTO trainer_schedules (gym_id, user_id, day_of_week, start_time, end_time, notes, created_at, updated_at) VALUES
(1, 4, 1, '06:00:00', '11:00:00', 'Morning floor', '2026-06-03 04:30:00', '2026-06-03 04:30:00'),
(1, 4, 1, '17:00:00', '21:00:00', 'Evening PT', '2026-06-03 04:30:00', '2026-06-03 04:30:00'),
(1, 4, 2, '06:00:00', '11:00:00', NULL, '2026-06-03 04:30:00', '2026-06-03 04:30:00'),
(1, 4, 3, '06:00:00', '11:00:00', NULL, '2026-06-03 04:30:00', '2026-06-03 04:30:00'),
(1, 4, 4, '06:00:00', '11:00:00', NULL, '2026-06-03 04:30:00', '2026-06-03 04:30:00'),
(1, 4, 5, '06:00:00', '11:00:00', NULL, '2026-06-03 04:30:00', '2026-06-03 04:30:00'),
(1, 4, 6, '07:00:00', '12:00:00', 'Saturday', '2026-06-03 04:30:00', '2026-06-03 04:30:00');

INSERT INTO membership_plans (id, gym_id, name, duration_days, price, description, benefits, is_personal_training, status, created_at, updated_at) VALUES
(1, 1, 'Monthly Plan', 30, 1500.00, 'Floor access for 30 days.', 'Gym floor, locker, one guest pass', 0, 'active', '2026-06-01 05:00:00', '2026-06-01 05:00:00'),
(2, 1, 'Quarterly Plan', 90, 4000.00, 'Three months of training.', 'Monthly benefits plus two PT sessions', 0, 'active', '2026-06-01 05:00:00', '2026-06-01 05:00:00'),
(3, 1, 'Half-Yearly Plan', 180, 7200.00, 'Six months of training.', 'Quarterly benefits plus a nutrition consult', 0, 'active', '2026-06-01 05:00:00', '2026-06-01 05:00:00'),
(4, 1, 'Annual Plan', 365, 12000.00, 'Best value for the year.', 'Freeze up to 30 days and six PT sessions', 0, 'active', '2026-06-01 05:00:00', '2026-06-01 05:00:00'),
(5, 1, 'Personal Training', 30, 8000.00, 'Twelve coached sessions.', 'Custom workout with the assigned trainer', 1, 'active', '2026-06-01 05:00:00', '2026-06-01 05:00:00');

INSERT INTO members (id, gym_id, member_code, full_name, phone, email, gender, date_of_birth, address, emergency_name, emergency_phone, registration_date, trainer_id, notes, status, whatsapp_opt_in, qr_token, created_by, created_at, updated_at) VALUES
(1, 1, 'PF-1001', 'Aarav Shah', '9800001001', 'aarav@example.com', 'male', '1994-03-12', 'Pali Hill, Mumbai', 'Nina Shah', '9800002001', '2026-01-15', 4, 'Prefers morning strength.', 'active', 1, '4f8c2a91b7e64d0c8a3f15e2d9b0476ac1e83f52a6d04b97', 1, '2026-01-15 03:30:00', '2026-01-15 03:30:00'),
(2, 1, 'PF-1002', 'Meera Iyer', '9800001002', 'meera@example.com', 'female', '1998-07-02', 'Khar West, Mumbai', 'Lakshmi Iyer', '9800002002', '2026-09-20', 4, 'Renewed in October.', 'active', 1, '19ab33d0e4c27f58a1b6904d3e7c12f8aa4509b6d1e23847', 3, '2026-09-20 03:30:00', '2026-10-20 03:30:00'),
(3, 1, 'PF-1003', 'Rohan Gupta', '9800001003', 'rohan@example.com', 'male', '1992-11-21', 'Andheri West, Mumbai', 'Sonia Gupta', '9800002003', '2026-09-01', NULL, 'Paying the quarterly plan in parts.', 'active', 1, '88c10fe4a2b5967d03e1c4f8b9a27654d0c13e9f5a7b2846', 1, '2026-09-01 03:30:00', '2026-09-01 03:30:00'),
(4, 1, 'PF-1004', 'Simran Kaur', '9800001004', 'simran@example.com', 'female', '2000-01-09', 'Juhu, Mumbai', 'Harpreet Kaur', '9800002004', '2026-10-15', 4, 'Expires in two days from the demo date.', 'active', 1, '02d6a8f1c3b9475e90a1d4c7e6b2834f15a09c8d7e2643b1', 3, '2026-10-15 03:30:00', '2026-10-15 03:30:00'),
(5, 1, 'PF-1005', 'Kabir Khan', '9800001005', 'kabir@example.com', 'male', '1988-05-18', 'Dadar, Mumbai', 'Imran Khan', '9800002005', '2026-08-01', NULL, 'Membership ended with an unpaid invoice.', 'expired', 1, 'e7b14290c6a5d8f3e1b0472a9c6d5f384e1720ab9d4c6e85', 1, '2026-08-01 03:30:00', '2026-10-30 03:30:00'),
(6, 1, 'PF-1006', 'Ananya Rao', '9800001006', 'ananya@example.com', 'female', '1996-12-03', 'Worli, Mumbai', 'Ravi Rao', '9800002006', '2026-09-01', 4, 'Membership is frozen.', 'active', 1, '5ac9e0b7d2f14863a0c5e9b3d7f62418c9a0e5b2d6f73841', 1, '2026-09-01 03:30:00', '2026-11-01 03:30:00'),
(7, 1, 'PF-1007', 'Vikram Patel', '9800001007', 'vikram@example.com', 'male', '1991-02-28', 'Bandra East, Mumbai', 'Neel Patel', '9800002007', '2026-11-02', 4, 'Joined this month.', 'active', 1, 'b3d70a1e9c5f2846a8e1b0d4c7f39265e0a1d8b4c6f57392', 3, '2026-11-02 03:30:00', '2026-11-02 03:30:00'),
(8, 1, 'PF-1008', 'Neha Joshi', '9800001008', 'neha@example.com', 'female', '1995-09-14', 'Santacruz, Mumbai', 'Asha Joshi', '9800002008', '2026-11-01', 4, 'Personal training, partially paid.', 'active', 1, '6e1f9a3c0b8d4527f4a2e6c1d9b07385a4e2f1c0b7d59638', 1, '2026-11-01 03:30:00', '2026-11-01 03:30:00'),
(9, 1, 'PF-1009', 'Arjun Nair', '9800001009', 'arjun@example.com', 'male', '1987-04-07', 'Matunga, Mumbai', 'Dev Nair', '9800002009', '2026-04-01', NULL, 'Suspended by the desk.', 'suspended', 0, '90c4e7a1b2d6f58304a9c1e7b5d2836f1a4e0c9b7d2f6583', 2, '2026-04-01 03:30:00', '2026-07-01 03:30:00'),
(10, 1, 'PF-1010', 'Diya Menon', '9800001010', 'diya@example.com', 'female', '1999-06-25', 'Colaba, Mumbai', 'Anita Menon', '9800002010', '2025-12-01', NULL, 'Archived sample profile.', 'archived', 0, 'd1a6f3c8e0b49275a3c1d9e6f4b0827a5e1c0d9b6f384275', 1, '2025-12-01 03:30:00', '2026-01-15 03:30:00'),
(11, 1, 'PF-1011', 'Sara Ali', '9800001011', 'sara@example.com', 'female', '1993-08-30', 'Carter Road, Mumbai', 'Yusuf Ali', '9800002011', '2026-08-11', 4, 'Half-year member.', 'active', 1, '37b8e1a0c4d6f592b1a7e3c9d0f68425a8c1b3e7d5f09246', 1, '2026-08-11 03:30:00', '2026-08-11 03:30:00'),
(12, 1, 'PF-1012', 'Ishaan Verma', '9800001012', 'ishaan@example.com', 'male', '2001-10-11', 'Versova, Mumbai', 'Pooja Verma', '9800002012', '2026-11-10', NULL, 'New this month and unpaid.', 'active', 1, 'fa12c0d9e6b38475a1f0c2d8e5b79634c0a1d7e9b5f28364', 3, '2026-11-10 03:30:00', '2026-11-10 03:30:00');

INSERT INTO member_subscriptions (id, gym_id, member_id, plan_id, start_date, end_date, status, price, discount, tax_percent, tax_amount, total_amount, freeze_start, frozen_days, change_type, previous_subscription_id, created_by, created_at, updated_at) VALUES
(1, 1, 1, 4, '2026-01-15', '2027-01-14', 'active', 12000.00, 0.00, 18.00, 2160.00, 14160.00, NULL, 0, 'new', NULL, 1, '2026-01-15 03:30:00', '2026-01-15 03:30:00'),
(2, 1, 2, 1, '2026-09-20', '2026-10-19', 'expired', 1500.00, 0.00, 18.00, 270.00, 1770.00, NULL, 0, 'new', NULL, 3, '2026-09-20 03:30:00', '2026-10-20 03:30:00'),
(3, 1, 2, 1, '2026-10-20', '2026-11-18', 'active', 1500.00, 0.00, 18.00, 270.00, 1770.00, NULL, 0, 'renewal', 2, 3, '2026-10-20 03:30:00', '2026-10-20 03:30:00'),
(4, 1, 3, 2, '2026-09-01', '2026-11-29', 'active', 4000.00, 0.00, 18.00, 720.00, 4720.00, NULL, 0, 'new', NULL, 1, '2026-09-01 03:30:00', '2026-09-01 03:30:00'),
(5, 1, 4, 1, '2026-10-15', '2026-11-13', 'active', 1500.00, 0.00, 18.00, 270.00, 1770.00, NULL, 0, 'new', NULL, 3, '2026-10-15 03:30:00', '2026-10-15 03:30:00'),
(6, 1, 5, 2, '2026-08-01', '2026-10-29', 'expired', 4000.00, 0.00, 18.00, 720.00, 4720.00, NULL, 0, 'new', NULL, 1, '2026-08-01 03:30:00', '2026-10-30 03:30:00'),
(7, 1, 6, 2, '2026-09-01', '2026-11-29', 'frozen', 4000.00, 0.00, 18.00, 720.00, 4720.00, '2026-11-01', 0, 'new', NULL, 1, '2026-09-01 03:30:00', '2026-11-01 03:30:00'),
(8, 1, 7, 1, '2026-11-02', '2026-12-01', 'active', 1500.00, 0.00, 18.00, 270.00, 1770.00, NULL, 0, 'new', NULL, 3, '2026-11-02 03:30:00', '2026-11-02 03:30:00'),
(9, 1, 8, 5, '2026-11-01', '2026-11-30', 'active', 8000.00, 0.00, 18.00, 1440.00, 9440.00, NULL, 0, 'new', NULL, 1, '2026-11-01 03:30:00', '2026-11-01 03:30:00'),
(10, 1, 9, 1, '2026-04-01', '2026-04-30', 'expired', 1500.00, 0.00, 18.00, 270.00, 1770.00, NULL, 0, 'new', NULL, 1, '2026-04-01 03:30:00', '2026-05-01 03:30:00'),
(11, 1, 10, 1, '2025-12-01', '2025-12-30', 'expired', 1500.00, 0.00, 18.00, 270.00, 1770.00, NULL, 0, 'new', NULL, 1, '2025-12-01 03:30:00', '2026-01-02 03:30:00'),
(12, 1, 11, 3, '2026-08-11', '2027-02-06', 'active', 7200.00, 0.00, 18.00, 1296.00, 8496.00, NULL, 0, 'new', NULL, 1, '2026-08-11 03:30:00', '2026-08-11 03:30:00'),
(13, 1, 12, 1, '2026-11-10', '2026-12-09', 'active', 1500.00, 0.00, 18.00, 270.00, 1770.00, NULL, 0, 'new', NULL, 3, '2026-11-10 03:30:00', '2026-11-10 03:30:00');

INSERT INTO invoices (id, gym_id, invoice_number, member_id, subscription_id, invoice_date, subtotal, discount, tax_percent, tax_amount, total_amount, amount_paid, balance_due, status, created_by, created_at, updated_at) VALUES
(1, 1, 'INV-2025-0001', 10, 11, '2025-12-01', 1500.00, 0.00, 18.00, 270.00, 1770.00, 1770.00, 0.00, 'paid', 1, '2025-12-01 03:30:00', '2025-12-01 03:30:00'),
(2, 1, 'INV-2026-0002', 1, 1, '2026-01-15', 12000.00, 0.00, 18.00, 2160.00, 14160.00, 14160.00, 0.00, 'paid', 1, '2026-01-15 03:30:00', '2026-01-15 03:30:00'),
(3, 1, 'INV-2026-0003', 9, 10, '2026-04-01', 1500.00, 0.00, 18.00, 270.00, 1770.00, 1770.00, 0.00, 'paid', 1, '2026-04-01 03:30:00', '2026-04-01 03:30:00'),
(4, 1, 'INV-2026-0004', 5, 6, '2026-08-01', 4000.00, 0.00, 18.00, 720.00, 4720.00, 0.00, 4720.00, 'unpaid', 1, '2026-08-01 03:30:00', '2026-08-01 03:30:00'),
(5, 1, 'INV-2026-0005', 11, 12, '2026-08-11', 7200.00, 0.00, 18.00, 1296.00, 8496.00, 8496.00, 0.00, 'paid', 1, '2026-08-11 03:30:00', '2026-08-11 03:30:00'),
(6, 1, 'INV-2026-0006', 6, 7, '2026-09-01', 4000.00, 0.00, 18.00, 720.00, 4720.00, 4720.00, 0.00, 'paid', 1, '2026-09-01 03:30:00', '2026-09-01 03:30:00'),
(7, 1, 'INV-2026-0007', 3, 4, '2026-09-01', 4000.00, 0.00, 18.00, 720.00, 4720.00, 2000.00, 2720.00, 'partial', 3, '2026-09-01 03:30:00', '2026-11-05 04:00:00'),
(8, 1, 'INV-2026-0008', 2, 2, '2026-09-20', 1500.00, 0.00, 18.00, 270.00, 1770.00, 1770.00, 0.00, 'paid', 3, '2026-09-20 03:30:00', '2026-09-20 03:30:00'),
(9, 1, 'INV-2026-0009', 4, 5, '2026-10-15', 1500.00, 0.00, 18.00, 270.00, 1770.00, 500.00, 1270.00, 'partial', 3, '2026-10-15 03:30:00', '2026-11-01 03:30:00'),
(10, 1, 'INV-2026-0010', 2, 3, '2026-10-20', 1500.00, 0.00, 18.00, 270.00, 1770.00, 1770.00, 0.00, 'paid', 1, '2026-10-20 03:30:00', '2026-10-20 03:30:00'),
(11, 1, 'INV-2026-0011', 8, 9, '2026-11-01', 8000.00, 0.00, 18.00, 1440.00, 9440.00, 4000.00, 5440.00, 'partial', 1, '2026-11-01 03:30:00', '2026-11-01 03:30:00'),
(12, 1, 'INV-2026-0012', 7, 8, '2026-11-02', 1500.00, 0.00, 18.00, 270.00, 1770.00, 1770.00, 0.00, 'paid', 3, '2026-11-02 03:30:00', '2026-11-02 03:30:00'),
(13, 1, 'INV-2026-0013', 12, 13, '2026-11-10', 1500.00, 0.00, 18.00, 270.00, 1770.00, 0.00, 1770.00, 'unpaid', 3, '2026-11-10 03:30:00', '2026-11-10 03:30:00');

INSERT INTO invoice_items (gym_id, invoice_id, description, quantity, unit_price, amount, created_at) VALUES
(1, 1, 'Monthly Plan (2025-12-01 to 2025-12-30)', 1, 1500.00, 1500.00, '2025-12-01 03:30:00'),
(1, 2, 'Annual Plan (2026-01-15 to 2027-01-14)', 1, 12000.00, 12000.00, '2026-01-15 03:30:00'),
(1, 3, 'Monthly Plan (2026-04-01 to 2026-04-30)', 1, 1500.00, 1500.00, '2026-04-01 03:30:00'),
(1, 4, 'Quarterly Plan (2026-08-01 to 2026-10-29)', 1, 4000.00, 4000.00, '2026-08-01 03:30:00'),
(1, 5, 'Half-Yearly Plan (2026-08-11 to 2027-02-06)', 1, 7200.00, 7200.00, '2026-08-11 03:30:00'),
(1, 6, 'Quarterly Plan (2026-09-01 to 2026-11-29)', 1, 4000.00, 4000.00, '2026-09-01 03:30:00'),
(1, 7, 'Quarterly Plan (2026-09-01 to 2026-11-29)', 1, 4000.00, 4000.00, '2026-09-01 03:30:00'),
(1, 8, 'Monthly Plan (2026-09-20 to 2026-10-19)', 1, 1500.00, 1500.00, '2026-09-20 03:30:00'),
(1, 9, 'Monthly Plan (2026-10-15 to 2026-11-13)', 1, 1500.00, 1500.00, '2026-10-15 03:30:00'),
(1, 10, 'Monthly Plan (2026-10-20 to 2026-11-18)', 1, 1500.00, 1500.00, '2026-10-20 03:30:00'),
(1, 11, 'Personal Training (2026-11-01 to 2026-11-30)', 1, 8000.00, 8000.00, '2026-11-01 03:30:00'),
(1, 12, 'Monthly Plan (2026-11-02 to 2026-12-01)', 1, 1500.00, 1500.00, '2026-11-02 03:30:00'),
(1, 13, 'Monthly Plan (2026-11-10 to 2026-12-09)', 1, 1500.00, 1500.00, '2026-11-10 03:30:00');

INSERT INTO payments (gym_id, invoice_id, member_id, subscription_id, amount, payment_date, method, reference_no, recorded_by, status, created_at, updated_at) VALUES
(1, 1, 10, 11, 1770.00, '2025-12-01', 'cash', NULL, 1, 'recorded', '2025-12-01 03:40:00', '2025-12-01 03:40:00'),
(1, 2, 1, 1, 14160.00, '2026-01-15', 'bank_transfer', 'NEFT-1001', 1, 'recorded', '2026-01-15 03:40:00', '2026-01-15 03:40:00'),
(1, 3, 9, 10, 1770.00, '2026-04-01', 'cash', NULL, 1, 'recorded', '2026-04-01 03:40:00', '2026-04-01 03:40:00'),
(1, 5, 11, 12, 8496.00, '2026-08-11', 'bank_transfer', 'NEFT-441', 1, 'recorded', '2026-08-11 03:40:00', '2026-08-11 03:40:00'),
(1, 6, 6, 7, 4720.00, '2026-09-01', 'upi', 'UPI-ANANYA', 1, 'recorded', '2026-09-01 03:40:00', '2026-09-01 03:40:00'),
(1, 7, 3, 4, 1500.00, '2026-09-02', 'upi', 'UPI-ROHAN-1', 3, 'recorded', '2026-09-02 04:00:00', '2026-09-02 04:00:00'),
(1, 7, 3, 4, 500.00, '2026-11-05', 'upi', 'UPI-ROHAN-2', 3, 'recorded', '2026-11-05 04:00:00', '2026-11-05 04:00:00'),
(1, 8, 2, 2, 1770.00, '2026-09-20', 'upi', NULL, 3, 'recorded', '2026-09-20 04:00:00', '2026-09-20 04:00:00'),
(1, 9, 4, 5, 500.00, '2026-11-01', 'cash', NULL, 3, 'recorded', '2026-11-01 04:10:00', '2026-11-01 04:10:00'),
(1, 10, 2, 3, 1770.00, '2026-10-20', 'upi', 'UPI-MEERA', 1, 'recorded', '2026-10-20 04:00:00', '2026-10-20 04:00:00'),
(1, 11, 8, 9, 4000.00, '2026-11-01', 'card', 'CARD-4481', 1, 'recorded', '2026-11-01 04:20:00', '2026-11-01 04:20:00'),
(1, 12, 7, 8, 1770.00, '2026-11-02', 'upi', 'UPI-88211', 3, 'recorded', '2026-11-02 04:00:00', '2026-11-02 04:00:00');

INSERT INTO attendance (gym_id, member_id, check_in_at, check_out_at, method, recorded_by, override_expired, created_at) VALUES
(1, 1, '2026-11-02 01:30:00', '2026-11-02 03:00:00', 'qr', 3, 0, '2026-11-02 01:30:00'),
(1, 3, '2026-11-02 01:40:00', '2026-11-02 03:10:00', 'manual', 3, 0, '2026-11-02 01:40:00'),
(1, 11, '2026-11-02 02:00:00', '2026-11-02 03:20:00', 'qr', 3, 0, '2026-11-02 02:00:00'),
(1, 1, '2026-11-03 01:35:00', '2026-11-03 03:00:00', 'qr', 3, 0, '2026-11-03 01:35:00'),
(1, 2, '2026-11-03 01:50:00', '2026-11-03 03:05:00', 'manual', 3, 0, '2026-11-03 01:50:00'),
(1, 4, '2026-11-03 02:10:00', '2026-11-03 03:30:00', 'qr', 3, 0, '2026-11-03 02:10:00'),
(1, 8, '2026-11-03 02:20:00', '2026-11-03 04:00:00', 'manual', 3, 0, '2026-11-03 02:20:00'),
(1, 3, '2026-11-04 01:25:00', '2026-11-04 02:40:00', 'manual', 3, 0, '2026-11-04 01:25:00'),
(1, 11, '2026-11-04 12:30:00', '2026-11-04 14:00:00', 'qr', 3, 0, '2026-11-04 12:30:00'),
(1, 1, '2026-11-05 01:20:00', '2026-11-05 02:50:00', 'qr', 3, 0, '2026-11-05 01:20:00'),
(1, 2, '2026-11-05 01:45:00', '2026-11-05 03:00:00', 'manual', 3, 0, '2026-11-05 01:45:00'),
(1, 4, '2026-11-05 02:00:00', '2026-11-05 03:10:00', 'qr', 3, 0, '2026-11-05 02:00:00'),
(1, 7, '2026-11-05 02:05:00', '2026-11-05 03:20:00', 'manual', 3, 0, '2026-11-05 02:05:00'),
(1, 11, '2026-11-05 02:15:00', '2026-11-05 03:30:00', 'qr', 3, 0, '2026-11-05 02:15:00'),
(1, 1, '2026-11-06 01:30:00', '2026-11-06 03:00:00', 'qr', 3, 0, '2026-11-06 01:30:00'),
(1, 3, '2026-11-06 01:55:00', '2026-11-06 03:05:00', 'manual', 3, 0, '2026-11-06 01:55:00'),
(1, 8, '2026-11-06 02:10:00', '2026-11-06 03:40:00', 'qr', 3, 0, '2026-11-06 02:10:00'),
(1, 2, '2026-11-07 01:40:00', '2026-11-07 03:00:00', 'manual', 3, 0, '2026-11-07 01:40:00'),
(1, 4, '2026-11-07 02:00:00', '2026-11-07 03:15:00', 'qr', 3, 0, '2026-11-07 02:00:00'),
(1, 7, '2026-11-07 02:20:00', '2026-11-07 03:40:00', 'manual', 3, 0, '2026-11-07 02:20:00'),
(1, 11, '2026-11-07 02:30:00', '2026-11-07 03:50:00', 'qr', 3, 0, '2026-11-07 02:30:00'),
(1, 1, '2026-11-09 01:20:00', '2026-11-09 02:40:00', 'qr', 3, 0, '2026-11-09 01:20:00'),
(1, 2, '2026-11-09 01:35:00', '2026-11-09 03:00:00', 'manual', 3, 0, '2026-11-09 01:35:00'),
(1, 3, '2026-11-09 01:50:00', '2026-11-09 03:10:00', 'qr', 3, 0, '2026-11-09 01:50:00'),
(1, 4, '2026-11-09 02:00:00', '2026-11-09 03:20:00', 'manual', 3, 0, '2026-11-09 02:00:00'),
(1, 8, '2026-11-09 02:15:00', '2026-11-09 03:45:00', 'qr', 3, 0, '2026-11-09 02:15:00'),
(1, 11, '2026-11-09 02:25:00', '2026-11-09 03:55:00', 'manual', 3, 0, '2026-11-09 02:25:00'),
(1, 1, '2026-11-10 02:00:00', '2026-11-10 03:20:00', 'qr', 3, 0, '2026-11-10 02:00:00'),
(1, 7, '2026-11-10 02:10:00', '2026-11-10 03:30:00', 'manual', 3, 0, '2026-11-10 02:10:00'),
(1, 8, '2026-11-10 02:20:00', '2026-11-10 03:50:00', 'qr', 3, 0, '2026-11-10 02:20:00'),
(1, 12, '2026-11-10 02:40:00', '2026-11-10 03:40:00', 'manual', 3, 0, '2026-11-10 02:40:00'),
(1, 1, '2026-11-11 01:10:00', NULL, 'qr', 3, 0, '2026-11-11 01:10:00'),
(1, 2, '2026-11-11 01:45:00', '2026-11-11 03:30:00', 'manual', 3, 0, '2026-11-11 01:45:00'),
(1, 7, '2026-11-11 02:35:00', NULL, 'qr', 3, 0, '2026-11-11 02:35:00'),
(1, 11, '2026-11-11 04:00:00', '2026-11-11 04:45:00', 'manual', 3, 0, '2026-11-11 04:00:00');

INSERT INTO expense_categories (id, gym_id, name, created_at) VALUES
(1, 1, 'Rent', '2026-06-01 05:00:00'),
(2, 1, 'Utilities', '2026-06-01 05:00:00'),
(3, 1, 'Equipment', '2026-06-01 05:00:00'),
(4, 1, 'Salaries', '2026-06-01 05:00:00'),
(5, 1, 'Marketing', '2026-06-01 05:00:00'),
(6, 1, 'Maintenance', '2026-06-01 05:00:00'),
(7, 1, 'Other', '2026-06-01 05:00:00');

INSERT INTO expenses (gym_id, category_id, expense_date, amount, description, payment_method, recorded_by, created_at, updated_at) VALUES
(1, 1, '2026-10-01', 45000.00, 'October rent', 'bank_transfer', 2, '2026-10-01 05:00:00', '2026-10-01 05:00:00'),
(1, 4, '2026-10-31', 78000.00, 'October payroll', 'bank_transfer', 2, '2026-10-31 05:00:00', '2026-10-31 05:00:00'),
(1, 1, '2026-11-01', 45000.00, 'November rent', 'bank_transfer', 2, '2026-11-01 05:00:00', '2026-11-01 05:00:00'),
(1, 2, '2026-11-03', 6200.00, 'Electricity and water', 'upi', 2, '2026-11-03 05:00:00', '2026-11-03 05:00:00'),
(1, 3, '2026-11-06', 3800.00, 'Dumbbell set', 'card', 2, '2026-11-06 05:00:00', '2026-11-06 05:00:00'),
(1, 5, '2026-11-08', 2500.00, 'Local ads', 'upi', 2, '2026-11-08 05:00:00', '2026-11-08 05:00:00'),
(1, 6, '2026-11-09', 1500.00, 'Treadmill belt', 'cash', 2, '2026-11-09 05:00:00', '2026-11-09 05:00:00');

INSERT INTO notifications (gym_id, user_id, type, title, body, link, is_read, created_at) VALUES
(1, 1, 'membership.expiring', 'Membership ending in 7 days', 'Meera Iyer · Monthly Plan', 'members/2', 0, '2026-11-11 02:00:00'),
(1, 1, 'payment.received', 'Payment recorded', 'Vikram Patel · INV-2026-0012', 'invoices/12', 1, '2026-11-02 04:05:00'),
(1, 1, 'member.created', 'New member', 'Ishaan Verma · PF-1012', 'members/12', 0, '2026-11-10 03:35:00'),
(1, 1, 'payment.overdue', 'Payment reminder', 'Kabir Khan still has an open balance.', 'invoices/4', 0, '2026-11-08 03:00:00'),
(1, 2, 'membership.expiring', 'Membership ending soon', 'Simran Kaur expires on 13 Nov 2026.', 'members/4', 0, '2026-11-11 02:05:00');

INSERT INTO whatsapp_settings (gym_id, api_version, demo_mode, webhook_verify_token, default_country_code, created_at, updated_at)
VALUES (1, 'v21.0', 1, 'pulseforge-demo-verify', '91', '2026-06-01 05:10:00', '2026-06-01 05:10:00');

INSERT INTO whatsapp_templates (gym_id, template_key, language_code, body_preview, is_active, created_at, updated_at) VALUES
(1, 'welcome', 'en', 'Welcome message — Body: member name, gym name', 1, '2026-06-01 05:10:00', '2026-06-01 05:10:00'),
(1, 'expiry_reminder', 'en', 'Membership expiry reminder — Body: member name, plan, expiry date, days remaining', 1, '2026-06-01 05:10:00', '2026-06-01 05:10:00'),
(1, 'payment_reminder', 'en', 'Payment reminder — Body: member name, invoice number, balance, status', 1, '2026-06-01 05:10:00', '2026-06-01 05:10:00'),
(1, 'payment_confirmation', 'en', 'Payment confirmation — Body: member name, amount, invoice number, balance', 1, '2026-06-01 05:10:00', '2026-06-01 05:10:00'),
(1, 'invoice_document', 'en', 'Invoice PDF — Header document plus body: member name, invoice number, total', 1, '2026-06-01 05:10:00', '2026-06-01 05:10:00'),
(1, 'renewal_confirmation', 'en', 'Renewal confirmation — Body: member name, plan, new expiry date', 1, '2026-06-01 05:10:00', '2026-06-01 05:10:00'),
(1, 'announcement', 'en', 'Announcement — Body: member name, message', 1, '2026-06-01 05:10:00', '2026-06-01 05:10:00');

INSERT INTO whatsapp_message_logs (gym_id, member_id, template_key, phone, payload_json, response_json, status, attempts, created_at, updated_at)
VALUES (1, 7, 'welcome', '919800001007', JSON_OBJECT('mode', 'demo', 'preview', 'Welcome to PulseForge Athletics, Vikram Patel.'), JSON_OBJECT('demo', true), 'demo', 0, '2026-11-02 03:35:00', '2026-11-02 03:35:00');

INSERT INTO gym_settings (gym_id, setting_key, setting_value, updated_at) VALUES
(1, 'expiry_reminder_days', '7,3,0', '2026-06-01 05:10:00'),
(1, 'payment_reminder_enabled', '1', '2026-06-01 05:10:00'),
(1, 'payment_overdue_days', '1,3,7', '2026-06-01 05:10:00'),
(1, 'payment_repeat_days', '7', '2026-06-01 05:10:00'),
(1, 'allow_expired_checkin_override', '1', '2026-06-01 05:10:00'),
(1, 'freeze_max_days', '60', '2026-06-01 05:10:00');

INSERT INTO workout_plans (id, gym_id, name, description, trainer_id, created_at, updated_at)
VALUES (1, 1, 'Strength Foundation', 'Three full-body sessions and two easier days.', 4, '2026-07-01 05:00:00', '2026-07-01 05:00:00');

INSERT INTO workout_assignments (gym_id, workout_plan_id, member_id, assigned_by, start_date, notes, created_at) VALUES
(1, 1, 1, 4, '2026-07-01', 'Start with the empty bar.', '2026-07-01 05:00:00'),
(1, 1, 8, 4, '2026-11-01', 'Pair with PT sessions.', '2026-11-01 05:00:00');

INSERT INTO audit_logs (gym_id, user_id, action, entity_type, entity_id, new_values, created_at) VALUES
(1, 3, 'member.created', 'member', 12, JSON_OBJECT('summary', 'Registered Ishaan Verma'), '2026-11-10 03:35:00'),
(1, 1, 'payment.recorded', 'payment', 11, JSON_OBJECT('summary', 'Recorded 4000.00 against INV-2026-0011'), '2026-11-01 04:20:00'),
(1, 3, 'attendance.checkin', 'attendance', 32, JSON_OBJECT('summary', 'Checked in Aarav Shah', 'member_id', 1), '2026-11-11 01:10:00');

ALTER TABLE gyms AUTO_INCREMENT = 2;
ALTER TABLE users AUTO_INCREMENT = 5;
ALTER TABLE members AUTO_INCREMENT = 13;
ALTER TABLE membership_plans AUTO_INCREMENT = 6;
ALTER TABLE member_subscriptions AUTO_INCREMENT = 14;
ALTER TABLE invoices AUTO_INCREMENT = 14;
ALTER TABLE expense_categories AUTO_INCREMENT = 8;
ALTER TABLE workout_plans AUTO_INCREMENT = 2;
