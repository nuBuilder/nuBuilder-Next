UPDATE zzzzsys_setup SET set_header = CONCAT(IFNULL(set_header,''), '\nfunction ttHeaderFn(){ return "hdr"; }\nfunction nuLoadEditGlobal(f, c){ window.TT_LEG = c; }\nfunction nuBeforeSaveGlobal(){ window.TT_BSG = 1; return true; }\n'),
  set_style = CONCAT(IFNULL(set_style,''), '\n.ttGlobalStyle { color: rgb(4, 5, 6) !important; }\n'),
  set_include = '<script>window.TT_INCLUDE = 1;</script>';
DELETE FROM zzzzsys_object WHERE zzzzsys_object_id IN ('tto_tt_html2','tto_tt_noevt');
DELETE FROM zzzzsys_event WHERE zzzzsys_event_id LIKE 'tte8_%';
INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_table, sob_all_type, sob_all_id, sob_all_label, sob_all_zzzzsys_tab_id, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_cloneable, sob_all_align, sob_all_validate, sob_all_access, sob_all_event, sob_html_code)
 VALUES ('tto_tt_html2','ttcus','tt_customer','html','tt_html2','HTML2','ttcus_t1',900,320,520,300,60,'1','left','0','0','0',
 '<button type="button" id="tt_inline_btn" class="ttGlobalStyle" onclick="window.TT_INLINE=(window.TT_INLINE||0)+1">Inline</button><script src="https://cdn.jsdelivr.net/npm/dayjs@1.11.10/dayjs.min.js"></script><iframe id="tt_ext_iframe" src="https://example.com/" width="50" height="20"></iframe>');
INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_table, sob_all_type, sob_all_id, sob_all_label, sob_all_zzzzsys_tab_id, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_cloneable, sob_all_align, sob_all_validate, sob_all_access, sob_all_event, sob_input_type)
 VALUES ('tto_tt_noevt','ttcus','tt_customer','input','tt_noevt','Events off','ttcus_t1',910,390,520,100,20,'1','left','0','0','0','button');
INSERT INTO zzzzsys_event (zzzzsys_event_id, sev_zzzzsys_object_id, sev_event, sev_javascript) VALUES
 ('tte8_1','tto_cus_number','onblur','window.TT_BLUR=1;'),
 ('tte8_2','tto_cus_number','onfocus','window.TT_FOCUS=1;'),
 ('tte8_3','tto_cus_type','onnuload','window.TT_NULOAD=1;'),
 ('tte8_4','tto_cus_search','onenter','window.TT_ENTER=1;'),
 ('tte8_5','tto_tt_sf','afterinsertrow','window.TT_AIR=(window.TT_AIR||0)+1;'),
 ('tte8_6','tto_tt_sf','clickdelete','window.TT_CD=1;'),
 ('tte8_7','tto_cus_email','oninput','window.TT_INPUT=1;'),
 ('tte8_8','tto_tt_noevt','onclick','window.TT_NOEVT=1;'),
 ('tte8_9','tto_cus_name','onkeydown','window.TT_KEYDOWN=1;');
UPDATE zzzzsys_object SET sob_all_event='1' WHERE zzzzsys_object_id IN ('tto_cus_number','tto_cus_type','tto_cus_search','tto_tt_sf','tto_cus_email');
UPDATE zzzzsys_object SET sob_all_style_type='CSS', sob_all_style='color: rgb(7, 8, 9);' WHERE zzzzsys_object_id='tto_tt_word';
UPDATE zzzzsys_object SET sob_all_access='9' WHERE zzzzsys_object_id='tto_cus_cond';
