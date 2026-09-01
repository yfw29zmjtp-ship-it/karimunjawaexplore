<?php
/**
 * Download face-api.js model weights to local server
 * Run ONCE: https://adfsystem.online/modules/payroll/download-face-models.php
 * Hapus file ini setelah selesai!
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../includes/auth.php';
$auth = new Auth();
$auth->requireLogin();

$baseGH = 'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/';
$saveDir = __DIR__ . '/../../assets/face-weights/';

$files = [
    'tiny_face_detector_model-weights_manifest.json',
    'tiny_face_detector_model-shard1',
    'face_landmark_68_tiny_model-weights_manifest.json',
    'face_landmark_68_tiny_model-shard1',
    'face_recognition_model-weights_manifest.json',
    'face_recognition_model-shard1',
    'face_recognition_model-shard2',
];

if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);

$results = [];
foreach ($files as $f) {
    $dest = $saveDir . $f;
    if (file_exists($dest)) { $results[] = ['file'=>$f,'status'=>'skip','msg'=>'sudah ada']; continue; }
    $ctx = stream_context_create(['http'=>['timeout'=>30,'user_agent'=>'Mozilla/5.0']]);
    $data = @file_get_contents($baseGH . $f, false, $ctx);
    if ($data === false) {
        $results[] = ['file'=>$f,'status'=>'error','msg'=>'Gagal download'];
    } else {
        file_put_contents($dest, $data);
        $results[] = ['file'=>$f,'status'=>'ok','msg'=>round(strlen($data)/1024,1).'KB'];
    }
}
$allOk = !array_filter($results, fn($r) => $r['status']==='error');
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Download Face Models</title>
<style>body{font-family:sans-serif;max-width:600px;margin:40px auto;padding:20px;}
.ok{color:#059669;font-weight:700;}.error{color:#dc2626;font-weight:700;}.skip{color:#6366f1;}
table{width:100%;border-collapse:collapse;}td,th{padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;}
th{background:#f8fafc;}.alert{padding:14px;border-radius:8px;margin-top:16px;font-size:13px;}
.alert-ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;}
.alert-err{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;}</style></head>
<body>
<h2>📥 Download Face-API Model Weights</h2>
<table><tr><th>File</th><th>Status</th><th>Keterangan</th></tr>
<?php foreach($results as $r): ?>
<tr><td><?php echo $r['file']; ?></td>
<td class="<?php echo $r['status']; ?>"><?php echo strtoupper($r['status']); ?></td>
<td><?php echo $r['msg']; ?></td></tr>
<?php endforeach; ?>
</table>
<?php if($allOk): ?>
<div class="alert alert-ok">✅ Semua model berhasil didownload ke <code>assets/face-weights/</code>.<br>
Sekarang halaman absen akan loading jauh lebih cepat.<br><br>
<strong>⚠️ Hapus file download-face-models.php ini setelah selesai!</strong></div>
<?php else: ?>
<div class="alert alert-err">❌ Ada file yang gagal. Coba jalankan ulang atau pastikan server bisa akses internet (allow_url_fopen=On).</div>
<?php endif; ?>
<p><a href="attendance.php">← Kembali ke Absensi</a></p>
</body></html>
