<?php

include "../config/koneksi.php";


$query=mysqli_query($conn,


"
SELECT id_antrean

FROM antrean

WHERE status_antrean='Menunggu'

ORDER BY id_antrean ASC

LIMIT 1

"

);


$data=mysqli_fetch_assoc($query);



if($data){


$id=$data['id_antrean'];



mysqli_query($conn,


"
UPDATE antrean

SET status_antrean='Selesai'

WHERE id_antrean='$id'

"

);


}



echo json_encode([

"status"=>"success"

]);


?>