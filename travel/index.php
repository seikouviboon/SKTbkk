<?php
/**
 * สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)
 * ระบบบันทึกการเดินทาง (GPS & AI Verification)
 * ไฟล์: index.php
 * คำอธิบาย: หน้ารวมประวัติการเดินทาง และศูนย์ควบคุมระบบเดินทาง GPS พร้อมปุ่ม Home กลับหน้าแรก
 */

session_start();

$user_role = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'admin';
$user_name = $_SESSION['user']['name'] ?? $_SESSION['name'] ?? $_SESSION['user_name'] ?? 'ผู้จัดการ สกต.กทม.';
$user_id   = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 1;

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

$can_approve = in_array($user_role, ['admin', 'superadmin', 'skt01', 'manager']);

// ดึงทริปที่กำลังเดินทางอยู่ (Ongoing)
$ongoing_trip = null;
if ($pdo) {
    try {
        $stmt_on = $pdo->prepare("SELECT * FROM travel_trips WHERE status = 'ongoing' ORDER BY trip_id DESC LIMIT 1");
        $stmt_on->execute();
        $ongoing_trip = $stmt_on->fetch(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}
}

// สถิติรวมประจำเดือน
$stats = ['trip_count' => 0, 'total_km' => 0, 'approved_amount' => 0];
if ($pdo) {
    try {
        $stmt_s = $pdo->query("
            SELECT 
                COUNT(*) as trip_count,
                COALESCE(SUM(total_distance_km), 0) as total_km
            FROM travel_trips
            WHERE DATE_FORMAT(trip_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        ");
        $res_s = $stmt_s->fetch(PDO::FETCH_ASSOC);
        if ($res_s) {
            $stats['trip_count'] = $res_s['trip_count'];
            $stats['total_km'] = $res_s['total_km'];
        }

        $stmt_a = $pdo->query("
            SELECT COALESCE(SUM(total_amount), 0) as approved_amount 
            FROM travel_claims 
            WHERE claim_status = 'approved' 
              AND DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        ");
        $stats['approved_amount'] = $stmt_a->fetchColumn() ?? 0;
    } catch(Exception $e) {}
}

// ดึงรายการทริปทั้งหมด
$trips = [];
if ($pdo) {
    try {
        $stmt_trips = $pdo->query("
            SELECT 
                t.*,
                c.claim_id, c.claim_status, c.total_amount as claim_total,
                (SELECT COUNT(*) FROM travel_waypoints w WHERE w.trip_id = t.trip_id) as wp_count
            FROM travel_trips t
            LEFT JOIN travel_claims c ON t.trip_id = c.trip_id
            ORDER BY t.trip_date DESC, t.trip_id DESC
        ");
        $trips = $stmt_trips->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ระบบบันทึกการเดินทาง (GPS & AI) — สกต.กทม.</title>
    
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
            background: #ffffff;
        }
        .trip-card {
            border-left: 5px solid #cbd5e1;
            transition: all 0.2s ease-in-out;
        }
        .trip-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            transform: translateY(-1px);
        }
        .trip-card.ongoing { border-left-color: #f59e0b; }
        .trip-card.submitted { border-left-color: #0284c7; }
        .trip-card.approved { border-left-color: #10b981; }
        .stat-box {
            border-radius: 14px;
            padding: 1.2rem;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

<!-- Header Top Navigation พร้อมปุ่ม Home ชัดเจน -->
<nav class="navbar navbar-dark navbar-baac py-3 sticky-top">
    <div class="container-fluid px-lg-4 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <!-- ปุ่ม Home กลับแดชบอร์ดหลักของระบบ สกต.กทม. -->
            <a href="https://sktbkk.seikouedu.com/dashboard.php" class="btn btn-warning btn-sm fw-bold shadow-sm me-2">
                <i class="bi bi-house-door-fill me-1"></i>หน้าแรก สกต. (Home)
            </a>
            <span class="fs-4 text-warning">🌾</span>
            <div>
                <h6 class="fw-bold mb-0 text-white">ระบบบันทึกการเดินทาง (GPS & AI Verification)</h6>
                <small class="text-white-50">สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด</small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="https://sktbkk.seikouedu.com/dashboard.php" class="btn btn-outline-light btn-sm fw-bold">
                <i class="bi bi-grid-fill me-1"></i>แดชบอร์ดหลัก
            </a>
            <a href="daily_overview.php" class="btn btn-light btn-sm fw-bold text-success shadow-sm">
                <i class="bi bi-map-fill me-1"></i>ดูภาพรวมรายวัน (Route Map)
            </a>
            <?php if ($can_approve): ?>
            <a href="approvals.php" class="btn btn-warning btn-sm fw-bold shadow-sm">
                <i class="bi bi-shield-check me-1"></i>ศูนย์พิจารณาอนุมัติ
            </a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="container-fluid py-4 px-lg-4 flex-grow-1">

    <!-- แถบ Header หัวหน้าเว็บ พร้อมปุ่ม Home และปุ่มเริ่มเดินทาง -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
        <div>
            <h4 class="fw-bold mb-1 text-dark">
                <i class="bi bi-geo-alt-fill text-success me-2"></i>ประวัติการเดินทาง & ขอเบิกค่าพาหนะ
            </h4>
            <p class="text-muted small mb-0">ระบบบันทึกพิกัดดาวเทียม GPS และคำนวณเงินเบิกจ่ายตามระเบียบ สกต.กทม.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <!-- ปุ่ม Home ชัดเจน -->
            <a href="https://sktbkk.seikouedu.com/dashboard.php" class="btn btn-warning fw-bold shadow-sm">
                <i class="bi bi-house-door-fill me-1"></i> หน้าแรก สกต. (Home)
            </a>
            <a href="checkin.php" class="btn btn-success fw-bold shadow-sm">
                <i class="bi bi-play-circle-fill me-1"></i> เช็คอิน — เริ่มเดินทาง (ใหม่)
            </a>
            <a href="daily_overview.php" class="btn btn-outline-primary fw-bold">
                <i class="bi bi-map-fill me-1"></i> ภาพรวมเส้นทางรายวัน
            </a>
        </div>
    </div>

    <!-- แถบเตือนกรณีมีทริปกำลังดำเนินอยู่ -->
    <?php if ($ongoing_trip): ?>
    <div class="alert alert-warning border-0 shadow-sm rounded-4 d-flex align-items-center justify-content-between p-3 mb-4">
        <div class="d-flex align-items-center gap-3">
            <span class="fs-1">🚗</span>
            <div>
                <strong class="text-dark d-block fs-6">มีการเดินทางกำลังปฏิบัติงานอยู่! (<?= htmlspecialchars($ongoing_trip['trip_code']) ?>)</strong>
                <small class="text-muted">เริ่มจาก <?= htmlspecialchars($ongoing_trip['start_location']) ?> เมื่อเวลา <?= substr($ongoing_trip['start_time'], 0, 5) ?> น.</small>
            </div>
        </div>
        <div>
            <a href="checkin.php" class="btn btn-warning fw-bold shadow-sm">
                <i class="bi bi-camera-fill me-1"></i> บันทึกจุดแวะต่อ / เช็คเอาต์
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- สถิติรวมประจำเดือน -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-box border-start border-4 border-success">
                <small class="text-muted fw-bold d-block">🚗 ครั้งที่เดินทาง (เดือนนี้)</small>
                <div class="fs-3 fw-bold text-success mt-1"><?= number_format($stats['trip_count']) ?> <span class="fs-6 fw-normal text-muted">ครั้ง</span></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-box border-start border-4 border-primary">
                <small class="text-muted fw-bold d-block">📍 ระยะทางสะสมรวม (เดือนนี้)</small>
                <div class="fs-3 fw-bold text-primary mt-1"><?= number_format($stats['total_km'], 1) ?> <span class="fs-6 fw-normal text-muted">กิโลเมตร</span></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-box border-start border-4 border-warning">
                <small class="text-muted fw-bold d-block">💰 ยอดเงินอนุมัติเบิกจ่ายแล้ว</small>
                <div class="fs-3 fw-bold text-warning mt-1">฿<?= number_format($stats['approved_amount'], 2) ?></div>
            </div>
        </div>
    </div>

    <!-- ตารางรายการประวัติการเดินทาง -->
    <div class="card card-custom">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-dark">
                <i class="bi bi-list-ul me-2 text-primary"></i>รายการประวัติการเดินทางทั้งหมด (<?= count($trips) ?> รายการ)
            </h6>
            <a href="daily_overview.php" class="btn btn-sm btn-outline-success">
                <i class="bi bi-map me-1"></i>ดูภาพรวมเส้นทางบนแผนที่
            </a>
        </div>
        <div class="card-body p-0">
            <div class="list-group list-group-flush">
                <?php if (empty($trips)): ?>
                <div class="p-5 text-center text-muted">
                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                    ยังไม่มีข้อมูลประวัติการเดินทาง
                </div>
                <?php else: ?>
                <?php foreach ($trips as $t): 
                    $tripStatus = $t['claim_status'] ?? $t['status'];
                    $cardClass = ($tripStatus === 'approved') ? 'approved' : (($tripStatus === 'ongoing') ? 'ongoing' : 'submitted');
                ?>
                <div class="list-group-item trip-card <?= $cardClass ?> py-3 px-3 px-md-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                <span class="fw-bold text-dark fs-6"><?= date('d/m/Y', strtotime($t['trip_date'])) ?></span>
                                <span class="badge bg-light text-dark border font-monospace"><?= htmlspecialchars($t['trip_code']) ?></span>
                                <?php if ($tripStatus === 'approved'): ?>
                                    <span class="badge bg-success">✅ อนุมัติแล้ว</span>
                                <?php elseif ($tripStatus === 'ongoing'): ?>
                                    <span class="badge bg-warning text-dark">🟡 กำลังเดินทาง</span>
                                <?php else: ?>
                                    <span class="badge bg-info text-white">⏳ รออนุมัติ (Submitted)</span>
                                <?php endif; ?>
                            </div>

                            <div class="small text-muted mb-1">
                                <strong>ผู้เดินทาง:</strong> <?= htmlspecialchars($t['officer_name'] ?? 'เจ้าหน้าที่') ?> (<?= htmlspecialchars($t['vehicle_plate'] ?? '-') ?>)
                            </div>

                            <div class="small text-dark fw-bold mb-1">
                                <i class="bi bi-briefcase text-secondary me-1"></i><?= htmlspecialchars($t['purpose'] ?? '-') ?>
                            </div>

                            <div class="small text-muted mb-2">
                                <i class="bi bi-geo-alt text-success me-1"></i><?= htmlspecialchars($t['start_location'] ?? '-') ?>
                                <i class="bi bi-arrow-right mx-1"></i>
                                <i class="bi bi-flag text-danger me-1"></i><?= htmlspecialchars($t['end_location'] ?? '-') ?>
                            </div>

                            <div class="d-flex gap-3 align-items-center small flex-wrap">
                                <span class="text-primary fw-bold"><i class="bi bi-speedometer2 me-1"></i><?= number_format($t['total_distance_km'], 1) ?> กม.</span>
                                <span class="text-secondary"><i class="bi bi-camera me-1"></i><?= $t['wp_count'] ?> จุดตรวจ</span>
                                <?php if (!empty($t['claim_total'])): ?>
                                <span class="text-success fw-bold fs-6">฿<?= number_format($t['claim_total'], 2) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <!-- ปุ่มดูภาพรวมประจำวันและแผนที่เส้นทาง -->
                            <a href="daily_overview.php?trip_id=<?= $t['trip_id'] ?>" class="btn btn-sm btn-outline-primary fw-bold" title="ดูภาพรวมเส้นทางและจุดแวะ">
                                <i class="bi bi-map me-1"></i>ดูภาพรวมวัน
                            </a>

                            <?php if ($tripStatus === 'submitted' && $can_approve): ?>
                            <a href="approve.php?id=<?= $t['claim_id'] ?>" class="btn btn-sm btn-success fw-bold">
                                <i class="bi bi-shield-check me-1"></i>พิจารณาอนุมัติ
                            </a>
                            <?php endif; ?>

                            <?php if ($tripStatus === 'approved' && !empty($t['claim_id'])): ?>
                            <a href="print_voucher.php?claim_id=<?= $t['claim_id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="พิมพ์ใบสำคัญจ่าย">
                                <i class="bi bi-printer me-1"></i>พิมพ์ใบสำคัญจ่าย
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<footer class="mt-auto py-3 bg-white border-top text-center text-muted">
    <div class="container">
        <small>
            &copy; 2026 <strong>สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)</strong> | ระบบบันทึกการเดินทาง (GPS & AI Verification)
        </small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
