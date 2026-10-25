<?php
defined('NU_PAGE') || exit;

/** nuFiles – texts in English and German ("du"); de-CH gets ss instead of ß. Placeholders %1, %2 … */
final class NufI18n
{
	private const EN = [
		'title' => 'Files', 'shares' => 'Shares', 'trash' => 'Recycle bin', 'log' => 'Log', 'upload' => 'Upload', 'new_folder' => 'New folder',
		'new_file' => 'New file', 'refresh' => 'Refresh', 'search_ph' => 'Search names (* and ? allowed)', 'name' => 'Name', 'size' => 'Size',
		'modified' => 'Modified', 'actions' => 'Actions', 'open' => 'Open', 'preview' => 'Preview', 'edit' => 'Edit', 'download' => 'Download',
		'download_zip' => 'Download as zip', 'rename' => 'Rename', 'copy' => 'Copy to …', 'move' => 'Move to …', 'delete' => 'Delete', 'zip' => 'Create zip',
		'unzip' => 'Extract', 'restore' => 'Restore', 'purge' => 'Delete for good', 'purge_all' => 'Empty recycle bin', 'empty_folder' => 'This folder is empty.',
		'select_all' => 'Select all', 'selected_n' => '%1 selected', 'confirm_delete_n' => 'Delete %1 item(s)?', 'confirm_delete_trash' => 'They go to the recycle bin.',
		'confirm_delete_final' => 'This cannot be undone.', 'prompt_folder' => 'Name of the new folder', 'prompt_file' => 'Name of the new file',
		'prompt_rename' => 'New name', 'prompt_zip' => 'Name of the zip file', 'save' => 'Save', 'saved' => 'Saved', 'cancel' => 'Cancel', 'close' => 'Close',
		'ok' => 'OK', 'overwrite_existing' => 'Replace files with the same name', 'uploading' => 'Uploading', 'upload_done' => 'Uploaded',
		'upload_failed' => 'Upload failed', 'drop_here' => 'Drop files here to upload', 'conflict_title' => 'Changed meanwhile',
		'conflict_text' => 'Someone changed this file after you opened it. Reload it (your changes are lost) or overwrite it with your version?',
		'reload' => 'Reload', 'overwrite' => 'Overwrite', 'lint_title' => 'Syntax error', 'lint_text' => 'Line %1: %2', 'save_anyway' => 'Save anyway',
		'unsaved' => 'You have unsaved changes. Close anyway?', 'versions' => 'Versions', 'restore_version' => 'Load this version', 'no_versions' => 'No older versions.',
		'target' => 'Target', 'choose_folder' => 'Choose a folder', 'here' => 'Here', 'up' => 'Up', 'root' => 'Root', 'no_shares' => 'No shares are available to you.',
		'problems' => 'Settings problems (only you see this)', 'readonly_badge' => 'read only', 'code_badge' => 'code', 'search_results' => 'Search results for “%1”',
		'partial_results' => 'Only the first results are shown – refine your search.', 'deleted_at' => 'Deleted', 'deleted_by' => 'By', 'original_path' => 'Original place',
		'items' => 'Items', 'files_skipped' => '%1 entries were skipped', 'nothing_selected' => 'Nothing selected.', 'unzip_done' => '%1 file(s) extracted to “%2”.',
		'zip_done' => 'Created “%1”.', 'moved_n' => '%1 item(s) moved', 'copied_n' => '%1 item(s) copied', 'deleted_n' => '%1 item(s) deleted',
		'restored' => 'Restored to “%1”', 'purged' => 'Deleted for good', 'log_when' => 'When', 'log_user' => 'User', 'log_action' => 'Action', 'log_path' => 'Path',
		'log_target' => 'Target', 'log_ok' => 'OK', 'free_space' => '%1 free', 'entries_truncated' => 'Only the first %1 entries are shown.',
		'preview_unavailable' => 'No preview for this file type – download it instead.', 'text_too_big' => 'The file is too large for the editor.',
		'binary_file' => 'This is not a text file.', 'encoding_file' => 'The file is not UTF-8 – open it in a desktop editor.', 'shortcut_hint' => 'Ctrl+S saves',
		'empty_trash' => 'The recycle bin is empty.', 'readonly_mode' => 'Read only – changes are not possible here.', 'reauth_needed' => 'Please confirm your password first.',
		'skipped_reason' => 'Skipped', 'details' => 'Details', 'filter' => 'Filter', 'all_shares' => 'All shares', 'loading' => 'Loading …', 'unknown_error' => 'Something went wrong.',
		'path_copied' => 'Path copied', 'copy_path' => 'Copy path', 'info' => 'Info', 'permissions' => 'Permissions', 'type' => 'Type', 'folder' => 'Folder', 'file' => 'File',
		'link_outside' => 'Link to a place outside this share', 'refresh_done' => 'Updated', 'chunk_retry' => 'Connection problem – retrying …',
		'absolute_path' => 'Path on the server', 'count_line' => '%1 folder(s), %2 file(s), %3', 'n_entries' => '%1 entries', 'n_files' => '%1 files',
		'no_results' => 'Nothing found.', 'reauth_ok' => 'Password confirmed', 'reauth_ok_close' => 'Password confirmed – this window closes itself (or close it).',
		'reauth_open' => 'Confirm password', 'reauth_title' => 'Password confirmation', 'retry' => 'Try again',
		'reauth_text' => 'Code folders hold the program of this server. Please confirm your password (a small window opens) – then nuFiles goes on.',
		'replaced' => 'replaced', 'trash_days' => 'Entries older than %1 days are deleted for good automatically.', 'version_loaded' => 'Older version loaded – Save writes it back.',
		'wrap' => 'Wrap lines',

		'err_path' => 'Invalid path.', 'err_name_empty' => 'Please enter a name.', 'err_name_long' => 'The name is too long (255 bytes at most).',
		'err_name_chars' => 'The name contains characters that are not allowed (/, \\, control characters).', 'err_name_edges' => 'The name must not start or end with a space or end with a dot.',
		'err_name_reserved' => 'This name is reserved.', 'err_name_dot' => 'Names starting with a dot are not allowed in this share.',
		'err_name_dangerous' => 'Files of type .%1 are not allowed here (a web server could run them).', 'err_name_web' => 'Files of type .%1 are not allowed in this share (web content).',
		'err_name_ext' => 'Files of type .%1 are not allowed here. Allowed: %2', 'err_root' => 'The share itself cannot be changed.', 'err_not_found' => 'Not found.',
		'err_outside' => 'This place lies outside the share.', 'err_not_folder' => 'This is not a folder.', 'err_not_file' => 'This is not a file.',
		'err_exists' => '“%1” already exists.', 'err_content_php' => 'The file contains PHP code – not allowed in this share.',
		'err_content_web' => 'The file is web content (%1) – not allowed in this share.', 'err_share' => 'This share does not exist or you have no access.',
		'err_share_broken' => 'The folder of this share is not available.', 'err_readonly' => 'This share is read only.', 'err_right' => 'You are not allowed to do this here (%1).',
		'err_read' => 'The folder cannot be read.', 'err_search' => 'Please enter a search term (200 characters at most).', 'err_write' => 'The change could not be written (permissions of the server?).',
		'err_into_self' => 'A folder cannot be moved or copied into itself.', 'err_link_in_tree' => 'Links (symlinks) are not copied, moved or extracted.',
		'err_too_many' => 'Too many entries.', 'err_too_big' => 'Too large.', 'err_conflict' => 'The file was changed by someone else.',
		'err_too_big_upload' => 'The file is too large (at most %1 MB).', 'err_disk_full' => 'Not enough free disk space.', 'err_data_dir' => 'The data folder of nuFiles is missing.',
		'err_upload_ticket' => 'The upload expired – please start it again.', 'err_upload_php' => 'The server refused the upload (PHP error %1).',
		'err_upload_chunk' => 'Invalid part of the upload.', 'err_upload_offset' => 'Upload out of step – resuming.', 'err_no_zip' => 'Zip is not available on this server (PHP zip extension).',
		'err_zip_open' => 'The archive cannot be opened.', 'err_zip_size' => 'Entry size does not match the archive.', 'err_lint' => 'Syntax error.',
		'err_not_text' => 'This file cannot be edited as text.', 'err_readonly_mode' => 'Read only mode – changes are not possible.', 'err_reauth' => 'Please confirm your password.',
		'err_admin' => 'Only the globeadmin may do this.', 'err_bad_request' => 'Invalid request.', 'err_server' => 'Server error.',
	];

	private const DE = [
		'title' => 'Dateien', 'shares' => 'Freigaben', 'trash' => 'Papierkorb', 'log' => 'Protokoll', 'upload' => 'Hochladen', 'new_folder' => 'Neuer Ordner',
		'new_file' => 'Neue Datei', 'refresh' => 'Aktualisieren', 'search_ph' => 'Namen suchen (* und ? erlaubt)', 'name' => 'Name', 'size' => 'Grösse',
		'modified' => 'Geändert', 'actions' => 'Aktionen', 'open' => 'Öffnen', 'preview' => 'Vorschau', 'edit' => 'Bearbeiten', 'download' => 'Herunterladen',
		'download_zip' => 'Als Zip herunterladen', 'rename' => 'Umbenennen', 'copy' => 'Kopieren nach …', 'move' => 'Verschieben nach …', 'delete' => 'Löschen', 'zip' => 'Zip erstellen',
		'unzip' => 'Entpacken', 'restore' => 'Wiederherstellen', 'purge' => 'Endgültig löschen', 'purge_all' => 'Papierkorb leeren', 'empty_folder' => 'Dieser Ordner ist leer.',
		'select_all' => 'Alle auswählen', 'selected_n' => '%1 ausgewählt', 'confirm_delete_n' => '%1 Element(e) löschen?', 'confirm_delete_trash' => 'Sie kommen in den Papierkorb.',
		'confirm_delete_final' => 'Das kann nicht rückgängig gemacht werden.', 'prompt_folder' => 'Name des neuen Ordners', 'prompt_file' => 'Name der neuen Datei',
		'prompt_rename' => 'Neuer Name', 'prompt_zip' => 'Name der Zip-Datei', 'save' => 'Speichern', 'saved' => 'Gespeichert', 'cancel' => 'Abbrechen', 'close' => 'Schliessen',
		'ok' => 'OK', 'overwrite_existing' => 'Gleichnamige Dateien ersetzen', 'uploading' => 'Wird hochgeladen', 'upload_done' => 'Hochgeladen',
		'upload_failed' => 'Hochladen fehlgeschlagen', 'drop_here' => 'Dateien hierher ziehen zum Hochladen', 'conflict_title' => 'Inzwischen geändert',
		'conflict_text' => 'Jemand hat diese Datei geändert, nachdem du sie geöffnet hast. Neu laden (deine Änderungen gehen verloren) oder mit deiner Version überschreiben?',
		'reload' => 'Neu laden', 'overwrite' => 'Überschreiben', 'lint_title' => 'Syntaxfehler', 'lint_text' => 'Zeile %1: %2', 'save_anyway' => 'Trotzdem speichern',
		'unsaved' => 'Du hast ungespeicherte Änderungen. Trotzdem schliessen?', 'versions' => 'Versionen', 'restore_version' => 'Diese Version laden', 'no_versions' => 'Keine älteren Versionen.',
		'target' => 'Ziel', 'choose_folder' => 'Ordner wählen', 'here' => 'Hierhin', 'up' => 'Nach oben', 'root' => 'Stamm', 'no_shares' => 'Für dich sind keine Freigaben verfügbar.',
		'problems' => 'Probleme in den Einstellungen (nur du siehst das)', 'readonly_badge' => 'nur lesen', 'code_badge' => 'Code', 'search_results' => 'Suchergebnisse für «%1»',
		'partial_results' => 'Es werden nur die ersten Ergebnisse gezeigt – verfeinere die Suche.', 'deleted_at' => 'Gelöscht', 'deleted_by' => 'Von', 'original_path' => 'Ursprünglicher Ort',
		'items' => 'Elemente', 'files_skipped' => '%1 Einträge wurden übersprungen', 'nothing_selected' => 'Nichts ausgewählt.', 'unzip_done' => '%1 Datei(en) nach «%2» entpackt.',
		'zip_done' => '«%1» erstellt.', 'moved_n' => '%1 Element(e) verschoben', 'copied_n' => '%1 Element(e) kopiert', 'deleted_n' => '%1 Element(e) gelöscht',
		'restored' => 'Wiederhergestellt nach «%1»', 'purged' => 'Endgültig gelöscht', 'log_when' => 'Wann', 'log_user' => 'Benutzer', 'log_action' => 'Aktion', 'log_path' => 'Pfad',
		'log_target' => 'Ziel', 'log_ok' => 'OK', 'free_space' => '%1 frei', 'entries_truncated' => 'Es werden nur die ersten %1 Einträge gezeigt.',
		'preview_unavailable' => 'Für diesen Dateityp gibt es keine Vorschau – lade die Datei herunter.', 'text_too_big' => 'Die Datei ist zu gross für den Editor.',
		'binary_file' => 'Das ist keine Textdatei.', 'encoding_file' => 'Die Datei ist nicht UTF-8 – öffne sie in einem Desktop-Editor.', 'shortcut_hint' => 'Ctrl+S speichert',
		'empty_trash' => 'Der Papierkorb ist leer.', 'readonly_mode' => 'Nur lesen – hier sind keine Änderungen möglich.', 'reauth_needed' => 'Bitte bestätige zuerst dein Passwort.',
		'skipped_reason' => 'Übersprungen', 'details' => 'Details', 'filter' => 'Filter', 'all_shares' => 'Alle Freigaben', 'loading' => 'Wird geladen …', 'unknown_error' => 'Etwas ist schiefgelaufen.',
		'path_copied' => 'Pfad kopiert', 'copy_path' => 'Pfad kopieren', 'info' => 'Info', 'permissions' => 'Rechte', 'type' => 'Typ', 'folder' => 'Ordner', 'file' => 'Datei',
		'link_outside' => 'Verknüpfung auf einen Ort ausserhalb dieser Freigabe', 'refresh_done' => 'Aktualisiert', 'chunk_retry' => 'Verbindungsproblem – neuer Versuch …',
		'absolute_path' => 'Pfad auf dem Server', 'count_line' => '%1 Ordner, %2 Datei(en), %3', 'n_entries' => '%1 Einträge', 'n_files' => '%1 Dateien',
		'no_results' => 'Nichts gefunden.', 'reauth_ok' => 'Passwort bestätigt', 'reauth_ok_close' => 'Passwort bestätigt – dieses Fenster schliesst sich (oder schliesse es).',
		'reauth_open' => 'Passwort bestätigen', 'reauth_title' => 'Passwort-Bestätigung', 'retry' => 'Nochmals versuchen',
		'reauth_text' => 'Code-Ordner enthalten das Programm dieses Servers. Bitte bestätige dein Passwort (ein kleines Fenster öffnet sich) – danach geht es weiter.',
		'replaced' => 'ersetzt', 'trash_days' => 'Einträge, die älter als %1 Tage sind, werden automatisch endgültig gelöscht.', 'version_loaded' => 'Ältere Version geladen – Speichern schreibt sie zurück.',
		'wrap' => 'Zeilen umbrechen',

		'err_path' => 'Ungültiger Pfad.', 'err_name_empty' => 'Bitte gib einen Namen ein.', 'err_name_long' => 'Der Name ist zu lang (höchstens 255 Bytes).',
		'err_name_chars' => 'Der Name enthält unerlaubte Zeichen (/, \\, Steuerzeichen).', 'err_name_edges' => 'Der Name darf nicht mit einem Leerzeichen beginnen oder enden und nicht mit einem Punkt enden.',
		'err_name_reserved' => 'Dieser Name ist reserviert.', 'err_name_dot' => 'Namen, die mit einem Punkt beginnen, sind in dieser Freigabe nicht erlaubt.',
		'err_name_dangerous' => 'Dateien vom Typ .%1 sind hier nicht erlaubt (ein Webserver könnte sie ausführen).', 'err_name_web' => 'Dateien vom Typ .%1 sind in dieser Freigabe nicht erlaubt (Webinhalt).',
		'err_name_ext' => 'Dateien vom Typ .%1 sind hier nicht erlaubt. Erlaubt: %2', 'err_root' => 'Die Freigabe selbst kann nicht geändert werden.', 'err_not_found' => 'Nicht gefunden.',
		'err_outside' => 'Dieser Ort liegt ausserhalb der Freigabe.', 'err_not_folder' => 'Das ist kein Ordner.', 'err_not_file' => 'Das ist keine Datei.',
		'err_exists' => '«%1» gibt es schon.', 'err_content_php' => 'Die Datei enthält PHP-Code – in dieser Freigabe nicht erlaubt.',
		'err_content_web' => 'Die Datei ist Webinhalt (%1) – in dieser Freigabe nicht erlaubt.', 'err_share' => 'Diese Freigabe gibt es nicht oder du hast keinen Zugriff.',
		'err_share_broken' => 'Der Ordner dieser Freigabe ist nicht verfügbar.', 'err_readonly' => 'Diese Freigabe ist schreibgeschützt.', 'err_right' => 'Das darfst du hier nicht (%1).',
		'err_read' => 'Der Ordner kann nicht gelesen werden.', 'err_search' => 'Bitte gib einen Suchbegriff ein (höchstens 200 Zeichen).', 'err_write' => 'Die Änderung konnte nicht geschrieben werden (Rechte auf dem Server?).',
		'err_into_self' => 'Ein Ordner kann nicht in sich selbst verschoben oder kopiert werden.', 'err_link_in_tree' => 'Verknüpfungen (Symlinks) werden nicht kopiert, verschoben oder entpackt.',
		'err_too_many' => 'Zu viele Einträge.', 'err_too_big' => 'Zu gross.', 'err_conflict' => 'Die Datei wurde von jemand anderem geändert.',
		'err_too_big_upload' => 'Die Datei ist zu gross (höchstens %1 MB).', 'err_disk_full' => 'Nicht genug freier Speicherplatz.', 'err_data_dir' => 'Der Datenordner von nuFiles fehlt.',
		'err_upload_ticket' => 'Das Hochladen ist abgelaufen – bitte neu starten.', 'err_upload_php' => 'Der Server hat das Hochladen abgelehnt (PHP-Fehler %1).',
		'err_upload_chunk' => 'Ungültiger Teil des Uploads.', 'err_upload_offset' => 'Upload nicht im Takt – wird fortgesetzt.', 'err_no_zip' => 'Zip ist auf diesem Server nicht verfügbar (PHP-Erweiterung zip).',
		'err_zip_open' => 'Das Archiv kann nicht geöffnet werden.', 'err_zip_size' => 'Die Grösse eines Eintrags stimmt nicht mit dem Archiv überein.', 'err_lint' => 'Syntaxfehler.',
		'err_not_text' => 'Diese Datei kann nicht als Text bearbeitet werden.', 'err_readonly_mode' => 'Nur-Lesen-Modus – keine Änderungen möglich.', 'err_reauth' => 'Bitte bestätige dein Passwort.',
		'err_admin' => 'Nur der Globeadmin darf das.', 'err_bad_request' => 'Ungültige Anfrage.', 'err_server' => 'Serverfehler.',
	];

	/** @return array<string,string> */
	public static function strings(string $lang): array
	{
		$l = strtolower($lang);
		if (str_starts_with($l, 'de')) {
			$de = self::DE + self::EN;
			if ($l !== 'de-ch') {                       // German German: ß where Swiss German writes ss
				$map = ['Grösse' => 'Größe', 'Schliessen' => 'Schließen', 'schliessen' => 'schließen', 'schliesst' => 'schließt', 'ausserhalb' => 'außerhalb', 'gross' => 'groß'];
				foreach ($de as $k => $v) $de[$k] = strtr($v, $map);
			}
			return $de;
		}
		return self::EN;
	}

	public static function t(array $strings, string $key, array $args = []): string
	{
		$s = $strings[$key] ?? $key;
		foreach (array_values($args) as $i => $a) $s = str_replace('%' . ($i + 1), (string)$a, $s);
		return $s;
	}
}
