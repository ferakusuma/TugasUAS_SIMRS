<?php

include "../config/koneksi.php";


$id = $_GET['id_pendaftaran'];



$query = mysqli_query($conn,

"

SELECT

pendaftaran.id_pendaftaran,

pasien.nama_pasien,
pasien.no_rm,
pasien.tanggal_lahir,
pasien.jenis_kelamin,


pemeriksaan_dokter.anamnesis,
pemeriksaan_dokter.diagnosis,
pemeriksaan_dokter.kode_icd10


FROM pendaftaran


JOIN pasien

ON pasien.id_pasien = pendaftaran.id_pasien



LEFT JOIN pemeriksaan_dokter

ON pemeriksaan_dokter.id_pendaftaran =
pendaftaran.id_pendaftaran



WHERE pendaftaran.id_pendaftaran='$id'


"

);



$data=mysqli_fetch_assoc($query);



echo json_encode($data);


?>