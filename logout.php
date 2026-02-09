<?php
session_start();
session_destroy(); // ล้างข้อมูล Session ทั้งหมดเพื่อออกจากระบบ
header("Location: index.php"); // สั่งให้เด้งไปหน้า index.php
exit();
