<?php
// Self-check for the company file helpers: php tests/files_test.php  (no database)
require __DIR__ . '/../inc/files.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }

check('size', [file_size_label(0), file_size_label(900), file_size_label(1024), file_size_label(340 * 1024), file_size_label(1258291), file_size_label(5 * 1048576)], ['0 B', '900 B', '1 KB', '340 KB', '1,2 MB', '5 MB']);
check('name keeps letters and the stored extension', file_download_name('Peraturan Perusahaan 2026.pdf', 'pdf'), 'Peraturan Perusahaan 2026.pdf');
check('name drops paths and quotes', file_download_name('../../etc/passwd"; x=1.pdf', 'pdf'), 'passwd x1.pdf');
check('name drops windows paths', file_download_name('C:\\Users\\a\\Surat Cuti.docx', 'docx'), 'Surat Cuti.docx');
check('name from nothing', [file_download_name('', 'pdf'), file_download_name('.pdf', 'pdf'), file_download_name("\x00\x01", 'xlsx')], ['file.pdf', 'file.pdf', 'file.xlsx']);
check('extension comes from the type, not the name', file_download_name('virus.exe', 'pdf'), 'virus.pdf');
$shared = ['shared' => 1]; $private = ['shared' => 0];
check('hr sees everything', [file_can_view($shared, true, false), file_can_view($private, true, false)], [true, true]);
check('employee sees shared only', [file_can_view($shared, false, true), file_can_view($private, false, true)], [true, false]);
check('nobody else sees anything', [file_can_view($shared, false, false), file_can_view(null, true, false)], [false, false]);
check('types: office documents and pdf, no scripts', [isset(files_types()['application/pdf']), isset(files_types()['application/x-httpd-php']), count(files_types())], [true, false, 6]);
check('categories include a catch-all', array_key_exists('lain', files_categories()), true);
echo "files_test: $ok checks passed\n";
