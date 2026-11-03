<?php
// Activity log (web_log): grouping of the action codes into types, and the WHERE clause of the filters.

/** Type groups in the order of the filter: label => action code prefixes (first match wins). */
function activity_types()
{
    return [
        'Masuk'          => ['login'],
        'Absensi'        => ['manual_scan', 'quick_entry', 'cloud_', 'sync_', 'ftm_', 'device_', 'geofence_', 'start_date_from_scan'],
        'Cuti & jadwal'  => ['leave_', 'quota_', 'swap_', 'holiday_', 'schedule_', 'shift_set', 'workhours_', 'overtime_'],
        'Penggajian'     => ['payroll_', 'pay_', 'thr_', 'loan_', 'reimburse_', 'settlement_', 'bonus_'],
        'Karyawan'       => ['employment_', 'profile_', 'doc_', 'letter_', 'warning_', 'candidate_', 'checklist_', 'master_', 'org_', 'portal_', 'review_', 'manpower_', 'job_', 'supervisor_'],
        'Perusahaan'     => ['announcement_', 'asset_'],
        'Pengguna'       => ['user_'],
    ];
}

/** Type label of an action code, "Lainnya" when no group knows it. */
function activity_type($action)
{
    foreach (activity_types() as $label => $prefixes) foreach ($prefixes as $p) if (strpos($action, $p) === 0) return $label;
    return 'Lainnya';
}

/**
 * WHERE clause and parameters for the log filters: from / to (Y-m-d), user, type (a label of activity_types()), q (text in the
 * action or detail). Returns [sql, params].
 */
function activity_where($f)
{
    $w = ['created_at >= ?', 'created_at < ? + INTERVAL 1 DAY'];
    $p = [$f['from'], $f['to']];
    if (!empty($f['user'])) { $w[] = 'username = ?'; $p[] = $f['user']; }
    if (!empty($f['q'])) { $w[] = '(detail LIKE ? OR action LIKE ?)'; $p[] = '%' . $f['q'] . '%'; $p[] = '%' . $f['q'] . '%'; }
    if (!empty($f['type'])) {
        $types = activity_types();
        if ($f['type'] === 'Lainnya') {   // everything no group claims
            foreach ($types as $prefixes) foreach ($prefixes as $x) { $w[] = 'action NOT LIKE ?'; $p[] = $x . '%'; }
        } elseif (isset($types[$f['type']])) {
            $or = [];
            foreach ($types[$f['type']] as $x) { $or[] = 'action LIKE ?'; $p[] = $x . '%'; }
            $w[] = '(' . implode(' OR ', $or) . ')';
        }
    }
    return [implode(' AND ', $w), $p];
}
