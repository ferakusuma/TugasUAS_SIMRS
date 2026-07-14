<?php
/**
 * Database connection & Auto-initialization for SIMRS Amino
 * Cocok dijalankan di Laragon (Host: localhost, User: root, Pass: empty)
 */

$host = "127.0.0.1";
$username = "root";
$password = "";
$dbname = "db_simrs";

try {
    // 1. Koneksi awal ke MySQL tanpa nama database untuk membuat DB jika belum ada
    $pdo = new PDO("mysql:host=$host", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Buat database jika belum ada
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // 2. Koneksi ke database yang dituju
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // 3. Buat Tabel-tabel yang diperlukan jika belum ada

    // Tabel: pasien
    $pdo->exec("CREATE TABLE IF NOT EXISTS `pasien` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `no_rm` VARCHAR(20) UNIQUE NOT NULL,
        `nama` VARCHAR(100) NOT NULL,
        `tipe_penjamin` VARCHAR(50) NOT NULL,
        `tgl_lahir` DATE NOT NULL,
        `gender` VARCHAR(20) NOT NULL,
        `foto` VARCHAR(255) DEFAULT NULL
    ) ENGINE=InnoDB;");

    // Tabel: asesmen_perawat
    $pdo->exec("CREATE TABLE IF NOT EXISTS `asesmen_perawat` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `pasien_id` INT NOT NULL,
        `keluhan_utama` TEXT NOT NULL,
        `tensi_darah` VARCHAR(20) NOT NULL,
        `suhu` DECIMAL(4,1) NOT NULL,
        `nadi` INT NOT NULL,
        `rr` INT NOT NULL,
        `spo2` INT NOT NULL,
        `bb` INT NOT NULL,
        `tb` INT NOT NULL,
        `alergi` TEXT NOT NULL,
        FOREIGN KEY (`pasien_id`) REFERENCES `pasien` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB;");

    // Tabel: riwayat_kunjungan
    $pdo->exec("CREATE TABLE IF NOT EXISTS `riwayat_kunjungan` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `pasien_id` INT NOT NULL,
        `tanggal` DATE NOT NULL,
        `klinik` VARCHAR(100) NOT NULL,
        `diagnosis` VARCHAR(100) NOT NULL,
        `terapi` TEXT NOT NULL,
        FOREIGN KEY (`pasien_id`) REFERENCES `pasien` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB;");

    // Tabel: pemeriksaan_dokter
    $pdo->exec("CREATE TABLE IF NOT EXISTS `pemeriksaan_dokter` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `pasien_id` INT NOT NULL,
        `anamnesis` TEXT NOT NULL,
        `diagnosis` TEXT NOT NULL, -- Menyimpan list diagnosis sebagai JSON/Text
        `penunjang_lab` TINYINT(1) DEFAULT 0,
        `penunjang_rad` TINYINT(1) DEFAULT 0,
        `penunjang_ok` TINYINT(1) DEFAULT 0, -- Elektro Diagnostik
        `disposisi` VARCHAR(50) NOT NULL,
        `rujuk_internal_target` VARCHAR(150) DEFAULT NULL,
        `tanggal_pemeriksaan` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`pasien_id`) REFERENCES `pasien` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB;");

    // Tabel: resep_obat
    $pdo->exec("CREATE TABLE IF NOT EXISTS `resep_obat` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `pemeriksaan_id` INT NOT NULL,
        `nama_obat` VARCHAR(150) NOT NULL,
        `dosis` VARCHAR(50) NOT NULL,
        `frekuensi` VARCHAR(100) NOT NULL,
        `durasi` VARCHAR(50) NOT NULL,
        `jumlah` VARCHAR(50) NOT NULL,
        `instruksi` TEXT DEFAULT NULL,
        FOREIGN KEY (`pemeriksaan_id`) REFERENCES `pemeriksaan_dokter` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB;");

    // 4. Seed sample data pasien (Budi Santoso) jika database kosong
    $stmt = $pdo->query("SELECT COUNT(*) FROM `pasien`");
    if ($stmt->fetchColumn() == 0) {
        // Insert data pasien
        $stmtPasien = $pdo->prepare("INSERT INTO `pasien` (`no_rm`, `nama`, `tipe_penjamin`, `tgl_lahir`, `gender`, `foto`) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtPasien->execute([
            '102-44-89',
            'Budi Santoso',
            'BPJS Kesehatan',
            '1979-05-12',
            'Laki-laki',
            'https://images.unsplash.com/photo-1633332755192-727a05c4013d?w=200&h=200&fit=crop&crop=faces'
        ]);
        $pasienId = $pdo->lastInsertId();

        // Insert asesmen awal dari perawat
        $stmtAsesmen = $pdo->prepare("INSERT INTO `asesmen_perawat` (`pasien_id`, `keluhan_utama`, `tensi_darah`, `suhu`, `nadi`, `rr`, `spo2`, `bb`, `tb`, `alergi`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtAsesmen->execute([
            $pasienId,
            'Pasien datang dengan keluhan sering mendengar suara-suara bisikan dan merasa ketakutan tanpa alasan jelas sejak 3 hari terakhir.',
            '120/80',
            36.8,
            88,
            18,
            99,
            70,
            170,
            'Amoxicillin, Seafood, Tidak Ada Alergi Dingin'
        ]);

        // Insert riwayat kunjungan medis sebelumnya
        $stmtKunjungan = $pdo->prepare("INSERT INTO `riwayat_kunjungan` (`pasien_id`, `tanggal`, `klinik`, `diagnosis`, `terapi`) VALUES (?, ?, ?, ?, ?)");
        $stmtKunjungan->execute([
            $pasienId,
            '2024-03-20',
            'Poliklinik Psikiatri Dewasa',
            'F20.0 (Paranoid Schizophrenia)',
            'Th/: Risperidone 2mg, Clozapine 25mg.'
        ]);
        $stmtKunjungan->execute([
            $pasienId,
            '2024-01-12',
            'Instalasi Gawat Darurat (IGD)',
            'Gelisah / Gaduh Gelisah',
            'Keluarga melaporkan pasien gaduh gelisah di rumah.'
        ]);
        $stmtKunjungan->execute([
            $pasienId,
            '2023-11-05',
            'Poliklinik Penyakit Dalam',
            'Gastritis',
            'Keluhan: Lambung terasa perih (Gastritis).'
        ]);
    }

} catch (PDOException $e) {
    die("Koneksi atau inisialisasi database MySQL gagal: " . $e->getMessage());
}
?>