<?php
// HR → Produktivitas → Asisten HR: chat with an AI assistant that reads the HR data (not salary or personal IDs) and drafts letters.
// Admins only. The conversation lives in the session; each answer calls the Anthropic API (inc/assistant.php).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/employment.php';
require __DIR__ . '/inc/claims.php';
require __DIR__ . '/inc/assistant.php';
$me = require_login('admin');
ensure_web_tables();
ensure_employment_tables();
$company = isset($CONFIG['company']) ? $CONFIG['company'] : 'Perusahaan';

if (get('new') === '1') { unset($_SESSION['assistant']); redirect('assistant.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {   // one question, answered as JSON for the page's script
    check_csrf();
    header('Content-Type: application/json; charset=utf-8');
    $question = trim(post('message'));
    if ($question === '' || mb_strlen($question) > 2000) { echo json_encode(['error' => 'Tulis pertanyaan (maksimal 2000 huruf).']); exit; }
    $hist = isset($_SESSION['assistant']) ? $_SESSION['assistant'] : [];
    session_write_close();   // do not hold the session lock while waiting for the API
    list($reply, $err) = assistant_ask($hist, $question, $company);
    if ($err === null) {
        session_start();
        $hist[] = ['role' => 'user', 'content' => $question];
        $hist[] = ['role' => 'assistant', 'content' => $reply];
        $_SESSION['assistant'] = array_slice($hist, -20);
        audit('assistant_ask', mb_substr($question, 0, 150));
    }
    echo json_encode($err === null ? ['reply' => $reply] : ['error' => $err], JSON_UNESCAPED_UNICODE);
    exit;
}

$hist = isset($_SESSION['assistant']) ? $_SESSION['assistant'] : [];
$ready = !empty($CONFIG['anthropic_api_key']) && is_file(__DIR__ . '/vendor/autoload.php');
hris_header('Asisten HR', 'performance', 'assistant.php', 'Produktivitas', '<a class="btn" href="assistant.php?new=1">Percakapan baru</a>'); ?>
  <?php if (!$ready): ?><div class="notice"><div><b>Asisten belum aktif</b>Isi <code>'anthropic_api_key'</code> di <code>config.php</code> dan jalankan <code>composer install</code> di folder aplikasi (SDK Anthropic).</div></div><?php endif; ?>
  <div class="card">
    <div id="chat" style="display:grid;gap:10px;max-height:60vh;overflow:auto;margin-bottom:12px">
      <?php foreach ($hist as $m): ?><div class="msg" style="<?= $m['role'] === 'user' ? 'justify-self:end;background:var(--primary-soft)' : 'background:var(--canvas)' ?>;padding:10px 14px;border-radius:10px;max-width:85%;white-space:pre-wrap"><?= h($m['content']) ?></div><?php endforeach; ?>
      <?php if (!$hist): ?><p class="muted" style="margin:0">Contoh: "Siapa yang terlambat paling sering bulan ini?", "Kontrak siapa yang berakhir 60 hari ke depan?", "Buatkan draf SP-1 untuk Ruben karena sering terlambat."</p><?php endif; ?>
    </div>
    <form id="f" style="display:flex;gap:8px;align-items:flex-end"><?= csrf_field() ?>
      <textarea name="message" rows="2" maxlength="2000" required placeholder="Tanya sesuatu tentang HR…" style="flex:1"<?= $ready ? '' : ' disabled' ?>></textarea>
      <button class="btn primary"<?= $ready ? '' : ' disabled' ?>>Kirim</button></form>
    <p class="muted" style="margin-bottom:0">Asisten hanya membaca data HR dan tidak mengubah apa pun. Gaji, pajak, BPJS, NIK, NPWP, rekening, alamat, telepon dan email tidak diberikan kepadanya. Pertanyaan dan hasil data dikirim ke Anthropic API. Draf surat perlu ditinjau HR.</p>
  </div>
<script>
(function () {
  var f = document.getElementById('f'), chat = document.getElementById('chat');
  function add(text, mine) {
    var d = document.createElement('div');
    d.className = 'msg'; d.style.cssText = 'padding:10px 14px;border-radius:10px;max-width:85%;white-space:pre-wrap;' + (mine ? 'justify-self:end;background:var(--primary-soft)' : 'background:var(--canvas)');
    d.textContent = text; chat.appendChild(d); chat.scrollTop = chat.scrollHeight; return d;
  }
  chat.scrollTop = chat.scrollHeight;
  f.addEventListener('submit', function (e) {
    e.preventDefault();
    var t = f.message.value.trim(); if (!t) return;
    var hint = chat.querySelector('p.muted'); if (hint) hint.remove();
    add(t, true); var wait = add('…', false); f.message.value = ''; f.querySelector('button').disabled = true;
    fetch('assistant.php', { method: 'POST', body: new FormData(f) }).then(function (r) { return r.json(); })
      .then(function (j) { wait.textContent = j.reply || ('⚠ ' + (j.error || 'Gagal.')); })
      .catch(function () { wait.textContent = '⚠ Koneksi gagal.'; })
      .then(function () { f.querySelector('button').disabled = false; chat.scrollTop = chat.scrollHeight; });
  });
  f.message.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); f.requestSubmit(); } });
})();
</script>
<?php hris_footer();
