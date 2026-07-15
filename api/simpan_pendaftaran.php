<?php

include "../config/koneksi.php";


$nik = $_POST['nik'];
$nama = $_POST['nama_pasien'];
$tgl = $_POST['tanggal_lahir'];
$jk = $_POST['jenis_kelamin'];
$alamat = $_POST['alamat'];
$hp = $_POST['no_hp'];


// generate RM

$no_rm = "RM".date("YmdHis");


// simpan pasien

$query1 = mysqli_query($conn,

"INSERT INTO pasien

(
no_rm,
nik,
nama_pasien,
tanggal_lahir,
jenis_kelamin,
alamat,
no_hp
)

VALUES

(
'$no_rm',
'$nik',
'$nama',
'$tgl',
'$jk',
'$alamat',
'$hp'
)

"

);



$id_pasien = mysqli_insert_id($conn);



// simpan pendaftaran


$query2 = mysqli_query($conn,


"INSERT INTO pendaftaran

(
id_pasien,
tanggal_daftar,
jenis_kunjungan,
poli_tujuan
)

VALUES

(
'$id_pasien',
CURDATE(),
'Baru',
'Poli Jiwa'
)

"


);



$id_pendaftaran = mysqli_insert_id($conn);



// buat nomor antrean


$nomor = "A-".str_pad(
$id_pendaftaran,
3,
"0",
STR_PAD_LEFT
);



mysqli_query($conn,


"INSERT INTO antrean

(
id_pendaftaran,
nomor_antrean
)

VALUES

(
'$id_pendaftaran',
'$nomor'
)

"


);



echo json_encode([

"status"=>"success",

"message"=>"Pasien berhasil didaftarkan",

"nomor_antrean"=>$nomor

]);


?>