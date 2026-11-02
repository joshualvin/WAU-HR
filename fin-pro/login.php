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
        redirect($next);
    }
    $error = 'Username atau password salah.';
}

page_header('Masuk');
?>
<div class="card narrow">
  <h1>Masuk</h1>
  <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <label>Username <input name="username" required autofocus value="<?= h(post('username', 'admin')) ?>" autocomplete="username"></label>
    <label>Password <input type="password" name="password" autocomplete="current-password" placeholder="Kosongkan jika tanpa password"></label>
    <button class="btn primary">Masuk</button>
  </form>
</div>
<?php page_footer();
