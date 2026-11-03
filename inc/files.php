<?php
// Company files (HR → Perusahaan → File): policies, SOPs, forms and templates kept in one place; each file can be shared with the
// employees (they see it in the Portal Pegawai). Stored under uploads/files, which Apache does not serve: filedl.php checks who asks.

const FILES_DIR = __DIR__ . '/../uploads/files';

function ensure_files_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_file (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(150) NOT NULL, category VARCHAR(30) NOT NULL DEFAULT 'lain',
        file VARCHAR(80) NOT NULL, orig VARCHAR(150) NOT NULL DEFAULT '', size INT UNSIGNED NOT NULL DEFAULT 0, shared TINYINT NOT NULL DEFAULT 0,
        uploaded_by VARCHAR(50) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, KEY cat (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

function files_categories()
{
    return ['kebijakan' => 'Kebijakan perusahaan', 'sop' => 'SOP', 'formulir' => 'Formulir', 'legal' => 'Kontrak & legal', 'template' => 'Template', 'lain' => 'Lain-lain'];
}

/** What can be uploaded: mime type => extension. */
function files_types()
{
    return ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx'];
}

/** "340 KB", "1,2 MB". */
function file_size_label($bytes)
{
    $bytes = (int)$bytes;
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024) . ' KB';
    return str_replace('.', ',', (string)round($bytes / 1048576, 1)) . ' MB';
}

/** A file name that is safe in a Content-Disposition header: no path, no quotes or control characters, with the stored extension. */
function file_download_name($orig, $ext)
{
    $base = preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '', basename(str_replace('\\', '/', (string)$orig)));
    $base = trim(preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $base));
    return ($base !== '' ? $base : 'file') . '.' . $ext;
}

/** Who may download: HR always, an employee only a shared file. */
function file_can_view($row, $isAdmin, $isEmployee)
{
    return $row && ($isAdmin || ($isEmployee && (int)$row['shared'] === 1));
}
