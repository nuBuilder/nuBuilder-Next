<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Reading SQL files statement by statement – one splitter for everything nuBuilder Next imports:
 *  - split(): SQL text → statements (NuInstall::splitSql uses it);
 *  - statements(): a .sql / .sql.gz / .zip file read line by line, so its size does not matter (the memory holds one statement);
 *  - run(): every statement of a file executed, with a progress callback.
 * Understands what dumps contain: strings ('…', "…", with \ escapes on MySQL and doubled quotes), quoted names (`…`, SQL Server […]),
 * comments (--, # on MySQL, /* … *\/ – MySQL's /*! … *\/ stays code), DELIMITER lines of the mysql client (routines, triggers and events
 * of a dump are written between "DELIMITER $$" … "$$") and, for SQL Server, GO lines between batches.
 */
final class NuSqlImport
{
	private string $dialect;
	private string $delim = ';';
	private string $buf = '';
	private string $q = '';          // closing character of the open string / quoted name, '' outside
	private bool $block = false;     // inside /* … */
	/** @var list<string> */
	private array $out = [];

	private function __construct(string $dialect)
	{
		$this->dialect = $dialect === 'sqlsrv' ? 'sqlsrv' : 'mysql';
	}

	/** SQL text → statements (trimmed, without the delimiter; comments removed, /*! … *\/ kept). @return list<string> */
	public static function split(string $sql, string $dialect = 'mysql'): array
	{
		$s = new self($dialect);
		$len = strlen($sql);
		$pos = 0;
		while ($pos < $len) {
			$e = strpos($sql, "\n", $pos);
			$line = $e === false ? substr($sql, $pos) : substr($sql, $pos, $e - $pos + 1);
			$pos = $e === false ? $len : $e + 1;
			$s->feed($line);
		}
		$s->finish();
		return $s->out;
	}

	/**
	 * The statements of a file, read line by line: .sql, .sql.gz, or a .zip with nubuilder4.sql / its only .sql file.
	 * $progress(int done, int total) is called about every MB (bytes of the SQL text; total 0 = unknown).
	 * @return Generator<int, string>
	 */
	public static function statements(string $path, string $dialect = 'mysql', ?callable $progress = null): Generator
	{
		[$h, $kind, $total, $zip] = self::open($path);
		$s = new self($dialect);
		$done = 0; $next = 1 << 20;
		try {
			while (true) {
				$line = $kind === 'gz' ? gzgets($h) : fgets($h);
				if ($line === false) break;
				$done += strlen($line);
				$s->feed($line);
				if ($s->out) { foreach ($s->out as $st) yield $st; $s->out = []; }
				if ($progress && $done >= $next) { $progress($done, $total); $next = $done + (1 << 20); }
			}
			$s->finish();
			foreach ($s->out as $st) yield $st;
			if ($progress) $progress($done, max($total, $done));
		} finally {
			if ($kind === 'gz') gzclose($h); else fclose($h);
			if ($zip) $zip->close();
		}
	}

	/**
	 * Runs every statement of a file on $db. $engines: MyISAM tables become InnoDB (imports of nuBuilder Forte dumps – NuInstall::importSql).
	 * An error names the file and the statement. @return int statements run
	 */
	public static function run(NuDB $db, string $path, ?callable $progress = null, bool $engines = false): int
	{
		$n = 0;
		nu_time_limit(0);
		// a dump changes the session (sql_mode, time zone, autocommit): put back afterwards – the request goes on with this connection
		$saved = $db->type === 'mysql' ? $db->pdo()->query('SELECT @@SESSION.sql_mode, @@SESSION.time_zone, @@SESSION.autocommit')->fetch(PDO::FETCH_NUM) : null;
		try {
			foreach (self::statements($path, $db->type, $progress) as $stmt) {
				if ($engines && $db->type === 'mysql') $stmt = (string)preg_replace('/\bENGINE\s*=\s*MyISAM\b/i', 'ENGINE=InnoDB', $stmt);
				try {
					$db->exec($stmt);
				} catch (PDOException $e) {
					throw new RuntimeException(basename($path) . ', ' . t('ins_err_statement', $n + 1) . ': ' . $e->getMessage() . ' — ' . mb_substr(trim($stmt), 0, 160), 0, $e);
				}
				$n++;
			}
		} finally {
			if ($saved) {
				$p = $db->pdo();
				try { $p->exec('SET SESSION sql_mode = ' . $p->quote((string)$saved[0]) . ', time_zone = ' . $p->quote((string)$saved[1]) . ', autocommit = '
					. ((int)$saved[2] ? 1 : 0)); } catch (PDOException $ig) { nu_ignored($ig); }
			}
		}
		return $n;
	}

	/**
	 * Resumable import (Reset database in steps): runs the statements of a PLAIN .sql file from byte $st['off'] on until the
	 * clock passes $until, then stops at the end of a line where no statement is open. $st (kept by the caller between requests):
	 * off = bytes done, n = statements run, delim = the DELIMITER in force, sets = the SET statements of the dump so far – they are
	 * session settings (time zone, sql_mode NO_AUTO_VALUE_ON_ZERO, character set …) and are run again at the start of the next slice,
	 * which has a new connection. Each slice ends with COMMIT (dumps may switch autocommit off) and UNLOCK TABLES.
	 * @param array<string, mixed> $st
	 * @return bool true = the file is done
	 */
	public static function slice(NuDB $db, string $file, array &$st, float $until, bool $engines = false): bool
	{
		$h = @fopen($file, 'rb');
		if (!$h) throw new RuntimeException(t('ins_dump_err_read'));
		$off = (int)($st['off'] ?? 0);
		$st['n'] = (int)($st['n'] ?? 0);
		$sets = array_values(array_filter((array)($st['sets'] ?? []), 'is_string'));
		$s = new self($db->type);
		$s->delim = (string)($st['delim'] ?? ';') ?: ';';
		$run = function (string $stmt) use ($db, $file, &$st, &$sets, $engines): void {
			if ($engines && $db->type === 'mysql') $stmt = (string)preg_replace('/\bENGINE\s*=\s*MyISAM\b/i', 'ENGINE=InnoDB', $stmt);
			try {
				$db->exec($stmt);
			} catch (PDOException $e) {
				throw new RuntimeException(basename($file) . ', ' . t('ins_err_statement', $st['n'] + 1) . ': ' . $e->getMessage() . ' — ' . mb_substr(trim($stmt), 0, 160), 0, $e);
			}
			$st['n']++;
			if (preg_match('#^(/\*!\d+\s*)?SET\s#i', $stmt)) {
				$k = array_search($stmt, $sets, true);
				if ($k !== false) array_splice($sets, (int)$k, 1);
				$sets[] = $stmt;
				if (count($sets) > 200) array_shift($sets);
			}
		};
		try {
			if ($off > 0 && fseek($h, $off) !== 0) throw new RuntimeException(t('ins_dump_err_read'));
			foreach ($sets as $q) { try { $db->exec($q); } catch (PDOException $ig) { nu_ignored($ig); } }
			$done = true;
			while (($line = fgets($h)) !== false) {
				$off += strlen($line);
				$s->feed($line);
				foreach ($s->out as $stmt) $run($stmt);
				$s->out = [];
				if ($s->q === '' && !$s->block && trim($s->buf) === '' && microtime(true) >= $until) { $done = false; break; }
			}
			if ($done) {
				$s->finish();
				foreach ($s->out as $stmt) $run($stmt);
				$s->out = [];
			}
			$st['off'] = $off;
			$st['delim'] = $s->delim;
			$st['sets'] = $sets;
			return $done;
		} finally {
			fclose($h);
			if ($db->type === 'mysql') {
				try { $db->exec('COMMIT'); } catch (PDOException $ig) { nu_ignored($ig); }
				try { $db->exec('UNLOCK TABLES'); } catch (PDOException $ig) { nu_ignored($ig); }
			}
		}
	}

	/**
	 * A .sql.gz or .zip as a plain .sql file $dest (for slice(), which needs to seek); a plain .sql stays where it is.
	 * @return string the plain file
	 */
	public static function plainCopy(string $path, string $dest): string
	{
		$name = strtolower($path);
		if (!str_ends_with($name, '.zip') && !str_ends_with($name, '.gz')) return $path;
		[$h, $kind, , $zip] = self::open($path);
		$out = @fopen($dest . '.part', 'wb');
		try {
			if (!$out) throw new RuntimeException(t('ins_dump_err_read'));
			while (true) {
				$buf = $kind === 'gz' ? gzread($h, 1 << 20) : fread($h, 1 << 20);
				if ($buf === false || $buf === '') break;
				if (fwrite($out, $buf) !== strlen($buf)) throw new RuntimeException(t('dbr_err_unpack'));
			}
		} finally {
			if ($out) fclose($out);
			if ($kind === 'gz') gzclose($h); else fclose($h);
			if ($zip) $zip->close();
		}
		if (!@rename($dest . '.part', $dest)) { @unlink($dest . '.part'); throw new RuntimeException(t('ins_dump_err_read')); }
		return $dest;
	}

	/** Size of the SQL text of a file (bytes; .sql.gz: the size gzip noted, modulo 4 GB) – for messages and progress. */
	public static function size(string $path): int
	{
		try { [$h, $kind, $total, $zip] = self::open($path); } catch (Throwable) { return 0; }
		if ($kind === 'gz') gzclose($h); else fclose($h);
		if ($zip) $zip->close();
		return $total;
	}

	/** @return array{0: resource, 1: string, 2: int, 3: ?ZipArchive} handle, 'gz' | 'stream', size of the SQL text (0 = unknown), the open archive */
	private static function open(string $path)
	{
		$name = strtolower($path);
		if (str_ends_with($name, '.zip')) {
			if (!class_exists('ZipArchive')) throw new RuntimeException(t('ins_dump_err_read'));
			$z = new ZipArchive();
			if ($z->open($path) !== true) throw new RuntimeException(t('ins_dump_err_read'));
			$pick = self::zipPick($z);
			$st = $pick !== null ? $z->statIndex($pick) : false;
			$h = $st ? $z->getStream((string)$st['name']) : false;
			if (!$h) { $z->close(); throw new RuntimeException(t('ins_dump_err_read')); }
			return [$h, 'stream', (int)$st['size'], $z];
		}
		if (!function_exists('gzopen')) {
			$h = @fopen($path, 'rb');
			if (!$h) throw new RuntimeException(t('ins_dump_err_read'));
			return [$h, 'stream', (int)@filesize($path), null];
		}
		$h = @gzopen($path, 'rb');                            // reads plain files too
		if (!$h) throw new RuntimeException(t('ins_dump_err_read'));
		$total = (int)@filesize($path);
		if (str_ends_with($name, '.gz') && $total >= 18 && ($f = @fopen($path, 'rb'))) {
			fseek($f, -4, SEEK_END);
			$isize = unpack('V', (string)fread($f, 4));
			fclose($f);
			$total = (int)($isize[1] ?? 0);
		}
		return [$h, 'gz', $total, null];
	}

	/** Entry of a zip with the SQL: nubuilder4.sql, otherwise the only .sql file; null = none. */
	public static function zipPick(ZipArchive $z): ?int
	{
		$pick = null; $sqls = [];
		for ($i = 0; $i < $z->numFiles; $i++) {
			$n = (string)$z->getNameIndex($i);
			if (!preg_match('/\.sql$/i', $n)) continue;
			$sqls[] = $i;
			if (strtolower(basename($n)) === 'nubuilder4.sql') $pick = $i;
		}
		return $pick ?? (count($sqls) === 1 ? $sqls[0] : null);
	}

	// ------------------------------------------------------------------ the scanner

	private function emit(): void
	{
		$s = self::plainCreate(trim($this->buf));
		if ($s !== '') $this->out[] = $s;
		$this->buf = '';
	}

	/**
	 * views, triggers and routines as mysqldump writes them – "/*!50001 CREATE ALGORITHM=… *\/ /*!50013 DEFINER=`u`@`h` SQL SECURITY
	 * DEFINER *\/ /*!50001 VIEW … *\/" – become one plain CREATE without the DEFINER clause: the object then belongs to the user who imports it
	 * (a definer that does not exist on this server, or needs SUPER, would stop the import) and the dump check accepts it.
	 */
	public static function plainCreate(string $s): string
	{
		if (!preg_match('#^(/\*!\d+\s*)?CREATE\b#i', $s)) return $s;
		if ($s[0] === '/') $s = trim((string)preg_replace(['#/\*!\d+\s?#', '#\s?\*/#'], ['', ''], $s));
		return (string)preg_replace('/\bDEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|[\w.%-]+)@(`[^`]*`|\'[^\']*\'|[\w.%-]+)\s*/i', '', $s, 1);
	}

	private function finish(): void
	{
		$this->emit();
	}

	/** One line (with its "\n"). Strings and block comments may span lines; the delimiter and comments are found outside them. */
	private function feed(string $line): void
	{
		$my = $this->dialect === 'mysql';
		if ($this->q === '' && !$this->block && trim($this->buf) === '') {
			if ($my && preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m)) { $this->delim = $m[1]; $this->buf = ''; return; }
		}
		if (!$my && $this->q === '' && !$this->block && preg_match('/^\s*GO\s*$/i', $line)) { $this->emit(); return; }
		$len = strlen($line);
		$d = $this->delim;
		$dl = strlen($d);
		// characters that may start something: quotes, comments, the delimiter
		$stops = "'\"-/" . ($my ? '`#' : '[') . $d[0];
		$i = 0;
		while ($i < $len) {
			if ($this->block) {
				$e = strpos($line, '*/', $i);
				if ($e === false) return;
				$this->block = false;
				$this->buf .= ' ';
				$i = $e + 2;
				continue;
			}
			if ($this->q !== '') {
				$q = $this->q;
				$esc = $my && $q !== '`';
				$k = $i + strcspn($line, $esc ? $q . '\\' : $q, $i);
				$this->buf .= substr($line, $i, $k - $i);
				if ($k >= $len) return;
				$c = $line[$k];
				if ($c === '\\') { $this->buf .= substr($line, $k, 2); $i = $k + 2; continue; }
				if ($k + 1 < $len && $line[$k + 1] === $q) { $this->buf .= $q . $q; $i = $k + 2; continue; }   // doubled quote
				$this->buf .= $c;
				$this->q = '';
				$i = $k + 1;
				continue;
			}
			$k = $i + strcspn($line, $stops, $i);
			$this->buf .= substr($line, $i, $k - $i);
			if ($k >= $len) return;
			$i = $k;
			$c = $line[$i];
			if (substr_compare($line, $d, $i, $dl) === 0) { $this->emit(); $i += $dl; continue; }
			if ($c === "'" || $c === '"' || ($my && $c === '`')) { $this->q = $c; $this->buf .= $c; $i++; continue; }
			if (!$my && $c === '[') { $this->q = ']'; $this->buf .= $c; $i++; continue; }
			if ($c === '-' && ($line[$i + 1] ?? '') === '-' && ($i + 2 >= $len || ctype_space($line[$i + 2]))) { $this->buf .= "\n"; return; }
			if ($my && $c === '#') { $this->buf .= "\n"; return; }
			if ($c === '/' && ($line[$i + 1] ?? '') === '*' && ($line[$i + 2] ?? '') !== '!') { $this->block = true; $i += 2; continue; }
			$this->buf .= $c;
			$i++;
		}
	}
}
