<?php
session_start();
require_once 'config.php';

// 1. ตรวจสอบสิทธิ์แอดมิน
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$sql_role = "SELECT role FROM users WHERE username = ?";
$stmt_r = mysqli_prepare($conn, $sql_role);
mysqli_stmt_bind_param($stmt_r, "s", $username);
mysqli_stmt_execute($stmt_r);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_r));

if ($user['role'] != 3) {
    echo "คุณไม่มีสิทธิ์เข้าถึงหน้านี้";
    exit();
}

// 2. จัดการคำสั่ง ลบข้อมูล
if (isset($_GET['delete_pid'])) {
    $pid_del = $_GET['delete_pid'];

    // 1. ลบไฟล์และข้อมูลจาก tax_reports (เอกสารหักภาษี)
    $sql_files_tax = "SELECT file_name FROM tax_reports WHERE file_name LIKE CONCAT(?, '-%')";
    $stmt_files_tax = mysqli_prepare($conn, $sql_files_tax);
    mysqli_stmt_bind_param($stmt_files_tax, "s", $pid_del);
    mysqli_stmt_execute($stmt_files_tax);
    $res_files_tax = mysqli_stmt_get_result($stmt_files_tax);
    while ($row = mysqli_fetch_assoc($res_files_tax)) {
        $file_path = "PDF/processed_PDFs/" . $row['file_name'];
        if (file_exists($file_path)) @unlink($file_path);
    }
    mysqli_stmt_close($stmt_files_tax);
    
    $stmt_del_tax = mysqli_prepare($conn, "DELETE FROM tax_reports WHERE file_name LIKE CONCAT(?, '-%')");
    mysqli_stmt_bind_param($stmt_del_tax, "s", $pid_del);
    mysqli_stmt_execute($stmt_del_tax);
    mysqli_stmt_close($stmt_del_tax);

    // 2. ลบไฟล์และข้อมูลจาก pdf_management (เอกสารลดหย่อน)
    $sql_files_pdf = "SELECT file_name FROM pdf_management WHERE file_name LIKE CONCAT(?, '-%')";
    $stmt_files_pdf = mysqli_prepare($conn, $sql_files_pdf);
    mysqli_stmt_bind_param($stmt_files_pdf, "s", $pid_del);
    mysqli_stmt_execute($stmt_files_pdf);
    $res_files_pdf = mysqli_stmt_get_result($stmt_files_pdf);
    while ($row = mysqli_fetch_assoc($res_files_pdf)) {
        $file_path = "PDF/pdf_storage/" . $row['file_name'];
        if (file_exists($file_path)) @unlink($file_path);
    }
    mysqli_stmt_close($stmt_files_pdf);

    $stmt_del_pdf = mysqli_prepare($conn, "DELETE FROM pdf_management WHERE file_name LIKE CONCAT(?, '-%')");
    mysqli_stmt_bind_param($stmt_del_pdf, "s", $pid_del);
    mysqli_stmt_execute($stmt_del_pdf);
    mysqli_stmt_close($stmt_del_pdf);

    // 3. ลบข้อมูลจากตาราง users (บัญชีเข้าสู่ระบบ)
    $stmt_del_user = mysqli_prepare($conn, "DELETE FROM users WHERE username = ?");
    mysqli_stmt_bind_param($stmt_del_user, "s", $pid_del);
    mysqli_stmt_execute($stmt_del_user);
    mysqli_stmt_close($stmt_del_user);

    $stmt_del = mysqli_prepare($conn, "DELETE FROM member WHERE pid = ?");
    mysqli_stmt_bind_param($stmt_del, "s", $pid_del);
    if (mysqli_stmt_execute($stmt_del)) {
        header("Location: admin_manage.php?msg=deleted");
        exit();
    }
}

// 2.1 จัดการคำสั่ง รีเซ็ตรหัสผ่าน (เพิ่มใหม่)
if (isset($_GET['reset_pid'])) {
    $pid_reset = $_GET['reset_pid'];
    $default_pass = substr($pid_reset, -4); // รหัสผ่านคือ 4 ตัวท้าย
    
    // รีเซ็ตรหัสผ่าน, บังคับล็อกอินใหม่ (is_first_login=1), และล้างเวลาเปลี่ยนรหัสล่าสุด
    $stmt_reset = mysqli_prepare($conn, "UPDATE users SET password = ?, is_first_login = 1, last_password_change = NULL WHERE username = ?");
    mysqli_stmt_bind_param($stmt_reset, "ss", $default_pass, $pid_reset);
    
    if (mysqli_stmt_execute($stmt_reset)) {
        header("Location: admin_manage.php?msg=reset_success");
        exit();
    }
}

// 3. ระบบแบ่งหน้าและค้นหา
$limit = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$start = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$where_sql = "";
if ($search !== '') {
    $where_sql = " WHERE m.pid LIKE ? OR m.fname LIKE ? OR m.lname LIKE ? ";
}

$sql_total = "SELECT COUNT(*) as total FROM member m $where_sql";
$stmt_t = mysqli_prepare($conn, $sql_total);
if ($search !== '') {
    $s_param = "%$search%";
    mysqli_stmt_bind_param($stmt_t, "sss", $s_param, $s_param, $s_param);
}
mysqli_stmt_execute($stmt_t);
$total_records = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_t))['total'];
$total_pages = ceil($total_records / $limit);

// ดึงข้อมูลพร้อม JOIN ตาราง users
$sql_members = "SELECT m.*, p.prefix AS prefix_name, u.role as system_role 
                FROM member m 
                LEFT JOIN prefix p ON m.prefix = p.prefix_id 
                LEFT JOIN users u ON m.pid = u.username 
                $where_sql
                ORDER BY m.pid ASC LIMIT ?, ?";

$stmt_m = mysqli_prepare($conn, $sql_members);
if ($search !== '') {
    $s_param = "%$search%";
    mysqli_stmt_bind_param($stmt_m, "sssii", $s_param, $s_param, $s_param, $start, $limit);
} else {
    mysqli_stmt_bind_param($stmt_m, "ii", $start, $limit);
}
mysqli_stmt_execute($stmt_m);
$result_members = mysqli_stmt_get_result($stmt_m);

$prefixes = mysqli_query($conn, "SELECT * FROM prefix");

// เตรียมข้อมูลสำหรับ Popup แจ้งเตือน
$popup_payload = null;
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'success') {
        $popup_payload = [
            'icon' => '<i class="fas fa-check-circle text-success fa-6x fa-beat"></i>',
            'title' => 'ดำเนินการสำเร็จ',
            'text' => 'บันทึกข้อมูลลงในระบบเรียบร้อยแล้ว'
        ];
    } elseif ($_GET['msg'] == 'deleted') {
        $popup_payload = [
            'icon' => '<i class="fas fa-check-circle text-success fa-6x fa-beat"></i>',
            'title' => 'ลบข้อมูลสำเร็จ',
            'text' => 'ลบข้อมูลสมาชิกออกจากระบบแล้ว'
        ];
    } elseif ($_GET['msg'] == 'reset_success') {
        $popup_payload = [
            'icon' => '<i class="fas fa-key text-warning fa-6x fa-beat"></i>',
            'title' => 'รีเซ็ตรหัสผ่านสำเร็จ',
            'text' => 'รหัสผ่านถูกคืนค่าเป็น 4 ตัวท้ายของเลขบัตรฯ และผู้ใช้ต้องตั้งค่าใหม่เมื่อเข้าสู่ระบบ'
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>จัดการสมาชิก</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f8f9fc;
        }
        .card-modern {
            border: none;
            border-radius: 20px;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }
        .table-custom thead th {
            background-color: #4e73df;
            color: white;
            font-weight: 600;
            border: none;
            padding: 15px;
        }
        .table-custom thead th:first-child { border-top-left-radius: 15px; }
        .table-custom thead th:last-child { border-top-right-radius: 15px; }
        .table-custom tbody td {
            padding: 15px;
            vertical-align: middle;
        }
        .pagination .page-link {
            border-radius: 50px !important;
            margin: 0 4px;
            border: none;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            color: #4e73df;
        }
        .pagination .page-item.active .page-link {
            background: #4e73df;
            color: white;
        }
        .btn-add-member {
            background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%);
            border: none;
            border-radius: 50px;
            padding: 10px 25px;
            box-shadow: 0 4px 10px rgba(28, 200, 138, 0.3);
            transition: all 0.3s;
        }
        .btn-add-member:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(28, 200, 138, 0.4);
            color: white;
        }
        .action-btn {
            width: 35px;
            height: 35px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: 0.2s;
        }
        .action-btn:hover {
            background-color: #f8f9fc;
            transform: scale(1.1);
        }
    </style>
</head>

<body class="d-flex flex-column min-vh-100">
    <div class="container mt-5 pb-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-dark mb-1"><i class="fas fa-users-cog text-primary me-2"></i>จัดการข้อมูลสมาชิก</h3>
                <p class="text-muted mb-0">รายการผู้เสียภาษีทั้งหมด <?php echo number_format($total_records); ?> รายการ</p>
            </div>
            <div class="d-flex gap-2">
                <a href="admin_menu.php" class="btn btn-outline-secondary rounded-pill px-4 d-flex align-items-center">
                    <i class="fas fa-arrow-left me-2"></i> กลับเมนูหลัก
                </a>
                <button class="btn btn-add-member text-white" onclick="prepareAddForm()">
                    <i class="fas fa-plus me-2"></i> เพิ่มสมาชิกใหม่
                </button>
            </div>
        </div>

        <form method="GET" class="mb-4">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="input-group shadow-sm rounded-pill">
                        <span class="input-group-text bg-white border-end-0 rounded-pill-start ps-4 text-muted"><i class="fas fa-search"></i></span>
                        <input type="text" name="search" class="form-control border-start-0 ps-2 py-3" placeholder="ค้นหาชื่อ, นามสกุล หรือเลขบัตรประชาชน..." value="<?php echo htmlspecialchars($search); ?>" style="border-radius: 0;">
                        <button type="submit" class="btn btn-primary px-4 rounded-pill-end fw-bold" style="background-color: #4e73df; border-color: #4e73df;">ค้นหา</button>
                    </div>
                </div>
            </div>
            <?php if ($search != ''): ?>
                <div class="text-center mt-2">
                    <a href="admin_manage.php" class="text-danger small text-decoration-none"><i class="fas fa-times-circle"></i> ล้างค่าการค้นหา</a>
                </div>
            <?php endif; ?>
        </form>

        <div class="card card-modern">
            <div class="card-body p-0">
                <div class="table-responsive" style="border-radius: 20px;">
                    <table class="table table-hover table-custom align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">ชื่อ-นามสกุล</th>
                                <th class="text-center">ตำแหน่ง</th>
                                <th class="text-end">ยอดจ่าย (฿)</th>
                                <th class="text-end">ภาษีที่หัก (฿)</th>
                                <th class="text-center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = mysqli_fetch_assoc($result_members)): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold text-dark"><?php echo $row['prefix_name'] . htmlspecialchars($row['fname'] . " " . $row['lname']); ?></div>
                                        <div class="small text-muted"><i class="fas fa-id-card me-1"></i> <?php echo substr($row['pid'], 0, 6) . "XXXXXXX"; ?></div>
                                    </td>
                                    <td class="text-center">
                                        <?php
                                        $badge_class = 'bg-light text-dark border';
                                        $role_text = $row['role_id'];
                                        if ($row['role_id'] == 1) { $role_text = "ข้าราชการ"; $badge_class = "bg-primary bg-opacity-10 text-primary"; }
                                        elseif ($row['role_id'] == 2) { $role_text = "ลูกจ้างประจำ"; $badge_class = "bg-info bg-opacity-10 text-info"; }
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?> rounded-pill px-3 py-2 fw-normal">
                                            <?php echo $role_text; ?>
                                        </span>
                                    </td>
                                    <td class="text-end fw-bold text-secondary"><?php echo number_format($row['amount_paid'], 2); ?></td>
                                    <td class="text-end fw-bold text-danger"><?php echo number_format($row['tax_withheld'], 2); ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-link action-btn text-warning" onclick="prepareEditForm('<?php echo $row['pid']; ?>', '<?php echo $row['prefix']; ?>', '<?php echo addslashes($row['fname']); ?>', '<?php echo addslashes($row['lname']); ?>', '<?php echo isset($row['email']) ? addslashes($row['email']) : ''; ?>', '<?php echo $row['amount_paid']; ?>', '<?php echo $row['tax_withheld']; ?>', '<?php echo $row['role_id']; ?>', '<?php echo $row['system_role']; ?>')" title="แก้ไข">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <a href="#" class="btn btn-link action-btn text-info" onclick="showConfirmModal('admin_manage.php?reset_pid=<?php echo $row['pid']; ?>', 'ยืนยันการรีเซ็ตรหัสผ่าน', 'คุณต้องการรีเซ็ตรหัสผ่านของ <b><?php echo addslashes($row['fname']); ?></b> หรือไม่?<br><br>รหัสผ่านจะถูกตั้งค่าเป็น: <b><?php echo substr($row['pid'], -4); ?></b> (4 ตัวท้ายเลขบัตร)<br>และผู้ใช้จะต้องตั้งรหัสผ่านใหม่เมื่อเข้าสู่ระบบ', 'info'); return false;" title="รีเซ็ตรหัสผ่าน">
                                            <i class="fas fa-key"></i>
                                        </a>
                                        <a href="#" class="btn btn-link action-btn text-danger" onclick="showConfirmModal('admin_manage.php?delete_pid=<?php echo $row['pid']; ?>', 'ยืนยันการลบข้อมูล', 'คุณต้องการลบข้อมูลของ <b><?php echo addslashes($row['fname']); ?></b> ออกจากระบบหรือไม่?<br><small class=\'text-danger\'>ข้อมูลและเอกสารทั้งหมดจะถูกลบถาวร</small>', 'danger'); return false;" title="ลบ">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
            <nav class="mt-5">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>"><i class="fas fa-chevron-left"></i></a>
                    </li>
                    <?php
                    $range = 1;
                    for ($i = 1; $i <= $total_pages; $i++) {
                        if ($i == 1 || $i == $total_pages || ($i >= $page - $range && $i <= $page + $range)) {
                            echo '<li class="page-item ' . ($page == $i ? 'active' : '') . '"><a class="page-link" href="?page=' . $i . '&search=' . urlencode($search) . '">' . $i . '</a></li>';
                        } elseif ($i == $page - $range - 1 || $i == $page + $range + 1) {
                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                    }
                    ?>
                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>"><i class="fas fa-chevron-right"></i></a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>

    <?php include 'footer.php'; ?>

    <div class="modal fade" id="memberModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 15px; overflow: hidden;">
                <form action="admin_process.php" method="POST">
                    <div class="modal-header text-white py-3" id="modalHeader" style="background: linear-gradient(135deg, #4e73df, #224abe);">
                        <h5 class="modal-title fw-bold" id="modalTitle"><i class="fas fa-user-edit me-2"></i>ข้อมูลสมาชิก</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light">
                        <input type="hidden" name="action" id="formAction">
                        
                        <div class="card border-0 shadow-sm mb-3">
                            <div class="card-body">
                                <h6 class="text-primary fw-bold mb-3"><i class="fas fa-id-card me-2"></i>ข้อมูลส่วนตัว</h6>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted">เลขบัตรประชาชน (PID)</label>
                                        <input type="text" name="pid" id="m_pid" class="form-control" maxlength="13" required placeholder="เลข 13 หลัก">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted">อีเมล (สำหรับแจ้งเตือน)</label>
                                        <input type="email" name="email" id="m_email" class="form-control" placeholder="example@email.com">
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-2">
                                        <label class="form-label small text-muted">คำนำหน้า</label>
                                        <select name="prefix" id="m_prefix" class="form-select">
                                            <?php mysqli_data_seek($prefixes, 0);
                                            while ($p = mysqli_fetch_assoc($prefixes)): ?>
                                                <option value="<?php echo $p['prefix_id']; ?>"><?php echo $p['prefix']; ?></option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label small text-muted">ชื่อจริง</label>
                                        <input type="text" name="fname" id="m_fname" class="form-control" required>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label small text-muted">นามสกุล</label>
                                        <input type="text" name="lname" id="m_lname" class="form-control" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <h6 class="text-success fw-bold mb-3"><i class="fas fa-briefcase me-2"></i>ข้อมูลการทำงานและภาษี</h6>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted">ตำแหน่งงาน</label>
                                        <select name="role_id" id="m_role" class="form-select" required>
                                            <option value="1">ข้าราชการ</option>
                                            <option value="2">ลูกจ้างประจำ</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted">สิทธิ์การใช้งานระบบ</label>
                                        <select name="system_role" id="m_system_role" class="form-select" required>
                                            <option value="4">User (ผู้ใช้งานทั่วไป)</option>
                                            <option value="3">Admin (ผู้ดูแลระบบ)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted">ยอดรายได้สะสม (บาท)</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white text-success">฿</span>
                                            <input type="number" step="0.01" name="amount_paid" id="m_amount" class="form-control fw-bold text-end" placeholder="0.00">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted">ภาษีหัก ณ ที่จ่าย (บาท)</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white text-danger">฿</span>
                                            <input type="number" step="0.01" name="tax_withheld" id="m_tax" class="form-control fw-bold text-end text-danger" placeholder="0.00">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-white border-top-0">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm" id="modalSubmitBtn">บันทึกข้อมูล</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal ยืนยันการกระทำ (Confirmation) -->
    <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 15px; overflow: hidden;">
                <div class="modal-body p-5 text-center">
                    <div class="mb-4" id="confirmIcon"></div>
                    <h4 id="confirmTitle" class="fw-bold text-dark mb-3"></h4>
                    <p id="confirmMessage" class="text-muted mb-4"></p>
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ยกเลิก</button>
                        <a href="#" id="confirmBtn" class="btn btn-primary rounded-pill px-4 shadow-sm">ยืนยัน</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal แจ้งเตือน (Success/Error) -->
    <div class="modal fade" id="notificationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content text-center p-5 border-0 shadow">
                <div class="mb-4" id="modalIcon"></div>
                <h3 id="modalTitle" class="fw-bold text-dark mb-2"></h3>
                <p id="modalMessage" class="text-muted fs-5"></p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const myModal = new bootstrap.Modal(document.getElementById('memberModal'));
        const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
        const confirmBtn = document.getElementById('confirmBtn');
        const confirmTitle = document.getElementById('confirmTitle');
        const confirmMessage = document.getElementById('confirmMessage');
        const confirmIcon = document.getElementById('confirmIcon');

        function showConfirmModal(url, title, message, type = 'warning') {
            confirmBtn.href = url;
            confirmTitle.innerText = title;
            confirmMessage.innerHTML = message;

            if (type === 'danger') {
                confirmIcon.innerHTML = '<div class="rounded-circle bg-danger bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;"><i class="fas fa-exclamation-triangle text-danger fa-3x"></i></div>';
                confirmBtn.className = 'btn btn-danger rounded-pill px-4 shadow-sm';
                confirmBtn.innerText = 'ยืนยันลบ';
            } else if (type === 'info') {
                confirmIcon.innerHTML = '<div class="rounded-circle bg-info bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;"><i class="fas fa-key text-info fa-3x"></i></div>';
                confirmBtn.className = 'btn btn-info text-white rounded-pill px-4 shadow-sm';
                confirmBtn.innerText = 'ยืนยันรีเซ็ต';
            } else {
                confirmIcon.innerHTML = '<div class="rounded-circle bg-warning bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;"><i class="fas fa-question text-warning fa-3x"></i></div>';
                confirmBtn.className = 'btn btn-warning text-dark rounded-pill px-4 shadow-sm';
                confirmBtn.innerText = 'ยืนยัน';
            }

            confirmModal.show();
        }

        function prepareAddForm() {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus me-2"></i>เพิ่มสมาชิกใหม่';
            document.getElementById('formAction').value = 'add';
            document.getElementById('m_pid').readOnly = false;
            document.querySelector('#memberModal form').reset();

            // ปรับสีสำหรับฟอร์มเพิ่ม (สีเขียว)
            const header = document.getElementById('modalHeader');
            header.style.background = 'linear-gradient(135deg, #1cc88a, #13855c)';
            
            const btn = document.getElementById('modalSubmitBtn');
            btn.className = 'btn btn-success rounded-pill px-4 shadow-sm';
            btn.innerHTML = '<i class="fas fa-save me-2"></i> บันทึกสมาชิกใหม่';

            myModal.show();
        }

        function prepareEditForm(pid, prefix, fname, lname, email, amount, tax, role, system_role) {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-edit me-2"></i>แก้ไขข้อมูลสมาชิก';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('m_pid').value = pid;
            document.getElementById('m_pid').readOnly = true;
            document.getElementById('m_prefix').value = prefix;
            document.getElementById('m_fname').value = fname;
            document.getElementById('m_lname').value = lname;
            document.getElementById('m_email').value = email;
            document.getElementById('m_amount').value = amount;
            document.getElementById('m_tax').value = tax;
            document.getElementById('m_role').value = role;
            document.getElementById('m_system_role').value = system_role;

            // ปรับสีสำหรับฟอร์มแก้ไข (สีน้ำเงิน)
            const header = document.getElementById('modalHeader');
            header.style.background = 'linear-gradient(135deg, #4e73df, #224abe)';
            
            const btn = document.getElementById('modalSubmitBtn');
            btn.className = 'btn btn-primary rounded-pill px-4 shadow-sm';
            btn.innerHTML = '<i class="fas fa-save me-2"></i> บันทึกการแก้ไข';

            myModal.show();
        }

        // แสดง Popup แจ้งเตือนถ้ามี msg ส่งมา
        <?php if ($popup_payload): ?>
            var notifModal = new bootstrap.Modal(document.getElementById('notificationModal'));
            document.getElementById('modalIcon').innerHTML = '<?php echo $popup_payload['icon']; ?>';
            document.getElementById('modalTitle').innerText = '<?php echo $popup_payload['title']; ?>';
            document.getElementById('modalMessage').innerText = '<?php echo $popup_payload['text']; ?>';
            notifModal.show();
            setTimeout(function(){ notifModal.hide(); }, 3000);
        <?php endif; ?>
    </script>
</body>

</html>