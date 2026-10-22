-- Only for an EMPTY test database. A nuBuilder 4 database already contains these tables.
CREATE TABLE IF NOT EXISTS zzzzsys_access (
  zzzzsys_access_id varchar(25) NOT NULL PRIMARY KEY,
  sal_code varchar(50) DEFAULT NULL,
  sal_description varchar(200) DEFAULT NULL,
  sal_group varchar(80) DEFAULT NULL,
  sal_zzzzsys_form_id varchar(25) DEFAULT NULL,
  sal_use_2fa varchar(1) DEFAULT NULL,
  sal_json mediumtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zzzzsys_access_form (
  zzzzsys_access_form_id varchar(25) NOT NULL PRIMARY KEY,
  slf_zzzzsys_access_id varchar(25) DEFAULT NULL,
  slf_zzzzsys_form_id varchar(25) DEFAULT NULL,
  slf_add_button varchar(1) DEFAULT NULL,
  slf_save_button varchar(1) DEFAULT NULL,
  slf_delete_button varchar(1) DEFAULT NULL,
  slf_clone_button varchar(1) DEFAULT NULL,
  slf_new_button varchar(1) DEFAULT NULL,
  slf_print_button varchar(1) DEFAULT NULL,
  slf_data_mode varchar(2) DEFAULT NULL,
  slf_form_type varchar(2) DEFAULT NULL,
  slf_json mediumtext DEFAULT NULL,
  KEY slf_zzzzsys_access_id (slf_zzzzsys_access_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zzzzsys_access_php (
  zzzzsys_access_php_id varchar(25) NOT NULL PRIMARY KEY,
  slp_zzzzsys_access_id varchar(25) DEFAULT NULL,
  slp_zzzzsys_php_id varchar(25) DEFAULT NULL,
  slp_json mediumtext DEFAULT NULL,
  KEY slp_zzzzsys_access_id (slp_zzzzsys_access_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zzzzsys_user (
  zzzzsys_user_id varchar(25) NOT NULL PRIMARY KEY,
  sus_zzzzsys_access_id varchar(25) DEFAULT NULL,
  sus_language varchar(20) DEFAULT NULL,
  sus_name varchar(50) DEFAULT NULL,
  sus_first_name varchar(30) DEFAULT NULL,
  sus_last_name varchar(30) DEFAULT NULL,
  sus_code varchar(50) DEFAULT NULL,
  sus_position varchar(50) DEFAULT NULL,
  sus_department varchar(50) DEFAULT NULL,
  sus_team varchar(50) DEFAULT NULL,
  sus_email varchar(255) DEFAULT NULL,
  sus_additional1 varchar(100) DEFAULT NULL,
  sus_additional2 varchar(100) DEFAULT NULL,
  sus_permission varchar(1000) DEFAULT NULL,
  sus_login_name varchar(50) DEFAULT NULL,
  sus_login_password varchar(300) DEFAULT NULL,
  sus_expires_on datetime DEFAULT NULL,
  sus_change_password varchar(1) DEFAULT NULL,
  sus_accessibility_features varchar(1) DEFAULT NULL,
  sus_json mediumtext DEFAULT NULL,
  KEY sus_login_name (sus_login_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- nuBuilder setup (SMTP settings, header) and e-mail log
CREATE TABLE IF NOT EXISTS zzzzsys_setup (
  zzzzsys_setup_id varchar(25) NOT NULL PRIMARY KEY,
  set_time_out_minutes int(11) DEFAULT NULL, set_zzzzsys_timezone_id varchar(25) DEFAULT NULL, set_language varchar(20) DEFAULT NULL,
  set_languages_included varchar(1000) DEFAULT NULL, set_smtp_username varchar(255) DEFAULT NULL, set_smtp_password varchar(255) DEFAULT NULL,
  set_smtp_host varchar(255) DEFAULT NULL, set_smtp_from_address varchar(255) DEFAULT NULL, set_smtp_from_name varchar(255) DEFAULT NULL,
  set_smtp_port int(11) DEFAULT NULL, set_smtp_use_authentication varchar(1) DEFAULT NULL, set_smtp_use_ssl varchar(1) DEFAULT NULL,
  set_header longtext, set_include mediumtext, set_style longtext, set_denied varchar(1) DEFAULT NULL, set_json longtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zzzzsys_email_log (
  zzzzsys_email_log_id varchar(25) NOT NULL PRIMARY KEY,
  eml_created_at datetime DEFAULT CURRENT_TIMESTAMP, eml_sent_at datetime DEFAULT NULL, eml_state varchar(20) DEFAULT NULL,
  eml_from varchar(70) DEFAULT NULL, eml_from_name varchar(70) DEFAULT NULL, eml_to varchar(4000) DEFAULT NULL,
  eml_cc varchar(4000) DEFAULT NULL, eml_bcc varchar(4000) DEFAULT NULL, eml_subject varchar(250) DEFAULT NULL, eml_body text,
  eml_file varchar(1000) DEFAULT NULL, eml_html tinyint(1) DEFAULT NULL, eml_reply_to varchar(100) DEFAULT NULL,
  eml_form_id varchar(25) DEFAULT NULL, eml_user_id varchar(25) DEFAULT NULL, eml_record_id varchar(25) DEFAULT NULL,
  eml_importance smallint DEFAULT NULL, eml_error varchar(200) DEFAULT NULL, eml_table_name varchar(100) DEFAULT NULL,
  eml_tag varchar(70) DEFAULT NULL, eml_json text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- files (nuBuilder form "nufile": pictures of image objects, files used by reports and code)
CREATE TABLE IF NOT EXISTS zzzzsys_file (
  zzzzsys_file_id varchar(25) NOT NULL PRIMARY KEY, sfi_code varchar(300) DEFAULT NULL, sfi_description varchar(300) DEFAULT NULL,
  sfi_group varchar(300) DEFAULT NULL, sfi_json longtext, sfi_system char(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- form cloner settings (nuBuilder form "nucloner")
CREATE TABLE IF NOT EXISTS zzzzsys_cloner (
  zzzzsys_cloner_id varchar(25) NOT NULL PRIMARY KEY, clo_form_source varchar(25) DEFAULT NULL, clo_form_dest varchar(25) DEFAULT NULL,
  clo_tabs varchar(1000) DEFAULT NULL, clo_dump tinyint(1) DEFAULT NULL, clo_objects tinyint(1) DEFAULT NULL, clo_subforms_include tinyint(1) DEFAULT NULL,
  clo_subforms varchar(1000) DEFAULT NULL, clo_iframe_forms_include tinyint(1) DEFAULT NULL, clo_iframe_forms text, clo_tables_include mediumtext,
  clo_tables_exclude mediumtext, clo_new_pks tinyint(1) DEFAULT NULL, clo_notes text, clo_json mediumtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- App cloner configurations (nuBuilder form "nuappcloner")
CREATE TABLE IF NOT EXISTS zzzzsys_app_cloner (
  zzzzsys_app_cloner_id varchar(25) NOT NULL PRIMARY KEY, cla_code varchar(100) DEFAULT NULL, cla_description varchar(100) DEFAULT NULL,
  cla_log_file varchar(150) DEFAULT NULL, cla_copy_files varchar(1) DEFAULT NULL, cla_copy_files_mode varchar(10) DEFAULT NULL,
  cla_source_path varchar(400) DEFAULT NULL, cla_target_host varchar(50) DEFAULT NULL, cla_target_db varchar(40) DEFAULT NULL,
  cla_target_username varchar(50) DEFAULT NULL, cla_target_password varchar(50) DEFAULT NULL, cla_target_port smallint(6) DEFAULT NULL,
  cla_target_db_collation varchar(30) DEFAULT NULL, cla_target_charset varchar(20) DEFAULT NULL, cla_target_path varchar(400) DEFAULT NULL,
  cla_excluded_dirs varchar(2000) DEFAULT NULL, cla_excluded_files varchar(2000) DEFAULT NULL, cla_insert_type varchar(15) DEFAULT NULL,
  cla_target_db_mode varchar(10) DEFAULT NULL, cla_dry_run varchar(1) DEFAULT NULL, cla_opt_1 varchar(1) DEFAULT NULL, cla_opt_2 varchar(1) DEFAULT NULL,
  cla_opt_2_include mediumtext, cla_opt_3 varchar(1) DEFAULT NULL, cla_opt_4 varchar(1) DEFAULT NULL, cla_opt_5 varchar(1) DEFAULT NULL,
  cla_opt_6 varchar(1) DEFAULT NULL, cla_opt_7 varchar(1) DEFAULT NULL, cla_opt_8 varchar(1) DEFAULT NULL, cla_opt_9 varchar(1) DEFAULT NULL,
  cla_show_progress varchar(1) DEFAULT NULL, cla_transaction_mode varchar(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- translations (nuBuilder form "nutranslate")
CREATE TABLE IF NOT EXISTS zzzzsys_translate (
  zzzzsys_translate_id varchar(25) NOT NULL PRIMARY KEY, trl_language varchar(20) DEFAULT NULL, trl_english varchar(500) DEFAULT NULL,
  trl_translation varchar(500) DEFAULT NULL, trl_json mediumtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO zzzzsys_setup (zzzzsys_setup_id, set_time_out_minutes, set_denied) VALUES ('1', 480, '0');

CREATE TABLE IF NOT EXISTS zzzzsys_email_template (
  zzzzsys_email_template_id varchar(25) NOT NULL PRIMARY KEY, emt_form_id varchar(25) DEFAULT NULL, emt_group varchar(100) DEFAULT NULL,
  emt_category varchar(100) DEFAULT NULL, emt_template varchar(1) DEFAULT NULL, emt_language varchar(20) DEFAULT NULL,
  emt_description varchar(200) DEFAULT NULL, emt_code varchar(80) DEFAULT NULL, emt_body mediumtext, emt_subject varchar(1000) DEFAULT NULL,
  emt_to varchar(1000) DEFAULT NULL, emt_cc varchar(1000) DEFAULT NULL, emt_bcc varchar(1000) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- permission items (nuBuilder forms "nupermissionitem" / "nuuserpermission"; upe_ident = zzzzsys_permission_item_id)
CREATE TABLE IF NOT EXISTS zzzzsys_permission_item (
  zzzzsys_permission_item_id varchar(25) NOT NULL PRIMARY KEY, pme_description text, pme_group varchar(50) DEFAULT NULL, pme_ident varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS zzzzsys_user_permission (
  zzzzsys_user_permission_id varchar(25) NOT NULL PRIMARY KEY, upe_zzzzsys_user_id varchar(25) DEFAULT NULL, upe_ident varchar(50) DEFAULT NULL,
  upe_active varchar(1) DEFAULT NULL, KEY upe_zzzzsys_user_id (upe_zzzzsys_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
