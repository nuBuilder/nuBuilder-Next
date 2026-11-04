<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * A small Markdown → HTML converter (0.8.874) for report fields with the content type Markdown – no library. The text is escaped
 * first, so HTML in the Markdown is shown as text, never run. Supported: headings (# … ######), paragraphs, line breaks (a single
 * newline), **bold** / __bold__, *italic* / _italic_, ~~strike~~, `code`, [links](https://…) (http, https, mailto only), lists with
 * - * + and 1. (one level of nesting by indentation), > quotes, --- rules, tables (| a | b | with a |---| line), fenced ``` code.
 * The HTML is the subset TCPDF prints (h1–h6, p, br, b, i, del, a, ul / ol / li, blockquote, hr, table, pre / tt).
 */
final class NuMarkdown
{
	public static function toHtml(string $md): string
	{
		$md = str_replace(["\r\n", "\r"], "\n", $md);
		$lines = explode("\n", $md);
		$out = [];
		$para = [];
		$n = count($lines);
		$flush = static function () use (&$para, &$out): void {
			if ($para) $out[] = '<p>' . implode('<br>', array_map([self::class, 'inline'], $para)) . '</p>';
			$para = [];
		};
		for ($i = 0; $i < $n; $i++) {
			$line = $lines[$i];
			$t = trim($line);
			if ($t === '') { $flush(); continue; }
			if (preg_match('/^(```|~~~)/', $t, $fm)) {                 // fenced code
				$flush();
				$code = [];
				for ($i++; $i < $n && !str_starts_with(trim($lines[$i]), $fm[1]); $i++) $code[] = $lines[$i];
				$out[] = '<pre>' . h(implode("\n", $code)) . '</pre>';
				continue;
			}
			if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/', $t, $m)) { $flush(); $l = strlen($m[1]); $out[] = "<h$l>" . self::inline($m[2]) . "</h$l>"; continue; }
			if (preg_match('/^([-*_])(\s*\1){2,}$/', $t)) { $flush(); $out[] = '<hr>'; continue; }
			if (str_starts_with($t, '>')) {                           // quote: the following > lines too
				$flush();
				$q = [];
				for (; $i < $n && str_starts_with(trim($lines[$i]), '>'); $i++) $q[] = ltrim(substr(trim($lines[$i]), 1));
				$i--;
				$out[] = '<blockquote>' . self::toHtml(implode("\n", $q)) . '</blockquote>';
				continue;
			}
			if (str_starts_with($t, '|') && $i + 1 < $n && preg_match('/^\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?$/', trim($lines[$i + 1]))) {
				$flush();
				$cells = static fn(string $row): array => array_map('trim', explode('|', trim(trim($row), '|')));
				$aligns = array_map(static fn($c) => str_starts_with($c, ':') && str_ends_with($c, ':') ? 'center' : (str_ends_with($c, ':') ? 'right' : 'left'), $cells($lines[$i + 1]));
				$html = '<table border="1" cellpadding="3"><tr>';
				foreach ($cells($t) as $k => $c) $html .= '<th align="' . ($aligns[$k] ?? 'left') . '"><b>' . self::inline($c) . '</b></th>';
				$html .= '</tr>';
				for ($i += 2; $i < $n && str_starts_with(trim($lines[$i]), '|'); $i++) {
					$html .= '<tr>';
					foreach ($cells($lines[$i]) as $k => $c) $html .= '<td align="' . ($aligns[$k] ?? 'left') . '">' . self::inline($c) . '</td>';
					$html .= '</tr>';
				}
				$i--;
				$out[] = $html . '</table>';
				continue;
			}
			if (preg_match('/^\s*([-*+]|\d+[.)])\s+/', $line)) {     // a list (one level of nesting)
				$flush();
				[$html, $i] = self::list($lines, $i);
				$out[] = $html;
				continue;
			}
			$para[] = $t;
		}
		$flush();
		return implode("\n", $out);
	}

	/** @return array{0: string, 1: int} the list's HTML and the index of its last line */
	private static function list(array $lines, int $i): array
	{
		$n = count($lines);
		preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $lines[$i], $m);
		$indent = strlen(str_replace("\t", '    ', $m[1]));
		$tag = ctype_digit(rtrim($m[2], '.)')) ? 'ol' : 'ul';
		$html = "<$tag>";
		$open = false;
		for (; $i < $n; $i++) {
			$line = $lines[$i];
			if (trim($line) === '') { if ($i + 1 < $n && preg_match('/^\s*([-*+]|\d+[.)])\s+/', $lines[$i + 1])) continue; break; }
			if (!preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $line, $mm)) {   // a continuation line of the item
				if ($open && preg_match('/^\s+\S/', $line)) { $html .= '<br>' . self::inline(trim($line)); continue; }
				break;
			}
			$in = strlen(str_replace("\t", '    ', $mm[1]));
			if ($in > $indent && $open) {                              // nested list inside the open item
				[$sub, $i] = self::list($lines, $i);
				$html .= $sub;
				continue;
			}
			if ($in < $indent) break;
			if ((ctype_digit(rtrim($mm[2], '.)')) ? 'ol' : 'ul') !== $tag) break;   // the other kind of list starts a new one
			if ($open) $html .= '</li>';
			$html .= '<li>' . self::inline($mm[3]);
			$open = true;
		}
		if ($open) $html .= '</li>';
		return [$html . "</$tag>", $i - 1];
	}

	/** Inline Markdown of one escaped line. */
	public static function inline(string $t): string
	{
		$t = h($t);
		$codes = [];
		$t = (string)preg_replace_callback('/`([^`]+)`/', static function ($m) use (&$codes) { $codes[] = '<font face="courier">' . $m[1] . '</font>'; return "\x01" . (count($codes) - 1) . "\x01"; }, $t);
		$t = (string)preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function ($m) {
			$url = html_entity_decode($m[2], ENT_QUOTES);
			return preg_match('~^(https?://|mailto:)~i', $url) ? '<a href="' . h($url) . '">' . $m[1] . '</a>' : $m[1];
		}, $t);
		$t = (string)preg_replace('/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/', '<b>$2</b>', $t);
		$t = (string)preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?!\*)/', '<i>$1</i>', $t);
		$t = (string)preg_replace('/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/', '<i>$1</i>', $t);
		$t = (string)preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/', '<del>$1</del>', $t);
		return (string)preg_replace_callback("/\x01(\\d+)\x01/", static fn($m) => $codes[(int)$m[1]] ?? '', $t);
	}
}
