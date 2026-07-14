<?php
/**
 * farmasi.php
 * ------------------------------------------------------------
 * Logika PHP untuk halaman Farmasi (SIMRS Amino).
 * File ini HANYA berisi bagian PHP: koneksi database, query,
 * fungsi bantu, dan handler AJAX untuk aksi tombol.
 *
 * Cara pakai:
 * 1. Sambungkan file ini di paling atas farmasi.html (ganti
 *    ekstensi file HTML menjadi .php), lalu ganti bagian yang
 *    datanya statis (kartu resep, tabel obat, stok tipis, dsb)
 *    dengan echo dari variabel di bawah ini. Contoh penempatan
 *    sudah ditandai di komentar "== GANTI DI HTML ==" pada tiap
 *    bagian.
 * 2. Sesuaikan nama tabel/kolom dengan struktur database Anda.
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
// 2. HANDLER AKSI (dipanggil via AJAX / fetch dari JS)
//    Contoh: fetch('farmasi.php?action=update_status', {...})
// =====================================================
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($action) {
    header('Content-Type: application/json; charset=UTF-8');

    switch ($action) {

        // ---- Ubah status resep: Menunggu -> Disiapkan ----
        case 'selesaikan_racikan':
            $resepId = $_POST['resep_id'] ?? '';
            if (!$resepId) {
                echo json_encode(['success' => false, 'message' => 'resep_id wajib diisi']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE resep SET status = 'disiapkan' WHERE kode_resep = ?");
            $stmt->execute([$resepId]);
            echo json_encode(['success' => true, 'status' => 'disiapkan']);
            exit;

        // ---- Konfirmasi penyerahan obat ke pasien ----
        case 'konfirmasi_penyerahan':
            $resepId   = $_POST['resep_id'] ?? '';
            $checklist = json_decode($_POST['checklist'] ?? '[]', true);
            $apoteker  = $_SESSION['user_nama'] ?? 'Apt. Budi Santoso';

            if (!$resepId || in_array(false, $checklist ?: [], true)) {
                echo json_encode(['success' => false, 'message' => 'Checklist belum lengkap']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE resep SET status = 'selesai' WHERE kode_resep = ?");
                $stmt->execute([$resepId]);

                $stmt = $pdo->prepare(
                    "INSERT INTO log_penyerahan (resep_id, apoteker, waktu, checklist_json)
                     VALUES (?, ?, NOW(), ?)"
                );
                $stmt->execute([$resepId, $apoteker, json_encode($checklist)]);

                $pdo->commit();
                echo json_encode(['success' => true, 'status' => 'selesai']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;

        // ---- Cari resep / pasien (search box) ----
        case 'cari_resep':
            $q = '%' . ($_GET['q'] ?? '') . '%';
            $stmt = $pdo->prepare(
                "SELECT r.kode_resep, p.nama AS pasien, p.no_rm
                 FROM resep r
                 JOIN pasien p ON p.id = r.pasien_id
                 WHERE p.nama LIKE ? OR p.no_rm LIKE ? OR r.kode_resep LIKE ?
                 LIMIT 10"
            );
            $stmt->execute([$q, $q, $q]);
            echo json_encode($stmt->fetchAll());
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali']);
            exit;
    }
}

// =====================================================
// 3. DATA APOTEKER YANG SEDANG LOGIN (topbar)
// =====================================================
$apotekerNama = $_SESSION['user_nama']  ?? 'Apt. Budi Santoso, S.Farm';
$apotekerRole = $_SESSION['user_role']  ?? 'APOTEKER UTAMA';
$apotekerFoto = $_SESSION['user_foto']  ?? 'https://images.unsplash.com/photo-1622253692010-333f2da6031d?w=100&h=100&fit=crop&crop=faces';
$notifBelum   = (int)$pdo->query("SELECT COUNT(*) FROM notifikasi WHERE dibaca = 0")->fetchColumn();

// == GANTI DI HTML ==
// <div class="name"><?= htmlspecialchars($apotekerNama) ?></div>
// <div class="role"><?= htmlspecialchars($apotekerRole) ?></div>
// <img class="avatar" src="<?= htmlspecialchars($apotekerFoto) ?>" ...>
// <span class="dot" style="display: <?= $notifBelum > 0 ? 'block' : 'none' ?>;"></span>

// =====================================================
// 4. DAFTAR RESEP MASUK (kolom kiri)
// =====================================================
$stmtAntrean = $pdo->query(
    "SELECT r.kode_resep, r.waktu_terima, r.prioritas, r.jenis_pasien, r.status,
            p.nama AS pasien, p.no_rm, d.nama AS dokter,
            (SELECT COUNT(*) FROM resep_detail rd WHERE rd.resep_id = r.id) AS jumlah_obat
     FROM resep r
     JOIN pasien p ON p.id = r.pasien_id
     JOIN dokter d ON d.id = r.dokter_id
     WHERE r.tanggal = CURDATE()
     ORDER BY FIELD(r.status,'menunggu','disiapkan','selesai'), r.waktu_terima ASC"
);
$antrean = $stmtAntrean->fetchAll();
$totalAntrean = count($antrean);

// == GANTI DI HTML ==
// <span class="count-badge" id="antreanCount"><?= $totalAntrean ?> Antrean</span>
//
// <div class="rx-list" id="rxList">
// <?php foreach ($antrean as $r):
//     $filterAttr = trim(($r['prioritas'] === 'prioritas' ? 'prioritas ' : '') . ($r['jenis_pasien'] === 'bpjs' ? 'bpjs' : ''));
//     $flagLabel  = $r['prioritas'] === 'prioritas' ? 'Priority · IGD' : ucfirst($r['jenis_pasien']) . ' · RJ';
//     $flagClass  = $r['prioritas'] === 'prioritas' ? '' : ($r['jenis_pasien'] === 'bpjs' ? 'bpjs' : 'regular');
// ?>
//   <div class="rx-card" data-filter="<?= htmlspecialchars($filterAttr) ?>" data-resep-id="<?= htmlspecialchars($r['kode_resep']) ?>" tabindex="0">
//     <div class="rx-top-row">
//       <span class="rx-flag <?= $flagClass ?>"><?= htmlspecialchars($flagLabel) ?></span>
//       <span class="rx-time"><?= date('H:i', strtotime($r['waktu_terima'])) ?></span>
//     </div>
//     <div class="rx-patient"><?= htmlspecialchars($r['pasien']) ?></div>
//     <div class="rx-sub">RM: <?= htmlspecialchars($r['no_rm']) ?> · <?= htmlspecialchars($r['dokter']) ?></div>
//     <div class="rx-bottom-row">
//       <span class="status-badge <?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span>
//       <span class="rx-item-count"><?= $r['jumlah_obat'] ?> Obat</span>
//     </div>
//   </div>
// <?php endforeach; ?>
// </div>

// =====================================================
// 5. DETAIL RESEP TERPILIH (kolom tengah)
//    Default: resep pertama di antrean, atau via ?resep_id=
// =====================================================
$resepIdAktif = $_GET['resep_id'] ?? ($antrean[0]['kode_resep'] ?? null);
$detailResep  = null;
$obatList     = [];

if ($resepIdAktif) {
    $stmt = $pdo->prepare(
        "SELECT r.kode_resep, r.waktu_terima, r.iter,
                p.nama AS pasien, p.no_rm, p.umur, p.gender,
                d.nama AS dokter, d.spesialisasi, d.poli
         FROM resep r
         JOIN pasien p ON p.id = r.pasien_id
         JOIN dokter d ON d.id = r.dokter_id
         WHERE r.kode_resep = ?"
    );
    $stmt->execute([$resepIdAktif]);
    $detailResep = $stmt->fetch();

    if ($detailResep) {
        $stmt = $pdo->prepare(
            "SELECT nama_obat, kategori, bentuk, dosis, aturan, jumlah, satuan
             FROM resep_detail rd
             JOIN resep r ON r.id = rd.resep_id
             WHERE r.kode_resep = ?"
        );
        $stmt->execute([$resepIdAktif]);
        $obatList = $stmt->fetchAll();
    }
}

// == GANTI DI HTML ==
// <div class="detail-title">Detail Resep #<?= htmlspecialchars($detailResep['kode_resep'] ?? '-') ?></div>
// <div class="received-note">Diterima <?= date('d M Y, H:i', strtotime($detailResep['waktu_terima'] ?? 'now')) ?> WIB</div>
// <div class="info-box-value"><?= htmlspecialchars($detailResep['pasien'] ?? '-') ?> (<?= $detailResep['umur'] ?? '-' ?> Th)</div>
// <div class="info-box-sub">RM: <?= htmlspecialchars($detailResep['no_rm'] ?? '-') ?> · <?= htmlspecialchars($detailResep['gender'] ?? '-') ?></div>
// <div class="info-box-value"><?= htmlspecialchars($detailResep['dokter'] ?? '-') ?></div>
// <div class="info-box-sub"><?= htmlspecialchars($detailResep['poli'] ?? '-') ?></div>
// Daftar Obat (Iter: <?= $detailResep['iter'] ?? 0 ?>)
//
// <tbody>
// <?php foreach ($obatList as $o): ?>
//   <tr>
//     <td>
//       <div class="drug-name <?= $o['kategori'] === 'terbatas' ? 'controlled' : '' ?>"><?= htmlspecialchars($o['nama_obat']) ?></div>
//       <div class="drug-form"><?= htmlspecialchars($o['bentuk']) ?> · <?= $o['kategori'] === 'terbatas' ? 'Obat Terbatas' : 'Generic' ?></div>
//     </td>
//     <td class="drug-note"><?= htmlspecialchars($o['dosis']) ?><br>(<?= htmlspecialchars($o['aturan']) ?>)</td>
//     <td class="drug-qty"><?= $o['jumlah'] ?> <?= htmlspecialchars($o['satuan']) ?></td>
//   </tr>
// <?php endforeach; ?>
// </tbody>

// =====================================================
// 6. PERINGATAN STOK TIPIS (kolom tengah, bawah)
// =====================================================
$stokTipis = $pdo->query(
    "SELECT nama_obat, bentuk, jumlah, satuan
     FROM stok_obat
     WHERE jumlah <= ambang_minimum
     ORDER BY jumlah ASC
     LIMIT 6"
)->fetchAll();

// == GANTI DI HTML ==
// <div class="stock-grid">
// <?php foreach ($stokTipis as $s): ?>
//   <div class="stock-item">
//     <div class="stock-item-name"><?= htmlspecialchars($s['nama_obat']) ?></div>
//     <div class="stock-item-form"><?= htmlspecialchars($s['bentuk']) ?></div>
//     <div class="stock-item-qty"><?= $s['jumlah'] ?> <span><?= htmlspecialchars($s['satuan']) ?></span></div>
//   </div>
// <?php endforeach; ?>
// </div>

// =====================================================
// 7. STATUS SISTEM (footer)
// =====================================================
$dukcapilOnline = true;   // ganti dengan hasil ping/cek API Dukcapil
$vclaimOnline   = true;   // ganti dengan hasil ping/cek API VClaim BPJS

// == GANTI DI HTML ==
// <span><span class="status-dot" style="background: <?= $dukcapilOnline ? 'var(--green)' : 'var(--red)' ?>;"></span>
//   Sistem Dukcapil <?= $dukcapilOnline ? 'Terkoneksi' : 'Terputus' ?></span>
// <span><span class="status-dot" style="background: <?= $vclaimOnline ? 'var(--green)' : 'var(--red)' ?>;"></span>
//   VClaim BPJS <?= $vclaimOnline ? 'Aktif' : 'Nonaktif' ?></span>