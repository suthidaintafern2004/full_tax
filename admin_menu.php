<?php
session_start();
require_once 'config.php';

// ตรวจสอบสิทธิ์การเข้าใช้งาน
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// ตรวจสอบว่าเป็น Admin หรือไม่ (Role = 3)
$username = $_SESSION['username'];
$sql_role = "SELECT role FROM users WHERE username = ?";
$stmt = mysqli_prepare($conn, $sql_role);
mysqli_stmt_bind_param($stmt, "s", $username);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);

if ($user['role'] != 3) {
    // หากไม่ใช่ Admin ให้กลับไปหน้า Index ปกติ
    header("Location: index.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เมนูผู้ดูแลระบบ - ระบบภาษี</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/admin_menu.css">
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark shadow-sm">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fas fa-file-invoice-dollar me-2"></i>ระบบดาวน์โหลดเอกสารรับรองภาษี</a>
            <div class="ms-auto">
                <a href="logout.php" class="btn btn-outline-light btn-sm px-3 rounded-pill"><i class="fas fa-sign-out-alt me-1"></i>ออกจากระบบ</a>
            </div>
        </div>
    </nav>

    <div class="main-container">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold text-dark">เมนูจัดการระบบ</h2>
                <p class="text-muted">เลือกรายการที่ต้องการดำเนินการ</p>
            </div>

            <div class="row g-4 justify-content-center">
                <!-- 1. ตั้งค่าระบบ -->
                <div class="col-md-4 col-lg-3">
                    <a href="admin_system_settings.php" class="menu-card card-settings">
                        <div class="icon-wrapper">
                            <i class="fas fa-tools"></i>
                        </div>
                        <h5 class="menu-title">ตั้งค่าระบบ</h5>
                        <p class="menu-desc">เปิด-ปิด OTP และตั้งค่าข้อมูลส่วนตัวของผู้ดูแลระบบ</p>
                    </a>
                </div>

                <!-- 2. จัดการข้อมูลผู้เสียภาษี -->
                <div class="col-md-4 col-lg-3">
                    <a href="admin_manage.php" class="menu-card card-users">
                        <div class="icon-wrapper">
                            <i class="fas fa-users-cog"></i>
                        </div>
                        <h5 class="menu-title">จัดการข้อมูลผู้เสียภาษี</h5>
                        <p class="menu-desc">เพิ่ม ลบ แก้ไข ข้อมูลสมาชิกและตรวจสอบยอดภาษีรายบุคคล</p>
                    </a>
                </div>

                <!-- 3. อัปโหลดโฟลเดอร์เอกสาร -->
                <div class="col-md-4 col-lg-3">
                    <a href="upload_documents.php" class="menu-card card-upload">
                        <div class="icon-wrapper">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </div>
                        <h5 class="menu-title">อัปโหลดเอกสาร</h5>
                        <p class="menu-desc">นำเข้าไฟล์ PDF เอกสารหักภาษี และ เอกสารลดหย่อนภาษีเข้าสู่ระบบ</p>
                    </a>
                </div>
            </div>
            
            <div class="text-center mt-5">
                <a href="index.php" class="btn btn-link text-muted text-decoration-none"><i class="fas fa-arrow-left me-1"></i> กลับหน้าหลัก</a>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
