<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * File uploads with Uppy (0.8.459) – nuBuilder 4.9's file objects with target "File System" (sob_input_file_target 1):
 *   - the object's HTML code is the Uppy template (code snippet nu_upload_file_uppy_code_template, filled in by the form builder);
 *   - Uppy posts each file to ?p=upload (pages/nuupload.php), which runs the procedure the template names – nuBuilder's
 *     nu_upload_file_template – and answers with its $result;
 *   - Uppy itself is a library pack (NuLibs slot 'uppy'), loaded by pages/nuform.php where a form uses it.
 * Installations that do not have nuBuilder's two rows get them with update step 0020-upload-template (ensureTemplates):
 * the snippet as nuBuilder ships it, the procedure in a hardened version (PROCEDURE).
 */
final class NuUpload
{
	public const PROCEDURE_CODE = 'nu_upload_file_template';
	public const SNIPPET_CODE   = 'nu_upload_file_uppy_code_template';
	/** nuBuilder's row ids (nubuilder4.sql) – nuBuilder's own update finds the rows under the same ids. */
	public const PROCEDURE_ID   = 'nu62da72900a02b9a';
	public const SNIPPET_ID     = 'nu6866e0177fc5399';

	/** nuBuilder 4.9.2's Uppy template (snippet nu_upload_file_uppy_code_template), unchanged. */
	public const HTML = <<<'NUHTML'
<div id="#uppy_div#"></div>

<script>

$(document).ready(function () {
    nuInitUppy();
});

function nuInitUppy() {

    const $objId = $('#' + '#this_object_id#');
    const targetDivId = '#uppy_div#';
    let uppyResponseMessage = '';

    const uppy = nuUppyCreate();
    uppy.use(Uppy.Dashboard, {
        inline: true,
        bundle: true,
        height: $objId.nuCSSNumber('height') || 400,
        width: $objId.nuCSSNumber('width') || '100%',
        target: '#' + targetDivId,
        showProgressDetails: true,
        replaceTargetContent: true,
        method: 'post'
    })
    .use(Uppy.XHRUpload, {
        endpoint: 'core/nuapi.php',
        shouldRetry: (xhr) => {
            return false;
        },
        async onAfterResponse(xhr, response) {
            try {
                const jsonData = JSON.parse(xhr.responseText);
                if (xhr.status === 401) {
                    uppyResponseMessage = jsonData.message || 'Authentication failed.';
                    throw new Error(uppyResponseMessage);
                }
            } catch (e) {
                uppyResponseMessage = 'Error processing server response.';
            }
        }
    });

    uppy.on('upload', (data) => {
        uppy.setMeta({
            procedure: 'nu_upload_file_template',
            session_id: window.nuSESSION,
            record_id: nuRecordId()
        });
    });

    uppy.on('complete', (result) => {
        uppyResponseMessage = '';

        if (result.successful.length > 0) {
            uppyResponseMessage = result.successful[0].response.body.message || 'File(s) uploaded successfully.';
        } else if (result.failed.length > 0) {
            const firstFailed = result.failed[0];
            uppyResponseMessage = firstFailed.response && firstFailed.response.body && firstFailed.response.body.message
                ? firstFailed.response.body.message
                : firstFailed.error.message || 'Error uploading file(s).';
        } else {
            uppyResponseMessage = 'No files processed.';
        }

        if (window.nuOnFileUploadComplete) {
            nuOnFileUploadComplete('FS', $objId.attr('id'), result, uppyResponseMessage);
        }
    });

    uppy.on('error', (error) => {
        console.error('Uppy error:', error);
    });
}
</script>
NUHTML;

	/** The upload procedure nu_upload_file_template – nuBuilder's, hardened (see its first lines). */
	public const PROCEDURE = <<<'NUPHP'
// nuBuilder Next's version of nuBuilder's upload template (same name, same structure – it runs in nuBuilder too):
// the file extension must fit the content type, no HTML / JavaScript / SVG / XML (they would run in the browser
// from your site), an existing file is never overwritten (name-1.pdf, name-2.pdf …) and files are stored where the web
// server does not serve them.

// Allowed content types and the file extensions that go with them
$allowedTypes = [
    // Documents
    'application/pdf' => ['pdf'],
    'application/msword' => ['doc'],
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
    'application/rtf' => ['rtf'],
    'text/rtf' => ['rtf'],

    // Spreadsheets
    'application/vnd.ms-excel' => ['xls'],
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],

    // Presentations
    'application/vnd.ms-powerpoint' => ['ppt'],
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],

    // Text
    'text/plain' => ['txt', 'csv', 'log', 'md', 'css'],
    'text/csv' => ['csv'],
    'application/csv' => ['csv'],
    'application/json' => ['json'],
    'text/css' => ['css'],

    // Archives (older servers report Office files as zip)
    'application/zip' => ['zip', 'docx', 'xlsx', 'pptx'],
    'application/x-rar-compressed' => ['rar'],
    'application/x-rar' => ['rar'],
    'application/vnd.rar' => ['rar'],
    'application/x-7z-compressed' => ['7z'],

    // Images
    'image/png' => ['png'],
    'image/jpeg' => ['jpg', 'jpeg'],

    // Media
    'audio/mpeg' => ['mp3'],
    'video/mp4' => ['mp4']
];

// Maximum file size
$maxFileSize = 5 * 1024 * 1024; // (5 MB)

// Target directory – never a folder the web server serves (nuBuilder Next 0.8.478): first "nu_uploads" NEXT TO the web
// root; where that cannot be created (shared hosting), "nu_uploads" inside it, closed with a deny-all .htaccess
// (nginx: add  location ^~ /nu_uploads/ { deny all; } ). Uploaded files are then read by your own code, not by URL.
$targetDirectory = dirname($_SERVER['DOCUMENT_ROOT']) . '/nu_uploads/';
if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0750, true)) {
    $targetDirectory = $_SERVER['DOCUMENT_ROOT'] . '/nu_uploads/';
}

// Ensure the directory exists and is closed to the web
if (!is_dir($targetDirectory)) {
    mkdir($targetDirectory, 0750, true);
}
if (!file_exists($targetDirectory . '.htaccess')) {
    file_put_contents($targetDirectory . '.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n");
}
if (!file_exists($targetDirectory . 'index.html')) {
    file_put_contents($targetDirectory . 'index.html', '');
}

try {

    // Sanitize file name
    $fileName = nuSanitizeFilename(basename($_FILES['file']['name']));
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($fileName === '' || $extension === '') {
        throw new Exception('Invalid file type');
    }

    // Check file size
    if ($_FILES['file']['size'] > $maxFileSize) {
        throw new Exception('Exceeded file size limit');
    }

    // Check file type: the content and the extension must fit together
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $type = $finfo->file($_FILES['file']['tmp_name']);
    if (!isset($allowedTypes[$type]) || !in_array($extension, $allowedTypes[$type], true)) {
        throw new Exception('Invalid file type');
    }

    // Build target file path – never overwrite an existing file
    $baseName = pathinfo($fileName, PATHINFO_FILENAME);
    $targetFile = $targetDirectory . $fileName;
    for ($i = 1; file_exists($targetFile); $i++) {
        $fileName = $baseName . '-' . $i . '.' . $extension;
        $targetFile = $targetDirectory . $fileName;
    }

    if (move_uploaded_file($_FILES['file']['tmp_name'], $targetFile)) {
        $data = ['url' => $fileName,          // the name only: the server path is not shown to the browser
            'file' => $fileName,
            'message' => 'The file ' . $fileName . ' has been uploaded.'];
        http_response_code(201);
        $result = json_encode($data);
    } else {
        throw new Exception(nuTranslate('Unable to move the uploaded file to its final location:') . ' ' . $fileName);
    }

} catch(\Throwable $th) {

    $result = nuSetUploadError(nuTranslate($th->getMessage()));

}
NUPHP;

	/**
	 * The Uppy HTML the form builder puts into a file object switched to "File System" with empty HTML code: as nuBuilder's
	 * procedure nu_upload_file_load_uppy_code – the snippet nu_upload_file_uppy_code_template, else nu_upload_file_uppy_code, else HTML.
	 */
	public static function htmlTemplate(): string
	{
		try {
			$db = nu_db();
			if ($db->tableExists('zzzzsys_code_snippet')) {
				foreach ([self::SNIPPET_CODE, 'nu_upload_file_uppy_code'] as $code) {
					$v = (string)($db->value('SELECT cot_source_code FROM zzzzsys_code_snippet WHERE cot_code = ?', [$code]) ?? '');
					if (trim($v) !== '') return $v;
				}
			}
		} catch (Throwable) {
		}
		return self::HTML;
	}

	/**
	 * Unchanged copies of the earlier upload procedures (sha1 of the trimmed text, LF line ends): nuBuilder 4.9's
	 * nu_upload_file_template and nuBuilder Next's of 0.8.459–0.8.477 – both store files in the web root (security assessment C1).
	 */
	public const OLD_PROCEDURES = ['8bb66de2e5860b8075f75d05c64d4c3792c3177e', '64671638dff56420dcbb54e47824684f5103fd64'];

	public static function codeHash(string $php): string
	{
		return sha1(trim(str_replace("\r\n", "\n", $php)));
	}

	/** Does the stored upload procedure still write into the web root? null = no such procedure. */
	public static function procedureInWebRoot(): ?bool
	{
		try {
			$db = nu_db();
			if (!$db->tableExists('zzzzsys_php')) return null;
			$php = $db->value('SELECT sph_php FROM zzzzsys_php WHERE sph_code = ?', [self::PROCEDURE_CODE]);
			if (!is_string($php)) return null;
			return (bool)preg_match('/\$targetDirectory\s*=\s*\$_SERVER\s*\[\s*[\'"]DOCUMENT_ROOT[\'"]\s*\]\s*\.\s*[\'"]\/?[\'"]\s*;/', $php);
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * Update step 0022-upload-template-folder: replaces the upload procedure by the current one when it is an UNCHANGED copy of an
	 * earlier template (OLD_PROCEDURES) – edited procedures are kept (the Security check names them). @return string what was done
	 */
	public static function replaceOldProcedure(): string
	{
		$db = nu_db();
		if (!$db->tableExists('zzzzsys_php')) return '';
		$row = $db->one('SELECT zzzzsys_php_id, sph_php FROM zzzzsys_php WHERE sph_code = ?', [self::PROCEDURE_CODE]);
		if (!$row) return '';
		if (!in_array(self::codeHash((string)$row['sph_php']), self::OLD_PROCEDURES, true)) return 'kept (edited)';
		$db->update('zzzzsys_php', ['sph_php' => self::PROCEDURE], 'zzzzsys_php_id', (string)$row['zzzzsys_php_id']);
		return 'replaced';
	}

	/** Update step 0020-upload-template: nuBuilder's procedure and snippet when the database has no row of that code yet. @return string what was added */
	public static function ensureTemplates(): string
	{
		$db = nu_db();
		$added = [];
		if ($db->tableExists('zzzzsys_php') && !(int)$db->value('SELECT COUNT(*) FROM zzzzsys_php WHERE sph_code = ? OR zzzzsys_php_id = ?', [self::PROCEDURE_CODE, self::PROCEDURE_ID])) {
			$cols = $db->columns('zzzzsys_php');
			$row = ['zzzzsys_php_id' => self::PROCEDURE_ID, 'sph_code' => self::PROCEDURE_CODE, 'sph_description' => 'Upload Files Template', 'sph_group' => 'nubuilder',
				'sph_category' => 'File Upload', 'sph_status' => '1', 'sph_php' => self::PROCEDURE, 'sph_run' => 'hide', 'sph_zzzzsys_form_id' => '', 'sph_system' => '0',
				'sph_global' => '1', 'sph_demo' => '1', 'sph_template' => '1', 'sph_hide' => ''];
			$db->insert('zzzzsys_php', array_intersect_key($row, $cols ?: $row));
			$added[] = self::PROCEDURE_CODE;
		}
		if ($db->tableExists('zzzzsys_code_snippet') && !(int)$db->value('SELECT COUNT(*) FROM zzzzsys_code_snippet WHERE cot_code = ? OR zzzzsys_code_snippet_id = ?', [self::SNIPPET_CODE, self::SNIPPET_ID])) {
			$cols = $db->columns('zzzzsys_code_snippet');
			$row = ['zzzzsys_code_snippet_id' => self::SNIPPET_ID, 'cot_code' => self::SNIPPET_CODE, 'cot_group' => 'nubuilder', 'cot_source_code' => self::HTML, 'cot_language' => 'JavaScript', 'cot_scope' => '[""]', 'cot_template' => '1'];
			$db->insert('zzzzsys_code_snippet', array_intersect_key($row, $cols ?: $row));
			$added[] = self::SNIPPET_CODE;
		}
		return implode(', ', $added);
	}
}
