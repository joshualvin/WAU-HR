<?php
// Payroll allocation: what a saved payroll period costs the company, split by department, branch or job position.
// Works on decoded payslips (web_payslip.detail); classifies lines like payroll_journal() does.

/** Cost parts of one payslip: salary (earnings minus absence / late deductions), company BPJS, reimbursement, plus PPh 21 and take-home. */
function alloc_slip($d)
{
    $salary = $reimb = $refund = $absence = 0;
    foreach ($d['earnings'] as $k => $v) {
        if (strpos($k, 'Reimbursement') === 0) $reimb += $v;
        elseif (strpos($k, 'Pengembalian') === 0) $refund += $v;   // tax paid back to the employee: not a cost of the company
        else $salary += $v;
    }
    foreach ($d['deductions'] as $k => $v) if (strpos($k, 'Potongan tidak hadir') === 0 || strpos($k, 'Potongan terlambat') === 0) $absence += $v;
    $co = array_sum($d['company']);
    return ['salary' => $salary - $absence, 'bpjs' => $co, 'reimb' => $reimb, 'total' => $salary - $absence + $co + $reimb, 'pph' => (int)$d['pph21'], 'net' => (int)$d['netto']];
}

/**
 * Group slips: $slips is a list of ['group' => label, 'detail' => decoded payslip]. Returns [label => ['n', 'salary', 'bpjs', 'reimb',
 * 'total', 'pph', 'net']] ordered by total cost, biggest first.
 */
function alloc_group($slips)
{
    $out = [];
    foreach ($slips as $s) {
        $g = $s['group'] !== '' ? $s['group'] : 'Belum diisi';
        if (!isset($out[$g])) $out[$g] = ['n' => 0, 'salary' => 0, 'bpjs' => 0, 'reimb' => 0, 'total' => 0, 'pph' => 0, 'net' => 0];
        $a = alloc_slip($s['detail']);
        $out[$g]['n']++;
        foreach ($a as $k => $v) $out[$g][$k] += $v;
    }
    $labels = array_keys($out);
    usort($labels, function ($a, $b) use ($out) { return $out[$b]['total'] <=> $out[$a]['total'] ?: strcmp($a, $b); });   // biggest first, equal totals by name
    $sorted = [];
    foreach ($labels as $l) $sorted[$l] = $out[$l];
    return $sorted;
}
