#!/usr/bin/env python3
"""Generates a nuBuilder 4.9 test application (tables + forms + objects + events + procedures + access level + user)
covering every object type, input type, hook and access option. Output: SQL on stdout."""
import json

out = []
def q(v):
    if v is None: return 'NULL'
    if isinstance(v, int): return str(v)
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"
def ins(table, row):
    cols = ', '.join(row.keys()); vals = ', '.join(q(v) for v in row.values())
    out.append(f"INSERT INTO {table} ({cols}) VALUES ({vals});")

# ---------------------------------------------------------------- data tables
out.append("""
DROP TABLE IF EXISTS tt_country, tt_customer, tt_order, tt_log, tt_nopk;
CREATE TABLE tt_country (tt_country_id VARCHAR(25) PRIMARY KEY, cou_code VARCHAR(10), cou_name VARCHAR(100));
CREATE TABLE tt_customer (
  tt_customer_id VARCHAR(25) PRIMARY KEY, cus_name VARCHAR(100), cus_email VARCHAR(200), cus_birthday DATE,
  cus_amount DECIMAL(12,2), cus_active VARCHAR(1), cus_tt_country_id VARCHAR(25), cus_type VARCHAR(20), cus_tags VARCHAR(500),
  cus_number INT, cus_color VARCHAR(20), cus_autonum INT, cus_scroll VARCHAR(50), cus_textarea TEXT, cus_editor LONGTEXT,
  cus_file LONGTEXT, cus_hidden VARCHAR(50), cus_readonly VARCHAR(50), cus_cond VARCHAR(50), cus_password VARCHAR(100),
  cus_range INT, cus_search VARCHAR(100), cus_time TIME, cus_month VARCHAR(7), cus_url VARCHAR(200), cus_datalist VARCHAR(50),
  cus_icon VARCHAR(50), cus_attr VARCHAR(50), tt_customer_nulog TEXT);
CREATE TABLE tt_order (tt_order_id VARCHAR(25) PRIMARY KEY, ord_tt_customer_id VARCHAR(25), ord_item VARCHAR(100), ord_qty INT,
  ord_price DECIMAL(10,2), ord_date DATE);
CREATE TABLE tt_log (tt_log_id INT AUTO_INCREMENT PRIMARY KEY, log_event VARCHAR(50), log_record VARCHAR(50), log_form VARCHAR(50),
  log_user VARCHAR(50), log_extra TEXT, log_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE tt_nopk (code VARCHAR(10), label VARCHAR(50));
INSERT INTO tt_nopk VALUES ('x','no primary key 1'),('y','no primary key 2');
INSERT INTO tt_country VALUES ('cty_ch','CH','Switzerland'),('cty_de','DE','Germany'),('cty_fr','FR','France'),('cty_it','IT','Italy');
INSERT INTO tt_customer (tt_customer_id,cus_name,cus_email,cus_birthday,cus_amount,cus_active,cus_tt_country_id,cus_type,cus_tags,cus_number,cus_color,cus_autonum,cus_scroll,cus_textarea,cus_hidden,cus_readonly,cus_cond)
 VALUES ('cus_1','Alice Muster','alice@example.com','1990-05-17',1234.5,'1','cty_ch','A','["x","y"]',42,'#ff0000',1001,'Medium','line1\\nline2','hid1','ro1','c1'),
        ('cus_2','Bruno Beispiel','bruno@example.com','1985-12-01',99.95,'0','cty_de','B','["y"]',7,'#00ff00',1002,'Low','','hid2','ro2','c2'),
        ('cus_3','Chloé Exemple','chloe@example.com','2000-01-31',0,'1','cty_fr','A','[]',0,'#0000ff',1003,'High','','hid3','ro3','c3');
INSERT INTO tt_order VALUES ('ord_1','cus_1','Pen',3,2.50,'2026-01-10'),('ord_2','cus_1','Book',1,29.90,'2026-02-11'),('ord_3','cus_2','Desk',1,399.00,'2026-03-12');
""")
for t in ['zzzzsys_form','zzzzsys_tab','zzzzsys_object','zzzzsys_browse','zzzzsys_event','zzzzsys_php','zzzzsys_access','zzzzsys_access_form','zzzzsys_access_php','zzzzsys_access_report','zzzzsys_report','zzzzsys_user','zzzzsys_permission_item','zzzzsys_user_permission']:
    idcol = t + '_id'
    out.append(f"DELETE FROM {t} WHERE {idcol} LIKE 'tt%';")

def form(fid, code, ftype, table, pk, desc, browse_sql='', js='', bjs='', ejs='', style='', breadcrumb='', rows=0, redirect=''):
    ins('zzzzsys_form', dict(zzzzsys_form_id=fid, sfo_type=ftype, sfo_code=code, sfo_description=desc, sfo_group='tt', sfo_status='1',
        sfo_breadcrumb_title=breadcrumb, sfo_table=table, sfo_primary_key=pk, sfo_browse_redirect_form_id=redirect, sfo_browse_row_height=0,
        sfo_browse_rows_per_page=rows, sfo_browse_title_multiline='0', sfo_browse_sql=browse_sql, sfo_javascript=js,
        sfo_browse_javascript=bjs, sfo_edit_javascript=ejs, sfo_style=style))
def tab(tid, fid, title, order, access='0'):
    ins('zzzzsys_tab', dict(zzzzsys_tab_id=tid, syt_zzzzsys_form_id=fid, syt_title=title, syt_order=order, syt_help='', syt_access=access))
def browse(bid, fid, title, display, order, width=150, align='l', fmt=''):
    ins('zzzzsys_browse', dict(zzzzsys_browse_id=bid, sbr_zzzzsys_form_id=fid, sbr_title=title, sbr_display=display, sbr_align=align, sbr_format=fmt, sbr_order=order, sbr_width=width))

OBJ_N = [0]
def obj(fid, table, tab_id, typ, oid, label, top, left, width=200, height=20, **kw):
    OBJ_N[0] += 1
    row = dict(zzzzsys_object_id=f'tto_{oid}', sob_all_zzzzsys_form_id=fid, sob_all_table=table, sob_all_type=typ, sob_all_id=oid,
        sob_all_label=label, sob_all_zzzzsys_tab_id=tab_id, sob_all_order=OBJ_N[0] * 10, sob_all_top=top, sob_all_left=left,
        sob_all_width=width, sob_all_height=height, sob_all_cloneable='1', sob_all_align='left', sob_all_validate='0',
        sob_all_access='0', sob_all_event='0')
    row.update(kw)
    ins('zzzzsys_object', row)
def event(oid, ev, js):
    ins('zzzzsys_event', dict(zzzzsys_event_id=f'tte_{oid}_{ev}', sev_zzzzsys_object_id=f'tto_{oid}', sev_event=ev, sev_javascript=js))
def php(pid, code, desc, src, run='', form_id='', system='0', glob='0', js='', js_active='0'):
    ins('zzzzsys_php', dict(zzzzsys_php_id=pid, sph_code=code, sph_description=desc, sph_group='tt', sph_category='', sph_status='1',
        sph_php=src, sph_javascript=js, sph_javascript_active=js_active, sph_run=run, sph_zzzzsys_form_id=form_id, sph_system=system,
        sph_global=glob, sph_hide='0'))

LOG = lambda ev, extra: f"nuRunQuery(\"INSERT INTO tt_log (log_event, log_record, log_form, log_user, log_extra) VALUES (?,?,?,?,?)\", ['{ev}', '#RECORD_ID#', '#FORM_ID#', '#USER_ID#', {extra}]);"

# ---------------------------------------------------------------- country (lookup target)
form('ttcty', 'ttcty', 'browseedit', 'tt_country', 'tt_country_id', 'TT Countries', 'SELECT * FROM tt_country ORDER BY cou_code')
tab('ttcty_t1', 'ttcty', 'Main', 10)
browse('ttcty_b1', 'ttcty', 'Code', 'cou_code', 10, 80)
browse('ttcty_b2', 'ttcty', 'Name', 'cou_name', 20, 200)
obj('ttcty', 'tt_country', 'ttcty_t1', 'input', 'cou_code', 'Code', 20, 120, 80, sob_input_type='text', sob_all_validate='1')
obj('ttcty', 'tt_country', 'ttcty_t1', 'input', 'cou_name', 'Name', 50, 120, 200, sob_input_type='text')

# ---------------------------------------------------------------- order subform
form('ttord', 'ttord', 'subform', 'tt_order', 'tt_order_id', 'TT Orders', 'SELECT * FROM tt_order')
tab('ttord_t1', 'ttord', 'Main', 10)
obj('ttord', 'tt_order', 'ttord_t1', 'input', 'ord_item', 'Item', 0, 0, 150, sob_input_type='text', sob_all_validate='1')
obj('ttord', 'tt_order', 'ttord_t1', 'input', 'ord_qty', 'Qty', 0, 160, 60, sob_input_type='nuNumber', sob_input_format='N|1000')
obj('ttord', 'tt_order', 'ttord_t1', 'input', 'ord_price', 'Price', 0, 230, 80, sob_input_type='nuNumber', sob_input_format='N|1000.00')
obj('ttord', 'tt_order', 'ttord_t1', 'input', 'ord_date', 'Date', 0, 320, 100, sob_input_type='nuDate', sob_input_format='D|dd.mm.yyyy')
obj('ttord', 'tt_order', 'ttord_t1', 'calc', 'ord_total', 'Total', 0, 430, 80, sob_calc_formula="nuTotal('ord_qty') * nuTotal('ord_price')", sob_calc_format='N|1000.00')

# ---------------------------------------------------------------- customer (main test form)
FORMJS = r"""
window.TT_FORMJS = 'ok:' + nuFormType();
window.TT_HOOKS = window.TT_HOOKS || [];
function nuBeforeSave() { window.TT_HOOKS.push('nuBeforeSave'); return nuGetValue('cus_name') !== 'JSBLOCK'; }
function nuAfterSave() { window.TT_HOOKS.push('nuAfterSave'); }
function nuBeforeDelete() { window.TT_HOOKS.push('nuBeforeDelete'); return true; }
function nuOnTabSelected(tab) { window.TT_HOOKS.push('nuOnTabSelected'); }
function nuSelectBrowse(e, element) { window.TT_HOOKS.push('nuSelectBrowse'); return true; }
function nuOnLookupPopulated(id, row) { window.TT_HOOKS.push('nuOnLookupPopulated:' + id); }
function nuOnSetSaved(saved) { window.TT_HOOKS.push('nuOnSetSaved'); }
"""
BJS = "window.TT_BROWSEJS = 'browse';"
EJS = "window.TT_EDITJS = 'edit:' + nuGetValue('cus_name'); nuSetTitle('TT ' + nuGetValue('cus_name'));"
STYLE = "#cus_name { font-weight: bold; } .ttStyled { color: rgb(1, 2, 3); }"
form('ttcus', 'ttcus', 'browseedit', 'tt_customer', 'tt_customer_id', 'TT Customers',
     "SELECT tt_customer.*, c.cou_name AS country FROM tt_customer LEFT JOIN tt_country c ON c.tt_country_id = cus_tt_country_id ORDER BY cus_name",
     FORMJS, BJS, EJS, STYLE, breadcrumb='#cus_name#|New customer')
tab('ttcus_t1', 'ttcus', 'Main', 10)
tab('ttcus_t2', 'ttcus', 'More', 20)
tab('ttcus_t3', 'ttcus', 'Hidden tab', 30, access='2')
browse('ttcus_b1', 'ttcus', 'Name', 'cus_name', 10, 180)
browse('ttcus_b2', 'ttcus', 'E-mail', 'cus_email', 20, 200)
browse('ttcus_b3', 'ttcus', 'Country', 'c.cou_name', 30, 120)
browse('ttcus_b4', 'ttcus', 'Amount', 'cus_amount', 40, 100, 'r', 'N|1000.00')
browse('ttcus_b5', 'ttcus', 'Birthday', 'cus_birthday', 50, 100, 'l', 'D|dd.mm.yyyy')
browse('ttcus_b6', 'ttcus', 'Active', "IF(cus_active='1','yes','no')", 60, 60)

T, F, TB = 'tt_customer', 'ttcus', 'ttcus_t1'
obj(F, T, TB, 'input', 'cus_name', 'Name', 20, 140, 250, sob_input_type='text', sob_all_validate='1', sob_all_event='1')
event('cus_name', 'onchange', "window.TT_ONCHANGE = nuGetValue('cus_name');")
obj(F, T, TB, 'input', 'cus_email', 'E-mail', 50, 140, 250, sob_input_type='email', sob_all_validate='2')
obj(F, T, TB, 'input', 'cus_birthday', 'Birthday', 80, 140, 110, sob_input_type='nuDate', sob_input_format='D|dd.mm.yyyy')
obj(F, T, TB, 'input', 'cus_amount', 'Amount', 110, 140, 110, sob_input_type='nuNumber', sob_input_format='N|$ 1,000.00', sob_all_align='right')
obj(F, T, TB, 'input', 'cus_active', 'Active', 140, 140, 30, sob_input_type='checkbox')
obj(F, T, TB, 'lookup', 'cus_tt_country_id', 'Country', 170, 140, 60, sob_lookup_zzzzsys_form_id='ttcty', sob_lookup_code='cou_code',
    sob_lookup_description='cou_name', sob_lookup_description_width='150', sob_lookup_table='tt_country', sob_lookup_autocomplete='1',
    sob_lookup_javascript="window.TT_LOOKUPJS = nuGetValue('cus_tt_country_id');")
obj(F, T, TB, 'select', 'cus_type', 'Type', 200, 140, 150, sob_select_sql="A|Type A|B|Type B|C|Type C", sob_select_multiple='0')
obj(F, T, TB, 'select', 'cus_tags', 'Tags', 230, 140, 200, 60, sob_select_sql="SELECT cou_code, cou_name FROM tt_country ORDER BY cou_code", sob_select_multiple='1', sob_select_2='1')
obj(F, T, TB, 'input', 'cus_number', 'Number', 300, 140, 80, sob_input_type='number')
obj(F, T, TB, 'input', 'cus_color', 'Color', 330, 140, 80, sob_input_type='color')
obj(F, T, TB, 'input', 'cus_autonum', 'Auto number', 360, 140, 80, sob_input_type='nuAutoNumber', sob_input_count='2000', sob_input_format='N|1000')
obj(F, T, TB, 'input', 'cus_scroll', 'Scroll', 390, 140, 100, sob_input_type='nuScroll', sob_input_javascript="['Low','Medium','High']")
obj(F, T, TB, 'textarea', 'cus_textarea', 'Textarea', 420, 140, 250, 60)
obj(F, T, TB, 'display', 'tt_display', 'Order count', 20, 520, 80, sob_display_sql="SELECT COUNT(*) FROM tt_order WHERE ord_tt_customer_id = '#RECORD_ID#'")
obj(F, T, TB, 'display', 'tt_display_proc', 'Display proc', 50, 520, 120, sob_display_procedure='tt_dispproc')
obj(F, T, TB, 'word', 'tt_word', 'Hello word object', 80, 520, 150)
obj(F, T, TB, 'html', 'tt_html', 'HTML', 110, 520, 250, 40,
    sob_html_code="<div id='tt_html_inner'>REC=#RECORD_ID#|USER=#USER_ID#|FORM=#FORM_ID#|CODE=#FORM_CODE#</div><script>window.TT_HTMLSCRIPT = 'ran';</script>")
obj(F, T, TB, 'run', 'tt_run_proc', 'Run procedure', 160, 520, 150, 30, sob_run_zzzzsys_form_id='tt_proc', sob_run_type='P', sob_run_method='b', sob_run_target='0')
obj(F, T, TB, 'run', 'tt_run_form', 'Open countries', 200, 520, 150, 30, sob_run_zzzzsys_form_id='ttcty', sob_run_type='F', sob_run_id='-1', sob_run_method='b', sob_run_target='0')
obj(F, T, TB, 'run', 'tt_run_hidden', 'Run hidden', 240, 520, 150, 30, sob_run_zzzzsys_form_id='tt_hidden', sob_run_type='P', sob_run_method='b', sob_run_target='0')
obj(F, T, TB, 'input', 'tt_btn', 'JS Button', 280, 520, 150, 30, sob_input_type='button', sob_all_event='1')
event('tt_btn', 'onclick', "window.TT_CLICK = (window.TT_CLICK || 0) + 1;")
obj(F, T, TB, 'subform', 'tt_sf', 'Orders', 500, 140, 560, 150, sob_subform_zzzzsys_form_id='ttord', sob_subform_foreign_key='ord_tt_customer_id',
    sob_subform_add='1', sob_subform_delete='1', sob_subform_type='g', sob_subform_table='tt_order')
obj(F, T, TB, 'calc', 'tt_calc', 'Orders total', 670, 140, 100, sob_calc_formula="nuTotal('tt_sf.ord_total')", sob_calc_format='N|1000.00')

T2 = 'ttcus_t2'
obj(F, T, T2, 'run', 'tt_iframe', 'Iframe', 20, 140, 400, 150, sob_run_zzzzsys_form_id='ttcty', sob_run_type='F', sob_run_id='', sob_run_method='i', sob_run_target='0')
obj(F, T, T2, 'image', 'tt_image', 'Image', 180, 140, 100, 50, sob_image_zzzzsys_file_id='tt_file1')
obj(F, T, T2, 'contentbox', 'tt_cbox', 'Content box', 240, 130, 300, 100)
obj(F, T, T2, 'editor', 'cus_editor', 'Editor', 360, 140, 400, 150)
obj(F, T, T2, 'input', 'cus_file', 'File', 520, 140, 250, sob_input_type='file', sob_input_file_target='0')
obj(F, T, T2, 'input', 'cus_hidden', 'Hidden (access 2)', 550, 140, 100, sob_input_type='text', sob_all_access='2')
obj(F, T, T2, 'input', 'cus_readonly', 'Readonly (access 1)', 580, 140, 100, sob_input_type='text', sob_all_access='1')
obj(F, T, T2, 'input', 'cus_cond', 'Conditional (access 9)', 610, 140, 100, sob_input_type='text', sob_all_access='9', sob_all_access_condition="SELECT '#USER_ID#' = 'globeadmin'")
obj(F, T, T2, 'input', 'cus_password', 'Password', 640, 140, 100, sob_input_type='password')
obj(F, T, T2, 'input', 'cus_range', 'Range', 670, 140, 100, sob_input_type='range')
obj(F, T, T2, 'input', 'cus_search', 'Search', 700, 140, 100, sob_input_type='search')
obj(F, T, T2, 'input', 'cus_time', 'Time', 730, 140, 100, sob_input_type='time')
obj(F, T, T2, 'input', 'cus_month', 'Month', 760, 140, 100, sob_input_type='month')
obj(F, T, T2, 'input', 'cus_url', 'URL', 790, 140, 200, sob_input_type='url')
obj(F, T, T2, 'input', 'cus_datalist', 'Datalist', 820, 140, 150, sob_input_type='text', sob_input_datalist='["Apple","Banana","Cherry"]')
obj(F, T, T2, 'input', 'cus_icon', 'Input icon', 850, 140, 150, sob_input_type='text', sob_input_icon='fa-solid fa-user')
obj(F, T, T2, 'input', 'cus_attr', 'Attribute', 880, 140, 150, sob_input_type='text', sob_input_attribute='placeholder="tt-placeholder" data-tt="attr-ok"')
obj(F, T, T2, 'input', 'tt_styled', 'Styled', 910, 140, 150, sob_input_type='text', sob_all_style_type='Class', sob_all_style='ttStyled')
obj(F, T, 'ttcus_t3', 'input', 'tt_ontab3', 'On hidden tab', 20, 140, 150, sob_input_type='text')

# ---------------------------------------------------------------- launch form with nuRunPHPHidden + report button
form('ttlaunch', 'ttlaunch', 'launch', '', '', 'TT Launch', '', "window.TT_LAUNCHJS = 'launch:' + nuFormType();")
tab('ttlaunch_t1', 'ttlaunch', 'Main', 10)
obj('ttlaunch', '', 'ttlaunch_t1', 'html', 'tt_launch_html', 'HTML', 20, 20, 300, 30, sob_html_code="<b id='tt_launch_b'>LAUNCH #USER_ID#</b>")
obj('ttlaunch', '', 'ttlaunch_t1', 'run', 'tt_run_report', 'Run report', 60, 20, 150, 30, sob_run_zzzzsys_form_id='tt_report', sob_run_type='R', sob_run_method='b', sob_run_target='0')
obj('ttlaunch', '', 'ttlaunch_t1', 'input', 'tt_from', 'From', 100, 120, 110, sob_input_type='nuDate', sob_input_format='D|dd.mm.yyyy')

# ---------------------------------------------------------------- PHP hooks (form events) and procedures
php('ttcus_BB', 'ttcus_BB', 'Before Browse', LOG('BB', "'#BROWSE_FILTER#'"), form_id='ttcus', system='1')
php('ttcus_BE', 'ttcus_BE', 'Before Edit', LOG('BE', "'#TABLE_ID#|#ACCESS_LEVEL_CODE#|#GLOBAL_ACCESS#|#HOME_ID#|#LANGUAGE#|#LOGIN_NAME#|#USER_NAME#|#nuEditFlag#'") +
    "\nnuAddJavaScript(\"window.TT_BE_JS = 'be-ok';\");\nnuSetProperty('TT_PROP', 'prop-ok');\n" +
    "nuSetJSONData('tt_json', ['a' => 1]);\n", form_id='ttcus', system='1')
php('ttcus_BS', 'ttcus_BS', 'Before Save', LOG('BS', "json_encode(['name' => '#cus_name#', 'rows' => count(nuSubformObject('tt_sf')->rows ?? []), 'tp' => nuGetProperty('TT_PROP')])") +
    "\nif ('#cus_name#' == 'PHPBLOCK') { nuDisplayError('Blocked by BS'); }\n", form_id='ttcus', system='1')
php('ttcus_AS', 'ttcus_AS', 'After Save', LOG('AS', "'#nuFORMdata#' !== '' ? 'has-formdata' : 'no-formdata'") +
    "\nnuSetFormValue('cus_hidden', 'set-by-AS');\n", form_id='ttcus', system='1')
php('ttcus_BD', 'ttcus_BD', 'Before Delete', LOG('BD', "''"), form_id='ttcus', system='1')
php('ttcus_AD', 'ttcus_AD', 'After Delete', LOG('AD', "''"), form_id='ttcus', system='1')
php('tto_cus_tt_country_id_AB', 'tto_cus_tt_country_id_AB', 'Lookup after browse', LOG('AB', "json_encode(nuLookupRecord())"), form_id='ttcus', system='1')
php('tt_proc', 'tt_proc', 'TT procedure (window)', "echo '<div id=\"tt_proc_out\">PROC OK #RECORD_ID# #USER_ID#</div>';\n" + LOG('PROC', "'window'"), run='window')
php('tt_hidden', 'tt_hidden', 'TT hidden procedure', LOG('HIDDEN', "'hidden'") + "\nnuJavaScriptCallback(\"window.TT_HIDDEN = 'hidden-ok';\");", run='hide')
php('tt_dispproc', 'tt_dispproc', 'Display procedure', "nuSetProperty('x', 1); echo 'DISP';\n$_POST['nuRunPHPHiddenResult'] = 'disp-proc-ok';", run='hide')
php('tt_globals', 'tt_globals', 'Global procedures', "", run='hide')
php('tt_nu_before_save', 'nu_before_save', 'Global Before Save', LOG('GLOBAL_BS', "'global'"), run='hide')
php('tt_nu_before_edit', 'nu_before_edit', 'Global Before Edit', LOG('GLOBAL_BE', "'global'"), run='hide')
php('tt_nu_before_delete', 'nu_before_delete', 'Global Before Delete', LOG('GLOBAL_BD', "'global'"), run='hide')
php('tt_nu_startup', 'nu_startup', 'Startup', LOG('STARTUP', "'#LOGIN_NAME#'"), run='hide')
php('tt_nu_invalid_login', 'nu_invalid_login', 'Invalid login', LOG('INVALID_LOGIN', "''"), run='hide')

# ---------------------------------------------------------------- access level + users
ins('zzzzsys_access', dict(zzzzsys_access_id='tt_acc_full', sal_code='TTFULL', sal_description='TT full', sal_group='tt', sal_zzzzsys_form_id='nuuserhome', sal_use_2fa='0'))
ins('zzzzsys_access', dict(zzzzsys_access_id='tt_acc_limited', sal_code='TTLIM', sal_description='TT limited', sal_group='tt', sal_zzzzsys_form_id='nuuserhome', sal_use_2fa='0'))
def af(aid, acc, fid, add='1', save='1', dele='1', clone='1', new='1', prnt='1', dm=None, ft=None):
    ins('zzzzsys_access_form', dict(zzzzsys_access_form_id=aid, slf_zzzzsys_access_id=acc, slf_zzzzsys_form_id=fid, slf_add_button=add,
        slf_save_button=save, slf_delete_button=dele, slf_clone_button=clone, slf_new_button=new, slf_print_button=prnt, slf_data_mode=dm, slf_form_type=ft))
for f in ['ttcus', 'ttcty', 'ttord', 'ttlaunch', 'nuuserhome']:
    af(f'tt_af_full_{f}', 'tt_acc_full', f)
# limited: ttcus -> data mode 0 (no edits of existing records); ttcty -> form type 0 (browse only); delete disabled on ttcus
af('tt_af_lim_ttcus', 'tt_acc_limited', 'ttcus', dele='0', dm='0')
af('tt_af_lim_ttcty', 'tt_acc_limited', 'ttcty', ft='0')
af('tt_af_lim_ttord', 'tt_acc_limited', 'ttord')
af('tt_af_lim_home', 'tt_acc_limited', 'nuuserhome')
ins('zzzzsys_access_php', dict(zzzzsys_access_php_id='tt_ap_full', slp_zzzzsys_access_id='tt_acc_full', slp_zzzzsys_php_id='tt_proc'))
ins('zzzzsys_access_php', dict(zzzzsys_access_php_id='tt_ap_full2', slp_zzzzsys_access_id='tt_acc_full', slp_zzzzsys_php_id='tt_hidden'))
ins('zzzzsys_access_report', dict(zzzzsys_access_report_id='tt_ar_full', sre_zzzzsys_access_id='tt_acc_full', sre_zzzzsys_report_id='tt_report'))
# md5 password (legacy) and bcrypt password users
import hashlib
ins('zzzzsys_user', dict(zzzzsys_user_id='tt_user_full', sus_zzzzsys_access_id='tt_acc_full', sus_language='', sus_name='TT Full User', sus_first_name='Full', sus_last_name='User',
    sus_code='TTF', sus_position='Agent', sus_department='CIC', sus_team='Team A', sus_email='full@example.com', sus_login_name='ttfull',
    sus_login_password=hashlib.md5(b'Secret#123').hexdigest(), sus_change_password='0', sus_accessibility_features='0', sus_permission='["TT_PERM"]'))
ins('zzzzsys_user', dict(zzzzsys_user_id='tt_user_lim', sus_zzzzsys_access_id='tt_acc_limited', sus_language='', sus_name='TT Limited User', sus_first_name='Lim', sus_last_name='User',
    sus_code='TTL', sus_position='Agent', sus_department='CIC', sus_team='Team B', sus_email='lim@example.com', sus_login_name='ttlim',
    sus_login_password=hashlib.md5(b'Secret#123').hexdigest(), sus_change_password='0', sus_accessibility_features='0'))
ins('zzzzsys_user', dict(zzzzsys_user_id='tt_user_exp', sus_zzzzsys_access_id='tt_acc_full', sus_language='', sus_name='TT Expired', sus_login_name='ttexp',
    sus_login_password=hashlib.md5(b'Secret#123').hexdigest(), sus_change_password='0', sus_expires_on='2020-01-01 00:00:00'))
ins('zzzzsys_permission_item', dict(zzzzsys_permission_item_id='tt_perm1', pme_ident='TT_PERM', pme_description='TT permission item', pme_group='tt'))
ins('zzzzsys_user_permission', dict(zzzzsys_user_permission_id='tt_up1', upe_zzzzsys_user_id='tt_user_full', upe_ident='TT_PERM', upe_active='1'))
# image file (reuse nuBuilder logo payload)
out.append("DELETE FROM zzzzsys_file WHERE zzzzsys_file_id='tt_file1';")
out.append("INSERT INTO zzzzsys_file (zzzzsys_file_id, sfi_code, sfi_description, sfi_group, sfi_json, sfi_system) SELECT 'tt_file1','tt_logo','TT logo','tt',sfi_json,'0' FROM zzzzsys_file WHERE zzzzsys_file_id='nu5bad6cb37d02d01';")
# ---------------- reports (nuBuilder JSON layout)
n=[0]
def o(typ, field, left, top, w, **more):
    n[0]+=1
    d={'id':'tt_o%d'%n[0],'name':'tt_o%d'%n[0],'objectType':typ,'fieldName':field,'left':left,'top':top,'width':w,'height':20,'backgroundColor':'#FFFFFF','borderColor':'#000000','borderWidth':'0','fontColor':'#000000','fontFamily':'Helvetica','fontSize':'10','fontWeight':'','format':'','textAlign':'left','minRows':0,'maxRows':0,'zIndex':1}
    d.update(more); return d
def sec(label,h,objs=[],brk=0): return {'id':'','top':0,'height':h,'label':label,'page_break':brk,'margins':0,'color':'#FFFFFF','objects':objs}
def layout(detail, rh, ph, groups, rf):
    g=[{'sortField':'Detail','sortBy':'a','sections':[sec('Detail',24,detail)]},
       {'sortField':'Report','sortBy':'a','sections':[sec('Report Header',60,rh),sec('Report Footer',40,rf)]},
       {'sortField':'Page','sortBy':'a','sections':[sec('Page Header',40,ph),sec('Page Footer',30,[o('label','Page #page# of #pages#',60,5,700,textAlign='right')])]}]
    for f,h,ft in groups: g.append({'sortField':f,'sortBy':'a','sections':[sec(f+' Header',26,h),sec(f+' Footer',26,ft)]})
    while len(g)<8: g.append({'sortField':'','sortBy':'a','sections':[sec(' Header',0),sec(' Footer',0)]})
    return json.dumps({'top':30,'left':30,'bottom':260,'right':30,'width':210,'height':297,'paper':'A4','orientation':'P','ishtml':'N','groups':g})
L1=layout([o('field','cus_name',60,2,250),o('field','cus_amount',320,2,120,format='N|1000.00',textAlign='right'),o('field','cus_birthday',460,2,120,format='D|dd.mm.yyyy')],
          [o('label','TT customer report #USER_ID#',60,10,600,fontSize='16',fontWeight='b')],
          [o('label','Name',60,10,250,fontWeight='b'),o('label','Amount',320,10,120,fontWeight='b')],
          [('cus_type',[o('field','cus_type',60,2,300,fontWeight='b')],[o('field','SUM(cus_amount)',320,2,120,format='N|1000.00',textAlign='right')])],
          [o('field','SUM(cus_amount)',320,5,120,format='N|1000.00',textAlign='right')])
ins('zzzzsys_report', dict(zzzzsys_report_id='tt_report', sre_code='tt_report', sre_description='TT report (table)', sre_group='tt', sre_zzzzsys_php_id='TABLE:tt_customer', sre_zzzzsys_form_id='ttlaunch', sre_layout=L1))
L2=layout([o('field','cus_name',60,2,250),o('field','cnt',320,2,120)],[o('label','TT procedure report',60,10,600,fontSize='16')],[],[],[])
ins('zzzzsys_report', dict(zzzzsys_report_id='tt_report_proc', sre_code='tt_report_proc', sre_description='TT report (procedure)', sre_group='tt', sre_zzzzsys_php_id='PROCEDURE:tt_rpt_proc', sre_zzzzsys_form_id='ttlaunch', sre_layout=L2))
php('tt_rpt_proc', 'tt_rpt_proc', 'Report data procedure', 'nuRunQuery("CREATE TABLE #TABLE_ID# SELECT cus_name, (SELECT COUNT(*) FROM tt_order WHERE ord_tt_customer_id = tt_customer_id) AS cnt FROM tt_customer");', run='hide')
ins('zzzzsys_access_report', dict(zzzzsys_access_report_id='tt_ar_full2', sre_zzzzsys_access_id='tt_acc_full', sre_zzzzsys_report_id='tt_report_proc'))

print('\n'.join(out))
