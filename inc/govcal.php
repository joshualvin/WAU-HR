<?php
// Indonesia's national holidays (libur nasional, status 1) and cuti bersama (status 2) from the yearly SKB 3 Menteri,
// as published on setneg.go.id. Add a year here when its SKB comes out (usually September of the year before).
// For private companies cuti bersama is not compulsory; it is usually taken from annual leave.

function gov_holidays($year)
{
    $c = [
        2026 => [
            ['2026-01-01', 'Tahun Baru 2026 Masehi', 1], ['2026-01-16', 'Isra Mikraj Nabi Muhammad SAW', 1],
            ['2026-02-16', 'Cuti bersama Tahun Baru Imlek 2577 Kongzili', 2], ['2026-02-17', 'Tahun Baru Imlek 2577 Kongzili', 1],
            ['2026-03-18', 'Cuti bersama Hari Suci Nyepi', 2], ['2026-03-19', 'Hari Suci Nyepi (Tahun Baru Saka 1948)', 1],
            ['2026-03-20', 'Cuti bersama Idul Fitri 1447 H', 2], ['2026-03-21', 'Idul Fitri 1447 H', 1], ['2026-03-22', 'Idul Fitri 1447 H', 1],
            ['2026-03-23', 'Cuti bersama Idul Fitri 1447 H', 2], ['2026-03-24', 'Cuti bersama Idul Fitri 1447 H', 2],
            ['2026-04-03', 'Wafat Yesus Kristus', 1], ['2026-04-05', 'Kebangkitan Yesus Kristus (Paskah)', 1], ['2026-05-01', 'Hari Buruh Internasional', 1],
            ['2026-05-14', 'Kenaikan Yesus Kristus', 1], ['2026-05-15', 'Cuti bersama Kenaikan Yesus Kristus', 2],
            ['2026-05-27', 'Idul Adha 1447 H', 1], ['2026-05-28', 'Cuti bersama Idul Adha 1447 H', 2], ['2026-05-31', 'Hari Raya Waisak 2570 BE', 1],
            ['2026-06-01', 'Hari Lahir Pancasila', 1], ['2026-06-16', '1 Muharam Tahun Baru Islam 1448 H', 1], ['2026-08-17', 'Proklamasi Kemerdekaan', 1],
            ['2026-08-25', 'Maulid Nabi Muhammad SAW', 1], ['2026-12-24', 'Cuti bersama Kelahiran Yesus Kristus', 2], ['2026-12-25', 'Kelahiran Yesus Kristus', 1],
        ],
        2027 => [
            ['2027-01-01', 'Tahun Baru 2027 Masehi', 1], ['2027-01-05', 'Isra Mikraj Nabi Muhammad SAW', 1],
            ['2027-02-05', 'Cuti bersama Tahun Baru Imlek 2578 Kongzili', 2], ['2027-02-06', 'Tahun Baru Imlek 2578 Kongzili', 1],
            ['2027-03-08', 'Hari Suci Nyepi (Tahun Baru Saka 1949)', 1], ['2027-03-09', 'Cuti bersama Idul Fitri 1448 H', 2],
            ['2027-03-10', 'Idul Fitri 1448 H', 1], ['2027-03-11', 'Idul Fitri 1448 H', 1], ['2027-03-12', 'Cuti bersama Idul Fitri 1448 H', 2],
            ['2027-03-15', 'Cuti bersama Idul Fitri 1448 H', 2], ['2027-03-25', 'Cuti bersama Wafat Yesus Kristus', 2], ['2027-03-26', 'Wafat Yesus Kristus', 1],
            ['2027-03-28', 'Kebangkitan Yesus Kristus (Paskah)', 1], ['2027-05-01', 'Hari Buruh Internasional', 1], ['2027-05-06', 'Kenaikan Yesus Kristus', 1],
            ['2027-05-17', 'Idul Adha 1448 H', 1], ['2027-05-18', 'Cuti bersama Idul Adha 1448 H', 2], ['2027-05-19', 'Cuti bersama Hari Raya Waisak', 2],
            ['2027-05-20', 'Hari Raya Waisak 2571 BE', 1], ['2027-06-01', 'Hari Lahir Pancasila', 1], ['2027-06-06', '1 Muharam Tahun Baru Islam 1449 H', 1],
            ['2027-08-15', 'Maulid Nabi Muhammad SAW', 1], ['2027-08-17', 'Proklamasi Kemerdekaan', 1], ['2027-12-24', 'Cuti bersama Kelahiran Yesus Kristus', 2],
            ['2027-12-25', 'Kelahiran Yesus Kristus', 1], ['2027-12-26', 'Isra Mikraj Nabi Muhammad SAW', 1],
        ],
    ];
    if (isset($c[$year])) return $c[$year];
    // shortcut: years without an SKB here only get the fixed-date holidays; the moving ones (Idul Fitri, Imlek, Waisak...) need the SKB, add the year above when it is published
    $y = (int)$year;
    return $y < 2000 ? [] : [["$y-01-01", "Tahun Baru $y Masehi", 1], ["$y-05-01", 'Hari Buruh Internasional', 1], ["$y-06-01", 'Hari Lahir Pancasila', 1],
        ["$y-08-17", 'Proklamasi Kemerdekaan', 1], ["$y-12-25", 'Kelahiran Yesus Kristus', 1]];
}

function gov_official($year)
{
    return in_array((int)$year, [2026, 2027], true);
}
