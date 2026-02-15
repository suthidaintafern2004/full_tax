<?php
session_start();
require_once 'config.php';
require_once 'send_otp_mail.php';

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$success = "";
$error = "";
$show_otp_modal = false;

// --- ดึงค่าการตั้งค่า OTP ---
$use_otp = true;
$settings_file = 'system_settings.json';
if (file_exists($settings_file)) {
    $data = json_decode(file_get_contents($settings_file), true);
    if (isset($data['otp_enabled'])) {
        $use_otp = ($data['otp_enabled'] == '1');
    }
}

// --- ตรวจสอบ Role เพื่อกำหนดปุ่มย้อนกลับ ---
$back_url = "index.php";
$back_text = "กลับหน้าหลัก";

$sql_r = "SELECT role FROM users WHERE username = ?";
$stmt_r = mysqli_prepare($conn, $sql_r);
mysqli_stmt_bind_param($stmt_r, "s", $username);
mysqli_stmt_execute($stmt_r);
$res_r = mysqli_stmt_get_result($stmt_r);
if ($row_r = mysqli_fetch_assoc($res_r)) {
    if ($row_r['role'] == 3) {
        $back_url = "admin_menu.php";
        $back_text = "เมนูผู้ดูแลระบบ";
    }
}

// --- ส่วนจัดการอัปโหลดรูปภาพ (เพิ่มใหม่) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['profile_image'])) {
    $target_dir = "uploads/profile_images/";
    
    // สร้างโฟลเดอร์ถ้ายังไม่มี
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $file = $_FILES['profile_image'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif'];

    if ($file['error'] === 0) {
        if (in_array($file_ext, $allowed)) {
            if ($file['size'] <= 5 * 1024 * 1024) { // ไม่เกิน 5MB
                $new_filename = $username . '_' . time() . '.' . $file_ext;
                $target_file = $target_dir . $new_filename;

                if (move_uploaded_file($file['tmp_name'], $target_file)) {
                    $sql_img = "UPDATE member SET profile_image = ? WHERE pid = ?";
                    $stmt_img = mysqli_prepare($conn, $sql_img);
                    mysqli_stmt_bind_param($stmt_img, "ss", $new_filename, $username);
                    if (mysqli_stmt_execute($stmt_img)) {
                        $success = "อัปโหลดรูปโปรไฟล์เรียบร้อยแล้ว";
                    } else {
                        $error = "บันทึกข้อมูลลงฐานข้อมูลไม่สำเร็จ";
                    }
                } else {
                    $error = "เกิดข้อผิดพลาดในการย้ายไฟล์";
                }
            } else {
                $error = "ไฟล์มีขนาดใหญ่เกินไป (ต้องไม่เกิน 5MB)";
            }
        } else {
            $error = "อนุญาตเฉพาะไฟล์รูปภาพ (JPG, JPEG, PNG, GIF)";
        }
    }
}

// --- ส่วนจัดการเปลี่ยนอีเมล (เพิ่มใหม่) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // ยกเลิกการเปลี่ยนอีเมล
    if (isset($_POST['cancel_email_change'])) {
        unset($_SESSION['email_change_otp']);
    }

    // ขอรหัส OTP หรือบันทึกทันทีถ้าปิด OTP
    if (isset($_POST['request_email_otp'])) {
        $new_email = trim($_POST['new_email']);
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error = "รูปแบบอีเมลไม่ถูกต้อง";
        } else {
            if ($use_otp) {
                $otp_code = rand(100000, 999999);
                $ref_code = substr(str_shuffle("ABCDEFGHJKLMNPQRSTUVWXYZ"), 0, 4);
                
                if (sendOtpEmail($new_email, $otp_code, 'change_email', $ref_code)) {
                    $_SESSION['email_change_otp'] = [
                        'code' => $otp_code,
                        'email' => $new_email,
                        'ref' => $ref_code,
                        'expiry' => time() + 300,
                        'last_req' => time() // เก็บเวลาที่ขอล่าสุด
                    ];
                    $show_otp_modal = true;
                } else {
                    $error = "ส่งอีเมลไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
                }
            } else {
                // กรณีปิด OTP ไม่อนุญาตให้เปลี่ยนเอง (ป้องกันทาง Backend)
                $error = "ระบบ OTP ปิดการใช้งานอยู่ กรุณาติดต่อแอดมินเพื่อทำการแก้ไขอีเมล";
            }
        }
    }

    // ขอรหัส OTP ใหม่ (Resend)
    if (isset($_POST['resend_email_otp'])) {
        if (isset($_SESSION['email_change_otp'])) {
            $last_req = $_SESSION['email_change_otp']['last_req'] ?? 0;
            // ตรวจสอบ Cooldown 60 วินาที
            if (time() - $last_req < 60) {
                $error = "กรุณารอ " . (60 - (time() - $last_req)) . " วินาทีก่อนขอรหัสใหม่";
            } else {
                $new_email = $_SESSION['email_change_otp']['email'];
                if ($use_otp) {
                    $otp_code = rand(100000, 999999);
                    $ref_code = substr(str_shuffle("ABCDEFGHJKLMNPQRSTUVWXYZ"), 0, 4);
                    
                    if (sendOtpEmail($new_email, $otp_code, 'change_email', $ref_code)) {
                        // อัปเดต Session (ล้างค่าเดิมโดยการทับค่าใหม่)
                        $_SESSION['email_change_otp']['code'] = $otp_code;
                        $_SESSION['email_change_otp']['ref'] = $ref_code;
                        $_SESSION['email_change_otp']['expiry'] = time() + 300;
                        $_SESSION['email_change_otp']['last_req'] = time();
                        
                        $show_otp_modal = true;
                    } else {
                        $error = "ส่งอีเมลไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
                    }
                } else {
                    $error = "ระบบ OTP ปิดการใช้งานอยู่";
                }
            }
        }
    }

    // ยืนยัน OTP
    if (isset($_POST['verify_email_otp'])) {
        $otp_input = isset($_POST['otp_digits']) ? implode('', $_POST['otp_digits']) : '';
        $session_otp = $_SESSION['email_change_otp'] ?? null;

        if (!$session_otp) {
            $error = "Session หมดอายุ กรุณาทำรายการใหม่";
        } elseif (time() > $session_otp['expiry']) {
            $error = "รหัส OTP หมดอายุ";
        } elseif ($otp_input != $session_otp['code']) {
            $error = "รหัส OTP ไม่ถูกต้อง";
            $show_otp_modal = true;
        } else {
            $new_email = $session_otp['email'];
            $stmt = mysqli_prepare($conn, "UPDATE member SET email = ? WHERE pid = ?");
            mysqli_stmt_bind_param($stmt, "ss", $new_email, $username);
            if (mysqli_stmt_execute($stmt)) {
                $success = "ยืนยันและเปลี่ยนอีเมลเรียบร้อยแล้ว";
                unset($_SESSION['email_change_otp']);
            } else {
                $error = "เกิดข้อผิดพลาดในการบันทึก";
            }
        }
    }
}

// ตรวจสอบ OTP หมดอายุ (เพื่อให้แสดงฟอร์มกรอกอีเมลใหม่เมื่อเข้ามาดู)
if (isset($_SESSION['email_change_otp']) && time() > $_SESSION['email_change_otp']['expiry'] && !isset($_POST['verify_email_otp'])) {
    unset($_SESSION['email_change_otp']);
}

// 2. ตรรกะการเปลี่ยนรหัสผ่าน
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $old_password = $_POST['old_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // ตรวจสอบรหัสผ่านเดิมจากฐานข้อมูล
    $sql_check = "SELECT password, last_password_change FROM users WHERE username = ?";
    $stmt_check = mysqli_prepare($conn, $sql_check);
    mysqli_stmt_bind_param($stmt_check, "s", $username);
    mysqli_stmt_execute($stmt_check);
    $res_check = mysqli_stmt_get_result($stmt_check);
    $row_check = mysqli_fetch_assoc($res_check);

    // ตรวจสอบเงื่อนไขระยะเวลา 7 วัน
    $allow_change = true;
    if (!empty($row_check['last_password_change'])) {
        $last_change = strtotime($row_check['last_password_change']);
        $diff = time() - $last_change;
        $limit_seconds = 7 * 24 * 60 * 60; // 7 วัน
        
        if ($diff < $limit_seconds) {
            $days_left = ceil(($limit_seconds - $diff) / (24 * 60 * 60));
            $error = "คุณเพิ่งเปลี่ยนรหัสผ่านไปเมื่อเร็วๆ นี้ กรุณารออีก $days_left วัน จึงจะสามารถเปลี่ยนได้อีกครั้ง";
            $allow_change = false;
        }
    }

    // เงื่อนไข: 8 ตัวขึ้นไป, เป็นตัวอักษรและตัวเลขเท่านั้น (ไม่มีตัวอักษรพิเศษ)
    if ($allow_change && $row_check['password'] !== $old_password) {
        $error = "รหัสผ่านเดิมไม่ถูกต้อง !";
    } elseif ($allow_change && $new_password !== $confirm_password) {
        $error = "รหัสผ่านใหม่และยืนยันรหัสผ่านไม่ตรงกัน";
    } elseif ($allow_change && !preg_match('/^(?=.*[a-zA-Z])(?=.*\d)[a-zA-Z\d]{8,}$/', $new_password)) {
        $error = "รหัสผ่านต้องประกอบด้วยตัวอักษรและตัวเลข รวมกัน 8 ตัวขึ้นไป (ห้ามใช้ตัวอักษรพิเศษ)";
    } elseif ($allow_change) {
        // อัปเดตรหัสผ่าน (แนะนำให้ใช้ password_hash เพื่อความปลอดภัย)
        // แต่ถ้าฐานข้อมูลเดิมเก็บแบบ Plain Text ให้ใช้คำสั่งอัปเดตตรงๆ (ไม่แนะนำในระยะยาว)
        $sql_update = "UPDATE users SET password = ?, last_password_change = NOW() WHERE username = ?";
        $stmt_update = mysqli_prepare($conn, $sql_update);
        mysqli_stmt_bind_param($stmt_update, "ss", $new_password, $username);

        if (mysqli_stmt_execute($stmt_update)) {
            $success = "เปลี่ยนรหัสผ่านสำเร็จแล้ว!";
        } else {
            $error = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
        }
    }
}

// 1. ดึงข้อมูลชื่อ-นามสกุลมาแสดง (ย้ายมาไว้ตรงนี้เพื่อให้ได้ข้อมูลล่าสุดหลังอัปโหลด)
$sql = "SELECT m.fname, m.lname, m.email, m.profile_image, p.prefix AS prefix_name, r.role_name 
        FROM member m 
        LEFT JOIN prefix p ON m.prefix = p.prefix_id 
        LEFT JOIN role r ON m.role_id = r.role_id 
        WHERE m.pid = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "s", $username);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user_info = mysqli_fetch_assoc($result);

// เตรียมข้อมูลสำหรับแสดงผล
$display_name = ($user_info['prefix_name'] ?? '') . ($user_info['fname'] ?? '') . ' ' . ($user_info['lname'] ?? '');
$display_role = $user_info['role_name'] ?? 'ทั่วไป';
$masked_username = substr($username, 0, 2) . "XXXXXXXX" . substr($username, -3);
$show_form = (isset($_POST['change_password'])) ? 'show' : '';
$show_email_form = (isset($_POST['request_email_otp']) || isset($_SESSION['email_change_otp']) || isset($_POST['cancel_email_change']) || isset($_POST['resend_email_otp'])) ? 'show' : '';
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>โปรไฟล์ส่วนตัว - ระบบภาษี</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/profile.css">
</head>

<body class="d-flex flex-column min-vh-100">
    <nav class="navbar navbar-expand-lg navbar-dark mb-5 shadow-sm">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fas fa-file-invoice-dollar me-2"></i>ระบบดาวน์โหลดเอกสารรับรองภาษี</a>
            <div class="ms-auto">
                <a href="<?php echo $back_url; ?>" class="btn btn-outline-light btn-sm px-3 rounded-pill">
                    <i class="fas fa-arrow-left me-1"></i> <?php echo $back_text; ?>
                </a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0 text-primary"><i class="fas fa-user-cog me-2"></i>ข้อมูลส่วนตัว / เปลี่ยนรหัสผ่าน</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="mb-4 text-center">
                            <div class="position-relative d-inline-block mb-3">
                                <?php if (!empty($user_info['profile_image']) && file_exists("uploads/profile_images/" . $user_info['profile_image'])): ?>
                                    <img src="uploads/profile_images/<?php echo htmlspecialchars($user_info['profile_image']); ?>" class="rounded-circle border shadow-sm" style="width: 120px; height: 120px; object-fit: cover;">
                                <?php else: ?>
                                    <div class="bg-light rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 120px; height: 120px;">
                                        <i class="fas fa-user-circle fa-4x text-secondary"></i>
                                    </div>
                                <?php endif; ?>
                                
                                <form method="POST" enctype="multipart/form-data" class="position-absolute bottom-0 end-0">
                                    <label for="uploadProfile" class="btn btn-sm btn-primary rounded-circle shadow" style="width: 35px; height: 35px; padding: 6px 0; cursor: pointer;" title="เปลี่ยนรูปโปรไฟล์">
                                        <i class="fas fa-camera"></i>
                                    </label>
                                    <input type="file" name="profile_image" id="uploadProfile" class="d-none" onchange="this.form.submit()" accept="image/*">
                                </form>
                            </div>
                            <h4 class="fw-bold mt-2"><?php echo htmlspecialchars($display_name); ?></h4>
                            <p class="mb-2"><span class="badge bg-primary rounded-pill px-3"><?php echo htmlspecialchars($display_role); ?></span></p>
                            <p class="text-muted mb-3">Username: <?php echo htmlspecialchars($masked_username); ?></p>

                            <!-- ส่วนแสดง/แก้ไขอีเมล -->
                            <div class="mb-3">
                                <p class="mb-1 text-muted small">อีเมลสำหรับรับการแจ้งเตือน</p>
                                <div class="d-flex justify-content-center align-items-center gap-2">
                                    <span class="fw-bold text-dark">
                                        <?php echo !empty($user_info['email']) ? htmlspecialchars($user_info['email']) : '<span class="text-danger">ยังไม่ได้ระบุอีเมล</span>'; ?>
                                    </span>
                                    
                                    <?php if ($use_otp): ?>
                                        <button class="btn btn-sm btn-link text-decoration-none p-0" type="button" data-bs-toggle="collapse" data-bs-target="#emailForm" aria-expanded="<?php echo $show_email_form ? 'true' : 'false'; ?>">
                                            <i class="fas fa-edit"></i> แก้ไข
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-link text-secondary text-decoration-none p-0" type="button" onclick="showOtpDisabledWarning()">
                                            <i class="fas fa-edit"></i> แก้ไข
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- ฟอร์มแก้ไขอีเมล -->
                            <div class="collapse <?php echo $show_email_form; ?> mb-4" id="emailForm">
                                <div class="card card-body bg-light border-0 p-3">
                                    <?php if (isset($_SESSION['email_change_otp'])): ?>
                                        <div class="text-center">
                                            <p class="mb-2 small">ระบบได้ส่งรหัส OTP ไปยัง <strong><?php echo htmlspecialchars($_SESSION['email_change_otp']['email']); ?></strong></p>
                                            <button type="button" class="btn btn-primary btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#otpModal">
                                                <i class="fas fa-key me-1"></i> กรอกรหัส OTP
                                            </button>
                                            <form method="POST" class="d-inline"><button type="submit" name="cancel_email_change" class="btn btn-link btn-sm text-muted text-decoration-none">ยกเลิก</button></form>
                                        </div>
                                    <?php else: ?>
                                        <!-- ฟอร์มกรอกอีเมลใหม่ -->
                                        <form method="POST">
                                            <label class="form-label small fw-bold">อีเมลใหม่ที่ต้องการใช้</label>
                                            <div class="input-group">
                                                <input type="email" name="new_email" class="form-control" placeholder="example@email.com" required>
                                                <button type="submit" name="request_email_otp" class="btn btn-primary">
                                                    <?php echo $use_otp ? 'ส่ง OTP' : 'บันทึก'; ?>
                                                </button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <button class="btn btn-outline-secondary btn-sm rounded-pill px-4" type="button" data-bs-toggle="collapse" data-bs-target="#passwordForm" aria-expanded="<?php echo $show_form ? 'true' : 'false'; ?>">
                                <i class="fas fa-key me-2"></i> เปลี่ยนรหัสผ่าน
                            </button>
                        </div>

                        <div class="collapse <?php echo $show_form; ?>" id="passwordForm">
                            <hr class="my-4">
                            
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">รหัสผ่านเดิม</label>
                                    <input type="password" name="old_password" class="form-control" placeholder="ระบุรหัสผ่านปัจจุบันเพื่อยืนยัน" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">รหัสผ่านใหม่</label>
                                    <input type="password" name="new_password" class="form-control" placeholder="A-Z, a-z และ 0-9 อย่างน้อย 8 ตัว" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">ยืนยันรหัสผ่านใหม่</label>
                                    <input type="password" name="confirm_password" class="form-control" placeholder="พิมพ์รหัสผ่านอีกครั้ง" required>
                                </div>
                                <div class="d-grid">
                                    <button type="submit" name="change_password" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i> บันทึกรหัสผ่านใหม่
                                    </button>
                                </div>
                            </form>
                            
                            <div class="text-center mt-3">
                                <small class="text-muted">รหัสผ่านต้องมีตัวอักษรและตัวเลขรวมกัน 8 ตัวขึ้นไป</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include 'footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- OTP Modal -->
    <div class="modal fade" id="otpModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
                <div class="modal-body p-5 text-center">
                    <div class="mb-3"><i class="fas fa-envelope-open-text fa-3x text-warning"></i></div>
                    <h5 class="fw-bold">ยืนยันรหัส OTP</h5>
                    <p class="text-muted small">รหัสถูกส่งไปที่: <span class="fw-bold text-dark"><?php echo $_SESSION['email_change_otp']['email'] ?? ''; ?></span><br>Ref: <span class="text-danger fw-bold"><?php echo $_SESSION['email_change_otp']['ref'] ?? ''; ?></span></p>

                    <form method="POST">
                        <div class="d-flex justify-content-center mb-4">
                            <?php for ($i = 0; $i < 6; $i++): ?>
                                <input type="text" name="otp_digits[]" class="otp-digit form-control mx-1 text-center fw-bold" style="width: 45px; height: 50px; font-size: 20px;" maxlength="1" pattern="\d*" inputmode="numeric" required>
                            <?php endfor; ?>
                        </div>
                        <button type="submit" name="verify_email_otp" class="btn btn-primary w-100 py-2 mb-3 rounded-pill">ยืนยันรหัส</button>
                        <div class="d-flex justify-content-between align-items-center">
                            <button type="submit" name="resend_email_otp" id="resendBtnModal" class="btn btn-link btn-sm text-decoration-none p-0" formnovalidate>ขอรหัสใหม่</button>
                            <button type="button" class="btn btn-link btn-sm text-muted text-decoration-none p-0" data-bs-dismiss="modal">ปิดหน้าต่าง</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal แจ้งเตือน (ใช้ร่วมกันทั้ง Success และ Error) -->
    <div class="modal fade" id="notificationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content text-center p-5 border-0 shadow">
                <div class="mb-4" id="modalIcon"></div>
                <h3 id="modalTitle" class="fw-bold text-dark mb-2"></h3>
                <p id="modalMessage" class="text-muted fs-5"></p>
                <div class="mt-4">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ปิด</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        var myModal = new bootstrap.Modal(document.getElementById('notificationModal'));
        var modalIcon = document.getElementById('modalIcon');
        var modalTitle = document.getElementById('modalTitle');
        var modalMessage = document.getElementById('modalMessage');

        // ฟังก์ชันแสดงแจ้งเตือนเมื่อ OTP ปิดอยู่
        function showOtpDisabledWarning() {
            modalIcon.innerHTML = '<div class="rounded-circle bg-secondary bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;"><i class="fas fa-exclamation-triangle text-secondary fa-3x"></i></div>';
            modalTitle.innerText = "ระบบ OTP ปิดใช้งาน";
            modalMessage.innerText = "ระบบ OTP ปิดการใช้งานอยู่ กรุณาติดต่อแอดมินเพื่อทำการแก้ไขอีเมลต่อไป";
            myModal.show();
        }

        <?php if ($success): ?>
            modalIcon.innerHTML = '<div class="rounded-circle bg-success bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;"><i class="fas fa-check text-success fa-3x"></i></div>';
            modalTitle.innerText = "ดำเนินการสำเร็จ";
            modalMessage.innerText = "<?php echo $success; ?>";
            myModal.show();
            setTimeout(function(){ myModal.hide(); }, 3000);
        <?php elseif ($error): ?>
            modalIcon.innerHTML = '<div class="rounded-circle bg-danger bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;"><i class="fas fa-times text-danger fa-3x"></i></div>';
            modalTitle.innerText = "เกิดข้อผิดพลาด";
            modalMessage.innerText = "<?php echo $error; ?>";
            myModal.show();
        <?php endif; ?>

        // Auto Show OTP Modal
        <?php if ($show_otp_modal): ?>
            var otpModal = new bootstrap.Modal(document.getElementById('otpModal'));
            otpModal.show();
        <?php endif; ?>

        // Auto Tab for OTP Inputs
        const inputs = document.querySelectorAll('.otp-digit');
        inputs.forEach((input, index) => {
            input.addEventListener('input', (e) => {
                if (e.target.value.length === 1 && index < inputs.length - 1) inputs[index + 1].focus();
            });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && e.target.value.length === 0 && index > 0) inputs[index - 1].focus();
            });
        });

        // สคริปต์นับถอยหลังปุ่มขอรหัสใหม่
        <?php if (isset($_SESSION['email_change_otp'])): ?>
            let timeLeft = <?php echo max(0, 60 - (time() - ($_SESSION['email_change_otp']['last_req'] ?? 0))); ?>;
            const resendBtn = document.getElementById('resendBtnModal');
            
            if (resendBtn) {
                if (timeLeft > 0) {
                    resendBtn.disabled = true;
                    const timer = setInterval(() => {
                        resendBtn.innerText = `ขอรหัสใหม่ (${timeLeft})`;
                        timeLeft--;
                        if (timeLeft < 0) {
                            clearInterval(timer);
                            resendBtn.disabled = false;
                            resendBtn.innerText = 'ขอรหัสใหม่';
                        }
                    }, 1000);
                }
            }
        <?php endif; ?>
    </script>
</body>

</html>