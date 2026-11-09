<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

ensure_web_tables();
if (q('SELECT COUNT(*) FROM web_user')->fetchColumn() == 0) redirect('setup.php');

$next = get('next', post('next', 'index.php'));
if (!preg_match('#^/?[A-Za-z0-9_./?=&%-]*$#', $next) || strpos($next, '//') !== false) $next = 'index.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $row = q('SELECT * FROM web_user WHERE username = ? AND active = 1', [post('username')])->fetch();
    if ($row && password_verify(isset($_POST['password']) ? (string)$_POST['password'] : '', $row['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$row['id'], 'username' => $row['username'], 'full_name' => $row['full_name'], 'role' => $row['role']];
        q('UPDATE web_user SET last_login = ? WHERE id = ?', [date('Y-m-d H:i:s'), $row['id']]);
        audit('login', 'Masuk');
        redirect($next);
    }
    q('INSERT INTO web_log (created_at, username, action, detail) VALUES (?, ?, ?, ?)', [date('Y-m-d H:i:s'), mb_substr(post('username'), 0, 50), 'login_failed', 'Username atau password salah']);
    $error = 'Username atau password salah.';
}

$company = isset($CONFIG['company']) ? $CONFIG['company'] : 'Perusahaan';
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk · HR</title>
<link rel="stylesheet" href="assets/hris.css?v=<?= (int)@filemtime(__DIR__ . '/assets/hris.css') ?>">
<link rel="icon" type="image/png" href="assets/logo.png">
<style>
.lg { min-height: 100%; display: grid; place-items: center; padding: 16px; }
.lg .card { width: 100%; max-width: 380px; padding: 28px; }
.lg .brand { justify-content: center; margin-bottom: 18px; }
.lg .brandtext { display: block !important; }
.lg h1 { font-size: 18px; margin: 0 0 14px; text-align: center; }
.lg form { display: grid; gap: 12px; }
.lg label { display: grid; gap: 4px; font-weight: 500; font-size: 13px; }
.lg input { width: 100%; border: 1px solid var(--line-strong); background: var(--bg); border-radius: 6px; padding: 9px 10px; }
.lg .btn { justify-content: center; width: 100%; padding: 9px; }
</style>
</head>
<body class="lg">
<div class="card">
  <div class="brand"><img class="mark" src="assets/logo.png" alt="" width="32" height="32"><span class="brandtext"><b><?= h($company) ?></b><small>HR</small></span></div>
  <h1>Masuk</h1>
  <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <label>Username <input name="username" required autofocus value="<?= h(post('username', 'admin')) ?>" autocomplete="username"></label>
    <label>Password <input type="password" name="password" autocomplete="current-password" placeholder="Kosongkan jika tanpa password"></label>
    <button class="btn primary">Masuk</button>
  </form>
</div>
</body>
</html>
