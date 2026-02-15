<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// 1. ตรวจสอบสถานะการเข้าใช้งานครั้งแรก
$session_pid = $_SESSION['username'];

// ตรวจสอบการตั้งค่าระบบ (เพิ่มใหม่)
$settings_file = 'system_settings.json';
$first_login_enabled = true; // ค่าเริ่มต้นเปิดใช้งาน
if (file_exists($settings_file)) {
    $data = json_decode(file_get_contents($settings_file), true);
    if (isset($data['first_login_enabled'])) {
        $first_login_enabled = ($data['first_login_enabled'] == '1');
    }
}

if ($first_login_enabled) {
    $sql_check = "SELECT is_first_login FROM users WHERE username = ?";
    $stmt_check = mysqli_prepare($conn, $sql_check);
    mysqli_stmt_bind_param($stmt_check, "s", $session_pid);
    mysqli_stmt_execute($stmt_check);
    $res_check = mysqli_stmt_get_result($stmt_check);
    $user_status = mysqli_fetch_assoc($res_check);

    if ($user_status['is_first_login'] == 1) {
        header("Location: setup_profile.php");
        exit();
    }
}

// 2. ตรวจสอบสิทธิ์ (Role)
$user_role = 4;
$sql_role = "SELECT role FROM users WHERE username = ?";
$stmt_role = mysqli_prepare($conn, $sql_role);
mysqli_stmt_bind_param($stmt_role, "s", $session_pid);
mysqli_stmt_execute($stmt_role);
$result_role = mysqli_stmt_get_result($stmt_role);
if ($row_role = mysqli_fetch_assoc($result_role)) {
    $user_role = $row_role['role'];
}

// ดึงชื่อผู้ใช้งานปัจจุบันสำหรับแสดงบน Navbar
$current_user_display = "ผู้ใช้งาน";
$sql_name = "SELECT m.fname, m.lname, p.prefix AS prefix_name 
             FROM member m 
             LEFT JOIN prefix p ON m.prefix = p.prefix_id 
             WHERE m.pid = ?";
$stmt_name = mysqli_prepare($conn, $sql_name);
mysqli_stmt_bind_param($stmt_name, "s", $session_pid);
mysqli_stmt_execute($stmt_name);
$res_name = mysqli_stmt_get_result($stmt_name);
if ($row_name = mysqli_fetch_assoc($res_name)) {
    $current_user_display = $row_name['prefix_name'] . $row_name['fname'] . " " . $row_name['lname'];
}

$result_data = null;
$admin_result = null;
$viewing_name = "";
$selected_year = isset($_GET['filter_year']) ? $_GET['filter_year'] : '';

// --- [ADMIN VIEW - ตารางรายชื่อรวม] ---
if ($user_role == 3 && !isset($_GET['view_pid'])) {
    // ---- Pagination & Search ---
    $limit = 50;
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $start = ($page - 1) * $limit;

    $search_term = isset($_GET['search_pid']) ? trim($_GET['search_pid']) : "";
    $where_clause = "";
    $params = [];
    $types = "";

    if (!empty($search_term)) {
        $where_clause = " WHERE m.pid LIKE ? OR m.fname LIKE ? OR m.lname LIKE ? OR m.email LIKE ? OR r.role_name LIKE ?";
        $like_term = "%" . $search_term . "%";
        $params = [$like_term, $like_term, $like_term, $like_term, $like_term];
        $types = "sssss";
    }

    // Count total records
    $sql_total = "SELECT COUNT(m.pid) as total FROM member m LEFT JOIN role r ON m.role_id = r.role_id $where_clause";
    $stmt_total = mysqli_prepare($conn, $sql_total);
    if (!empty($types)) {
        mysqli_stmt_bind_param($stmt_total, $types, ...$params);
    }
    mysqli_stmt_execute($stmt_total);
    $total_records = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_total))['total'];
    $total_pages = ceil($total_records / $limit);

    // Fetch data for current page
    $sql_data = "SELECT m.pid, p.prefix AS prefix_name, m.fname, m.lname, m.email, r.role_name, m.amount_paid, m.tax_withheld 
                 FROM member m 
                 LEFT JOIN prefix p ON m.prefix = p.prefix_id 
                 LEFT JOIN role r ON m.role_id = r.role_id 
                 $where_clause 
                 ORDER BY m.pid ASC
                 LIMIT ?, ?";
    $stmt = mysqli_prepare($conn, $sql_data);
    if (!empty($types)) {
        $types .= "ii";
        $params[] = $start;
        $params[] = $limit;
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    } else {
        mysqli_stmt_bind_param($stmt, "ii", $start, $limit);
    }
    mysqli_stmt_execute($stmt);
    $admin_result = mysqli_stmt_get_result($stmt);
} else {
    // --- [USER VIEW / ADMIN VIEW SINGLE - ข้อมูลรายบุคคล] ---
    $search_pid = ($user_role == 3 && isset($_GET['view_pid'])) ? $_GET['view_pid'] : $session_pid;

    $sql = "SELECT m.pid, p.prefix AS prefix_name, m.fname, m.lname, m.email, r.role_name, m.amount_paid, m.tax_withheld 
            FROM member m 
            LEFT JOIN prefix p ON m.prefix = p.prefix_id 
            LEFT JOIN role r ON m.role_id = r.role_id 
            WHERE m.pid = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $search_pid);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($row_data = mysqli_fetch_assoc($result)) {
        $result_data = $row_data;
        $viewing_name = ($result_data['prefix_name'] ?? '') . $result_data['fname'] . ' ' . $result_data['lname'];
    }

    // จัดการปีและไฟล์ PDF
    $available_years = [];
    $like_pattern = $search_pid . '-%';
    $query_years = ["SELECT file_name FROM tax_reports WHERE file_name LIKE ?", "SELECT file_name FROM pdf_management WHERE file_name LIKE ?"];
    foreach ($query_years as $sql_y) {
        $stmt_y = mysqli_prepare($conn, $sql_y);
        mysqli_stmt_bind_param($stmt_y, "s", $like_pattern);
        mysqli_stmt_execute($stmt_y);
        $res_y = mysqli_stmt_get_result($stmt_y);
        while ($y_row = mysqli_fetch_assoc($res_y)) {
            $parts = explode('-', pathinfo($y_row['file_name'], PATHINFO_FILENAME));
            if (isset($parts[3]) && strlen($parts[3]) >= 2) {
                $yr = substr($parts[3], 0, 2);
                if (!in_array($yr, $available_years)) $available_years[] = $yr;
            }
        }
    }
    rsort($available_years);

    $tax_reports_list = [];
    $pdf_deduction_list = [];
    $year_suffix = !empty($selected_year) ? "%-" . $selected_year . ".pdf" : ".pdf";
    $search_filter = $search_pid . "-%" . $year_suffix;

    $sql_r = "SELECT file_name FROM tax_reports WHERE file_name LIKE ? ORDER BY file_name DESC";
        $stmt_r = mysqli_prepare($conn, $sql_r);
    mysqli_stmt_bind_param($stmt_r, "s", $search_filter);
    mysqli_stmt_execute($stmt_r);
    $res_r = mysqli_stmt_get_result($stmt_r);
    while ($row = mysqli_fetch_assoc($res_r)) {
        $tax_reports_list[] = $row;
    }

    $sql_p = "SELECT file_name FROM pdf_management WHERE file_name LIKE ? ORDER BY file_name DESC";
        $stmt_p = mysqli_prepare($conn, $sql_p);
    mysqli_stmt_bind_param($stmt_p, "s", $search_filter);
    mysqli_stmt_execute($stmt_p);
    $res_p = mysqli_stmt_get_result($stmt_p);
    while ($row = mysqli_fetch_assoc($res_p)) {
        $pdf_deduction_list[] = $row;
    }

    // ฟังก์ชันจัดเรียงวันที่จากชื่อไฟล์ (เรียงจากล่าสุดไปเก่าสุด)
    $date_sort = function($a, $b) {
        $pa = explode('-', pathinfo($a['file_name'], PATHINFO_FILENAME));
        $pb = explode('-', pathinfo($b['file_name'], PATHINFO_FILENAME));
        
        // เปรียบเทียบ ปี -> เดือน -> วัน
        $ya = isset($pa[3]) ? (int)$pa[3] : 0;
        $yb = isset($pb[3]) ? (int)$pb[3] : 0;
        if ($ya != $yb) return $yb - $ya;
        
        $ma = isset($pa[2]) ? (int)$pa[2] : 0;
        $mb = isset($pb[2]) ? (int)$pb[2] : 0;
        if ($ma != $mb) return $mb - $ma;
        
        $da = isset($pa[1]) ? (int)$pa[1] : 0;
        $db = isset($pb[1]) ? (int)$pb[1] : 0;
        return $db - $da;
    };
    usort($tax_reports_list, $date_sort);
    usort($pdf_deduction_list, $date_sort);
}

$thai_months = [1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน', 5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม', 9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'];
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>ระบบจัดการภาษี</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/index.css">
</head>

<body class="d-flex flex-column min-vh-100">
    <nav class="navbar navbar-expand-lg navbar-dark mb-4 shadow-sm">
        <div class="container content-wrapper">
            <a class="navbar-brand" href="index.php"><i class="fas fa-file-invoice-dollar me-2"></i>ระบบดาวน์โหลดเอกสารรับรองภาษี</a>
            <div class="ms-auto d-flex align-items-center">
                <?php if ($user_role == 3): ?>
                    <a href="admin_menu.php" class="btn btn-light btn-sm me-2 rounded-pill text-primary px-3"><i class="fas fa-th-large me-1"></i> เมนูแอดมิน</a>
                <?php else: ?>
                    <a href="profile.php" class="btn btn-outline-light btn-sm rounded-pill px-3 me-2" title="แก้ไขข้อมูลส่วนตัว">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($current_user_display); ?>
                    </a>
                <?php endif; ?>
                <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3"><i class="fas fa-sign-out-alt me-1"></i> ออกจากระบบ</a>
            </div>
        </div>
    </nav>

    <div class="container content-wrapper">
        <div class="row justify-content-center">
            <div class="col-lg-11 col-xl-11">
                <div class="card shadow-sm mb-5">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                        <h5 class="mb-0 text-primary fw-bold">
                            <?php echo ($user_role == 3 && !isset($_GET['view_pid'])) ? '<i class="fas fa-list-ul me-2"></i>รายชื่อบุคลากร' : '<i class="fas fa-user-check me-2"></i>ข้อมูลภาษีส่วนบุคคล'; ?>
                        </h5>
                        <?php if (isset($_GET['view_pid'])): ?><a href="index.php" class="btn btn-secondary btn-sm px-4">กลับ</a><?php endif; ?>
                    </div>
                    <div class="card-body p-4">

                        <?php if ($user_role == 3 && !isset($_GET['view_pid'])): ?>
                            <form method="get" class="mb-4 d-flex justify-content-end">
                                <div class="input-group" style="max-width: 400px;">
                                    <input type="text" class="form-control" name="search_pid" placeholder="ค้นชื่อ, อีเมล หรือตำแหน่ง..." value="<?php echo htmlspecialchars($search_term ?? ''); ?>">
                                    <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button>
                                </div>
                            </form>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ชื่อ-นามสกุล</th>
                                            <th>อีเมล (ติดต่อ)</th>
                                            <th>ตำแหน่ง</th>
                                            <th class="text-end">รายได้สะสม (฿)</th>
                                            <th class="text-end">ภาษีหักสะสม (฿)</th>
                                            <th class="text-center">ดูเอกสาร</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($admin_result): while ($row = mysqli_fetch_assoc($admin_result)): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($row['prefix_name'] . $row['fname'] . ' ' . $row['lname']); ?></strong></td>
                                                    <td class="text-muted small"><?php echo htmlspecialchars($row['email'] ?? '-'); ?></td>
                                                    <td><span class="badge bg-light text-primary border fw-normal"><?php echo htmlspecialchars($row['role_name'] ?? 'บุคลากร'); ?></span></td>
                                                    <td class="text-end text-success amount-text"><?php echo number_format($row['amount_paid'], 2); ?></td>
                                                    <td class="text-end text-danger amount-text"><?php echo number_format($row['tax_withheld'], 2); ?></td>
                                                    <td class="text-center"><a href="index.php?view_pid=<?php echo htmlspecialchars($row['pid']); ?>" class="btn btn-outline-info btn-sm px-3"><i class="fas fa-eye"></i></a></td>
                                                </tr>
                                        <?php endwhile;
                                        endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if (isset($total_pages) && $total_pages > 1): ?>
                            <nav class="mt-4">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&search_pid=<?php echo urlencode($search_term); ?>"><i class="fas fa-chevron-left"></i></a>
                                    </li>
                                    <?php
                                    $range = 2;
                                    for ($i = 1; $i <= $total_pages; $i++) {
                                        if ($i == 1 || $i == $total_pages || ($i >= $page - $range && $i <= $page + $range)) {
                                            echo '<li class="page-item ' . ($page == $i ? 'active' : '') . '"><a class="page-link" href="?page=' . $i . '&search_pid=' . urlencode($search_term) . '">' . $i . '</a></li>';
                                        } elseif ($i == $page - $range - 1 || $i == $page + $range + 1) {
                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        }
                                    } ?>
                                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&search_pid=<?php echo urlencode($search_term); ?>"><i class="fas fa-chevron-right"></i></a>
                                    </li>
                                </ul>
                            </nav>
                            <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (($user_role != 3 || isset($_GET['view_pid'])) && $result_data): ?>
                            <div class="bg-light p-4 rounded-3 border mb-4 shadow-sm">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <h4 class="fw-bold mb-1 text-dark"><?php echo htmlspecialchars($viewing_name); ?></h4>
                                        <div class="mb-2">
                                            <span class="badge bg-primary me-2"><i class="fas fa-briefcase me-1"></i> <?php echo htmlspecialchars($result_data['role_name'] ?? 'บุคลากร'); ?></span>
                                            <span class="text-muted small"><i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($result_data['email'] ?? '-'); ?></span>
                                        </div>
                                        <p class="text-muted small mb-0">Username: <?php echo substr($result_data['pid'], 0, 1) . "XXXXX" . substr($result_data['pid'], -4); ?></p>
                                    </div>
                                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                                        <form method="get">
                                            <?php if (isset($_GET['view_pid'])): ?><input type="hidden" name="view_pid" value="<?php echo htmlspecialchars($_GET['view_pid']); ?>"><?php endif; ?>
                                            <select name="filter_year" class="form-select" onchange="this.form.submit()">
                                                <option value="">ปี พ.ศ. ทั้งหมด</option>
                                                <?php foreach ($available_years as $yr): ?><option value="<?php echo $yr; ?>" <?php echo ($selected_year == $yr) ? 'selected' : ''; ?>>25<?php echo $yr; ?></option><?php endforeach; ?>
                                            </select>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-4 mb-4">
                                <div class="col-md-6">
                                    <div class="card h-100 border-start border-success border-4 py-3 px-4 shadow-sm">
                                        <small class="text-success fw-bold text-uppercase">รายได้รวมสะสม</small>
                                        <h2 class="amount-text mb-0 mt-1">฿ <?php echo number_format($result_data['amount_paid'], 2); ?></h2>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card h-100 border-start border-danger border-4 py-3 px-4 shadow-sm">
                                        <small class="text-danger fw-bold text-uppercase">ภาษีหัก ณ ที่จ่าย</small>
                                        <h2 class="amount-text mb-0 mt-1">฿ <?php echo number_format($result_data['tax_withheld'], 2); ?></h2>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <h5 class="doc-section-title text-primary"><i class="fas fa-file-pdf text-danger me-2"></i>เอกสารหักภาษี ณ ที่จ่าย</h5>
                                    <div class="list-group shadow-sm">
                                        <?php if (!empty($tax_reports_list)): 
                                            $current_year = null;
                                            foreach ($tax_reports_list as $r):
                                                $p = explode('-', pathinfo($r['file_name'], PATHINFO_FILENAME));
                                                $ds = (isset($p[1]) ? (int)$p[1] : '-') . " " . ($thai_months[(int)($p[2] ?? 0)] ?? '-') . " " . (isset($p[3]) ? '25' . $p[3] : '-'); ?>
                                                 
                                                <?php 
                                                    $year = isset($p[3]) ? '25' . $p[3] : '-';
                                                    if ($year != $current_year): 
                                                        if ($current_year != null) echo '<hr class="my-2">'; // เส้นคั่นปี (ยกเว้นปีแรก)
                                                        echo '<h6 class="text-muted fw-bold">ปี ' . $year . '</h6>';
                                                        $current_year = $year;
                                                    endif; 
                                                ?>

                                                <a href="view_pdf.php?file=<?php echo htmlspecialchars($r['file_name']); ?>" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                                    <span><?php echo $ds; ?></span><i class="fas fa-file-download text-muted"></i>
                                                </a>
                                        <?php endforeach;
                                        else: ?><div class="list-group-item text-muted text-center py-3">ไม่มีข้อมูลเอกสาร</div><?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">

                                    <h5 class="doc-section-title text-success"><i class="fas fa-file-invoice text-success me-2"></i>เอกสารลดหย่อนภาษีอื่นๆ</h5>
                                    <div class="list-group shadow-sm">
                                        <?php if (!empty($pdf_deduction_list)): 
                                            $current_year_deduct = null;
                                            foreach ($pdf_deduction_list as $pdf):
                                                $p = explode('-', pathinfo($pdf['file_name'], PATHINFO_FILENAME));
                                                $ds = (isset($p[1]) ? (int)$p[1] : '-') . " " . ($thai_months[(int)($p[2] ?? 0)] ?? '-') . " " . (isset($p[3]) ? '25' . $p[3] : '-'); 
                                                
                                                $year = isset($p[3]) ? '25' . $p[3] : '-';
                                                if ($year != $current_year_deduct): 
                                                    if ($current_year_deduct != null) echo '<hr class="my-2">';
                                                    echo '<h6 class="text-muted fw-bold">ปี ' . $year . '</h6>';
                                                    $current_year_deduct = $year;
                                                endif;
                                                ?>
                                                <a href="PDF/pdf_storage/<?php echo htmlspecialchars($pdf['file_name']); ?>" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                                    <span><?php echo $ds; ?></span><i class="fas fa-external-link-alt text-muted"></i>
                                                </a>
                                            <?php endforeach;
                                        else: ?><div class="list-group-item text-muted text-center py-3">ไม่มีข้อมูลเอกสาร</div><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include 'footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>