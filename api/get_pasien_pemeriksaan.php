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


JOIN pasien

ON pasien.id_pasien=
pendaftaran.id_pasien


WHERE pendaftaran.status_proses='Pemeriksaan'


LIMIT 1


"

);



$data=[];


while($row=mysqli_fetch_assoc($query)){


$data[]=$row;


}


echo json_encode($data);


?>