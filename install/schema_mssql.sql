-- Only for an EMPTY test database. A nuBuilder 4 database already contains these tables.
IF OBJECT_ID('zzzzsys_access') IS NULL CREATE TABLE zzzzsys_access (
  zzzzsys_access_id nvarchar(25) NOT NULL PRIMARY KEY,
  sal_code nvarchar(50) NULL, sal_description nvarchar(200) NULL, sal_group nvarchar(80) NULL,
  sal_zzzzsys_form_id nvarchar(25) NULL, sal_use_2fa nvarchar(1) NULL, sal_json nvarchar(max) NULL);

IF OBJECT_ID('zzzzsys_access_form') IS NULL CREATE TABLE zzzzsys_access_form (
  zzzzsys_access_form_id nvarchar(25) NOT NULL PRIMARY KEY,
  slf_zzzzsys_access_id nvarchar(25) NULL, slf_zzzzsys_form_id nvarchar(25) NULL,
  slf_add_button nvarchar(1) NULL, slf_save_button nvarchar(1) NULL, slf_delete_button nvarchar(1) NULL,
  slf_clone_button nvarchar(1) NULL, slf_new_button nvarchar(1) NULL, slf_print_button nvarchar(1) NULL,
  slf_data_mode nvarchar(2) NULL, slf_form_type nvarchar(2) NULL, slf_json nvarchar(max) NULL);

IF OBJECT_ID('zzzzsys_access_php') IS NULL CREATE TABLE zzzzsys_access_php (
  zzzzsys_access_php_id nvarchar(25) NOT NULL PRIMARY KEY,
  slp_zzzzsys_access_id nvarchar(25) NULL, slp_zzzzsys_php_id nvarchar(25) NULL, slp_json nvarchar(max) NULL);

IF OBJECT_ID('zzzzsys_user') IS NULL CREATE TABLE zzzzsys_user (
  zzzzsys_user_id nvarchar(25) NOT NULL PRIMARY KEY,
  sus_zzzzsys_access_id nvarchar(25) NULL, sus_language nvarchar(20) NULL, sus_name nvarchar(50) NULL,
  sus_first_name nvarchar(30) NULL, sus_last_name nvarchar(30) NULL, sus_code nvarchar(50) NULL,
  sus_position nvarchar(50) NULL, sus_department nvarchar(50) NULL, sus_team nvarchar(50) NULL,
  sus_email nvarchar(255) NULL, sus_additional1 nvarchar(100) NULL, sus_additional2 nvarchar(100) NULL,
  sus_permission nvarchar(1000) NULL, sus_login_name nvarchar(50) NULL, sus_login_password nvarchar(300) NULL,
  sus_expires_on datetime NULL, sus_change_password nvarchar(1) NULL, sus_accessibility_features nvarchar(1) NULL,
  sus_json nvarchar(max) NULL);

-- nuBuilder setup (SMTP settings) and e-mail log
IF OBJECT_ID('zzzzsys_setup') IS NULL CREATE TABLE zzzzsys_setup (
  zzzzsys_setup_id NVARCHAR(25) NOT NULL PRIMARY KEY,
  set_time_out_minutes INT NULL, set_zzzzsys_timezone_id NVARCHAR(25) NULL, set_language NVARCHAR(20) NULL,
  set_languages_included NVARCHAR(1000) NULL, set_smtp_username NVARCHAR(255) NULL, set_smtp_password NVARCHAR(255) NULL,
  set_smtp_host NVARCHAR(255) NULL, set_smtp_from_address NVARCHAR(255) NULL, set_smtp_from_name NVARCHAR(255) NULL,
  set_smtp_port INT NULL, set_smtp_use_authentication NVARCHAR(1) NULL, set_smtp_use_ssl NVARCHAR(1) NULL,
  set_header NVARCHAR(MAX) NULL, set_include NVARCHAR(MAX) NULL, set_style NVARCHAR(MAX) NULL, set_denied NVARCHAR(1) NULL, set_json NVARCHAR(MAX) NULL
);

IF OBJECT_ID('zzzzsys_email_log') IS NULL CREATE TABLE zzzzsys_email_log (
  zzzzsys_email_log_id NVARCHAR(25) NOT NULL PRIMARY KEY,
  eml_created_at DATETIME2(0) NULL DEFAULT SYSDATETIME(), eml_sent_at DATETIME2(0) NULL, eml_state NVARCHAR(20) NULL,
  eml_from NVARCHAR(70) NULL, eml_from_name NVARCHAR(70) NULL, eml_to NVARCHAR(4000) NULL, eml_cc NVARCHAR(4000) NULL,
  eml_bcc NVARCHAR(4000) NULL, eml_subject NVARCHAR(250) NULL, eml_body NVARCHAR(MAX) NULL, eml_file NVARCHAR(1000) NULL,
  eml_html TINYINT NULL, eml_reply_to NVARCHAR(100) NULL, eml_form_id NVARCHAR(25) NULL, eml_user_id NVARCHAR(25) NULL,
  eml_record_id NVARCHAR(25) NULL, eml_importance SMALLINT NULL, eml_error NVARCHAR(200) NULL, eml_table_name NVARCHAR(100) NULL,
  eml_tag NVARCHAR(70) NULL, eml_json NVARCHAR(MAX) NULL
);

IF NOT EXISTS (SELECT 1 FROM zzzzsys_setup) INSERT INTO zzzzsys_setup (zzzzsys_setup_id, set_time_out_minutes, set_denied) VALUES ('1', 480, '0');

-- nuBuilder formats
IF OBJECT_ID('zzzzsys_file') IS NULL CREATE TABLE zzzzsys_file (
  zzzzsys_file_id NVARCHAR(25) NOT NULL PRIMARY KEY, sfi_code NVARCHAR(300) NULL, sfi_description NVARCHAR(300) NULL,
  sfi_group NVARCHAR(300) NULL, sfi_json NVARCHAR(MAX) NULL, sfi_system NCHAR(1) NULL
);
IF OBJECT_ID('zzzzsys_cloner') IS NULL CREATE TABLE zzzzsys_cloner (
  zzzzsys_cloner_id NVARCHAR(25) NOT NULL PRIMARY KEY, clo_form_source NVARCHAR(25) NULL, clo_form_dest NVARCHAR(25) NULL,
  clo_tabs NVARCHAR(1000) NULL, clo_dump TINYINT NULL, clo_objects TINYINT NULL, clo_subforms_include TINYINT NULL,
  clo_subforms NVARCHAR(1000) NULL, clo_iframe_forms_include TINYINT NULL, clo_iframe_forms NVARCHAR(MAX) NULL, clo_tables_include NVARCHAR(MAX) NULL,
  clo_tables_exclude NVARCHAR(MAX) NULL, clo_new_pks TINYINT NULL, clo_notes NVARCHAR(MAX) NULL, clo_json NVARCHAR(MAX) NULL
);
IF OBJECT_ID('zzzzsys_app_cloner') IS NULL CREATE TABLE zzzzsys_app_cloner (
  zzzzsys_app_cloner_id NVARCHAR(25) NOT NULL PRIMARY KEY, cla_code NVARCHAR(100) NULL, cla_description NVARCHAR(100) NULL,
  cla_log_file NVARCHAR(150) NULL, cla_copy_files NVARCHAR(1) NULL, cla_copy_files_mode NVARCHAR(10) NULL,
  cla_source_path NVARCHAR(400) NULL, cla_target_host NVARCHAR(50) NULL, cla_target_db NVARCHAR(40) NULL,
  cla_target_username NVARCHAR(50) NULL, cla_target_password NVARCHAR(50) NULL, cla_target_port INT NULL,
  cla_target_db_collation NVARCHAR(30) NULL, cla_target_charset NVARCHAR(20) NULL, cla_target_path NVARCHAR(400) NULL,
  cla_excluded_dirs NVARCHAR(2000) NULL, cla_excluded_files NVARCHAR(2000) NULL, cla_insert_type NVARCHAR(15) NULL,
  cla_target_db_mode NVARCHAR(10) NULL, cla_dry_run NVARCHAR(1) NULL, cla_opt_1 NVARCHAR(1) NULL, cla_opt_2 NVARCHAR(1) NULL,
  cla_opt_2_include NVARCHAR(MAX) NULL, cla_opt_3 NVARCHAR(1) NULL, cla_opt_4 NVARCHAR(1) NULL, cla_opt_5 NVARCHAR(1) NULL,
  cla_opt_6 NVARCHAR(1) NULL, cla_opt_7 NVARCHAR(1) NULL, cla_opt_8 NVARCHAR(1) NULL, cla_opt_9 NVARCHAR(1) NULL,
  cla_show_progress NVARCHAR(1) NULL, cla_transaction_mode NVARCHAR(1) NULL
);
IF OBJECT_ID('zzzzsys_translate') IS NULL CREATE TABLE zzzzsys_translate (
  zzzzsys_translate_id NVARCHAR(25) NOT NULL PRIMARY KEY, trl_language NVARCHAR(20) NULL, trl_english NVARCHAR(500) NULL,
  trl_translation NVARCHAR(500) NULL, trl_json NVARCHAR(MAX) NULL
);
IF OBJECT_ID('zzzzsys_format') IS NULL CREATE TABLE zzzzsys_format (
  zzzzsys_format_id NVARCHAR(25) NOT NULL PRIMARY KEY, srm_type NVARCHAR(10) NULL, srm_default NVARCHAR(1) NULL,
  srm_format NVARCHAR(300) NULL, srm_currency NVARCHAR(25) NULL, srm_json NVARCHAR(MAX) NULL
);
IF NOT EXISTS (SELECT 1 FROM zzzzsys_format) INSERT INTO zzzzsys_format (zzzzsys_format_id, srm_type, srm_default, srm_format, srm_currency, srm_json) VALUES
('nu59e28f061a779da', 'Number', '0', N'$ 1,000.00', '["$",",",".","2"]', NULL),
('nu5d8a9b303b55b6d', 'Date', '0', 'dd-mm-yy', NULL, NULL),
('nu5d8a9b47ac4f5ae', 'Number', '1', '1000.00', '["","",".",2]', NULL),
('nu5fdfc72e7021ccf', 'Date', '1', 'yyyy-mm-dd', NULL, NULL),
('nu62fb20c1f13fd44', 'Date', '0', 'dd.mm.yyyy', NULL, NULL),
('nu687e3ae91ea9386', 'Number', NULL, '1000', '["","","",""]', NULL);

-- nuBuilder notes and code snippets (forms: install/notes_snippets_mysql.sql for MySQL; a nuBuilder database has them already)
IF OBJECT_ID('zzzzsys_note') IS NULL CREATE TABLE zzzzsys_note (
  zzzzsys_note_id NVARCHAR(25) NOT NULL PRIMARY KEY, not_title NVARCHAR(60) NOT NULL, not_content NVARCHAR(MAX) NULL, not_text NVARCHAR(MAX) NULL,
  not_updated_on DATETIME2(0) NULL DEFAULT SYSDATETIME(), not_zzzzsys_note_category_id NVARCHAR(25) NULL, not_json NVARCHAR(MAX) NULL
);
IF OBJECT_ID('zzzzsys_note_category') IS NULL CREATE TABLE zzzzsys_note_category (
  zzzzsys_note_category_id NVARCHAR(25) NOT NULL PRIMARY KEY, noc_name NVARCHAR(1000) NULL, hoc_json NVARCHAR(MAX) NULL
);
IF OBJECT_ID('zzzzsys_code_snippet') IS NULL CREATE TABLE zzzzsys_code_snippet (
  zzzzsys_code_snippet_id NVARCHAR(25) NOT NULL PRIMARY KEY, cot_code NVARCHAR(50) NULL, cot_description NVARCHAR(MAX) NULL, cot_group NVARCHAR(80) NULL,
  cot_url NVARCHAR(500) NULL, cot_source_code NVARCHAR(MAX) NULL, cot_language NVARCHAR(20) NULL, cot_scope NVARCHAR(50) NULL,
  cot_template NVARCHAR(1) NULL, cot_updated_on DATETIME2(0) NULL DEFAULT SYSDATETIME(), cot_json NVARCHAR(MAX) NULL
);

IF OBJECT_ID('zzzzsys_email_template') IS NULL CREATE TABLE zzzzsys_email_template (
  zzzzsys_email_template_id NVARCHAR(25) NOT NULL PRIMARY KEY, emt_form_id NVARCHAR(25) NULL, emt_group NVARCHAR(100) NULL,
  emt_category NVARCHAR(100) NULL, emt_template NVARCHAR(1) NULL, emt_language NVARCHAR(20) NULL, emt_description NVARCHAR(200) NULL,
  emt_code NVARCHAR(80) NULL, emt_body NVARCHAR(MAX) NULL, emt_subject NVARCHAR(1000) NULL, emt_to NVARCHAR(1000) NULL,
  emt_cc NVARCHAR(1000) NULL, emt_bcc NVARCHAR(1000) NULL
);

IF OBJECT_ID('zzzzsys_debug') IS NULL CREATE TABLE zzzzsys_debug (
  zzzzsys_debug_id NVARCHAR(25) NOT NULL PRIMARY KEY, deb_message NVARCHAR(MAX) NULL, deb_flag NVARCHAR(50) NULL, deb_added INT NULL,
  deb_user_id NVARCHAR(25) NULL, deb_json NVARCHAR(MAX) NULL
);
IF OBJECT_ID('zzzzsys_item') IS NULL CREATE TABLE zzzzsys_item (
  zzzzsys_item_id NVARCHAR(25) NOT NULL PRIMARY KEY, itm_value NVARCHAR(1000) NULL, itm_description NVARCHAR(MAX) NULL, itm_group NVARCHAR(100) NULL,
  itm_category NVARCHAR(255) NULL, itm_object_id NVARCHAR(25) NULL, itm_sort_order INT NOT NULL DEFAULT 0, itm_tag NVARCHAR(1000) NULL,
  itm_additional1 NVARCHAR(MAX) NULL, itm_additional2 NVARCHAR(2000) NULL, itm_notes NVARCHAR(2000) NULL, itm_css NVARCHAR(100) NULL,
  itm_icon NVARCHAR(100) NULL, itm_language NVARCHAR(25) NULL, itm_parent_id NVARCHAR(25) NULL, itm_active NVARCHAR(1) NULL DEFAULT '1',
  itm_created_on DATETIME2(0) NULL, itm_updated_on DATETIME2(0) NULL, itm_access_level NVARCHAR(2000) NULL, itm_json NVARCHAR(MAX) NULL
);

-- permission items (nuBuilder forms "nupermissionitem" / "nuuserpermission"; upe_ident = zzzzsys_permission_item_id)
IF OBJECT_ID('zzzzsys_permission_item') IS NULL CREATE TABLE zzzzsys_permission_item (
  zzzzsys_permission_item_id NVARCHAR(25) NOT NULL PRIMARY KEY, pme_description NVARCHAR(MAX) NULL, pme_group NVARCHAR(50) NULL, pme_ident NVARCHAR(50) NULL
);
IF OBJECT_ID('zzzzsys_user_permission') IS NULL CREATE TABLE zzzzsys_user_permission (
  zzzzsys_user_permission_id NVARCHAR(25) NOT NULL PRIMARY KEY, upe_zzzzsys_user_id NVARCHAR(25) NULL, upe_ident NVARCHAR(50) NULL, upe_active NVARCHAR(1) NULL
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'upe_zzzzsys_user_id') CREATE INDEX upe_zzzzsys_user_id ON zzzzsys_user_permission (upe_zzzzsys_user_id);

IF OBJECT_ID('zzzzsys_report') IS NULL CREATE TABLE zzzzsys_report (
  zzzzsys_report_id NVARCHAR(25) NOT NULL PRIMARY KEY, sre_code NVARCHAR(300) NULL, sre_description NVARCHAR(300) NULL, sre_group NVARCHAR(100) NULL,
  sre_zzzzsys_php_id NVARCHAR(200) NULL, sre_zzzzsys_form_id NVARCHAR(25) NULL, sre_layout NVARCHAR(MAX) NULL, sre_json NVARCHAR(MAX) NULL
);
IF OBJECT_ID('zzzzsys_access_report') IS NULL CREATE TABLE zzzzsys_access_report (
  zzzzsys_access_report_id NVARCHAR(25) NOT NULL PRIMARY KEY, sre_zzzzsys_access_id NVARCHAR(25) NULL, sre_zzzzsys_report_id NVARCHAR(25) NULL, srp_json NVARCHAR(MAX) NULL
);
IF OBJECT_ID('zzzzsys_select') IS NULL CREATE TABLE zzzzsys_select (
  zzzzsys_select_id NVARCHAR(25) NOT NULL PRIMARY KEY, sse_code NVARCHAR(50) NULL, sse_description NVARCHAR(300) NULL, sse_json NVARCHAR(MAX) NULL,
  sse_sql NVARCHAR(MAX) NULL, sse_edit NVARCHAR(1) NULL, sse_system NVARCHAR(1) NULL
);
IF OBJECT_ID('zzzzsys_select_clause') IS NULL CREATE TABLE zzzzsys_select_clause (
  zzzzsys_select_clause_id NVARCHAR(25) NOT NULL PRIMARY KEY, ssc_zzzzsys_select_id NVARCHAR(25) NULL, ssc_type NVARCHAR(300) NULL, ssc_field NVARCHAR(500) NULL,
  ssc_clause NVARCHAR(500) NULL, ssc_sort NVARCHAR(10) NULL, ssc_order NVARCHAR(500) NULL, ssc_json NVARCHAR(MAX) NULL
);
