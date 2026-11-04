/* nuBuilder Next – toolbar of the code editors (CodeMirror 5): undo / redo, search, replace, toggle comment, show invisibles, format.
 * Used by the form runtime (double-click editor), the form builder and the procedure page. No inline scripts (CSP).
 * 0.8.476: a click on the editor's label (label[for=<textarea id>], or opt.label; without one: a clipboard button in the toolbar) opens
 * Copy / Paste / Paste (replace existing).
 *
 *   NuCodeTools.attach(cm, host, {lang: 'PHP'|'SQL'|'JS'|'HTML'|'CSS', db: 'mysql'|'sqlsrv', readOnly: bool, compact: bool,
 *                                 snippets: [[name, description, code], …]  (0.8.593: a second tab of the Insert menu), noInsert: true (no Insert menu),
 *                                 scope: 0–3 | null  (0.8.714: nuBuilder Forte's snippet scope – 0 Forms / Custom Code, 1 Setup / Header, 2 SQL,
 *                                 3 Procedures/PHP; default from lang: SQL 2, PHP 3, else 0; the snippets come from ?p=snippetlist on first use),
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
	// Insert menu and suggestions while typing (0.8.593)
	var TEXTS_INS = {
		"en": {"ins": "Insert function or snippet (Ctrl+Space while typing)", "insLabel": "Insert", "insFns": "Functions", "insSnips": "Snippets", "insSearch": "Search functions…", "insAll": "All groups", "insCall": "Insert call", "insExample": "Insert example", "insHelp": "Help", "insParams": "Parameters", "insReturns": "Returns", "insResult": "Result", "insNone": "Nothing found.", "insNext": "nuBuilder Next only", "insKeys": "Enter: insert call · Shift+Enter: insert example", "insErr": "The function list could not be loaded."}, "de": {"ins": "Funktion oder Snippet einfügen (beim Tippen Strg+Leertaste)", "insLabel": "Einfügen", "insFns": "Funktionen", "insSnips": "Snippets", "insSearch": "Funktionen suchen…", "insAll": "Alle Gruppen", "insCall": "Aufruf einfügen", "insExample": "Beispiel einfügen", "insHelp": "Hilfe", "insParams": "Parameter", "insReturns": "Rückgabe", "insResult": "Ergebnis", "insNone": "Nichts gefunden.", "insNext": "nur nuBuilder Next", "insKeys": "Enter: Aufruf einfügen · Umschalt+Enter: Beispiel einfügen", "insErr": "Die Funktionsliste konnte nicht geladen werden."}, "fr": {"ins": "Insérer une fonction ou un extrait (Ctrl+Espace en tapant)", "insLabel": "Insérer", "insFns": "Fonctions", "insSnips": "Extraits", "insSearch": "Rechercher des fonctions…", "insAll": "Tous les groupes", "insCall": "Insérer l'appel", "insExample": "Insérer l'exemple", "insHelp": "Aide", "insParams": "Paramètres", "insReturns": "Retour", "insResult": "Résultat", "insNone": "Rien trouvé.", "insNext": "nuBuilder Next seulement", "insKeys": "Entrée : insérer l'appel · Maj+Entrée : insérer l'exemple", "insErr": "La liste des fonctions n'a pas pu être chargée."}, "it": {"ins": "Inserisci funzione o snippet (Ctrl+Spazio mentre scrivi)", "insLabel": "Inserisci", "insFns": "Funzioni", "insSnips": "Snippet", "insSearch": "Cerca funzioni…", "insAll": "Tutti i gruppi", "insCall": "Inserisci chiamata", "insExample": "Inserisci esempio", "insHelp": "Guida", "insParams": "Parametri", "insReturns": "Restituisce", "insResult": "Risultato", "insNone": "Nessun risultato.", "insNext": "solo nuBuilder Next", "insKeys": "Invio: inserisci chiamata · Maiusc+Invio: inserisci esempio", "insErr": "Impossibile caricare l'elenco delle funzioni."}, "es": {"ins": "Insertar función o fragmento (Ctrl+Espacio al escribir)", "insLabel": "Insertar", "insFns": "Funciones", "insSnips": "Fragmentos", "insSearch": "Buscar funciones…", "insAll": "Todos los grupos", "insCall": "Insertar llamada", "insExample": "Insertar ejemplo", "insHelp": "Ayuda", "insParams": "Parámetros", "insReturns": "Devuelve", "insResult": "Resultado", "insNone": "Nada encontrado.", "insNext": "solo nuBuilder Next", "insKeys": "Intro: insertar llamada · Mayús+Intro: insertar ejemplo", "insErr": "No se pudo cargar la lista de funciones."}, "pt": {"ins": "Inserir função ou excerto (Ctrl+Espaço ao escrever)", "insLabel": "Inserir", "insFns": "Funções", "insSnips": "Excertos", "insSearch": "Pesquisar funções…", "insAll": "Todos os grupos", "insCall": "Inserir chamada", "insExample": "Inserir exemplo", "insHelp": "Ajuda", "insParams": "Parâmetros", "insReturns": "Devolve", "insResult": "Resultado", "insNone": "Nada encontrado.", "insNext": "só nuBuilder Next", "insKeys": "Enter: inserir chamada · Shift+Enter: inserir exemplo", "insErr": "Não foi possível carregar a lista de funções."}, "nl": {"ins": "Functie of fragment invoegen (Ctrl+Spatie tijdens het typen)", "insLabel": "Invoegen", "insFns": "Functies", "insSnips": "Fragmenten", "insSearch": "Functies zoeken…", "insAll": "Alle groepen", "insCall": "Aanroep invoegen", "insExample": "Voorbeeld invoegen", "insHelp": "Help", "insParams": "Parameters", "insReturns": "Geeft terug", "insResult": "Resultaat", "insNone": "Niets gevonden.", "insNext": "alleen nuBuilder Next", "insKeys": "Enter: aanroep invoegen · Shift+Enter: voorbeeld invoegen", "insErr": "De functielijst kon niet worden geladen."}, "pl": {"ins": "Wstaw funkcję lub fragment (Ctrl+Spacja podczas pisania)", "insLabel": "Wstaw", "insFns": "Funkcje", "insSnips": "Fragmenty", "insSearch": "Szukaj funkcji…", "insAll": "Wszystkie grupy", "insCall": "Wstaw wywołanie", "insExample": "Wstaw przykład", "insHelp": "Pomoc", "insParams": "Parametry", "insReturns": "Zwraca", "insResult": "Wynik", "insNone": "Nic nie znaleziono.", "insNext": "tylko nuBuilder Next", "insKeys": "Enter: wstaw wywołanie · Shift+Enter: wstaw przykład", "insErr": "Nie udało się załadować listy funkcji."}, "ru": {"ins": "Вставить функцию или фрагмент (Ctrl+Пробел при вводе)", "insLabel": "Вставить", "insFns": "Функции", "insSnips": "Фрагменты", "insSearch": "Поиск функций…", "insAll": "Все группы", "insCall": "Вставить вызов", "insExample": "Вставить пример", "insHelp": "Справка", "insParams": "Параметры", "insReturns": "Возвращает", "insResult": "Результат", "insNone": "Ничего не найдено.", "insNext": "только nuBuilder Next", "insKeys": "Enter: вставить вызов · Shift+Enter: вставить пример", "insErr": "Не удалось загрузить список функций."}, "zh": {"ins": "插入函数或代码片段（输入时按 Ctrl+空格）", "insLabel": "插入", "insFns": "函数", "insSnips": "代码片段", "insSearch": "搜索函数…", "insAll": "所有分组", "insCall": "插入调用", "insExample": "插入示例", "insHelp": "帮助", "insParams": "参数", "insReturns": "返回", "insResult": "结果", "insNone": "未找到。", "insNext": "仅 nuBuilder Next", "insKeys": "Enter：插入调用 · Shift+Enter：插入示例", "insErr": "无法加载函数列表。"}, "ar": {"ins": "إدراج دالة أو مقتطف (Ctrl+مسافة أثناء الكتابة)", "insLabel": "إدراج", "insFns": "الدوال", "insSnips": "المقتطفات", "insSearch": "البحث في الدوال…", "insAll": "كل المجموعات", "insCall": "إدراج الاستدعاء", "insExample": "إدراج المثال", "insHelp": "مساعدة", "insParams": "المعاملات", "insReturns": "يُرجع", "insResult": "النتيجة", "insNone": "لم يُعثر على شيء.", "insNext": "nuBuilder Next فقط", "insKeys": "Enter: إدراج الاستدعاء · Shift+Enter: إدراج المثال", "insErr": "تعذّر تحميل قائمة الدوال."}};
	Object.keys(TEXTS_INS).forEach(function (l) { if (TEXTS[l]) Object.keys(TEXTS_INS[l]).forEach(function (k) { TEXTS[l][k] = TEXTS_INS[l][k]; }); });
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
	// CodeMirror 6's search panel: its own keys (the adapter maps Search: / Replace: / All / Jump to line: itself)
	var PHRASE_KEYS6 = ['next', 'previous', 'match case', 'regexp', 'by word', 'replace', 'replace all', 'close'];
	var PHRASE_SETS6 = {
		de: ['weiter', 'zurück', 'Groß-/Kleinschreibung', 'regulärer Ausdruck', 'ganzes Wort', 'ersetzen', 'alle ersetzen', 'schließen'],
		fr: ['suivant', 'précédent', 'respecter la casse', 'expression régulière', 'mot entier', 'remplacer', 'tout remplacer', 'fermer'],
		it: ['successivo', 'precedente', 'maiuscole/minuscole', 'espressione regolare', 'parola intera', 'sostituisci', 'sostituisci tutto', 'chiudi'],
		es: ['siguiente', 'anterior', 'mayúsculas/minúsculas', 'expresión regular', 'palabra completa', 'reemplazar', 'reemplazar todo', 'cerrar'],
		pt: ['seguinte', 'anterior', 'maiúsculas/minúsculas', 'expressão regular', 'palavra inteira', 'substituir', 'substituir tudo', 'fechar'],
		ru: ['далее', 'назад', 'с учётом регистра', 'регулярное выражение', 'слово целиком', 'заменить', 'заменить все', 'закрыть'],
		zh: ['下一个', '上一个', '区分大小写', '正则表达式', '全字匹配', '替换', '全部替换', '关闭'],
		nl: ['volgende', 'vorige', 'hoofdlettergevoelig', 'reguliere expressie', 'heel woord', 'vervangen', 'alles vervangen', 'sluiten'],
		pl: ['następny', 'poprzedni', 'wielkość liter', 'wyrażenie regularne', 'całe słowo', 'zamień', 'zamień wszystko', 'zamknij'],
		ar: ['التالي', 'السابق', 'مطابقة حالة الأحرف', 'تعبير نمطي', 'كلمة كاملة', 'استبدال', 'استبدال الكل', 'إغلاق']
	};
	if (PHRASES && PHRASE_SETS6[LANG]) PHRASE_KEYS6.forEach(function (k, i) { PHRASES[k] = PHRASE_SETS6[LANG][i]; });

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
		var ins = ro || opt.noInsert ? null : insertTools(cm, host, lang, opt);   // Insert menu + suggestions while typing (0.8.593)
		var chk = CHECKS[lang] && !opt.noCheck ? checkTools(cm, g, lang, opt) : null;   // syntax check (0.8.781)

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
			if (ins) ins.detach();
			if (chk) chk.detach();
			g.remove();
		};
		return g;
	}

	// ------------------------------------------------------------------ Insert: functions of the help's reference + snippets (0.8.593)
	// The list comes from ?p=fnlist&lang=php|js (pages/nufnlist.php = docs/nunext-functions.json), loaded on first use. Insert menu
	// (search, group, detail with parameters / returns / example / result, Insert call / Insert example / Help) and suggestions while
	// typing (CodeMirror show-hint: nu… / db_… after two letters, Ctrl+Space for any word).
	var FN = {};
	function fnList(l) {
		if (!FN[l]) {
			FN[l] = fetch(BASE + 'index.php?p=fnlist&lang=' + l, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
				.then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
				.then(function (j) { j.items = (j.items || []).map(function (it) { it.short = shortName(it.n); return it; }); return j; })
				.catch(function (e) { delete FN[l]; throw e; });
		}
		return FN[l];
	}
	/** "$nu->log" → "log", "NuPage.setTitle" → "setTitle", "$.fn.nuHighlight" → "nuHighlight" */
	function shortName(n) { var m = /(?:->|::|\.)([A-Za-z_$][\w$]*)$/.exec(n); return m ? m[1] : n; }
	/** The call from the signature with the required parameters: "nuGetProperty($name)" – optional ones ([…], = default) left out. */
	function callOf(it) {
		var s = it.s, i = s.indexOf('(');
		if (i < 0) return {text: s.replace(/[:\s].*$/, ''), sel: null};
		var depth = 0, j = i;
		for (; j < s.length; j++) { if (s[j] === '(') depth++; else if (s[j] === ')' && --depth === 0) break; }
		var inner = s.slice(i + 1, j), args = [], cur = '', d = 0, opt = false;
		for (var k = 0; k <= inner.length; k++) {
			var c = inner[k];
			if (k === inner.length || (c === ',' && d === 0)) { if (cur.trim()) args.push({t: cur.trim(), opt: opt}); cur = ''; continue; }
			if (c === '[') { opt = true; continue; }
			if (c === ']') continue;
			if (c === '(' || c === '{') d++; else if (c === ')' || c === '}') d--;
			cur += c;
		}
		var req = args.filter(function (a) { return !a.opt && a.t.indexOf('=') < 0 && !/^\.\.\./.test(a.t); }).map(function (a) {
			var v = /(&?\$[A-Za-z_]\w*)/.exec(a.t);                   // PHP: the variable without its type
			return v ? v[1].replace(/^&/, '') : a.t.replace(/\s*:.*$/, '').split(/\s+/).pop();
		});
		var head = s.slice(0, i);
		return {text: head + '(' + req.join(', ') + ')', sel: req.length ? [head.length + 1, head.length + 1 + req[0].length] : [head.length + 1, head.length + 1]};
	}
	/** Inserts at the cursor (or replaces from..to); selects the first parameter. */
	function insertCall(cm, it, from, to) {
		var c = callOf(it), start = from || cm.getCursor('from');
		var text = c.text;
		// typed after "$nu->" / "NuPage.": only the rest
		var before = cm.getRange({line: start.line, ch: 0}, start), pre = it.n.slice(0, it.n.length - it.short.length);
		if (pre && before.slice(-pre.length) === pre && text.indexOf(pre) === 0) { text = text.slice(pre.length); if (c.sel) c.sel = [c.sel[0] - pre.length, c.sel[1] - pre.length]; }
		cm.replaceRange(text, start, to || cm.getCursor('to'), '+insert');
		if (c.sel) {
			var base = cm.indexFromPos(start);
			cm.setSelection(cm.posFromIndex(base + c.sel[0]), cm.posFromIndex(base + c.sel[1]));
		}
		cm.focus();
	}
	function insertText(cm, text) { cm.replaceSelection(text, 'end', '+insert'); cm.focus(); }
	function elt(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined && text !== null) e.textContent = text; return e; }
	/** Detail of an entry: signature, description, parameters, returns, example, result, origin. */
	function detail(it) {
		var box = elt('div', 'nuInsDetail');
		var head = elt('div', 'd-flex align-items-start gap-2 mb-1');
		head.appendChild(elt('code', 'nuInsSig flex-grow-1', it.s));
		if (it.o === 'next') head.appendChild(elt('span', 'badge text-bg-info fw-normal', T.insNext));
		box.appendChild(head);
		if (it.d) box.appendChild(elt('div', 'small mb-1', it.d));
		var p = it.p || {}, keys = Object.keys(p);
		if (keys.length) {
			box.appendChild(elt('div', 'small fw-semibold mt-1', T.insParams));
			var dl = elt('dl', 'nuInsParams small mb-1');
			keys.forEach(function (k) { dl.appendChild(elt('dt', '', k)); dl.appendChild(elt('dd', '', p[k])); });
			box.appendChild(dl);
		}
		if (it.r) { var r = elt('div', 'small mb-1'); r.appendChild(elt('span', 'fw-semibold', T.insReturns + ': ')); r.appendChild(document.createTextNode(it.r)); box.appendChild(r); }
		if (it.e) box.appendChild(elt('pre', 'nuInsCode mb-1', it.e));
		if (it.x) { box.appendChild(elt('div', 'small fw-semibold', T.insResult)); box.appendChild(elt('pre', 'nuInsCode nuInsResult mb-1', it.x)); }
		return box;
	}

	// code snippets of a scope (0.8.714, pages/nusnippetlist.php): loaded once per scope + language and page
	var SN = {}, SCOPE_OF = {SQL: 2, PHP: 3, JS: 0, CSS: 0, HTML: 0};
	function snList(scope, lang) {
		var k = scope + '|' + lang;
		if (!SN[k]) {
			SN[k] = fetch(BASE + 'index.php?p=snippetlist&scope=' + scope + '&lang=' + encodeURIComponent(lang), {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
				.then(function (r) { return r.ok ? r.json() : {items: []}; })
				.catch(function () { delete SN[k]; return {items: []}; });
		}
		return SN[k];
	}

	function insertTools(cm, host, lang, opt) {
		var fl = lang === 'PHP' ? 'php' : (lang === 'JS' || lang === 'HTML' ? 'js' : '');
		var own = (opt.snippets || []).filter(function (x) { return x && x[2]; }), snippets = own.slice();
		var scope = opt.scope !== undefined ? opt.scope : (SCOPE_OF[lang] !== undefined ? SCOPE_OF[lang] : null), snDone = scope === null, manage = null;
		if (!fl && !snippets.length && scope === null) return null;
		var btn = elt('button', 'btn btn-sm btn-outline-secondary nuCtInsert');
		btn.type = 'button'; btn.title = T.ins; btn.setAttribute('aria-label', T.ins); btn.setAttribute('aria-haspopup', 'true'); btn.setAttribute('aria-expanded', 'false');
		var ic = elt('i', 'bi bi-braces-asterisk'); ic.setAttribute('aria-hidden', 'true');
		btn.appendChild(ic); btn.appendChild(elt('span', 'nuCtInsertLabel ms-1', T.insLabel));
		host.appendChild(btn);
		var menu = null, tab = fl ? 'fn' : 'sn', active = 0, shown = [], data = null, group = '';
		function close() { if (!menu) return; menu.remove(); menu = null; btn.setAttribute('aria-expanded', 'false'); document.removeEventListener('pointerdown', outside, true); }
		function outside(e) { if (menu && !menu.contains(e.target) && !btn.contains(e.target)) close(); }
		function place() {
			if (!menu) return;
			var r = btn.getBoundingClientRect(), w = menu.offsetWidth, h = menu.offsetHeight;
			var left = Math.max(4, Math.min(r.left, window.innerWidth - w - 4)), top = r.bottom + 4;
			if (top + h > window.innerHeight - 4) top = r.top - h - 4 > 4 ? r.top - h - 4 : window.innerHeight - h - 4;   // above, else as far up as needed: always fully visible
			menu.style.left = left + 'px'; menu.style.top = Math.max(4, top) + 'px';
		}
		function open() {
			if (menu) { close(); return; }
			if (!snDone) {                                             // the scope's snippets first (one request per page)
				btn.disabled = true;
				snList(scope, lang).then(function (d) {
					snDone = true; btn.disabled = false;
					snippets = own.concat((d.items || []).filter(function (x) { return x && x[2]; }));
					if (d.manage) manage = [d.manage, d.manage_label || ''];
					if (!fl) tab = 'sn';
					open();
				});
				return;
			}
			menu = elt('div', 'dropdown-menu show nuInsMenu p-2'); menu.setAttribute('role', 'dialog'); menu.setAttribute('aria-label', T.ins);
			var tabs = elt('div', 'nav nav-pills nav-fill nuInsTabs mb-2');
			[['fn', T.insFns, !!fl], ['sn', T.insSnips, snippets.length > 0 || !!manage || !fl]].forEach(function (t) {
				if (!t[2]) return;
				var b = elt('button', 'nav-link py-1' + (tab === t[0] ? ' active' : ''), t[1]); b.type = 'button'; b.setAttribute('data-tab', t[0]);
				b.addEventListener('click', function () { tab = t[0]; tabs.querySelectorAll('.nav-link').forEach(function (x) { x.classList.toggle('active', x === b); }); gsel.classList.toggle('d-none', tab !== 'fn'); if (mlink) mlink.classList.toggle('d-none', tab !== 'sn'); active = 0; render(); search.focus(); });
				tabs.appendChild(b);
			});
			if (tabs.children.length > 1) menu.appendChild(tabs);
			var row = elt('div', 'd-flex gap-2 mb-2');
			var search = elt('input', 'form-control form-control-sm'); search.type = 'search'; search.placeholder = T.insSearch; search.setAttribute('aria-label', T.insSearch);
			var gsel = elt('select', 'form-select form-select-sm w-auto nuInsGroup' + (tab !== 'fn' ? ' d-none' : '')); gsel.setAttribute('aria-label', T.insAll);
			gsel.appendChild(new Option(T.insAll, ''));
			row.appendChild(search); row.appendChild(gsel); menu.appendChild(row);
			var list = elt('div', 'nuInsList list-group list-group-flush'); list.setAttribute('role', 'listbox');
			var info = elt('div', 'nuInsInfo border-top mt-2 pt-2');
			var body = elt('div', 'nuInsBody');                        // wide screens: list left, detail right (0.8.594, nuapp.css)
			body.appendChild(list); body.appendChild(info); menu.appendChild(body);
			menu.appendChild(elt('div', 'nuInsKeys small text-body-secondary mt-1', T.insKeys));
			var mlink = null;                                          // "Manage snippets" → nuBuilder Forte's form nucodesnippet (with the right on it)
			if (manage) {
				mlink = elt('a', 'nuInsManage small d-block mt-1' + (tab !== 'sn' ? ' d-none' : ''));
				mlink.href = manage[0]; mlink.target = '_blank'; mlink.rel = 'noopener';
				var mi = elt('i', 'bi bi-box-arrow-up-right me-1'); mi.setAttribute('aria-hidden', 'true');
				mlink.appendChild(mi); mlink.appendChild(document.createTextNode(manage[1]));
				menu.appendChild(mlink);
			}
			document.body.appendChild(menu);
			btn.setAttribute('aria-expanded', 'true');
			document.addEventListener('pointerdown', outside, true);
			function items() {
				var q = search.value.trim().toLowerCase();
				if (tab === 'sn') return snippets.filter(function (x) { return !q || (x[0] + ' ' + (x[1] || '')).toLowerCase().indexOf(q) >= 0; });
				if (!data) return [];
				var a = data.items.filter(function (it) { return (!group || it.g === group) && (!q || (it.n + ' ' + it.s + ' ' + it.d).toLowerCase().indexOf(q) >= 0); });
				if (q) a.sort(function (x, y) {                                                      // names starting with the text first
					var sx = x.short.toLowerCase().indexOf(q) === 0 ? 0 : (x.n.toLowerCase().indexOf(q) >= 0 ? 1 : 2), sy = y.short.toLowerCase().indexOf(q) === 0 ? 0 : (y.n.toLowerCase().indexOf(q) >= 0 ? 1 : 2);
					return sx - sy || x.n.localeCompare(y.n);
				});
				return a;
			}
			function showInfo() {
				info.textContent = '';
				var it = shown[active];
				if (!it) return;
				if (tab === 'sn') { if (it[1]) info.appendChild(elt('div', 'small mb-1', it[1])); info.appendChild(elt('pre', 'nuInsCode mb-1', it[2])); }
				else info.appendChild(detail(it));
				var acts = elt('div', 'd-flex flex-wrap gap-2 mt-1');
				var bCall = elt('button', 'btn btn-sm btn-primary nuInsCall', tab === 'sn' ? T.insert : T.insCall); bCall.type = 'button';
				bCall.addEventListener('click', function () { use(false); });
				acts.appendChild(bCall);
				if (tab === 'fn' && it.e) { var bEx = elt('button', 'btn btn-sm btn-outline-secondary nuInsExample', T.insExample); bEx.type = 'button'; bEx.addEventListener('click', function () { use(true); }); acts.appendChild(bEx); }
				if (tab === 'fn' && it.h) { var a = elt('a', 'btn btn-sm btn-link ms-auto nuInsHelp'); a.href = it.h; a.target = '_blank'; a.rel = 'noopener'; a.appendChild(elt('i', 'bi bi-box-arrow-up-right me-1')); a.appendChild(document.createTextNode(T.insHelp)); acts.appendChild(a); }
				info.appendChild(acts);
				place();
			}
			function render() {
				shown = items();
				if (active >= shown.length) active = Math.max(0, shown.length - 1);
				list.textContent = '';
				shown.slice(0, 200).forEach(function (it, i) {
					var b = elt('button', 'list-group-item list-group-item-action py-1 px-2' + (i === active ? ' active' : '')); b.type = 'button'; b.setAttribute('role', 'option');
					if (tab === 'sn') { b.appendChild(elt('div', 'small fw-semibold', it[0])); if (it[1]) b.appendChild(elt('div', 'small nuInsSub text-truncate', it[1])); }
					else { b.appendChild(elt('div', 'small fw-semibold font-monospace', it.n)); b.appendChild(elt('div', 'small nuInsSub text-truncate', it.d || it.s)); }
					b.addEventListener('click', function () { active = i; mark(); showInfo(); });
					b.addEventListener('dblclick', function () { active = i; use(false); });
					list.appendChild(b);
				});
				if (!shown.length) list.appendChild(elt('div', 'small text-body-secondary p-1', data || tab === 'sn' ? T.insNone : ''));
				showInfo();
			}
			function mark() {
				Array.prototype.forEach.call(list.children, function (c, i) { c.classList.toggle('active', i === active); if (i === active && c.scrollIntoView) c.scrollIntoView({block: 'nearest'}); });
			}
			function use(example) {
				var it = shown[active];
				if (!it) return;
				close();
				if (tab === 'sn') insertText(cm, it[2]);
				else if (example || it.k === 'hook') insertText(cm, it.e || it.s);   // a hook: its example is the function to write
				else insertCall(cm, it);
			}
			search.addEventListener('input', function () { active = 0; render(); });
			gsel.addEventListener('change', function () { group = gsel.value; active = 0; render(); });
			search.addEventListener('keydown', function (e) {
				if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(0, Math.min(shown.length - 1, active + (e.key === 'ArrowDown' ? 1 : -1))); mark(); showInfo(); }
				else if (e.key === 'Enter') { e.preventDefault(); use(e.shiftKey); }
				else if (e.key === 'Escape') { e.preventDefault(); close(); cm.focus(); }
			});
			menu.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); close(); cm.focus(); } });
			render(); place(); search.focus();
			if (fl) fnList(fl).then(function (d) {
				data = d;
				if (!menu) return;
				Object.keys(d.groups || {}).forEach(function (g) { if (d.items.some(function (it) { return it.g === g; })) gsel.appendChild(new Option(d.groups[g], g)); });
				if (tab === 'fn') render();
				place();
			}, function () { if (menu && tab === 'fn') { list.textContent = ''; list.appendChild(elt('div', 'small text-danger p-1', T.insErr)); } });
		}
		btn.addEventListener('click', open);
		window.addEventListener('resize', place);
		window.addEventListener('scroll', place, true);

		// suggestions while typing (CodeMirror show-hint): nu… / db_… after two letters; Ctrl+Space for any word
		var hintOn = !!(fl && cm.showHint), tip = null;
		function tipHide() { if (tip) { tip.remove(); tip = null; } }
		function hint(force) {
			return fnList(fl).then(function (d) {
				var cur = cm.getCursor(), tok = cm.getTokenAt(cur);
				if (/comment|string/.test(tok.type || '') && !force) return;
				var line = cm.getLine(cur.line).slice(0, cur.ch), m = /[A-Za-z_][\w]*$/.exec(line);
				var word = m ? m[0] : '';
				if (!force && !/^(nu|db_)/i.test(word)) return;
				if (!force && word.length < 2) return;
				var w = word.toLowerCase();
				var list = d.items.filter(function (it) { return it.short.toLowerCase().indexOf(w) === 0; });
				if (!list.length) return;
				list.sort(function (a, b) { return a.short.length - b.short.length || a.short.localeCompare(b.short); });
				var from = {line: cur.line, ch: cur.ch - word.length};
				cm.showHint({completeSingle: false, closeCharacters: /[\s()[\]{};:>,]/, hint: function () {
					var res = {from: from, to: cm.getCursor(), list: list.slice(0, 60).map(function (it) {
						return {text: it.n, it: it, hint: function (c, self, data) { insertCall(c, data.it, self.from, self.to); },
							render: function (el, self, data) { el.appendChild(elt('span', 'nuHintName', data.it.n)); el.appendChild(elt('span', 'nuHintSig', ' ' + data.it.s.slice(data.it.n.length))); }};
					})};
					CodeMirror.on(res, 'select', function (c, el) {               // signature + description next to the list
						tipHide();
						var r = el.parentNode.getBoundingClientRect();
						tip = elt('div', 'nuHintTip shadow-sm');
						tip.appendChild(elt('code', 'd-block mb-1', c.it.s));
						if (c.it.d) tip.appendChild(elt('div', '', c.it.d));
						document.body.appendChild(tip);
						var left = r.right + 6;
						if (left + tip.offsetWidth > window.innerWidth - 4) left = Math.max(4, r.left - tip.offsetWidth - 6);
						tip.style.left = left + 'px'; tip.style.top = Math.max(4, Math.min(r.top, window.innerHeight - tip.offsetHeight - 4)) + 'px';
					});
					CodeMirror.on(res, 'close', tipHide);
					return res;
				}});
			}).catch(function () { /* no list: no suggestions */ });
		}
		var onInput = function (c, change) { if (change.origin === '+input' && /[A-Za-z_]$/.test(change.text.join(''))) hint(false); };
		var keys = {'Ctrl-Space': function () { hint(true); }};
		if (hintOn) { cm.on('inputRead', onInput); cm.addKeyMap(keys); }
		return {button: btn, detach: function () {
			close(); tipHide(); btn.remove();
			window.removeEventListener('resize', place); window.removeEventListener('scroll', place, true);
			if (hintOn) { cm.off('inputRead', onInput); cm.removeKeyMap(keys); }
		}};
	}

	// ================================================================ syntax check (0.8.781)
	// JavaScript: Acorn (vendor/bundled/acorn-*, loaded on first use – the strict CSP rules out new Function()); CSS: braces, comments,
	// strings + rules the browser drops; HTML: unclosed <script> / <style>, their contents as JavaScript / CSS; PHP: ?p=codecheck
	// (token_get_all, nothing runs). Nothing is ever executed. Marks in the gutter while typing (after a pause), the ✓ button lists the
	// problems; NuCodeTools.guardForm(form) / confirmProblems() ask before saving code with errors ("Save anyway").
	var TEXTS_CHK = {
		en: {chk: 'Check syntax (Shift+Alt+C)', chkOk: 'No syntax errors found.', chkN: '%d problem(s)', chkLine: 'Line %d', chkTitle: 'Syntax errors',
			chkSave: 'The code has syntax errors. Save anyway?', chkSaveAnyway: 'Save anyway', chkPhpOff: 'The PHP check could not be reached.',
			cssOpen: 'Unclosed {', cssClose: 'Unexpected }', cssComment: 'Unclosed comment', cssString: 'Unclosed string', cssDropped: 'The browser ignores this rule',
			htmlScript: 'Unclosed <script>', htmlStyle: 'Unclosed <style>'},
		de: {chk: 'Syntax prüfen (Umschalt+Alt+C)', chkOk: 'Keine Syntaxfehler gefunden.', chkN: '%d Problem(e)', chkLine: 'Zeile %d', chkTitle: 'Syntaxfehler',
			chkSave: 'Der Code enthält Syntaxfehler. Trotzdem speichern?', chkSaveAnyway: 'Trotzdem speichern', chkPhpOff: 'Die PHP-Prüfung war nicht erreichbar.',
			cssOpen: 'Nicht geschlossene {', cssClose: 'Unerwartete }', cssComment: 'Nicht geschlossener Kommentar', cssString: 'Nicht geschlossene Zeichenkette', cssDropped: 'Der Browser ignoriert diese Regel',
			htmlScript: 'Nicht geschlossenes <script>', htmlStyle: 'Nicht geschlossenes <style>'},
		fr: {chk: 'Vérifier la syntaxe (Maj+Alt+C)', chkOk: 'Aucune erreur de syntaxe.', chkN: '%d problème(s)', chkLine: 'Ligne %d', chkTitle: 'Erreurs de syntaxe',
			chkSave: 'Le code contient des erreurs de syntaxe. Enregistrer quand même ?', chkSaveAnyway: 'Enregistrer quand même', chkPhpOff: 'La vérification PHP n\'a pas pu être jointe.',
			cssOpen: '{ non fermée', cssClose: '} inattendue', cssComment: 'Commentaire non fermé', cssString: 'Chaîne non fermée', cssDropped: 'Le navigateur ignore cette règle',
			htmlScript: '<script> non fermé', htmlStyle: '<style> non fermé'},
		it: {chk: 'Controlla la sintassi (Maiusc+Alt+C)', chkOk: 'Nessun errore di sintassi.', chkN: '%d problema/i', chkLine: 'Riga %d', chkTitle: 'Errori di sintassi',
			chkSave: 'Il codice contiene errori di sintassi. Salvare comunque?', chkSaveAnyway: 'Salva comunque', chkPhpOff: 'Il controllo PHP non è raggiungibile.',
			cssOpen: '{ non chiusa', cssClose: '} inattesa', cssComment: 'Commento non chiuso', cssString: 'Stringa non chiusa', cssDropped: 'Il browser ignora questa regola',
			htmlScript: '<script> non chiuso', htmlStyle: '<style> non chiuso'},
		es: {chk: 'Comprobar la sintaxis (Mayús+Alt+C)', chkOk: 'No se encontraron errores de sintaxis.', chkN: '%d problema(s)', chkLine: 'Línea %d', chkTitle: 'Errores de sintaxis',
			chkSave: 'El código tiene errores de sintaxis. ¿Guardar de todos modos?', chkSaveAnyway: 'Guardar de todos modos', chkPhpOff: 'No se pudo acceder a la comprobación de PHP.',
			cssOpen: '{ sin cerrar', cssClose: '} inesperada', cssComment: 'Comentario sin cerrar', cssString: 'Cadena sin cerrar', cssDropped: 'El navegador ignora esta regla',
			htmlScript: '<script> sin cerrar', htmlStyle: '<style> sin cerrar'},
		pt: {chk: 'Verificar a sintaxe (Shift+Alt+C)', chkOk: 'Nenhum erro de sintaxe encontrado.', chkN: '%d problema(s)', chkLine: 'Linha %d', chkTitle: 'Erros de sintaxe',
			chkSave: 'O código tem erros de sintaxe. Guardar mesmo assim?', chkSaveAnyway: 'Guardar mesmo assim', chkPhpOff: 'Não foi possível contactar a verificação de PHP.',
			cssOpen: '{ não fechada', cssClose: '} inesperada', cssComment: 'Comentário não fechado', cssString: 'Texto não fechado', cssDropped: 'O navegador ignora esta regra',
			htmlScript: '<script> não fechado', htmlStyle: '<style> não fechado'},
		ru: {chk: 'Проверить синтаксис (Shift+Alt+C)', chkOk: 'Синтаксических ошибок нет.', chkN: 'Проблем: %d', chkLine: 'Строка %d', chkTitle: 'Синтаксические ошибки',
			chkSave: 'В коде есть синтаксические ошибки. Всё равно сохранить?', chkSaveAnyway: 'Всё равно сохранить', chkPhpOff: 'Проверка PHP недоступна.',
			cssOpen: 'Незакрытая {', cssClose: 'Лишняя }', cssComment: 'Незакрытый комментарий', cssString: 'Незакрытая строка', cssDropped: 'Браузер игнорирует это правило',
			htmlScript: 'Незакрытый <script>', htmlStyle: 'Незакрытый <style>'},
		zh: {chk: '检查语法（Shift+Alt+C）', chkOk: '未发现语法错误。', chkN: '%d 个问题', chkLine: '第 %d 行', chkTitle: '语法错误',
			chkSave: '代码有语法错误。仍要保存吗？', chkSaveAnyway: '仍然保存', chkPhpOff: '无法连接 PHP 检查。',
			cssOpen: '未闭合的 {', cssClose: '多余的 }', cssComment: '未闭合的注释', cssString: '未闭合的字符串', cssDropped: '浏览器会忽略此规则',
			htmlScript: '未闭合的 <script>', htmlStyle: '未闭合的 <style>'},
		nl: {chk: 'Syntaxis controleren (Shift+Alt+C)', chkOk: 'Geen syntaxisfouten gevonden.', chkN: '%d probleem/problemen', chkLine: 'Regel %d', chkTitle: 'Syntaxisfouten',
			chkSave: 'De code bevat syntaxisfouten. Toch opslaan?', chkSaveAnyway: 'Toch opslaan', chkPhpOff: 'De PHP-controle was niet bereikbaar.',
			cssOpen: 'Niet gesloten {', cssClose: 'Onverwachte }', cssComment: 'Niet gesloten commentaar', cssString: 'Niet gesloten tekenreeks', cssDropped: 'De browser negeert deze regel',
			htmlScript: 'Niet gesloten <script>', htmlStyle: 'Niet gesloten <style>'},
		pl: {chk: 'Sprawdź składnię (Shift+Alt+C)', chkOk: 'Nie znaleziono błędów składni.', chkN: 'Problemy: %d', chkLine: 'Wiersz %d', chkTitle: 'Błędy składni',
			chkSave: 'Kod zawiera błędy składni. Zapisać mimo to?', chkSaveAnyway: 'Zapisz mimo to', chkPhpOff: 'Sprawdzanie PHP jest niedostępne.',
			cssOpen: 'Niezamknięty {', cssClose: 'Nieoczekiwany }', cssComment: 'Niezamknięty komentarz', cssString: 'Niezamknięty ciąg znaków', cssDropped: 'Przeglądarka ignoruje tę regułę',
			htmlScript: 'Niezamknięty <script>', htmlStyle: 'Niezamknięty <style>'},
		ar: {chk: 'التحقق من الصياغة (Shift+Alt+C)', chkOk: 'لا توجد أخطاء صياغة.', chkN: 'المشكلات: %d', chkLine: 'السطر %d', chkTitle: 'أخطاء صياغة',
			chkSave: 'في الكود أخطاء صياغة. هل تريد الحفظ على أي حال؟', chkSaveAnyway: 'احفظ على أي حال', chkPhpOff: 'تعذّر الوصول إلى فحص PHP.',
			cssOpen: '{ غير مغلقة', cssClose: '} غير متوقعة', cssComment: 'تعليق غير مغلق', cssString: 'نص غير مغلق', cssDropped: 'يتجاهل المتصفح هذه القاعدة',
			htmlScript: '<script> غير مغلق', htmlStyle: '<style> غير مغلق'}
	};
	var TC = TEXTS_CHK[LANG] || TEXTS_CHK.en;
	var ACORN = BASE + 'vendor/bundled/acorn-8.19.0/acorn.min.js';
	var CHECKS = {JS: 1, CSS: 1, HTML: 1, PHP: 1};

	function loadAcorn() {
		if (window.acorn) return Promise.resolve();
		if (!loaded[ACORN]) {
			loaded[ACORN] = new Promise(function (resolve, reject) {
				var s = document.createElement('script');
				s.src = ACORN;
				s.onload = resolve;
				s.onerror = function () { delete loaded[ACORN]; reject(new Error('acorn')); };
				document.head.appendChild(s);
			});
		}
		return loaded[ACORN];
	}
	/** #NAME# (nuBuilder's hash variables, replaced before the code runs) → an identifier of the same length, so lines / columns stay. */
	function hashFree(code) { return code.replace(/#([A-Za-z_][A-Za-z0-9_]*)#/g, function (m, n) { return '_' + n + '_'; }); }
	function lineAt(code, i) { var n = 1; for (var k = 0; k < i; k++) if (code.charCodeAt(k) === 10) n++; return n; }

	function checkJs(code, line0, col0) {
		return loadAcorn().then(function () {
			try {
				window.acorn.parse(hashFree(code), {ecmaVersion: 'latest', sourceType: 'script', allowReturnOutsideFunction: true,
					allowAwaitOutsideFunction: true, allowHashBang: true, locations: true});
				return [];
			} catch (e) {
				var loc = e.loc || {line: 1, column: 0};
				return [{line: loc.line + (line0 || 0), col: loc.line === 1 ? loc.column + (col0 || 0) : loc.column, sev: 'error',
					msg: String(e.message || e).replace(/\s*\(\d+:\d+\)\s*$/, '')}];
			}
		});
	}
	function checkCss(code, line0) {
		line0 = line0 || 0;
		var out = [], stack = [], line = 1, i = 0, n = code.length, segStart = 0, depth = 0, segs = [];
		while (i < n) {
			var c = code[i];
			if (c === '\n') { line++; i++; continue; }
			if (c === '/' && code[i + 1] === '*') {
				var end = code.indexOf('*/', i + 2);
				if (end < 0) { out.push({line: line + line0, col: 0, sev: 'error', msg: TC.cssComment}); return out; }
				line += code.slice(i, end).split('\n').length - 1; i = end + 2; continue;
			}
			if (c === '"' || c === "'") {
				var j = i + 1, sl = line;
				while (j < n && code[j] !== c && code[j] !== '\n') { if (code[j] === '\\') j++; j++; }
				if (j >= n || code[j] === '\n') { out.push({line: sl + line0, col: 0, sev: 'error', msg: TC.cssString}); return out; }
				i = j + 1; continue;
			}
			if (c === '{') { stack.push(line); depth++; }
			else if (c === '}') {
				if (!stack.length) { out.push({line: line + line0, col: 0, sev: 'error', msg: TC.cssClose}); return out; }
				stack.pop(); depth--;
				if (depth === 0) { segs.push([segStart, i + 1]); segStart = i + 1; }
			} else if (c === ';' && depth === 0) segStart = i + 1;     // @import / @charset …
			i++;
		}
		if (stack.length) { out.push({line: stack[stack.length - 1] + line0, col: 0, sev: 'error', msg: TC.cssOpen}); return out; }
		// rules the browser throws away (wrong selector …): warnings – a newer browser may know them
		if (window.CSSStyleSheet && 'replaceSync' in CSSStyleSheet.prototype) {
			segs.forEach(function (s) {
				var txt = hashFree(code.slice(s[0], s[1])), lead = txt.replace(/\/\*[\s\S]*?\*\//g, '').trim();
				if (lead === '' || lead.charAt(0) === '@') return;
				try {
					var sh = new CSSStyleSheet();
					sh.replaceSync(txt);
					if (sh.cssRules.length === 0) {
						var at = s[0] + txt.search(/\S/);
						out.push({line: lineAt(code, at) + line0, col: 0, sev: 'warn', msg: TC.cssDropped + ': ' + lead.split('{')[0].trim().slice(0, 60)});
					}
				} catch (e) { /* not checkable here */ }
			});
		}
		return out;
	}
	function checkHtml(code) {
		var jobs = [], out = [], re = /<(script|style)\b([^>]*)>/gi, m;
		while ((m = re.exec(code)) !== null) {
			var tag = m[1].toLowerCase(), attrs = m[2], from = m.index + m[0].length;
			var close = code.slice(from).search(new RegExp('</' + tag + '\\s*>', 'i'));
			var line = lineAt(code, m.index);
			if (close < 0) { out.push({line: line, col: 0, sev: 'error', msg: tag === 'script' ? TC.htmlScript : TC.htmlStyle}); break; }
			var body = code.slice(from, from + close), l0 = lineAt(code, from) - 1, c0 = from - code.lastIndexOf('\n', from - 1) - 1;
			re.lastIndex = from + close;
			if (tag === 'style') { jobs.push(Promise.resolve(checkCss(body, l0))); continue; }
			var type = (/\btype\s*=\s*["']?([^"'\s>]+)/i.exec(attrs) || [])[1] || '';
			if (/\bsrc\s*=/i.test(attrs) || (type !== '' && !/^(text\/javascript|application\/javascript|module)$/i.test(type))) continue;
			jobs.push(checkJs(body, l0, c0));
		}
		return Promise.all(jobs).then(function (r) { r.forEach(function (x) { out = out.concat(x); }); return out; });
	}
	var phpBusy = null;
	function checkPhp(code) {
		var tok = (window.nuNext && window.nuNext.csrf) || '';
		if (!tok) { var el = document.querySelector('[data-csrf]'), inp = document.querySelector('input[name="_csrf"]'); tok = el ? el.getAttribute('data-csrf') : (inp ? inp.value : ''); }
		if (phpBusy) phpBusy.abort();
		phpBusy = window.AbortController ? new AbortController() : null;
		var body = new URLSearchParams({action: 'check', code: code, _csrf: tok});
		return fetch(BASE + 'index.php?p=codecheck', {method: 'POST', credentials: 'same-origin', body: body, signal: phpBusy ? phpBusy.signal : undefined})
			.then(function (r) { if (!r.ok) throw new Error('http'); return r.json(); })
			.then(function (j) { return (j.errors || []).map(function (e) { return {line: e.line, col: e.col || 0, sev: 'error', msg: e.msg}; }); });
	}
	/** Problems of a piece of code: Promise of [{line, col, sev: error|warn, msg}] (lines from 1); null = no check for the language. */
	function checkCode(code, lang) {
		lang = String(lang || '').toUpperCase();
		if (lang === 'JAVASCRIPT') lang = 'JS';
		if (!CHECKS[lang] || String(code).trim() === '') return Promise.resolve(CHECKS[lang] ? [] : null);
		if (lang === 'JS' && /^\s*<(script|link|style|meta|!--)/i.test(code)) lang = 'HTML';   // Setup → Header written as HTML (as nuBuilder Forte reads it)
		if (lang === 'JS') return checkJs(code);
		if (lang === 'CSS') return Promise.resolve(checkCss(code));
		if (lang === 'HTML') return checkHtml(code);
		return checkPhp(code);
	}

	function sevIcon(p) { return p.sev === 'error' ? 'bi-x-circle-fill' : 'bi-exclamation-triangle-fill'; }
	/** The check of one editor: gutter marks, toolbar button, check while typing. One state per CodeMirror (design mode attaches again on every code item). */
	function checkTools(cm, g, lang, opt) {
		var st = cm.nuChk;
		if (!st) {
			st = cm.nuChk = {lang: lang, problems: [], marks: [], timer: null, seq: 0, btn: null, label: ''};
			var gut = (cm.getOption('gutters') || []).slice();
			if (gut.indexOf('nuChkGutter') < 0) { gut.push('nuChkGutter'); cm.setOption('gutters', gut); }
			cm.on('change', function () { clearTimeout(st.timer); st.timer = setTimeout(function () { run(false); }, st.lang === 'PHP' ? 1500 : 800); });
			cm.on('swapDoc', function () { st.marks = []; run(false); });
			cm.addKeyMap({'Shift-Alt-C': function () { run(true); }});
		}
		st.lang = lang;
		var ta = cm.getTextArea ? cm.getTextArea() : null;
		st.label = opt.checkLabel || (ta && (ta.getAttribute('aria-label') || (ta.id && document.querySelector('label[for="' + ta.id + '"]') || {}).textContent)) || lang;
		var b = button('bi-check2-circle', TC.chk, 'nuCtCheck');
		st.btn = b;
		var full = g.querySelector('.nuCtFull');
		g.insertBefore(b, full || null);
		b.addEventListener('click', function () { run(true); });
		function clearMarks() {
			st.marks.forEach(function (h) { cm.removeLineClass(h, 'background', 'nuChkLine'); });
			st.marks = [];
			cm.clearGutter('nuChkGutter');
		}
		function show(list) {
			clearMarks();
			list.forEach(function (p) {
				var ln = Math.max(0, Math.min(cm.lineCount() - 1, p.line - 1));
				var m = elt('span', 'nuChkMark nuChk-' + p.sev);
				m.appendChild(elt('i', 'bi ' + sevIcon(p)));
				m.title = p.msg;
				m.addEventListener('click', function () { cm.setCursor({line: ln, ch: p.col || 0}); cm.focus(); });
				cm.setGutterMarker(ln, 'nuChkGutter', m);
				if (p.sev === 'error') st.marks.push(cm.addLineClass(ln, 'background', 'nuChkLine'));
			});
			var errs = list.filter(function (p) { return p.sev === 'error'; }).length;
			b.classList.toggle('nuChkBad', errs > 0);
			b.classList.toggle('nuChkWarn', !errs && list.length > 0);
			b.title = list.length ? TC.chk + ' – ' + TC.chkN.replace('%d', list.length) : TC.chk;
			b.setAttribute('aria-label', b.title);
		}
		function run(loud) {
			var seq = ++st.seq;
			return checkCode(cm.getValue(), st.lang).then(function (list) {
				if (seq !== st.seq || list === null) return list;
				st.problems = list;
				show(list);
				if (loud) pop(list);
				return list;
			}, function () { if (loud && st.lang === 'PHP') pop(null); return []; });
		}
		var popEl = null;
		function popClose() { if (popEl) { popEl.remove(); popEl = null; document.removeEventListener('mousedown', outside, true); } }
		function outside(e) { if (popEl && !popEl.contains(e.target) && e.target !== b && !b.contains(e.target)) popClose(); }
		function pop(list) {
			popClose();
			popEl = elt('div', 'nuChkPop shadow-sm');
			popEl.setAttribute('role', 'status');
			if (list === null) popEl.appendChild(elt('div', 'text-body-secondary', TC.chkPhpOff));
			else if (!list.length) { var ok = elt('div', 'nuChkOk'); ok.appendChild(elt('i', 'bi bi-check-circle-fill me-1')); ok.appendChild(document.createTextNode(TC.chkOk)); popEl.appendChild(ok); }
			else list.forEach(function (p) {
				var a = elt('button', 'nuChkItem nuChk-' + p.sev);
				a.type = 'button';
				a.appendChild(elt('i', 'bi ' + sevIcon(p) + ' me-1'));
				a.appendChild(elt('strong', '', TC.chkLine.replace('%d', p.line)));
				a.appendChild(document.createTextNode(' – ' + p.msg));
				a.addEventListener('click', function () { popClose(); cm.setCursor({line: p.line - 1, ch: p.col || 0}); cm.scrollIntoView(null, 80); cm.focus(); });
				popEl.appendChild(a);
			});
			document.body.appendChild(popEl);
			var r = b.getBoundingClientRect();
			popEl.style.top = (r.bottom + window.scrollY + 4) + 'px';
			popEl.style.left = Math.max(4, Math.min(r.left + window.scrollX, window.scrollX + window.innerWidth - popEl.offsetWidth - 8)) + 'px';
			setTimeout(function () { document.addEventListener('mousedown', outside, true); }, 0);
			if (list && !list.length) setTimeout(popClose, 2500);
		}
		st.run = run;
		setTimeout(function () { run(false); }, 300);
		return {detach: function () { popClose(); b.remove(); }};
	}

	/** Every checked editor in root (or the page): Promise of [{cm, label, problems}] with the problems found now. */
	function checkAll(root) {
		var cms = Array.prototype.map.call((root || document).querySelectorAll('.CodeMirror'), function (w) { return w.CodeMirror; })
			.filter(function (c) { return c && c.nuChk && c.nuChk.run; });
		return Promise.all(cms.map(function (c) { return c.nuChk.run(false).then(function (p) { return {cm: c, label: c.nuChk.label, problems: p || []}; }); }));
	}
	/** Asks before saving code with errors: res = [{label, problems, cm?}]; proceed() on "Save anyway", back(first) on Cancel. */
	function confirmProblems(res, proceed, back) {
		var bad = res.filter(function (r) { return r.problems.some(function (p) { return p.sev === 'error'; }); });
		if (!bad.length) { proceed(); return false; }
		var details = [];
		bad.forEach(function (r) { r.problems.forEach(function (p) { if (p.sev === 'error') details.push((r.label ? r.label + ' · ' : '') + TC.chkLine.replace('%d', p.line) + ' – ' + p.msg); }); });
		var first = bad[0];
		var goBack = function () {
			if (back) { back(first); return; }
			if (!first.cm) return;
			var pane = first.cm.getWrapperElement().closest('.tab-pane');
			if (pane && pane.id && window.nuBs) { var link = document.querySelector('[data-bs-target="#' + pane.id + '"], a[href="#' + pane.id + '"]'); if (link) window.nuBs.tab(link).show(); }
			setTimeout(function () { var p = first.problems.filter(function (x) { return x.sev === 'error'; })[0]; first.cm.refresh(); first.cm.setCursor({line: p.line - 1, ch: p.col || 0}); first.cm.focus(); }, 150);
		};
		if (typeof window.nuConfirmAsk === 'function') {
			window.nuConfirmAsk(TC.chkSave, proceed, TC.chkSaveAnyway, 'btn btn-warning', {title: TC.chkTitle, details: details.slice(0, 8), cancelFirst: true, onCancel: goBack});
		} else if (window.confirm(TC.chkSave + '\n\n' + details.slice(0, 8).join('\n'))) proceed(); else goBack();
		return true;
	}
	/** Save of a form with code editors: checked first; errors ask "Save anyway" (submit buttons with data-no-check are not checked). */
	function guardForm(form) {
		if (!form || form.nuChkGuard) return;
		form.nuChkGuard = true;
		form.addEventListener('submit', function (e) {
			if (form.nuChkPass) { form.nuChkPass = false; return; }
			var sub = e.submitter || null;
			if (sub && sub.hasAttribute('data-no-check')) return;
			e.preventDefault();
			e.stopImmediatePropagation();
			checkAll(form.closest('main') || document).then(function (res) {
				confirmProblems(res, function () {
					// later, not in this microtask: it can still run inside the first submit event, where a second submit is ignored
					setTimeout(function () {
						form.nuChkPass = true;
						if (form.requestSubmit) form.requestSubmit(sub && sub.form === form ? sub : undefined); else form.submit();
					}, 0);
				});
			});
		}, true);
	}

	window.NuCodeTools = {attach: attach, format: format, check: checkCode, checkAll: checkAll, confirmProblems: confirmProblems, guardForm: guardForm};
})();
