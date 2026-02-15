<?php
session_start();
require_once 'config.php';

if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // ใช้ Prepared Statement เพื่อความปลอดภัย
    $sql = "SELECT * FROM users WHERE username = ? AND password = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $username, $password);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) == 1) {
        $row = mysqli_fetch_assoc($result);
        $_SESSION['username'] = $row['username'];
        header("Location: index.php");
        exit();
    } else {
        $error = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
    }
}

// ตรวจสอบสถานะ OTP
$use_otp = true;
$settings_file = 'system_settings.json';
if (file_exists($settings_file)) {
    $data = json_decode(file_get_contents($settings_file), true);
    if (isset($data['otp_enabled'])) $use_otp = ($data['otp_enabled'] == '1');
}
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - ระบบภาษี สพม.ลำปาง ลำพูน</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/login.css">
</head>

<body>

    <div class="login-container">
        <div class="login-card">
            <div class="login-left">
                <h1>ระบบค้นหาเอกสารรับรองภาษี</h1>
                <p>ของบุคลากรประเภทพนักงานราชการ และจ้างเหมาบริการในสังกัด สพม.ลำปาง ลำพูน</p>
            </div>

            <div class="login-right">
                <img src="images/logo.png" alt="Logo" class="logo-img">
                <h4>USER LOGIN</h4>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger py-2 w-100 mb-3 small text-center" style="border-radius: 50px;">
                        <i class="fas fa-exclamation-circle me-1"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="" class="w-100">
                    <div class="input-group-custom">
                        <i class="fas fa-user-circle"></i>
                        <input type="text" name="username" class="form-control" placeholder="เลขบัตรประชาชน" required>
                    </div>

                    <div class="input-group-custom">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" class="form-control" placeholder="รหัสผ่าน 8 ตัวท้ายเลขบัตรประชาชน" required>
                    </div>

                    <button type="submit" name="login" class="btn btn-login">Login</button>

                    <?php if ($use_otp): ?>
                        <div class="text-center">
                            <a href="forgot_password.php" class="forgot-link">ลืมรหัสผ่าน?</a>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>