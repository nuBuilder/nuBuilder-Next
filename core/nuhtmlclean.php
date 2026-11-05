<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Allowlist HTML cleaner (0.8.400) – for formatted notification texts (TinyMCE) and anything else where users send HTML that other
 * users see. Only the tags of TAGS stay, without attributes except a checked href on <a>; dangerous elements are dropped with their
 * content, other unknown elements are unwrapped (their text stays). Links get target="_blank" rel="noopener noreferrer".
 */
final class NuHtmlClean
{
	/** Allowed elements. */
	public const TAGS = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'ul', 'ol', 'li', 'a', 'blockquote', 'h4', 'h5', 'h6', 'hr'];
	/** Removed together with their content. */
	private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math', 'form', 'input', 'button', 'select',
		'textarea', 'head', 'title', 'meta', 'link', 'base', 'frame', 'frameset', 'applet', 'audio', 'video', 'canvas', 'img', 'picture', 'source'];

	public static function clean(string $html): string
	{
		if (trim($html) === '') return '';
		$dom = new DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$dom->loadHTML('<?xml encoding="utf-8"?><div id="nuCleanRoot">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$root = null;
		foreach ($dom->getElementsByTagName('div') as $d) { if ($d->getAttribute('id') === 'nuCleanRoot') { $root = $d; break; } }
		if (!$root) return h(strip_tags($html));
		self::walk($root);
		$out = '';
		foreach ($root->childNodes as $c) $out .= $dom->saveHTML($c);
		return trim($out);
	}

	private static function walk(DOMNode $node): void
	{
		foreach (iterator_to_array($node->childNodes) as $c) {
			if ($c instanceof DOMElement) {
				$tag = strtolower($c->tagName);
				if (in_array($tag, self::DROP, true)) { $node->removeChild($c); continue; }
				self::walk($c);
				if (!in_array($tag, self::TAGS, true)) {           // unwrap: keep the children
					while ($c->firstChild) $node->insertBefore($c->firstChild, $c);
					$node->removeChild($c);
					continue;
				}
				$href = $tag === 'a' ? self::safeHref($c->getAttribute('href')) : '';
				foreach (iterator_to_array($c->attributes) as $a) $c->removeAttribute($a->nodeName);
				if ($tag === 'a') {
					if ($href === '') {                               // a link without a usable target becomes its text
						while ($c->firstChild) $node->insertBefore($c->firstChild, $c);
						$node->removeChild($c);
						continue;
					}
					$c->setAttribute('href', $href);
					$c->setAttribute('target', '_blank');
					$c->setAttribute('rel', 'noopener noreferrer');
				}
			} elseif ($c instanceof DOMText) {
				continue;
			} else {
				$node->removeChild($c);                               // comments, processing instructions, CDATA
			}
		}
	}

	/** http(s)://…, mailto:…, index.php…, ?… – otherwise ''. */
	public static function safeHref(string $href): string
	{
		$href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$href = (string)preg_replace('/[\x00-\x20\x7f]+/', '', $href) === $href ? $href : '';
		if ($href === '' || strlen($href) > 1000) return '';
		return preg_match('~^(https?://[^\s"<>]+|mailto:[^\s"<>]+|index\.php(\?[^\s"<>]*)?|\?[^\s"<>]+)$~i', $href) ? $href : '';
	}

	/** Plain text of HTML: block ends and <br> become line breaks. */
	public static function toText(string $html): string
	{
		$s = (string)preg_replace('~<br\s*/?>~i', "\n", $html);
		$s = (string)preg_replace('~</(p|li|h[1-6]|blockquote|div)>~i', "\n", $s);
		$s = (string)preg_replace('~<li[^>]*>~i', '• ', $s);
		$s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$s = (string)preg_replace("~[ \t]+\n~", "\n", $s);
		return trim((string)preg_replace("~\n{3,}~", "\n\n", $s));
	}

	/** Elements a list cell or column title may hold (0.8.945, nuBuilder Forte draws them as HTML): icons, badges, simple formatting, links, images. */
	public const CELL_TAGS = ['i', 'span', 'b', 'strong', 'em', 'u', 's', 'small', 'sub', 'sup', 'mark', 'br', 'img', 'a', 'div', 'p', 'code', 'kbd', 'abbr', 'del', 'ins'];
	private const CELL_ATTRS = ['class', 'title', 'style', 'alt', 'width', 'height', 'aria-hidden', 'aria-label', 'role'];

	/**
	 * A list cell value or column title as safe HTML (0.8.945): plain text stays escaped text; a value with tags or entities keeps the
	 * elements of CELL_TAGS with class / title / a checked style, <a href> (safeHref or a relative path, target _blank / _self) and
	 * <img src> (http(s), data:image, a relative path) – scripts, event attributes and everything else are removed.
	 */
	public static function cell(string $v): string
	{
		if (!self::looksHtml($v)) return h($v);
		$dom = new DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$dom->loadHTML('<?xml encoding="utf-8"?><div id="nuCleanRoot">' . $v . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$root = null;
		foreach ($dom->getElementsByTagName('div') as $d) { if ($d->getAttribute('id') === 'nuCleanRoot') { $root = $d; break; } }
		if (!$root) return h(strip_tags($v));
		self::walkCell($root);
		$out = '';
		foreach ($root->childNodes as $c) $out .= $dom->saveHTML($c);
		return $out;
	}

	/** Text of a cell value / title for attributes, labels and exports (0.8.945): tags removed only when it looks like HTML. */
	public static function cellText(string $v): string
	{
		return self::looksHtml($v) ? trim(html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : $v;
	}

	/** A tag (<x …>, </x>, <!--) or an entity – "a < b" or "AT&T" stay text. */
	public static function looksHtml(string $v): bool
	{
		return strpbrk($v, '<&') !== false && (bool)preg_match('~<(?:[a-z][a-z0-9]*[\s/>]|/[a-z]|!--)|&(?:#\d+|#x[0-9a-f]+|[a-z][a-z0-9]{1,30});~i', $v);
	}

	private static function walkCell(DOMNode $node): void
	{
		foreach (iterator_to_array($node->childNodes) as $c) {
			if ($c instanceof DOMElement) {
				$tag = strtolower($c->tagName);
				if (in_array($tag, self::DROP, true) && $tag !== 'img') { $node->removeChild($c); continue; }
				self::walkCell($c);
				if (!in_array($tag, self::CELL_TAGS, true)) {
					while ($c->firstChild) $node->insertBefore($c->firstChild, $c);
					$node->removeChild($c);
					continue;
				}
				$keep = [];
				foreach (self::CELL_ATTRS as $a) if ($c->hasAttribute($a)) $keep[$a] = $c->getAttribute($a);
				if (isset($keep['style'])) { $st = self::safeStyle($keep['style']); if ($st === '') unset($keep['style']); else $keep['style'] = $st; }
				foreach (['width', 'height'] as $a) if (isset($keep[$a]) && !preg_match('~^\d{1,4}(px|%)?$~', $keep[$a])) unset($keep[$a]);
				$href = $tag === 'a' ? self::safeLink($c->getAttribute('href')) : '';
				$src = $tag === 'img' ? self::safeSrc($c->getAttribute('src')) : '';
				$target = $tag === 'a' ? strtolower($c->getAttribute('target')) : '';
				foreach (iterator_to_array($c->attributes) as $a) $c->removeAttribute($a->nodeName);
				if ($tag === 'img' && $src === '') { $node->removeChild($c); continue; }
				foreach ($keep as $a => $val) $c->setAttribute($a, $val);
				if ($tag === 'img') $c->setAttribute('src', $src);
				if ($tag === 'a') {
					if ($href === '') { while ($c->firstChild) $node->insertBefore($c->firstChild, $c); $node->removeChild($c); continue; }
					$c->setAttribute('href', $href);
					if ($target === '_blank') { $c->setAttribute('target', '_blank'); $c->setAttribute('rel', 'noopener noreferrer'); }
				}
			} elseif (!($c instanceof DOMText)) {
				$node->removeChild($c);
			}
		}
	}

	/** safeHref, or a relative path / anchor without a scheme. */
	private static function safeLink(string $href): string
	{
		$ok = self::safeHref($href);
		if ($ok !== '') return $ok;
		$h = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		return $h !== '' && strlen($h) <= 1000 && preg_match('~^(#[\w-]*|[\w./%-]+(\?[^\s"<>]*)?(#[\w-]*)?)$~', $h) && !preg_match('~^//~', $h) ? $h : '';
	}

	/** http(s), data:image (raster) base64, or a relative path. */
	private static function safeSrc(string $src): string
	{
		$s = trim($src);
		if ($s === '' || strlen($s) > 200000) return '';
		if (preg_match('~^data:image/(png|gif|jpe?g|webp|bmp);base64,[a-z0-9+/=\s]+$~i', $s)) return $s;
		if (preg_match('~^https?://[^\s"<>]+$~i', $s)) return $s;
		return preg_match('~^[\w./%-]+(\?[^\s"<>]*)?$~', $s) && !str_starts_with($s, '//') ? $s : '';
	}

	/** Inline style without urls, expressions, imports or escapes. */
	private static function safeStyle(string $st): string
	{
		$st = trim($st);
		if ($st === '' || strlen($st) > 500 || preg_match('~url\s*\(|expression|javascript:|@import|behavior|-moz-binding|[\\\\<>]~i', $st)) return '';
		return $st;
	}
}
