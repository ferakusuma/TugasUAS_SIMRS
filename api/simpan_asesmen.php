<?php

include "../config/koneksi.php";


$id_pendaftaran = $_POST['id_pendaftaran'];
$tekanan_darah = $_POST['tekanan_darah'];
$suhu = $_POST['suhu'];
$nadi = $_POST['nadi'];
$pernapasan = $_POST['pernapasan'];
$keluhan = $_POST['keluhan_awal'];

$riwayat = $_POST['riwayat_penyakit'];

$alergi = $_POST['alergi'];

$nama_perawat = $_POST['nama_perawat'];



$query = mysqli_query($conn,


"
INSERT INTO asesmen

(
id_pendaftaran,
tekanan_darah,
suhu,
nadi,
pernapasan,
keluhan_awal,
riwayat_penyakit,
alergi,
nama_perawat
)


VALUES

(
'$id_pendaftaran',
'$tekanan_darah',
'$suhu',
'$nadi',
'$pernapasan',
'$keluhan',
'$riwayat',
'$alergi',
'$nama_perawat'
)

"

);



if($query){


mysqli_query($conn,

"
UPDATE pendaftaran
SET status_proses='Pemeriksaan'
WHERE id_pendaftaran='$id_pendaftaran'
"
);



mysqli_query($conn,

"
UPDATE antrean

SET status_antrean='Pemeriksaan'

WHERE id_pendaftaran='$id_pendaftaran'

"

);


echo json_encode([

"status"=>"success"

]);


}

else{


echo json_encode([

"status"=>"error"

]);


}


?>