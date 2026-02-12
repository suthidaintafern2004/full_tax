<?php
session_start();
require_once 'config.php';

// ตรวจสอบสิทธิ์ Admin
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$sql_role = "SELECT role FROM users WHERE username = ?";
$stmt = mysqli_prepare($conn, $sql_role);
mysqli_stmt_bind_param($stmt, "s", $username);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if ($user['role'] != 3) {
    header("Location: index.php");
    exit();
}

// --- ตั้งค่าไฟล์สำหรับเก็บข้อมูล (ไม่ต้องใช้ Database) ---
$settings_file = 'system_settings.json';

// --- จัดการ AJAX Request (บันทึกอัตโนมัติ) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'update_otp') {
    $otp_status = (isset($_POST['otp_enabled']) && $_POST['otp_enabled'] === 'true') ? '1' : '0';
    
    $data = ['otp_enabled' => $otp_status];
    if (file_put_contents($settings_file, json_encode($data))) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit();
}

// --- ดึงค่าปัจจุบันมาแสดง ---
$current_otp = '1'; // ค่าเริ่มต้น
if (file_exists($settings_file)) {
    $data = json_decode(file_get_contents($settings_file), true);
    if (isset($data['otp_enabled'])) {
        $current_otp = $data['otp_enabled'];
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่าระบบ - ระบบภาษี</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Sarabun', sans-serif; background-color: #f8f9fc; }
        .navbar { background: linear-gradient(135deg, #4e73df 0%, #224abe 100%); }
        .card-settings { border: none; border-radius: 15px; box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1); }
        .form-check-input:checked { background-color: #1cc88a; border-color: #1cc88a; }
        .form-switch .form-check-input { width: 3em; height: 1.5em; cursor: pointer; }
        .status-label { font-weight: 600; margin-left: 10px; vertical-align: middle; }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
    <nav class="navbar navbar-expand-lg navbar-dark mb-5 shadow-sm">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fas fa-file-invoice-dollar me-2"></i>ระบบดาวน์โหลดเอกสารรับรองภาษี</a>
            <div class="ms-auto">
                <a href="admin_menu.php" class="btn btn-outline-light btn-sm px-3 rounded-pill"><i class="fas fa-arrow-left me-1"></i> กลับเมนูหลัก</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                
                <div class="card card-settings mb-4">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-cogs me-2"></i>ตั้งค่าระบบทั่วไป</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h6 class="fw-bold mb-1"><i class="fas fa-shield-alt me-2 text-warning"></i>ระบบยืนยันตัวตน OTP</h6>
                                <p class="text-muted small mb-0">เปิด/ปิด การส่งรหัส OTP ทางอีเมลเมื่อผู้ใช้ลงทะเบียนครั้งแรก</p>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="otpSwitch" <?php echo ($current_otp == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label status-label" for="otpSwitch" id="otpLabel">
                                    <?php echo ($current_otp == '1') ? '<span class="text-success">เปิดใช้งาน</span>' : '<span class="text-secondary">ปิดใช้งาน</span>'; ?>
                                </label>
                            </div>
                        </div>

                        <hr class="my-4">

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h6 class="fw-bold mb-1"><i class="fas fa-user-cog me-2 text-info"></i>บัญชีของคุณ</h6>
                                <p class="text-muted small mb-0">แก้ไขรหัสผ่านและข้อมูลส่วนตัวของผู้ใช้งานปัจจุบัน</p>
                            </div>
                            <a href="profile.php" class="btn btn-outline-primary rounded-pill px-4">
                                <i class="fas fa-edit me-1"></i> จัดการ
                            </a>
                        </div>

                        <div id="saveStatus" class="text-end text-muted small" style="min-height: 24px;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Script เปลี่ยนข้อความและบันทึกอัตโนมัติ (Auto-save)
        document.getElementById('otpSwitch').addEventListener('change', function() {
            const isChecked = this.checked;
            const label = document.getElementById('otpLabel');
            const statusDiv = document.getElementById('saveStatus');
            
            if(isChecked) {
                label.innerHTML = '<span class="text-success">เปิดใช้งาน</span>';
            } else {
                label.innerHTML = '<span class="text-secondary">ปิดใช้งาน</span>';
            }

            statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังบันทึก...';

            const formData = new FormData();
            formData.append('ajax_action', 'update_otp');
            formData.append('otp_enabled', isChecked);

            fetch('admin_system_settings.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if(data.status === 'success') {
                    statusDiv.innerHTML = '<span class="text-success"><i class="fas fa-check"></i> บันทึกเรียบร้อย</span>';
                    setTimeout(() => { statusDiv.innerHTML = ''; }, 2000);
                } else {
                    statusDiv.innerHTML = '<span class="text-danger">บันทึกไม่สำเร็จ</span>';
                }
            })
            .catch(err => {
                console.error(err);
                statusDiv.innerHTML = '<span class="text-danger">เกิดข้อผิดพลาดในการเชื่อมต่อ</span>';
            });
        });
    </script>
</body>
</html>
