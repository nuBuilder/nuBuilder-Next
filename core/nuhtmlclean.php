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
}
