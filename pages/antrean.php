<?php
/**
 * ==========================================================================
 *  ANTREAN PASIEN - LOGIC PHP
 *  File ini HANYA berisi logic PHP (koneksi DB, query, dan aksi AJAX).
 *  Tempelkan <?php ... ?> di bagian yang relevan pada file HTML/CSS
 *  yang sudah ada (antrean-pasien.php), sesuai komentar "SISIPKAN DI SINI".
 * ==========================================================================
 */

session_start();
header('Content-Type: text/html; charset=UTF-8');

/* ==========================================================================
 * 1. KONEKSI DATABASE
 * ========================================================================== */
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
    die('Koneksi database gagal: ' . $e->getMessage());
}

/* ==========================================================================
 * 2. STRUKTUR TABEL YANG DIASUMSIKAN
 * --------------------------------------------------------------------------
 * klinik   (id, nama_klinik)
 * dokter   (id, nama_dokter, klinik_id)
 * antrean  (id, klinik_id, dokter_id, no_antrean, nama_pasien, nomor_id,
 *           is_bpjs TINYINT(1), status ENUM('menunggu','dipanggil','selesai','dilewati'),
 *           created_at, updated_at)
 * ========================================================================== */

/* ==========================================================================
 * 3. HANDLE AKSI AJAX (dipanggil dari tombol via fetch())
 *    Semua aksi mengembalikan JSON lalu keluar (exit), tidak merender HTML.
 * ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=UTF-8');

    $action    = $_POST['action'];
    $klinikId  = (int) ($_POST['klinik_id'] ?? 0);
    $dokterId  = (int) ($_POST['dokter_id'] ?? 0);
    $antreanId = (int) ($_POST['antrean_id'] ?? 0);

    try {
        switch ($action) {

            /* ---- Panggil Pasien Berikutnya ---- */
            case 'panggil_berikutnya':
                // Set antrean yang sedang "dipanggil" menjadi "selesai" dulu
                $stmt = $pdo->prepare(
                    "UPDATE antrean SET status = 'selesai', updated_at = NOW()
                     WHERE klinik_id = ? AND status = 'dipanggil'"
                );
                $stmt->execute([$klinikId]);

                // Ambil antrean paling awal yang masih menunggu
                $stmt = $pdo->prepare(
                    "SELECT id FROM antrean
                     WHERE klinik_id = ? AND status = 'menunggu'
                     ORDER BY created_at ASC LIMIT 1"
                );
                $stmt->execute([$klinikId]);
                $next = $stmt->fetch();

                if ($next) {
                    $update = $pdo->prepare(
                        "UPDATE antrean SET status = 'dipanggil', dokter_id = ?, updated_at = NOW()
                         WHERE id = ?"
                    );
                    $update->execute([$dokterId, $next['id']]);
                }

                echo json_encode(['success' => true, 'antrean_id' => $next['id'] ?? null]);
                break;

            /* ---- Panggil Ulang (nomor yang sama) ---- */
            case 'panggil_ulang':
                $stmt = $pdo->prepare(
                    "UPDATE antrean SET updated_at = NOW()
                     WHERE klinik_id = ? AND status = 'dipanggil'"
                );
                $stmt->execute([$klinikId]);
                echo json_encode(['success' => true]);
                break;

            /* ---- Lewati Pasien ---- */
            case 'lewati':
                $stmt = $pdo->prepare(
                    "UPDATE antrean SET status = 'dilewati', updated_at = NOW()
                     WHERE klinik_id = ? AND status = 'dipanggil'"
                );
                $stmt->execute([$klinikId]);
                echo json_encode(['success' => true]);
                break;

            /* ---- Tambah Antrean Baru (tombol FAB +) ---- */
            case 'tambah_antrean':
                $namaPasien = trim($_POST['nama_pasien'] ?? '');
                $nomorId    = trim($_POST['nomor_id'] ?? '');
                $isBpjs     = isset($_POST['is_bpjs']) ? 1 : 0;

                if ($namaPasien === '' || $nomorId === '') {
                    echo json_encode(['success' => false, 'message' => 'Nama pasien dan Nomor ID wajib diisi.']);
                    break;
                }

                $noAntrean = generateNomorAntrean($pdo, $klinikId);

                $insert = $pdo->prepare(
                    "INSERT INTO antrean (klinik_id, no_antrean, nama_pasien, nomor_id, is_bpjs, status, created_at)
                     VALUES (?, ?, ?, ?, ?, 'menunggu', NOW())"
                );
                $insert->execute([$klinikId, $noAntrean, $namaPasien, $nomorId, $isBpjs]);

                echo json_encode(['success' => true, 'no_antrean' => $noAntrean]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }

    exit;
}

/* ==========================================================================
 * 4. FUNGSI BANTUAN UNTUK MENGAMBIL DATA HALAMAN
 * ========================================================================== */

function generateNomorAntrean(PDO $pdo, int $klinikId): string
{
    $stmt = $pdo->prepare(
        "SELECT nama_klinik FROM klinik WHERE id = ?"
    );
    $stmt->execute([$klinikId]);
    $klinik = $stmt->fetch();
    $prefix = $klinik ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $klinik['nama_klinik']), -1)) : 'A';

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total FROM antrean
         WHERE klinik_id = ? AND DATE(created_at) = CURDATE()"
    );
    $stmt->execute([$klinikId]);
    $total = (int) $stmt->fetch()['total'];

    return $prefix . '-' . str_pad((string) ($total + 1), 3, '0', STR_PAD_LEFT);
}

function getKlinikList(PDO $pdo): array
{
    return $pdo->query("SELECT id, nama_klinik FROM klinik ORDER BY nama_klinik ASC")->fetchAll();
}

function getDokterList(PDO $pdo, int $klinikId): array
{
    $stmt = $pdo->prepare("SELECT id, nama_dokter FROM dokter WHERE klinik_id = ? ORDER BY nama_dokter ASC");
    $stmt->execute([$klinikId]);
    return $stmt->fetchAll();
}

function getSedangDipanggil(PDO $pdo, int $klinikId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM antrean WHERE klinik_id = ? AND status = 'dipanggil'
         ORDER BY updated_at DESC LIMIT 1"
    );
    $stmt->execute([$klinikId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getStats(PDO $pdo, int $klinikId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            SUM(status = 'menunggu') AS menunggu,
            SUM(status = 'selesai' AND DATE(updated_at) = CURDATE()) AS selesai_hari_ini
         FROM antrean WHERE klinik_id = ?"
    );
    $stmt->execute([$klinikId]);
    $row = $stmt->fetch();

    return [
        'menunggu'         => (int) ($row['menunggu'] ?? 0),
        'selesai_hari_ini' => (int) ($row['selesai_hari_ini'] ?? 0),
    ];
}

function getAntreanList(PDO $pdo, int $klinikId): array
{
    $stmt = $pdo->prepare(
        "SELECT a.*, k.nama_klinik
         FROM antrean a
         JOIN klinik k ON k.id = a.klinik_id
         WHERE a.klinik_id = ? AND a.status IN ('menunggu', 'dipanggil')
         ORDER BY FIELD(a.status, 'dipanggil', 'menunggu'), a.created_at ASC"
    );
    $stmt->execute([$klinikId]);
    return $stmt->fetchAll();
}

/* ==========================================================================
 * 5. AMBIL DATA UNTUK MERENDER HALAMAN (dipakai di bagian HTML)
 * ========================================================================== */
$klinikId = isset($_GET['klinik_id']) ? (int) $_GET['klinik_id'] : 1;
$dokterId = isset($_GET['dokter_id']) ? (int) $_GET['dokter_id'] : 0;

$daftarKlinik    = getKlinikList($pdo);
$daftarDokter    = getDokterList($pdo, $klinikId);
$sedangDipanggil = getSedangDipanggil($pdo, $klinikId);
$stats           = getStats($pdo, $klinikId);
$antreanList     = getAntreanList($pdo, $klinikId);

/* ==========================================================================
 * 6. CONTOH PENYISIPAN KE HTML YANG SUDAH ADA (referensi, tidak dieksekusi)
 * --------------------------------------------------------------------------

 -- Dropdown "PILIH KLINIK" --
 <select id="klinikSelect">
     <?php foreach ($daftarKlinik as $k): ?>
         <option value="<?= $k['id'] ?>" <?= $k['id'] == $klinikId ? 'selected' : '' ?>>
             <?= htmlspecialchars($k['nama_klinik']) ?>
         </option>
     <?php endforeach; ?>
 </select>

 -- Dropdown "DOKTER BERTUGAS" --
 <select id="dokterSelect">
     <?php foreach ($daftarDokter as $d): ?>
         <option value="<?= $d['id'] ?>" <?= $d['id'] == $dokterId ? 'selected' : '' ?>>
             <?= htmlspecialchars($d['nama_dokter']) ?>
         </option>
     <?php endforeach; ?>
 </select>

 -- Kotak "Sedang Memanggil" --
 <div class="queue-num"><?= $sedangDipanggil ? htmlspecialchars($sedangDipanggil['no_antrean']) : '-' ?></div>
 <div class="queue-name"><?= $sedangDipanggil ? htmlspecialchars($sedangDipanggil['nama_pasien']) : 'Belum ada panggilan' ?></div>

 -- Statistik --
 <div class="num"><?= $stats['menunggu'] ?></div>
 <div class="num"><?= $stats['selesai_hari_ini'] ?></div>

 -- Baris tabel "Daftar Antrean Pasien" --
 <?php foreach ($antreanList as $row): ?>
     <tr class="<?= $row['status'] === 'dipanggil' ? 'row-calling' : '' ?>">
         <td class="queue-no-cell"><?= htmlspecialchars($row['no_antrean']) ?></td>
         <td class="name-cell">
             <?= htmlspecialchars($row['nama_pasien']) ?>
             <?php if ($row['is_bpjs']): ?><span class="bpjs-tag">BPJS</span><?php endif; ?>
         </td>
         <td class="id-cell"><?= htmlspecialchars($row['nomor_id']) ?></td>
         <td><?= htmlspecialchars($row['nama_klinik']) ?></td>
         <td>
             <?php if ($row['status'] === 'dipanggil'): ?>
                 <span class="status-badge calling"><span class="dot"></span>DIPANGGIL</span>
             <?php else: ?>
                 <span class="status-badge waiting"><span class="dot"></span>MENUNGGU</span>
             <?php endif; ?>
         </td>
         <td class="actions-cell">
             <div class="action-icons" data-antrean-id="<?= $row['id'] ?>">
                 <!-- ikon aksi tetap sama seperti HTML asli -->
             </div>
         </td>
     </tr>
 <?php endforeach; ?>

 -- Tombol aksi (dipanggil lewat fetch() dari JS yang sudah ada) --
 fetch('antrean-pasien-logic.php', {
     method: 'POST',
     headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
     body: 'action=panggil_berikutnya&klinik_id=<?= $klinikId ?>&dokter_id=<?= $dokterId ?>'
 }).then(r => r.json()).then(data => { if (data.success) location.reload(); });

 ========================================================================== */