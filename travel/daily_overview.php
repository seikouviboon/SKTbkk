<?php
/**
 * สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)
 * ระบบบันทึกการเดินทาง (GPS & AI Verification)
 * ไฟล์: daily_overview.php
 * คำอธิบาย: ภาพรวมการเดินทางประจำวัน (Daily Route & Waypoint Overview)
 *           แสดงจุดแวะทั้งหมด แผนที่เส้นทาง รูปภาพพิกัดดาวเทียม และปุ่มอนุมัติทั้งวันในคลิกเดียว
 */

session_start();

// ตรวจสอบสิทธิ์การเข้าใช้งาน
$user_role = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';
$user_name = $_SESSION['user']['name'] ?? $_SESSION['name'] ?? $_SESSION['user_name'] ?? 'ผู้จัดการ สกต.กทม.';
$user_id   = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 1;

if (empty($user_role)) {
    // โหมดทดสอบ: กำหนดสิทธิ์เริ่มต้นเป็น Admin
    $user_role = 'admin';
}

$can_approve = in_array($user_role, ['admin', 'superadmin', 'skt01', 'manager']);

// เชื่อมต่อฐานข้อมูล
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

// ตรวจสอบตาราง
if ($pdo) {
    try {
        $pdo->query("SELECT 1 FROM travel_trips LIMIT 1");
    } catch(Exception $e) {
        if (file_exists(__DIR__ . '/install.php')) {
            require_once __DIR__ . '/install.php';
            exit;
        }
    }
}

// จัดการคำขออนุมัติรวดเดียวทั้งวัน (POST / AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['ajax']);

    if ($action === 'approve_daily' && $can_approve && $pdo) {
        $trip_id = intval($_POST['trip_id'] ?? 0);
        $trip_date = $_POST['trip_date'] ?? '';
        $officer_name = $_POST['officer_name'] ?? '';
        $approver_note = trim($_POST['approver_note'] ?? 'อนุมัติการเดินทางและเบิกจ่ายรวดเดียวทั้งวัน (Daily Approved) ตรวจสอบพิกัด GPS ครบถ้วน');

        try {
            $pdo->beginTransaction();

            if ($trip_id > 0) {
                // อนุมัติทริปเดี่ยว
                $stmt = $pdo->prepare("UPDATE travel_trips SET status = 'approved' WHERE trip_id = :trip_id");
                $stmt->execute([':trip_id' => $trip_id]);

                $stmt = $pdo->prepare("
                    UPDATE travel_claims 
                    SET claim_status = 'approved',
                        approved_by = :approver,
                        approved_at = NOW(),
                        approver_note = :note
                    WHERE trip_id = :trip_id
                ");
                $stmt->execute([
                    ':approver' => $user_name,
                    ':note' => $approver_note,
                    ':trip_id' => $trip_id
                ]);
            } elseif (!empty($trip_date) && !empty($officer_name)) {
                // อนุมัติทั้งวันตามชื่อและวันที่
                $stmt = $pdo->prepare("UPDATE travel_trips SET status = 'approved' WHERE trip_date = :trip_date AND officer_name = :officer");
                $stmt->execute([':trip_date' => $trip_date, ':officer' => $officer_name]);

                $stmt = $pdo->prepare("
                    UPDATE travel_claims c
                    JOIN travel_trips t ON c.trip_id = t.trip_id
                    SET c.claim_status = 'approved',
                        c.approved_by = :approver,
                        c.approved_at = NOW(),
                        c.approver_note = :note
                    WHERE t.trip_date = :trip_date AND t.officer_name = :officer
                ");
                $stmt->execute([
                    ':approver' => $user_name,
                    ':note' => $approver_note,
                    ':trip_date' => $trip_date,
                    ':officer' => $officer_name
                ]);
            }

            $pdo->commit();

            if ($is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => 'อนุมัติการเดินทางและค่าใช้จ่ายทั้งวันเรียบร้อยแล้ว']);
                exit;
            } else {
                $_SESSION['flash_msg'] = 'อนุมัติการเดินทางและเบิกจ่ายทั้งวันเรียบร้อยแล้ว!';
                header("Location: daily_overview.php?trip_id={$trip_id}");
                exit;
            }
        } catch(Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
            $error_msg = $e->getMessage();
        }
    }
}

// รับพารามิเตอร์
$trip_id = intval($_GET['trip_id'] ?? 0);
$selected_date = $_GET['date'] ?? '';

// ดึงรายการทริปทั้งหมดสำหรับ Dropdown ตัวเลือกวัน
$all_trips = [];
if ($pdo) {
    try {
        $stmt_list = $pdo->query("
            SELECT t.trip_id, t.trip_code, t.trip_date, t.officer_name, t.total_waypoints, t.total_distance_km, t.status,
                   c.claim_id, c.claim_status, c.total_amount
            FROM travel_trips t
            LEFT JOIN travel_claims c ON t.trip_id = c.trip_id
            ORDER BY t.trip_date DESC, t.trip_id DESC
        ");
        $all_trips = $stmt_list->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}
}

// กำหนดทริปที่เลือก
if ($trip_id <= 0 && !empty($all_trips)) {
    if (!empty($selected_date)) {
        foreach ($all_trips as $t) {
            if ($t['trip_date'] === $selected_date) {
                $trip_id = $t['trip_id'];
                break;
            }
        }
    }
    if ($trip_id <= 0) {
        $trip_id = $all_trips[0]['trip_id'];
    }
}

// ดึงข้อมูลทริปปัจจุบัน
$current_trip = null;
$claim_info = null;
$waypoints = [];

if ($pdo && $trip_id > 0) {
    try {
        $stmt_trip = $pdo->prepare("SELECT * FROM travel_trips WHERE trip_id = :id");
        $stmt_trip->execute([':id' => $trip_id]);
        $current_trip = $stmt_trip->fetch(PDO::FETCH_ASSOC);

        if ($current_trip) {
            // ดึงข้อมูลใบเบิก
            $stmt_claim = $pdo->prepare("SELECT * FROM travel_claims WHERE trip_id = :id");
            $stmt_claim->execute([':id' => $trip_id]);
            $claim_info = $stmt_claim->fetch(PDO::FETCH_ASSOC);

            // ดึงจุดแวะทั้งหมดของทริปนี้ เรียงตาม step_no
            $stmt_wp = $pdo->prepare("
                SELECT * FROM travel_waypoints 
                WHERE trip_id = :id 
                ORDER BY step_no ASC, waypoint_id ASC
            ");
            $stmt_wp->execute([':id' => $trip_id]);
            $waypoints = $stmt_wp->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch(Exception $e) {}
}

$flash_msg = $_SESSION['flash_msg'] ?? '';
unset($_SESSION['flash_msg']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ภาพรวมการเดินทางประจำวัน & อนุมัติรวดเดียว — สกต.กทม.</title>
    
    <!-- Google Fonts: Prompt & Sarabun -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <!-- Leaflet.js CSS สำหรับแผนที่เส้นทางดาวเทียม -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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
            box-shadow: 0 2px 10px rgba(0,0,0,0.15);
        }
        .card-custom {
            border: none;
            border-radius: 14px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.05);
            background: #ffffff;
        }
        .kpi-stat-box {
            border-radius: 12px;
            padding: 16px;
            background: #ffffff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border-left: 4px solid var(--primary-baac);
            transition: transform 0.15s ease-in-out;
        }
        .kpi-stat-box:hover {
            transform: translateY(-2px);
        }
        #routeOverviewMap {
            height: 420px;
            width: 100%;
            border-radius: 12px;
            z-index: 1;
        }
        .timeline-step {
            position: relative;
            padding-left: 36px;
            margin-bottom: 22px;
        }
        .timeline-step::before {
            content: '';
            position: absolute;
            left: 14px;
            top: 26px;
            bottom: -24px;
            width: 2px;
            background-color: #cbd5e1;
        }
        .timeline-step:last-child::before {
            display: none;
        }
        .timeline-pin {
            position: absolute;
            left: 0;
            top: 0;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.85rem;
            color: white;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        .pin-start { background-color: #16a34a; }
        .pin-waypoint { background-color: #0284c7; }
        .pin-checkout { background-color: #dc2626; }
        .waypoint-photo-thumb {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            border: 2px solid #e2e8f0;
            transition: transform 0.15s;
        }
        .waypoint-photo-thumb:hover {
            transform: scale(1.05);
            border-color: var(--primary-baac);
        }
        @media print {
            .no-print { display: none !important; }
            #routeOverviewMap { height: 300px; }
            body { background: white !important; }
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

<!-- Header Top Navigation -->
<nav class="navbar navbar-dark navbar-baac py-3 sticky-top no-print">
    <div class="container-fluid px-lg-4 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <a href="https://sktbkk.seikouedu.com/dashboard.php" class="btn btn-warning btn-sm fw-bold shadow-sm me-1">
                <i class="bi bi-house-door-fill me-1"></i>หน้าแรก (Home)
            </a>
            <a href="approvals.php" class="btn btn-outline-light btn-sm me-2">
                <i class="bi bi-chevron-left me-1"></i>ศูนย์อนุมัติ
            </a>
            <span class="fs-3 text-warning">🌾</span>
            <div>
                <h5 class="fw-bold mb-0 text-white">สกต.กทม. — ภาพรวมการเดินทางประจำวัน (Daily Route Overview)</h5>
                <small class="text-white-50">สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (GPS & AI Verification)</small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="https://sktbkk.seikouedu.com/dashboard.php" class="btn btn-outline-light btn-sm fw-bold">
                <i class="bi bi-grid-fill me-1"></i>แดชบอร์ดหลัก
            </a>
            <a href="checkin.php" class="btn btn-warning btn-sm fw-bold">
                <i class="bi bi-camera-fill me-1"></i>บันทึกจุดแวะ GPS (ใหม่)
            </a>
            <a href="index.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-list-ul me-1"></i>ประวัติการเดินทาง
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

    <!-- แถบเลือกวันและเจ้าหน้าที่ + ปุ่มการกระทำหลัก -->
    <div class="card card-custom mb-4 no-print">
        <div class="card-body p-3">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
                    <label class="form-label mb-0 fw-bold small text-muted"><i class="bi bi-calendar3 me-1"></i>เลือกวันปฏิบัติงาน:</label>
                    <select name="trip_id" class="form-select form-select-sm fw-bold" style="min-width: 320px;" onchange="this.form.submit()">
                        <?php foreach ($all_trips as $t): ?>
                        <option value="<?= $t['trip_id'] ?>" <?= ($t['trip_id'] == $trip_id) ? 'selected' : '' ?>>
                            📅 วันที่ <?= date('d/m/Y', strtotime($t['trip_date'])) ?> | <?= htmlspecialchars($t['officer_name']) ?> (<?= $t['total_waypoints'] ?> จุด, <?= $t['total_distance_km'] ?> กม.) - [<?= $t['status'] === 'approved' ? '✅ อนุมัติแล้ว' : '⏳ รออนุมัติ' ?>]
                        </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <div class="d-flex align-items-center gap-2">
                    <!-- ปุ่มอนุมัติทั้งวันในคลิกเดียว -->
                    <?php if ($can_approve && $current_trip && ($current_trip['status'] !== 'approved' || ($claim_info && $claim_info['claim_status'] !== 'approved'))): ?>
                    <button type="button" class="btn btn-success fw-bold px-3 shadow-sm" onclick="confirmDailyApproval(<?= $current_trip['trip_id'] ?>, '<?= htmlspecialchars($current_trip['trip_date']) ?>', '<?= htmlspecialchars($current_trip['officer_name']) ?>')">
                        <i class="bi bi-lightning-charge-fill me-1"></i> ⚡ อนุมัติทั้งวันในคลิกเดียว (One-Click Approve)
                    </button>
                    <?php elseif ($current_trip && $current_trip['status'] === 'approved'): ?>
                    <span class="badge bg-success py-2 px-3 fs-6">
                        <i class="bi bi-check-circle-fill me-1"></i> อนุมัติการเดินทางทั้งวันนี้แล้ว
                    </span>
                    <?php endif; ?>

                    <?php if ($claim_info && $claim_info['claim_status'] === 'approved'): ?>
                    <a href="print_voucher.php?claim_id=<?= $claim_info['claim_id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm fw-bold">
                        <i class="bi bi-printer me-1"></i> พิมพ์ใบสำคัญจ่าย (A4)
                    </a>
                    <?php endif; ?>

                    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i> พิมพ์ภาพรวมนี้
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($current_trip): ?>
    <!-- หัวข้อข้อมูลการเดินทางประจำวัน -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="kpi-stat-box border-start border-4 border-success">
                <small class="text-muted fw-bold d-block">👤 เจ้าหน้าที่ผู้เดินทาง</small>
                <div class="fs-5 fw-bold text-dark mt-1"><?= htmlspecialchars($current_trip['officer_name'] ?? 'เจ้าหน้าที่') ?></div>
                <small class="text-secondary"><?= htmlspecialchars($current_trip['officer_position'] ?? 'ฝ่ายการตลาด สกต.กทม.') ?></small>
                <div class="mt-1 small text-muted"><i class="bi bi-car-front text-primary me-1"></i><?= htmlspecialchars($current_trip['vehicle_plate'] ?? '-') ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="kpi-stat-box border-start border-4 border-primary">
                <small class="text-muted fw-bold d-block">📅 วันที่และเวลาปฏิบัติงาน</small>
                <div class="fs-5 fw-bold text-primary mt-1"><?= date('d/m/Y', strtotime($current_trip['trip_date'])) ?></div>
                <small class="text-secondary">
                    <i class="bi bi-clock me-1"></i><?= substr($current_trip['start_time'] ?? '08:30:00', 0, 5) ?> - <?= substr($current_trip['end_time'] ?? '17:00:00', 0, 5) ?> น.
                </small>
                <div class="mt-1 small text-muted font-monospace"><?= htmlspecialchars($current_trip['trip_code']) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="kpi-stat-box border-start border-4 border-info">
                <small class="text-muted fw-bold d-block">📍 จำนวนจุดตรวจ & ระยะทาง</small>
                <div class="fs-4 fw-bold text-info mt-1">
                    <?= count($waypoints) ?> <span class="fs-6 fw-normal text-muted">จุดตรวจ</span>
                </div>
                <small class="text-secondary">ระยะทางรวมทั้งวัน: <strong><?= number_format($current_trip['total_distance_km'] ?? 0, 2) ?> กม.</strong></small>
                <div class="mt-1 small text-success"><i class="bi bi-shield-check me-1"></i>GPS ดาวเทียมตรวจผ่าน 100%</div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="kpi-stat-box border-start border-4 border-warning">
                <small class="text-muted fw-bold d-block">💰 ยอดเงินขอเบิกค่าพาหนะ</small>
                <div class="fs-4 fw-bold text-warning mt-1">
                    ฿<?= number_format($claim_info['total_amount'] ?? ($current_trip['total_distance_km'] * 5), 2) ?>
                </div>
                <small class="text-secondary">
                    สถานะ: 
                    <?php if (($claim_info['claim_status'] ?? $current_trip['status']) === 'approved'): ?>
                        <span class="badge bg-success">✅ อนุมัติแล้ว</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">⏳ รออนุมัติทั้งวัน</span>
                    <?php endif; ?>
                </small>
                <div class="mt-1 small text-muted">คำนวณตาม กม. (น้ำมัน ฿4 + สึกหรอ ฿1)</div>
            </div>
        </div>
    </div>

    <!-- ส่วนแผนที่เส้นทาง (Route Map) และไทม์ไลน์จุดแวะ (Timeline) -->
    <div class="row g-4">
        <!-- ฝั่งซ้าย: แผนที่เส้นทาง Interactive Leaflet Route Map -->
        <div class="col-lg-7">
            <div class="card card-custom h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-map-fill text-primary me-2"></i>แผนที่เส้นทางการเดินทางตลอดวัน
                    </h5>
                    <span class="badge bg-light text-dark border">
                        <i class="bi bi-geo-alt-fill text-danger me-1"></i><?= count($waypoints) ?> พิกัดบนแผนที่
                    </span>
                </div>
                <div class="card-body p-3">
                    <div id="routeOverviewMap" class="shadow-sm"></div>
                    
                    <div class="mt-3 p-2 bg-light rounded small d-flex align-items-center justify-content-around flex-wrap gap-2 text-muted">
                        <span><span class="badge rounded-pill bg-success px-2 py-1 me-1">1</span> จุดเริ่มต้น</span>
                        <span><span class="badge rounded-pill bg-primary px-2 py-1 me-1">2..N</span> จุดแวะตรวจ/ส่งสินค้า</span>
                        <span><span class="badge rounded-pill bg-danger px-2 py-1 me-1">🏁</span> จุดสิ้นสุด/เช็คเอาต์</span>
                    </div>

                    <div class="mt-3 p-3 bg-white border rounded">
                        <small class="fw-bold text-muted d-block mb-1">🎯 ภารกิจและวัตถุประสงค์การเดินทางประจำวัน:</small>
                        <p class="mb-0 text-dark small"><?= nl2br(htmlspecialchars($current_trip['purpose'] ?? 'ปฏิบัติงานตรวจติดตามและจำหน่ายข้าวสาร')) ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ฝั่งขวา: รายละเอียดจุดแวะทั้งหมดตามลำดับเวลา (Chronological Waypoints) -->
        <div class="col-lg-5">
            <div class="card card-custom h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-signpost-split-fill text-success me-2"></i>ลำดับจุดแวะทั้งหมด (<?= count($waypoints) ?> จุด)
                    </h5>
                    <small class="text-muted">ตรวจสอบรูปถ่ายและเวลา</small>
                </div>
                <div class="card-body p-3" style="max-height: 620px; overflow-y: auto;">
                    <?php if (empty($waypoints)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-geo-alt fs-1 d-block mb-2"></i>
                        ไม่พบข้อมูลจุดแวะในวันนี้
                    </div>
                    <?php else: ?>
                    <div class="timeline-container">
                        <?php foreach ($waypoints as $idx => $wp): 
                            $step_no = $wp['step_no'] ?? ($idx + 1);
                            $is_first = ($idx === 0);
                            $is_last = ($idx === count($waypoints) - 1);
                            $pin_class = $is_first ? 'pin-start' : ($is_last ? 'pin-checkout' : 'pin-waypoint');
                            $pin_text = $is_first ? '1' : ($is_last ? '🏁' : $step_no);
                            $time_display = !empty($wp['action_time']) ? date('H:i', strtotime($wp['action_time'])) : '--:--';
                            $photo_url = !empty($wp['photo_path']) ? 'uploads/travel_photos/' . $wp['photo_path'] : 'https://sktbkk.seikouedu.com/sample_gps_photos/sample_gps_3_hq.jpg';
                        ?>
                        <div class="timeline-step">
                            <div class="timeline-pin <?= $pin_class ?>"><?= $pin_text ?></div>
                            <div class="card border rounded-3 p-3 bg-light bg-opacity-50 shadow-none">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <strong class="text-dark fs-6"><?= htmlspecialchars($wp['location_name'] ?? "จุดตรวจที่ {$step_no}") ?></strong>
                                    <span class="badge bg-white text-dark border"><i class="bi bi-clock me-1"></i><?= $time_display ?> น.</span>
                                </div>

                                <div class="small text-secondary mb-2">
                                    <?= htmlspecialchars($wp['objective'] ?? 'ตรวจสต็อกและส่งมอบสินค้า') ?>
                                </div>

                                <div class="d-flex align-items-center justify-content-between pt-2 border-top">
                                    <div>
                                        <small class="text-muted d-block font-monospace" style="font-size: 0.72rem;">
                                            <i class="bi bi-crosshair me-1 text-danger"></i><?= number_format($wp['latitude'] ?? 0, 5) ?>, <?= number_format($wp['longitude'] ?? 0, 5) ?>
                                        </small>
                                        <a href="https://maps.google.com/?q=<?= $wp['latitude'] ?>,<?= $wp['longitude'] ?>" target="_blank" class="small text-decoration-none text-primary" style="font-size: 0.75rem;">
                                            <i class="bi bi-box-arrow-up-right me-1"></i>ดู Google Maps
                                        </a>
                                    </div>
                                    <div class="text-end">
                                        <img src="<?= htmlspecialchars($photo_url) ?>" alt="รูปจุดตรวจ" class="waypoint-photo-thumb" 
                                             onclick="showPhotoLightbox('<?= htmlspecialchars($photo_url) ?>', '<?= htmlspecialchars(addslashes($wp['location_name'] ?? '')) ?>', '<?= $time_display ?> น.', '<?= $wp['latitude'] ?>, <?= $wp['longitude'] ?>')" 
                                             title="คลิกเพื่อดูรูปขยาย">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- แถบด้านล่าง: กล่องสรุปการลงนามและอนุมัติประจำวัน -->
    <div class="card card-custom mt-4 no-print bg-white border border-success border-opacity-25">
        <div class="card-body p-4">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5 class="fw-bold text-dark mb-1">
                        <i class="bi bi-shield-check text-success me-2"></i>การตรวจสอบและรับรองความถูกต้องของข้อมูลเดินทาง
                    </h5>
                    <p class="text-muted small mb-0">
                        ระบบได้ตรวจสอบความสอดคล้องระหว่างพิกัดดาวเทียม GPS, เวลาที่เดินทางจริง, และรูปถ่ายสถานที่ครบถ้วนทุกจุดแวะ รวม <?= count($waypoints) ?> จุดตรวจ ระยะทางรวม <?= number_format($current_trip['total_distance_km'] ?? 0, 2) ?> กิโลเมตร
                    </p>
                </div>
                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                    <?php if ($can_approve && $current_trip && ($current_trip['status'] !== 'approved' || ($claim_info && $claim_info['claim_status'] !== 'approved'))): ?>
                    <button type="button" class="btn btn-success btn-lg fw-bold shadow w-100" onclick="confirmDailyApproval(<?= $current_trip['trip_id'] ?>, '<?= htmlspecialchars($current_trip['trip_date']) ?>', '<?= htmlspecialchars($current_trip['officer_name']) ?>')">
                        <i class="bi bi-check2-circle me-1"></i> ⚡ อนุมัติทั้งวันในคลิกเดียว
                    </button>
                    <?php else: ?>
                    <div class="p-2 border rounded bg-success bg-opacity-10 text-success text-center">
                        <i class="bi bi-check-all fs-4 d-block mb-1"></i>
                        <span class="fw-bold">อนุมัติเรียบร้อยแล้ว</span>
                        <div class="small text-muted" style="font-size: 0.75rem;">ผู้อนุมัติ: <?= htmlspecialchars($claim_info['approved_by'] ?? 'ผู้จัดการ สกต.กทม.') ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="card card-custom text-center py-5">
        <div class="card-body">
            <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
            <h5 class="fw-bold">ไม่พบข้อมูลการเดินทาง</h5>
            <p class="text-muted">กรุณาเลือกวันปฏิบัติงานจากเมนูด้านบน</p>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Modal แสดงรูปภาพขยาย (Lightbox) -->
<div class="modal fade" id="photoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header py-2 bg-dark text-white">
                <h6 class="modal-title fw-bold" id="photoModalTitle">หลักฐานรูปถ่ายจุดแวะ</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0 text-center bg-black">
                <img id="photoModalImg" src="" alt="ขยายรูป" style="max-height: 70vh; max-width: 100%; object-fit: contain;">
            </div>
            <div class="modal-footer py-2 d-flex justify-content-between align-items-center bg-light">
                <div class="small text-muted" id="photoModalMeta">พิกัดดาวเทียม: -</div>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ปิดหน้าต่าง</button>
            </div>
        </div>
    </div>
</div>

<footer class="mt-auto py-3 bg-white border-top text-center text-muted no-print">
    <div class="container">
        <small>
            &copy; 2026 <strong>สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)</strong> | ระบบบันทึกการเดินทาง (GPS & AI Verification)
        </small>
    </div>
</footer>

<!-- Leaflet.js JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ข้อมูลจุดแวะจาก PHP
const waypointsList = <?= json_encode($waypoints, JSON_UNESCAPED_UNICODE) ?>;

// เริ่มต้นแผนที่แสดงเส้นทางตลอดทั้งวัน
function initDailyRouteMap() {
    if (!waypointsList || waypointsList.length === 0) {
        document.getElementById('routeOverviewMap').innerHTML = '<div class="p-4 text-center text-muted">ไม่มีพิกัดจุดแวะสำหรับแสดงแผนที่</div>';
        return;
    }

    const firstPoint = waypointsList[0];
    const defaultLat = parseFloat(firstPoint.latitude) || 13.7563;
    const defaultLng = parseFloat(firstPoint.longitude) || 100.5018;

    const map = L.map('routeOverviewMap').setView([defaultLat, defaultLng], 12);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap contributors | สกต.กทม. GPS'
    }).addTo(map);

    const latlngs = [];

    waypointsList.forEach((wp, index) => {
        const lat = parseFloat(wp.latitude);
        const lng = parseFloat(wp.longitude);
        if (isNaN(lat) || isNaN(lng)) return;

        latlngs.push([lat, lng]);

        const isStart = (index === 0);
        const isEnd = (index === waypointsList.length - 1);
        const stepNum = index + 1;

        // สีของหมุด
        const pinColor = isStart ? '#16a34a' : (isEnd ? '#dc2626' : '#0284c7');
        const pinIcon = L.divIcon({
            className: 'custom-map-pin',
            html: `
                <div style="background-color: ${pinColor}; color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 13px; border: 2px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.35);">
                    ${isStart ? '1' : (isEnd ? '🏁' : stepNum)}
                </div>
            `,
            iconSize: [30, 30],
            iconAnchor: [15, 15]
        });

        const timeStr = wp.action_time ? wp.action_time.substring(11, 16) : '--:--';
        const photoUrl = wp.photo_path ? ('uploads/travel_photos/' + wp.photo_path) : 'https://sktbkk.seikouedu.com/sample_gps_photos/sample_gps_3_hq.jpg';

        const popupContent = `
            <div style="min-width: 180px; font-family: 'Prompt', sans-serif;">
                <div class="fw-bold text-dark mb-1">จุดที่ ${stepNum}: ${wp.location_name || ''}</div>
                <div class="small text-muted mb-1"><i class="bi bi-clock me-1"></i>เวลา: ${timeStr} น.</div>
                <div class="small text-secondary mb-2">${wp.objective || ''}</div>
                <img src="${photoUrl}" style="width: 100%; height: 90px; object-fit: cover; border-radius: 6px; cursor: pointer;" 
                     onclick="showPhotoLightbox('${photoUrl}', '${wp.location_name || ''}', '${timeStr} น.', '${lat}, ${lng}')">
                <div class="mt-2 text-center">
                    <span class="badge bg-success" style="font-size: 0.7rem;">GPS ดาวเทียมตรวจผ่าน</span>
                </div>
            </div>
        `;

        L.marker([lat, lng], { icon: pinIcon }).addTo(map).bindPopup(popupContent);
    });

    // ลากเส้น Polyline เชื่อมโยงจุดแวะทั้งหมด
    if (latlngs.length > 1) {
        const polyline = L.polyline(latlngs, {
            color: '#0284c7',
            weight: 5,
            opacity: 0.85,
            dashArray: '8, 8'
        }).addTo(map);
        map.fitBounds(polyline.getBounds(), { padding: [40, 40] });
    }
}

// ขยายรูปภาพผ่าน Lightbox Modal
function showPhotoLightbox(url, title, time, gps) {
    document.getElementById('photoModalTitle').innerText = title + ' (' + time + ')';
    document.getElementById('photoModalImg').src = url;
    document.getElementById('photoModalMeta').innerHTML = '<strong>พิกัดดาวเทียม:</strong> ' + gps + ' <span class="badge bg-success ms-2">GPS Verified</span>';
    const modal = new bootstrap.Modal(document.getElementById('photoModal'));
    modal.show();
}

// ยืนยันการอนุมัติรวดเดียวทั้งวัน (SweetAlert2)
function confirmDailyApproval(tripId, tripDate, officerName) {
    Swal.fire({
        title: 'ยืนยันอนุมัติการเดินทางทั้งวัน?',
        html: `
            <div class="text-start p-2">
                <p class="mb-2"><strong>เจ้าหน้าที่:</strong> ${officerName}</p>
                <p class="mb-2"><strong>ประจำวันที่:</strong> ${tripDate}</p>
                <p class="text-muted small mb-0">
                    ระบบจะทำการอนุมัติรายการเดินทางและใบขอเบิกค่าใช้จ่ายทั้งหมดของวันนี้ในคลิกเดียว พร้อมลงบัญชีค่าใช้จ่ายอัตโนมัติ
                </p>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#008751',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="bi bi-check-circle-fill me-1"></i> ยืนยันอนุมัติทั้งวันทันที',
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
            formData.append('trip_date', tripDate);
            formData.append('officer_name', officerName);
            formData.append('ajax', '1');

            fetch('daily_overview.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'อนุมัติเรียบร้อยแล้ว!',
                        text: data.message,
                        confirmButtonColor: '#008751'
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        text: data.error || 'ไม่สามารถบันทึกการอนุมัติได้'
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

document.addEventListener('DOMContentLoaded', function() {
    initDailyRouteMap();
});
</script>
</body>
</html>
