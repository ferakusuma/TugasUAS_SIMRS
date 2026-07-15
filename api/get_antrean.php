<?php

include "../config/koneksi.php";


$query = mysqli_query($conn, "

SELECT 

antrean.id_antrean,
antrean.nomor_antrean,
antrean.status_antrean,

pasien.nama_pasien,
pasien.no_rm

FROM antrean

JOIN pendaftaran

ON antrean.id_pendaftaran = pendaftaran.id_pendaftaran


JOIN pasien

ON pendaftaran.id_pasien = pasien.id_pasien


WHERE antrean.status_antrean='Menunggu'

ORDER BY antrean.id_antrean ASC


");



$data = [];


while($row = mysqli_fetch_assoc($query)){

    $data[] = $row;

}



echo json_encode($data);


?>