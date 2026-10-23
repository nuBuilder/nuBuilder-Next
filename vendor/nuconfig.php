<?php
// nuBuilder Next (0.8.409): phpMyAdmin and Tiny File Manager packs live in vendor/packs/<name>-<version>/ and keep nuBuilder's config files,
// which read "../../nuconfig.php" – from there that is this file. It only loads nuBuilder Next's own nuconfig.php (one folder up).
// Never served: the root .htaccess denies nuconfig.php in every folder (nginx: Help → Security → Web server rules).
require_once __DIR__ . '/../nuconfig.php';
