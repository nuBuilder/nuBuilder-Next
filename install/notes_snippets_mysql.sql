-- Notes and code snippets (nuBuilder's forms nunotes, nunotescategroy, nucodesnippet).
-- Only for a database WITHOUT nuBuilder: a nuBuilder 4 database already contains these tables and forms.
-- Run after schema_mysql.sql and schema_mysql_forms.sql.

CREATE TABLE IF NOT EXISTS zzzzsys_note (
  zzzzsys_note_id varchar(25) NOT NULL PRIMARY KEY, not_title varchar(60) NOT NULL, not_content mediumtext, not_text longtext,
  not_updated_on datetime NULL DEFAULT CURRENT_TIMESTAMP, not_zzzzsys_note_category_id varchar(25) DEFAULT NULL, not_json mediumtext,
  KEY not_zzzzsys_note_category_id (not_zzzzsys_note_category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zzzzsys_note_category (
  zzzzsys_note_category_id varchar(25) NOT NULL PRIMARY KEY, noc_name varchar(1000) DEFAULT NULL, hoc_json mediumtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zzzzsys_code_snippet (
  zzzzsys_code_snippet_id varchar(25) NOT NULL PRIMARY KEY, cot_code varchar(50) DEFAULT NULL, cot_description text, cot_group varchar(80) DEFAULT NULL,
  cot_url varchar(500) DEFAULT NULL, cot_source_code text, cot_language varchar(20) DEFAULT NULL, cot_scope varchar(50) DEFAULT NULL,
  cot_template varchar(1) DEFAULT NULL, cot_updated_on timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, cot_json mediumtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO zzzzsys_form (zzzzsys_form_id, sfo_type, sfo_code, sfo_description, sfo_group, sfo_status, sfo_breadcrumb_title, sfo_table, sfo_primary_key, sfo_browse_redirect_form_id, sfo_browse_target, sfo_browse_row_height, sfo_browse_rows_per_page, sfo_browse_title_multiline, sfo_browse_autoresize_columns, sfo_browse_sql, sfo_javascript, sfo_browse_javascript, sfo_edit_javascript, sfo_style, sfo_mobile_view, sfo_json) VALUES
('nunotes', 'browseedit', 'nunotes', 'Notes', 'nubuilder', '1', '#not_title#|New', 'zzzzsys_note', 'zzzzsys_note_id', '', NULL, 0, 0, '0', NULL, 'SELECT\n    zzzzsys_note.*, noc_name\nFROM\n    zzzzsys_note\nLEFT JOIN zzzzsys_note_category ON not_zzzzsys_note_category_id = zzzzsys_note_category.zzzzsys_note_category_id\nORDER BY\n    not_title ASC', NULL, NULL, 'nuHide(\'label_not_title\');\nnuSetPlaceholder(\'not_title\', nuTranslate(\'Title\'));\n\nnuHide(\'label_not_zzzzsys_note_category_id\');\nnuSetPlaceholder(\'not_zzzzsys_note_category_idcode\', nuTranslate(\'Category\'));\n\nfunction nuNotesHandleEnterKey() {\n\n    $(\'.ql-editor\').trigger(\"focus\");\n\n    let tinyB = tinyMCE.get(\'not_content_container\').getBody();\n    if (tinyB !== null) tinyB.focus();\n\n\n}\n\n$(\'#not_title\').nuOnEnterKey(nuNotesHandleEnterKey, true);\nnuTabAdditionalSetMarker();\n\n\nfunction nuTinyMCEOnInit(e, editor, id) {\n\n    if (nuUseMobileView()) {\n        $(function() {\n            const maxWidth = $(\'#\' + id)?.attr(\'data-nu-mobile-max-width\')?.nuJustNumbers();\n            const maxHeight = $(\'#\' + id)?.attr(\'data-nu-mobile-max-height\')?.nuJustNumbers();\n\n            nuTinyMCESetBounds(\'not_content\', 5, 70, maxWidth, maxHeight);\n            $(\'#not_updated_on\').css(\'top\', \'640px\');\n            $(\'#label_not_updated_on\').css(\'top\', \'620px\');\n        });\n    }\n\n}\n\n\nfunction nuTabAdditionalSetMarker() {\n   nuTabSetMarker(\'nu684e16abd1b5744\', \'not_text\');\n}', '', '1', NULL),
('nunotescategroy', 'browseedit', 'nunotescategory', 'Categories', 'nubuilder', '1', '#noc_name#|New', 'zzzzsys_note_category', 'zzzzsys_note_category_id', '', NULL, 0, 15, '0', NULL, 'SELECT * FROM zzzzsys_note_category\nORDER BY noc_name', 'nuHide(\'nuPrintButton\');', 'if (nuIsIframe()) {\n    var btn = nuAddActionButton(\'runCategories\', \'\', \'nuForm(\"\' + nuFormId() + \'\", \"\", \"\", \"\", 2);\');\n    btn.attr(\'title\', nuTranslate(\'Edit Categories\'));\n    nuAttachFontAwesome(btn.attr(\'id\'), \'fa-solid fa-list\');\n}', NULL, NULL, '1', NULL),
('nucodesnippet', 'browseedit', 'nucodesnippet', 'Code Snippets', 'nubuilder', '1', '#cot_code#|New', 'zzzzsys_code_snippet', 'zzzzsys_code_snippet_id', '', NULL, 35, 10, '0', NULL, 'SELECT * FROM zzzzsys_code_snippet\nORDER BY cot_group, cot_code', NULL, 'nuBrowseStyleBadge(2); // Group', 'var sc = $(\'#cot_source_code\');\nsc.addClass(\'js\');\n\nsc.css(\'padding\', \'3px 3px 3px 3px\')\n\n// Add ACE event handlers\nsc.on(\'dblclick\', function() {\n    nuOpenAce(nuCodeSnippetGetSourceLangage(), this.id);\n});\n\n// Code Snippets form\nnuSetProperty(\'IS_SETUP_HEADER\', 0);\nnuSetProperty(\'IS_CUSTOM_CODE\', 1);\n\n// Disable nu-records\nif (String(nuRecordId()).startsWith(\'nu\') && !nuDevMode()) {\n    nuDisableAllObjects();\n    $(\'#nuSaveButton\').hide();\n}\n\nfunction nuCodeSnippetGetSourceLangage() {\n\n    let l = $(\'#cot_language\').val();\n    if (l === \'JavaScript\') l = \'JS\';\n    return l === \'\' ? \'JS\' : l;\n\n}\n\nif (nuCodeIsTemplate() && !nuDevMode()) {\n    nuDisableAllObjects();\n    nuMessage(\"Unable to save templates. Please clone them to create a new copy and save the cloned version instead.\", 2500);\n}\n\nfunction nuCodeIsTemplate() {\n    return nuGetValue(\'cot_template\');\n}\n\nfunction nuOnClone() {\n\n    let code = cot_code.value;\n\n    if (nuCodeIsTemplate()) {\n        code = code.substring(0, code.length - 9);\n        nuSetValue(\'cot_code\', code);\n        nuSetValue(\'cot_group\', \'\');\n        nuSetValue(\'cot_template\', false);\n        nuEnableAllObjects();\n        $(\'#nuSaveButton\').show();\n    }\n\n}', NULL, '1', NULL);

INSERT IGNORE INTO zzzzsys_tab (zzzzsys_tab_id, syt_zzzzsys_form_id, syt_title, syt_order, syt_help, syt_access, syt_json) VALUES
('nu5fd29810a60df91', 'nunotes', 'Notes', 10, '', NULL, NULL),
('nu684e16abd1b5744', 'nunotes', 'Additional', 20, NULL, NULL, NULL),
('nu5fd6f697276396f', 'nunotescategroy', 'Main', 10, '', NULL, NULL),
('nu5fdb1b5b254566f', 'nucodesnippet', 'Main', 10, '', NULL, NULL);

INSERT IGNORE INTO zzzzsys_browse (zzzzsys_browse_id, sbr_zzzzsys_form_id, sbr_title, sbr_display, sbr_align, sbr_format, sbr_order, sbr_width, sbr_json) VALUES
('nu5fd29810a628f4f', 'nunotes', 'Notes', 'not_title', 'l', '', 20, 600, NULL),
('nu5fd29810a62a421', 'nunotes', 'Category', 'noc_name', 'l', '', 10, 150, NULL),
('nu5fd3bb4dc0d2841', 'nunotes', 'Updated on', 'not_updated_on', 'l', '', 40, 145, NULL),
('nu684e185947ef47e', 'nunotes', 'Text', 'not_text', 'l', NULL, 30, 300, NULL),
('nu5fd3b22fa460a12', 'nunotescategroy', 'Category', 'noc_name', 'l', '', 10, 400, NULL),
('nu5fdb1b5b3fd1be7', 'nucodesnippet', 'Code', 'cot_code', 'l', '', 20, 250, NULL),
('nu5fdb1b5b407a6f1', 'nucodesnippet', 'Description', 'cot_description', 'l', '', 40, 400, NULL),
('nu5fdb1b5b408a860', 'nucodesnippet', 'Language', 'cot_language', 'l', '', 10, 120, NULL),
('nu5fdcc69ceb26325', 'nucodesnippet', 'Changed On', 'cot_updated_on', 'l', '', 50, 180, NULL),
('nu647b47e3943fc57', 'nucodesnippet', 'Group', 'cot_group', 'l', NULL, 30, 180, NULL);

INSERT IGNORE INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_table, sob_all_type, sob_all_id, sob_all_label, sob_all_zzzzsys_tab_id, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_cloneable, sob_all_align, sob_all_validate, sob_all_access, sob_all_style_type, sob_all_style, sob_input_type, sob_select_sql, sob_lookup_code, sob_lookup_description, sob_lookup_description_width, sob_lookup_zzzzsys_form_id, sob_lookup_table) VALUES
('nu5fd29810a6409bc', 'nunotes', 'zzzzsys_note', 'input', 'not_title', 'Title', 'nu5fd29810a60df91', 10, 26, 26, 585, 22, '0', 'left', '3', '0', 'CSS', 'color: maroon; padding-left: 8; font-weight: bold; font-size: 15px', 'text', NULL, NULL, NULL, NULL, NULL, NULL),
('nu5fd3a61c5abb1b7', 'nunotes', 'zzzzsys_note', 'lookup', 'not_zzzzsys_note_category_id', 'Category', 'nu5fd29810a60df91', 20, 26, 648, 214, 24, '0', 'left', '0', '0', NULL, NULL, NULL, NULL, 'noc_name', 'noc_name', '0', 'nunotescategroy', 'zzzzsys_note_category'),
('nu5fd29810a646742', 'nunotes', 'zzzzsys_note', 'editor', 'not_content', 'Content', 'nu5fd29810a60df91', 30, 65, 26, 1167, 460, '1', 'left', '0', '0', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('nu5fd29810a644544', 'nunotes', 'zzzzsys_note', 'input', 'not_updated_on', 'Updated on', 'nu5fd29810a60df91', 40, 26, 1050, 133, 22, '0', 'left', '0', '1', NULL, NULL, 'text', NULL, NULL, NULL, NULL, NULL, NULL),
('nu684e17844eca02f', 'nunotes', 'zzzzsys_note', 'textarea', 'not_text', 'Text', 'nu684e16abd1b5744', 40, 26, 30, 900, 500, '0', 'left', '0', '0', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('nu5fd3b22f8c32954', 'nunotescategroy', 'zzzzsys_note_category', 'input', 'noc_name', 'Name', 'nu5fd6f697276396f', 10, 38, 101, 150, 22, '1', 'left', '3', '0', NULL, NULL, 'text', NULL, NULL, NULL, NULL, NULL, NULL),
('nu63f124950cabc67', 'nucodesnippet', 'zzzzsys_code_snippet', 'contentbox', 'contentbox', '', 'nu5fdb1b5b254566f', 10, 5, 10, 867, 594, '1', 'left', '0', '0', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('nu5fdb1b5b3b7b123', 'nucodesnippet', 'zzzzsys_code_snippet', 'input', 'cot_code', 'Code', 'nu5fdb1b5b254566f', 20, 38, 165, 354, 22, '1', 'left', '1', '0', NULL, NULL, 'text', NULL, NULL, NULL, NULL, NULL, NULL),
('nu6401f7527bbe462', 'nucodesnippet', 'zzzzsys_code_snippet', 'input', 'cot_group', 'Group', 'nu5fdb1b5b254566f', 30, 72, 165, 354, 21, '1', 'left', '0', '0', NULL, NULL, 'text', NULL, NULL, NULL, NULL, NULL, NULL),
('nu5fdb1f7a0731ba4', 'nucodesnippet', 'zzzzsys_code_snippet', 'textarea', 'cot_description', 'Description', 'nu5fdb1b5b254566f', 40, 106, 165, 659, 40, '1', 'left', '0', '0', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('nu5fdb21b2b2daac4', 'nucodesnippet', 'zzzzsys_code_snippet', 'input', 'cot_url', 'URL', 'nu5fdb1b5b254566f', 50, 166, 165, 663, 22, '1', 'left', '0', '0', NULL, NULL, 'url', NULL, NULL, NULL, NULL, NULL, NULL),
('nu5fdb1b5b3d70508', 'nucodesnippet', 'zzzzsys_code_snippet', 'select', 'cot_language', 'Language', 'nu5fdb1b5b254566f', 60, 200, 165, 110, 22, '1', 'left', '0', '0', NULL, NULL, NULL, '["JavaScript","PHP","SQL","CSS","HTML","Other"]', NULL, NULL, NULL, NULL, NULL),
('nu686741a18ccdc5a', 'nucodesnippet', 'zzzzsys_code_snippet', 'input', 'cot_template', 'Template', 'nu5fdb1b5b254566f', 60, 0, 165, 110, 22, '1', 'left', '0', '2', NULL, NULL, 'checkbox', NULL, NULL, NULL, NULL, NULL, NULL),
('nu5fdb1b5b3c86d2c', 'nucodesnippet', 'zzzzsys_code_snippet', 'textarea', 'cot_source_code', 'Source Code', 'nu5fdb1b5b254566f', 80, 234, 165, 661, 357, '1', 'left', '1', '0', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('nu5fdbcb7f8f7e8a4', 'nucodesnippet', 'zzzzsys_code_snippet', 'input', 'cot_updated_on', 'Updated on', 'nu5fdb1b5b254566f', 90, 38, 700, 128, 22, '1', 'left', '0', '1', NULL, NULL, 'text', NULL, NULL, NULL, NULL, NULL, NULL);
