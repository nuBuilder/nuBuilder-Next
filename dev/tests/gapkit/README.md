# Gap analysis test application (nuBuilder 4.9 vs nuBuilder Next)

Fixture of the owner's gap analysis of 0.8.458 (October 2026): a nuBuilder 4.9 application with the prefix `tt` written straight
into nuBuilder's tables – 4 data tables, forms ttcus (every object and input type), ttcty, ttord (subform with calc), ttlaunch,
form hooks and global hook procedures that log into `tt_log`, reports, access levels TTFULL / TTLIM (data mode 0 on ttcus,
form type 0 = browse only on ttcty) and users ttfull / ttlim / ttexp (password `Secret#123`).

- `testapp.sql` (written by `gen_testapp.py`) + `t08_fixture.sql` (events onnuload / onenter, access 9 object): import in this order;
  both start by removing their own rows, so importing again is safe.
- `inventory.sql`: queries that find, in an application, the features the analysis found broken (B3–B7) – filter `NOT LIKE 'nu%'`.
- Used by dev/tests/e2e.js (tests gap_*), which import the fixture and remove it again (ttInstall / ttRemove).
