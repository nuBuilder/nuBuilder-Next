-- nuBuilder 4.9 → nuBuilder Next: application forms affected by the findings (system forms nu… excluded)
SELECT 'C1' AS finding, f.sfo_code AS form, b.sbr_display AS detail
  FROM zzzzsys_browse b JOIN zzzzsys_form f ON f.zzzzsys_form_id = b.sbr_zzzzsys_form_id
 WHERE f.sfo_code NOT LIKE 'nu%' AND b.sbr_display REGEXP '[A-Za-z_][A-Za-z0-9_]*[.][A-Za-z_]'
UNION ALL
SELECT 'C2', f.sfo_code, b.sbr_display
  FROM zzzzsys_browse b JOIN zzzzsys_form f ON f.zzzzsys_form_id = b.sbr_zzzzsys_form_id
 WHERE f.sfo_code NOT LIKE 'nu%' AND b.sbr_display REGEXP '[[:space:]]AS[[:space:]]'
UNION ALL
SELECT 'C3/C4 check', sfo_code, LEFT(REPLACE(REPLACE(sfo_browse_sql, '\r', ' '), '\n', ' '), 150)
  FROM zzzzsys_form
 WHERE sfo_code NOT LIKE 'nu%' AND IFNULL(sfo_browse_sql, '') <> ''
   AND (sfo_browse_sql NOT REGEXP '^[[:space:]]*SELECT[[:space:]]+[*]' OR sfo_browse_sql REGEXP '[[:space:]]JOIN[[:space:]]')
UNION ALL
SELECT 'C5', sfo_code, 'nu_filter_match in browse SQL'
  FROM zzzzsys_form WHERE sfo_code NOT LIKE 'nu%' AND sfo_browse_sql LIKE '%nu\_filter\_match%'
UNION ALL
SELECT 'C6', f.sfo_code, CONCAT(o.sob_all_id, ': ', IFNULL(o.sob_all_access_condition, ''))
  FROM zzzzsys_object o JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id
 WHERE f.sfo_code NOT LIKE 'nu%' AND o.sob_all_access = '9'
UNION ALL
SELECT 'M1', f.sfo_code, o.sob_all_id
  FROM zzzzsys_object o JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id
 WHERE f.sfo_code NOT LIKE 'nu%' AND o.sob_all_type = 'html' AND o.sob_html_code LIKE '%<script%'
UNION ALL
SELECT 'M4', f.sfo_code, CONCAT(o.sob_all_id, ' → ', o.sob_select_procedure)
  FROM zzzzsys_object o JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id
 WHERE f.sfo_code NOT LIKE 'nu%' AND IFNULL(o.sob_select_procedure, '') <> ''
UNION ALL
SELECT 'M5', f.sfo_code, o.sob_all_id
  FROM zzzzsys_object o JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id
 WHERE f.sfo_code NOT LIKE 'nu%' AND o.sob_input_type = 'file' AND o.sob_input_file_target = '1'
UNION ALL
SELECT CASE WHEN js LIKE '%nuAddBrowseTitleSelect%' THEN 'M2' WHEN js LIKE '%multiSelect%' THEN 'M3' WHEN js LIKE '%nuAfterSave%' THEN 'M7'
            WHEN js REGEXP 'nuFORM[.](getProperty|setProperty|subform|data|calc|scrollList|getTables|breadcrumbs|tableSchema)' THEN 'M8' ELSE 'N1' END,
       src, 'see JavaScript'
  FROM (SELECT sfo_code AS src, CONCAT_WS(' ', sfo_javascript, sfo_browse_javascript, sfo_edit_javascript) AS js FROM zzzzsys_form WHERE sfo_code NOT LIKE 'nu%'
        UNION ALL SELECT CONCAT(f.sfo_code, '.', o.sob_all_id), CONCAT_WS(' ', o.sob_html_code, e.sev_javascript)
          FROM zzzzsys_object o JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id
          LEFT JOIN zzzzsys_event e ON e.sev_zzzzsys_object_id = o.zzzzsys_object_id WHERE f.sfo_code NOT LIKE 'nu%'
        UNION ALL SELECT 'Setup header', set_header FROM zzzzsys_setup) x
 WHERE js REGEXP 'nuAddBrowseTitleSelect|multiSelect|nuAfterSave|nuSERVERRESPONSE|nuFORM[.](getProperty|setProperty|subform|data|calc|scrollList|getTables|breadcrumbs|tableSchema)'
UNION ALL
SELECT 'M9', sph_code, 'report functions in PHP'
  FROM zzzzsys_php WHERE sph_code NOT LIKE 'nu%' AND sph_php REGEXP 'nuRunReport|nuSavePDF|nuBuildTempTable'
ORDER BY 1, 2;
