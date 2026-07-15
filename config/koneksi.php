<?php

$host = "localhost";
$user = "root";
$password = "password";
$database = "simrs_amino";
$port = 3307;


$conn = mysqli_connect(
    $host,
    $user,
    $password,
    $database,
    $port
);


if(!$conn){

    die(
        "Database gagal konek : "
        .mysqli_connect_error()
    );

}


?>