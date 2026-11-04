/* nuBuilder Next – CodeMirror 6 with the CodeMirror 5 calls nuBuilder Next uses.
 * window.CodeMirror = a small CodeMirror 5 compatible API on top of CodeMirror 6: fromTextArea, Doc / swapDoc, options, events,
 * gutters, line classes, overlays, key maps, show-hint, search. Only what the code of nuBuilder Next calls – see the README of the
 * folder. Built by dev/cm6/build.sh (esbuild). */
import {EditorState, EditorSelection, Compartment, StateField, StateEffect, RangeSetBuilder, Annotation, Prec, Transaction, Text} from '@codemirror/state';
import {EditorView, keymap, lineNumbers, highlightActiveLine, highlightActiveLineGutter, drawSelection, dropCursor, rectangularSelection,
	crosshairCursor, highlightSpecialChars, Decoration, ViewPlugin, gutter, GutterMarker} from '@codemirror/view';
import {defaultKeymap, history, historyKeymap, undo, redo, undoDepth, redoDepth, indentWithTab, toggleComment, selectAll,
	indentMore, indentLess} from '@codemirror/commands';
import {indentUnit, bracketMatching, syntaxHighlighting, HighlightStyle, syntaxTree, indentOnInput, foldGutter} from '@codemirror/language';
import {searchKeymap, openSearchPanel, searchPanelOpen, findNext, findPrevious, gotoLine, highlightSelectionMatches} from '@codemirror/search';
import {closeBrackets, closeBracketsKeymap} from '@codemirror/autocomplete';
import {tags as t} from '@lezer/highlight';
import {javascript} from '@codemirror/lang-javascript';
import {php} from '@codemirror/lang-php';
import {sql, MySQL, MSSQL, StandardSQL, PostgreSQL, SQLite} from '@codemirror/lang-sql';
import {html} from '@codemirror/lang-html';
import {css} from '@codemirror/lang-css';
import {json} from '@codemirror/lang-json';
import {xml} from '@codemirror/lang-xml';

var VERSION = '6.0.0-nu';                                     // version of the adapter; the packages: CodeMirror.versions
var Pass = {toString: function () { return 'CodeMirror.Pass'; }};
var originAnn = Annotation.define();

// ---------------------------------------------------------------- events (CodeMirror.on / off / signal)
function on(obj, type, fn) { var h = obj._handlers || (obj._handlers = {}); (h[type] || (h[type] = [])).push(fn); }
function off(obj, type, fn) { var h = obj._handlers && obj._handlers[type]; if (!h) return; var i = h.indexOf(fn); if (i >= 0) h.splice(i, 1); }
function signal(obj, type) {
	var h = obj._handlers && obj._handlers[type]; if (!h || !h.length) return;
	var args = Array.prototype.slice.call(arguments, 2);
	h.slice().forEach(function (fn) { try { fn.apply(null, args); } catch (e) { setTimeout(function () { throw e; }); } });
}

// ---------------------------------------------------------------- modes → languages
function langOf(mode) {
	var name = mode && typeof mode === 'object' ? mode.name : mode;
	var open = mode && typeof mode === 'object' ? !!mode.startOpen : false;
	switch (String(name || '').toLowerCase()) {
		case 'javascript': case 'text/javascript': case 'application/javascript': case 'js': return javascript();
		case 'application/json': case 'json': return json();
		case 'text/typescript': case 'typescript': return javascript({typescript: true});
		case 'css': case 'text/css': case 'text/x-scss': case 'text/x-less': case 'scss': case 'less': return css();
		case 'htmlmixed': case 'text/html': case 'html': return html();
		case 'xml': case 'application/xml': case 'text/xml': return xml();
		case 'php': case 'application/x-httpd-php': return php({plain: open});
		case 'text/x-php': case 'application/x-httpd-php-open': return php({plain: true});
		case 'text/x-mysql': case 'text/x-mariadb': case 'mysql': return sql({dialect: MySQL});
		case 'text/x-mssql': return sql({dialect: MSSQL});
		case 'text/x-pgsql': return sql({dialect: PostgreSQL});
		case 'text/x-sqlite': return sql({dialect: SQLite});
		case 'text/x-sql': case 'sql': return sql({dialect: StandardSQL});
		default: return [];
	}
}

// CodeMirror 5's token classes (cm-keyword …), so the colours of codemirror.css and nuapp.css (dark mode) apply
var HL = HighlightStyle.define([
	{tag: [t.keyword, t.controlKeyword, t.operatorKeyword, t.definitionKeyword, t.moduleKeyword, t.modifier, t.self], class: 'cm-keyword'},
	{tag: [t.atom, t.bool, t.null], class: 'cm-atom'},
	{tag: [t.number, t.integer, t.float], class: 'cm-number'},
	{tag: [t.definition(t.variableName), t.function(t.definition(t.variableName))], class: 'cm-def'},
	{tag: t.variableName, class: 'cm-variable'},
	{tag: [t.local(t.variableName), t.special(t.variableName), t.labelName], class: 'cm-variable-2'},
	{tag: [t.typeName, t.className, t.namespace], class: 'cm-variable-3'},
	{tag: t.standard(t.variableName), class: 'cm-builtin'},
	{tag: [t.propertyName, t.definition(t.propertyName)], class: 'cm-property'},
	{tag: [t.operator, t.compareOperator, t.arithmeticOperator, t.logicOperator, t.bitwiseOperator, t.updateOperator, t.definitionOperator], class: 'cm-operator'},
	{tag: [t.comment, t.lineComment, t.blockComment, t.docComment], class: 'cm-comment'},
	{tag: [t.string, t.attributeValue, t.character], class: 'cm-string'},
	{tag: [t.special(t.string), t.regexp, t.escape], class: 'cm-string-2'},
	{tag: [t.meta, t.processingInstruction, t.documentMeta, t.annotation], class: 'cm-meta'},
	{tag: t.tagName, class: 'cm-tag'},
	{tag: t.attributeName, class: 'cm-attribute'},
	{tag: t.heading, class: 'cm-header'},
	{tag: [t.link, t.url], class: 'cm-link'},
	{tag: t.invalid, class: 'cm-error'},
	{tag: [t.bracket, t.paren, t.squareBracket, t.brace, t.angleBracket], class: 'cm-bracket'},
	{tag: [t.punctuation, t.separator], class: 'cm-punctuation'}
]);

// ---------------------------------------------------------------- positions {line, ch} ⇄ offsets
function clampPos(doc, p) {
	if (p == null) return {line: 0, ch: 0};
	var line = Math.max(0, Math.min(doc.lines - 1, p.line | 0));
	var len = doc.line(line + 1).length;
	return {line: line, ch: p.ch == null ? len : Math.max(0, Math.min(len, p.ch | 0))};
}
function toOff(doc, p) { var c = clampPos(doc, p); return doc.line(c.line + 1).from + c.ch; }
function toPos(doc, off) { off = Math.max(0, Math.min(doc.length, off | 0)); var l = doc.lineAt(off); return {line: l.number - 1, ch: off - l.from}; }
function cmpPos(a, b) { return a.line - b.line || a.ch - b.ch; }

function userEvent(origin) {
	if (!origin) return undefined;
	if (origin === '+input') return 'input.type';
	if (origin === '+delete') return 'delete';
	if (origin === 'paste') return 'input.paste';
	if (origin === 'undo' || origin === 'redo') return origin;
	if (origin === '+move' || origin === '+select') return 'select';
	return 'input';
}
function originOf(tr) {
	var o = tr.annotation(originAnn); if (o) return o;
	if (tr.isUserEvent('input.paste')) return 'paste';
	if (tr.isUserEvent('input.drop')) return 'drag';
	if (tr.isUserEvent('input.complete')) return 'complete';
	if (tr.isUserEvent('input')) return '+input';
	if (tr.isUserEvent('delete')) return '+delete';
	if (tr.isUserEvent('undo')) return 'undo';
	if (tr.isUserEvent('redo')) return 'redo';
	if (tr.isUserEvent('move')) return 'drag';
	return 'setValue';
}
function changeObjs(tr) {
	var out = [], origin = originOf(tr), start = tr.startState.doc;
	tr.changes.iterChanges(function (fA, tA, fB, tB, ins) {
		out.push({from: toPos(start, fA), to: toPos(start, tA), text: ins.toString().split('\n'), removed: start.sliceString(fA, tA).split('\n'), origin: origin});
	});
	return out;
}

// ---------------------------------------------------------------- gutter markers and line classes (state fields)
var setMarkerEff = StateEffect.define({map: function (v, ch) { return {pos: ch.mapPos(v.pos), gutter: v.gutter, el: v.el}; }});
var clearGutterEff = StateEffect.define();
class ElMarker extends GutterMarker {
	constructor(el) { super(); this.el = el; }
	toDOM() { return this.el; }
	eq(o) { return o.el === this.el; }
}
var markerField = StateField.define({
	create: function () { return {}; },
	update: function (val, tr) {
		var next = {}, changed = false;
		Object.keys(val).forEach(function (g) { next[g] = tr.docChanged ? val[g].map(tr.changes) : val[g]; if (tr.docChanged) changed = true; });
		tr.effects.forEach(function (e) {
			if (e.is(clearGutterEff)) { next[e.value] = Decoration.none; changed = true; }
			if (e.is(setMarkerEff)) {
				var g = e.value.gutter, cur = next[g] || Decoration.none, line = tr.state.doc.lineAt(e.value.pos).from;
				cur = cur.update({filter: function (from) { return from !== line; }});
				if (e.value.el) cur = cur.update({add: [new ElMarker(e.value.el).range(line)]});
				next[g] = cur; changed = true;
			}
		});
		return changed ? next : val;
	}
});
var lineClassEff = StateEffect.define();              // {pos, cls, add}
var lineClassField = StateField.define({
	create: function () { return Decoration.none; },
	update: function (deco, tr) {
		deco = deco.map(tr.changes);
		tr.effects.forEach(function (e) {
			if (!e.is(lineClassEff)) return;
			var v = e.value, from = tr.state.doc.lineAt(v.pos).from;
			deco = deco.update({filter: function (f, to, d) { return !(f === from && d.spec.nuCls === v.cls); }});
			if (v.add) deco = deco.update({add: [Decoration.line({attributes: {class: v.cls}, nuCls: v.cls}).range(from)]});
		});
		return deco;
	},
	provide: function (f) { return EditorView.decorations.from(f); }
});

// ---------------------------------------------------------------- overlays (mode objects with token(stream))
function StringStream(string) { this.string = string; this.pos = 0; this.start = 0; }
StringStream.prototype = {
	eol: function () { return this.pos >= this.string.length; },
	sol: function () { return this.pos === 0; },
	peek: function () { return this.string.charAt(this.pos) || undefined; },
	next: function () { if (this.pos < this.string.length) return this.string.charAt(this.pos++); },
	eat: function (m) {
		var ch = this.string.charAt(this.pos), ok = typeof m === 'string' ? ch === m : ch && (m.test ? m.test(ch) : m(ch));
		if (ok) { ++this.pos; return ch; }
	},
	eatWhile: function (m) { var s = this.pos; while (this.eat(m)) { /* more */ } return this.pos > s; },
	eatSpace: function () { var s = this.pos; while (/[\s ]/.test(this.string.charAt(this.pos))) ++this.pos; return this.pos > s; },
	skipToEnd: function () { this.pos = this.string.length; },
	skipTo: function (ch) { var i = this.string.indexOf(ch, this.pos); if (i > -1) { this.pos = i; return true; } },
	backUp: function (n) { this.pos -= n; },
	current: function () { return this.string.slice(this.start, this.pos); },
	match: function (pattern, consume, ci) {
		if (typeof pattern === 'string') {
			var cased = function (s) { return ci ? s.toLowerCase() : s; }, sub = this.string.substr(this.pos, pattern.length);
			if (cased(sub) === cased(pattern)) { if (consume !== false) this.pos += pattern.length; return true; }
			return null;
		}
		var m = this.string.slice(this.pos).match(pattern);
		if (m && m.index > 0) return null;
		if (m && consume !== false) this.pos += m[0].length;
		return m;
	}
};
function overlayPlugin(mode) {
	var build = function (view) {
		var b = new RangeSetBuilder();
		view.visibleRanges.forEach(function (r) {
			for (var pos = r.from; pos <= r.to;) {
				var line = view.state.doc.lineAt(pos), st = mode.startState ? mode.startState() : null, s = new StringStream(line.text);
				while (!s.eol()) {
					s.start = s.pos;
					var style = mode.token(s, st);
					if (s.pos <= s.start) s.pos = s.start + 1;            // a token that eats nothing would never end
					if (style) b.add(line.from + s.start, line.from + s.pos, Decoration.mark({class: style.split(' ').map(function (x) { return 'cm-' + x; }).join(' ')}));
				}
				pos = line.to + 1;
			}
		});
		return b.finish();
	};
	return ViewPlugin.fromClass(class {
		constructor(view) { this.decorations = build(view); }
		update(u) { if (u.docChanged || u.viewportChanged) this.decorations = build(u.view); }
	}, {decorations: function (v) { return v.decorations; }});
}

// ---------------------------------------------------------------- key names CodeMirror 5 → 6
function keyName(k) {
	var parts = String(k).split('-'), key = parts.pop();   // "Ctrl--" → ['Ctrl', '', ''] = Ctrl + minus
	if (key === '' && parts.length) { parts.pop(); key = '-'; }
	key = {Esc: 'Escape', Space: 'Space', Del: 'Delete', Ins: 'Insert', Up: 'ArrowUp', Down: 'ArrowDown', Left: 'ArrowLeft', Right: 'ArrowRight', PageUp: 'PageUp', PageDown: 'PageDown'}[key] || key;
	if (key.length === 1) key = key.toLowerCase();
	return parts.map(function (m) { return {Cmd: 'Meta', Mod: 'Mod'}[m] || m; }).concat([key]).join('-');
}

// ---------------------------------------------------------------- commands (execCommand, key map values)
var COMMANDS = {
	find: function (cm) { openSearchPanel(cm.view); },
	replace: function (cm) {
		openSearchPanel(cm.view);
		setTimeout(function () { var f = cm.view.dom.querySelector('.cm-search input[name=replace]'); if (f && !cm.getOption('readOnly')) f.focus(); }, 0);
	},
	findNext: function (cm) { findNext(cm.view); },
	findPrev: function (cm) { findPrevious(cm.view); },
	jumpToLine: function (cm) { gotoLine(cm.view); },
	toggleComment: function (cm) { toggleComment(cm.view); },
	undo: function (cm) { undo(cm.view); },
	redo: function (cm) { redo(cm.view); },
	selectAll: function (cm) { selectAll(cm.view); },
	indentMore: function (cm) { indentMore(cm.view); },
	indentLess: function (cm) { indentLess(cm.view); },
	autocomplete: function (cm) { if (cm.showHint) cm.showHint(); },
	save: function (cm) { if (cm._ta && cm._ta.form) cm._ta.form.requestSubmit ? cm._ta.form.requestSubmit() : cm._ta.form.submit(); }
};
function runKey(cm, fn) {
	return function () {
		if (typeof fn === 'string') { if (!COMMANDS[fn]) return false; COMMANDS[fn](cm); return true; }
		return fn(cm) !== Pass;
	};
}

// search phrases: CodeMirror 5 keys (nucodetools.js) → CodeMirror 6 keys
var PHRASE_MAP = {'Search:': 'Find', 'Replace:': 'Replace', 'All': 'all', 'Replace all:': 'replace all', 'Jump to line:': 'Go to line'};

// ---------------------------------------------------------------- Doc
var docId = 0;
function Doc(text, mode, firstLine, lineSep) {
	if (!(this instanceof Doc)) return new Doc(text, mode, firstLine, lineSep);
	this.id = ++docId;
	this.modeOption = mode;
	this.cm = null;
	this._lang = new Compartment();
	this._state = EditorState.create({doc: text == null ? '' : String(text), extensions: [history(), markerField, lineClassField, this._lang.of(langOf(mode))]});
	this._clean = this._state.doc;
	this._gens = [];
}
Doc.prototype = {
	get state() { return this.cm ? this.cm.view.state : this._state; },
	_dispatch: function (spec) {
		if (this.cm) this.cm.view.dispatch(spec);
		else {
			var tr = this._state.update(spec), ch = tr.docChanged ? changeObjs(tr) : null;
			this._state = tr.state;
			if (ch) { var self = this; ch.forEach(function (c) { signal(self, 'change', self, c); }); }
		}
	},
	_change: function (from, to, text, origin, sel) {
		var d = this.state.doc, a = toOff(d, from), b = to ? toOff(d, to) : a;
		if (b < a) { var x = a; a = b; b = x; }
		var spec = {changes: {from: a, to: b, insert: text == null ? '' : String(text)}, annotations: origin ? [originAnn.of(origin)] : [], userEvent: userEvent(origin)};
		if (sel) spec.selection = sel(a, String(text == null ? '' : text).length);
		this._dispatch(spec);
	},
	getValue: function (sep) { var t = this.state.doc; return sep == null ? t.sliceString(0, t.length, this.state.lineBreak) : t.toJSON().join(sep); },
	setValue: function (v) {
		var d = this.state.doc;
		this._dispatch({changes: {from: 0, to: d.length, insert: v == null ? '' : String(v)}, selection: EditorSelection.cursor(0), annotations: [originAnn.of('setValue'), Transaction.addToHistory.of(true)]});
	},
	getLine: function (n) { var d = this.state.doc; return n < 0 || n >= d.lines ? undefined : d.line(n + 1).text; },
	lineCount: function () { return this.state.doc.lines; },
	firstLine: function () { return 0; },
	lastLine: function () { return this.state.doc.lines - 1; },
	getRange: function (from, to, sep) { var d = this.state.doc, a = toOff(d, from), b = toOff(d, to); if (b < a) { var x = a; a = b; b = x; } var s = d.sliceString(a, b); return sep == null ? s : s.split('\n').join(sep); },
	replaceRange: function (text, from, to, origin) { this._change(from, to || from, text, origin); },
	getCursor: function (which) {
		var s = this.state.selection.main, d = this.state.doc;
		var off = which === 'from' || which === 'start' ? s.from : which === 'to' || which === 'end' ? s.to : which === 'anchor' ? s.anchor : s.head;
		return toPos(d, off);
	},
	setCursor: function (line, ch) {
		var p = typeof line === 'object' ? line : {line: line, ch: ch};
		this._dispatch({selection: EditorSelection.cursor(toOff(this.state.doc, p)), scrollIntoView: !!this.cm});
	},
	setSelection: function (anchor, head) {
		var d = this.state.doc;
		this._dispatch({selection: EditorSelection.range(toOff(d, anchor), toOff(d, head || anchor)), scrollIntoView: !!this.cm});
	},
	getSelection: function (sep) { var s = this.state.selection.main, t = this.state.doc.sliceString(s.from, s.to); return sep == null ? t : t.split('\n').join(sep); },
	somethingSelected: function () { return this.state.selection.ranges.some(function (r) { return !r.empty; }); },
	listSelections: function () { var d = this.state.doc; return this.state.selection.ranges.map(function (r) { return {anchor: toPos(d, r.anchor), head: toPos(d, r.head)}; }); },
	replaceSelection: function (text, select, origin) {
		var s = this.state.selection.main;
		text = text == null ? '' : String(text);
		this._dispatch({changes: {from: s.from, to: s.to, insert: text},
			selection: select === 'around' ? EditorSelection.range(s.from, s.from + text.length) : select === 'start' ? EditorSelection.cursor(s.from) : EditorSelection.cursor(s.from + text.length),
			annotations: origin ? [originAnn.of(origin)] : [], userEvent: userEvent(origin) || 'input', scrollIntoView: !!this.cm});
	},
	indexFromPos: function (p) { return toOff(this.state.doc, p); },
	posFromIndex: function (i) { return toPos(this.state.doc, i); },
	historySize: function () { return {undo: undoDepth(this.state), redo: redoDepth(this.state)}; },
	undo: function () { if (this.cm) undo(this.cm.view); else undo({state: this._state, dispatch: this._dispatch.bind(this)}); },
	redo: function () { if (this.cm) redo(this.cm.view); else redo({state: this._state, dispatch: this._dispatch.bind(this)}); },
	clearHistory: function () {
		// a new state with the same text, selection, gutters and line classes, but an empty history
		var st = this.state, ext = this.cm ? this.cm._extensions(this) : [history(), markerField, lineClassField, this._lang.of(langOf(this.modeOption))];
		var fresh = EditorState.create({doc: st.doc, selection: st.selection, extensions: ext});
		var tr = fresh.update({effects: []});
		fresh = tr.state;
		var mk = st.field(markerField, false), lc = st.field(lineClassField, false);
		if (this.cm) {
			var v = this.cm.view; cssom(function () { v.setState(fresh); });
			var eff = [];
			if (mk) Object.keys(mk).forEach(function (g) { var c = mk[g].iter(); while (c.value) { eff.push(setMarkerEff.of({pos: c.from, gutter: g, el: c.value.el})); c.next(); } });
			if (lc) { var c2 = lc.iter(); while (c2.value) { eff.push(lineClassEff.of({pos: c2.from, cls: c2.value.spec.nuCls, add: true})); c2.next(); } }
			if (eff.length) this.cm.view.dispatch({effects: eff, annotations: Transaction.addToHistory.of(false)});
		} else this._state = fresh;
	},
	changeGeneration: function () { this._gens.push(this.state.doc); return this._gens.length - 1; },
	isClean: function (gen) { var ref = gen == null ? this._clean : this._gens[gen]; return !!ref && ref.eq(this.state.doc); },
	markClean: function () { this._clean = this.state.doc; },
	getMode: function () { var m = this.modeOption; return {name: m && typeof m === 'object' ? m.name : (m || 'null')}; },
	getEditor: function () { return this.cm; },
	on: function (type, fn) { on(this, type, fn); },
	off: function (type, fn) { off(this, type, fn); },
	eachLine: function (a, b, fn) {
		var d = this.state.doc, from = 0, to = d.lines;
		if (typeof a === 'function') fn = a; else { from = a; to = b; }
		for (var i = from; i < to && i < d.lines; i++) fn(d.line(i + 1).text);
	}
};

// ---------------------------------------------------------------- strict CSP
// CodeMirror 6 writes some styles as a style attribute (the content's tab-size); a page with style-src 'self' blocks that. While
// CodeMirror builds or reconfigures the view, a style attribute is set through the CSSOM instead (allowed by the CSP).
function cssom(fn) {
	var P = Element.prototype, orig = P.setAttribute;
	P.setAttribute = function (name, value) { if (name === 'style' && this.style) { this.style.cssText = value; return; } return orig.call(this, name, value); };
	try { return fn(); } finally { P.setAttribute = orig; }
}

// ---------------------------------------------------------------- the editor
function CM(place, options) {
	var self = this;
	options = options || {};
	this.options = {
		mode: options.mode, lineNumbers: !!options.lineNumbers, lineWrapping: !!options.lineWrapping, readOnly: options.readOnly || false,
		matchBrackets: options.matchBrackets !== false && options.matchBrackets !== undefined ? true : !!options.matchBrackets,
		autoCloseBrackets: !!options.autoCloseBrackets, indentUnit: options.indentUnit || 2, tabSize: options.tabSize || 4,
		indentWithTabs: !!options.indentWithTabs, extraKeys: options.extraKeys || null, gutters: (options.gutters || []).slice(),
		phrases: options.phrases || null, lineSeparator: options.lineSeparator || null, theme: options.theme || 'default',
		styleActiveLine: !!options.styleActiveLine, tabindex: options.tabindex, autofocus: !!options.autofocus, placeholder: options.placeholder
	};
	this.state = {};
	this._maps = [];
	this._overlays = [];
	this._c = {lang: null, conf: new Compartment(), keys: new Compartment(), over: new Compartment()};
	this.display = {};
	var wrap = document.createElement('div');
	wrap.className = 'CodeMirror';
	this._wrap = wrap;
	this._applyTheme();
	var doc = options.value instanceof Doc ? options.value : new Doc(options.value || '', options.mode);
	if (doc.cm) throw new Error('This document is already in use.');
	this.doc = doc;
	doc.cm = null;
	var st = doc._state.update({effects: StateEffect.reconfigure.of(this._extensions(doc))}).state;
	this.view = cssom(function () { return new EditorView({state: st, parent: wrap, dispatchTransactions: function (trs, view) { self._dispatch(trs, view); }}); });
	doc.cm = this;
	this.view.scrollDOM.classList.add('CodeMirror-scroll');
	this.view.scrollDOM.CodeMirror = this;
	this.display.wrapper = wrap; this.display.scroller = this.view.scrollDOM; this.display.input = this.view.contentDOM;
	wrap.CodeMirror = this;
	if (typeof place === 'function') place(wrap); else if (place && place.appendChild) place.appendChild(wrap);
	if (this.options.tabindex != null) this.view.contentDOM.setAttribute('tabindex', this.options.tabindex);
	if (this.options.autofocus) setTimeout(function () { self.view.focus(); }, 0);
	this.view.dom.addEventListener('mousedown', function (e) { signal(self, 'mousedown', self, e); });
	this.view.dom.addEventListener('keydown', function (e) { signal(self, 'keydown', self, e); });
}
CM.prototype = {
	constructor: CM,
	_applyTheme: function () {
		var w = this._wrap;
		Array.prototype.slice.call(w.classList).forEach(function (c) { if (/^cm-s-/.test(c)) w.classList.remove(c); });
		String(this.options.theme || 'default').split(/\s+/).forEach(function (c) { if (c) w.classList.add('cm-s-' + c); });
		w.classList.toggle('CodeMirror-wrap', !!this.options.lineWrapping);
	},
	_conf: function () {
		var o = this.options, ext = [];
		ext.push(EditorState.tabSize.of(o.tabSize), indentUnit.of(o.indentWithTabs ? '\t' : ' '.repeat(o.indentUnit)));
		if (o.lineNumbers) ext.push(lineNumbers());
		o.gutters.forEach(function (g) {
			if (g === 'CodeMirror-linenumbers') return;
			if (g === 'CodeMirror-foldgutter') { ext.push(foldGutter()); return; }
			ext.push(gutter({class: g, markers: function (v) { var m = v.state.field(markerField, false); return (m && m[g]) || Decoration.none; },
				initialSpacer: null}));
		});
		if (o.lineWrapping) ext.push(EditorView.lineWrapping);
		if (o.readOnly) ext.push(EditorState.readOnly.of(true));
		if (o.readOnly === 'nocursor') ext.push(EditorView.editable.of(false));
		if (o.matchBrackets) ext.push(bracketMatching());
		if (o.autoCloseBrackets) ext.push(closeBrackets(), keymap.of(closeBracketsKeymap));
		if (o.styleActiveLine) ext.push(highlightActiveLine(), highlightActiveLineGutter());
		if (o.lineSeparator) ext.push(EditorState.lineSeparator.of(o.lineSeparator));
		if (o.phrases) {
			var ph = {};
			Object.keys(o.phrases).forEach(function (k) { ph[k] = o.phrases[k]; if (PHRASE_MAP[k]) ph[PHRASE_MAP[k]] = String(o.phrases[k]).replace(/\s*[:：]\s*$/, ''); });
			ext.push(EditorState.phrases.of(ph));
		}
		return ext;
	},
	_keys: function () {
		var self = this, maps = this._maps.slice().reverse();          // newest first, like CodeMirror 5
		if (this.options.extraKeys) maps.unshift(this.options.extraKeys);
		var bindings = [];
		maps.forEach(function (m) {
			Object.keys(m).forEach(function (k) {
				if (k === 'fallthrough' || k === 'name') return;
				var name = keyName(k), fn = m[k];
				if (fn === false) return;
				bindings.push({key: name, run: runKey(self, fn), preventDefault: true});
			});
		});
		var hintKeys = [
			['ArrowUp', -1], ['ArrowDown', 1], ['PageUp', -5], ['PageDown', 5], ['Home', 'first'], ['End', 'last'], ['Enter', 'pick'], ['Tab', 'pick'], ['Escape', 'close']
		].map(function (k) { return {key: k[0], run: function () { return self._hint ? self._hint.key(k[1]) : false; }}; });
		return [Prec.highest(keymap.of(hintKeys)), Prec.high(keymap.of(bindings))];
	},
	_extensions: function (doc) {
		return [
			history(), markerField, lineClassField, doc._lang.of(langOf(doc.modeOption)),
			highlightSpecialChars(), drawSelection(), dropCursor(), indentOnInput(), rectangularSelection(), crosshairCursor(), highlightSelectionMatches(),
			syntaxHighlighting(HL, {fallback: false}),
			this._c.conf.of(this._conf()), this._c.keys.of(this._keys()), this._c.over.of(this._overlays.map(function (o) { return o.ext; })),
			keymap.of([].concat(searchKeymap, defaultKeymap, historyKeymap, [indentWithTab]))
		];
	},
	_dispatch: function (trs, view) {
		var self = this, changes = [], sel = false, focus = view.hasFocus;
		cssom(function () { view.update(trs); });
		trs.forEach(function (tr) {
			if (tr.docChanged) changes = changes.concat(changeObjs(tr));
			if (tr.selection || tr.docChanged) sel = true;
		});
		if (changes.length) {
			var input = trs.some(function (tr) { return tr.isUserEvent('input.type') || tr.isUserEvent('input.paste') || tr.isUserEvent('input.drop'); });
			changes.forEach(function (c) { signal(self, 'change', self, c); signal(self.doc, 'change', self.doc, c); });
			signal(self, 'changes', self, changes);
			if (input) changes.forEach(function (c) { if (c.origin === '+input' || c.origin === 'paste') signal(self, 'inputRead', self, c); });
			if (self._hint) self._hint.update(changes);
		}
		if (sel) { signal(self, 'cursorActivity', self); if (self._hint && !changes.length) self._hint.cursor(); }
		this._wrap.classList.toggle('CodeMirror-focused', focus);
	},
	_reconf: function (part) {
		var eff = [];
		if (!part || part === 'conf') eff.push(this._c.conf.reconfigure(this._conf()));
		if (!part || part === 'keys') eff.push(this._c.keys.reconfigure(this._keys()));
		if (!part || part === 'over') eff.push(this._c.over.reconfigure(this._overlays.map(function (o) { return o.ext; })));
		this.view.dispatch({effects: eff});
	},
	// ---- options
	setOption: function (name, value) {
		var o = this.options;
		if (name === 'mode') { o.mode = value; this.doc.modeOption = value; this.view.dispatch({effects: this.doc._lang.reconfigure(langOf(value))}); return; }
		if (name === 'value') { this.setValue(value); return; }
		if (name === 'theme') { o.theme = value; this._applyTheme(); return; }
		if (name === 'gutters') { o.gutters = (value || []).slice(); this._reconf('conf'); return; }
		if (name === 'extraKeys') { o.extraKeys = value; this._reconf('keys'); return; }
		o[name] = value;
		if (name === 'lineWrapping') this._applyTheme();
		if (name === 'tabindex') { this.view.contentDOM.setAttribute('tabindex', value); return; }
		this._reconf('conf');
	},
	getOption: function (name) { if (name === 'value') return this.getValue(); if (name === 'mode') return this.doc.modeOption; return this.options[name]; },
	// ---- documents
	getDoc: function () { return this.doc; },
	swapDoc: function (doc) {
		var old = this.doc;
		if (doc === old) return old;
		if (doc.cm) throw new Error('This document is already in use.');
		old._state = this.view.state; old.cm = null;
		doc.cm = null;
		var st = doc._state.update({effects: StateEffect.reconfigure.of(this._extensions(doc))}).state;
		this.doc = doc; this.options.mode = doc.modeOption;
		var v = this.view; cssom(function () { v.setState(st); });
		doc.cm = this;
		if (this._hint) this._hint.close();
		signal(this, 'swapDoc', this, old);
		return old;
	},
	// ---- view
	getWrapperElement: function () { return this._wrap; },
	getScrollerElement: function () { return this.view.scrollDOM; },
	getGutterElement: function () { return this.view.dom.querySelector('.cm-gutters'); },
	getInputField: function () { return this.view.contentDOM; },
	getTextArea: function () { return this._ta || null; },
	focus: function () { this.view.focus(); },
	hasFocus: function () { return this.view.hasFocus; },
	refresh: function () { this.view.requestMeasure(); },
	setSize: function (w, h) {
		var px = function (v) { return typeof v === 'number' ? v + 'px' : v; };
		if (w != null) this._wrap.style.width = px(w);
		if (h != null) this._wrap.style.height = px(h);
		this.view.requestMeasure();
		signal(this, 'refresh', this);
	},
	scrollIntoView: function (what, margin) {
		var d = this.view.state.doc, pos;
		if (what == null) pos = this.view.state.selection.main.head;
		else if (typeof what === 'number') pos = d.line(Math.max(1, Math.min(d.lines, what + 1))).from;
		else if (what.from) pos = toOff(d, what.from);
		else pos = toOff(d, what);
		this.view.dispatch({effects: EditorView.scrollIntoView(pos, {y: 'nearest', yMargin: margin || 5})});
	},
	scrollTo: function (x, y) { if (x != null) this.view.scrollDOM.scrollLeft = x; if (y != null) this.view.scrollDOM.scrollTop = y; },
	getScrollInfo: function () { var s = this.view.scrollDOM; return {left: s.scrollLeft, top: s.scrollTop, width: s.scrollWidth, height: s.scrollHeight, clientWidth: s.clientWidth, clientHeight: s.clientHeight}; },
	cursorCoords: function (where) {
		var off = where && typeof where === 'object' ? toOff(this.view.state.doc, where) : this.view.state.selection.main.head;
		var r = this.view.coordsAtPos(off) || {left: 0, right: 0, top: 0, bottom: 0};
		return {left: r.left, right: r.right, top: r.top, bottom: r.bottom};
	},
	operation: function (fn) { return fn(); },
	startOperation: function () {}, endOperation: function () {},
	execCommand: function (name) { if (COMMANDS[name]) COMMANDS[name](this); },
	toggleComment: function () { toggleComment(this.view); },
	save: function () { if (this._ta) this._ta.value = this.getValue(); },
	toTextArea: function () {
		this.save();
		if (this._hint) this._hint.close();
		this.view.destroy(); this._wrap.remove();
		if (this._ta) { this._ta.style.display = ''; if (this._ta.form && this._onSubmit) this._ta.form.removeEventListener('submit', this._onSubmit); }
		this.doc.cm = null;
	},
	// ---- events
	on: function (type, fn) { on(this, type, fn); },
	off: function (type, fn) { off(this, type, fn); },
	// ---- key maps, overlays
	addKeyMap: function (map, bottom) { if (bottom) this._maps.unshift(map); else this._maps.push(map); this._reconf('keys'); },
	removeKeyMap: function (map) {
		var i = this._maps.indexOf(map);
		if (i < 0 && typeof map === 'string') this._maps.forEach(function (m, j) { if (m.name === map) i = j; });
		if (i >= 0) { this._maps.splice(i, 1); this._reconf('keys'); }
	},
	addOverlay: function (mode) { this._overlays.push({mode: mode, ext: overlayPlugin(mode)}); this._reconf('over'); },
	removeOverlay: function (mode) {
		var i = -1;
		this._overlays.forEach(function (o, j) { if (o.mode === mode || (typeof mode === 'string' && o.mode.name === mode)) i = j; });
		if (i >= 0) { this._overlays.splice(i, 1); this._reconf('over'); }
	},
	// ---- gutters and line classes
	setGutterMarker: function (line, gutterId, el) {
		var d = this.view.state.doc, n = typeof line === 'number' ? line : line && line.line;
		if (n == null || n < 0 || n >= d.lines) return;
		this.view.dispatch({effects: setMarkerEff.of({pos: d.line(n + 1).from, gutter: gutterId, el: el || null}), annotations: Transaction.addToHistory.of(false)});
		return {line: n};
	},
	clearGutter: function (gutterId) { this.view.dispatch({effects: clearGutterEff.of(gutterId)}); },
	addLineClass: function (line, where, cls) {
		var d = this.view.state.doc, n = typeof line === 'number' ? line : line && line.line;
		if (n == null || n < 0 || n >= d.lines) return null;
		this.view.dispatch({effects: lineClassEff.of({pos: d.line(n + 1).from, cls: cls, add: true})});
		return {line: n, cls: cls};
	},
	removeLineClass: function (line, where, cls) {
		var d = this.view.state.doc, n = typeof line === 'number' ? line : line && line.line;
		if (n == null || n < 0 || n >= d.lines) return;
		this.view.dispatch({effects: lineClassEff.of({pos: d.line(n + 1).from, cls: cls || (line && line.cls), add: false})});
	},
	// ---- tokens
	getTokenAt: function (p) {
		var st = this.view.state, off = toOff(st.doc, p), node = syntaxTree(st).resolveInner(off, -1), type = null;
		for (var n = node; n; n = n.parent) {
			if (/Comment/.test(n.name)) { type = 'comment'; break; }
			if (/String|Template|Heredoc|Nowdoc/.test(n.name)) { type = 'string'; break; }
		}
		var line = st.doc.lineAt(off);
		return {start: Math.max(0, node.from - line.from), end: Math.min(line.length, node.to - line.from), string: st.doc.sliceString(node.from, node.to), type: type};
	},
	getTokenTypeAt: function (p) { return this.getTokenAt(p).type; },
	// ---- show-hint
	showHint: function (opts) { if (this._hint) this._hint.close(); var h = new Hint(this, opts || {}); if (h.alive) this._hint = h; },
	closeHint: function () { if (this._hint) this._hint.close(); }
};
// text methods of the document on the editor too
['getValue', 'setValue', 'getLine', 'lineCount', 'firstLine', 'lastLine', 'getRange', 'replaceRange', 'getCursor', 'setCursor', 'setSelection',
	'getSelection', 'somethingSelected', 'listSelections', 'replaceSelection', 'indexFromPos', 'posFromIndex', 'historySize', 'undo', 'redo',
	'clearHistory', 'changeGeneration', 'isClean', 'markClean', 'getMode', 'eachLine'].forEach(function (m) {
	CM.prototype[m] = function () { return this.doc[m].apply(this.doc, arguments); };
});
Object.defineProperty(CM.prototype, 'state', {
	get: function () { var self = this; var s = this._st || (this._st = {}); Object.defineProperty(s, 'dialog', {configurable: true, get: function () { return self.view && searchPanelOpen(self.view.state) ? true : null; }}); return s; },
	set: function () {}
});

// ---------------------------------------------------------------- show-hint (CodeMirror 5's addon, the parts nucodetools.js uses)
function Hint(cm, opts) {
	this.cm = cm; this.opts = opts; this.alive = true; this.sel = 0; this.data = null; this.ul = null;
	var self = this;
	this.onBlur = function () { setTimeout(function () { if (self.alive && !self.cm.view.hasFocus && !(self.ul && self.ul.matches(':hover'))) self.close(); }, 150); };
	this.onScroll = function () { self.place(); };
	cm.view.contentDOM.addEventListener('blur', this.onBlur);
	cm.view.scrollDOM.addEventListener('scroll', this.onScroll);
	this.fetch();
}
Hint.prototype = {
	fetch: function () {
		var fn = this.opts.hint, r;
		if (!fn) { this.close(); return; }
		r = fn(this.cm, this.opts);
		var self = this;
		if (r && typeof r.then === 'function') r.then(function (x) { if (self.alive) self.show(x); }); else this.show(r);
	},
	show: function (data) {
		var old = this.data;
		if (old && old !== data) signal(old, 'close');
		this.data = data;
		if (!data || !data.list || !data.list.length) { this.close(); return; }
		if (this.opts.completeSingle !== false && data.list.length === 1 && !old) { this.pick(0); return; }
		var ul = this.ul;
		if (!ul) {
			ul = this.ul = document.createElement('ul');
			ul.className = 'CodeMirror-hints default';
			ul.setAttribute('role', 'listbox');
			document.body.appendChild(ul);
			var self = this;
			ul.addEventListener('mousedown', function (e) { e.preventDefault(); });
			ul.addEventListener('click', function (e) { var li = e.target.closest('li'); if (li && li.hintId != null) self.pick(li.hintId); });
			ul.addEventListener('mousemove', function (e) { var li = e.target.closest('li'); if (li && li.hintId != null && li.hintId !== self.sel) self.select(li.hintId); });
		}
		ul.textContent = '';
		data.list.forEach(function (c, i) {
			var li = document.createElement('li');
			li.className = 'CodeMirror-hint' + (c.className ? ' ' + c.className : '');
			li.setAttribute('role', 'option');
			li.hintId = i;
			if (typeof c === 'string') li.textContent = c;
			else if (c.render) c.render(li, data, c);
			else li.textContent = c.displayText || c.text;
			ul.appendChild(li);
		});
		this.place();
		this.sel = -1;
		this.select(0);
		signal(data, 'shown');
	},
	place: function () {
		if (!this.ul || !this.data) return;
		var off = toOff(this.cm.view.state.doc, this.data.from), r = this.cm.view.coordsAtPos(off);
		if (!r) { this.ul.style.display = 'none'; return; }
		var ul = this.ul;
		ul.style.display = ''; ul.style.position = 'fixed';
		ul.style.left = Math.max(0, r.left) + 'px'; ul.style.top = r.bottom + 'px';
		var h = ul.offsetHeight, w = ul.offsetWidth;
		if (r.bottom + h > window.innerHeight && r.top - h > 0) ul.style.top = (r.top - h) + 'px';
		if (r.left + w > window.innerWidth) ul.style.left = Math.max(0, window.innerWidth - w - 4) + 'px';
	},
	select: function (i) {
		var n = this.data.list.length;
		i = Math.max(0, Math.min(n - 1, i));
		if (i === this.sel) return;
		var lis = this.ul.children;
		if (lis[this.sel]) { lis[this.sel].classList.remove('CodeMirror-hint-active'); lis[this.sel].removeAttribute('aria-selected'); }
		this.sel = i;
		var li = lis[i];
		li.classList.add('CodeMirror-hint-active'); li.setAttribute('aria-selected', 'true');
		if (li.offsetTop < this.ul.scrollTop) this.ul.scrollTop = li.offsetTop - 3;
		else if (li.offsetTop + li.offsetHeight > this.ul.scrollTop + this.ul.clientHeight) this.ul.scrollTop = li.offsetTop + li.offsetHeight - this.ul.clientHeight + 3;
		signal(this.data, 'select', this.data.list[i], li);
	},
	key: function (k) {
		if (!this.alive || !this.data) return false;
		if (k === 'close') { this.close(); return true; }
		if (k === 'pick') { this.pick(this.sel); return true; }
		if (k === 'first') { this.select(0); return true; }
		if (k === 'last') { this.select(this.data.list.length - 1); return true; }
		this.select(this.sel + k);
		return true;
	},
	pick: function (i) {
		var data = this.data, c = data.list[i];
		this.close();
		if (!c) return;
		if (c.hint) c.hint(this.cm, data, c);
		else this.cm.replaceRange(typeof c === 'string' ? c : c.text, c.from || data.from, c.to || data.to, 'complete');
		signal(data, 'pick', c);
	},
	update: function (changes) {
		if (!this.alive) return;
		var cc = this.opts.closeCharacters || /[\s()[\]{};:>,]/;
		var typed = changes.map(function (c) { return c.text.join('\n'); }).join('');
		if (typed && cc.test(typed)) { this.close(); return; }
		var cur = this.cm.getCursor();
		if (this.data && (cur.line !== this.data.from.line || cur.ch < this.data.from.ch)) { this.close(); return; }
		this.fetch();
	},
	cursor: function () {
		if (!this.alive || !this.data) return;
		var cur = this.cm.getCursor(), d = this.data;
		if (cur.line !== d.from.line || cur.ch < d.from.ch || cmpPos(cur, d.to) > 0) this.close();
	},
	close: function () {
		if (!this.alive) return;
		this.alive = false;
		this.cm.view.contentDOM.removeEventListener('blur', this.onBlur);
		this.cm.view.scrollDOM.removeEventListener('scroll', this.onScroll);
		if (this.ul) this.ul.remove();
		if (this.cm._hint === this) this.cm._hint = null;
		if (this.data) signal(this.data, 'close');
	}
};

// ---------------------------------------------------------------- the public object
function CodeMirror(place, options) { return new CM(place, options); }
CodeMirror.fromTextArea = function (ta, options) {
	options = Object.assign({}, options || {});
	options.value = ta.value;
	if (options.tabindex == null && ta.tabIndex) options.tabindex = ta.tabIndex;
	if (options.placeholder == null && ta.getAttribute('placeholder')) options.placeholder = ta.getAttribute('placeholder');
	if (options.autofocus == null) options.autofocus = ta.hasAttribute('autofocus') && document.activeElement === ta;
	var cm = new CM(function (w) { ta.parentNode.insertBefore(w, ta.nextSibling); }, options);
	cm._ta = ta;
	ta.style.display = 'none';
	if (ta.form) { cm._onSubmit = function () { cm.save(); }; ta.form.addEventListener('submit', cm._onSubmit); }
	var lbl = ta.id ? document.querySelector('label[for="' + (window.CSS && CSS.escape ? CSS.escape(ta.id) : ta.id) + '"]') : null;
	var name = (lbl && lbl.textContent.trim()) || ta.getAttribute('aria-label');
	if (name) cm.view.contentDOM.setAttribute('aria-label', name);
	return cm;
};
CodeMirror.Doc = Doc;
CodeMirror.Pass = Pass;
CodeMirror.on = on;
CodeMirror.off = off;
CodeMirror.signal = signal;
CodeMirror.cmpPos = cmpPos;
CodeMirror.Pos = function (line, ch) { return {line: line, ch: ch == null ? 0 : ch}; };
CodeMirror.commands = COMMANDS;
CodeMirror.version = VERSION;
CodeMirror.nu6 = true;
/* global __CM6_VERSIONS__ */
CodeMirror.versions = typeof __CM6_VERSIONS__ !== 'undefined' ? __CM6_VERSIONS__ : {};
CodeMirror.cm6 = {EditorView: EditorView, EditorState: EditorState, Text: Text};    // the real CodeMirror 6 classes, for own code
window.CodeMirror = CodeMirror;
