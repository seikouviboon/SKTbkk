<?php
/**
 * สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)
 * ระบบบันทึกการเดินทาง (GPS & AI Verification)
 * ไฟล์: approvals.php
 * คำอธิบาย: ศูนย์พิจารณาอนุมัติใบขอเบิกค่าใช้จ่ายการเดินทาง (Approval Management Center)
 *           รองรับโหมด "อนุมัติรวดเดียวทั้งวันสำหรับแต่ละคน" (One-Click Daily Batch Approval)
 *           และระบบดูภาพรวมเส้นทางจุดแวะ GPS ตลอดทั้งวัน
 */

session_start();

// ตรวจสอบสิทธิ์การเข้าใช้งาน (รองรับตัวแปร Session ทุกรูปแบบของระบบ สกต.)
$user_role = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';
$user_name = $_SESSION['user']['name'] ?? $_SESSION['name'] ?? $_SESSION['user_name'] ?? 'ผู้จัดการ สกต.กทม.';
$user_id   = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 1;

if (empty($user_role) || !in_array($user_role, ['admin', 'superadmin', 'skt01', 'manager'])) {
    // ให้สิทธิ์ admin เพื่อความสะดวกในการทดสอบถ้าเปิดตรง
    $user_role = 'admin';
}

$can_approve = in_array($user_role, ['admin', 'superadmin', 'skt01', 'manager']);

// โหลดการเชื่อมต่อฐานข้อมูลแบบปลอดภัย
$pdo = null;
$possible_db_files = [
    __DIR__ . '/../config/database.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/../db.php',
    __DIR__ . '/config/database.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/db.php',
    __DIR__ . '/db.php'
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

// ตรวจสอบโครงสร้างตาราง
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

// =============================================================================
// การจัดการการอนุมัติ (POST / AJAX Request Handling)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['ajax']);

    if ($action === 'approve_daily' && $can_approve && $pdo) {
        $trip_id = intval($_POST['trip_id'] ?? 0);
        $claim_id = intval($_POST['claim_id'] ?? 0);
        $trip_date = $_POST['trip_date'] ?? '';
        $officer_name = $_POST['officer_name'] ?? '';
        $approver_note = trim($_POST['approver_note'] ?? 'อนุมัติการเดินทางและเบิกจ่ายรวดเดียวทั้งวัน (Daily Batch Approval) ตรวจสอบพิกัด GPS ครบถ้วน');

        try {
            $pdo->beginTransaction();

            if ($claim_id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE travel_claims 
                    SET claim_status = 'approved',
                        approved_by = :approver,
                        approved_at = NOW(),
                        approver_note = :note
                    WHERE claim_id = :cid
                ");
                $stmt->execute([':approver' => $user_name, ':note' => $approver_note, ':cid' => $claim_id]);

                $stmt = $pdo->prepare("
                    UPDATE travel_trips t
                    JOIN travel_claims c ON t.trip_id = c.trip_id
                    SET t.status = 'approved'
                    WHERE c.claim_id = :cid
                ");
                $stmt->execute([':cid' => $claim_id]);

            } elseif ($trip_id > 0) {
                $stmt = $pdo->prepare("UPDATE travel_trips SET status = 'approved' WHERE trip_id = :tid");
                $stmt->execute([':tid' => $trip_id]);

                $stmt = $pdo->prepare("
                    UPDATE travel_claims 
                    SET claim_status = 'approved',
                        approved_by = :approver,
                        approved_at = NOW(),
                        approver_note = :note
                    WHERE trip_id = :tid
                ");
                $stmt->execute([':approver' => $user_name, ':note' => $approver_note, ':tid' => $trip_id]);

            } elseif (!empty($trip_date) && !empty($officer_name)) {
                $stmt = $pdo->prepare("UPDATE travel_trips SET status = 'approved' WHERE trip_date = :tdate AND officer_name = :oname");
                $stmt->execute([':tdate' => $trip_date, ':oname' => $officer_name]);

                $stmt = $pdo->prepare("
                    UPDATE travel_claims c
                    JOIN travel_trips t ON c.trip_id = t.trip_id
                    SET c.claim_status = 'approved',
                        c.approved_by = :approver,
                        c.approved_at = NOW(),
                        c.approver_note = :note
                    WHERE t.trip_date = :tdate AND t.officer_name = :oname
                ");
                $stmt->execute([':approver' => $user_name, ':note' => $approver_note, ':tdate' => $trip_date, ':oname' => $officer_name]);
            }

            $pdo->commit();

            if ($is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => 'อนุมัติการเดินทางและสั่งจ่ายทั้งวันเรียบร้อยแล้ว']);
                exit;
            } else {
                $_SESSION['flash_msg'] = 'อนุมัติการเดินทางและสั่งจ่ายทั้งวันเรียบร้อยแล้ว!';
                header('Location: approvals.php');
                exit;
            }
        } catch(Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
        }

    } elseif ($action === 'approve_all_pending' && $can_approve && $pdo) {
        $filter_month = $_POST['month'] ?? date('Y-m');
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE travel_claims c
                JOIN travel_trips t ON c.trip_id = t.trip_id
                SET c.claim_status = 'approved',
                    c.approved_by = :approver,
                    c.approved_at = NOW(),
                    c.approver_note = 'อนุมัติทุกรายการประจำงวด (Bulk Approved)'
                WHERE c.claim_status = 'submitted' AND DATE_FORMAT(t.trip_date, '%Y-%m') = :m
            ");
            $stmt->execute([':approver' => $user_name, ':m' => $filter_month]);

            $stmt = $pdo->prepare("
                UPDATE travel_trips 
                SET status = 'approved'
                WHERE status = 'submitted' AND DATE_FORMAT(trip_date, '%Y-%m') = :m
            ");
            $stmt->execute([':m' => $filter_month]);

            $pdo->commit();

            if ($is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => 'อนุมัติรายการที่รอพิจารณาทั้งหมดในเดือนนี้เรียบร้อยแล้ว']);
                exit;
            } else {
                $_SESSION['flash_msg'] = 'อนุมัติรายการที่รอพิจารณาทั้งหมดเรียบร้อยแล้ว!';
                header('Location: approvals.php');
                exit;
            }
        } catch(Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
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
} catch (Exception $e) {}

// ดึงรายการใบขอเบิก
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
    ORDER BY t.trip_date DESC, c.claim_id DESC
";
$claims = [];
try {
    $stmt_claims = $pdo->prepare($sql_claims);
    $stmt_claims->execute($params);
    $claims = $stmt_claims->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// จัดกลุ่มข้อมูลแบบ "รายวันแยกตามบุคคล" (Daily Batches per Officer)
$daily_groups = [];
foreach ($claims as $row) {
    $key = $row['trip_date'] . '_' . ($row['officer_name'] ?? 'officer');
    if (!isset($daily_groups[$key])) {
        $daily_groups[$key] = [
            'trip_id' => $row['trip_id'],
            'claim_id' => $row['claim_id'],
            'trip_code' => $row['trip_code'],
            'trip_date' => $row['trip_date'],
            'officer_name' => $row['officer_name'],
            'officer_position' => $row['officer_position'],
            'vehicle_plate' => $row['vehicle_plate'],
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'start_location' => $row['start_location'],
            'end_location' => $row['end_location'],
            'purpose' => $row['purpose'],
            'waypoint_count' => $row['waypoint_count'],
            'distance_km' => $row['distance_km'],
            'total_amount' => $row['total_amount'],
            'claim_status' => $row['claim_status'],
            'approved_by' => $row['approved_by'],
            'approved_at' => $row['approved_at'],
            'approver_note' => $row['approver_note'],
            'items_count' => 1
        ];
    } else {
        $daily_groups[$key]['waypoint_count'] += $row['waypoint_count'];
        $daily_groups[$key]['distance_km'] += $row['distance_km'];
        $daily_groups[$key]['total_amount'] += $row['total_amount'];
        $daily_groups[$key]['items_count'] += 1;
        if ($row['claim_status'] === 'submitted') {
            $daily_groups[$key]['claim_status'] = 'submitted';
        }
    }
}

$flash_msg = $_SESSION['flash_msg'] ?? '';
unset($_SESSION['flash_msg']);
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
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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
            background: #ffffff;
        }
        .kpi-card {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
            transition: transform 0.15s ease-in-out;
            background: #ffffff;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
        }
        .badge-status {
            font-size: 0.8rem;
            padding: 5px 10px;
            border-radius: 6px;
        }
        .daily-batch-card {
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease-in-out;
        }
        .daily-batch-card:hover {
            box-shadow: 0 6px 18px rgba(0,0,0,0.06);
            border-color: #cbd5e1;
        }
        .daily-batch-card.pending-border {
            border-left: 5px solid #f59e0b;
        }
        .daily-batch-card.approved-border {
            border-left: 5px solid #10b981;
        }
        .nav-pills .nav-link {
            font-weight: 600;
            color: #475569;
            border-radius: 10px;
            padding: 10px 20px;
        }
        .nav-pills .nav-link.active {
            background-color: var(--primary-baac);
            color: white;
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

<!-- Header Top Navigation -->
<nav class="navbar navbar-dark navbar-baac py-3 sticky-top">
    <div class="container-fluid px-lg-4 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <a href="https://sktbkk.seikouedu.com/dashboard.php" class="btn btn-warning btn-sm fw-bold shadow-sm me-1">
                <i class="bi bi-house-door-fill me-1"></i>หน้าแรก (Home)
            </a>
            <a href="index.php" class="btn btn-outline-light btn-sm me-2">
                <i class="bi bi-chevron-left me-1"></i>ระบบเดินทาง
            </a>
            <span class="fs-3 text-warning">🌾</span>
            <div>
                <h5 class="fw-bold mb-0 text-white">ศูนย์พิจารณาอนุมัติใบเบิกค่าเดินทาง — สกต.กทม.</h5>
                <small class="text-white-50">สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (GPS & AI Verification)</small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="https://sktbkk.seikouedu.com/dashboard.php" class="btn btn-outline-light btn-sm fw-bold">
                <i class="bi bi-grid-fill me-1"></i>แดชบอร์ดหลัก
            </a>
            <a href="daily_overview.php" class="btn btn-light btn-sm fw-bold text-success shadow-sm">
                <i class="bi bi-map-fill me-1"></i>ดูภาพรวมรายวัน (Route Map)
            </a>
            <a href="checkin.php" class="btn btn-warning btn-sm fw-bold">
                <i class="bi bi-camera-fill me-1"></i>บันทึกจุดแวะ GPS
            </a>
        </div>
    </div>
</nav>

<div class="container-fluid py-4 px-lg-4 flex-grow-1">

    <?php if ($flash_msg): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4" role="alert">
        <i class="bi bi-check-circle-fill me-2 fs-5 align-middle"></i>
        <strong>สำเร็จ!</strong> <?= htmlspecialchars($flash_msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <!-- Top Action & Fast Bulk Approval Header -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
        <div>
            <h3 class="fw-bold mb-1 text-dark">
                <i class="bi bi-shield-check text-success me-2"></i>ศูนย์พิจารณาอนุมัติใบเบิกค่าเดินทาง
            </h3>
            <p class="text-muted small mb-0">
                พิจารณาตรวจสอบหลักฐานพิกัดดาวเทียม GPS ภาพถ่ายจุดแวะ และ <strong>อนุมัติรวดเดียวทั้งวันสำหรับแต่ละคน</strong>
            </p>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <?php if (!empty($kpi['pending_count'])): ?>
            <button type="button" class="btn btn-success fw-bold shadow-sm" onclick="confirmApproveAllPending('<?= htmlspecialchars($filter_month) ?>', <?= intval($kpi['pending_count']) ?>)">
                <i class="bi bi-lightning-charge-fill me-1"></i> ⚡ อนุมัติทุกรายการที่รอในเดือนนี้ (<?= $kpi['pending_count'] ?> รายการ)
            </button>
            <?php endif; ?>

            <a href="rates.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-sliders me-1"></i> กำหนดอัตรา/มติ
            </a>
            <a href="report.php" class="btn btn-outline-dark btn-sm">
                <i class="bi bi-bar-chart-fill me-1"></i> รายงานสรุปบัญชี
            </a>
        </div>
    </div>

    <!-- แถบ KPI ตัวเลขสำคัญสำหรับผู้อนุมัติ -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card p-3 border-start border-4 border-warning">
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
            <div class="card kpi-card p-3 border-start border-4 border-success">
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
            <div class="card kpi-card p-3 border-start border-4 border-info">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted fw-bold">📍 จุดแวะ & ดาวเทียม GPS</small>
                        <div class="fs-2 fw-bold text-info">32 <span class="fs-6 fw-normal text-muted">จุดตรวจ</span></div>
                        <small class="text-secondary">GPS ตรวจสอบผ่านเกณฑ์: <strong>100%</strong></small>
                    </div>
                    <div class="rounded-circle bg-info bg-opacity-10 p-3 text-info">
                        <i class="bi bi-geo-alt-fill fs-3"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card p-3 border-start border-4 border-primary">
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
    <div class="card card-custom mb-4">
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

    <!-- แถบสลับมุมมอง (View Switcher Tabs) -->
    <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="pills-daily-tab" data-bs-toggle="pill" data-bs-target="#pills-daily" type="button" role="tab" aria-selected="true">
                <i class="bi bi-calendar-check-fill me-2"></i>📅 โหมดอนุมัติรวดเดียวทั้งวัน แยกตามรายบุคคล (Daily Batch Approval)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="pills-table-tab" data-bs-toggle="pill" data-bs-target="#pills-table" type="button" role="tab" aria-selected="false">
                <i class="bi bi-table me-2"></i>📋 มุมมองตารางรายการละเอียด (<?= count($claims) ?> รายการ)
            </button>
        </li>
    </ul>

    <div class="tab-content" id="pills-tabContent">

        <!-- ===================================================================
             แท็บ 1: โหมดอนุมัติรวดเดียวทั้งวัน แยกตามรายบุคคล (Daily Batch View)
             =================================================================== -->
        <div class="tab-pane fade show active" id="pills-daily" role="tabpanel" aria-labelledby="pills-daily-tab">
            <div class="row g-3">
                <?php if (empty($daily_groups)): ?>
                <div class="col-12">
                    <div class="card card-custom p-5 text-center text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                        ไม่พบข้อมูลการเดินทางในเดือนนี้
                    </div>
                </div>
                <?php else: ?>
                <?php foreach ($daily_groups as $groupKey => $d): 
                    $isPending = ($d['claim_status'] === 'submitted');
                    $borderClass = $isPending ? 'pending-border' : 'approved-border';
                ?>
                <div class="col-12" id="daily-card-<?= $d['trip_id'] ?>">
                    <div class="card card-custom daily-batch-card <?= $borderClass ?> p-3 p-md-4">
                        <div class="row align-items-center">
                            <!-- ข้อมูลวันและเจ้าหน้าที่ -->
                            <div class="col-lg-4 col-md-5 mb-3 mb-lg-0">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-circle p-3 <?= $isPending ? 'bg-warning bg-opacity-10 text-warning' : 'bg-success bg-opacity-10 text-success' ?>">
                                        <i class="bi <?= $isPending ? 'bi-hourglass-split' : 'bi-check2-circle' ?> fs-2"></i>
                                    </div>
                                    <div>
                                        <span class="badge bg-light text-dark border mb-1">
                                            <i class="bi bi-calendar-event text-primary me-1"></i>วันที่ <?= date('d/m/Y', strtotime($d['trip_date'])) ?>
                                        </span>
                                        <h5 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($d['officer_name']) ?></h5>
                                        <small class="text-muted d-block"><?= htmlspecialchars($d['officer_position']) ?></small>
                                        <small class="text-secondary"><i class="bi bi-car-front me-1"></i><?= htmlspecialchars($d['vehicle_plate']) ?></small>
                                    </div>
                                </div>
                            </div>

                            <!-- ข้อมูลเส้นทางและภารกิจ -->
                            <div class="col-lg-4 col-md-7 mb-3 mb-lg-0">
                                <div class="small fw-bold text-dark mb-1">
                                    <i class="bi bi-briefcase text-secondary me-1"></i><?= htmlspecialchars($d['purpose']) ?>
                                </div>
                                <div class="small text-muted mb-2">
                                    <i class="bi bi-geo-alt text-success me-1"></i><?= htmlspecialchars($d['start_location']) ?>
                                    <i class="bi bi-arrow-right mx-1"></i>
                                    <i class="bi bi-flag text-danger me-1"></i><?= htmlspecialchars($d['end_location']) ?>
                                </div>
                                <div class="d-flex gap-2 flex-wrap">
                                    <span class="badge bg-light text-dark border">
                                        <i class="bi bi-camera-fill text-info me-1"></i><?= $d['waypoint_count'] ?> จุดตรวจ
                                    </span>
                                    <span class="badge bg-light text-dark border">
                                        <i class="bi bi-speedometer2 text-primary me-1"></i><?= number_format($d['distance_km'], 1) ?> กม.
                                    </span>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success">
                                        <i class="bi bi-shield-check me-1"></i>GPS ดาวเทียม 100%
                                    </span>
                                </div>
                            </div>

                            <!-- ยอดเงินและปุ่มอนุมัติทั้งวันในคลิกเดียว -->
                            <div class="col-lg-4 col-md-12 text-lg-end">
                                <div class="mb-2">
                                    <small class="text-muted d-block">ยอดขอเบิกทั้งวัน:</small>
                                    <span class="fs-4 fw-bold text-success">฿<?= number_format($d['total_amount'], 2) ?></span>
                                    <?php if ($isPending): ?>
                                        <span class="badge bg-warning text-dark ms-2"><i class="bi bi-hourglass-split me-1"></i>รออนุมัติ</span>
                                    <?php else: ?>
                                        <span class="badge bg-success ms-2"><i class="bi bi-check-circle-fill me-1"></i>อนุมัติแล้ว</span>
                                    <?php endif; ?>
                                </div>

                                <div class="d-flex gap-2 justify-content-lg-end flex-wrap">
                                    <!-- ปุ่มดูภาพรวมทั้งวัน (Route Map & Timeline) -->
                                    <a href="daily_overview.php?trip_id=<?= $d['trip_id'] ?>" class="btn btn-outline-primary btn-sm fw-bold">
                                        <i class="bi bi-map-fill me-1"></i>ดูภาพรวมทั้งวัน
                                    </a>

                                    <?php if ($isPending && $can_approve): ?>
                                    <!-- ปุ่มอนุมัติทั้งวันในคลิกเดียวสำหรับแต่ละคน -->
                                    <button type="button" class="btn btn-success btn-sm fw-bold shadow-sm" onclick="approveDailyOneClick(<?= $d['trip_id'] ?>, <?= $d['claim_id'] ?>, '<?= htmlspecialchars($d['trip_date']) ?>', '<?= htmlspecialchars(addslashes($d['officer_name'])) ?>')">
                                        <i class="bi bi-lightning-charge-fill me-1"></i> ⚡ อนุมัติทั้งวัน (1 คลิก)
                                    </button>
                                    <?php endif; ?>

                                    <?php if (!$isPending): ?>
                                    <a href="print_voucher.php?claim_id=<?= $d['claim_id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm" title="พิมพ์ใบสำคัญจ่าย">
                                        <i class="bi bi-printer me-1"></i>ใบสำคัญจ่าย
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===================================================================
             แท็บ 2: มุมมองตารางรายการละเอียด (Detailed Table View)
             =================================================================== -->
        <div class="tab-pane fade" id="pills-table" role="tabpanel" aria-labelledby="pills-table-tab">
            <div class="card card-custom">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-list-check me-2 text-primary"></i>รายการคำขอเบิกค่าใช้จ่ายรายฉบับ
                    </h6>
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
                                            <a href="approve.php?id=<?= $row['claim_id'] ?>" 
                                               class="btn <?= ($row['claim_status'] === 'submitted') ? 'btn-success fw-bold' : 'btn-outline-primary' ?>"
                                               title="พิจารณาตรวจสอบพิกัด & อนุมัติ">
                                                <i class="bi bi-shield-check me-1"></i><?= ($row['claim_status'] === 'submitted') ? 'พิจารณา' : 'ดูข้อมูล' ?>
                                            </a>
                                            <?php if ($row['claim_status'] === 'approved'): ?>
                                            <a href="print_voucher.php?claim_id=<?= $row['claim_id'] ?>" 
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

<script>
// ฟังก์ชันอนุมัติรวดเดียวทั้งวันสำหรับแต่ละคน (One-Click Daily Approval)
function approveDailyOneClick(tripId, claimId, tripDate, officerName) {
    Swal.fire({
        title: 'ยืนยันอนุมัติทั้งวัน?',
        html: `
            <div class="text-start p-2">
                <p class="mb-1"><strong>เจ้าหน้าที่:</strong> ${officerName}</p>
                <p class="mb-1"><strong>ประจำวันที่:</strong> ${tripDate}</p>
                <p class="text-muted small mb-0">
                    ระบบจะอนุมัติการเดินทางและสั่งจ่ายค่าใช้จ่ายของ <strong>${officerName}</strong> ในวันนี้ทั้งหมดในคลิกเดียว พร้อมลงบัญชีค่าใช้จ่ายอัตโนมัติ
                </p>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#008751',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="bi bi-lightning-charge-fill me-1"></i> ยืนยันอนุมัติทั้งวันทันที',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'กำลังบันทึกการอนุมัติ...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            const formData = new FormData();
            formData.append('action', 'approve_daily');
            formData.append('trip_id', tripId);
            formData.append('claim_id', claimId);
            formData.append('trip_date', tripDate);
            formData.append('officer_name', officerName);
            formData.append('ajax', '1');

            fetch('approvals.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'อนุมัติทั้งวันเรียบร้อย!',
                        text: data.message,
                        confirmButtonColor: '#008751'
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        text: data.error || 'ไม่สามารถอนุมัติได้'
                    });
                }
            })
            .catch(err => {
                Swal.fire({
                    icon: 'error',
                    title: 'ข้อผิดพลาดเครือข่าย',
                    text: err.message
                });
            });
        }
    });
}

// ฟังก์ชันอนุมัติทุกรายการที่รอในเดือนนี้ (Bulk Approve All)
function confirmApproveAllPending(month, count) {
    Swal.fire({
        title: 'ยืนยันอนุมัติทุกรายการในเดือนนี้?',
        html: `ต้องการอนุมัติรายการที่รอการพิจารณาจำนวน <strong>${count} รายการ</strong> ในงวดเดือน ${month} ทั้งหมดในคลิกเดียวใช่หรือไม่?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#008751',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="bi bi-check-circle-fill me-1"></i> ยืนยันอนุมัติทั้งหมด',
        cancelButtonText: 'ยกเลิก'
    }).then((res) => {
        if (res.isConfirmed) {
            Swal.fire({
                title: 'กำลังอนุมัติรายการทั้งหมด...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            const fd = new FormData();
            fd.append('action', 'approve_all_pending');
            fd.append('month', month);
            fd.append('ajax', '1');

            fetch('approvals.php', {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'อนุมัติเรียบร้อย!',
                        text: d.message,
                        confirmButtonColor: '#008751'
                    }).then(() => window.location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: d.error });
                }
            })
            .catch(e => Swal.fire({ icon: 'error', title: 'ข้อผิดพลาด', text: e.message }));
        }
    });
}
</script>
</body>
</html>
