<?php

include "../config/koneksi.php";


// cari antrean paling awal yang masih menunggu

$query = mysqli_query($conn,

"
SELECT id_antrean,id_pendaftaran

FROM antrean

WHERE status_antrean='Menunggu'

ORDER BY id_antrean ASC

LIMIT 1

"

);



$data=mysqli_fetch_assoc($query);



if(!$data){


echo json_encode([

"status"=>"error",

"message"=>"Tidak ada antrean"

]);


exit;


}



$id_antrean=$data['id_antrean'];

$id_pendaftaran=$data['id_pendaftaran'];




// ubah status jadi selesai

mysqli_query($conn,


"
UPDATE antrean

SET 
status_antrean='Dipanggil',
waktu_dipanggil=NOW()

WHERE id_antrean='$id_antrean'

"

);





echo json_encode([

"status"=>"success",

"id_pendaftaran"=>$id_pendaftaran,

"message"=>"Pasien masuk asesmen"

]);


?>