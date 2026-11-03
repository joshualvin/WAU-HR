<?php
// Self-check for the bulk employee update: php tests/bulk_test.php  (no database)
require __DIR__ . '/../inc/bulk.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }

$ctx = ['org' => ['jabatan' => ['staff' => 4, 'manager' => 1], 'departemen' => ['gudang' => 6, 'it' => 4], 'kantor' => ['pusat' => 1]],
        'names' => ['jabatan' => [4 => 'Staff', 1 => 'Manager'], 'departemen' => [6 => 'Gudang', 4 => 'IT'], 'kantor' => [1 => 'Pusat']],
        'opts' => ['marital' => ['belum' => 'Belum kawin', 'kawin' => 'Kawin'], 'religion' => ['islam' => 'Islam', 'kristen' => 'Kristen'], 'education' => ['s1' => 'S1/D4', 'sma' => 'SMA/SMK']]];

// ---- reading the file
check('csv: BOM, semicolons, quotes, blank lines', bulk_parse_csv("\xEF\xBB\xBFPIN;Nama;Alamat\n9101;Oerip;\"Jl. A, No. 5\"\n\n;;\n"), [['PIN', 'Nama', 'Alamat'], ['9101', 'Oerip', 'Jl. A, No. 5']]);
check('csv: commas and trimming', bulk_parse_csv("PIN, Nama\n 9101 , Oerip \n"), [['PIN', 'Nama'], ['9101', 'Oerip']]);
check('csv: tabs', bulk_parse_csv("PIN\tNama\n9101\tOerip"), [['PIN', 'Nama'], ['9101', 'Oerip']]);
check('csv: Windows-1252 (Excel) is converted', bulk_parse_csv("PIN;Nama\n9101;Jos\xE9\n")[1][1], 'José');
check('headers', bulk_header_map(['PIN', 'Nama', 'Tanggal Lahir', 'No. HP', 'Agama', 'xyz', 'nama']), [[0 => 'pin', 1 => 'nama', 2 => 'tgl_lahir', 3 => 'hp', 4 => 'religion'], ['xyz', 'nama']]);

// ---- cells
check('date formats', [bulk_date('2026-12-31'), bulk_date('31-12-2026'), bulk_date('1/2/2026'), bulk_date('31-02-2026'), bulk_date('hello')], ['2026-12-31', '2026-12-31', '2026-02-01', null, null]);
$v = function ($k, $raw) use ($ctx) { return bulk_value($k, $raw, $ctx, '2026-11-04'); };
check('gender', [$v('gender', 'L')[0], $v('gender', 'Perempuan')[0], $v('gender', 'x')[1]], [1, 2, 'Jenis kelamin "x" harus L atau P']);
check('org by name, any case', [$v('jabatan', 'Staff')[0], $v('departemen', 'IT')[0], $v('kantor', 'pusat')[0]], [4, 4, 1]);
check('org unknown', $v('jabatan', 'Direktur')[1], 'Jabatan "Direktur" tidak ada di daftar (tambahkan dulu di Pengaturan)');
check('select by label or key', [$v('religion', 'Kristen')[0], $v('marital', 'belum kawin')[0], $v('education', 'S1/D4')[0], $v('education', 's1')[0]], ['kristen', 'belum', 's1', 's1']);
check('select unknown lists the choices', $v('religion', 'zzz')[1], 'Agama "zzz" tidak dikenal (pilihan: Islam, Kristen)');
check('NIK 16 digits, spaces and dashes removed', [$v('nik_ktp', '3578 0101-8001 0002')[0], $v('nik_ktp', '123')[1]], ['3578010180010002', 'NIK (KTP) harus 16 angka (ada 3)']);
check('Excel scientific notation is caught', $v('nik_ktp', '3.57801E+15')[1], 'NIK (KTP) dirusak Excel (jadi 3.57801E+15): ubah format kolom menjadi Teks lalu isi ulang');
check('NIK letters', $v('nik_ktp', '35780101800100AB')[1], 'NIK (KTP) hanya boleh angka');
check('NPWP 15 or 16', [$v('npwp', '12.345.678.9-012.000')[0], $v('npwp', '12345')[1]], ['123456789012000', 'NPWP harus 15 atau 16 angka (ada 5)']);
check('email', [$v('email', 'a@b.co')[0], $v('email', 'not-an-email')[1]], ['a@b.co', 'Email "not-an-email" tidak valid']);
check('account', [$v('no_rek', '123 456 789')[0], $v('no_rek', 'abc')[1]], ['123456789', 'No. rekening hanya angka (maksimal 20)']);
check('birth date in the future / absurd', [$v('tgl_lahir', '01-01-2030')[1], $v('tgl_lahir', '01-01-1800')[1], $v('tgl_lahir', '23-08-1996')[0]], ['Tanggal lahir 01-01-2030 tidak masuk akal', 'Tanggal lahir 01-01-1800 tidak masuk akal', '1996-08-23']);
check('text too long', $v('alias', str_repeat('a', 51))[1], 'Nama panggilan terlalu panjang (maksimal 50 huruf)');
check('display', [bulk_show('tgl_lahir', '1996-08-23', $ctx), bulk_show('gender', 2, $ctx), bulk_show('jabatan', 4, $ctx), bulk_show('religion', 'islam', $ctx), bulk_show('nama', '', $ctx), bulk_show('jabatan', 99, $ctx)], ['23-08-1996', 'Perempuan', 'Staff', 'Islam', '—', '#99']);

// ---- planning
$current = ['9101' => ['id' => 1, 'cur' => ['nama' => 'Oerip', 'hp' => '0811', 'jabatan' => 4, 'departemen' => 6, 'religion' => 'islam', 'tgl_lahir' => '1990-05-05', 'gender' => 1]],
            '9102' => ['id' => 2, 'cur' => ['nama' => 'Son', 'hp' => '', 'jabatan' => 1, 'departemen' => 6, 'religion' => '', 'tgl_lahir' => '', 'gender' => 1]]];
$csv = [['PIN', 'No HP', 'Jabatan', 'Agama', 'Tanggal Lahir', 'Kolom Aneh'],
        ['9101', '0822', 'Staff', 'Kristen', '05-05-1990', 'x'],        // hp and religion change; jabatan and birth date are the same
        ['9102', '', 'Manager', '', '', ''],                              // nothing changes (empty cells are left alone)
        ['9999', '0833', '', '', '', ''],                                 // unknown PIN
        ['9102', '0844', '', '', '', ''],                                 // the same PIN twice
        ['9101', '', 'Direktur', '', '', '']];                            // (duplicate of 9101 too)
$plan = bulk_plan($csv, $current, $ctx, '2026-11-04');
check('unknown header ignored', $plan['unknown'], ['Kolom Aneh']);
check('known columns', $plan['known'], ['hp', 'jabatan', 'religion', 'tgl_lahir']);
check('row 1 changes: only what differs', array_map(function ($c) { return [$c[0], $c[2], $c[3]]; }, $plan['rows'][0]['changes']), [['hp', '0811', '0822'], ['religion', 'Islam', 'Kristen']]);
check('row 1 target and stored value', [$plan['rows'][0]['changes'][0][4], $plan['rows'][0]['changes'][0][5], $plan['rows'][0]['changes'][1][4], $plan['rows'][0]['changes'][1][5]], ['p:pegawai_telp', '0822', 'religion', 'kristen']);
check('row 2: empty cells and same values change nothing', [$plan['rows'][1]['changes'], $plan['rows'][1]['errors']], [[], []]);
check('row 3: unknown PIN', $plan['rows'][2]['errors'], ['PIN 9999 tidak ada di data karyawan']);
check('row 4: PIN twice', $plan['rows'][3]['errors'], ['PIN 9102 muncul dua kali di file']);
check('row 5: PIN twice and an unknown jabatan', $plan['rows'][4]['errors'], ['PIN 9101 muncul dua kali di file', 'Jabatan "Direktur" tidak ada di daftar (tambahkan dulu di Pengaturan)']);
check('counts', $plan['counts'], ['rows' => 5, 'changes' => 1, 'errors' => 3, 'unchanged' => 1]);
check('no PIN column', bulk_plan([['Nama'], ['x']], $current, $ctx)['error'], 'Kolom PIN tidak ada. PIN dipakai untuk mengenali karyawan.');
check('only a PIN column', bulk_plan([['PIN'], ['9101']], $current, $ctx)['error'], 'Tidak ada kolom data yang dikenal selain PIN.');
check('empty file', bulk_plan([['PIN', 'Nama']], $current, $ctx)['error'], 'File kosong: harus ada baris judul dan minimal satu baris data.');
check('org id 0 is not a name', bulk_show('kantor', 0, $ctx), '—');
echo "bulk_test: $ok checks passed\n";
