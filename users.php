<?php
// Web app sign-ins (web_user) and the change log. Admins only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
$me = require_login('admin');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    $pass = isset($_POST['password']) ? (string)$_POST['password'] : '';
    if ($action === 'add') {
        $user = post('username');
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $user)) $error = 'Username: 3-50 huruf, angka, titik, - atau _.';
        elseif (q('SELECT COUNT(*) FROM web_user WHERE username = ?', [$user])->fetchColumn()) $error = 'Username sudah dipakai.';
        else {
            q('INSERT INTO web_user (username, full_name, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)',
                [$user, post('full_name'), password_hash($pass, PASSWORD_DEFAULT), post('role') === 'admin' ? 'admin' : 'operator', date('Y-m-d H:i:s')]);
            audit('user_add', $user);
            flash("User $user added.");
            redirect('users.php');
        }
    } elseif ($action === 'password') {
        q('UPDATE web_user SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), (int)post('id')]);
        audit('user_password', 'user id ' . (int)post('id'));
        flash($pass === '' ? 'Password dihapus.' : 'Password diubah.');
        redirect('users.php');
    } elseif ($action === 'toggle') {
        if ((int)post('id') === $me['id']) $error = 'Tidak bisa menonaktifkan akun sendiri.';
        else {
            q('UPDATE web_user SET active = 1 - active WHERE id = ?', [(int)post('id')]);
            audit('user_toggle', 'user id ' . (int)post('id'));
            redirect('users.php');
        }
    }
}

$users = q('SELECT * FROM web_user ORDER BY username')->fetchAll();
$log = q('SELECT * FROM web_log ORDER BY id DESC LIMIT 50')->fetchAll();

settings_header('User', 'users.php');
?>
<?php if (settings_ui() === 'fs'): ?><h1>User</h1><?php endif; ?>
<?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
<div class="row">
  <div class="card">
    <h2>User</h2>
    <p class="muted">Admin dapat mengubah pegawai, pengaturan dan user. Operator dapat input scan dan izin.</p>
    <div class="table-wrap"><table>
      <tr><th>Username</th><th>Nama</th><th>Hak akses</th><th>Login terakhir</th><th></th></tr>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= h($u['username']) ?> <?= $u['active'] ? '' : '<span class="tag">nonaktif</span>' ?></td>
          <td><?= h($u['full_name']) ?></td>
          <td><?= h($u['role']) ?></td>
          <td><?= h($u['last_login']) ?></td>
          <td style="white-space:nowrap">
            <form method="post" class="btn-form">
              <?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <input type="password" name="password" placeholder="Password baru (kosong = tanpa)" style="width:190px" autocomplete="new-password">
              <button class="btn small">Simpan</button>
            </form>
            <?php if ((int)$u['id'] !== $me['id']): ?>
            <form method="post" class="btn-form">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button class="btn small"><?= $u['active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table></div>
  </div>
  <div class="card">
    <h2>Tambah User</h2>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="add">
      <label>Username <input name="username" required value="<?= h(post('username')) ?>" autocomplete="off"></label>
      <label>Nama lengkap <input name="full_name" value="<?= h(post('full_name')) ?>"></label>
      <label>Password (opsional) <input type="password" name="password" autocomplete="new-password"></label>
      <label>Hak akses <select name="role"><?= options(['operator' => 'Operator', 'admin' => 'Admin'], post('role', 'operator')) ?></select></label>
      <button class="btn primary">Tambah User</button>
    </form>
  </div>
</div>
<div class="card">
  <h2>Log perubahan</h2>
  <div class="table-wrap"><table>
    <tr><th>Waktu</th><th>User</th><th>Aksi</th><th>Detail</th></tr>
    <?php foreach ($log as $l): ?>
      <tr><td><?= h($l['created_at']) ?></td><td><?= h($l['username']) ?></td><td><?= h($l['action']) ?></td><td><?= h($l['detail']) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php settings_footer();
