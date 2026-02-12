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
    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f8f9fc;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .navbar {
            background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
        }
        .main-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }
        .menu-card {
            border: none;
            border-radius: 20px;
            background: white;
            padding: 40px 20px;
            text-align: center;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            height: 100%;
            text-decoration: none;
            color: inherit;
            display: block;
            position: relative;
            overflow: hidden;
        }
        .menu-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(78, 115, 223, 0.2);
            color: inherit;
        }
        .menu-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: #e3e6f0;
            transition: 0.3s;
        }
        .menu-card:hover::before {
            height: 10px;
        }
        /* สีแถบด้านบนของแต่ละการ์ด */
        .card-settings:hover::before { background: #f6c23e; } /* สีเหลือง */
        .card-users:hover::before { background: #4e73df; }    /* สีน้ำเงิน */
        .card-upload:hover::before { background: #1cc88a; }   /* สีเขียว */

        .icon-wrapper {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            font-size: 2.5rem;
            transition: 0.3s;
        }
        .card-settings .icon-wrapper { background: #fff7d6; color: #f6c23e; }
        .card-users .icon-wrapper { background: #e6edfc; color: #4e73df; }
        .card-upload .icon-wrapper { background: #e0f7ef; color: #1cc88a; }

        .menu-title {
            font-weight: 600;
            font-size: 1.25rem;
            margin-bottom: 10px;
            color: #2c3e50;
        }
        .menu-desc {
            font-size: 0.9rem;
            color: #858796;
            line-height: 1.5;
        }
    </style>
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
