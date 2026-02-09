<?php
$host = "localhost";
$user = "root"; // ตามมาตรฐาน XAMPP
$pass = "";     // ตามมาตรฐาน XAMPP (ถ้าตั้งไว้ให้ใส่ด้วย)
$dbname = "tax_db";

$conn = mysqli_connect($host, $user, $pass, $dbname);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>