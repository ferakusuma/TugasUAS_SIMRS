<?php

include "../config/koneksi.php";


$query=mysqli_query($conn,


"
SELECT

pendaftaran.id_pendaftaran,

pasien.nama_pasien,

pasien.no_rm,

pasien.tanggal_lahir,

pasien.jenis_kelamin


FROM antrean


JOIN pendaftaran

ON antrean.id_pendaftaran =
pendaftaran.id_pendaftaran



JOIN pasien

ON pendaftaran.id_pasien =
pasien.id_pasien



WHERE antrean.status_antrean='Selesai'


ORDER BY antrean.id_antrean ASC
LIMIT 1

"

);



$data=[];


while($row=mysqli_fetch_assoc($query)){


$data[]=$row;


}


echo json_encode($data);


?>