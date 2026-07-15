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


FROM pendaftaran


JOIN antrean

ON antrean.id_pendaftaran =
pendaftaran.id_pendaftaran


WHERE antrean.status_antrean='Pemeriksaan'


LIMIT 1


"

);



$data=[];


while($row=mysqli_fetch_assoc($query)){


$data[]=$row;


}


echo json_encode($data);


?>