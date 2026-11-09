<?php
// Personal data (with change requests from the portal), employee documents and HR letters from templates.
// Fingerspot's pegawai holds phone, birth place / date and bank account; everything else is in web_emp_profile.

const DOC_DIR = __DIR__ . '/../uploads/docs';

function ensure_people_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_emp_profile (
        pegawai_id INT NOT NULL PRIMARY KEY,
        nik_ktp VARCHAR(20) NOT NULL DEFAULT '', no_kk VARCHAR(20) NOT NULL DEFAULT '', npwp VARCHAR(30) NOT NULL DEFAULT '',
        marital VARCHAR(12) NOT NULL DEFAULT '', marriage_date DATE NULL, dependants TINYINT NOT NULL DEFAULT 0,
        religion VARCHAR(20) NOT NULL DEFAULT '', blood_type VARCHAR(3) NOT NULL DEFAULT '', education VARCHAR(20) NOT NULL DEFAULT '',
        address VARCHAR(255) NOT NULL DEFAULT '', domicile VARCHAR(255) NOT NULL DEFAULT '', email VARCHAR(120) NOT NULL DEFAULT '',
        emergency_name VARCHAR(100) NOT NULL DEFAULT '', emergency_relation VARCHAR(40) NOT NULL DEFAULT '', emergency_phone VARCHAR(30) NOT NULL DEFAULT '',
        atasan_id INT NULL,
        updated_at DATETIME NULL, updated_by VARCHAR(50) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_profile_request (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        pegawai_id INT NOT NULL, data TEXT NOT NULL, note VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(10) NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL, decided_by VARCHAR(50) NOT NULL DEFAULT '', decided_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_emp_doc (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        pegawai_id INT NOT NULL, kind VARCHAR(20) NOT NULL, title VARCHAR(120) NOT NULL DEFAULT '', file VARCHAR(80) NOT NULL,
        expires DATE NULL, uploaded_by VARCHAR(50) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, KEY emp (pegawai_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_letter_tpl (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, code VARCHAR(10) NOT NULL DEFAULT 'SK', body TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_letter (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, tpl_id INT UNSIGNED NOT NULL, pegawai_id INT NOT NULL, number VARCHAR(40) NOT NULL,
        title VARCHAR(80) NOT NULL, issued DATE NOT NULL, body TEXT NOT NULL, signer VARCHAR(100) NOT NULL DEFAULT '', created_by VARCHAR(50) NOT NULL DEFAULT '', created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    if (!q('SELECT COUNT(*) FROM web_letter_tpl')->fetchColumn()) {
        foreach (default_letter_templates() as $t) q('INSERT INTO web_letter_tpl (name, code, body) VALUES (?, ?, ?)', $t);
    }
    // templates added later: inserted once when their code is missing (a deleted one comes back, edit it instead)
    foreach (default_letter_templates() as $t) {
        if ($t[1] === 'PKWTT' && !q('SELECT COUNT(*) FROM web_letter_tpl WHERE code = ?', [$t[1]])->fetchColumn()) q('INSERT INTO web_letter_tpl (name, code, body) VALUES (?, ?, ?)', $t);
    }
}

/** Editable personal fields: key => [label, type, options]. 'p:' keys live in Fingerspot's pegawai table. */
function profile_fields()
{
    return [
        'nik_ktp' => ['NIK (KTP)', 'text'], 'no_kk' => ['No. KK', 'text'], 'npwp' => ['NPWP', 'text'],
        'p:tempat_lahir' => ['Tempat lahir', 'text'], 'p:tgl_lahir' => ['Tanggal lahir', 'date'],
        'marital' => ['Status perkawinan', 'select', ['belum' => 'Belum kawin', 'kawin' => 'Kawin', 'cerai_hidup' => 'Cerai hidup', 'cerai_mati' => 'Cerai mati']],
        'marriage_date' => ['Tanggal menikah', 'date'], 'dependants' => ['Jumlah tanggungan', 'number'],
        'religion' => ['Agama', 'select', ['islam' => 'Islam', 'kristen' => 'Kristen', 'katolik' => 'Katolik', 'hindu' => 'Hindu', 'buddha' => 'Buddha', 'konghucu' => 'Konghucu', 'lainnya' => 'Lainnya']],
        'blood_type' => ['Golongan darah', 'select', ['A' => 'A', 'B' => 'B', 'AB' => 'AB', 'O' => 'O']],
        'education' => ['Pendidikan terakhir', 'select', ['sd' => 'SD', 'smp' => 'SMP', 'sma' => 'SMA/SMK', 'd3' => 'D3', 's1' => 'S1/D4', 's2' => 'S2', 's3' => 'S3']],
        'address' => ['Alamat KTP', 'text'], 'domicile' => ['Alamat domisili', 'text'], 'p:pegawai_telp' => ['No. HP', 'text'], 'email' => ['Email', 'text'],
        'emergency_name' => ['Kontak darurat', 'text'], 'emergency_relation' => ['Hubungan', 'text'], 'emergency_phone' => ['No. HP kontak darurat', 'text'],
        'p:nama_bank' => ['Bank', 'text'], 'p:no_rek' => ['No. rekening', 'text'], 'p:nama_rek' => ['Nama di rekening', 'text'],
    ];
}

/** An employee's personal data: [field key => value] with the keys of profile_fields(). */
function get_profile($pegawaiId)
{
    $p = q('SELECT * FROM pegawai WHERE pegawai_id = ?', [$pegawaiId])->fetch() ?: [];
    $x = q('SELECT * FROM web_emp_profile WHERE pegawai_id = ?', [$pegawaiId])->fetch() ?: [];
    $out = [];
    foreach (profile_fields() as $k => $f) {
        $col = strpos($k, 'p:') === 0 ? substr($k, 2) : $k;
        $v = strpos($k, 'p:') === 0 ? (isset($p[$col]) ? $p[$col] : '') : (isset($x[$col]) ? $x[$col] : '');
        $out[$k] = $f[1] === 'date' && $v ? substr($v, 0, 10) : (string)$v;
    }
    $out['atasan_id'] = isset($x['atasan_id']) ? $x['atasan_id'] : null;
    return $out;
}

/** Clean a submitted set of fields: only known keys, trimmed, selects / dates / numbers checked. */
function clean_profile($in)
{
    $out = [];
    foreach (profile_fields() as $k => $f) {
        $name = str_replace(':', '_', $k);
        if (!array_key_exists($name, $in)) continue;
        $v = trim((string)$in[$name]);
        if ($f[1] === 'select' && $v !== '' && !isset($f[2][$v])) continue;
        if ($f[1] === 'date' && $v !== '' && !valid_date($v)) continue;
        if ($f[1] === 'number') $v = (string)max(0, min(9, (int)$v));
        $out[$k] = mb_substr($v, 0, 255);
    }
    return $out;
}

function save_profile($pegawaiId, $data)
{
    $peg = [];
    $own = [];
    foreach ($data as $k => $v) {
        if (strpos($k, 'p:') === 0) $peg[substr($k, 2)] = $v === '' && $k === 'p:tgl_lahir' ? null : $v;
        else $own[$k] = $v === '' && $k === 'marriage_date' ? null : $v;
    }
    if ($peg) q('UPDATE pegawai SET ' . implode(', ', array_map(function ($c) { return "$c = ?"; }, array_keys($peg))) . ' WHERE pegawai_id = ?', array_merge(array_values($peg), [$pegawaiId]));
    q('INSERT IGNORE INTO web_emp_profile (pegawai_id) VALUES (?)', [$pegawaiId]);
    $own['updated_at'] = date('Y-m-d H:i:s');
    $own['updated_by'] = actor();
    q('UPDATE web_emp_profile SET ' . implode(', ', array_map(function ($c) { return "$c = ?"; }, array_keys($own))) . ' WHERE pegawai_id = ?', array_merge(array_values($own), [$pegawaiId]));
}

/** Fields that differ from the current data (for a change request). */
function profile_changes($current, $new)
{
    $out = [];
    foreach ($new as $k => $v) if ((string)$v !== (string)(isset($current[$k]) ? $current[$k] : '')) $out[$k] = $v;
    return $out;
}

/** PTKP the personal data points to: K when married (husband / single parent rules aside), dependants up to 3. */
function ptkp_from_profile($marital, $dependants)
{
    return ($marital === 'kawin' ? 'K' : 'TK') . '/' . min(3, max(0, (int)$dependants));
}

function profile_value_label($k, $v)
{
    $f = profile_fields()[$k];
    if ($v === '' || $v === null) return '—';
    if ($f[1] === 'select') return isset($f[2][$v]) ? $f[2][$v] : $v;
    if ($f[1] === 'date') return date('d-m-Y', strtotime($v));
    return $v;
}

// ---------- documents ----------

function doc_kinds()
{
    return ['ktp' => 'KTP', 'kk' => 'Kartu Keluarga', 'npwp' => 'NPWP', 'bpjs' => 'Kartu BPJS', 'ijazah' => 'Ijazah', 'kontrak' => 'Kontrak kerja', 'sertifikat' => 'Sertifikat', 'sim' => 'SIM', 'sk' => 'SK / surat mutasi', 'lain' => 'Lainnya'];
}

function save_doc_file($upload)
{
    return save_upload($upload, DOC_DIR, ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'], 'Dokumen', 'PDF atau foto (JPG, PNG)');
}

/** Documents expiring within $days (or expired) for active employees. */
function documents_expiring($days = 30)
{
    return q("SELECT d.*, p.pegawai_nama FROM web_emp_doc d JOIN pegawai p ON p.pegawai_id = d.pegawai_id
              WHERE p.pegawai_status = 1 AND d.expires IS NOT NULL AND d.expires <= ? ORDER BY d.expires", [date('Y-m-d', strtotime("+$days days"))])->fetchAll();
}

// ---------- letters ----------

function default_letter_templates()
{
    return [
        ['Surat Keterangan Kerja (Paklaring)', 'SKK', "Yang bertanda tangan di bawah ini menerangkan bahwa:\n\nNama: {nama}\nNIP: {nip}\nJabatan terakhir: {jabatan}\nDepartemen: {departemen}\n\ntelah bekerja di {perusahaan} sejak {mulai_kerja} sampai dengan {tgl_berhenti} ({masa_kerja}).\n\nSelama bekerja, yang bersangkutan telah menunjukkan dedikasi dan loyalitas terhadap perusahaan. Kami mengucapkan terima kasih atas kontribusinya dan semoga sukses di tempat yang baru.\n\nDemikian surat keterangan ini dibuat untuk dapat dipergunakan sebagaimana mestinya."],
        ['Surat Keterangan Karyawan Aktif', 'SKA', "Yang bertanda tangan di bawah ini menerangkan bahwa:\n\nNama: {nama}\nNIP: {nip}\nNIK: {nik}\nJabatan: {jabatan}\nDepartemen: {departemen}\nAlamat: {alamat}\n\nadalah benar karyawan {perusahaan} dengan status {status_kerja} sejak {mulai_kerja} sampai saat ini.\n\nDemikian surat keterangan ini dibuat untuk dapat dipergunakan sebagaimana mestinya."],
        ['SK Pengangkatan Karyawan Tetap', 'SK', "Menimbang hasil evaluasi kinerja selama masa kerja, dengan ini {perusahaan} memutuskan:\n\nMengangkat Saudara {nama} (NIP {nip}) sebagai Karyawan Tetap (PKWTT) dengan jabatan {jabatan} pada departemen {departemen}, terhitung mulai tanggal {tanggal}.\n\nHak dan kewajiban Saudara mengikuti Peraturan Perusahaan dan ketentuan perundang-undangan yang berlaku.\n\nKeputusan ini berlaku sejak tanggal ditetapkan, dengan ketentuan apabila di kemudian hari terdapat kekeliruan akan diperbaiki sebagaimana mestinya."],
        ['Perjanjian Kerja Waktu Tidak Tertentu (PKWTT) + NDA', 'PKWTT', "PERJANJIAN KERJA WAKTU TIDAK TERTENTU (PKWTT)\nNomor: ........\n\nPada hari ini, {tanggal}, yang bertanda tangan di bawah ini:\n\nI. Nama: ........ (Direktur / pejabat berwenang)\n   Jabatan: ........\n   Bertindak untuk dan atas nama {perusahaan}, selanjutnya disebut PERUSAHAAN.\n\nII. Nama: {nama}\n   NIK: {nik}\n   NIP: {nip}\n   Alamat: {alamat}\n   selanjutnya disebut KARYAWAN.\n\nPERUSAHAAN dan KARYAWAN sepakat mengadakan perjanjian kerja dengan ketentuan sebagai berikut.\n\nPasal 1 - Jabatan dan Tempat Kerja\n1. KARYAWAN dipekerjakan sebagai {jabatan} pada departemen {departemen}, di {kantor}.\n2. PERUSAHAAN dapat menugaskan KARYAWAN pada pekerjaan atau lokasi lain yang masih sesuai dengan kemampuan KARYAWAN dan kebutuhan usaha, sesuai peraturan perundang-undangan.\n\nPasal 2 - Jangka Waktu dan Masa Percobaan\n1. Perjanjian ini berlaku untuk waktu tidak tertentu (karyawan tetap) terhitung sejak {mulai_kerja}.\n2. Tiga bulan pertama merupakan masa percobaan. Selama masa percobaan PERUSAHAAN menilai kinerja dan kehadiran KARYAWAN, dan upah yang diterima tidak lebih rendah dari upah minimum yang berlaku.\n3. Apabila hasil penilaian memuaskan, KARYAWAN terus bekerja sebagai karyawan tetap tanpa perlu perjanjian baru.\n\nPasal 3 - Jam Kerja\n1. Jam kerja ........ sampai ........, ........ hari kerja dalam seminggu, tidak melebihi 40 (empat puluh) jam seminggu.\n2. Kerja lembur hanya atas perintah atau persetujuan tertulis PERUSAHAAN dan dibayar sesuai peraturan perundang-undangan.\n\nPasal 4 - Upah dan Tunjangan\n1. Gaji pokok sebesar Rp ........ per bulan.\n2. Tunjangan tetap: ........ sebesar Rp ........ per bulan.\n3. Upah dibayarkan setiap bulan pada tanggal ........, melalui transfer ke rekening KARYAWAN.\n4. Dari upah dipotong iuran BPJS bagian KARYAWAN dan PPh Pasal 21 sesuai ketentuan yang berlaku.\n\nPasal 5 - Jaminan Sosial\nPERUSAHAAN mendaftarkan KARYAWAN pada BPJS Ketenagakerjaan dan BPJS Kesehatan, dan membayar iuran bagian PERUSAHAAN sesuai peraturan.\n\nPasal 6 - Cuti dan Hari Libur\n1. KARYAWAN berhak atas cuti tahunan 12 (dua belas) hari kerja setelah bekerja 12 (dua belas) bulan secara terus-menerus.\n2. KARYAWAN berhak atas hari libur resmi, cuti sakit dan cuti lain sesuai peraturan perundang-undangan dan Peraturan Perusahaan.\n\nPasal 7 - Hak dan Kewajiban KARYAWAN\n1. KARYAWAN wajib melaksanakan tugas dengan jujur dan penuh tanggung jawab, hadir tepat waktu, menaati Peraturan Perusahaan dan perintah kerja yang sah.\n2. KARYAWAN wajib menjaga dan merawat barang dan peralatan milik PERUSAHAAN, serta tidak menggunakannya untuk kepentingan pribadi tanpa izin.\n3. KARYAWAN tidak boleh bekerja pada pihak lain yang bersaing langsung dengan PERUSAHAAN atau yang menimbulkan benturan kepentingan selama masa kerja, tanpa persetujuan tertulis PERUSAHAAN.\n\nPasal 8 - Kerahasiaan (Non-Disclosure Agreement)\n1. Informasi Rahasia adalah semua informasi yang tidak tersedia untuk umum yang diketahui KARYAWAN karena hubungan kerja, termasuk namun tidak terbatas pada: data pelanggan dan pemasok, daftar harga, harga pokok, kontrak dan penawaran, strategi penjualan dan pemasaran, data keuangan dan gaji, rancangan produk dan resep atau formula produksi, proses produksi, data persediaan dan gudang, sistem dan kata sandi, serta data pribadi karyawan lain.\n2. KARYAWAN wajib menjaga kerahasiaan Informasi Rahasia, tidak mengungkapkan, menyalin, memindahkan atau menggunakannya untuk kepentingan sendiri atau pihak lain, selain untuk melaksanakan pekerjaan bagi PERUSAHAAN.\n3. KARYAWAN hanya boleh menyimpan Informasi Rahasia di sarana yang disediakan atau disetujui PERUSAHAAN, dan wajib segera melapor kepada PERUSAHAAN apabila mengetahui terjadi kebocoran.\n4. Kewajiban kerahasiaan ini berlaku selama masa kerja dan tetap berlaku 2 (dua) tahun setelah hubungan kerja berakhir, dengan alasan apa pun. Terhadap rahasia dagang, kewajiban berlaku selama informasi tersebut tetap menjadi rahasia.\n5. Kewajiban kerahasiaan tidak berlaku untuk informasi yang (a) sudah menjadi pengetahuan umum bukan karena pelanggaran KARYAWAN, (b) sudah diketahui KARYAWAN secara sah sebelum bekerja, atau (c) wajib diungkapkan berdasarkan perintah pengadilan atau peraturan perundang-undangan; dalam hal (c) KARYAWAN segera memberi tahu PERUSAHAAN apabila diperbolehkan hukum.\n6. Pada saat hubungan kerja berakhir atau atas permintaan PERUSAHAAN, KARYAWAN wajib mengembalikan semua dokumen, data, peralatan dan akses milik PERUSAHAAN, dan tidak menyimpan salinannya.\n7. Pelanggaran terhadap pasal ini merupakan pelanggaran berat yang dapat dikenai sanksi disiplin hingga pemutusan hubungan kerja, dan PERUSAHAAN berhak menuntut ganti rugi atas kerugian yang timbul sesuai peraturan perundang-undangan.\n\nPasal 9 - Hasil Kerja\nSemua hasil pekerjaan, dokumen, desain, data dan karya yang dibuat KARYAWAN dalam rangka tugasnya menjadi milik PERUSAHAAN.\n\nPasal 10 - Pemutusan Hubungan Kerja\n1. Hubungan kerja dapat berakhir karena pengunduran diri, kesepakatan bersama, pelanggaran, atau alasan lain yang diatur peraturan perundang-undangan.\n2. KARYAWAN yang mengundurkan diri wajib mengajukan permohonan tertulis paling lambat 30 (tiga puluh) hari sebelum tanggal berhenti, dan tetap bekerja sampai tanggal tersebut.\n3. Hak-hak KARYAWAN akibat pemutusan hubungan kerja mengikuti peraturan perundang-undangan yang berlaku.\n\nPasal 11 - Peraturan Perusahaan\nHal-hal yang belum diatur dalam perjanjian ini mengikuti Peraturan Perusahaan dan peraturan perundang-undangan ketenagakerjaan yang berlaku di Indonesia. Apabila Peraturan Perusahaan lebih menguntungkan KARYAWAN, yang berlaku adalah yang lebih menguntungkan.\n\nPasal 12 - Penyelesaian Perselisihan\nPerselisihan yang timbul diselesaikan secara musyawarah untuk mufakat. Apabila tidak tercapai, diselesaikan melalui bipartit, mediasi, dan Pengadilan Hubungan Industrial sesuai peraturan perundang-undangan.\n\nPasal 13 - Penutup\nPerjanjian ini dibuat rangkap 2 (dua) bermeterai cukup, masing-masing mempunyai kekuatan hukum yang sama dan dipegang oleh PERUSAHAAN dan KARYAWAN. KARYAWAN menyatakan telah membaca, memahami dan menyetujui seluruh isi perjanjian ini tanpa paksaan.\n\nPERUSAHAAN,                                              KARYAWAN,\n\n\n\n(........................)                                  ({nama})\n"],
    ];
}

function letter_placeholders()
{
    return ['{nama}', '{nip}', '{nik}', '{jabatan}', '{departemen}', '{kantor}', '{alamat}', '{mulai_kerja}', '{tgl_berhenti}', '{masa_kerja}', '{status_kerja}', '{perusahaan}', '{tanggal}'];
}

/** Fill a template: $vals = placeholder name (without braces) => text. */
function render_letter($body, $vals)
{
    $map = [];
    foreach ($vals as $k => $v) $map['{' . $k . '}'] = $v;
    return strtr($body, $map);
}

function letter_number($code, $date)
{
    $roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
    $n = 1 + (int)q('SELECT COUNT(*) FROM web_letter WHERE YEAR(issued) = ?', [substr($date, 0, 4)])->fetchColumn();
    return sprintf('%03d/%s/HR/%s/%s', $n, $code, $roman[(int)substr($date, 5, 2) - 1], substr($date, 0, 4));
}
