<?php
/**
 * ============================================================================
 * SIMRS Amino - Pemeriksaan Dokter
 * ============================================================================
 * Catatan konversi dari HTML statis ke PHP:
 * - Semua data yang tadinya hardcoded (identitas pasien, vital sign, alergi,
 *   diagnosis, resep, riwayat kunjungan, daftar poliklinik) sekarang diambil
 *   dari database.
 * - Tombol "Tambah Obat", "Hapus Obat", "Pilih Poliklinik (Rujuk Internal)",
 *   dan "Simpan & Selesai Pemeriksaan" sekarang memanggil endpoint AJAX
 *   (action=... di file ini sendiri) alih-alih hanya memanipulasi DOM.
 * - Sesuaikan nama tabel/kolom di bawah ini dengan skema database Anda.
 * ============================================================================
 */

session_start();
header_remove('X-Powered-By');
date_default_timezone_set('Asia/Jakarta');

/* ---------------------------------------------------------------------------
 * 1. KONEKSI DATABASE
 * ------------------------------------------------------------------------- */
$DB_HOST = 'localhost';
$DB_NAME = 'simrs_amino';
$DB_USER = 'root';
$DB_PASS = '';

$koneksi = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($koneksi->connect_error) {
    http_response_code(500);
    die('Koneksi database gagal: ' . $koneksi->connect_error);
}
$koneksi->set_charset('utf8mb4');

/* ---------------------------------------------------------------------------
 * 2. AUTH SEDERHANA (sesuaikan dengan sistem login yang sudah ada)
 * ------------------------------------------------------------------------- */
if (empty($_SESSION['dokter_id'])) {
    // Contoh dummy supaya file bisa langsung dites, hapus di production.
    $_SESSION['dokter_id'] = 1;
}
$dokterId = (int) $_SESSION['dokter_id'];

/* ---------------------------------------------------------------------------
 * 3. HELPER
 * ------------------------------------------------------------------------- */
function e($string)
{
    return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8');
}

function jsonResponse($data, int $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/* ---------------------------------------------------------------------------
 * 4. PARAMETER KUNJUNGAN AKTIF
 *    (dikirim dari halaman antrian, mis: pemeriksaan-dokter.php?kunjungan_id=55)
 * ------------------------------------------------------------------------- */
$kunjunganId = isset($_GET['kunjungan_id']) ? (int) $_GET['kunjungan_id'] : (int) ($_POST['kunjungan_id'] ?? 0);

if ($kunjunganId <= 0) {
    die('Parameter kunjungan_id tidak valid.');
}

/* ---------------------------------------------------------------------------
 * 5. HANDLER AJAX (dipanggil via fetch() dari JavaScript di bawah)
 * ------------------------------------------------------------------------- */
$action = $_POST['action'] ?? $_GET['action'] ?? null;

if ($action !== null) {

    switch ($action) {

        /* ---- Tambah obat ke resep ------------------------------------- */
        case 'tambah_obat':
            $nama       = trim($_POST['nama_obat'] ?? '');
            $dosis      = trim($_POST['dosis'] ?? '-');
            $freqRaw    = trim($_POST['frekuensi'] ?? '0|Sesuai Kebutuhan (PRN)');
            [$freqCount, $freqWaktu] = array_pad(explode('|', $freqRaw, 2), 2, '');
            $durasi     = (int) ($_POST['durasi'] ?? 0);
            $total      = (int) ($_POST['total'] ?? 0);
            $instruksi  = trim($_POST['instruksi'] ?? '');

            if ($nama === '') {
                jsonResponse(['success' => false, 'message' => 'Nama obat wajib diisi.'], 422);
            }

            $freqLabel = ($freqCount === '0')
                ? $freqWaktu
                : $freqCount . ' x 1 (' . $freqWaktu . ')';

            $stmt = $koneksi->prepare(
                'INSERT INTO resep (kunjungan_id, nama_obat, dosis, frekuensi_label, durasi_hari, jumlah, instruksi, dibuat_pada)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->bind_param('issssis', $kunjunganId, $nama, $dosis, $freqLabel, $durasi, $total, $instruksi);
            $stmt->execute();
            $obatId = $stmt->insert_id;
            $stmt->close();

            jsonResponse([
                'success' => true,
                'obat'    => [
                    'id'        => $obatId,
                    'nama_obat' => $nama,
                    'dosis'     => $dosis,
                    'frekuensi' => $freqLabel,
                    'durasi'    => $durasi . ' Hari',
                    'jumlah'    => $total . ' Tab',
                ],
            ]);
            break;

        /* ---- Hapus obat dari resep -------------------------------------- */
        case 'hapus_obat':
            $obatId = (int) ($_POST['obat_id'] ?? 0);
            $stmt = $koneksi->prepare('DELETE FROM resep WHERE id = ? AND kunjungan_id = ?');
            $stmt->bind_param('ii', $obatId, $kunjunganId);
            $stmt->execute();
            $stmt->close();
            jsonResponse(['success' => true]);
            break;

        /* ---- Hapus diagnosis ICD-10 dari kunjungan ---------------------- */
        case 'hapus_diagnosis':
            $icdId = (int) ($_POST['icd_id'] ?? 0);
            $stmt = $koneksi->prepare('DELETE FROM kunjungan_diagnosis WHERE kunjungan_id = ? AND icd10_id = ?');
            $stmt->bind_param('ii', $kunjunganId, $icdId);
            $stmt->execute();
            $stmt->close();
            jsonResponse(['success' => true]);
            break;

        /* ---- Cari ICD-10 (autocomplete diagnosis) ----------------------- */
        case 'cari_icd10':
            $kata = '%' . trim($_GET['q'] ?? $_POST['q'] ?? '') . '%';
            $stmt = $koneksi->prepare(
                'SELECT id, kode, nama FROM master_icd10
                 WHERE kode LIKE ? OR nama LIKE ? LIMIT 15'
            );
            $stmt->bind_param('ss', $kata, $kata);
            $stmt->execute();
            $hasil = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            jsonResponse(['success' => true, 'data' => $hasil]);
            break;

        /* ---- Tambah diagnosis ICD-10 ke kunjungan ----------------------- */
        case 'tambah_diagnosis':
            $icdId = (int) ($_POST['icd_id'] ?? 0);
            $stmt = $koneksi->prepare(
                'INSERT IGNORE INTO kunjungan_diagnosis (kunjungan_id, icd10_id) VALUES (?, ?)'
            );
            $stmt->bind_param('ii', $kunjunganId, $icdId);
            $stmt->execute();
            $stmt->close();

            $stmt = $koneksi->prepare('SELECT kode, nama FROM master_icd10 WHERE id = ?');
            $stmt->bind_param('i', $icdId);
            $stmt->execute();
            $icd = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            jsonResponse(['success' => true, 'diagnosis' => $icd]);
            break;

        /* ---- Simpan pilihan poliklinik rujuk internal ------------------- */
        case 'pilih_poli':
            $poliId = (int) ($_POST['poli_id'] ?? 0);
            $stmt = $koneksi->prepare(
                'INSERT INTO rujukan_internal (kunjungan_id, poliklinik_id, dibuat_pada)
                 VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE poliklinik_id = VALUES(poliklinik_id), dibuat_pada = NOW()'
            );
            $stmt->bind_param('ii', $kunjunganId, $poliId);
            $stmt->execute();
            $stmt->close();

            $stmt = $koneksi->prepare('SELECT nama FROM poliklinik WHERE id = ?');
            $stmt->bind_param('i', $poliId);
            $stmt->execute();
            $poli = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $stmt = $koneksi->prepare(
                'UPDATE kunjungan SET disposisi = "rujuk-internal" WHERE id = ?'
            );
            $stmt->bind_param('i', $kunjunganId);
            $stmt->execute();
            $stmt->close();

            jsonResponse(['success' => true, 'poli' => $poli]);
            break;

        /* ---- Set disposisi biasa (rawat jalan / rawat inap) ------------- */
        case 'set_disposisi':
            $disposisi = trim($_POST['disposisi'] ?? 'rawat-jalan');
            $allowed = ['rawat-jalan', 'rawat-inap'];
            if (!in_array($disposisi, $allowed, true)) {
                jsonResponse(['success' => false, 'message' => 'Disposisi tidak valid.'], 422);
            }
            $stmt = $koneksi->prepare('UPDATE kunjungan SET disposisi = ? WHERE id = ?');
            $stmt->bind_param('si', $disposisi, $kunjunganId);
            $stmt->execute();
            $stmt->close();
            jsonResponse(['success' => true]);
            break;

        /* ---- Simpan & Selesai Pemeriksaan -------------------------------- */
        case 'simpan_pemeriksaan':
            $anamnesis = trim($_POST['anamnesis'] ?? '');
            if ($anamnesis === '') {
                jsonResponse(['success' => false, 'message' => 'Anamnesis wajib diisi.'], 422);
            }
            $stmt = $koneksi->prepare(
                'UPDATE kunjungan
                 SET anamnesis = ?, status = "selesai", diperiksa_oleh = ?, selesai_pada = NOW()
                 WHERE id = ?'
            );
            $stmt->bind_param('sii', $anamnesis, $dokterId, $kunjunganId);
            $stmt->execute();
            $stmt->close();

            jsonResponse(['success' => true, 'redirect' => 'antrian-pemeriksaan.php']);
            break;

        default:
            jsonResponse(['success' => false, 'message' => 'Aksi tidak dikenal.'], 400);
    }
}

/* ---------------------------------------------------------------------------
 * 6. AMBIL DATA UNTUK RENDER HALAMAN (GET biasa, bukan AJAX)
 * ------------------------------------------------------------------------- */

// -- Dokter yang sedang login
$stmt = $koneksi->prepare(
    'SELECT nama, gelar, role FROM dokter WHERE id = ?'
);
$stmt->bind_param('i', $dokterId);
$stmt->execute();
$dokter = $stmt->get_result()->fetch_assoc() ?: ['nama' => '-', 'gelar' => '', 'role' => 'DOKTER'];
$stmt->close();

// -- Kunjungan + pasien
$stmt = $koneksi->prepare(
    'SELECT k.id AS kunjungan_id, k.anamnesis, k.disposisi,
            p.id AS pasien_id, p.no_rm, p.nama AS nama_pasien, p.tanggal_lahir,
            p.gender, p.foto, p.jenis_penjaminan
     FROM kunjungan k
     JOIN pasien p ON p.id = k.pasien_id
     WHERE k.id = ?'
);
$stmt->bind_param('i', $kunjunganId);
$stmt->execute();
$kunjungan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$kunjungan) {
    die('Data kunjungan tidak ditemukan.');
}

$pasienId = (int) $kunjungan['pasien_id'];
$umur = date_diff(date_create($kunjungan['tanggal_lahir']), date_create('now'))->y;

// -- Diagnosis ICD-10 yang sudah dipilih untuk kunjungan ini
$stmt = $koneksi->prepare(
    'SELECT m.id, m.kode, m.nama
     FROM kunjungan_diagnosis kd
     JOIN master_icd10 m ON m.id = kd.icd10_id
     WHERE kd.kunjungan_id = ?'
);
$stmt->bind_param('i', $kunjunganId);
$stmt->execute();
$diagnosisList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// -- Resep / obat yang sudah ditambahkan
$stmt = $koneksi->prepare(
    'SELECT id, nama_obat, dosis, frekuensi_label, durasi_hari, jumlah
     FROM resep WHERE kunjungan_id = ? ORDER BY id ASC'
);
$stmt->bind_param('i', $kunjunganId);
$stmt->execute();
$resepList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// -- Vital sign hasil asesmen perawat
$stmt = $koneksi->prepare(
    'SELECT tekanan_darah, suhu, nadi, rr, spo2, berat_badan, tinggi_badan, keluhan_perawat
     FROM vital_sign WHERE kunjungan_id = ? ORDER BY id DESC LIMIT 1'
);
$stmt->bind_param('i', $kunjunganId);
$stmt->execute();
$vital = $stmt->get_result()->fetch_assoc() ?: [
    'tekanan_darah' => '-', 'suhu' => '-', 'nadi' => '-', 'rr' => '-',
    'spo2' => '-', 'berat_badan' => '-', 'tinggi_badan' => '-', 'keluhan_perawat' => '-',
];
$stmt->close();

// -- Alergi pasien
$stmt = $koneksi->prepare('SELECT nama, jenis FROM alergi WHERE pasien_id = ?');
$stmt->bind_param('i', $pasienId);
$stmt->execute();
$alergiList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// -- Riwayat kunjungan sebelumnya (timeline)
$stmt = $koneksi->prepare(
    'SELECT tanggal, unit_pelayanan, ringkasan
     FROM riwayat_kunjungan
     WHERE pasien_id = ? AND kunjungan_id != ?
     ORDER BY tanggal DESC LIMIT 10'
);
$stmt->bind_param('ii', $pasienId, $kunjunganId);
$stmt->execute();
$riwayatList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// -- Daftar poliklinik untuk modal Rujuk Internal
$stmt = $koneksi->prepare('SELECT id, nama FROM poliklinik ORDER BY nama ASC');
$stmt->execute();
$poliList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// -- Poli tujuan yang sudah pernah dipilih (jika ada)
$stmt = $koneksi->prepare(
    'SELECT pk.id, pk.nama
     FROM rujukan_internal ri
     JOIN poliklinik pk ON pk.id = ri.poliklinik_id
     WHERE ri.kunjungan_id = ?'
);
$stmt->bind_param('i', $kunjunganId);
$stmt->execute();
$poliTerpilih = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemeriksaan Dokter - SIMRS Amino</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <!-- CSS tidak diubah dari versi HTML asli — silakan sertakan file style.css
         atau blok <style> yang sama seperti pada file HTML sumber di sini. -->
    <link rel="stylesheet" href="assets/css/pemeriksaan-dokter.css">
</head>

<body>

    <div class="app">

        <div id="navbar-container">
            <div class="sidebar-loading">Memuat menu...</div>
        </div>

        <main class="main">

            <header class="topbar">
                <div class="brand">RSJD Dr. Amino Gondohutomo</div>
                <div class="topbar-right">
                    <div class="doctor-chip">
                        <div class="doc-avatar">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="8" r="4" />
                                <path d="M4 20c1.5-4 5-6 8-6s6.5 2 8 6" />
                            </svg>
                        </div>
                        <div>
                            <div class="doc-name"><?= e($dokter['nama']) ?><?= $dokter['gelar'] ? ', ' . e($dokter['gelar']) : '' ?></div>
                            <span class="doctor-role-badge"><?= e($dokter['role']) ?></span>
                        </div>
                    </div>
                </div>
            </header>

            <section class="content">

                <div class="patient-bar">
                    <img class="patient-photo"
                        src="<?= e($kunjungan['foto'] ?: 'assets/img/default-avatar.png') ?>"
                        alt="Foto pasien">

                    <div class="patient-headline">
                        <div class="patient-name-row">
                            <div class="patient-name"><?= e($kunjungan['nama_pasien']) ?></div>
                            <span class="pill bpjs"><?= e($kunjungan['jenis_penjaminan']) ?></span>
                        </div>
                        <div class="patient-meta">
                            <span><b>No. RM:</b> <?= e($kunjungan['no_rm']) ?></span>
                            <span class="sep">|</span>
                            <span><b>Umur/Tgl Lahir:</b> <?= e($umur) ?> Th /
                                <?= e(date('d F Y', strtotime($kunjungan['tanggal_lahir']))) ?></span>
                            <span class="sep">|</span>
                            <span><b>Gender:</b> <?= e($kunjungan['gender']) ?></span>
                        </div>
                    </div>

                    <div class="patient-actions">
                        <button class="btn btn-outline-navy">Data Historis</button>
                        <button class="btn btn-navy">Ringkasan Medis</button>
                    </div>
                </div>

                <div class="tabs">
                    <button class="tab-btn active" data-tab="tab-pemeriksaan">Pemeriksaan &amp; Resep</button>
                    <button class="tab-btn" data-tab="tab-asesmen">Asesmen &amp; Riwayat</button>
                </div>

                <div class="tab-panel active" id="tab-pemeriksaan">
                    <div class="panel">

                        <div class="field-block">
                            <div class="field-label">Anamnesis / Keluhan &amp; Riwayat Penyakit Sekarang <span
                                    class="req">*</span></div>
                            <textarea class="input-area" id="inputAnamnesis"
                                placeholder="Tuliskan keluhan pasien secara mendetail di sini..."><?= e($kunjungan['anamnesis']) ?></textarea>
                        </div>

                        <div class="field-block">
                            <div class="field-label">Diagnosis (ICD-10)</div>
                            <div class="search-input">
                                <input type="text" id="icd10SearchInput" placeholder="Cari kode ICD-10 atau nama penyakit...">
                            </div>
                            <div class="tags-row" id="diagnosisTagsRow">
                                <?php foreach ($diagnosisList as $dx): ?>
                                    <span class="diagnosis-tag" data-icd-id="<?= (int) $dx['id'] ?>">
                                        <?= e($dx['kode']) ?> - <?= e($dx['nama']) ?>
                                        <button aria-label="Hapus diagnosis" class="btn-hapus-diagnosis">×</button>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="field-block">
                            <div class="field-label">Permintaan Pemeriksaan Penunjang</div>
                            <div class="penunjang-grid">
                                <div class="penunjang-box"><span>Laboratorium</span></div>
                                <div class="penunjang-box"><span>Radiologi</span></div>
                                <div class="penunjang-box"><span>Elektro Diagnostik</span></div>
                            </div>
                        </div>
                    </div>

                    <div class="panel" style="margin-top:22px;">
                        <div class="panel-head">
                            <h2>Resep Elektronik</h2>
                            <button class="btn btn-navy" id="btnTambahObat">+ Tambah Obat</button>
                        </div>

                        <table class="obat-table">
                            <thead>
                                <tr>
                                    <th>Nama Obat</th>
                                    <th>Dosis</th>
                                    <th>Frekuensi</th>
                                    <th>Lama</th>
                                    <th>Jumlah</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="obatTableBody">
                                <?php foreach ($resepList as $obat): ?>
                                    <tr data-obat-id="<?= (int) $obat['id'] ?>">
                                        <td><div class="obat-name"><?= e($obat['nama_obat']) ?></div></td>
                                        <td><?= e($obat['dosis']) ?></td>
                                        <td><?= e($obat['frekuensi_label']) ?></td>
                                        <td><?= (int) $obat['durasi_hari'] ?> Hari</td>
                                        <td><?= e($obat['jumlah']) ?></td>
                                        <td>
                                            <button class="del-btn" aria-label="Hapus obat">Hapus</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="panel" style="margin-top:22px;">
                        <div class="panel-head" style="margin-bottom:16px;">
                            <div class="field-label" style="margin-bottom:0;">Tindak Lanjut / Disposisi</div>
                            <a href="surat-izin-dokter.php?kunjungan_id=<?= $kunjunganId ?>" class="btn btn-outline-navy">
                                Surat Izin Dokter
                            </a>
                        </div>
                        <div class="disposisi-row">
                            <button class="disposisi-chip <?= $kunjungan['disposisi'] === 'rawat-jalan' ? 'selected' : '' ?>"
                                data-disposisi="rawat-jalan">Rawat Jalan</button>
                            <button class="disposisi-chip <?= $kunjungan['disposisi'] === 'rawat-inap' ? 'selected' : '' ?>"
                                data-disposisi="rawat-inap">Rawat Inap</button>
                            <button class="disposisi-chip <?= $kunjungan['disposisi'] === 'rujuk-internal' ? 'selected' : '' ?>"
                                id="chipRujukInternal" data-disposisi="rujuk-internal">Rujuk Internal</button>
                            <a class="disposisi-chip" data-disposisi="rujuk-eksternal"
                                href="surat-rujukan-eksternal.php?kunjungan_id=<?= $kunjunganId ?>">
                                Rujuk Eksternal
                            </a>
                        </div>
                        <div class="rujuk-target-note" id="rujukInternalNote"
                            style="display:<?= $poliTerpilih ? 'flex' : 'none' ?>;">
                            Tujuan rujukan: <b id="rujukInternalTarget"><?= e($poliTerpilih['nama'] ?? '') ?></b>
                        </div>
                    </div>
                </div>

                <div class="tab-panel" id="tab-asesmen">
                    <div class="grid-2col">

                        <div class="panel">
                            <div class="panel-head"><h2>Hasil Asesmen Perawat (Anamnesis &amp; Vital Sign)</h2></div>

                            <div class="field-label">Keluhan Utama (Perawat)</div>
                            <div class="keluhan-perawat"><?= nl2br(e($vital['keluhan_perawat'])) ?></div>

                            <div class="vitals-grid">
                                <div class="vital-box">
                                    <div class="vlabel">TD (BP)</div>
                                    <div class="vvalue"><?= e($vital['tekanan_darah']) ?> <span class="vunit">mmHg</span></div>
                                </div>
                                <div class="vital-box">
                                    <div class="vlabel">Suhu</div>
                                    <div class="vvalue"><?= e($vital['suhu']) ?> <span class="vunit">°C</span></div>
                                </div>
                                <div class="vital-box">
                                    <div class="vlabel">Nadi (Pulse)</div>
                                    <div class="vvalue"><?= e($vital['nadi']) ?> <span class="vunit">x/mnt</span></div>
                                </div>
                                <div class="vital-box">
                                    <div class="vlabel">RR</div>
                                    <div class="vvalue"><?= e($vital['rr']) ?> <span class="vunit">x/mnt</span></div>
                                </div>
                                <div class="vital-box">
                                    <div class="vlabel">SPO2</div>
                                    <div class="vvalue"><?= e($vital['spo2']) ?> <span class="vunit">%</span></div>
                                </div>
                                <div class="vital-box">
                                    <div class="vlabel">BB / TB</div>
                                    <div class="vvalue"><?= e($vital['berat_badan']) ?> <span class="vunit">kg</span> /
                                        <?= e($vital['tinggi_badan']) ?> <span class="vunit">cm</span></div>
                                </div>
                            </div>

                            <div class="field-label">Alergi</div>
                            <div class="tags-row">
                                <?php if (empty($alergiList)): ?>
                                    <span class="pill allergy-pill none">Tidak Ada Alergi</span>
                                <?php else: ?>
                                    <?php foreach ($alergiList as $alergi): ?>
                                        <span class="pill allergy-pill <?= e($alergi['jenis']) ?>"><?= e($alergi['nama']) ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="panel">
                            <div class="panel-head"><h2>Riwayat Kunjungan &amp; Rekam Medis</h2></div>

                            <div class="timeline">
                                <?php foreach ($riwayatList as $i => $r): ?>
                                    <div class="timeline-item <?= $i === 0 ? 'latest' : '' ?>">
                                        <div class="timeline-date">
                                            <?= e(date('d M Y', strtotime($r['tanggal']))) ?>
                                            <?= $i === 0 ? '(Kunjungan Terakhir)' : '' ?>
                                        </div>
                                        <div class="timeline-title"><?= e($r['unit_pelayanan']) ?></div>
                                        <div class="timeline-sub italic"><?= nl2br(e($r['ringkasan'])) ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($riwayatList)): ?>
                                    <div class="timeline-sub">Belum ada riwayat kunjungan sebelumnya.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>

            </section>

            <div class="bottom-bar">
                <button class="btn btn-batal">Batal &amp; Keluar</button>
                <button class="btn btn-simpan" id="btnSimpanPemeriksaan">Simpan &amp; Selesai Pemeriksaan</button>
            </div>
        </main>
    </div>

    <!-- ===== Modal: Tambah Obat Ke Resep ===== -->
    <div class="modal-overlay" id="modalTambahObat">
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-titles">
                    <h3>Tambah Obat Ke Resep</h3>
                    <p>Input detail pengobatan pasien secara presisi</p>
                </div>
                <button class="modal-close" id="modalCloseBtn" aria-label="Tutup">×</button>
            </div>
            <div class="modal-body">
                <div class="modal-field-block">
                    <div class="modal-field-label">Cari Nama Obat / Kandungan</div>
                    <div class="modal-search">
                        <input type="text" id="modalNamaObat" placeholder="Ketik nama obat...">
                    </div>
                </div>
                <div class="modal-row2 modal-field-block">
                    <div>
                        <div class="modal-field-label">Dosis</div>
                        <div class="modal-input-icon"><input type="text" id="modalDosis" placeholder="Contoh: 500mg"></div>
                    </div>
                    <div>
                        <div class="modal-field-label">Frekuensi Pemakaian</div>
                        <div class="modal-select-wrap">
                            <select class="modal-select" id="modalFrekuensi">
                                <option value="1|Pagi">1 x 1 (Pagi)</option>
                                <option value="2|Pagi, Malam">2 x 1 (Pagi, Malam)</option>
                                <option value="3|Pagi, Siang, Malam" selected>3 x 1 (Pagi, Siang, Malam)</option>
                                <option value="0|Sesuai Kebutuhan (PRN)">Sesuai Kebutuhan (PRN)</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-row2 modal-field-block">
                    <div>
                        <div class="modal-field-label">Durasi (Hari)</div>
                        <div class="modal-suffix-input"><input type="number" id="modalDurasi" value="3" min="0"><span class="suffix-tag">Hari</span></div>
                    </div>
                    <div>
                        <div class="modal-field-label">Total Kuantitas</div>
                        <div class="modal-suffix-input"><input type="text" id="modalTotal" class="calc-value" value="9" readonly><span class="suffix-tag calc">Calculated</span></div>
                    </div>
                </div>
                <div class="modal-field-block">
                    <div class="modal-field-label">Instruksi Khusus (Keterangan)</div>
                    <textarea class="modal-textarea" id="modalInstruksi" placeholder="Contoh: Diminum sesudah makan..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn-batal" id="modalBatalBtn">Batal</button>
                <button class="modal-btn-submit" id="modalSubmitBtn">Tambah ke Resep</button>
            </div>
        </div>
    </div>

    <!-- ===== Modal: Rujuk Internal ===== -->
    <div class="modal-overlay" id="modalRujukInternal">
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-titles">
                    <h3>Rujuk Internal (Konsul)</h3>
                    <p>Pilih poliklinik / pelayanan tujuan rujukan antar spesialis</p>
                </div>
                <button class="modal-close" id="rujukCloseBtn" aria-label="Tutup">×</button>
            </div>
            <div class="modal-body">
                <div class="poli-search">
                    <input type="text" id="poliSearchInput" placeholder="Cari poliklinik / pelayanan...">
                </div>
                <div class="poli-list" id="poliList">
                    <?php foreach ($poliList as $poli): ?>
                        <div class="poli-item" data-poli-id="<?= (int) $poli['id'] ?>" data-nama="<?= e($poli['nama']) ?>">
                            <div class="poli-radio"><span></span></div>
                            <div class="poli-name"><?= e($poli['nama']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="poli-empty" id="poliEmpty">Poliklinik tidak ditemukan.</div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn-batal" id="rujukBatalBtn">Batal</button>
                <button class="modal-btn-submit" id="rujukSubmitBtn">Pilih Poliklinik</button>
            </div>
        </div>
    </div>

    <script>
        // Endpoint AJAX = file ini sendiri, kunjungan_id disisipkan otomatis.
        const KUNJUNGAN_ID = <?= (int) $kunjunganId ?>;
        const ENDPOINT = 'pemeriksaan-dokter.php';

        function loadNavbar() {
            fetch('../navbar.html')
                .then(r => r.ok ? r.text() : Promise.reject())
                .then(html => { document.getElementById('navbar-container').innerHTML = html; })
                .catch(() => {
                    document.getElementById('navbar-container').innerHTML =
                        '<div class="sidebar-loading">Gagal memuat navbar.</div>';
                });
        }

        // ---- Tab switching ----
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
                document.querySelectorAll('.tab-panel').forEach(el => el.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById(btn.dataset.tab).classList.add('active');
            });
        });

        // ---- Disposisi ----
        const rujukInternalNote = document.getElementById('rujukInternalNote');
        const rujukInternalTarget = document.getElementById('rujukInternalTarget');
        const chipRujukInternal = document.getElementById('chipRujukInternal');

        function selectDisposisiChip(chip) {
            document.querySelectorAll('.disposisi-chip').forEach(el => el.classList.remove('selected'));
            chip.classList.add('selected');
        }

        document.querySelectorAll('.disposisi-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                const jenis = chip.dataset.disposisi;
                if (jenis === 'rujuk-internal') { openRujukModal(); return; }
                if (jenis === 'rujuk-eksternal') return; // link biasa
                selectDisposisiChip(chip);
                rujukInternalNote.style.display = 'none';

                fetch(ENDPOINT + '?action=set_disposisi', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `kunjungan_id=${KUNJUNGAN_ID}&disposisi=${encodeURIComponent(jenis)}`
                });
            });
        });

        // ---- Modal Tambah Obat ----
        const modalOverlay = document.getElementById('modalTambahObat');
        const btnTambahObat = document.getElementById('btnTambahObat');
        const modalNamaObat = document.getElementById('modalNamaObat');
        const modalDosis = document.getElementById('modalDosis');
        const modalFrekuensi = document.getElementById('modalFrekuensi');
        const modalDurasi = document.getElementById('modalDurasi');
        const modalTotal = document.getElementById('modalTotal');
        const modalInstruksi = document.getElementById('modalInstruksi');
        const obatTableBody = document.getElementById('obatTableBody');

        function openModal() { modalOverlay.classList.add('active'); document.body.classList.add('modal-open'); }
        function closeModal() { modalOverlay.classList.remove('active'); document.body.classList.remove('modal-open'); }

        function resetModalForm() {
            modalNamaObat.value = '';
            modalDosis.value = '';
            modalFrekuensi.value = '3|Pagi, Siang, Malam';
            modalDurasi.value = 3;
            modalInstruksi.value = '';
            hitungTotalKuantitas();
        }

        function hitungTotalKuantitas() {
            const freqPerHari = parseInt(modalFrekuensi.value.split('|')[0], 10) || 0;
            const durasi = parseInt(modalDurasi.value, 10) || 0;
            modalTotal.value = freqPerHari * durasi;
        }

        btnTambahObat.addEventListener('click', () => { resetModalForm(); openModal(); });
        document.getElementById('modalCloseBtn').addEventListener('click', closeModal);
        document.getElementById('modalBatalBtn').addEventListener('click', closeModal);
        modalOverlay.addEventListener('click', e => { if (e.target === modalOverlay) closeModal(); });
        modalFrekuensi.addEventListener('change', hitungTotalKuantitas);
        modalDurasi.addEventListener('input', hitungTotalKuantitas);

        function bindDeleteButton(btn) {
            btn.addEventListener('click', () => {
                const row = btn.closest('tr');
                const obatId = row.dataset.obatId;
                fetch(ENDPOINT + '?action=hapus_obat', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `kunjungan_id=${KUNJUNGAN_ID}&obat_id=${obatId}`
                }).then(() => row.remove());
            });
        }
        document.querySelectorAll('#obatTableBody .del-btn').forEach(bindDeleteButton);

        document.getElementById('modalSubmitBtn').addEventListener('click', () => {
            const namaObat = modalNamaObat.value.trim();
            if (!namaObat) { modalNamaObat.focus(); return; }

            const body = new URLSearchParams({
                kunjungan_id: KUNJUNGAN_ID,
                nama_obat: namaObat,
                dosis: modalDosis.value.trim() || '-',
                frekuensi: modalFrekuensi.value,
                durasi: modalDurasi.value,
                total: modalTotal.value,
                instruksi: modalInstruksi.value.trim()
            });

            fetch(ENDPOINT + '?action=tambah_obat', { method: 'POST', body })
                .then(r => r.json())
                .then(res => {
                    if (!res.success) { alert(res.message || 'Gagal menambah obat.'); return; }
                    const o = res.obat;
                    const tr = document.createElement('tr');
                    tr.dataset.obatId = o.id;
                    tr.innerHTML =
                        `<td><div class="obat-name">${o.nama_obat}</div></td>
                         <td>${o.dosis}</td>
                         <td>${o.frekuensi}</td>
                         <td>${o.durasi}</td>
                         <td>${o.jumlah}</td>
                         <td><button class="del-btn" aria-label="Hapus obat">Hapus</button></td>`;
                    obatTableBody.appendChild(tr);
                    bindDeleteButton(tr.querySelector('.del-btn'));
                    closeModal();
                });
        });

        // ---- Diagnosis ICD-10 (search + tambah + hapus) ----
        const icd10SearchInput = document.getElementById('icd10SearchInput');
        const diagnosisTagsRow = document.getElementById('diagnosisTagsRow');

        function bindHapusDiagnosis(tag) {
            tag.querySelector('.btn-hapus-diagnosis').addEventListener('click', () => {
                const icdId = tag.dataset.icdId;
                fetch(ENDPOINT + '?action=hapus_diagnosis', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `kunjungan_id=${KUNJUNGAN_ID}&icd_id=${icdId}`
                }).then(() => tag.remove());
            });
        }
        document.querySelectorAll('.diagnosis-tag').forEach(bindHapusDiagnosis);

        let icdDebounce;
        icd10SearchInput.addEventListener('input', () => {
            clearTimeout(icdDebounce);
            const q = icd10SearchInput.value.trim();
            if (q.length < 2) return;
            icdDebounce = setTimeout(() => {
                fetch(ENDPOINT + '?action=cari_icd10&q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(res => {
                        // Implementasikan dropdown hasil pencarian sesuai kebutuhan UI Anda.
                        // res.data = [{id, kode, nama}, ...]
                        console.log(res.data);
                    });
            }, 300);
        });

        function tambahDiagnosis(icdId) {
            fetch(ENDPOINT + '?action=tambah_diagnosis', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `kunjungan_id=${KUNJUNGAN_ID}&icd_id=${icdId}`
            }).then(r => r.json()).then(res => {
                if (!res.success) return;
                const tag = document.createElement('span');
                tag.className = 'diagnosis-tag';
                tag.dataset.icdId = icdId;
                tag.innerHTML = `${res.diagnosis.kode} - ${res.diagnosis.nama} <button class="btn-hapus-diagnosis">×</button>`;
                diagnosisTagsRow.appendChild(tag);
                bindHapusDiagnosis(tag);
            });
        }

        // ---- Modal Rujuk Internal ----
        const modalRujukOverlay = document.getElementById('modalRujukInternal');
        const poliListEl = document.getElementById('poliList');
        const poliSearchInput = document.getElementById('poliSearchInput');
        const poliEmptyEl = document.getElementById('poliEmpty');
        let poliTerpilihId = null;

        document.querySelectorAll('.poli-item').forEach(item => {
            item.addEventListener('click', () => {
                poliTerpilihId = item.dataset.poliId;
                document.querySelectorAll('.poli-item').forEach(el => el.classList.remove('selected'));
                item.classList.add('selected');
            });
        });

        function filterPoliList() {
            const keyword = poliSearchInput.value.trim().toLowerCase();
            let totalTampil = 0;
            document.querySelectorAll('.poli-item').forEach(el => {
                const match = el.dataset.nama.toLowerCase().includes(keyword);
                el.classList.toggle('hidden', !match);
                if (match) totalTampil++;
            });
            poliEmptyEl.style.display = totalTampil === 0 ? 'block' : 'none';
        }

        function openRujukModal() {
            poliSearchInput.value = '';
            filterPoliList();
            modalRujukOverlay.classList.add('active');
            document.body.classList.add('modal-open');
        }
        function closeRujukModal() {
            modalRujukOverlay.classList.remove('active');
            document.body.classList.remove('modal-open');
        }

        document.getElementById('rujukCloseBtn').addEventListener('click', closeRujukModal);
        document.getElementById('rujukBatalBtn').addEventListener('click', closeRujukModal);
        modalRujukOverlay.addEventListener('click', e => { if (e.target === modalRujukOverlay) closeRujukModal(); });
        poliSearchInput.addEventListener('input', filterPoliList);

        document.getElementById('rujukSubmitBtn').addEventListener('click', () => {
            if (!poliTerpilihId) { poliListEl.scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }

            fetch(ENDPOINT + '?action=pilih_poli', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `kunjungan_id=${KUNJUNGAN_ID}&poli_id=${poliTerpilihId}`
            }).then(r => r.json()).then(res => {
                if (!res.success) return;
                selectDisposisiChip(chipRujukInternal);
                rujukInternalTarget.textContent = res.poli.nama;
                rujukInternalNote.style.display = 'flex';
                closeRujukModal();
            });
        });

        // ---- Simpan & Selesai Pemeriksaan ----
        document.getElementById('btnSimpanPemeriksaan').addEventListener('click', () => {
            const anamnesis = document.getElementById('inputAnamnesis').value.trim();
            if (!anamnesis) { alert('Anamnesis wajib diisi.'); return; }

            fetch(ENDPOINT + '?action=simpan_pemeriksaan', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `kunjungan_id=${KUNJUNGAN_ID}&anamnesis=${encodeURIComponent(anamnesis)}`
            }).then(r => r.json()).then(res => {
                if (!res.success) { alert(res.message || 'Gagal menyimpan pemeriksaan.'); return; }
                window.location.href = res.redirect || 'antrian-pemeriksaan.php';
            });
        });

        document.addEventListener('DOMContentLoaded', () => {
            loadNavbar();
        });
    </script>

</body>

</html>