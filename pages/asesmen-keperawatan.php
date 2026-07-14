<?php
/**
 * asesmen-keperawatan.php
 * ------------------------------------------------------------
 * Logika PHP untuk halaman "Asesmen Awal Keperawatan" (SIMRS Amino).
 * File ini HANYA berisi bagian PHP: koneksi database, query,
 * fungsi bantu, dan handler AJAX untuk aksi tombol.
 *
 * Cara pakai:
 * 1. Sambungkan file ini di paling atas file HTML (ganti ekstensi
 *    menjadi .php), lalu ganti bagian data statis (info pasien,
 *    vital sign, tag alergi) dengan echo dari variabel di bawah.
 *    Contoh penempatan ditandai komentar "== GANTI DI HTML ==".
 * 2. Sesuaikan nama tabel/kolom dengan struktur database Anda.
 * 3. Halaman ini butuh parameter kunjungan, contoh akses:
 *    asesmen-keperawatan.php?kunjungan_id=1024
 * ------------------------------------------------------------
 */

session_start();
header('Content-Type: text/html; charset=UTF-8');

// =====================================================
// 1. KONEKSI DATABASE
// =====================================================
$DB_HOST = 'localhost';
$DB_NAME = 'simrs_amino';
$DB_USER = 'root';
$DB_PASS = '';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die('Koneksi database gagal: ' . htmlspecialchars($e->getMessage()));
}

// =====================================================
// 2. VALIDASI PARAMETER KUNJUNGAN
// =====================================================
$kunjunganId = filter_input(INPUT_GET, 'kunjungan_id', FILTER_VALIDATE_INT);
if (!$kunjunganId) {
    die('Parameter kunjungan_id wajib disertakan, contoh: ?kunjungan_id=1024');
}

// =====================================================
// 3. HANDLER AKSI (dipanggil via AJAX / fetch dari JS)
//    Contoh: fetch('asesmen-keperawatan.php?action=simpan_asesmen', {...})
// =====================================================
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($action) {
    header('Content-Type: application/json; charset=UTF-8');

    switch ($action) {

        // ---- Simpan / update asesmen awal keperawatan ----
        case 'simpan_asesmen':
            $perawatId   = $_SESSION['user_id'] ?? null;
            $keluhan     = trim($_POST['keluhan_utama'] ?? '');
            $vital       = json_decode($_POST['vital'] ?? '{}', true);
            $requiredVital = ['tekanan_darah', 'suhu', 'nadi', 'napas', 'spo2', 'berat_badan', 'tinggi_badan'];

            if (!$perawatId) {
                echo json_encode(['success' => false, 'message' => 'Sesi login tidak ditemukan']);
                exit;
            }
            foreach ($requiredVital as $field) {
                if (!isset($vital[$field]) || $vital[$field] === '') {
                    echo json_encode(['success' => false, 'message' => "Field vital '$field' wajib diisi"]);
                    exit;
                }
            }

            $stmt = $pdo->prepare(
                "INSERT INTO asesmen_keperawatan
                    (kunjungan_id, perawat_id, keluhan_utama, tekanan_darah, suhu, nadi,
                     frekuensi_napas, spo2, berat_badan, tinggi_badan, waktu_input)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    keluhan_utama = VALUES(keluhan_utama),
                    tekanan_darah = VALUES(tekanan_darah),
                    suhu = VALUES(suhu),
                    nadi = VALUES(nadi),
                    frekuensi_napas = VALUES(frekuensi_napas),
                    spo2 = VALUES(spo2),
                    berat_badan = VALUES(berat_badan),
                    tinggi_badan = VALUES(tinggi_badan),
                    waktu_input = NOW()"
            );
            $stmt->execute([
                $kunjunganId,
                $perawatId,
                $keluhan,
                $vital['tekanan_darah'],
                $vital['suhu'],
                $vital['nadi'],
                $vital['napas'],
                $vital['spo2'],
                $vital['berat_badan'],
                $vital['tinggi_badan'],
            ]);

            echo json_encode(['success' => true, 'message' => 'Asesmen berhasil disimpan']);
            exit;

        // ---- Tambah alergi ke daftar pasien ----
        case 'tambah_alergi':
            $namaAlergi = trim($_POST['nama_alergi'] ?? '');
            $pasienId   = $_POST['pasien_id'] ?? null;

            if (!$namaAlergi || !$pasienId) {
                echo json_encode(['success' => false, 'message' => 'Data alergi tidak lengkap']);
                exit;
            }

            $stmt = $pdo->prepare(
                "INSERT INTO alergi_pasien (pasien_id, nama_alergi, dicatat_oleh, waktu)
                 VALUES (?, ?, ?, NOW())"
            );
            $stmt->execute([$pasienId, $namaAlergi, $_SESSION['user_id'] ?? null]);

            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'nama_alergi' => $namaAlergi]);
            exit;

        // ---- Hapus alergi (klik "×" pada tag) ----
        case 'hapus_alergi':
            $alergiId = $_POST['alergi_id'] ?? null;
            if (!$alergiId) {
                echo json_encode(['success' => false, 'message' => 'alergi_id wajib diisi']);
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM alergi_pasien WHERE id = ?");
            $stmt->execute([$alergiId]);
            echo json_encode(['success' => true]);
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali']);
            exit;
    }
}

// =====================================================
// 4. DATA USER YANG SEDANG LOGIN (topbar)
// =====================================================
$userNama = $_SESSION['user_nama'] ?? 'Dr. Amino Gondohutomo';
$userRole = $_SESSION['user_role'] ?? 'Dokter Utama';
$userFoto = $_SESSION['user_foto'] ?? 'https://images.unsplash.com/photo-1559839734-2b71ea197ec2?w=100&h=100&fit=crop&crop=faces';

// == GANTI DI HTML ==
// <div class="name"><?= htmlspecialchars($userNama) ?></div>
// <div class="role"><?= htmlspecialchars($userRole) ?></div>
// <img class="avatar" src="<?= htmlspecialchars($userFoto) ?>" alt="avatar">

// =====================================================
// 5. DATA PASIEN & KUNJUNGAN (kartu pasien)
// =====================================================
$stmt = $pdo->prepare(
    "SELECT p.id AS pasien_id, p.nama, p.no_rm, p.tanggal_lahir, p.gender,
            p.penjamin, k.poli, k.tanggal_kunjungan
     FROM kunjungan k
     JOIN pasien p ON p.id = k.pasien_id
     WHERE k.id = ?"
);
$stmt->execute([$kunjunganId]);
$pasien = $stmt->fetch();

if (!$pasien) {
    die('Data kunjungan tidak ditemukan.');
}

$umur = date_diff(date_create($pasien['tanggal_lahir']), date_create('now'))->y;

// == GANTI DI HTML ==
// <h2><?= strtoupper(htmlspecialchars($pasien['nama'])) ?></h2>
// <p>RM: <?= htmlspecialchars($pasien['no_rm']) ?></p>
//
// <div class="patient-info-value"><?= $umur ?> Thn / <?= date('d M Y', strtotime($pasien['tanggal_lahir'])) ?></div>
// <div class="patient-info-value"><?= htmlspecialchars($pasien['gender']) ?></div>
// <span class="insurance-badge"><?= htmlspecialchars($pasien['penjamin']) ?></span>

// =====================================================
// 6. DATA ASESMEN SEBELUMNYA (jika sudah pernah diisi -> mode edit)
// =====================================================
$stmt = $pdo->prepare(
    "SELECT keluhan_utama, tekanan_darah, suhu, nadi, frekuensi_napas, spo2,
            berat_badan, tinggi_badan
     FROM asesmen_keperawatan
     WHERE kunjungan_id = ?"
);
$stmt->execute([$kunjunganId]);
$asesmen = $stmt->fetch();

// Nilai default kalau belum pernah diisi (dipakai untuk value awal input)
$vitalDefault = [
    'tekanan_darah' => $asesmen['tekanan_darah'] ?? '120/80',
    'suhu'          => $asesmen['suhu'] ?? '36.5',
    'nadi'          => $asesmen['nadi'] ?? '80',
    'napas'         => $asesmen['frekuensi_napas'] ?? '20',
    'spo2'          => $asesmen['spo2'] ?? '98',
    'berat_badan'   => $asesmen['berat_badan'] ?? '',
    'tinggi_badan'  => $asesmen['tinggi_badan'] ?? '',
];
$keluhanUtama = $asesmen['keluhan_utama'] ?? '';

// == GANTI DI HTML ==
// <textarea placeholder="Tuliskan keluhan utama pasien secara detail..."><?= htmlspecialchars($keluhanUtama) ?></textarea>
//
// <input type="text" value="<?= htmlspecialchars($vitalDefault['tekanan_darah']) ?>">   <!-- Tekanan Darah -->
// <input type="text" value="<?= htmlspecialchars($vitalDefault['suhu']) ?>">             <!-- Suhu Tubuh -->
// <input type="text" value="<?= htmlspecialchars($vitalDefault['nadi']) ?>">             <!-- Denyut Nadi -->
// <input type="text" value="<?= htmlspecialchars($vitalDefault['napas']) ?>">            <!-- Frekuensi Napas -->
// <input type="text" value="<?= htmlspecialchars($vitalDefault['spo2']) ?>">             <!-- SpO2 -->
// <input type="text" value="<?= htmlspecialchars($vitalDefault['berat_badan']) ?>">      <!-- Berat Badan -->
// <input type="text" value="<?= htmlspecialchars($vitalDefault['tinggi_badan']) ?>">     <!-- Tinggi Badan -->

// =====================================================
// 7. DAFTAR ALERGI PASIEN (tag alergi)
// =====================================================
$stmt = $pdo->prepare(
    "SELECT id, nama_alergi
     FROM alergi_pasien
     WHERE pasien_id = ?
     ORDER BY waktu DESC"
);
$stmt->execute([$pasien['pasien_id']]);
$daftarAlergi = $stmt->fetchAll();

// == GANTI DI HTML ==
// <div class="allergy-tags">
// <?php foreach ($daftarAlergi as $a): ?>
//   <div class="allergy-tag" data-alergi-id="<?= $a['id'] ?>">
//     <?= htmlspecialchars($a['nama_alergi']) ?>
//     <span onclick="hapusAlergi(<?= $a['id'] ?>)">&times;</span>
//   </div>
// <?php endforeach; ?>
// </div>
//
// Contoh JS pemanggil aksi tambah/hapus alergi & simpan asesmen (boleh
// ditaruh di file HTML, cukup arahkan endpoint fetch ke file PHP ini):
//
// function tambahAlergi(nama) {
//   fetch('asesmen-keperawatan.php?action=tambah_alergi', {
//     method: 'POST',
//     headers: {'Content-Type': 'application/x-www-form-urlencoded'},
//     body: 'pasien_id=<?= $pasien['pasien_id'] ?>&nama_alergi=' + encodeURIComponent(nama)
//   }).then(r => r.json()).then(res => { /* update DOM */ });
// }
//
// function hapusAlergi(id) {
//   fetch('asesmen-keperawatan.php?action=hapus_alergi', {
//     method: 'POST',
//     headers: {'Content-Type': 'application/x-www-form-urlencoded'},
//     body: 'alergi_id=' + id
//   }).then(r => r.json()).then(res => { /* hapus tag dari DOM */ });
// }
//
// function simpanAsesmen() {
//   var vital = {
//     tekanan_darah: document.querySelectorAll('.input-unit input')[0].value,
//     suhu: document.querySelectorAll('.input-unit input')[1].value,
//     nadi: document.querySelectorAll('.input-unit input')[2].value,
//     napas: document.querySelectorAll('.input-unit input')[3].value,
//     spo2: document.querySelectorAll('.input-unit input')[4].value,
//     berat_badan: document.querySelectorAll('.input-unit input')[5].value,
//     tinggi_badan: document.querySelectorAll('.input-unit input')[6].value
//   };
//   fetch('asesmen-keperawatan.php?action=simpan_asesmen', {
//     method: 'POST',
//     headers: {'Content-Type': 'application/x-www-form-urlencoded'},
//     body: 'keluhan_utama=' + encodeURIComponent(document.querySelector('textarea').value)
//         + '&vital=' + encodeURIComponent(JSON.stringify(vital))
//   }).then(r => r.json()).then(res => { alert(res.message); });
// }