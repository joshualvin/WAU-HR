<?php
// HR → Penggajian: run payroll for a 21st–20th period, payslip history and exports, employee pay data, rates.
// Admins only: salaries are confidential.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/payroll.php';
require __DIR__ . '/inc/erp.php';
require __DIR__ . '/inc/approval.php';
$me = require_login('admin');
ensure_web_tables();
ensure_payroll_tables();
ensure_approval_tables();

$p = get('p', 'run');
list($defFrom, $defTo) = current_period();
$to = get('to', post('to', $defTo));
if (!valid_date($to)) $to = $defTo;
// a period is named by its last day; without a start date it began on the 21st of the month before
$from = get('from', post('from', ''));
if (!valid_date($from) || $from > $to) $from = date('Y-m-21', strtotime(date('Y-m-01', strtotime($to)) . ' -1 month'));

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'save' || $action === 'publish') {
        $rows = calculate_payroll($from, $to);
        save_payroll($rows, $action === 'publish');
        audit('payroll_' . $action, "$from..$to: " . count($rows) . ' karyawan');
        flash(count($rows) . ' slip gaji ' . ($action === 'publish' ? 'disimpan dan diterbitkan ke Portal Pegawai.' : 'disimpan sebagai draf.'));
        redirect('payroll.php?p=run&' . http_build_query(['from' => $from, 'to' => $to]));
    } elseif ($action === 'rb_approve' || $action === 'rb_reject') {
        $row = q('SELECT r.*, p.pegawai_nama FROM web_reimburse r JOIN pegawai p ON p.pegawai_id = r.pegawai_id WHERE r.id = ? AND r.status = ?', [(int)post('id'), CLAIM_PENDING])->fetch();
        if ($row) {
            q('UPDATE web_reimburse SET status = ?, decided_by = ? WHERE id = ?', [$action === 'rb_approve' ? CLAIM_APPROVED : CLAIM_REJECTED, actor(), $row['id']]);
            audit($action, "{$row['pegawai_nama']} {$row['kategori']} {$row['jumlah']}");
            flash($action === 'rb_approve' ? 'Klaim disetujui; dibayar bersama gaji periode berikutnya.' : 'Klaim ditolak.');
        }
        redirect(safe_back(post('back'), 'payroll.php?p=reimburse'));
    } elseif ($action === 'ytd_save') {   // Jan-Nov totals before the app, for the December annual PPh 21
        $yr = (int)post('year');
        $n = 0;
        foreach (isset($_POST['y']) ? (array)$_POST['y'] : [] as $id => $v) {
            $num = function ($k) use ($v) { return isset($v[$k]) ? (int)preg_replace('/\D/', '', $v[$k]) : 0; };
            if (!$num('months') && !$num('bruto') && !$num('iuran') && !$num('pph')) { q('DELETE FROM web_pph_open WHERE year = ? AND pegawai_id = ?', [$yr, (int)$id]); continue; }
            q('REPLACE INTO web_pph_open (year, pegawai_id, months, bruto, iuran, pph) VALUES (?, ?, ?, ?, ?, ?)', [$yr, (int)$id, min(11, $num('months')), $num('bruto'), $num('iuran'), $num('pph')]);
            $n++;
        }
        audit('ytd_save', "$yr: $n karyawan");
        flash("Saldo awal PPh 21 $yr untuk $n karyawan disimpan.");
        redirect('payroll.php?p=ytd&year=' . $yr);
    } elseif ($action === 'rb_add') {   // HR enters an approved claim for an employee (e.g. bensin for errands)
        $amt = (int)preg_replace('/\D/', '', post('jumlah'));
        $emp = q('SELECT pegawai_id, pegawai_nama FROM pegawai WHERE pegawai_id = ?', [(int)post('pegawai_id')])->fetch();
        if (!$emp || $amt <= 0 || !valid_date(post('tgl')) || !in_array(post('kategori'), reimburse_categories(), true)) flash('Isi pegawai, tanggal, kategori dan jumlah klaim dengan benar.', 'err');
        else {
            q('INSERT INTO web_reimburse (pegawai_id, tgl, kategori, jumlah, keterangan, file, status, created_at, decided_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$emp['pegawai_id'], post('tgl'), post('kategori'), $amt, mb_substr(trim(post('keterangan')), 0, 255), '', CLAIM_APPROVED, date('Y-m-d H:i:s'), actor()]);
            audit('rb_add', "{$emp['pegawai_nama']} " . post('kategori') . " $amt");
            flash('Klaim ' . h($emp['pegawai_nama']) . ' ' . rupiah($amt) . ' disimpan dan disetujui; dibayar bersama gaji periode berikutnya.');
        }
        redirect('payroll.php?p=reimburse&s=' . CLAIM_APPROVED);
    } elseif ($action === 'unpublish') {
        q('UPDATE web_payslip SET published = 0 WHERE period_to = ?', [$to]);
        audit('payroll_unpublish', $to);
        flash('Slip gaji periode ini ditarik dari Portal Pegawai.');
        redirect('payroll.php?p=slips');
    } elseif ($action === 'bonus_save' || $action === 'bonus_erp') {
        $tiers = bonus_tiers();
        $n = 0;
        $old = bonuses_for_period($to);
        $erp = null;
        if ($action === 'bonus_erp') {
            list($erp, $err) = erp_sales($from, $to);
            if ($err) { flash($err, 'err'); redirect('payroll.php?' . http_build_query(['p' => 'bonus', 'from' => $from, 'to' => $to])); }
        }
        $used = [];
        foreach (isset($_POST['b']) ? (array)$_POST['b'] : [] as $id => $v) {
            $code = strtoupper(trim(isset($v['code']) ? $v['code'] : ''));
            if ($code === '') q('DELETE FROM web_sales_code WHERE pegawai_id = ?', [(int)$id]);
            else q('REPLACE INTO web_sales_code (pegawai_id, code) VALUES (?, ?)', [(int)$id, mb_substr($code, 0, 10)]);
            $source = isset($old[$id]) ? $old[$id]['sales_source'] : '';
            if ($erp !== null && $code !== '') {   // invoiced value of this salesman's surat jalan in the period
                $v['sales'] = isset($erp[$code]) ? (string)$erp[$code]['invoiced'] : '0';
                $source = 'ERP ' . date('d-m H:i') . (isset($erp[$code]) && $erp[$code]['returns'] ? ', retur ' . rupiah($erp[$code]['returns']) : '');
                $used[$code] = true;
            } elseif (isset($old[$id]) && isset($v['sales']) && (int)preg_replace('/\D/', '', $v['sales']) !== (int)$old[$id]['sales']) {
                $source = 'Diisi manual';
            }
            $num = function ($k) use ($v) { return isset($v[$k]) ? (int)preg_replace('/\D/', '', $v[$k]) : 0; };
            list($sb) = sales_bonus($num('target'), $num('sales'), $tiers);
            $otRaw = isset($v['ot_manual']) ? trim($v['ot_manual']) : '';
            $otManual = $otRaw === '' ? null : (int)preg_replace('/\D/', '', $otRaw);   // empty = calculated from scans, 0 = no lembur
            if (!$num('target') && !$num('sales') && !$num('other_bonus') && $otManual === null && !$num('trip_driver') && !$num('trip_alone')) { q('DELETE FROM web_bonus WHERE period_to = ? AND pegawai_id = ?', [$to, (int)$id]); continue; }
            q('REPLACE INTO web_bonus (period_to, pegawai_id, target, sales, sales_bonus, other_bonus, note, sales_source, updated_by, ot_manual, trip_driver, trip_alone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$to, (int)$id, $num('target'), $num('sales'), $sb, $num('other_bonus'), mb_substr(trim(isset($v['note']) ? $v['note'] : ''), 0, 200), $source, actor(), $otManual, $num('trip_driver'), $num('trip_alone')]);
            $n++;
        }
        audit($action, "$to: $n karyawan");
        flash("Bonus $n karyawan disimpan; ikut dihitung saat gaji periode ini disimpan.");
        if ($erp !== null) {
            $left = [];
            foreach ($erp as $code => $s) if (!isset($used[$code]) && $s['invoiced']) $left[] = ($code === '' ? 'tanpa sales' : "$code ({$s['name']})") . ' ' . rupiah($s['invoiced']);
            flash('Penjualan diambil dari WAU ERP (surat jalan ' . fmt_date($from) . ' – ' . fmt_date($to) . ').' . ($left ? ' Tidak dipasangkan ke karyawan: ' . implode('; ', $left) . '.' : ''), $left ? 'err' : 'ok');
        }
        redirect('payroll.php?' . http_build_query(['p' => 'bonus', 'from' => $from, 'to' => $to]));
    } elseif ($action === 'tier_save') {
        foreach (isset($_POST['t']) ? (array)$_POST['t'] : [] as $id => $t) {
            if (trim($t['min_pct']) === '') q('DELETE FROM web_bonus_tier WHERE id = ?', [(int)$id]);
            else q('UPDATE web_bonus_tier SET min_pct = ?, rate = ?, fixed = ? WHERE id = ?', [(float)str_replace(',', '.', $t['min_pct']), (float)str_replace(',', '.', $t['rate']), (int)preg_replace('/\D/', '', $t['fixed']), (int)$id]);
        }
        if (post('new_min') !== '') q('INSERT INTO web_bonus_tier (min_pct, rate, fixed) VALUES (?, ?, ?)', [(float)str_replace(',', '.', post('new_min')), (float)str_replace(',', '.', post('new_rate')), (int)preg_replace('/\D/', '', post('new_fixed'))]);
        audit('bonus_tiers', 'diubah');
        flash('Skema bonus disimpan. Simpan ulang bonus periode yang sudah diisi agar ikut skema baru.');
        redirect('payroll.php?' . http_build_query(['p' => 'bonus', 'from' => $from, 'to' => $to]));
    } elseif ($action === 'loan_add' || $action === 'loan_approve') {
        $amount = (int)preg_replace('/\D/', '', post('jumlah'));
        $inst = (int)preg_replace('/\D/', '', post('cicilan'));
        $back = 'payroll.php?p=loans';
        if ($amount <= 0 || $inst <= 0 || $inst > $amount) { flash('Isi jumlah kasbon dan cicilan per periode (tidak lebih dari jumlah).', 'err'); redirect($back); }
        if ($action === 'loan_add') {
            $emp = employee(post('pegawai_id'));
            if (!$emp || !valid_date(post('tgl'))) { flash('Pilih karyawan dan tanggal.', 'err'); redirect($back); }
            q('INSERT INTO web_loan (pegawai_id, tgl, jumlah, cicilan, keterangan, status, created_at, decided_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$emp['pegawai_id'], post('tgl'), $amount, $inst, post('keterangan'), LOAN_ACTIVE, date('Y-m-d H:i:s'), actor()]);
            audit('loan_add', "{$emp['pegawai_nama']} $amount");
        } else {
            q('UPDATE web_loan SET jumlah = ?, cicilan = ?, tgl = ?, status = ?, decided_by = ? WHERE id = ? AND status = ?',
                [$amount, $inst, valid_date(post('tgl')) ? post('tgl') : date('Y-m-d'), LOAN_ACTIVE, actor(), (int)post('id'), LOAN_PENDING]);
            audit('loan_approve', '#' . (int)post('id') . " $amount");
        }
        flash('Kasbon dicatat; cicilan dipotong mulai gaji periode yang berakhir setelah tanggal kasbon.');
        redirect($back);
    } elseif ($action === 'loan_reject') {
        q('UPDATE web_loan SET status = ?, decided_by = ? WHERE id = ? AND status = ?', [LOAN_REJECTED, actor(), (int)post('id'), LOAN_PENDING]);
        audit('loan_reject', '#' . (int)post('id'));
        flash('Pengajuan kasbon ditolak.');
        redirect('payroll.php?p=loans');
    } elseif ($action === 'loan_pay') {
        $loan = q("SELECT * FROM web_loan WHERE id = ? AND status = 'active'", [(int)post('id')])->fetch();
        $amount = (int)preg_replace('/\D/', '', post('jumlah'));
        if ($loan && $amount > 0) {
            $amount = min($amount, $loan['jumlah'] - loan_repaid($loan['id']));
            q('INSERT INTO web_loan_pay (loan_id, period_to, tgl, jumlah, note) VALUES (?, NULL, ?, ?, ?)', [$loan['id'], valid_date(post('tgl')) ? post('tgl') : date('Y-m-d'), $amount, 'Bayar tunai']);
            refresh_loan_status();
            audit('loan_pay', "#{$loan['id']} $amount");
            flash('Pembayaran ' . rupiah($amount) . ' dicatat.');
        }
        redirect('payroll.php?p=loans&id=' . (int)post('id'));
    } elseif ($action === 'thr_create') {
        if (post('name') === '' || !valid_date(post('holiday')) || !valid_date(post('pay_date'))) { flash('Isi nama, tanggal hari raya dan tanggal bayar.', 'err'); redirect('payroll.php?p=thr'); }
        q('INSERT INTO web_thr (name, holiday, pay_date, created_at, created_by) VALUES (?, ?, ?, ?, ?)', [post('name'), post('holiday'), post('pay_date'), date('Y-m-d H:i:s'), actor()]);
        $thrId = (int)db()->lastInsertId();
        save_thr($thrId, calculate_thr(post('holiday'), post('pay_date')));
        audit('thr_create', post('name'));
        flash('THR dihitung. Periksa, ubah jumlah bila perlu, lalu terbitkan.');
        redirect('payroll.php?p=thr&id=' . $thrId);
    } elseif (in_array($action, ['thr_recalc', 'thr_publish', 'thr_unpublish', 'thr_delete'], true)) {
        $thr = q('SELECT * FROM web_thr WHERE id = ?', [(int)post('id')])->fetch();
        if (!$thr) redirect('payroll.php?p=thr');
        if ($action === 'thr_recalc' && !$thr['published']) {
            $over = [];
            $rule = q('SELECT pegawai_id, rule_amount FROM web_thr_slip WHERE thr_id = ?', [$thr['id']])->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach (isset($_POST['a']) ? (array)$_POST['a'] : [] as $id => $v) {
                $v = (int)preg_replace('/\D/', '', $v);
                if (!isset($rule[$id]) || $v !== (int)$rule[$id]) $over[(int)$id] = $v;   // keep only amounts HR changed
            }
            save_thr($thr['id'], calculate_thr($thr['holiday'], $thr['pay_date'], $over));
            flash('THR dihitung ulang.');
        } elseif ($action === 'thr_publish' || $action === 'thr_unpublish') {
            q('UPDATE web_thr SET published = ? WHERE id = ?', [$action === 'thr_publish' ? 1 : 0, $thr['id']]);
            flash($action === 'thr_publish' ? 'Slip THR diterbitkan ke Portal Pegawai.' : 'Slip THR ditarik dari Portal Pegawai.');
        } elseif ($action === 'thr_delete' && !$thr['published']) {
            q('DELETE FROM web_thr_slip WHERE thr_id = ?', [$thr['id']]);
            q('DELETE FROM web_thr WHERE id = ?', [$thr['id']]);
            flash('THR dihapus.');
            audit('thr_delete', $thr['name']);
            redirect('payroll.php?p=thr');
        }
        audit($action, $thr['name']);
        redirect('payroll.php?p=thr&id=' . $thr['id']);
    } elseif ($action === 'comp_define') {
        foreach (isset($_POST['c']) ? (array)$_POST['c'] : [] as $cid => $v) {
            q('UPDATE web_pay_component SET name = ?, kind = ?, fixed = ?, per = ?, active = ? WHERE id = ?',
                [mb_substr(trim($v['name']), 0, 60) ?: 'Komponen', $v['kind'] === 'deduction' ? 'deduction' : 'earning', empty($v['fixed']) ? 0 : 1, $v['per'] === 'day' ? 'day' : 'month', empty($v['active']) ? 0 : 1, (int)$cid]);
        }
        if (trim(post('new_name')) !== '') q('INSERT INTO web_pay_component (name, kind, fixed, per, sort) VALUES (?, ?, ?, ?, 99)',
            [mb_substr(trim(post('new_name')), 0, 60), post('new_kind') === 'deduction' ? 'deduction' : 'earning', post('new_fixed') ? 1 : 0, post('new_per') === 'day' ? 'day' : 'month']);
        audit('pay_component_define', 'diubah');
        flash('Daftar komponen disimpan.');
        redirect('payroll.php?p=components');
    } elseif ($action === 'comp_amounts') {
        $eff = valid_date(post('effective')) ? post('effective') : date('Y-m-d');
        $cur = components_as_of($eff);
        $n = 0;
        foreach (isset($_POST['a']) ? (array)$_POST['a'] : [] as $id => $vals) {
            foreach ((array)$vals as $cid => $v) {
                $amt = (int)preg_replace('/\D/', '', $v);
                $old = isset($cur[$id][$cid]) ? $cur[$id][$cid] : 0;
                if ($amt === $old) continue;   // only changes get a new dated row
                q('REPLACE INTO web_pay_emp_comp (pegawai_id, component_id, effective_from, amount, created_by) VALUES (?, ?, ?, ?, ?)', [(int)$id, (int)$cid, $eff, $amt, actor()]);
                $n++;
            }
        }
        audit('pay_component_amounts', "$n perubahan berlaku $eff");
        flash("$n nilai komponen diubah, berlaku mulai " . fmt_date($eff) . '.');
        redirect('payroll.php?p=components');
    } elseif ($action === 'settle_create') {
        $emp = q('SELECT * FROM pegawai WHERE pegawai_id = ?', [(int)post('pegawai_id')])->fetch();
        $reason = isset(settlement_reasons()[post('reason')]) ? post('reason') : '';
        if (!$emp || !$reason || !valid_date(post('end_date')) || !valid_date(post('start'))) { flash('Pilih karyawan, alasan, tanggal mulai dan tanggal berakhir.', 'err'); redirect('payroll.php?p=settle'); }
        $num = function ($k) { return (int)preg_replace('/\D/', '', post($k)); };
        $in = ['reason' => $reason, 'upah' => $num('upah'), 'start' => post('start'), 'end' => post('end_date'), 'leave_days' => (float)str_replace(',', '.', post('leave_days')),
               'leave_divisor' => max(1, (int)post('leave_divisor')), 'uang_pisah' => $num('uang_pisah'), 'other' => $num('other'), 'pkwt_months' => (int)post('pkwt_months')];
        $calc = calculate_settlement($in);
        q('INSERT INTO web_settlement (pegawai_id, reason, end_date, detail, total, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$emp['pegawai_id'], $reason, $in['end'], json_encode(['in' => $in, 'calc' => $calc, 'note' => post('note')]), $calc['net'], 'draft', actor(), date('Y-m-d H:i:s')]);
        $sid = (int)db()->lastInsertId();
        audit('settlement_create', "{$emp['pegawai_nama']} $reason {$in['end']}");
        flash('Perhitungan disimpan sebagai draf.');
        redirect('payroll.php?p=settle&id=' . $sid);
    } elseif ($action === 'settle_final' || $action === 'settle_delete') {
        $st = q('SELECT s.*, p.pegawai_nama FROM web_settlement s JOIN pegawai p ON p.pegawai_id = s.pegawai_id WHERE s.id = ? AND s.status = ?', [(int)post('id'), 'draft'])->fetch();
        if ($st && $action === 'settle_delete') {
            q('DELETE FROM web_settlement WHERE id = ?', [$st['id']]);
            flash('Draf dihapus.');
            redirect('payroll.php?p=settle');
        }
        if ($st) {
            // the employee leaves: status Berhenti, last day, and the offboarding checklist
            q('UPDATE web_settlement SET status = ? WHERE id = ?', ['final', $st['id']]);
            q('UPDATE pegawai SET pegawai_status = 2, tgl_resign = ? WHERE pegawai_id = ?', [$st['end_date'], $st['pegawai_id']]);
            require_once __DIR__ . '/inc/talent.php';
            ensure_talent_tables();
            if (!q("SELECT COUNT(*) FROM web_checklist WHERE pegawai_id = ? AND type = 'offboarding'", [$st['pegawai_id']])->fetchColumn()) start_checklist($st['pegawai_id'], 'offboarding', $st['end_date']);
            audit('settlement_final', "{$st['pegawai_nama']} {$st['end_date']}");
            flash("{$st['pegawai_nama']} berhenti per " . fmt_date($st['end_date']) . '; checklist offboarding dimulai. Gaji terakhir dihitung prorata di Proses gaji.');
        }
        redirect('payroll.php?p=settle&id=' . (int)post('id'));
    } elseif ($action === 'setup') {
        $n = 0;
        $eff = valid_date(post('effective')) ? post('effective') : date('Y-m-d');
        $before = pay_data();
        foreach (isset($_POST['e']) ? (array)$_POST['e'] : [] as $id => $v) {
            $num = function ($k) use ($v) { return isset($v[$k]) ? (int)preg_replace('/\D/', '', $v[$k]) : 0; };
            // a change of pay is kept in the history from the chosen date; the first time, the old amounts are kept
            // as the history before it, so earlier periods still calculate with them
            $old = isset($before[$id]) ? $before[$id] : null;
            if ($old && ($num('gaji_pokok') != $old['gaji_pokok'] || $num('tunjangan') != $old['tunjangan'] || $num('uang_harian') != $old['uang_harian'] || $num('upah_harian') != $old['upah_harian'])) {
                if (!q('SELECT COUNT(*) FROM web_pay_history WHERE pegawai_id = ?', [(int)$id])->fetchColumn())
                    save_pay_history((int)$id, '2000-01-01', $old['gaji_pokok'], $old['tunjangan'], $old['uang_harian'], 'Sebelum riwayat dicatat', $old['upah_harian']);
                save_pay_history((int)$id, $eff, $num('gaji_pokok'), $num('tunjangan'), $num('uang_harian'), post('note'), $num('upah_harian'));
            }
            $ptkp = isset($v['ptkp']) && in_array($v['ptkp'], ptkp_options(), true) ? $v['ptkp'] : 'TK/0';
            q('REPLACE INTO web_pay_emp (pegawai_id, gaji_pokok, tunjangan, uang_harian, upah_harian, ptkp, npwp, bpjs_kes, bpjs_tk, bpjs_jp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [(int)$id, $num('gaji_pokok'), $num('tunjangan'), $num('uang_harian'), $num('upah_harian'), $ptkp, substr(trim(isset($v['npwp']) ? $v['npwp'] : ''), 0, 30),
                 empty($v['bpjs_kes']) ? 0 : 1, empty($v['bpjs_tk']) ? 0 : 1, empty($v['bpjs_jp']) ? 0 : 1]);
            $n++;
        }
        audit('payroll_setup', "$n karyawan, berlaku $eff");
        flash('Data gaji disimpan. Perubahan gaji berlaku mulai ' . fmt_date($eff) . '.');
        redirect('payroll.php?p=setup');
    } elseif ($action === 'settings') {
        foreach (payroll_defaults() as $k => $def) {
            $v = str_replace(',', '.', post($k, (string)$def));
            if (is_numeric($v) && $v >= 0) q('REPLACE INTO web_pay_setting (k, v) VALUES (?, ?)', [$k, $v]);
        }
        foreach (journal_accounts() as $k => $a) if (post($k) !== '') q('REPLACE INTO web_pay_setting (k, v) VALUES (?, ?)', [$k, mb_substr(trim(post($k)), 0, 30)]);
        audit('payroll_settings', 'tarif diubah');
        flash('Pengaturan gaji disimpan.');
        redirect('payroll.php?p=settings');
    }
}

// ---------- exports ----------
if (get('csv') !== '') {
    $rows = q('SELECT s.*, p.pegawai_pin, p.pegawai_nama, p.nama_bank, p.nama_rek, p.no_rek FROM web_payslip s JOIN pegawai p ON p.pegawai_id = s.pegawai_id
               WHERE s.period_to = ? ORDER BY p.pegawai_pin + 0', [$to])->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    $names = ['bupot' => 'pph21_', 'bank' => 'transfer_', 'bpjs' => 'bpjs_', 'journal' => 'jurnal_gaji_'];
    header('Content-Disposition: attachment; filename="' . (isset($names[get('csv')]) ? $names[get('csv')] : 'gaji_') . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if (get('csv') === 'bank') {   // transfer list for the bank's bulk / payroll upload: one row per employee with an account
        fputcsv($out, ['No', 'No. rekening', 'Nama rekening', 'Bank', 'Jumlah', 'Keterangan', 'PIN', 'Nama karyawan']);
        $i = 0;
        foreach ($rows as $r) if ($r['netto'] > 0) fputcsv($out, [++$i, preg_replace('/\D/', '', (string)$r['no_rek']), $r['nama_rek'] ?: $r['pegawai_nama'], $r['nama_bank'], (int)$r['netto'], 'Gaji ' . date('m/Y', strtotime($to)), $r['pegawai_pin'], $r['pegawai_nama'] . ($r['no_rek'] ? '' : ' (NO. REKENING KOSONG)')]);
    } elseif (get('csv') === 'bpjs') {   // contributions per employee and programme, for the BPJS reports / payment
        fputcsv($out, ['PIN', 'Nama', 'NIK / NPWP', 'Upah dilaporkan', 'Kes perusahaan', 'Kes karyawan', 'JHT perusahaan', 'JHT karyawan', 'JP perusahaan', 'JP karyawan', 'JKK', 'JKM', 'Total']);
        $sum = array_fill(0, 9, 0);
        foreach ($rows as $r) {
            $l = json_decode($r['detail'], true); $b = bpjs_lines($l);
            $wage = 0; foreach ($l['earnings'] as $k => $v) if (strpos($k, 'Gaji pokok') === 0 || strpos($k, 'Tunjangan tetap') === 0 || strpos($k, 'Upah harian') === 0) $wage += $v;
            $vals = [$b['kes'][0], $b['kes'][1], $b['jht'][0], $b['jht'][1], $b['jp'][0], $b['jp'][1], $b['jkk'][0], $b['jkm'][0]];
            if (!array_sum($vals)) continue;
            fputcsv($out, array_merge([$r['pegawai_pin'], $r['pegawai_nama'], $l['npwp'], $wage], $vals, [array_sum($vals)]));
            foreach ($vals as $i => $v) $sum[$i] += $v; $sum[8] += array_sum($vals);
        }
        fputcsv($out, array_merge(['', 'TOTAL', '', ''], $sum));
    } elseif (get('csv') === 'journal') {   // accounting journal of the period
        $acc = journal_accounts();
        fputcsv($out, ['Tanggal', 'Kode akun', 'Nama akun', 'Keterangan', 'Debit', 'Kredit']);
        foreach (payroll_journal(array_map(function ($r) { return json_decode($r['detail'], true); }, $rows)) as $j)
            fputcsv($out, [$to, $acc[$j[0]][1], $acc[$j[0]][0], 'Gaji periode ' . date('d-m-Y', strtotime($rows ? $rows[0]['period_from'] : $to)) . ' s/d ' . date('d-m-Y', strtotime($to)), $j[1], $j[2]]);
    } elseif (get('csv') === 'bupot') {   // data for the PPh 21 monthly withholding (e-Bupot 21)
        fputcsv($out, ['Masa', 'Tahun', 'PIN', 'Nama', 'NPWP/NIK', 'PTKP', 'Kategori TER', 'Penghasilan bruto', 'PPh 21 dipotong', 'Keterangan']);
        foreach ($rows as $r) {
            $l = json_decode($r['detail'], true);
            fputcsv($out, [date('n', strtotime($to)), date('Y', strtotime($to)), $r['pegawai_pin'], $r['pegawai_nama'], $l['npwp'], $l['ptkp'], isset($l['pay_type']) && $l['pay_type'] === 'daily' ? 'Harian (bukan pegawai tetap)' : ter_category($l['ptkp']), $r['bruto'], $r['pph21'], $l['pph21_note']]);
        }
    } else {                         // payroll report and transfer list
        fputcsv($out, ['PIN', 'Nama', 'Periode', 'Hari hadir', 'Pendapatan', 'Potongan', 'PPh 21', 'Take home pay', 'Bank', 'Nama rekening', 'No. rekening']);
        foreach ($rows as $r) {
            $l = json_decode($r['detail'], true);
            fputcsv($out, [$r['pegawai_pin'], $r['pegawai_nama'], $r['period_from'] . ' s/d ' . $r['period_to'], $l['counts']['present'],
                           array_sum($l['earnings']), array_sum($l['deductions']) - $r['pph21'], $r['pph21'], $r['netto'], $r['nama_bank'], $r['nama_rek'], $r['no_rek']]);
        }
    }
    exit;
}

$csrf = csrf_field();
$periodPicker = function ($pp) use ($from, $to) {
    $prev = date('Y-m-21', strtotime($from . ' -1 month')); $prevTo = date('Y-m-20', strtotime($to . ' -1 month'));
    $next = date('Y-m-21', strtotime($from . ' +1 month')); $nextTo = date('Y-m-20', strtotime($to . ' +1 month'));
    return '<form class="toolbar" method="get"><input type="hidden" name="p" value="' . $pp . '">'
         . '<a class="btn small" href="payroll.php?' . h(http_build_query(['p' => $pp, 'from' => $prev, 'to' => $prevTo])) . '">‹</a>'
         . '<input type="date" name="from" value="' . h($from) . '"> s/d <input type="date" name="to" value="' . h($to) . '">'
         . '<a class="btn small" href="payroll.php?' . h(http_build_query(['p' => $pp, 'from' => $next, 'to' => $nextTo])) . '">›</a>'
         . '<button class="btn">Tampilkan</button></form>';
};

// ======================================================================= Proses gaji
if ($p === 'run'):
    $rows = calculate_payroll($from, $to);
    $saved = q('SELECT COUNT(*) n, MAX(published) pub, MAX(created_at) at FROM web_payslip WHERE period_to = ?', [$to])->fetch();
    $missing = count(employees(true)) - count($rows);
    $tot = ['earn' => 0, 'ded' => 0, 'pph' => 0, 'net' => 0, 'co' => 0];
    foreach ($rows as $r) { $tot['earn'] += array_sum($r['earnings']); $tot['ded'] += array_sum($r['deductions']) - $r['pph21']; $tot['pph'] += $r['pph21']; $tot['net'] += $r['netto']; $tot['co'] += array_sum($r['company']); }
    hris_header('Proses gaji', 'payroll', 'payroll.php?p=run', 'Penggajian',
        '<form method="post" class="inline">' . $csrf . '<input type="hidden" name="from" value="' . h($from) . '"><input type="hidden" name="to" value="' . h($to) . '">'
        . '<button class="btn" name="action" value="save"' . ($rows ? '' : ' disabled') . '>Simpan draf</button> '
        . '<button class="btn primary" name="action" value="publish"' . ($rows ? '' : ' disabled') . ' onclick="return confirm(\'Simpan dan terbitkan slip gaji ke Portal Pegawai?\')">Simpan &amp; terbitkan</button></form>'); ?>
  <?= $periodPicker('run') ?>
  <?php if ($saved['n']): ?><div class="notice"><div><b><?= $saved['pub'] ? 'Sudah diterbitkan' : 'Draf tersimpan' ?></b>Periode ini disimpan <?= h(date('d-m-Y H:i', strtotime($saved['at']))) ?>. Menyimpan lagi menghitung ulang dari data kehadiran terbaru dan menimpa slip.</div></div><?php endif; ?>
  <?php if ($missing > 0): ?><div class="notice"><div><b><?= $missing ?> karyawan aktif belum punya data gaji</b>Isi di <a href="payroll.php?p=setup">Data gaji karyawan</a>; mereka tidak ikut dihitung.</div></div><?php endif; ?>
  <div class="counters" style="grid-template-columns:repeat(5,minmax(0,1fr))">
    <div class="counter"><b><?= count($rows) ?></b><span>Karyawan</span></div>
    <div class="counter"><b><?= rupiah($tot['earn']) ?></b><span>Total pendapatan</span></div>
    <div class="counter"><b><?= rupiah($tot['pph']) ?></b><span>PPh 21</span></div>
    <div class="counter"><b><?= rupiah($tot['net']) ?></b><span>Take home pay</span></div>
    <div class="counter"><b><?= rupiah($tot['co']) ?></b><span>BPJS dibayar perusahaan</span></div>
  </div>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>PTKP</th><th class="num">Hadir</th><th class="num">Gaji + tunjangan</th><th class="num">Uang harian</th><th class="num">Lembur</th><th class="num">Bonus</th>
      <th class="num">Potongan absensi</th><th class="num">Kasbon</th><th class="num">BPJS karyawan</th><th class="num">PPh 21</th><th class="num">Take home pay</th><th class="actions">Slip</th></tr></thead><tbody>
    <?php foreach ($rows as $r):
        $e = $r['earnings']; $d = $r['deductions'];
        $harian = 0; foreach ($e as $k => $v) if (strpos($k, 'Uang makan') === 0) $harian = $v;
        $absensi = 0; foreach ($d as $k => $v) if (strpos($k, 'Potongan tidak hadir') === 0 || strpos($k, 'Potongan terlambat') === 0) $absensi += $v;
        $gt = 0; foreach ($e as $k => $v) if (strpos($k, 'Gaji pokok') === 0 || strpos($k, 'Tunjangan tetap') === 0) $gt += $v;
        $bonus = 0; foreach ($e as $k => $v) if (strpos($k, 'Bonus') === 0) $bonus += $v;
        $bpjsEmp = (isset($d['BPJS Kesehatan (1%)']) ? $d['BPJS Kesehatan (1%)'] : 0) + (isset($d['BPJS JHT (2%)']) ? $d['BPJS JHT (2%)'] : 0) + (isset($d['BPJS JP (1%)']) ? $d['BPJS JP (1%)'] : 0); ?>
      <tr><td><div class="who"><span class="avatar"><?= h(initials($r['name'])) ?></span><div><b><?= h($r['name']) ?></b><small>PIN <?= h($r['pin']) ?></small></div></div></td>
        <td><?= h($r['ptkp']) ?></td><td class="num"><?= $r['counts']['present'] ?>/<?= $r['counts']['workdays'] ?></td>
        <td class="num"><?= rupiah($gt) ?><?= $r['prorate'] ? '<br><small class="muted">prorata ' . $r['prorate'][0] . '/' . $r['prorate'][1] . '</small>' : '' ?></td>
        <td class="num"><?= $harian ? rupiah($harian) : '' ?></td><td class="num"><?= isset($e['Lembur']) ? rupiah($e['Lembur']) : '' ?></td><td class="num"><?= $bonus ? rupiah($bonus) : '' ?></td>
        <td class="num red"><?= $absensi ? rupiah($absensi) : '' ?></td><td class="num"><?= $r['kasbon'] ? rupiah($r['kasbon']) : '' ?></td><td class="num"><?= rupiah($bpjsEmp) ?></td>
        <td class="num" title="<?= h($r['pph21_note']) ?>"><?= rupiah($r['pph21']) ?></td><td class="num"><b><?= rupiah($r['netto']) ?></b></td>
        <td class="actions"><?php if ($saved['n']): ?><a class="btn small" href="payslip.php?<?= h(http_build_query(['to' => $to, 'id' => $r['pegawai_id']])) ?>" target="_blank">Lihat</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php if (!$rows): ?><div class="empty"><strong>Belum ada data gaji</strong>Isi gaji pokok dan tunjangan di <a href="payroll.php?p=setup">Data gaji karyawan</a>.</div><?php endif; ?></div>
  <p class="muted">Dihitung dari kehadiran <?= h(fmt_date($from)) ?> – <?= h(fmt_date($to)) ?>. Arahkan kursor ke angka PPh 21 untuk melihat cara hitungnya. Simpan untuk membuat slip gaji.</p>
<?php
// ======================================================================= Riwayat & slip
elseif ($p === 'slips'):
    $periods = q('SELECT period_from, period_to, COUNT(*) n, SUM(bruto) bruto, SUM(pph21) pph, SUM(netto) net, MAX(published) pub
                  FROM web_payslip GROUP BY period_from, period_to ORDER BY period_to DESC')->fetchAll();
    $sel = get('to') !== '' ? get('to') : ($periods ? $periods[0]['period_to'] : '');
    $slips = $sel ? q('SELECT s.*, p.pegawai_pin, p.pegawai_nama FROM web_payslip s JOIN pegawai p ON p.pegawai_id = s.pegawai_id WHERE s.period_to = ? ORDER BY p.pegawai_nama', [$sel])->fetchAll() : [];
    hris_header('Riwayat & slip gaji', 'payroll', 'payroll.php?p=slips', 'Penggajian'); ?>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Periode</th><th class="num">Karyawan</th><th class="num">Bruto PPh 21</th><th class="num">PPh 21</th><th class="num">Take home pay</th><th>Status</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($periods as $r): ?><tr><td><a href="payroll.php?p=slips&to=<?= h($r['period_to']) ?>"><b><?= h(date('d-m-Y', strtotime($r['period_from']))) ?> – <?= h(date('d-m-Y', strtotime($r['period_to']))) ?></b></a></td>
      <td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= rupiah($r['bruto']) ?></td><td class="num"><?= rupiah($r['pph']) ?></td><td class="num"><?= rupiah($r['net']) ?></td>
      <td><span class="pill <?= $r['pub'] ? 'ok' : 'grey' ?>"><?= $r['pub'] ? 'Diterbitkan' : 'Draf' ?></span></td>
      <td class="actions" style="white-space:nowrap"><a class="btn small" href="payroll.php?<?= h(http_build_query(['csv' => 'gaji', 'to' => $r['period_to']])) ?>">Laporan gaji</a>
        <a class="btn small" href="payroll.php?<?= h(http_build_query(['csv' => 'bupot', 'to' => $r['period_to']])) ?>">Data e-Bupot 21</a>
        <a class="btn small" href="payroll.php?<?= h(http_build_query(['csv' => 'bank', 'to' => $r['period_to']])) ?>">Transfer bank</a>
        <a class="btn small" href="payroll.php?<?= h(http_build_query(['csv' => 'bpjs', 'to' => $r['period_to']])) ?>">Iuran BPJS</a>
        <a class="btn small" href="payroll.php?<?= h(http_build_query(['csv' => 'journal', 'to' => $r['period_to']])) ?>">Jurnal</a>
        <?php if ($r['pub']): ?><form method="post" class="inline" onsubmit="return confirm('Tarik slip periode ini dari Portal Pegawai?')"><?= $csrf ?><input type="hidden" name="action" value="unpublish"><input type="hidden" name="to" value="<?= h($r['period_to']) ?>"><button class="btn small">Tarik</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php if (!$periods): ?><div class="empty"><strong>Belum ada gaji yang disimpan</strong>Hitung dan simpan di <a href="payroll.php?p=run">Proses gaji</a>.</div><?php endif; ?></div>
  <?php if ($slips): ?>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th class="num">Bruto</th><th class="num">PPh 21</th><th class="num">Take home pay</th><th class="actions">Slip</th></tr></thead><tbody>
    <?php foreach ($slips as $r): ?><tr><td><b><?= h($r['pegawai_nama']) ?></b> <span class="muted">PIN <?= h($r['pegawai_pin']) ?></span></td><td class="num"><?= rupiah($r['bruto']) ?></td>
      <td class="num"><?= rupiah($r['pph21']) ?></td><td class="num"><b><?= rupiah($r['netto']) ?></b></td>
      <td class="actions"><a class="btn small" target="_blank" href="payslip.php?<?= h(http_build_query(['to' => $r['period_to'], 'id' => $r['pegawai_id']])) ?>">Lihat / cetak</a></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  <?php endif; ?>
<?php
// ======================================================================= Reimbursement
elseif ($p === 'reimburse'):
    $state = get('s', CLAIM_PENDING);
    $cnt = q('SELECT status, COUNT(*) n, SUM(jumlah) t FROM web_reimburse GROUP BY status')->fetchAll(PDO::FETCH_UNIQUE);
    $rows = q('SELECT r.*, p.pegawai_nama, p.pegawai_pin FROM web_reimburse r JOIN pegawai p ON p.pegawai_id = r.pegawai_id WHERE r.status = ? ORDER BY r.tgl DESC, r.id DESC LIMIT 500', [$state])->fetchAll();
    $back = h($_SERVER['REQUEST_URI']);
    hris_header('Reimbursement', 'payroll', 'payroll.php?p=reimburse', 'Penggajian'); ?>
  <form method="post" class="section" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:12px"><?= $csrf ?><input type="hidden" name="action" value="rb_add">
    <label class="field"><span>Karyawan</span><select name="pegawai_id" required><?php foreach (employees(true) as $e): ?><option value="<?= (int)$e['pegawai_id'] ?>"><?= h($e['pegawai_nama']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Tanggal</span><input type="date" name="tgl" value="<?= h($to) ?>" required></label>
    <label class="field"><span>Kategori</span><select name="kategori"><?php foreach (reimburse_categories() as $c): ?><option><?= h($c) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Jumlah (Rp)</span><input type="text" inputmode="numeric" name="jumlah" required style="width:120px"></label>
    <label class="field" style="flex:1 1 220px"><span>Keterangan</span><input type="text" name="keterangan" maxlength="255" placeholder="mis. Bensin 17 trip × 15 km × Rp500"></label>
    <button class="btn primary">Tambah klaim (langsung disetujui)</button></form>
  <div class="counters" style="grid-template-columns:repeat(3,minmax(0,1fr))">
    <?php foreach ([CLAIM_PENDING => 'Menunggu persetujuan', CLAIM_APPROVED => 'Disetujui', CLAIM_REJECTED => 'Ditolak'] as $k => $label): $c = isset($cnt[$k]) ? $cnt[$k] : ['n' => 0, 't' => 0]; ?>
      <a class="counter<?= $state === $k ? ' on' : '' ?>" href="payroll.php?p=reimburse&s=<?= $k ?>"><b><?= (int)$c['n'] ?></b><span><?= $label ?> · <?= rupiah($c['t']) ?></span></a>
    <?php endforeach; ?>
  </div>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>Tanggal</th><th>Kategori</th><th class="num">Jumlah</th><th>Keterangan</th><th>Bukti</th><th>Status</th><th>Dibayar</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><b><?= h($r['pegawai_nama']) ?></b> <span class="muted">PIN <?= h($r['pegawai_pin']) ?></span></td><td><?= h(fmt_date($r['tgl'])) ?></td><td><?= h($r['kategori']) ?></td>
        <td class="num"><b><?= rupiah($r['jumlah']) ?></b></td><td><?= h($r['keterangan']) ?></td>
        <td><?= $r['file'] ? '<a href="receipt.php?id=' . (int)$r['id'] . '" target="_blank">Lihat</a>' : '—' ?></td><td><?= claim_badge($r['status']) ?></td>
        <td class="muted"><?= $r['paid_period_to'] ? 'gaji s/d ' . h(date('d-m-Y', strtotime($r['paid_period_to']))) : ($r['status'] === CLAIM_APPROVED ? 'gaji berikutnya' : '') ?></td>
        <td class="actions"><?php if ($r['status'] === CLAIM_PENDING): echo supervisor_badge('reimburse', $r['id'], $r['pegawai_id']) . ' '; foreach (['rb_approve' => ['primary', 'Setujui'], 'rb_reject' => ['outline', 'Tolak']] as $act => $b): ?>
          <form method="post" class="inline"><?= $csrf ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= $back ?>">
            <button class="btn small <?= $b[0] ?>"><?= $b[1] ?></button></form>
        <?php endforeach; endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php if (!$rows): ?><div class="empty"><strong>Tidak ada klaim</strong>Karyawan mengirim klaim di Portal Pegawai.</div><?php endif; ?></div>
  <p class="muted">Klaim yang disetujui ikut dibayar saat gaji periode berikutnya disimpan, sebagai pendapatan di luar penghasilan bruto PPh 21.</p>
<?php
// ======================================================================= Saldo awal PPh 21
elseif ($p === 'ytd'):
    $yr = (int)get('year', date('Y'));
    $pays = pay_data();
    $open = q('SELECT * FROM web_pph_open WHERE year = ?', [$yr])->fetchAll(PDO::FETCH_UNIQUE);
    hris_header('Saldo awal PPh 21', 'payroll', 'payroll.php?p=ytd', 'Penggajian'); ?>
  <form method="get" class="toolbar"><input type="hidden" name="p" value="ytd"><label>Tahun <input type="number" name="year" value="<?= $yr ?>" style="width:90px"></label><button class="btn">Tampilkan</button></form>
  <p class="muted">Isi total Januari sampai November yang <b>belum tercatat sebagai slip di aplikasi</b> (mis. dari file Excel). Slip yang sudah disimpan di aplikasi ikut dihitung sendiri, jadi jangan isi bulan yang sama dua kali. Dipakai pada periode Desember untuk PPh 21 setahun.</p>
  <form method="post"><?= $csrf ?><input type="hidden" name="action" value="ytd_save"><input type="hidden" name="year" value="<?= $yr ?>">
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>Jumlah bulan (1-11)</th><th>Penghasilan bruto Jan-Nov (Rp)</th><th>Iuran pensiun/JHT karyawan Jan-Nov (Rp)</th><th>PPh 21 sudah dipotong Jan-Nov (Rp)</th></tr></thead><tbody>
    <?php foreach (employees(true) as $e): $id = (int)$e['pegawai_id']; if (empty($pays[$id])) continue; $o = isset($open[$id]) ? $open[$id] : ['months' => '', 'bruto' => '', 'iuran' => '', 'pph' => '']; ?>
      <tr><td><b><?= h($e['pegawai_nama']) ?></b> <span class="muted"><?= h($e['pegawai_pin']) ?></span></td>
        <?php foreach (['months' => 70, 'bruto' => 140, 'iuran' => 120, 'pph' => 120] as $k => $w): ?>
        <td><input type="text" inputmode="numeric" name="y[<?= $id ?>][<?= $k ?>]" value="<?= $o[$k] !== '' && $o[$k] !== null ? h(number_format($o[$k], 0, ',', '.')) : '' ?>" style="width:<?= $w ?>px"></td><?php endforeach; ?></tr>
    <?php endforeach; ?></tbody></table></div></div>
  <div class="formfoot"><button class="btn primary">Simpan</button></div></form>
<?php
// ======================================================================= Bonus penjualan
elseif ($p === 'bonus'):
    $tiers = bonus_tiers();
    $rows = bonuses_for_period($to);
    $pays = pay_data();
    $codes = q('SELECT pegawai_id, code FROM web_sales_code')->fetchAll(PDO::FETCH_KEY_PAIR);
    $tot = 0;
    hris_header('Bonus penjualan', 'payroll', 'payroll.php?p=bonus', 'Penggajian'); ?>
  <?= $periodPicker('bonus') ?>
  <?php if (!erp_enabled()): ?><div class="notice"><div><b>WAU ERP belum terhubung</b>Isi erp_token di config.php (sama dengan hr_api_token di secret WAU ERP) agar realisasi penjualan bisa diambil dari surat jalan.</div></div><?php endif; ?>
  <form method="post"><?= $csrf ?><input type="hidden" name="action" value="bonus_save"><input type="hidden" name="from" value="<?= h($from) ?>"><input type="hidden" name="to" value="<?= h($to) ?>">
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th title="Kode salesman di WAU ERP (mastersalesman)">Kode sales ERP</th><th>Target penjualan</th><th>Realisasi penjualan</th><th class="num">Capaian</th><th class="num">Bonus penjualan</th><th>Bonus lain</th><th title="Jumlah hari menyetir sebagai driver (Rp10.000 per hari)">Hari driver</th><th title="Jumlah trip kirim sendiri, hanya dengan 1 kernet (Rp5.000 per trip)">Trip sendiri</th><th title="Isi untuk mengganti lembur hasil hitung scan; kosong = hitung otomatis, 0 = tanpa lembur">Lembur manual (Rp)</th><th>Keterangan</th></tr></thead><tbody>
    <?php foreach (employees(true) as $e): $id = (int)$e['pegawai_id']; if (empty($pays[$id])) continue;
        $v = isset($rows[$id]) ? $rows[$id] : ['target' => 0, 'sales' => 0, 'sales_bonus' => 0, 'other_bonus' => 0, 'note' => '', 'sales_source' => '', 'ot_manual' => null, 'trip_driver' => 0, 'trip_alone' => 0];
        list(, $pct) = sales_bonus($v['target'], $v['sales'], $tiers); $tot += $v['sales_bonus'] + $v['other_bonus'];
        $money = function ($k) use ($v, $id) { return '<input type="text" inputmode="numeric" name="b[' . $id . '][' . $k . ']" value="' . ($v[$k] ? h(number_format($v[$k], 0, ',', '.')) : '') . '" style="width:140px;text-align:right" placeholder="0">'; }; ?>
      <tr><td><b><?= h($e['pegawai_nama']) ?></b> <span class="muted"><?= h($e['pegawai_pin']) ?></span></td>
        <td><input type="text" name="b[<?= $id ?>][code]" value="<?= h(isset($codes[$id]) ? $codes[$id] : '') ?>" maxlength="10" style="width:70px;text-transform:uppercase" placeholder="—"></td>
        <td><?= $money('target') ?></td><td><?= $money('sales') ?><?php if ($v['sales_source'] !== ''): ?><br><small class="muted"><?= h($v['sales_source']) ?></small><?php endif; ?></td><td class="num"><?= $v['target'] ? str_replace('.', ',', $pct) . '%' : '' ?></td>
        <td class="num"><b><?= $v['sales_bonus'] ? rupiah($v['sales_bonus']) : '' ?></b></td><td><?= $money('other_bonus') ?></td>
        <td><input type="text" inputmode="numeric" name="b[<?= $id ?>][trip_driver]" value="<?= $v['trip_driver'] ?: '' ?>" style="width:60px"></td>
        <td><input type="text" inputmode="numeric" name="b[<?= $id ?>][trip_alone]" value="<?= $v['trip_alone'] ?: '' ?>" style="width:60px"></td>
        <td><input type="text" inputmode="numeric" name="b[<?= $id ?>][ot_manual]" value="<?= $v['ot_manual'] !== null ? h(number_format($v['ot_manual'], 0, ',', '.')) : '' ?>" style="width:140px" placeholder="otomatis"></td>
        <td><input type="text" name="b[<?= $id ?>][note]" value="<?= h($v['note']) ?>" maxlength="200" style="width:180px"></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
  <div class="formfoot"><span class="muted" style="margin-right:auto">Total bonus periode ini: <b><?= rupiah($tot) ?></b>. Bonus termasuk penghasilan bruto PPh 21 bulan ini. Hanya karyawan yang punya data gaji yang tampil.</span>
    <?php if (erp_enabled()): ?><button class="btn" name="action" value="bonus_erp" title="Isi realisasi penjualan karyawan yang punya kode sales dengan nilai surat jalan periode ini">Ambil dari ERP</button><?php endif; ?>
    <button class="btn primary">Simpan bonus</button></div></form>
  <form method="post" class="section" style="margin-top:16px"><?= $csrf ?><input type="hidden" name="action" value="tier_save"><input type="hidden" name="from" value="<?= h($from) ?>"><input type="hidden" name="to" value="<?= h($to) ?>">
    <h2>Skema bonus penjualan</h2>
    <small class="muted">Tingkat tertinggi yang tercapai berlaku: bonus = % dari realisasi penjualan + nominal tetap. Kosongkan "capaian minimal" untuk menghapus baris.</small>
    <table><thead><tr><th>Capaian minimal (% target)</th><th>Bonus (% dari penjualan)</th><th>Nominal tetap (Rp)</th></tr></thead><tbody>
      <?php foreach ($tiers as $t): ?><tr><td><input type="text" name="t[<?= (int)$t['id'] ?>][min_pct]" value="<?= h(rtrim(rtrim($t['min_pct'], '0'), '.')) ?>" style="width:90px"></td>
        <td><input type="text" name="t[<?= (int)$t['id'] ?>][rate]" value="<?= h(rtrim(rtrim($t['rate'], '0'), '.')) ?>" style="width:90px"></td>
        <td><input type="text" name="t[<?= (int)$t['id'] ?>][fixed]" value="<?= $t['fixed'] ? h(number_format($t['fixed'], 0, ',', '.')) : '' ?>" style="width:130px"></td></tr><?php endforeach; ?>
      <tr><td><input type="text" name="new_min" placeholder="mis. 150" style="width:90px"></td><td><input type="text" name="new_rate" style="width:90px"></td><td><input type="text" name="new_fixed" style="width:130px"></td></tr>
    </tbody></table>
    <div class="formfoot"><button class="btn">Simpan skema</button></div></form>
<?php
// ======================================================================= Kasbon
elseif ($p === 'loans'):
    $state = get('s', 'open');
    $where = $state === 'open' ? "l.status IN ('pending', 'active')" : ($state === 'paid' ? "l.status = 'paid'" : "l.status = 'rejected'");
    $loans = q("SELECT l.*, p.pegawai_nama, p.pegawai_pin, (SELECT COALESCE(SUM(jumlah), 0) FROM web_loan_pay x WHERE x.loan_id = l.id) repaid
                FROM web_loan l JOIN pegawai p ON p.pegawai_id = l.pegawai_id WHERE $where ORDER BY l.status = 'pending' DESC, l.tgl DESC")->fetchAll();
    $sel = get('id') !== '' ? q('SELECT l.*, p.pegawai_nama FROM web_loan l JOIN pegawai p ON p.pegawai_id = l.pegawai_id WHERE l.id = ?', [(int)get('id')])->fetch() : null;
    $out = q("SELECT COUNT(*) n, COALESCE(SUM(l.jumlah - (SELECT COALESCE(SUM(jumlah), 0) FROM web_loan_pay x WHERE x.loan_id = l.id)), 0) t FROM web_loan l WHERE status = 'active'")->fetch();
    hris_header('Kasbon', 'payroll', 'payroll.php?p=loans', 'Penggajian'); ?>
  <div class="counters" style="grid-template-columns:repeat(3,minmax(0,1fr))">
    <?php foreach (['open' => 'Menunggu & berjalan', 'paid' => 'Lunas', 'rejected' => 'Ditolak'] as $k => $label): ?><a class="counter<?= $state === $k ? ' on' : '' ?>" href="payroll.php?p=loans&s=<?= $k ?>"><b><?= $k === 'open' ? rupiah($out['t']) : '' ?></b><span><?= $label ?><?= $k === 'open' ? ' · sisa ' . (int)$out['n'] . ' kasbon' : '' ?></span></a><?php endforeach; ?>
  </div>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>Tanggal</th><th class="num">Jumlah</th><th class="num">Cicilan / periode</th><th class="num">Sudah dibayar</th><th class="num">Sisa</th><th>Keterangan</th><th>Status</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($loans as $l): ?>
      <tr><td><b><?= h($l['pegawai_nama']) ?></b> <span class="muted">PIN <?= h($l['pegawai_pin']) ?></span></td><td><?= h(date('d-m-Y', strtotime($l['tgl']))) ?></td>
        <td class="num"><?= rupiah($l['jumlah']) ?></td><td class="num"><?= rupiah($l['cicilan']) ?> <span class="muted">(<?= (int)ceil($l['jumlah'] / max(1, $l['cicilan'])) ?>×)</span></td>
        <td class="num"><?= rupiah($l['repaid']) ?></td><td class="num"><b><?= rupiah($l['jumlah'] - $l['repaid']) ?></b></td><td><?= h($l['keterangan']) ?></td><td><?= loan_badge($l['status']) ?></td>
        <td class="actions" style="white-space:nowrap"><?php if ($l['status'] === LOAN_PENDING): ?>
          <?= supervisor_badge('loan', $l['id'], $l['pegawai_id']) ?>
          <form method="post" class="inline"><?= $csrf ?><input type="hidden" name="action" value="loan_approve"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <input type="hidden" name="tgl" value="<?= date('Y-m-d') ?>"><input type="text" name="jumlah" value="<?= h(number_format($l['jumlah'], 0, ',', '.')) ?>" style="width:110px" title="Jumlah disetujui">
            <input type="text" name="cicilan" value="<?= h(number_format($l['cicilan'], 0, ',', '.')) ?>" style="width:100px" title="Cicilan per periode"><button class="btn small primary">Setujui</button></form>
          <form method="post" class="inline"><?= $csrf ?><input type="hidden" name="action" value="loan_reject"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn small outline">Tolak</button></form>
        <?php else: ?><a class="btn small" href="payroll.php?p=loans&s=<?= h($state) ?>&id=<?= (int)$l['id'] ?>">Riwayat</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php if (!$loans): ?><div class="empty"><strong>Tidak ada kasbon</strong>Karyawan dapat mengajukan kasbon di Portal Pegawai, atau tambahkan di bawah.</div><?php endif; ?></div>
  <?php if ($sel): $pays = q('SELECT * FROM web_loan_pay WHERE loan_id = ? ORDER BY tgl, id', [$sel['id']])->fetchAll(); ?>
  <div class="section" style="margin-top:16px"><h2>Kasbon #<?= (int)$sel['id'] ?> · <?= h($sel['pegawai_nama']) ?> · <?= rupiah($sel['jumlah']) ?></h2>
    <table><thead><tr><th>Tanggal</th><th>Cara</th><th class="num">Jumlah</th></tr></thead><tbody>
      <?php foreach ($pays as $x): ?><tr><td><?= h(date('d-m-Y', strtotime($x['tgl']))) ?></td><td><?= $x['period_to'] ? 'Potong gaji s/d ' . h(date('d-m-Y', strtotime($x['period_to']))) : h($x['note']) ?></td><td class="num"><?= rupiah($x['jumlah']) ?></td></tr><?php endforeach; ?>
      <?php if (!$pays): ?><tr><td colspan="3" class="muted">Belum ada pembayaran.</td></tr><?php endif; ?></tbody></table>
    <?php if ($sel['status'] === LOAN_ACTIVE): ?><form method="post" class="toolbar"><?= $csrf ?><input type="hidden" name="action" value="loan_pay"><input type="hidden" name="id" value="<?= (int)$sel['id'] ?>">
      <span>Catat pembayaran tunai</span><input type="date" name="tgl" value="<?= date('Y-m-d') ?>"><input type="text" name="jumlah" inputmode="numeric" placeholder="Jumlah" style="width:130px"><button class="btn">Catat</button></form><?php endif; ?></div>
  <?php endif; ?>
  <form method="post" class="section" style="margin-top:16px"><?= $csrf ?><input type="hidden" name="action" value="loan_add">
    <h2>Tambah kasbon</h2>
    <div class="row2"><label class="field"><span>Karyawan</span><select name="pegawai_id" required><option value="">Pilih…</option><?php foreach (employees(true) as $e): ?><option value="<?= (int)$e['pegawai_id'] ?>"><?= h($e['pegawai_nama']) ?> (<?= h($e['pegawai_pin']) ?>)</option><?php endforeach; ?></select></label>
      <label class="field"><span>Tanggal diberikan</span><input type="date" name="tgl" required value="<?= date('Y-m-d') ?>"></label>
      <label class="field"><span>Jumlah (Rp)</span><input type="text" name="jumlah" inputmode="numeric" required></label>
      <label class="field"><span>Cicilan per periode gaji (Rp)</span><input type="text" name="cicilan" inputmode="numeric" required></label></div>
    <label class="field"><span>Keterangan</span><input type="text" name="keterangan" maxlength="255"></label>
    <div class="formfoot"><button class="btn primary">Simpan kasbon</button></div></form>
<?php
// ======================================================================= THR
elseif ($p === 'thr'):
    $thr = get('id') !== '' ? q('SELECT * FROM web_thr WHERE id = ?', [(int)get('id')])->fetch() : null;
    if ($thr):
        $slips = q('SELECT s.*, p.pegawai_nama, p.pegawai_pin, p.tgl_mulai_kerja FROM web_thr_slip s JOIN pegawai p ON p.pegawai_id = s.pegawai_id WHERE s.thr_id = ? ORDER BY p.pegawai_nama', [$thr['id']])->fetchAll();
        $tot = ['a' => array_sum(array_column($slips, 'amount')), 't' => array_sum(array_column($slips, 'pph21')), 'n' => array_sum(array_column($slips, 'netto'))];
        $lock = (bool)$thr['published'];
        hris_header($thr['name'], 'payroll', 'payroll.php?p=thr', '<a href="payroll.php?p=thr">THR</a>',
            '<form method="post" class="inline">' . $csrf . '<input type="hidden" name="id" value="' . (int)$thr['id'] . '">'
            . ($lock ? '<button class="btn" name="action" value="thr_unpublish">Tarik dari portal</button>'
                     : '<button class="btn" name="action" value="thr_delete" onclick="return confirm(\'Hapus THR ini?\')">Hapus</button> <button class="btn primary" name="action" value="thr_publish" onclick="return confirm(\'Terbitkan slip THR ke Portal Pegawai?\')">Terbitkan</button>')
            . '</form>'); ?>
  <p class="muted" style="margin:0 0 12px">Hari raya <?= h(fmt_date($thr['holiday'])) ?> · dibayar <?= h(fmt_date($thr['pay_date'])) ?> (paling lambat 7 hari sebelum hari raya) ·
    <span class="pill <?= $lock ? 'ok' : 'grey' ?>"><?= $lock ? 'Diterbitkan' : 'Draf' ?></span></p>
  <div class="counters" style="grid-template-columns:repeat(4,minmax(0,1fr))"><div class="counter"><b><?= count($slips) ?></b><span>Karyawan</span></div>
    <div class="counter"><b><?= rupiah($tot['a']) ?></b><span>Total THR</span></div><div class="counter"><b><?= rupiah($tot['t']) ?></b><span>PPh 21</span></div><div class="counter"><b><?= rupiah($tot['n']) ?></b><span>Dibayarkan</span></div></div>
  <form method="post"><?= $csrf ?><input type="hidden" name="action" value="thr_recalc"><input type="hidden" name="id" value="<?= (int)$thr['id'] ?>">
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>Mulai bekerja</th><th class="num">Masa kerja</th><th class="num">Upah sebulan</th><th class="num">THR sesuai aturan</th><th>THR dibayar</th><th class="num">PPh 21</th><th class="num">Diterima</th><th>Catatan</th><th class="actions">Slip</th></tr></thead><tbody>
    <?php foreach ($slips as $s): ?>
      <tr><td><b><?= h($s['pegawai_nama']) ?></b> <span class="muted"><?= h($s['pegawai_pin']) ?></span></td><td><?= $s['tgl_mulai_kerja'] ? h(date('d-m-Y', strtotime($s['tgl_mulai_kerja']))) : '—' ?></td>
        <td class="num"><?= (int)$s['months'] >= 12 ? floor($s['months'] / 12) . ' th ' . ($s['months'] % 12) . ' bln' : (int)$s['months'] . ' bln' ?></td><td class="num"><?= rupiah($s['upah']) ?></td>
        <td class="num"><?= rupiah($s['rule_amount']) ?></td>
        <td><?php if ($lock): ?><b><?= rupiah($s['amount']) ?></b><?php else: ?><input type="text" name="a[<?= (int)$s['pegawai_id'] ?>]" value="<?= h(number_format($s['amount'], 0, ',', '.')) ?>" style="width:130px;text-align:right<?= $s['amount'] != $s['rule_amount'] ? ';border-color:var(--warn)' : '' ?>"><?php endif; ?></td>
        <td class="num"><?= rupiah($s['pph21']) ?></td><td class="num"><b><?= rupiah($s['netto']) ?></b></td><td class="muted" style="font-size:12px"><?= h($s['note']) ?></td>
        <td class="actions"><?php if ($s['amount']): ?><a class="btn small" target="_blank" href="payslip.php?<?= h(http_build_query(['thr' => $thr['id'], 'id' => $s['pegawai_id']])) ?>">Lihat</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php if (!$slips): ?><div class="empty"><strong>Tidak ada karyawan</strong>Hanya karyawan aktif dengan data gaji yang dihitung.</div><?php endif; ?></div>
  <?php if (!$lock): ?><div class="formfoot"><span class="muted" style="margin-right:auto">Ubah "THR dibayar" bila perusahaan memberi lebih dari aturan, lalu hitung ulang. Data gaji dan tanggal mulai kerja terbaru ikut dipakai.</span><button class="btn primary">Hitung ulang</button></div><?php endif; ?>
  </form>
  <?php else:
    $runs = q('SELECT t.*, COUNT(s.pegawai_id) n, COALESCE(SUM(s.amount), 0) total, COALESCE(SUM(s.pph21), 0) tax FROM web_thr t LEFT JOIN web_thr_slip s ON s.thr_id = t.id GROUP BY t.id ORDER BY t.pay_date DESC')->fetchAll();
    hris_header('THR', 'payroll', 'payroll.php?p=thr', 'Penggajian'); ?>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>THR</th><th>Hari raya</th><th>Dibayar</th><th class="num">Karyawan</th><th class="num">Total THR</th><th class="num">PPh 21</th><th>Status</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($runs as $r): ?><tr><td><b><?= h($r['name']) ?></b></td><td><?= h(date('d-m-Y', strtotime($r['holiday']))) ?></td><td><?= h(date('d-m-Y', strtotime($r['pay_date']))) ?></td>
      <td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= rupiah($r['total']) ?></td><td class="num"><?= rupiah($r['tax']) ?></td>
      <td><span class="pill <?= $r['published'] ? 'ok' : 'grey' ?>"><?= $r['published'] ? 'Diterbitkan' : 'Draf' ?></span></td><td class="actions"><a class="btn small" href="payroll.php?p=thr&id=<?= (int)$r['id'] ?>">Buka</a></td></tr><?php endforeach; ?>
    </tbody></table></div><?php if (!$runs): ?><div class="empty"><strong>Belum ada THR</strong>Buat perhitungan THR di bawah.</div><?php endif; ?></div>
  <?php $choices = lebaran_choices(date('Y-m-d')); $c0 = $choices ? $choices[0] : null;
        $label = function ($c) { return 'THR Idul Fitri ' . $c[0] . ($c[2] ? ' (' . $c[2] . ' H)' : ''); }; ?>
  <form method="post" class="section" style="margin-top:16px"><?= $csrf ?><input type="hidden" name="action" value="thr_create">
    <h2>Hitung THR</h2>
    <?php if ($choices): ?><label class="field"><span>Lebaran</span><select id="lebaran" onchange="var o=this.options[this.selectedIndex];this.form.name.value=o.dataset.name;this.form.holiday.value=o.value;this.form.pay_date.value=o.dataset.pay;document.getElementById('lebsrc').textContent=o.dataset.src">
      <?php foreach ($choices as $c): ?><option value="<?= h($c[1]) ?>" data-name="<?= h($label($c)) ?>" data-pay="<?= h($c[4]) ?>" data-src="Sumber tanggal: <?= h($c[3]) ?>"><?= h(fmt_date($c[1])) ?> · <?= h($label($c)) ?></option><?php endforeach; ?>
    </select><small id="lebsrc">Sumber tanggal: <?= h($c0[3]) ?></small></label><?php endif; ?>
    <label class="field"><span>Nama</span><input type="text" name="name" required value="<?= h($c0 ? $label($c0) : 'THR Idul Fitri ' . date('Y')) ?>"></label>
    <div class="row2"><label class="field"><span>Tanggal hari raya</span><input type="date" name="holiday" required value="<?= h($c0 ? $c0[1] : '') ?>"
        onchange="var d=new Date(this.value+'T00:00:00');if(!isNaN(d)){d.setDate(d.getDate()-7);if(d.getDay()===0)d.setDate(d.getDate()-1);this.form.pay_date.value=d.toISOString().slice(0,10)}"><small>Masa kerja dihitung sampai tanggal ini.</small></label>
      <label class="field"><span>Tanggal dibayar</span><input type="date" name="pay_date" required value="<?= h($c0 ? $c0[4] : '') ?>"><small>Diisi H-7 (paling lambat), mundur ke hari kerja bila Minggu atau libur. Boleh lebih awal. Menentukan masa pajak PPh 21.</small></label></div>
    <small class="muted">Permenaker 6/2016: masa kerja 12 bulan atau lebih = 1 bulan upah (gaji pokok + tunjangan tetap); 1–11 bulan = masa kerja / 12 × upah; di bawah 1 bulan tidak mendapat THR.</small>
    <div class="formfoot"><button class="btn primary">Hitung</button></div></form>
  <?php endif; ?>
<?php
// ======================================================================= Bukti potong (BPA1)
elseif ($p === 'bupot'):
    $year = (int)get('year', date('Y'));
    $list = q("SELECT DISTINCT p.pegawai_id, p.pegawai_nama, p.pegawai_pin FROM pegawai p WHERE p.pegawai_id IN
               (SELECT pegawai_id FROM web_payslip WHERE period_to BETWEEN ? AND ?) OR p.pegawai_id IN
               (SELECT s.pegawai_id FROM web_thr_slip s JOIN web_thr t ON t.id = s.thr_id WHERE t.pay_date BETWEEN ? AND ?) ORDER BY p.pegawai_nama",
              ["$year-01-01", "$year-12-31", "$year-01-01", "$year-12-31"])->fetchAll();
    hris_header('Bukti potong PPh 21', 'payroll', 'payroll.php?p=bupot', 'Penggajian'); ?>
  <form class="toolbar" method="get"><input type="hidden" name="p" value="bupot"><label>Tahun pajak <input type="number" name="year" value="<?= $year ?>" style="width:90px"></label><button class="btn">Tampilkan</button>
    <?php if ($list): ?><a class="btn" target="_blank" href="bupot.php?year=<?= $year ?>&all=1">Cetak semua</a><?php endif; ?></form>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>Masa perolehan</th><th class="num">Penghasilan bruto</th><th class="num">PKP</th><th class="num">PPh 21 terutang</th><th class="num">Sudah dipotong</th><th>Status</th><th class="actions">Dokumen</th></tr></thead><tbody>
    <?php foreach ($list as $e): $b = bpa1($e['pegawai_id'], $year); if (!$b) continue; $l = $b['lines']; ?>
      <tr><td><b><?= h($e['pegawai_nama']) ?></b> <span class="muted"><?= h($e['pegawai_pin']) ?></span></td><td><?= sprintf('%02d', $b['from']) ?> – <?= sprintf('%02d', $b['to']) ?></td>
        <td class="num"><?= rupiah($l[8]) ?></td><td class="num"><?= rupiah($l[16]) ?></td><td class="num"><?= rupiah($l[19]) ?></td><td class="num"><?= rupiah($l[20]) ?></td>
        <td><?php if (!$b['final']): ?><span class="pill grey">Sementara</span><?php elseif ($b['diff']): ?><span class="pill warn" title="Terutang − dipotong">Selisih <?= rupiah($b['diff']) ?></span><?php else: ?><span class="pill ok">Final</span><?php endif; ?></td>
        <td class="actions"><a class="btn small" target="_blank" href="bupot.php?<?= h(http_build_query(['year' => $year, 'id' => $e['pegawai_id']])) ?>">Lihat / cetak</a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php if (!$list): ?><div class="empty"><strong>Belum ada gaji tersimpan di <?= $year ?></strong>Bukti potong dibuat dari slip gaji dan THR yang disimpan.</div><?php endif; ?></div>
  <p class="muted">Bukti potong tahunan pegawai tetap (BPA1, PER-2/PJ/2024) dibuat dari slip gaji dan THR yang disimpan. "Final" setelah gaji Desember disimpan; sebelum itu angkanya sementara. Pelaporan resmi tetap lewat e-Bupot di Coretax.</p>
<?php
// ======================================================================= Komponen gaji
elseif ($p === 'components'):
    $defs = pay_components(false);
    $active = array_filter($defs, function ($d) { return $d['active']; });
    $cur = components_as_of(date('Y-m-d'));
    hris_header('Komponen gaji', 'payroll', 'payroll.php?p=components', 'Penggajian'); ?>
  <form method="post" class="section" style="max-width:none"><?= $csrf ?><input type="hidden" name="action" value="comp_define">
    <h2>Jenis komponen</h2>
    <small class="muted">Selain gaji pokok, tunjangan tetap dan uang harian di Data gaji karyawan. "Tetap" = termasuk upah untuk BPJS, lembur, potongan tidak hadir, THR dan pesangon. Pendapatan masuk penghasilan bruto PPh 21; potongan dipotong setelah pajak.</small>
    <table><thead><tr><th>Nama</th><th>Jenis</th><th>Dihitung</th><th>Tetap</th><th>Aktif</th></tr></thead><tbody>
      <?php foreach ($defs as $cid => $d): ?><tr><td><input type="text" name="c[<?= (int)$cid ?>][name]" value="<?= h($d['name']) ?>" maxlength="60"></td>
        <td><select name="c[<?= (int)$cid ?>][kind]"><?php foreach (component_kinds() as $k => $l): ?><option value="<?= $k ?>"<?= $d['kind'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></td>
        <td><select name="c[<?= (int)$cid ?>][per]"><?php foreach (component_pers() as $k => $l): ?><option value="<?= $k ?>"<?= $d['per'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></td>
        <td style="text-align:center"><input type="checkbox" name="c[<?= (int)$cid ?>][fixed]" value="1"<?= $d['fixed'] ? ' checked' : '' ?>></td>
        <td style="text-align:center"><input type="checkbox" name="c[<?= (int)$cid ?>][active]" value="1"<?= $d['active'] ? ' checked' : '' ?>></td></tr><?php endforeach; ?>
      <tr><td><input type="text" name="new_name" placeholder="mis. Tunjangan jabatan" maxlength="60"></td>
        <td><select name="new_kind"><?php foreach (component_kinds() as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></td>
        <td><select name="new_per"><?php foreach (component_pers() as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></td>
        <td style="text-align:center"><input type="checkbox" name="new_fixed" value="1"></td><td></td></tr>
    </tbody></table>
    <div class="formfoot"><button class="btn">Simpan jenis komponen</button></div></form>
  <?php if ($active): ?>
  <form method="post" style="margin-top:16px"><?= $csrf ?><input type="hidden" name="action" value="comp_amounts">
    <div class="toolbar" style="margin-bottom:10px"><label>Perubahan berlaku mulai <input type="date" name="effective" value="<?= date('Y-m-d') ?>"></label>
      <span class="muted">Nilai yang tampil = yang berlaku hari ini. Hanya nilai yang diubah yang dicatat; periode sebelum tanggal ini memakai nilai lama. 0 = berhenti.</span></div>
    <div class="tablecard"><div class="tablescroll"><table>
      <thead><tr><th>Nama karyawan</th><?php foreach ($active as $d): ?><th><?= h($d['name']) ?><br><small class="muted"><?= $d['kind'] === 'deduction' ? 'potongan' : 'pendapatan' ?> <?= component_pers()[$d['per']] ?><?= $d['fixed'] ? ', tetap' : '' ?></small></th><?php endforeach; ?></tr></thead><tbody>
      <?php foreach (employees(true) as $e): $id = (int)$e['pegawai_id']; ?>
        <tr><td><b><?= h($e['pegawai_nama']) ?></b> <a class="muted" style="font-size:12px" href="payroll.php?p=history&id=<?= $id ?>">riwayat</a></td>
          <?php foreach ($active as $cid => $d): $v = isset($cur[$id][$cid]) ? $cur[$id][$cid] : 0; ?>
            <td><input type="text" inputmode="numeric" name="a[<?= $id ?>][<?= (int)$cid ?>]" value="<?= $v ? h(number_format($v, 0, ',', '.')) : '' ?>" placeholder="0" style="width:120px;text-align:right"></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody></table></div></div>
    <div class="formfoot"><button class="btn primary">Simpan nilai komponen</button></div></form>
  <?php else: ?><div class="empty"><strong>Belum ada komponen</strong>Tambahkan jenis komponen di atas, misalnya Tunjangan jabatan (pendapatan, per bulan, tetap) atau Potongan koperasi.</div><?php endif; ?>
<?php
// ======================================================================= Riwayat gaji satu karyawan
elseif ($p === 'history'):
    $emp = employee(get('id'));
    if (!$emp) redirect('payroll.php?p=setup');
    $hist = q('SELECT * FROM web_pay_history WHERE pegawai_id = ? ORDER BY effective_from DESC', [$emp['pegawai_id']])->fetchAll();
    $ch = q('SELECT c.*, k.name, k.kind FROM web_pay_emp_comp c JOIN web_pay_component k ON k.id = c.component_id WHERE c.pegawai_id = ? ORDER BY c.effective_from DESC, k.sort, k.id', [$emp['pegawai_id']])->fetchAll();
    hris_header('Riwayat gaji · ' . $emp['pegawai_nama'], 'payroll', 'payroll.php?p=setup', '<a href="payroll.php?p=setup">Data gaji karyawan</a>'); ?>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Berlaku mulai</th><th class="num">Gaji pokok</th><th class="num">Tunjangan tetap</th><th class="num">Uang harian</th><th>Catatan</th><th>Dicatat</th></tr></thead><tbody>
    <?php foreach ($hist as $r): ?><tr><td><?= $r['effective_from'] === '2000-01-01' ? 'sebelumnya' : h(date('d-m-Y', strtotime($r['effective_from']))) ?></td><td class="num"><?= rupiah($r['gaji_pokok']) ?></td>
      <td class="num"><?= rupiah($r['tunjangan']) ?></td><td class="num"><?= rupiah($r['uang_harian']) ?></td><td><?= h($r['note']) ?></td><td class="muted"><?= h($r['created_by']) ?> · <?= h(date('d-m-Y', strtotime($r['created_at']))) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php if (!$hist): ?><div class="empty"><strong>Belum ada perubahan gaji tercatat</strong>Riwayat dimulai saat gaji pertama kali diubah di Data gaji karyawan.</div><?php endif; ?></div>
  <h2 style="font-size:15px;margin:18px 0 8px">Komponen gaji</h2>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Berlaku mulai</th><th>Komponen</th><th class="num">Nilai</th><th>Dicatat oleh</th></tr></thead><tbody>
    <?php foreach ($ch as $r): ?><tr><td><?= h(date('d-m-Y', strtotime($r['effective_from']))) ?></td><td><?= h($r['name']) ?> <span class="muted"><?= $r['kind'] === 'deduction' ? '(potongan)' : '' ?></span></td>
      <td class="num"><?= $r['amount'] ? rupiah($r['amount']) : '<span class="muted">berhenti</span>' ?></td><td class="muted"><?= h($r['created_by']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php if (!$ch): ?><div class="empty"><strong>Tidak ada komponen</strong></div><?php endif; ?></div>
<?php
// ======================================================================= Pesangon & penyelesaian
elseif ($p === 'settle'):
    $sid = (int)get('id');
    if ($sid):
        $st = q('SELECT s.*, p.pegawai_nama, p.pegawai_pin FROM web_settlement s JOIN pegawai p ON p.pegawai_id = s.pegawai_id WHERE s.id = ?', [$sid])->fetch();
        if (!$st) redirect('payroll.php?p=settle');
        $dt = json_decode($st['detail'], true); $c = $dt['calc']; $in = $dt['in'];
        if (get('print')):
            $company = isset($CONFIG['company']) ? $CONFIG['company'] : 'Perusahaan'; ?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Penyelesaian <?= h($st['pegawai_nama']) ?></title>
<style>body{font:13px/1.5 system-ui,sans-serif;color:#232933;background:#f6f7f9;margin:0;padding:24px 12px}.doc{max-width:720px;margin:0 auto;background:#fff;border:1px solid #e2e5ea;border-radius:8px;padding:28px 32px}
h1{font-size:17px;margin:0}table{width:100%;border-collapse:collapse;margin-top:10px}td{padding:5px 0;border-bottom:1px solid #eef1f6}td.n{text-align:right;white-space:nowrap}.tot td{font-weight:700}
.muted{color:#626b79}.sign{display:flex;justify-content:space-between;margin-top:36px;text-align:center}.btn{padding:7px 14px;border:1px solid #cfd4dc;border-radius:6px;background:#fff;cursor:pointer}
@media print{body{background:#fff;padding:0}.doc{border:none}.noprint{display:none}}</style></head><body>
<div class="noprint" style="text-align:center;margin-bottom:12px"><button class="btn" onclick="window.print()">Cetak / simpan PDF</button></div>
<div class="doc"><h1><?= h($company) ?></h1><div class="muted">Perhitungan hak karyawan pada akhir hubungan kerja<?= $st['status'] === 'draft' ? ' (DRAF)' : '' ?></div>
  <table><tr><td>Nama</td><td><b><?= h($st['pegawai_nama']) ?></b> (PIN <?= h($st['pegawai_pin']) ?>)</td></tr><tr><td>Alasan</td><td><?= h($c['reason_label']) ?> · PP 35/2021 <?= h($c['article']) ?></td></tr>
    <tr><td>Masa kerja</td><td><?= h(date('d-m-Y', strtotime($in['start']))) ?> – <?= h(date('d-m-Y', strtotime($in['end']))) ?> (<?= h($c['service']) ?>)</td></tr><tr><td>Upah sebulan</td><td><?= rupiah($c['upah']) ?></td></tr></table>
  <table><?php foreach ($c['lines'] as $k => $v): ?><tr><td><?= h($k) ?></td><td class="n"><?= rupiah($v) ?></td></tr><?php endforeach; ?>
    <tr class="tot"><td>Jumlah</td><td class="n"><?= rupiah($c['gross']) ?></td></tr>
    <tr><td>PPh 21 final atas pesangon (PP 68/2009)</td><td class="n">− <?= rupiah($c['pph_final']) ?></td></tr>
    <tr class="tot"><td>Diterima</td><td class="n"><?= rupiah($c['net']) ?></td></tr></table>
  <?php if ($c['regular']): ?><p class="muted">Uang penggantian hak / kompensasi <?= rupiah($c['regular']) ?> adalah penghasilan biasa: PPh 21-nya dihitung bersama gaji terakhir.</p><?php endif; ?>
  <?php if (!empty($dt['note'])): ?><p><?= nl2br(h($dt['note'])) ?></p><?php endif; ?>
  <p class="muted">Gaji terakhir sampai <?= h(date('d-m-Y', strtotime($in['end']))) ?> dibayar terpisah dengan slip gaji (prorata).</p>
  <div class="sign"><div>Perusahaan<br><br><br><br>(............................)</div><div>Karyawan<br><br><br><br><b><?= h($st['pegawai_nama']) ?></b></div></div></div></body></html>
<?php       exit;
        endif;
        hris_header('Penyelesaian · ' . $st['pegawai_nama'], 'payroll', 'payroll.php?p=settle', '<a href="payroll.php?p=settle">Pesangon & penyelesaian</a>',
            '<a class="btn" target="_blank" href="payroll.php?p=settle&id=' . $sid . '&print=1">Cetak</a>'); ?>
  <p class="muted" style="margin:0 0 12px"><?= h($c['reason_label']) ?> (PP 35/2021 <?= h($c['article']) ?>) · masa kerja <?= h($c['service']) ?> · upah <?= rupiah($c['upah']) ?> ·
    <span class="pill <?= $st['status'] === 'final' ? 'ok' : 'grey' ?>"><?= $st['status'] === 'final' ? 'Final' : 'Draf' ?></span></p>
  <div class="tablecard"><table>
    <?php foreach ($c['lines'] as $k => $v): ?><tr><td><?= h($k) ?></td><td class="num"><?= rupiah($v) ?></td></tr><?php endforeach; ?>
    <tr><th>Jumlah</th><th class="num"><?= rupiah($c['gross']) ?></th></tr>
    <tr><td>PPh 21 final atas pesangon, uang penghargaan dan uang pisah <?= rupiah($c['severance']) ?></td><td class="num">− <?= rupiah($c['pph_final']) ?></td></tr>
    <tr><th>Diterima</th><th class="num"><?= rupiah($c['net']) ?></th></tr></table></div>
  <?php if ($c['regular']): ?><p class="muted">Uang penggantian hak / kompensasi <?= rupiah($c['regular']) ?> termasuk penghasilan biasa; masukkan sebagai bonus lain di gaji terakhir agar PPh 21-nya dihitung.</p><?php endif; ?>
  <?php if ($st['status'] === 'draft'): ?>
    <form method="post" class="toolbar" style="margin-top:12px"><?= $csrf ?><input type="hidden" name="id" value="<?= $sid ?>">
      <button class="btn primary" name="action" value="settle_final" onclick="return confirm('Finalkan? Karyawan ditandai Berhenti per <?= h(date('d-m-Y', strtotime($st['end_date']))) ?> dan checklist offboarding dimulai.')">Finalkan & tandai berhenti</button>
      <button class="btn" name="action" value="settle_delete" onclick="return confirm('Hapus draf ini?')">Hapus draf</button>
      <span class="muted">Untuk mengubah angka, hapus draf lalu hitung lagi.</span></form>
  <?php endif; ?>
<?php
    else:
        $list = q('SELECT s.*, p.pegawai_nama FROM web_settlement s JOIN pegawai p ON p.pegawai_id = s.pegawai_id ORDER BY s.created_at DESC')->fetchAll();
        $people = q('SELECT pegawai_id, pegawai_nama, pegawai_pin, tgl_mulai_kerja FROM pegawai WHERE pegawai_status = 1 OR tgl_resign >= ? ORDER BY pegawai_nama', [date('Y-m-d', strtotime('-90 days'))])->fetchAll();
        $pays = pay_data_as_of(date('Y-m-d')); $comps = components_as_of(date('Y-m-d')); $defs = pay_components(false); $status = current_employment(); $dm = payroll_settings()['daily_month_days'];
        hris_header('Pesangon & penyelesaian', 'payroll', 'payroll.php?p=settle', 'Penggajian'); ?>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>Alasan</th><th>Berakhir</th><th class="num">Diterima</th><th>Status</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($list as $r): $d = json_decode($r['detail'], true); ?><tr><td><b><?= h($r['pegawai_nama']) ?></b></td><td><?= h($d['calc']['reason_label']) ?></td><td><?= h(date('d-m-Y', strtotime($r['end_date']))) ?></td>
      <td class="num"><?= rupiah($r['total']) ?></td><td><span class="pill <?= $r['status'] === 'final' ? 'ok' : 'grey' ?>"><?= $r['status'] === 'final' ? 'Final' : 'Draf' ?></span></td>
      <td class="actions"><a class="btn small" href="payroll.php?p=settle&id=<?= (int)$r['id'] ?>">Buka</a></td></tr><?php endforeach; ?>
    </tbody></table></div><?php if (!$list): ?><div class="empty"><strong>Belum ada perhitungan</strong></div><?php endif; ?></div>
  <form method="post" class="section" style="margin-top:16px"><?= $csrf ?><input type="hidden" name="action" value="settle_create">
    <h2>Hitung pesangon / hak karyawan</h2>
    <div class="row2">
      <label class="field"><span>Karyawan</span><select name="pegawai_id" required onchange="var o=this.selectedOptions[0];this.form.start.value=o.dataset.start||'';this.form.upah.value=o.dataset.upah||'';this.form.leave_days.value=o.dataset.leave||0">
        <option value="">Pilih…</option>
        <?php foreach ($people as $e): $id = (int)$e['pegawai_id']; $pd = isset($pays[$id]) ? $pays[$id] : null;
            $u = !$pd ? 0 : (isset($status[$id]) && $status[$id]['status'] === 'harian' ? $pd['upah_harian'] * $dm : $pd['gaji_pokok'] + $pd['tunjangan']);
            foreach (isset($comps[$id]) ? $comps[$id] : [] as $cid => $amt) if (isset($defs[$cid]) && $defs[$cid]['kind'] === 'earning' && $defs[$cid]['fixed'] && $defs[$cid]['per'] === 'month') $u += $amt;
            list($quota, $taken) = leave_balance($id); ?>
          <option value="<?= $id ?>" data-start="<?= h(substr((string)$e['tgl_mulai_kerja'], 0, 10)) ?>" data-upah="<?= $u ? h(number_format($u, 0, ',', '.')) : '' ?>" data-leave="<?= $quota === null ? 0 : max(0, $quota - $taken) ?>"><?= h($e['pegawai_nama']) ?> (<?= h($e['pegawai_pin']) ?>)</option>
        <?php endforeach; ?></select></label>
      <label class="field"><span>Alasan berakhir</span><select name="reason" required><?php foreach (settlement_reasons() as $k => $r): ?><option value="<?= $k ?>"><?= h($r[0]) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Mulai bekerja</span><input type="date" name="start" required></label>
      <label class="field"><span>Hari terakhir bekerja</span><input type="date" name="end_date" required value="<?= date('Y-m-d') ?>"></label>
      <label class="field"><span>Upah sebulan (gaji pokok + tunjangan tetap)</span><input type="text" name="upah" inputmode="numeric" required></label>
      <label class="field"><span>Sisa cuti tahunan (hari)</span><input type="text" name="leave_days" value="0"><small>Diisi dari saldo cuti tahun ini; ubah bila perlu.</small></label>
      <label class="field"><span>Hari kerja sebulan (pembagi upah harian)</span><input type="number" name="leave_divisor" value="21" min="1"><small>21 untuk 5 hari kerja seminggu, 25 untuk 6 hari.</small></label>
      <label class="field"><span>Lama kontrak (bulan, untuk PKWT)</span><input type="number" name="pkwt_months" value="0" min="0" max="60"><small>Kompensasi = bulan / 12 × upah (PP 35/2021 Pasal 16).</small></label>
      <label class="field"><span>Uang pisah (Rp)</span><input type="text" name="uang_pisah" inputmode="numeric" value="0"><small>Untuk mengundurkan diri / pelanggaran mendesak / mangkir, sesuai PK, PP atau PKB.</small></label>
      <label class="field"><span>Hak lain (Rp)</span><input type="text" name="other" inputmode="numeric" value="0"><small>mis. ongkos pulang ke tempat diterima bekerja.</small></label></div>
    <label class="field"><span>Catatan</span><textarea name="note" rows="2"></textarea></label>
    <small class="muted">Uang pesangon dan uang penghargaan masa kerja mengikuti PP 35/2021 Pasal 40 dikali pengali sesuai alasan; PPh 21 final pesangon PP 68/2009. Gaji terakhir dihitung terpisah (prorata) di Proses gaji.</small>
    <div class="formfoot"><button class="btn primary">Hitung</button></div></form>
<?php
    endif;
// ======================================================================= Data gaji karyawan
elseif ($p === 'setup'):
    $pays = pay_data();
    $status = current_employment();
    $umr = payroll_settings()['umr'];
    hris_header('Data gaji karyawan', 'payroll', 'payroll.php?p=setup', 'Penggajian'); ?>
  <form method="post"><?= $csrf ?><input type="hidden" name="action" value="setup">
  <div class="toolbar" style="margin-bottom:10px"><label>Perubahan gaji berlaku mulai <input type="date" name="effective" value="<?= date('Y-m-d') ?>"></label>
    <label>Catatan <input type="text" name="note" placeholder="mis. Kenaikan UMK 2027" style="width:220px"></label>
    <span class="muted">Periode gaji sebelum tanggal ini tetap memakai gaji lama. Tunjangan lain: <a href="payroll.php?p=components">Komponen gaji</a>.</span></div>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>Gaji pokok / bulan</th><th>Tunjangan tetap / bulan</th><th>Upah harian<br><small class="muted">harian lepas</small></th><th>Uang makan + transport / hari hadir</th><th>PTKP</th><th>NPWP / NIK</th><th>BPJS Kes</th><th>BPJS TK</th><th>JP</th></tr></thead><tbody>
    <?php foreach (employees(true) as $e): $id = (int)$e['pegawai_id']; $v = isset($pays[$id]) ? $pays[$id] : ['gaji_pokok' => '', 'tunjangan' => '', 'uang_harian' => '', 'upah_harian' => '', 'ptkp' => 'TK/0', 'npwp' => '', 'bpjs_kes' => 1, 'bpjs_tk' => 1, 'bpjs_jp' => 1];
        $harian = isset($status[$id]) && $status[$id]['status'] === 'harian'; ?>
      <tr><td><b><?= h($e['pegawai_nama']) ?></b> <span class="muted"><?= h($e['pegawai_pin']) ?></span><br><a class="muted" style="font-size:12px" href="payroll.php?p=history&id=<?= $id ?>">riwayat</a><?= !$harian && $umr > 0 && (float)$v['gaji_pokok'] > 0 && (float)$v['gaji_pokok'] + (float)$v['tunjangan'] < $umr ? ' <span class="pill bad" title="Gaji pokok + tunjangan tetap di bawah UMR/UMK ' . h(rupiah($umr)) . '">di bawah UMR</span>' : ''?><?= $harian ? ' <span class="pill grey">harian lepas</span>' : '' ?></td>
        <?php foreach (['gaji_pokok', 'tunjangan', 'upah_harian', 'uang_harian'] as $k): $off = $harian ? in_array($k, ['gaji_pokok', 'tunjangan'], true) : $k === 'upah_harian'; ?>
          <td><input type="text" inputmode="numeric" name="e[<?= $id ?>][<?= $k ?>]" value="<?= $v[$k] !== '' && $v[$k] !== null ? h(number_format((float)$v[$k], 0, ',', '.')) : '' ?>" style="width:120px;text-align:right<?= $off ? ';opacity:.45' : '' ?>" placeholder="0"<?= $off ? ' title="Tidak dipakai untuk status kepegawaian ini"' : '' ?>></td><?php endforeach; ?>
        <td><select name="e[<?= $id ?>][ptkp]"><?php foreach (ptkp_options() as $o): ?><option <?= $v['ptkp'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></td>
        <td><input type="text" name="e[<?= $id ?>][npwp]" value="<?= h($v['npwp']) ?>" style="width:170px"></td>
        <?php foreach (['bpjs_kes', 'bpjs_tk', 'bpjs_jp'] as $k): ?><td style="text-align:center"><input type="checkbox" name="e[<?= $id ?>][<?= $k ?>]" value="1" <?= $v[$k] ? 'checked' : '' ?>></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
  <div class="formfoot"><span class="muted" style="margin-right:auto">Harian lepas (status kepegawaian) dibayar upah harian × hari hadir, PPh 21 TER harian; yang lain gaji bulanan. Tanpa gaji / upah harian tidak ikut proses gaji. PTKP: TK = tidak kawin, K = kawin, angka = tanggungan.</span><button class="btn primary">Simpan data gaji</button></div>
  </form>
<?php
// ======================================================================= Pengaturan gaji
else:
    $s = payroll_settings();
    $fields = [
        'BPJS Kesehatan' => ['kes_co' => 'Perusahaan (%)', 'kes_emp' => 'Karyawan (%)', 'kes_cap' => 'Batas upah (Rp)'],
        'BPJS Ketenagakerjaan' => ['jht_co' => 'JHT perusahaan (%)', 'jht_emp' => 'JHT karyawan (%)', 'jp_co' => 'JP perusahaan (%)', 'jp_emp' => 'JP karyawan (%)',
                                   'jp_cap' => 'Batas upah JP (Rp)', 'jkk' => 'JKK (%) — sesuai tingkat risiko', 'jkm' => 'JKM (%)'],
        'Lembur & potongan' => ['ot_divisor' => 'Pembagi upah per jam (173)', 'late_per_minute' => 'Potongan terlambat per menit (Rp, 0 = tidak dipotong)',
                                'absent_deduct' => 'Potong gaji hari tidak hadir tanpa izin (1 = ya, 0 = tidak)',
                                'attend_bonus_office' => 'Insentif kehadiran kantor pusat (Rp, bulan tanpa hari tidak hadir; 0 = tidak ada)',
                                'attend_bonus_gudang' => 'Insentif kehadiran gudang (Rp; nama departemen/kantor memuat "gudang")',
                                'dl_weekday' => 'Uang DL per hari dinas luar (Rp; ditandai di Input Cepat)',
                                'dl_saturday' => 'Uang DL hari Sabtu (Rp)',
                                'trip_driver' => 'Gudang: insentif per hari menyetir sebagai driver (Rp)',
                                'trip_alone' => 'Gudang: insentif per trip kirim sendiri, hanya dengan 1 kernet (Rp)',
                                'npwp_surcharge' => 'Tambahan PPh 21 untuk karyawan tanpa NPWP (%; 0 = tidak ada)',
                                'ot_requires_approval' => 'Lembur harus diajukan (1 = hanya bayar lembur yang disetujui, 0 = bayar dari scan)',
                                'umr' => 'UMR/UMK daerah (Rp / bulan, 0 = tidak dicek): gaji pokok + tunjangan tetap di bawahnya diberi tanda di Data gaji karyawan',
                                'daily_month_days' => 'Harian lepas: upah sebulan = upah harian × (25 untuk 6 hari kerja, 21 untuk 5 hari)'],
    ];
    settings_header('Pengaturan gaji', 'settings.php', true); ?>
  <form method="post" class="section"><?= $csrf ?><input type="hidden" name="action" value="settings">
    <?php foreach ($fields as $title => $fs): ?><h2><?= h($title) ?></h2><div class="row2">
      <?php foreach ($fs as $k => $label): ?><label class="field"><span><?= h($label) ?></span><input type="text" name="<?= $k ?>" value="<?= h(rtrim(rtrim(number_format($s[$k], 2, '.', ''), '0'), '.')) ?>"></label><?php endforeach; ?></div>
    <?php endforeach; ?>
    <h2>Akun jurnal (ekspor Jurnal per periode)</h2><div class="row2">
      <?php foreach (journal_accounts() as $k => $a): ?><label class="field"><span><?= h($a[0]) ?></span><input type="text" name="<?= $k ?>" value="<?= h($a[1]) ?>"></label><?php endforeach; ?></div>
    <p class="muted">PPh 21 memakai tarif efektif rata-rata (TER, PP 58/2023) per bulan dan tarif Pasal 17 di periode Desember. Batas upah JP berubah tiap Maret; periksa angkanya.</p>
    <div class="formfoot"><button class="btn primary">Simpan</button></div>
  </form>
<?php echo '</div></div>';   // closes the settings frame opened by settings_header
endif;
hris_footer();
