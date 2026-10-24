SELECT a.sal_code, af.slf_zzzzsys_form_id, af.slf_data_mode, af.slf_form_type
FROM zzzzsys_access_form af JOIN zzzzsys_access a ON a.zzzzsys_access_id = af.slf_zzzzsys_access_id
WHERE af.slf_data_mode = '0' OR af.slf_form_type IN ('0','1');
SELECT sob_all_zzzzsys_form_id, sob_all_id, sob_all_access_condition FROM zzzzsys_object WHERE sob_all_access = '9';
SELECT sph_code FROM zzzzsys_php WHERE sph_code IN ('nu_before_save','nu_before_edit','nu_before_delete','nu_startup','nu_invalid_login','nu_check_password_policy','nu_after_update','nu_debug_result_added');
SELECT o.sob_all_zzzzsys_form_id, o.sob_all_id, o.sob_calc_formula FROM zzzzsys_object o JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id WHERE o.sob_all_type = 'calc' AND f.sfo_type = 'subform';
SELECT o.sob_all_zzzzsys_form_id, o.sob_all_id, e.sev_event FROM zzzzsys_event e JOIN zzzzsys_object o ON o.zzzzsys_object_id = e.sev_zzzzsys_object_id WHERE e.sev_event IN ('onnuload','onenter');
SELECT sph_code FROM zzzzsys_php WHERE sph_php REGEXP 'nuFORMdata|nuGetNuDataValue|nuSetNuDataValue|nuRunReport|nuSavePDF|nuBuildTempTable|nuToCSV|nuFromCSV|nuCreateFile|nuGetFile|nuEval|nuGetDBParams|USER_NAME#';
SELECT sfo_code FROM zzzzsys_form WHERE CONCAT_WS(' ', sfo_javascript, sfo_browse_javascript, sfo_edit_javascript, sfo_style) REGEXP 'nuRECORD|nuTabSelected|nuContentBox|input_text|type=.?nu(Date|Number)|nuSetDateFormat|nuShowFile|nuEnableMultiSelect|nuSetBrowseColumnWidth|https?://';
SELECT sob_all_zzzzsys_form_id, sob_all_id FROM zzzzsys_object WHERE sob_html_code REGEXP 'https?://|<iframe';
SELECT LENGTH(set_header), LENGTH(set_style) FROM zzzzsys_setup;
SELECT sob_all_zzzzsys_form_id, sob_all_id FROM zzzzsys_object WHERE IFNULL(sob_select_procedure,'') <> '' OR sob_input_file_target = '1';
