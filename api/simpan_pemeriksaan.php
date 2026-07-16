<?php

header("Content-Type: application/json; charset=utf-8");

include "../config/koneksi.php";

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

mysqli_set_charset($conn, "utf8mb4");

try {

    $json = file_get_contents("php://input");

    $data = json_decode(
        $json,
        true
    );

    if (!is_array($data)) {
        throw new Exception(
            "Format data pemeriksaan tidak valid."
        );
    }

    $idPendaftaran = filter_var(
        $data["id_pendaftaran"] ?? null,
        FILTER_VALIDATE_INT
    );

    $namaDokter = trim(
        $data["nama_dokter"] ?? ""
    );

    $anamnesis = trim(
        $data["anamnesis"] ?? ""
    );

    $diagnosisInput =
        $data["diagnosis"] ?? [];

    $resepInput =
        $data["resep"] ?? [];

    $penunjangInput =
        $data["penunjang"] ?? [];

    $disposisiInput =
        $data["disposisi"] ?? [];

    if (!$idPendaftaran) {
        throw new Exception(
            "ID pendaftaran tidak valid."
        );
    }

    if ($namaDokter === "") {
        throw new Exception(
            "Nama dokter tidak tersedia."
        );
    }

    if ($anamnesis === "") {
        throw new Exception(
            "Anamnesis wajib diisi."
        );
    }

    if (
        !is_array($diagnosisInput) ||
        count($diagnosisInput) === 0
    ) {
        throw new Exception(
            "Minimal satu diagnosis harus diisi."
        );
    }

    $daftarKode = [];
    $daftarDiagnosis = [];

    foreach ($diagnosisInput as $item) {

        $kode = strtoupper(
            trim($item["kode"] ?? "")
        );

        $nama = trim(
            $item["nama"] ?? ""
        );

        if ($kode === "" || $nama === "") {
            continue;
        }

        $daftarKode[] = $kode;
        $daftarDiagnosis[] = $nama;
    }

    if (count($daftarDiagnosis) === 0) {
        throw new Exception(
            "Diagnosis tidak valid."
        );
    }

    $kodeIcd10 = implode(
        ", ",
        $daftarKode
    );

    $diagnosis = implode(
        "; ",
        $daftarDiagnosis
    );

    $keputusan = trim(
        $disposisiInput["keputusan"] ?? ""
    );

    $tujuan = trim(
        $disposisiInput["tujuan"] ?? ""
    );

    $keterangan = trim(
        $disposisiInput["keterangan"] ?? ""
    );

    $keputusanDiizinkan = [
        "Rawat Jalan",
        "Rawat Inap",
        "Rujuk Internal",
        "Rujuk Eksternal"
    ];

    if (
        !in_array(
            $keputusan,
            $keputusanDiizinkan,
            true
        )
    ) {
        throw new Exception(
            "Keputusan dokter tidak valid."
        );
    }

    if (
        $keputusan === "Rujuk Internal" &&
        $tujuan === ""
    ) {
        throw new Exception(
            "Tujuan rujuk internal wajib dipilih."
        );
    }

    $jenisPenunjangDiizinkan = [
        "Laboratorium",
        "Radiologi",
        "Elektro Diagnostik"
    ];

    mysqli_begin_transaction($conn);


    // ======================================
    // CEK PENDAFTARAN
    // ======================================

    $cekPendaftaran = mysqli_prepare(
        $conn,
        "SELECT id_pendaftaran
         FROM pendaftaran
         WHERE id_pendaftaran = ?"
    );

    mysqli_stmt_bind_param(
        $cekPendaftaran,
        "i",
        $idPendaftaran
    );

    mysqli_stmt_execute(
        $cekPendaftaran
    );

    mysqli_stmt_store_result(
        $cekPendaftaran
    );

    if (
        mysqli_stmt_num_rows(
            $cekPendaftaran
        ) === 0
    ) {
        throw new Exception(
            "Data pendaftaran pasien tidak ditemukan."
        );
    }


    // ======================================
    // CEK APAKAH SUDAH DIPERIKSA
    // ======================================

    $cekPemeriksaan = mysqli_prepare(
        $conn,
        "SELECT id_pemeriksaan
         FROM pemeriksaan_dokter
         WHERE id_pendaftaran = ?"
    );

    mysqli_stmt_bind_param(
        $cekPemeriksaan,
        "i",
        $idPendaftaran
    );

    mysqli_stmt_execute(
        $cekPemeriksaan
    );

    mysqli_stmt_store_result(
        $cekPemeriksaan
    );

    if (
        mysqli_stmt_num_rows(
            $cekPemeriksaan
        ) > 0
    ) {
        throw new Exception(
            "Pemeriksaan pasien ini sudah pernah disimpan."
        );
    }


    // ======================================
    // SIMPAN PEMERIKSAAN DOKTER
    // ======================================

    $simpanPemeriksaan = mysqli_prepare(
        $conn,
        "INSERT INTO pemeriksaan_dokter
        (
            id_pendaftaran,
            nama_dokter,
            anamnesis,
            diagnosis,
            kode_icd10
        )
        VALUES (?, ?, ?, ?, ?)"
    );

    mysqli_stmt_bind_param(
        $simpanPemeriksaan,
        "issss",
        $idPendaftaran,
        $namaDokter,
        $anamnesis,
        $diagnosis,
        $kodeIcd10
    );

    mysqli_stmt_execute(
        $simpanPemeriksaan
    );

    $idPemeriksaan =
        mysqli_insert_id($conn);


    // ======================================
    // SIMPAN RESEP
    // ======================================

    if (is_array($resepInput)) {

        $simpanResep = mysqli_prepare(
            $conn,
            "INSERT INTO resep
            (
                id_pemeriksaan,
                nama_obat,
                dosis,
                frekuensi,
                lama_pemakaian,
                jumlah,
                instruksi
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($resepInput as $obat) {

            $namaObat = trim(
                $obat["nama_obat"] ?? ""
            );

            $dosis = trim(
                $obat["dosis"] ?? ""
            );

            $frekuensi = trim(
                $obat["frekuensi"] ?? ""
            );

            $lamaPemakaian = trim(
                $obat["lama_pemakaian"] ?? ""
            );

            $jumlah = trim(
                $obat["jumlah"] ?? ""
            );

            $instruksi = trim(
                $obat["instruksi"] ?? ""
            );

            if ($namaObat === "") {
                continue;
            }

            mysqli_stmt_bind_param(
                $simpanResep,
                "issssss",
                $idPemeriksaan,
                $namaObat,
                $dosis,
                $frekuensi,
                $lamaPemakaian,
                $jumlah,
                $instruksi
            );

            mysqli_stmt_execute(
                $simpanResep
            );
        }
    }


    // ======================================
    // SIMPAN PEMERIKSAAN PENUNJANG
    // ======================================

    if (is_array($penunjangInput)) {

        $simpanPenunjang = mysqli_prepare(
            $conn,
            "INSERT INTO pemeriksaan_penunjang
            (
                id_pemeriksaan,
                jenis_penunjang,
                status
            )
            VALUES (?, ?, 'Diminta')"
        );

        foreach ($penunjangInput as $jenisPenunjang) {

            $jenisPenunjang = trim(
                $jenisPenunjang
            );

            if (
                !in_array(
                    $jenisPenunjang,
                    $jenisPenunjangDiizinkan,
                    true
                )
            ) {
                continue;
            }

            mysqli_stmt_bind_param(
                $simpanPenunjang,
                "is",
                $idPemeriksaan,
                $jenisPenunjang
            );

            mysqli_stmt_execute(
                $simpanPenunjang
            );
        }
    }


    // ======================================
    // SIMPAN KEPUTUSAN DOKTER
    // ======================================

    $simpanKeputusan = mysqli_prepare(
        $conn,
        "INSERT INTO keputusan_dokter
        (
            id_pemeriksaan,
            keputusan,
            tujuan,
            keterangan
        )
        VALUES (?, ?, ?, ?)"
    );

    mysqli_stmt_bind_param(
        $simpanKeputusan,
        "isss",
        $idPemeriksaan,
        $keputusan,
        $tujuan,
        $keterangan
    );

    mysqli_stmt_execute(
        $simpanKeputusan
    );


    mysqli_commit($conn);

    echo json_encode([
        "status" => "success",
        "message" =>
            "Pemeriksaan berhasil disimpan.",
        "id_pemeriksaan" =>
            $idPemeriksaan
    ]);

} catch (Throwable $error) {

    if (isset($conn)) {
        mysqli_rollback($conn);
    }

    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => $error->getMessage()
    ]);
}