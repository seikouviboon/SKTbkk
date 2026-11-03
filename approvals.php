<?php
/**
 * สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)
 * ระบบบันทึกการเดินทาง (GPS & AI Verification)
 * ไฟล์: approvals.php
 * คำอธิบาย: ศูนย์พิจารณาอนุมัติใบขอเบิกค่าใช้จ่ายการเดินทาง (Approval Management Center)
 */

session_start();

// ตรวจสอบสิทธิ์การเข้าใช้งาน (รองรับตัวแปร Session ทุกรูปแบบของระบบ สกต.)
$user_role = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';
$user_name = $_SESSION['user']['name'] ?? $_SESSION['name'] ?? $_SESSION['user_name'] ?? 'ผู้จัดการ สกต.กทม.';
$user_id   = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 1;

if (empty($user_role) || !in_array($user_role, ['admin', 'superadmin', 'skt01', 'manager'])) {
    header('Location: https://sktbkk.seikouedu.com/index.php');
    exit;
}

$current_user = [
    'id' => $user_id,
    'username' => $_SESSION['username'] ?? 'admin',
    'role' => $user_role,
    'name' => $user_name
];

// โหลดการเชื่อมต่อฐานข้อมูลแบบปลอดภัย
$pdo = null;
$possible_db_files = [
    __DIR__ . '/../config/database.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/../db.php'
];
foreach ($possible_db_files as $f) {
    if (file_exists($f)) {
        require_once $f;
        break;
    }
}

if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
    $pdo = $GLOBALS['pdo'];
} elseif (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
    $pdo = $GLOBALS['db'];
} elseif (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) {
    $pdo = $GLOBALS['conn'];
} elseif (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER')) {
    $pass = defined('DB_PASS') ? DB_PASS : (defined('DB_PASSWORD') ? DB_PASSWORD : '');
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (Exception $e) {}
}

// ตรวจสอบโครงสร้างตารางและข้อมูลอัตโนมัติ หากยังไม่สมบูรณ์ให้รัน install.php ซ่อมแซมทันที
if ($pdo) {
    try {
        $pdo->query('SELECT c.claim_id, t.trip_id FROM travel_claims c JOIN travel_trips t ON c.trip_id = t.trip_id LIMIT 1');
    } catch (Exception $e) {
        if (file_exists(__DIR__ . '/install.php')) {
            require_once __DIR__ . '/install.php';
            exit;
        } elseif (file_exists(__DIR__ . '/../travel/install.php')) {
            require_once __DIR__ . '/../travel/install.php';
            exit;
        }
    }
}
$filter_status = $_GET['status'] ?? 'all';
$filter_month  = $_GET['month'] ?? date('Y-m');

// ดึงข้อมูลสรุป KPI ใบขอเบิก
$kpi = ['pending_count' => 0, 'pending_amount' => 0, 'approved_count' => 0, 'approved_amount' => 0, 'rejected_count' => 0];
try {
    $sql_kpi = "
        SELECT 
            COUNT(CASE WHEN c.claim_status = 'submitted' THEN 1 END) AS pending_count,
            COALESCE(SUM(CASE WHEN c.claim_status = 'submitted' THEN c.total_amount ELSE 0 END), 0) AS pending_amount,
            COUNT(CASE WHEN c.claim_status = 'approved' AND DATE_FORMAT(t.trip_date, '%Y-%m') = :month THEN 1 END) AS approved_count,
            COALESCE(SUM(CASE WHEN c.claim_status = 'approved' AND DATE_FORMAT(t.trip_date, '%Y-%m') = :month THEN c.total_amount ELSE 0 END), 0) AS approved_amount,
            COUNT(CASE WHEN c.claim_status = 'rejected' AND DATE_FORMAT(t.trip_date, '%Y-%m') = :month THEN 1 END) AS rejected_count
        FROM travel_claims c
        JOIN travel_trips t ON c.trip_id = t.trip_id
    ";
    $stmt_kpi = $pdo->prepare($sql_kpi);
    $stmt_kpi->execute([':month' => $filter_month]);
    $kpi_res = $stmt_kpi->fetch(PDO::FETCH_ASSOC);
    if ($kpi_res) $kpi = $kpi_res;
} catch (Exception $e) {
    if (file_exists(__DIR__ . '/install.php')) {
        require_once __DIR__ . '/install.php';
        exit;
    }
}

// สร้างคำสั่ง SQL สำหรับดึงรายการใบขอเบิก
$where_clauses = ["DATE_FORMAT(t.trip_date, '%Y-%m') = :month"];
$params = [':month' => $filter_month];

if ($filter_status !== 'all') {
    $where_clauses[] = "c.claim_status = :status";
    $params[':status'] = $filter_status;
}

$where_sql = implode(' AND ', $where_clauses);

$sql_claims = "
    SELECT 
        c.claim_id,
        c.trip_id,
        c.claim_type,
        c.rate_id,
        c.distance_km,
        c.fuel_rate,
        c.wear_rate,
        c.fuel_amount,
        c.wear_amount,
        c.total_amount,
        c.claim_status,
        c.created_at AS claim_date,
        c.approved_at,
        c.approved_by,
        c.approver_note,
        t.trip_code,
        t.trip_date,
        t.start_time,
        t.end_time,
        t.start_location,
        t.end_location,
        t.purpose,
        t.vehicle_plate,
        t.officer_name,
        t.officer_position,
        (SELECT COUNT(*) FROM travel_waypoints w WHERE w.trip_id = t.trip_id) AS waypoint_count
    FROM travel_claims c
    JOIN travel_trips t ON c.trip_id = t.trip_id
    WHERE {$where_sql}
    ORDER BY c.claim_id DESC
";
$claims = [];
try {
    $stmt_claims = $pdo->prepare($sql_claims);
    $stmt_claims->execute($params);
    $claims = $stmt_claims->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    if (file_exists(__DIR__ . '/install.php')) {
        require_once __DIR__ . '/install.php';
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ศูนย์พิจารณาอนุมัติใบเบิกค่าเดินทาง — สกต.กทม.</title>
    
    <!-- Google Fonts: Prompt -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --primary-baac: #008751;
            --primary-dark: #005f38;
            --accent-gold: #f59e0b;
        }
        body {
            font-family: 'Prompt', sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
        }
        .navbar-baac {
            background: linear-gradient(135deg, var(--primary-baac) 0%, var(--primary-dark) 100%);
            box-shadow: 0 2px 8px rgba(0,0,0,0.12);
        }
        .card-custom {
            border: none;
            border-radius: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .kpi-card {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
            transition: transform 0.15s ease-in-out;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
        }
        .badge-status {
            font-size: 0.8rem;
            padding: 5px 10px;
            border-radius: 6px;
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

<div class="container-fluid py-4 px-lg-4">

    <!-- Top Bar -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <div class="d-flex align-items-center gap-2">
                <a href="https://sktbkk.seikouedu.com/travel/index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-chevron-left me-1"></i> หน้ารวมการเดินทาง
                </a>
                <h3 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-shield-check text-success me-2"></i>ศูนย์พิจารณาอนุมัติใบเบิกค่าเดินทาง
                </h3>
            </div>
            <p class="text-muted small mb-0 mt-1">
                พิจารณาตรวจสอบหลักฐานพิกัดดาวเทียม GPS ภาพถ่ายจุดแวะ และอนุมัติสั่งจ่ายตามระเบียบ สกต.กทม.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="https://sktbkk.seikouedu.com/travel/rates.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-sliders me-1"></i> กำหนดอัตราค่าน้ำมัน/มติ
            </a>
            <a href="https://sktbkk.seikouedu.com/travel/report.php" class="btn btn-outline-dark btn-sm">
                <i class="bi bi-bar-chart-fill me-1"></i> รายงานสรุปบัญชี
            </a>
        </div>
    </div>

    <!-- แถบ KPI ตัวเลขสำคัญสำหรับผู้อนุมัติ -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card bg-white p-3 border-start border-4 border-warning">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted fw-bold">⏳ คำขอรอการพิจารณา</small>
                        <div class="fs-2 fw-bold text-warning"><?= number_format($kpi['pending_count'] ?? 0) ?> <span class="fs-6 fw-normal text-muted">รายการ</span></div>
                        <small class="text-secondary">ยอดเงินรออนุมัติ: <strong>฿<?= number_format($kpi['pending_amount'] ?? 0, 2) ?></strong></small>
                    </div>
                    <div class="rounded-circle bg-warning bg-opacity-10 p-3 text-warning">
                        <i class="bi bi-hourglass-split fs-3"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card bg-white p-3 border-start border-4 border-success">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted fw-bold">✅ อนุมัติแล้ว (เดือนนี้)</small>
                        <div class="fs-2 fw-bold text-success"><?= number_format($kpi['approved_count'] ?? 0) ?> <span class="fs-6 fw-normal text-muted">รายการ</span></div>
                        <small class="text-secondary">ยอดเงินอนุมัติแล้ว: <strong>฿<?= number_format($kpi['approved_amount'] ?? 0, 2) ?></strong></small>
                    </div>
                    <div class="rounded-circle bg-success bg-opacity-10 p-3 text-success">
                        <i class="bi bi-check2-circle fs-3"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card bg-white p-3 border-start border-4 border-danger">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted fw-bold">❌ ปฏิเสธ / ส่งกลับแก้ไข</small>
                        <div class="fs-2 fw-bold text-danger"><?= number_format($kpi['rejected_count'] ?? 0) ?> <span class="fs-6 fw-normal text-muted">รายการ</span></div>
                        <small class="text-secondary">ข้อมูลไม่สมบูรณ์ / พิกัดไม่ตรง</small>
                    </div>
                    <div class="rounded-circle bg-danger bg-opacity-10 p-3 text-danger">
                        <i class="bi bi-x-circle fs-3"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card bg-white p-3 border-start border-4 border-primary">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted fw-bold">📊 ยอดเบิกจ่ายรวมสุทธิ</small>
                        <div class="fs-2 fw-bold text-primary">฿<?= number_format($kpi['approved_amount'] ?? 0, 2) ?></div>
                        <small class="text-secondary">ลงบัญชีค่าใช้จ่ายอัตโนมัติแล้ว</small>
                    </div>
                    <div class="rounded-circle bg-primary bg-opacity-10 p-3 text-primary">
                        <i class="bi bi-cash-stack fs-3"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ฟิลเตอร์ตัวกรองสถานะและเดือน -->
    <div class="card card-custom mb-4 bg-white">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-3">
                    <label class="form-label small fw-bold mb-1">ประจำเดือน</label>
                    <input type="month" name="month" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_month) ?>" onchange="this.form.submit()">
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-bold mb-1">สถานะการพิจารณา</label>
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>-- ทั้งหมด --</option>
                        <option value="submitted" <?= $filter_status === 'submitted' ? 'selected' : '' ?>>⏳ รออนุมัติ (Pending)</option>
                        <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>✅ อนุมัติแล้ว (Approved)</option>
                        <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>❌ ปฏิเสธ (Rejected)</option>
                    </select>
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold">
                        <i class="bi bi-funnel-fill me-1"></i> กรองรายการ
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ตารางรายการใบขอเบิก -->
    <div class="card card-custom bg-white">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="bi bi-list-check me-2 text-primary"></i>รายการคำขอเบิกค่าใช้จ่าย
            </h5>
            <small class="text-muted">พบทั้งหมด <?= count($claims) ?> รายการ</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-muted">
                        <tr>
                            <th class="ps-3">เลขที่ใบเบิก / วันที่</th>
                            <th>ผู้ขอเบิก / ยานพาหนะ</th>
                            <th>วัตถุประสงค์ & เส้นทาง</th>
                            <th class="text-center">จุดแวะ (GPS)</th>
                            <th class="text-end">ระยะทาง</th>
                            <th class="text-end">ยอดขอเบิก</th>
                            <th class="text-center">สถานะ</th>
                            <th class="text-center pe-3">ดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($claims)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-1 d-block text-secondary mb-2"></i>
                                ไม่พบรายการคำขอเบิกค่าใช้จ่ายตามเงื่อนไขที่เลือก
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($claims as $row): ?>
                        <tr>
                            <td class="ps-3">
                                <strong class="text-primary d-block">#CLAIM-<?= str_pad($row['claim_id'], 5, '0', STR_PAD_LEFT) ?></strong>
                                <small class="text-muted"><i class="bi bi-calendar-event me-1"></i><?= date('d/m/Y', strtotime($row['trip_date'])) ?></small>
                                <div class="text-secondary small font-monospace"><?= htmlspecialchars($row['trip_code'] ?? '') ?></div>
                            </td>
                            <td>
                                <strong class="text-dark d-block"><?= htmlspecialchars($row['officer_name'] ?? 'เจ้าหน้าที่') ?></strong>
                                <small class="text-muted d-block"><?= htmlspecialchars($row['officer_position'] ?? 'ฝ่ายการตลาด') ?></small>
                                <small class="text-secondary"><i class="bi bi-car-front me-1"></i><?= htmlspecialchars($row['vehicle_plate'] ?? '-') ?></small>
                            </td>
                            <td>
                                <div class="fw-bold text-dark small mb-1"><?= htmlspecialchars($row['purpose'] ?? '-') ?></div>
                                <div class="small text-muted" style="max-width: 280px;">
                                    <i class="bi bi-geo-alt text-success me-1"></i><?= htmlspecialchars($row['start_location'] ?? '-') ?>
                                    <br><i class="bi bi-flag text-danger me-1"></i><?= htmlspecialchars($row['end_location'] ?? '-') ?>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-dark border">
                                    <i class="bi bi-camera-fill text-info me-1"></i><?= number_format($row['waypoint_count'] ?? 0) ?> จุด
                                </span>
                            </td>
                            <td class="text-end fw-bold text-secondary">
                                <?= number_format($row['distance_km'] ?? 0, 2) ?> กม.
                                <small class="d-block text-muted fw-normal">
                                    <?= ($row['claim_type'] === 'zone') ? 'เหมาจ่ายโซน' : 'คิดตาม กม.' ?>
                                </small>
                            </td>
                            <td class="text-end">
                                <strong class="fs-6 text-success">฿<?= number_format($row['total_amount'] ?? 0, 2) ?></strong>
                                <?php if ($row['claim_type'] === 'distance'): ?>
                                <small class="text-muted d-block" style="font-size: 0.72rem;">
                                    น้ำมัน: ฿<?= number_format($row['fuel_amount'] ?? 0, 1) ?> | สึกหรอ: ฿<?= number_format($row['wear_amount'] ?? 0, 1) ?>
                                </small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($row['claim_status'] === 'approved'): ?>
                                    <span class="badge bg-success badge-status"><i class="bi bi-check-circle-fill me-1"></i>อนุมัติแล้ว</span>
                                    <small class="text-muted d-block mt-1" style="font-size: 0.7rem;"><?= htmlspecialchars($row['approved_by'] ?? '') ?></small>
                                <?php elseif ($row['claim_status'] === 'rejected'): ?>
                                    <span class="badge bg-danger badge-status"><i class="bi bi-x-circle-fill me-1"></i>ปฏิเสธ</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark badge-status"><i class="bi bi-hourglass-split me-1"></i>รออนุมัติ</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center pe-3">
                                <div class="btn-group btn-group-sm">
                                    <a href="https://sktbkk.seikouedu.com/travel/approve.php?id=<?= $row['claim_id'] ?>" 
                                       class="btn <?= ($row['claim_status'] === 'submitted') ? 'btn-success fw-bold' : 'btn-outline-primary' ?>"
                                       title="พิจารณาตรวจสอบพิกัด & อนุมัติ">
                                        <i class="bi bi-shield-check me-1"></i><?= ($row['claim_status'] === 'submitted') ? 'พิจารณา' : 'ดูข้อมูล' ?>
                                    </a>
                                    <?php if ($row['claim_status'] === 'approved'): ?>
                                    <a href="https://sktbkk.seikouedu.com/travel/print_voucher.php?claim_id=<?= $row['claim_id'] ?>" 
                                       target="_blank"
                                       class="btn btn-outline-secondary"
                                       title="พิมพ์ใบสำคัญจ่าย / ใบเบิก">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<footer class="mt-auto py-3 bg-white border-top text-center text-muted">
    <div class="container">
        <small>
            &copy; 2026 <strong>สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)</strong> | ระบบบริหารจัดการสต็อกและจุดขายข้าวสาร
        </small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
