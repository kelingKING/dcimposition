<?php
// Penyimpan jobs.txt untuk index.html. Taruh di folder yang sama dengan index.html dan jobs.txt.
// Folder dan jobs.txt harus bisa ditulis oleh akun PHP (misalnya chmod 664 atau 775 sesuai hosting).
//
// KEAMANAN: siapa pun yang bisa membuka halaman bisa mengubah jobs.txt lewat skrip ini.
// Isi $TOKEN dengan kata sandi bersama (disarankan), lalu tiap orang mengisinya sekali di
// menu Database > Kunci simpan. Lindungi juga folder dengan password server (Basic Auth) bila ada.
$TOKEN = '';

$FILE = __DIR__ . '/jobs.txt';
$MAX  = 1000000; // batas ukuran data, dalam byte

function out_json($code, $arr) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// GET: kirim isi jobs.txt beserta versinya (header X-Jobs-Version).
if ($method === 'GET') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    $txt = is_file($FILE) ? file_get_contents($FILE) : '';
    header('X-Jobs-Version: ' . sha1($txt));
    echo $txt;
    exit;
}

if ($method !== 'POST') out_json(405, ['error' => 'Metode tidak didukung.']);
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'jobs-page') out_json(400, ['error' => 'Permintaan tidak valid.']);
if ($TOKEN !== '' && !hash_equals($TOKEN, $_SERVER['HTTP_X_TOKEN'] ?? '')) {
    out_json(403, ['error' => 'Kunci simpan salah atau belum diisi.']);
}

$raw = file_get_contents('php://input', false, null, 0, $MAX + 1);
if ($raw === false || strlen($raw) > $MAX) out_json(413, ['error' => 'Data terlalu besar.']);
$in = json_decode($raw, true);
if (!is_array($in) || !isset($in['text'], $in['base']) || !is_string($in['text']) || !is_string($in['base'])) {
    out_json(400, ['error' => 'Format data salah.']);
}
$text = str_replace(["\r\n", "\r"], "\n", $in['text']);
if (!mb_check_encoding($text, 'UTF-8')) out_json(400, ['error' => 'Teks harus UTF-8.']);

// Kunci supaya dua penyimpanan tidak saling menimpa.
$lock = fopen($FILE . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) out_json(500, ['error' => 'Gagal mengunci file. Periksa izin folder.']);

$cur = is_file($FILE) ? file_get_contents($FILE) : '';
if (!hash_equals(sha1($cur), $in['base'])) {
    // File sudah diubah orang lain sejak halaman memuatnya: kirim isi terbaru agar halaman menggabungkan.
    out_json(409, ['text' => $cur, 'version' => sha1($cur)]);
}
if (is_file($FILE)) @copy($FILE, $FILE . '.bak');
$tmp = $FILE . '.tmp';
if (file_put_contents($tmp, $text) === false || !rename($tmp, $FILE)) {
    out_json(500, ['error' => 'Gagal menulis jobs.txt. Periksa izin folder.']);
}
out_json(200, ['version' => sha1($text)]);
