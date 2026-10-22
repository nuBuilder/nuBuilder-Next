-- Only for an EMPTY test database. A nuBuilder 4 database already contains these tables.
CREATE TABLE IF NOT EXISTS zzzzsys_form (
  zzzzsys_form_id varchar(25) NOT NULL PRIMARY KEY,
  sfo_type varchar(300) DEFAULT NULL, sfo_code varchar(300) DEFAULT NULL, sfo_description varchar(300) DEFAULT NULL,
  sfo_group varchar(100) DEFAULT NULL, sfo_status varchar(1) DEFAULT NULL, sfo_breadcrumb_title varchar(100) DEFAULT NULL,
  sfo_table varchar(300) DEFAULT NULL, sfo_primary_key varchar(300) DEFAULT NULL, sfo_browse_redirect_form_id varchar(300) DEFAULT NULL,
  sfo_browse_target varchar(1) DEFAULT NULL, sfo_browse_row_height int(11) DEFAULT NULL, sfo_browse_rows_per_page int(11) DEFAULT NULL,
  sfo_browse_title_multiline varchar(1) DEFAULT NULL, sfo_browse_autoresize_columns varchar(1) DEFAULT NULL,
  sfo_browse_sql mediumtext, sfo_javascript longtext, sfo_browse_javascript longtext, sfo_edit_javascript longtext,
  sfo_style longtext, sfo_mobile_view varchar(1) DEFAULT NULL, sfo_json longtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zzzzsys_tab (
  zzzzsys_tab_id varchar(25) NOT NULL PRIMARY KEY, syt_zzzzsys_form_id varchar(25) DEFAULT NULL, syt_title varchar(250) DEFAULT NULL,
  syt_order int(11) DEFAULT NULL, syt_help varchar(3000) DEFAULT NULL, syt_access varchar(1) DEFAULT NULL, syt_json longtext,
  KEY syt_zzzzsys_form_id (syt_zzzzsys_form_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zzzzsys_browse (
  zzzzsys_browse_id varchar(25) NOT NULL PRIMARY KEY, sbr_zzzzsys_form_id varchar(25) DEFAULT NULL, sbr_title varchar(100) DEFAULT NULL,
  sbr_display varchar(512) DEFAULT NULL, sbr_align char(1) DEFAULT NULL, sbr_format varchar(300) DEFAULT NULL,
  sbr_order int(11) DEFAULT NULL, sbr_width int(11) DEFAULT NULL, sbr_json longtext,
  KEY sbr_zzzzsys_form_id (sbr_zzzzsys_form_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zzzzsys_event (
  zzzzsys_event_id varchar(25) NOT NULL PRIMARY KEY, sev_zzzzsys_object_id varchar(25) DEFAULT NULL, sev_event varchar(100) DEFAULT NULL,
  sev_javascript varchar(3000) DEFAULT NULL, sev_json longtext,
  KEY sev_zzzzsys_object_id (sev_zzzzsys_object_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zzzzsys_php (
  zzzzsys_php_id varchar(25) NOT NULL PRIMARY KEY, sph_code varchar(300) DEFAULT NULL, sph_description varchar(300) DEFAULT NULL,
  sph_group varchar(100) DEFAULT NULL, sph_category varchar(100) DEFAULT NULL, sph_status varchar(1) DEFAULT NULL, sph_php longtext,
  sph_javascript mediumtext, sph_javascript_active varchar(1) DEFAULT NULL, sph_run varchar(20) DEFAULT NULL,
  sph_zzzzsys_form_id varchar(25) DEFAULT NULL, sph_system varchar(1) DEFAULT NULL, sph_global varchar(1) DEFAULT NULL,
  sph_demo varchar(1) DEFAULT NULL, sph_template varchar(1) DEFAULT NULL, sph_hide varchar(1) DEFAULT NULL, sph_json longtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zzzzsys_object (
  zzzzsys_object_id varchar(25) NOT NULL PRIMARY KEY,
  sob_all_zzzzsys_form_id varchar(25) DEFAULT NULL, sob_all_table varchar(300) DEFAULT NULL, sob_all_type varchar(20) DEFAULT NULL,
  sob_all_id varchar(300) DEFAULT NULL, sob_all_label varchar(1000) DEFAULT NULL, sob_all_zzzzsys_tab_id varchar(25) DEFAULT NULL,
  sob_all_order int(11) DEFAULT NULL, sob_all_top int(11) DEFAULT NULL, sob_all_left int(11) DEFAULT NULL, sob_all_width int(11) DEFAULT NULL,
  sob_all_height int(11) DEFAULT NULL, sob_all_cloneable varchar(1) DEFAULT NULL, sob_all_align varchar(10) DEFAULT NULL,
  sob_all_validate varchar(1) DEFAULT NULL, sob_all_access varchar(1) DEFAULT NULL, sob_all_access_condition varchar(1000) DEFAULT NULL,
  sob_all_event varchar(1) DEFAULT NULL, sob_all_style_type varchar(15) DEFAULT NULL, sob_all_style varchar(1000) DEFAULT NULL,
  sob_calc_formula varchar(3000) DEFAULT NULL, sob_calc_format varchar(30) DEFAULT NULL, sob_run_zzzzsys_form_id varchar(50) DEFAULT NULL,
  sob_run_filter varchar(300) DEFAULT NULL, sob_run_method varchar(1) DEFAULT NULL, sob_run_target varchar(1) DEFAULT NULL,
  sob_run_type varchar(1) DEFAULT NULL, sob_run_id varchar(50) DEFAULT NULL, sob_display_sql mediumtext, sob_display_procedure varchar(25) DEFAULT NULL,
  sob_select_multiple varchar(1) DEFAULT NULL, sob_select_2 varchar(1) DEFAULT NULL, sob_select_sql mediumtext, sob_select_procedure varchar(25) DEFAULT NULL,
  sob_lookup_code varchar(50) DEFAULT NULL, sob_lookup_description varchar(300) DEFAULT NULL, sob_lookup_description_width varchar(4) DEFAULT NULL,
  sob_lookup_autocomplete varchar(1) DEFAULT NULL, sob_lookup_zzzzsys_form_id varchar(50) DEFAULT NULL, sob_lookup_javascript mediumtext,
  sob_lookup_php varchar(25) DEFAULT NULL, sob_lookup_table varchar(500) DEFAULT NULL, sob_subform_zzzzsys_form_id varchar(50) DEFAULT NULL,
  sob_subform_foreign_key varchar(100) DEFAULT NULL, sob_subform_add varchar(1) DEFAULT NULL, sob_subform_delete varchar(1) DEFAULT NULL,
  sob_subform_type varchar(10) DEFAULT NULL, sob_subform_table varchar(300) DEFAULT NULL, sob_input_count varchar(15) DEFAULT NULL,
  sob_input_format varchar(30) DEFAULT NULL, sob_input_type varchar(30) DEFAULT NULL, sob_input_icon varchar(50) DEFAULT NULL,
  sob_input_javascript mediumtext, sob_input_datalist mediumtext, sob_input_attribute varchar(1000) DEFAULT NULL,
  sob_input_file_target varchar(1) DEFAULT NULL, sob_html_code longtext, sob_html_chart_type varchar(70) DEFAULT NULL,
  sob_html_javascript varchar(1000) DEFAULT NULL, sob_html_title varchar(70) DEFAULT NULL, sob_html_vertical_label varchar(70) DEFAULT NULL,
  sob_html_horizontal_label varchar(70) DEFAULT NULL, sob_image_zzzzsys_file_id varchar(25) DEFAULT NULL, sob_all_json longtext, sob_json longtext,
  KEY sob_all_zzzzsys_form_id (sob_all_zzzzsys_form_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zzzzsys_format (
  zzzzsys_format_id varchar(25) NOT NULL PRIMARY KEY, srm_type varchar(10) DEFAULT NULL, srm_default varchar(1) DEFAULT NULL,
  srm_format varchar(300) DEFAULT NULL, srm_currency varchar(25) DEFAULT NULL, srm_json mediumtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO zzzzsys_format (zzzzsys_format_id, srm_type, srm_default, srm_format, srm_currency, srm_json) VALUES
('nu59e28f061a779da', 'Number', '0', '$ 1,000.00', '["$",",",".","2"]', NULL),
('nu5d8a9b303b55b6d', 'Date', '0', 'dd-mm-yy', NULL, NULL),
('nu5d8a9b47ac4f5ae', 'Number', '1', '1000.00', '["","",".",2]', NULL),
('nu5fdfc72e7021ccf', 'Date', '1', 'yyyy-mm-dd', NULL, NULL),
('nu62fb20c1f13fd44', 'Date', '0', 'dd.mm.yyyy', NULL, NULL),
('nu687e3ae91ea9386', 'Number', NULL, '1000', '["","","",""]', NULL);

CREATE TABLE IF NOT EXISTS zzzzsys_debug (
  zzzzsys_debug_id varchar(25) NOT NULL PRIMARY KEY, deb_message longtext, deb_flag varchar(50) DEFAULT NULL, deb_added int(11) DEFAULT NULL,
  deb_user_id varchar(25) DEFAULT NULL, deb_json mediumtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zzzzsys_item (
  zzzzsys_item_id varchar(25) NOT NULL PRIMARY KEY, itm_value varchar(1000) DEFAULT NULL, itm_description mediumtext, itm_group varchar(100) DEFAULT NULL,
  itm_category varchar(255) DEFAULT NULL, itm_object_id varchar(25) DEFAULT NULL, itm_sort_order int(11) NOT NULL DEFAULT 0, itm_tag varchar(1000) DEFAULT NULL,
  itm_additional1 text, itm_additional2 varchar(2000) DEFAULT NULL, itm_notes varchar(2000) DEFAULT NULL, itm_css varchar(100) DEFAULT NULL,
  itm_icon varchar(100) DEFAULT NULL, itm_language varchar(25) DEFAULT NULL, itm_parent_id varchar(25) DEFAULT NULL, itm_active varchar(1) DEFAULT '1',
  itm_created_on datetime DEFAULT NULL, itm_updated_on datetime DEFAULT NULL, itm_access_level varchar(2000) DEFAULT NULL, itm_json mediumtext,
  KEY itm_object_id (itm_object_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- reports and SQL builder (nuBuilder's structure, 0.8.183)
CREATE TABLE IF NOT EXISTS zzzzsys_report (
  zzzzsys_report_id varchar(25) NOT NULL PRIMARY KEY, sre_code varchar(300) DEFAULT NULL, sre_description varchar(300) DEFAULT NULL,
  sre_group varchar(100) DEFAULT NULL, sre_zzzzsys_php_id varchar(200) DEFAULT NULL, sre_zzzzsys_form_id varchar(25) DEFAULT NULL,
  sre_layout longtext, sre_json mediumtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zzzzsys_access_report (
  zzzzsys_access_report_id varchar(25) NOT NULL PRIMARY KEY, sre_zzzzsys_access_id varchar(25) DEFAULT NULL,
  sre_zzzzsys_report_id varchar(25) DEFAULT NULL, srp_json mediumtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zzzzsys_select (
  zzzzsys_select_id varchar(25) NOT NULL PRIMARY KEY, sse_code varchar(50) DEFAULT NULL, sse_description varchar(300) DEFAULT NULL,
  sse_json mediumtext, sse_sql mediumtext, sse_edit varchar(1) DEFAULT NULL, sse_system varchar(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zzzzsys_select_clause (
  zzzzsys_select_clause_id varchar(25) NOT NULL PRIMARY KEY, ssc_zzzzsys_select_id varchar(25) DEFAULT NULL, ssc_type varchar(300) DEFAULT NULL,
  ssc_field varchar(500) DEFAULT NULL, ssc_clause varchar(500) DEFAULT NULL, ssc_sort varchar(10) DEFAULT NULL, ssc_order varchar(500) DEFAULT NULL, ssc_json mediumtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
