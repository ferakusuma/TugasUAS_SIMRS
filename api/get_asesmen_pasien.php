<?php

include "../config/koneksi.php";


$id=$_GET['id_pendaftaran'];


$query=mysqli_query($conn,

"

SELECT *

FROM asesmen

WHERE id_pendaftaran='$id'


"


);


$data=mysqli_fetch_assoc($query);


if(!$data){
echo json_encode([
"status"=>"empty"
]);

exit;

}
echo json_encode($data);

?>