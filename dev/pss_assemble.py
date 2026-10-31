#!/usr/bin/env python3
"""
Builds the source folder of the PhpSpreadsheet library pack (0.8.755) from GitHub: PhpSpreadsheet and the libraries of its
composer.lock (no Composer needed – Packagist is often not reachable), each in its own folder, plus a small PSR-4 autoload.php.

    python3 -I dev/pss_assemble.py <out folder> [PhpSpreadsheet tag, default 5.10.0]
    php dev/libpack.php <packs folder> ../nunext-signing.key phpspreadsheet=<out folder>

<out>/autoload.php · VERSION · README.txt · LICENSES/<package>.txt · PhpSpreadsheet/ · Complex/ · Matrix/ · ZipStream/ · Pcre/ · SimpleCache/
"""
import json
import os
import shutil
import subprocess
import sys
import tempfile

# package (composer name) → GitHub repository, folder in the pack, namespace, source folder inside the repository
PARTS = {
    'phpoffice/phpspreadsheet': ('PHPOffice/PhpSpreadsheet', 'PhpSpreadsheet', 'PhpOffice\\PhpSpreadsheet\\', 'src/PhpSpreadsheet'),
    'markbaker/complex': ('MarkBaker/PHPComplex', 'Complex', 'Complex\\', 'classes/src'),
    'markbaker/matrix': ('MarkBaker/PHPMatrix', 'Matrix', 'Matrix\\', 'classes/src'),
    'maennchen/zipstream-php': ('maennchen/ZipStream-PHP', 'ZipStream', 'ZipStream\\', 'src'),
    'composer/pcre': ('composer/pcre', 'Pcre', 'Composer\\Pcre\\', 'src'),
    'psr/simple-cache': ('php-fig/simple-cache', 'SimpleCache', 'Psr\\SimpleCache\\', 'src'),
}

AUTOLOAD = r"""<?php
// PhpSpreadsheet library pack of nuBuilder Next: PSR-4 autoloader for the folders of this pack (made by dev/pss_assemble.py).
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
	static $map = %MAP%;
	foreach ($map as $prefix => $dir) {
		if (strncmp($class, $prefix, strlen($prefix)) !== 0) continue;
		$file = __DIR__ . '/' . $dir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) require $file;
		return;
	}
});
"""


def git(*a):
    subprocess.run(['git', *a], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def clone(repo, tag, dest):
    for t in (tag, 'v' + tag):
        try:
            git('clone', '-q', '--depth', '1', '--branch', t, f'https://github.com/{repo}.git', dest)
            return
        except subprocess.CalledProcessError:
            shutil.rmtree(dest, ignore_errors=True)
    sys.exit(f'{repo} {tag}: tag not found')


def main():
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    out = os.path.abspath(sys.argv[1])
    tag = sys.argv[2] if len(sys.argv) > 2 else '5.10.0'
    tmp = tempfile.mkdtemp(prefix='pss-')
    try:
        main_dir = os.path.join(tmp, 'phpspreadsheet')
        clone(PARTS['phpoffice/phpspreadsheet'][0], tag, main_dir)
        lock = json.load(open(os.path.join(main_dir, 'composer.lock'), encoding='utf-8'))
        versions = {'phpoffice/phpspreadsheet': tag}
        for p in lock['packages']:
            if p['name'] not in PARTS:
                sys.exit(f"composer.lock needs {p['name']} – add it to PARTS")
            versions[p['name']] = p['version'].lstrip('v')
        if os.path.exists(out):
            shutil.rmtree(out)
        os.makedirs(os.path.join(out, 'LICENSES'))
        amap = {}
        readme = [f'PhpSpreadsheet {tag} for nuBuilder Next (library pack), assembled from GitHub by dev/pss_assemble.py:', '']
        for name, (repo, folder, ns, src) in PARTS.items():
            if name not in versions:
                continue
            d = main_dir if name == 'phpoffice/phpspreadsheet' else os.path.join(tmp, folder)
            if d != main_dir:
                clone(repo, versions[name], d)
            shutil.copytree(os.path.join(d, src), os.path.join(out, folder))
            for lic in ('LICENSE', 'LICENSE.md', 'LICENSE.txt', 'license.md'):
                if os.path.exists(os.path.join(d, lic)):
                    shutil.copy(os.path.join(d, lic), os.path.join(out, 'LICENSES', name.replace('/', '-') + '.txt'))
                    break
            amap[ns] = folder
            readme.append(f'  {name} {versions[name]}  →  {folder}/  ({ns})')
        php_map = '[' + ', '.join("'" + k.replace('\\', '\\\\') + "' => '" + v + "'" for k, v in amap.items()) + ']'
        open(os.path.join(out, 'autoload.php'), 'w', encoding='utf-8').write(AUTOLOAD.replace('%MAP%', php_map))
        open(os.path.join(out, 'VERSION'), 'w').write(tag + '\n')
        readme += ['', 'Needs PHP 8.2+ and the extensions ctype, dom, fileinfo, filter, gd, iconv, libxml, mbstring, simplexml, xml,',
                   'xmlreader, xmlwriter, zip, zlib. Licences: LICENSES/ (MIT).']
        open(os.path.join(out, 'README.txt'), 'w', encoding='utf-8').write('\n'.join(readme) + '\n')
        print('\n'.join(readme))
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == '__main__':
    main()
