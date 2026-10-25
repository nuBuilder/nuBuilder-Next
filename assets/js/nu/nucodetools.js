/* nuBuilder Next – toolbar of the code editors (CodeMirror 5): undo / redo, search, replace, toggle comment, show invisibles, format.
 * Used by the form runtime (double-click editor), the form builder and the procedure page. No inline scripts (CSP).
 * 0.8.476: a click on the editor's label (label[for=<textarea id>], or opt.label; without one: a clipboard button in the toolbar) opens
 * Copy / Paste / Paste (replace existing).
 *
 *   NuCodeTools.attach(cm, host, {lang: 'PHP'|'SQL'|'JS'|'HTML'|'CSS', db: 'mysql'|'sqlsrv', readOnly: bool, compact: bool,
 *                                 fullscreen: function () → bool  (own full screen, e.g. a modal; default: toolbar + editor fill the window),
 *                                 noFull: true (no full-screen button – the editor fills the window already)})
 *   → the toolbar element; toolbar.nuDetach() removes it again (keys, listeners) – e.g. before attaching for another file
 *   NuCodeTools.format(code, lang, db) → Promise<string>
 *
 * Formatters (vendor/bundled/formatter, loaded on first use) – options as nuBuilder's nuace.php:
 *   JS / HTML / CSS: js-beautify · SQL: sql-formatter (MySQL or T-SQL) · PHP: Prettier + @prettier/plugin-php
 * Hash variables (#RECORD_ID# …) are protected while formatting: "#" starts a comment in PHP and MySQL.
 */
(function () {
	'use strict';

	var script = document.currentScript && document.currentScript.src || '';
	var BASE = script.replace(/assets\/js\/nu\/nucodetools\.js.*$/, '');
	var FMT = BASE + 'vendor/bundled/formatter/';
	var LIBS = {
		JS: ['beautify.min.js'], CSS: ['beautify-css.js'], HTML: ['beautify.min.js', 'beautify-css.js', 'beautify-html.min.js'],
		SQL: ['sql-formatter.min.js'], PHP: ['prettier-standalone.js', 'prettier-plugin-php.js']
	};
	var LANG = (document.documentElement.lang || 'en').toLowerCase().slice(0, 2);
	var TEXTS = {
		en: {undo: 'Undo (Ctrl+Z)', redo: 'Redo (Ctrl+Y)', find: 'Search (Ctrl+F)', replace: 'Replace (Ctrl+H)', comment: 'Toggle comment (Ctrl+/)',
			invisibles: 'Show spaces and tabs', full: 'Full screen (F11)', fullExit: 'Exit full screen (F11 / Esc)', format: 'Format code (Shift+Alt+F)', formatErr: 'Cannot format: ', loadErr: 'The formatter could not be loaded.',
			copy: 'Copy', paste: 'Paste', pasteAll: 'Paste (replace existing)', copied: 'Copied', pasteHere: 'Paste the text here (Ctrl+V or long press), then Insert:', insert: 'Insert', clip: 'Copy / paste', clipErr: 'The clipboard could not be used.'},
		de: {undo: 'Rückgängig (Strg+Z)', redo: 'Wiederholen (Strg+Y)', find: 'Suchen (Strg+F)', replace: 'Ersetzen (Strg+H)', comment: 'Zeilen aus-/einkommentieren (Strg+/)',
			invisibles: 'Leerzeichen und Tabs anzeigen', full: 'Vollbild (F11)', fullExit: 'Vollbild beenden (F11 / Esc)', format: 'Code formatieren (Umschalt+Alt+F)', formatErr: 'Formatieren nicht möglich: ', loadErr: 'Formatierer konnte nicht geladen werden.',
			copy: 'Kopieren', paste: 'Einfügen', pasteAll: 'Einfügen (Bestehendes ersetzen)', copied: 'Kopiert', pasteHere: 'Text hier einfügen (Strg+V oder lange tippen), dann Einfügen:', insert: 'Einfügen', clip: 'Kopieren / Einfügen', clipErr: 'Die Zwischenablage konnte nicht verwendet werden.'},
		fr: {undo: 'Annuler (Ctrl+Z)', redo: 'Rétablir (Ctrl+Y)', find: 'Rechercher (Ctrl+F)', replace: 'Remplacer (Ctrl+H)', comment: 'Commenter / décommenter (Ctrl+/)',
			invisibles: 'Afficher espaces et tabulations', full: 'Plein écran (F11)', fullExit: 'Quitter le plein écran (F11 / Échap)', format: 'Formater le code (Maj+Alt+F)', formatErr: 'Formatage impossible : ', loadErr: 'Le formateur n\'a pas pu être chargé.',
			copy: 'Copier', paste: 'Coller', pasteAll: 'Coller (remplacer l\'existant)', copied: 'Copié', pasteHere: 'Collez le texte ici (Ctrl+V ou appui long), puis Insérer :', insert: 'Insérer', clip: 'Copier / coller', clipErr: 'Le presse-papiers n\'a pas pu être utilisé.'},
		it: {undo: 'Annulla (Ctrl+Z)', redo: 'Ripeti (Ctrl+Y)', find: 'Cerca (Ctrl+F)', replace: 'Sostituisci (Ctrl+H)', comment: 'Commenta / decommenta (Ctrl+/)',
			invisibles: 'Mostra spazi e tabulazioni', full: 'Schermo intero (F11)', fullExit: 'Esci da schermo intero (F11 / Esc)', format: 'Formatta codice (Maiusc+Alt+F)', formatErr: 'Impossibile formattare: ', loadErr: 'Impossibile caricare il formattatore.',
			copy: 'Copia', paste: 'Incolla', pasteAll: 'Incolla (sostituisci esistente)', copied: 'Copiato', pasteHere: 'Incolla qui il testo (Ctrl+V o pressione prolungata), poi Inserisci:', insert: 'Inserisci', clip: 'Copia / incolla', clipErr: 'Impossibile usare gli appunti.'},
		es: {undo: 'Deshacer (Ctrl+Z)', redo: 'Rehacer (Ctrl+Y)', find: 'Buscar (Ctrl+F)', replace: 'Reemplazar (Ctrl+H)', comment: 'Comentar / descomentar (Ctrl+/)',
			invisibles: 'Mostrar espacios y tabulaciones', full: 'Pantalla completa (F11)', fullExit: 'Salir de pantalla completa (F11 / Esc)', format: 'Formatear código (Mayús+Alt+F)', formatErr: 'No se puede formatear: ', loadErr: 'No se pudo cargar el formateador.',
			copy: 'Copiar', paste: 'Pegar', pasteAll: 'Pegar (reemplazar lo existente)', copied: 'Copiado', pasteHere: 'Pega el texto aquí (Ctrl+V o pulsación larga) y luego Insertar:', insert: 'Insertar', clip: 'Copiar / pegar', clipErr: 'No se pudo usar el portapapeles.'},
		pt: {undo: 'Anular (Ctrl+Z)', redo: 'Refazer (Ctrl+Y)', find: 'Pesquisar (Ctrl+F)', replace: 'Substituir (Ctrl+H)', comment: 'Comentar / descomentar (Ctrl+/)',
			invisibles: 'Mostrar espaços e tabulações', full: 'Ecrã inteiro (F11)', fullExit: 'Sair do ecrã inteiro (F11 / Esc)', format: 'Formatar código (Shift+Alt+F)', formatErr: 'Não é possível formatar: ', loadErr: 'Não foi possível carregar o formatador.',
			copy: 'Copiar', paste: 'Colar', pasteAll: 'Colar (substituir o existente)', copied: 'Copiado', pasteHere: 'Cole o texto aqui (Ctrl+V ou toque longo) e depois Inserir:', insert: 'Inserir', clip: 'Copiar / colar', clipErr: 'Não foi possível usar a área de transferência.'},
		ru: {undo: 'Отменить (Ctrl+Z)', redo: 'Повторить (Ctrl+Y)', find: 'Найти (Ctrl+F)', replace: 'Заменить (Ctrl+H)', comment: 'Закомментировать / раскомментировать (Ctrl+/)',
			invisibles: 'Показывать пробелы и табуляции', full: 'Во весь экран (F11)', fullExit: 'Выйти из полноэкранного режима (F11 / Esc)', format: 'Форматировать код (Shift+Alt+F)', formatErr: 'Не удалось отформатировать: ', loadErr: 'Не удалось загрузить форматировщик.',
			copy: 'Копировать', paste: 'Вставить', pasteAll: 'Вставить (заменить существующее)', copied: 'Скопировано', pasteHere: 'Вставьте текст сюда (Ctrl+V или долгое нажатие), затем «Вставить»:', insert: 'Вставить', clip: 'Копировать / вставить', clipErr: 'Не удалось использовать буфер обмена.'},
		zh: {undo: '撤销（Ctrl+Z）', redo: '重做（Ctrl+Y）', find: '查找（Ctrl+F）', replace: '替换（Ctrl+H）', comment: '切换注释（Ctrl+/）',
			invisibles: '显示空格和制表符', full: '全屏（F11）', fullExit: '退出全屏（F11 / Esc）', format: '格式化代码（Shift+Alt+F）', formatErr: '无法格式化：', loadErr: '无法加载格式化工具。',
			copy: '复制', paste: '粘贴', pasteAll: '粘贴（替换现有内容）', copied: '已复制', pasteHere: '在此粘贴文本（Ctrl+V 或长按），然后点击插入：', insert: '插入', clip: '复制 / 粘贴', clipErr: '无法使用剪贴板。'},
		nl: {undo: 'Ongedaan maken (Ctrl+Z)', redo: 'Opnieuw (Ctrl+Y)', find: 'Zoeken (Ctrl+F)', replace: 'Vervangen (Ctrl+H)', comment: 'Commentaar aan/uit (Ctrl+/)',
			invisibles: 'Spaties en tabs tonen', full: 'Volledig scherm (F11)', fullExit: 'Volledig scherm sluiten (F11 / Esc)', format: 'Code opmaken (Shift+Alt+F)', formatErr: 'Opmaken niet mogelijk: ', loadErr: 'De opmaker kon niet worden geladen.',
			copy: 'Kopiëren', paste: 'Plakken', pasteAll: 'Plakken (bestaande vervangen)', copied: 'Gekopieerd', pasteHere: 'Plak de tekst hier (Ctrl+V of lang drukken), dan Invoegen:', insert: 'Invoegen', clip: 'Kopiëren / plakken', clipErr: 'Het klembord kon niet worden gebruikt.'},
		pl: {undo: 'Cofnij (Ctrl+Z)', redo: 'Ponów (Ctrl+Y)', find: 'Szukaj (Ctrl+F)', replace: 'Zamień (Ctrl+H)', comment: 'Przełącz komentarz (Ctrl+/)',
			invisibles: 'Pokaż spacje i tabulatory', full: 'Pełny ekran (F11)', fullExit: 'Zamknij pełny ekran (F11 / Esc)', format: 'Formatuj kod (Shift+Alt+F)', formatErr: 'Nie można sformatować: ', loadErr: 'Nie udało się załadować formatera.',
			copy: 'Kopiuj', paste: 'Wklej', pasteAll: 'Wklej (zastąp istniejące)', copied: 'Skopiowano', pasteHere: 'Wklej tutaj tekst (Ctrl+V lub długie naciśnięcie), potem Wstaw:', insert: 'Wstaw', clip: 'Kopiuj / wklej', clipErr: 'Nie udało się użyć schowka.'},
		ar: {undo: 'تراجع (Ctrl+Z)', redo: 'إعادة (Ctrl+Y)', find: 'بحث (Ctrl+F)', replace: 'استبدال (Ctrl+H)', comment: 'تبديل التعليق (Ctrl+/)',
			invisibles: 'إظهار المسافات وعلامات الجدولة', full: 'ملء الشاشة (F11)', fullExit: 'إنهاء ملء الشاشة (F11 / Esc)', format: 'تنسيق الشيفرة (Shift+Alt+F)', formatErr: 'تعذّر التنسيق: ', loadErr: 'تعذّر تحميل أداة التنسيق.',
			copy: 'نسخ', paste: 'لصق', pasteAll: 'لصق (استبدال الموجود)', copied: 'تم النسخ', pasteHere: 'الصق النص هنا (Ctrl+V أو ضغط مطوّل) ثم إدراج:', insert: 'إدراج', clip: 'نسخ / لصق', clipErr: 'تعذّر استخدام الحافظة.'}
	};
	var T = TEXTS[LANG] || TEXTS.en;
	// phrases of CodeMirror's search dialogs (English: CodeMirror's own)
	var PHRASE_SETS = {
		de: ['Suchen:', 'Ersetzen:', 'Durch:', 'Ersetzen?', 'Ja', 'Nein', 'Alle', 'Stopp', 'Alle ersetzen:', 'Gehe zu Zeile:', '(/re/ für reguläre Ausdrücke)', '(Zeile:Spalte oder Prozent%)'],
		fr: ['Rechercher :', 'Remplacer :', 'Par :', 'Remplacer ?', 'Oui', 'Non', 'Tous', 'Arrêter', 'Tout remplacer :', 'Aller à la ligne :', '(syntaxe /re/ pour les expressions régulières)', '(ligne:colonne ou pourcentage%)'],
		it: ['Cerca:', 'Sostituisci:', 'Con:', 'Sostituire?', 'Sì', 'No', 'Tutti', 'Ferma', 'Sostituisci tutto:', 'Vai alla riga:', '(sintassi /re/ per le espressioni regolari)', '(riga:colonna o percentuale%)'],
		es: ['Buscar:', 'Reemplazar:', 'Por:', '¿Reemplazar?', 'Sí', 'No', 'Todos', 'Parar', 'Reemplazar todo:', 'Ir a la línea:', '(sintaxis /re/ para expresiones regulares)', '(línea:columna o porcentaje%)'],
		pt: ['Pesquisar:', 'Substituir:', 'Por:', 'Substituir?', 'Sim', 'Não', 'Todos', 'Parar', 'Substituir tudo:', 'Ir para a linha:', '(sintaxe /re/ para expressões regulares)', '(linha:coluna ou percentagem%)'],
		ru: ['Найти:', 'Заменить:', 'На:', 'Заменить?', 'Да', 'Нет', 'Все', 'Стоп', 'Заменить все:', 'Перейти к строке:', '(синтаксис /re/ для регулярных выражений)', '(строка:столбец или процент%)'],
		zh: ['查找：', '替换：', '替换为：', '替换？', '是', '否', '全部', '停止', '全部替换：', '跳到行：', '（使用 /re/ 语法进行正则搜索）', '（使用 行:列 或 百分比% 语法）'],
		nl: ['Zoeken:', 'Vervangen:', 'Door:', 'Vervangen?', 'Ja', 'Nee', 'Alle', 'Stop', 'Alles vervangen:', 'Ga naar regel:', '(/re/-syntaxis voor reguliere expressies)', '(regel:kolom of percentage%)'],
		pl: ['Szukaj:', 'Zamień:', 'Na:', 'Zamienić?', 'Tak', 'Nie', 'Wszystkie', 'Stop', 'Zamień wszystko:', 'Przejdź do wiersza:', '(składnia /re/ dla wyrażeń regularnych)', '(wiersz:kolumna lub procent%)'],
		ar: ['بحث:', 'استبدال:', 'بـ:', 'استبدال؟', 'نعم', 'لا', 'الكل', 'إيقاف', 'استبدال الكل:', 'الانتقال إلى السطر:', '(استخدم صيغة /re/ للتعابير النمطية)', '(سطر:عمود أو نسبة%)']
	};
	var PHRASE_KEYS = ['Search:', 'Replace:', 'With:', 'Replace?', 'Yes', 'No', 'All', 'Stop', 'Replace all:', 'Jump to line:', '(Use /re/ syntax for regexp search)', '(Use line:column or scroll% syntax)'];
	var PHRASES = null;
	if (PHRASE_SETS[LANG]) { PHRASES = {}; PHRASE_KEYS.forEach(function (k, i) { PHRASES[k] = PHRASE_SETS[LANG][i]; }); }

	var loaded = {};
	function load(file) {
		if (!loaded[file]) {
			loaded[file] = new Promise(function (resolve, reject) {
				var s = document.createElement('script');
				s.src = FMT + file;
				s.onload = resolve;
				s.onerror = function () { delete loaded[file]; reject(new Error(T.loadErr)); };
				document.head.appendChild(s);
			});
		}
		return loaded[file];
	}
	function loadAll(lang) {
		return (LIBS[lang] || []).reduce(function (p, f) { return p.then(function () { return load(f); }); }, Promise.resolve());
	}

	/** #NAME# → placeholders that every language treats as a plain word, and back. */
	function protect(code) {
		var map = [];
		var out = code.replace(/#([A-Za-z_][A-Za-z0-9_]*)#/g, function (m) { map.push(m); return '__nuHash' + (map.length - 1) + '__'; });
		return {code: out, restore: function (s) { return s.replace(/__nuHash(\d+)__/g, function (m, i) { return map[+i] !== undefined ? map[+i] : m; }); }};
	}

	// nuBuilder's nuace.php options
	var BEAUTIFY = {indent_size: 4, indent_char: ' ', indent_with_tabs: false, indent_level: 0, eol: '\n', preserve_newlines: true, max_preserve_newlines: 10,
		space_in_paren: false, space_in_empty_paren: false, space_before_conditional: true, space_after_anon_function: false, jslint_happy: false,
		brace_style: 'preserve-inline', break_chained_methods: false, keep_array_indentation: true, keep_function_indentation: false, unescape_strings: false,
		wrap_line_length: 0, wrap_attributes: 'auto', wrap_attributes_indent_size: 4, end_with_newline: false, e4x: false, comma_first: false};

	function format(code, lang, db) {
		lang = String(lang || '').toUpperCase();
		if (lang === 'JAVASCRIPT') lang = 'JS';
		if (!LIBS[lang]) return Promise.reject(new Error('–'));
		return loadAll(lang).then(function () {
			var p = protect(code);
			var out;
			if (lang === 'JS') out = window.js_beautify(p.code, BEAUTIFY);
			else if (lang === 'CSS') out = window.css_beautify(p.code, BEAUTIFY);
			else if (lang === 'HTML') out = window.html_beautify(p.code, BEAUTIFY);
			else if (lang === 'SQL') {
				out = window.sqlFormatter.format(p.code, {language: db === 'sqlsrv' ? 'transactsql' : 'mysql', tabWidth: 2, keywordCase: 'upper', dataTypeCase: 'upper',
					functionCase: 'lower', identifierCase: 'preserve', logicalOperatorNewline: 'before', expressionWidth: 50, linesBetweenQueries: 2,
					denseOperators: false, newlineBeforeSemicolon: false});
			} else {
				// nuBuilder stores procedure / event code without "<?php"
				var open = /^\s*<\?php/i.test(p.code);
				return window.prettier.format(open ? p.code : '<?php\n' + p.code, {parser: 'php', plugins: [window.prettierPlugins.php], phpVersion: '8.1',
					tabWidth: 4, useTabs: false, printWidth: 120, singleQuote: true, trailingCommaPHP: false, braceStyle: '1tbs'})
					.then(function (s) { return p.restore(open ? s : s.replace(/^\s*<\?php\s*\n?/i, '')).replace(/\s+$/, '') + '\n'; });
			}
			return p.restore(out);
		});
	}

	function applyFormat(cm, lang, db, btn) {
		var code = cm.getValue();
		if (code.trim() === '') return Promise.resolve();
		if (btn) btn.disabled = true;
		return format(code, lang, db).then(function (out) {
			out = out.replace(/\n$/, code.slice(-1) === '\n' ? '\n' : '');
			if (out !== code) {
				var cur = cm.getCursor();
				cm.operation(function () {
					cm.replaceRange(out, {line: 0, ch: 0}, {line: cm.lastLine(), ch: cm.getLine(cm.lastLine()).length}, '+format');
					cm.setCursor({line: Math.min(cur.line, cm.lastLine()), ch: 0});
				});
			}
		}).catch(function (e) {
			var msg = String(e && e.message || e).split('\n')[0];
			if (window.nuToast) window.nuToast(T.formatErr + msg, 'danger'); else window.alert(T.formatErr + msg);
		}).then(function () { if (btn) btn.disabled = false; });
	}

	// show spaces and tabs
	var INVIS = {token: function (stream) {
		if (stream.eat('\t')) return 'nu-tab';
		if (stream.eat(' ')) return 'nu-space';
		stream.match(/^[^ \t]+/);
		return null;
	}};

	function button(icon, title, cls) {
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'btn btn-sm btn-outline-secondary ' + (cls || '');
		b.title = title;
		b.setAttribute('aria-label', title);
		var i = document.createElement('i');
		i.className = 'bi ' + icon;
		b.appendChild(i);
		return b;
	}

	// ------------------------------------------------------------------ Copy / Paste / Paste (replace existing) (0.8.476)
	// The editor's label opens the menu (a code editor without a label gets a clipboard button in the toolbar). Copy = the whole code,
	// Paste = at the cursor (replaces a selection), Paste (replace existing) = the whole code – Undo brings it back. The system
	// clipboard needs HTTPS (and may ask once); otherwise Copy uses execCommand and Paste shows a box to paste into by hand.
	var openMenu = null;
	function closeMenu() { if (openMenu) { openMenu.remove(); openMenu = null; } }
	document.addEventListener('mousedown', function (e) { if (openMenu && !openMenu.contains(e.target) && !(e.target.closest && e.target.closest('[data-nu-clip]'))) closeMenu(); });
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && openMenu) { var t = openMenu.nuTrigger; closeMenu(); if (t) t.focus(); } });
	window.addEventListener('resize', closeMenu);

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
		return new Promise(function (ok, no) {
			var ta = document.createElement('textarea');
			ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
			document.body.appendChild(ta); ta.select();
			var done = false;
			try { done = document.execCommand('copy'); } catch (e) { done = false; }
			ta.remove();
			if (done) ok(); else no(new Error('copy'));
		});
	}

	function clipMenu(cm, trigger, ro) {
		trigger.setAttribute('data-nu-clip', '1');
		trigger.setAttribute('aria-haspopup', 'menu');
		trigger.setAttribute('aria-expanded', 'false');
		if (trigger.tagName === 'LABEL') { trigger.setAttribute('role', 'button'); trigger.tabIndex = 0; trigger.classList.add('nuClipLabel'); trigger.title = T.clip; }
		function put(text, all) {
			if (ro) return;
			if (all) cm.setValue(text); else cm.replaceSelection(text);
			cm.focus();
		}
		function flash(msg, bad) {
			var f = document.createElement('span');
			f.className = 'nuClipFlash badge ' + (bad ? 'text-bg-danger' : 'text-bg-success');
			f.textContent = msg;
			trigger.after(f);
			setTimeout(function () { f.remove(); }, 1600);
		}
		function manual(menu, all) {                             // no clipboard access: paste by hand into a box
			menu.textContent = '';
			var lab = document.createElement('div'); lab.className = 'small text-body-secondary mb-1'; lab.textContent = T.pasteHere;
			var box = document.createElement('textarea'); box.className = 'form-control form-control-sm font-monospace mb-2'; box.rows = 5; box.setAttribute('aria-label', T.paste);
			var go = document.createElement('button'); go.type = 'button'; go.className = 'btn btn-sm btn-primary'; go.textContent = T.insert;
			go.addEventListener('click', function () { var v = box.value; closeMenu(); if (v !== '') put(v, all); });
			menu.append(lab, box, go);
			menu.classList.add('nuClipManual');
			if (menu.nuPlace) menu.nuPlace();
			box.focus();
		}
		function item(icon, text, cls, fn, disabled) {
			var b = document.createElement('button');
			b.type = 'button'; b.className = 'dropdown-item ' + cls; b.setAttribute('role', 'menuitem');
			if (disabled) b.disabled = true;
			var i = document.createElement('i'); i.className = 'bi ' + icon + ' me-2'; i.setAttribute('aria-hidden', 'true');
			b.append(i, document.createTextNode(text));
			b.addEventListener('click', fn);
			return b;
		}
		function open() {
			if (openMenu && openMenu.nuTrigger === trigger) { closeMenu(); return; }
			closeMenu();
			var menu = document.createElement('div');
			menu.className = 'dropdown-menu show nuClipMenu shadow-sm';
			menu.setAttribute('role', 'menu');
			menu.nuTrigger = trigger;
			var paste = function (all) {
				return function () {
					if (navigator.clipboard && navigator.clipboard.readText && window.isSecureContext) {
						navigator.clipboard.readText().then(function (t) { closeMenu(); put(t, all); }, function () { manual(menu, all); });
					} else manual(menu, all);
				};
			};
			menu.append(
				item('bi-copy', T.copy, 'nuClipCopy', function () { closeMenu(); copyText(cm.getValue()).then(function () { flash(T.copied); }, function () { flash(T.clipErr, true); }); }),
				item('bi-clipboard', T.paste, 'nuClipPaste', paste(false), ro),
				item('bi-clipboard2-check', T.pasteAll, 'nuClipPasteAll', paste(true), ro));
			document.body.appendChild(menu);
			menu.style.position = 'fixed';
			menu.style.zIndex = 1090;
			menu.nuPlace = function () {                        // below the label, above it when there is no room below
				var r = trigger.getBoundingClientRect(), h = menu.offsetHeight, mw = menu.offsetWidth;
				var top = r.bottom + 4 + h <= window.innerHeight - 8 ? r.bottom + 4 : Math.max(8, r.top - h - 4);
				menu.style.top = top + 'px';
				menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - mw - 8)) + 'px';
			};
			menu.nuPlace();
			openMenu = menu;
			trigger.setAttribute('aria-expanded', 'true');
			var obs = new MutationObserver(function () { if (!menu.isConnected) { trigger.setAttribute('aria-expanded', 'false'); obs.disconnect(); } });
			obs.observe(document.body, {childList: true});
			var first = menu.querySelector('button:not([disabled])'); if (first) first.focus();
		}
		trigger.addEventListener('click', function (e) { e.preventDefault(); open(); });
		trigger.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
	}

	function attach(cm, host, opt) {
		opt = opt || {};
		if (!cm || !host) return null;
		var lang = String(opt.lang || '').toUpperCase();
		if (lang === 'JAVASCRIPT') lang = 'JS';
		var ro = !!opt.readOnly;
		if (PHRASES) cm.setOption('phrases', PHRASES);
		var g = document.createElement('div');
		g.className = 'btn-group nuCodeTools';
		g.setAttribute('role', 'toolbar');
		var bUndo = button('bi-arrow-counterclockwise', T.undo, 'nuCtUndo'), bRedo = button('bi-arrow-clockwise', T.redo, 'nuCtRedo');
		var bFind = button('bi-search', T.find, 'nuCtFind'), bRepl = button('bi-arrow-left-right', T.replace, 'nuCtReplace');
		var bComm = button('bi-chat-square-text', T.comment, 'nuCtComment'), bInv = button('bi-paragraph', T.invisibles, 'nuCtInvisibles');
		var bFmt = button('bi-magic', T.format, 'nuCtFormat');
		var bFull = button('bi-arrows-fullscreen', T.full, 'nuCtFull');
		var items = (ro ? [bFind, bInv] : [bUndo, bRedo, bFind, bRepl, bComm, bInv].concat(LIBS[lang] ? [bFmt] : [])).concat(opt.noFull ? [] : [bFull]);
		// Copy / Paste menu: on the editor's label, or – without one – a clipboard button in the toolbar (0.8.476)
		var ta = cm.getTextArea ? cm.getTextArea() : null;
		var label = opt.label || (ta && ta.id ? document.querySelector('label[for="' + (window.CSS && CSS.escape ? CSS.escape(ta.id) : ta.id) + '"]') : null);
		var bClip = label ? null : button('bi-clipboard', T.clip, 'nuCtClip');
		if (bClip) items.splice(opt.noFull ? items.length : items.length - 1, 0, bClip);
		items.forEach(function (b) { g.appendChild(b); });
		host.appendChild(g);
		clipMenu(cm, label || bClip, ro);

		var hist = function () { var h = cm.historySize(); bUndo.disabled = !h.undo; bRedo.disabled = !h.redo; };
		hist();
		cm.on('change', hist);
		bUndo.addEventListener('click', function () { cm.undo(); cm.focus(); });
		bRedo.addEventListener('click', function () { cm.redo(); cm.focus(); });
		bFind.addEventListener('click', function () { cm.focus(); if (cm.execCommand) cm.execCommand('find'); });
		bRepl.addEventListener('click', function () { cm.focus(); if (cm.execCommand) cm.execCommand('replace'); });
		bComm.addEventListener('click', function () { if (cm.toggleComment) cm.toggleComment(); cm.focus(); });
		var on = false;
		bInv.addEventListener('click', function () {
			on = !on;
			if (on) cm.addOverlay(INVIS); else cm.removeOverlay(INVIS);
			cm.getWrapperElement().classList.toggle('nuShowInvisibles', on);
			bInv.classList.toggle('active', on);
			bInv.setAttribute('aria-pressed', on ? 'true' : 'false');
		});
		bFmt.addEventListener('click', function () { applyFormat(cm, lang, opt.db, bFmt).then(function () { cm.focus(); }); });

		// full screen: the toolbar and the editor (their common parent) fill the window; a modal brings its own (opt.fullscreen)
		var full = false, box = null, savedH = '';
		function commonParent(a, b) { var p = a; while (p && !p.contains(b)) p = p.parentElement; return p; }
		function fit() {
			if (!full || !box) return;
			var w = cm.getWrapperElement(), top = w.getBoundingClientRect().top - box.getBoundingClientRect().top;
			cm.setSize(null, Math.max(120, box.clientHeight - top - 12));
		}
		function setFull(on) {
			if (on === full) return;
			if (typeof opt.fullscreen === 'function') {
				full = !!opt.fullscreen(on);
			} else {
				box = box || commonParent(host, cm.getWrapperElement());
				if (!box) return;
				full = on;
				if (on) savedH = cm.getWrapperElement().style.height;
				box.classList.toggle('nuCodeFull', on);
				document.documentElement.classList.toggle('nuCodeFullOpen', on);
				if (on) fit(); else { cm.getWrapperElement().style.height = savedH; cm.refresh(); }
			}
			bFull.querySelector('i').className = 'bi ' + (full ? 'bi-fullscreen-exit' : 'bi-arrows-fullscreen');
			bFull.title = full ? T.fullExit : T.full;
			bFull.setAttribute('aria-label', bFull.title);
			bFull.classList.toggle('active', full);
			bFull.setAttribute('aria-pressed', full ? 'true' : 'false');
			cm.refresh();
			setTimeout(function () { fit(); cm.refresh(); }, 60);       // after the layout settled (gutter height)
			cm.focus();
		}
		bFull.setAttribute('aria-pressed', 'false');
		bFull.addEventListener('click', function () { setFull(!full); });
		window.addEventListener('resize', fit);

		var keys = {F11: function () { setFull(!full); }};
		if (typeof opt.fullscreen !== 'function') keys.Esc = function () {   // a modal keeps Esc for itself
			if (cm.state.dialog || document.querySelector('.CodeMirror-dialog')) return CodeMirror.Pass;
			if (full) setFull(false); else return CodeMirror.Pass;
		};
		if (!ro) {
			keys['Ctrl-/'] = keys['Cmd-/'] = function () { if (cm.toggleComment) cm.toggleComment(); };
			keys['Ctrl-H'] = function () { cm.execCommand('replace'); };
			if (LIBS[lang]) keys['Shift-Alt-F'] = function () { applyFormat(cm, lang, opt.db, bFmt); };
		}
		if (opt.noFull) delete keys.F11;
		cm.addKeyMap(keys);
		g.nuSetFull = setFull;
		g.nuDetach = function () {
			cm.removeKeyMap(keys);
			cm.off('change', hist);
			window.removeEventListener('resize', fit);
			if (on) { cm.removeOverlay(INVIS); cm.getWrapperElement().classList.remove('nuShowInvisibles'); }
			g.remove();
		};
		return g;
	}

	window.NuCodeTools = {attach: attach, format: format};
})();
