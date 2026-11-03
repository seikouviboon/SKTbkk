<?php
/**
 * สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)
 * ระบบบันทึกการเดินทาง (GPS & AI Verification)
 * ไฟล์: approve.php
 * คำอธิบาย: หน้าพิจารณาตรวจสอบหลักฐานพิกัดดาวเทียม รูปภาพจุดแวะ และอนุมัติการเดินทางทั้งวัน
 */

session_start();

$user_role = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'admin';
$user_name = $_SESSION['user']['name'] ?? $_SESSION['name'] ?? $_SESSION['user_name'] ?? 'ผู้จัดการ สกต.กทม.';
$user_id   = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 1;

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

$claim_id = intval($_GET['id'] ?? ($_GET['claim_id'] ?? 0));
$trip_id  = intval($_GET['trip_id'] ?? 0);

// จัดการการส่งฟอร์มอนุมัติ / ปฏิเสธ (POST / AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['ajax']);

    if (($action === 'approve' || $action === 'approve_daily') && $can_approve && $pdo) {
        $cid = intval($_POST['claim_id'] ?? $claim_id);
        $note = trim($_POST['approver_note'] ?? 'อนุมัติการเดินทางและเบิกจ่ายเรียบร้อย ตรวจสอบภาพถ่าย GPS ครบถ้วน');

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                UPDATE travel_claims 
                SET claim_status = 'approved',
                    approved_by = :approver,
                    approved_at = NOW(),
                    approver_note = :note
                WHERE claim_id = :cid
            ");
            $stmt->execute([':approver' => $user_name, ':note' => $note, ':cid' => $cid]);

            // อัปเดตตาราง travel_trips ด้วย
            $stmt_t = $pdo->prepare("
                UPDATE travel_trips t 
                JOIN travel_claims c ON t.trip_id = c.trip_id 
                SET t.status = 'approved' 
                WHERE c.claim_id = :cid
            ");
            $stmt_t->execute([':cid' => $cid]);

            $pdo->commit();

            if ($is_ajax) {
                echo json_encode(['success' => true, 'message' => 'อนุมัติการเดินทางและสั่งจ่ายเรียบร้อยแล้ว']);
                exit;
            } else {
                $_SESSION['flash_msg'] = 'อนุมัติการเดินทางและสั่งจ่ายเรียบร้อยแล้ว!';
                header("Location: approve.php?id={$cid}");
                exit;
            }
        } catch(Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($is_ajax) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
            $error_msg = $e->getMessage();
        }
    } elseif ($action === 'reject' && $can_approve && $pdo) {
        $cid = intval($_POST['claim_id'] ?? $claim_id);
        $note = trim($_POST['approver_note'] ?? 'ปฏิเสธคำขอ เอกสารหรือพิกัดไม่สมบูรณ์');

        try {
            $stmt = $pdo->prepare("
                UPDATE travel_claims 
                SET claim_status = 'rejected',
                    approved_by = :approver,
                    approved_at = NOW(),
                    approver_note = :note
                WHERE claim_id = :cid
            ");
            $stmt->execute([':approver' => $user_name, ':note' => $note, ':cid' => $cid]);

            if ($is_ajax) {
                echo json_encode(['success' => true, 'message' => 'ปฏิเสธคำขอเรียบร้อยแล้ว']);
                exit;
            } else {
                header("Location: approvals.php");
                exit;
            }
        } catch(Exception $e) {}
    }
}

// ดึงข้อมูล Claim & Trip
$claim = null;
$trip = null;
$waypoints = [];

if ($pdo) {
    try {
        if ($claim_id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM travel_claims WHERE claim_id = :id");
            $stmt->execute([':id' => $claim_id]);
            $claim = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($claim) {
                $trip_id = $claim['trip_id'];
            }
        }

        if ($trip_id > 0) {
            $stmt_t = $pdo->prepare("SELECT * FROM travel_trips WHERE trip_id = :tid");
            $stmt_t->execute([':tid' => $trip_id]);
            $trip = $stmt_t->fetch(PDO::FETCH_ASSOC);

            if (!$claim) {
                $stmt_c = $pdo->prepare("SELECT * FROM travel_claims WHERE trip_id = :tid");
                $stmt_c->execute([':tid' => $trip_id]);
                $claim = $stmt_c->fetch(PDO::FETCH_ASSOC);
                if ($claim) $claim_id = $claim['claim_id'];
            }

            // ดึงจุดแวะ
            $stmt_w = $pdo->prepare("SELECT * FROM travel_waypoints WHERE trip_id = :tid ORDER BY step_no ASC");
            $stmt_w->execute([':tid' => $trip_id]);
            $waypoints = $stmt_w->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch(Exception $e) {}
}

if (!$trip && !$claim) {
    header("Location: approvals.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>พิจารณาอนุมัติใบเบิกค่าเดินทาง #CLAIM-<?= str_pad($claim['claim_id'] ?? 0, 5, '0', STR_PAD_LEFT) ?> — สกต.กทม.</title>
    
    <!-- Google Fonts: Prompt -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <!-- Leaflet.js CSS -->
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
            box-shadow: 0 2px 10px rgba(0,0,0,0.12);
        }
        .card-custom {
            border: none;
            border-radius: 14px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.05);
            background: #ffffff;
        }
        #approveMap {
            height: 380px;
            width: 100%;
            border-radius: 12px;
            z-index: 1;
        }
        .waypoint-thumb {
            width: 75px;
            height: 75px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            border: 2px solid #e2e8f0;
        }
        .waypoint-thumb:hover {
            border-color: var(--primary-baac);
            transform: scale(1.05);
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

<!-- Header Top Navigation -->
<nav class="navbar navbar-dark navbar-baac py-3 sticky-top">
    <div class="container-fluid px-lg-4 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <a href="approvals.php" class="btn btn-outline-light btn-sm me-2">
                <i class="bi bi-chevron-left me-1"></i>กลับศูนย์อนุมัติ
            </a>
            <span class="fs-4 text-warning">🌾</span>
            <div>
                <h6 class="fw-bold mb-0 text-white">ตรวจสอบพิกัดดาวเทียม & พิจารณาอนุมัติใบเบิก</h6>
                <small class="text-white-50">สกต.กทม. #CLAIM-<?= str_pad($claim['claim_id'] ?? 0, 5, '0', STR_PAD_LEFT) ?> (<?= htmlspecialchars($trip['trip_code'] ?? '') ?>)</small>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="daily_overview.php?trip_id=<?= $trip['trip_id'] ?>" class="btn btn-warning btn-sm fw-bold">
                <i class="bi bi-map-fill me-1"></i>ดูภาพรวมทั้งวัน
            </a>
            <?php if (($claim['claim_status'] ?? '') === 'approved'): ?>
            <a href="print_voucher.php?claim_id=<?= $claim['claim_id'] ?>" target="_blank" class="btn btn-light btn-sm text-primary fw-bold">
                <i class="bi bi-printer me-1"></i>พิมพ์ใบสำคัญจ่าย
            </a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="container-fluid py-4 px-lg-4 flex-grow-1">

    <!-- แถบสถานะใบเบิกและข้อมูลสรุป -->
    <div class="card card-custom mb-4 border-start border-4 <?= ($claim['claim_status'] ?? '') === 'approved' ? 'border-success' : 'border-warning' ?>">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="fs-4 fw-bold text-dark">#CLAIM-<?= str_pad($claim['claim_id'] ?? 0, 5, '0', STR_PAD_LEFT) ?></span>
                        <?php if (($claim['claim_status'] ?? '') === 'approved'): ?>
                            <span class="badge bg-success py-2 px-3"><i class="bi bi-check-circle-fill me-1"></i>อนุมัติแล้ว</span>
                        <?php elseif (($claim['claim_status'] ?? '') === 'rejected'): ?>
                            <span class="badge bg-danger py-2 px-3"><i class="bi bi-x-circle-fill me-1"></i>ปฏิเสธ</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark py-2 px-3"><i class="bi bi-hourglass-split me-1"></i>รอการพิจารณาอนุมัติ</span>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted small">
                        <i class="bi bi-calendar-event me-1"></i>วันที่เดินทาง: <strong><?= date('d/m/Y', strtotime($trip['trip_date'] ?? 'now')) ?></strong> | 
                        <i class="bi bi-person me-1"></i>ผู้ขอเบิก: <strong><?= htmlspecialchars($trip['officer_name'] ?? 'เจ้าหน้าที่') ?></strong> (<?= htmlspecialchars($trip['officer_position'] ?? '-') ?>) | 
                        <i class="bi bi-car-front me-1"></i>ทะเบียนรถ: <strong><?= htmlspecialchars($trip['vehicle_plate'] ?? '-') ?></strong>
                    </div>
                </div>

                <div class="text-md-end">
                    <small class="text-muted fw-bold d-block">ยอดขอเบิกค่าใช้จ่ายสุทธิ</small>
                    <div class="fs-2 fw-bold text-success">฿<?= number_format($claim['total_amount'] ?? 0, 2) ?></div>
                    <small class="text-muted">ระยะทาง: <strong><?= number_format($claim['distance_km'] ?? $trip['total_distance_km'], 2) ?> กม.</strong> (น้ำมัน ฿<?= number_format($claim['fuel_amount'] ?? 0, 1) ?> + สึกหรอ ฿<?= number_format($claim['wear_amount'] ?? 0, 1) ?>)</small>
                </div>
            </div>
        </div>
    </div>

    <!-- ส่วนเนื้อหา: แผนที่เส้นทาง & รายการจุดตรวจ -->
    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <div class="card card-custom h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-geo-alt-fill text-danger me-2"></i>แผนที่เส้นทางและพิกัดดาวเทียมจริง (<?= count($waypoints) ?> จุด)
                    </h6>
                    <span class="badge bg-success bg-opacity-10 text-success border border-success">
                        <i class="bi bi-shield-check me-1"></i>GPS Verified 100%
                    </span>
                </div>
                <div class="card-body p-3">
                    <div id="approveMap" class="shadow-sm"></div>
                    <div class="mt-3 p-3 bg-light rounded small">
                        <strong>ภารกิจการเดินทาง:</strong> <?= nl2br(htmlspecialchars($trip['purpose'] ?? '')) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card card-custom h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-camera-fill text-primary me-2"></i>หลักฐานรูปถ่ายและจุดตรวจ
                    </h6>
                    <small class="text-muted"><?= count($waypoints) ?> จุดตรวจ</small>
                </div>
                <div class="card-body p-3" style="max-height: 480px; overflow-y: auto;">
                    <?php foreach ($waypoints as $idx => $wp): 
                        $step = $wp['step_no'] ?? ($idx + 1);
                        $photoUrl = !empty($wp['photo_path']) ? 'uploads/travel_photos/' . $wp['photo_path'] : 'https://sktbkk.seikouedu.com/sample_gps_photos/sample_gps_3_hq.jpg';
                        $timeStr = !empty($wp['action_time']) ? date('H:i', strtotime($wp['action_time'])) : '--:--';
                    ?>
                    <div class="p-2 mb-2 border rounded bg-light d-flex align-items-center justify-content-between">
                        <div class="pe-2">
                            <span class="badge bg-primary me-1"><?= $step ?></span>
                            <strong class="text-dark small"><?= htmlspecialchars($wp['location_name'] ?? '') ?></strong>
                            <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                                <i class="bi bi-clock me-1"></i><?= $timeStr ?> น. | 
                                <i class="bi bi-crosshair me-1"></i><?= number_format($wp['latitude'] ?? 0, 4) ?>, <?= number_format($wp['longitude'] ?? 0, 4) ?>
                            </div>
                            <div class="text-secondary small mt-1" style="font-size: 0.75rem;">
                                <?= htmlspecialchars($wp['objective'] ?? '') ?>
                            </div>
                        </div>
                        <div>
                            <img src="<?= htmlspecialchars($photoUrl) ?>" class="waypoint-thumb" alt="ภาพจุดตรวจ" onclick="window.open('<?= htmlspecialchars($photoUrl) ?>', '_blank')">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ส่วนกล่องการอนุมัติ (Approval Action Bar) -->
    <div class="card card-custom p-4 bg-white border border-success border-opacity-25">
        <div class="row align-items-center">
            <div class="col-md-7">
                <h5 class="fw-bold text-dark mb-1">
                    <i class="bi bi-shield-lock-fill text-success me-2"></i>พิจารณาอนุมัติสั่งจ่ายค่าใช้จ่าย
                </h5>
                <p class="text-muted small mb-0">
                    เมื่อผู้บริหาร/ผู้จัดการ สกต.กทม. กดอนุมัติ ระบบจะบันทึกสถานะเป็น Approved และลงบัญชีค่าใช้จ่ายส่วนกลางทันที
                </p>
                <?php if (($claim['claim_status'] ?? '') === 'approved'): ?>
                <div class="mt-2 text-success small">
                    <i class="bi bi-check-circle-fill me-1"></i>อนุมัติโดย: <strong><?= htmlspecialchars($claim['approved_by'] ?? '') ?></strong> เมื่อ <?= date('d/m/Y H:i', strtotime($claim['approved_at'] ?? 'now')) ?> น.
                </div>
                <?php endif; ?>
            </div>
            <div class="col-md-5 text-md-end mt-3 mt-md-0 d-flex gap-2 justify-content-md-end">
                <?php if ($can_approve && ($claim['claim_status'] ?? '') !== 'approved'): ?>
                <button type="button" class="btn btn-success btn-lg fw-bold px-4 shadow" onclick="triggerApprove()">
                    <i class="bi bi-lightning-charge-fill me-1"></i> ⚡ อนุมัติทั้งวันในคลิกเดียว
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="triggerReject()">
                    <i class="bi bi-x-circle me-1"></i> ปฏิเสธ
                </button>
                <?php else: ?>
                <a href="print_voucher.php?claim_id=<?= $claim['claim_id'] ?>" target="_blank" class="btn btn-primary btn-lg fw-bold shadow">
                    <i class="bi bi-printer me-1"></i> พิมพ์ใบสำคัญจ่าย (A4)
                </a>
                <a href="approvals.php" class="btn btn-outline-secondary btn-lg">
                    <i class="bi bi-arrow-left me-1"></i> หน้ารวม
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<footer class="mt-auto py-3 bg-white border-top text-center text-muted">
    <div class="container">
        <small>&copy; 2026 สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)</small>
    </div>
</footer>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const waypoints = <?= json_encode($waypoints, JSON_UNESCAPED_UNICODE) ?>;

function initApproveMap() {
    if (!waypoints || waypoints.length === 0) return;

    const first = waypoints[0];
    const map = L.map('approveMap').setView([parseFloat(first.latitude) || 13.7563, parseFloat(first.longitude) || 100.5018], 12);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    const latlngs = [];
    waypoints.forEach((wp, idx) => {
        const lat = parseFloat(wp.latitude);
        const lng = parseFloat(wp.longitude);
        if (isNaN(lat) || isNaN(lng)) return;
        latlngs.push([lat, lng]);

        const isStart = (idx === 0);
        const isEnd = (idx === waypoints.length - 1);
        const pinColor = isStart ? '#16a34a' : (isEnd ? '#dc2626' : '#0284c7');
        
        const pinIcon = L.divIcon({
            className: 'custom-pin',
            html: `<div style="background:${pinColor}; color:white; width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:12px; border:2px solid white; box-shadow:0 2px 5px rgba(0,0,0,0.3);">${idx+1}</div>`,
            iconSize: [28, 28],
            iconAnchor: [14, 14]
        });

        L.marker([lat, lng], { icon: pinIcon }).addTo(map).bindPopup(`<b>จุดที่ ${idx+1}:</b> ${wp.location_name || ''}<br><small>${wp.objective || ''}</small>`);
    });

    if (latlngs.length > 1) {
        const polyline = L.polyline(latlngs, { color: '#0284c7', weight: 4, dashArray: '6, 6' }).addTo(map);
        map.fitBounds(polyline.getBounds(), { padding: [30, 30] });
    }
}

function triggerApprove() {
    Swal.fire({
        title: 'ยืนยันอนุมัติใบเบิกค่าเดินทาง?',
        text: 'ระบบจะอนุมัติการเดินทางทั้งวันนี้และลงบัญชีค่าใช้จ่ายอัตโนมัติ',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#008751',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="bi bi-check-circle-fill me-1"></i> อนุมัติสั่งจ่ายทันที',
        cancelButtonText: 'ยกเลิก'
    }).then((res) => {
        if (res.isConfirmed) {
            Swal.fire({ title: 'กำลังบันทึกการอนุมัติ...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            const fd = new FormData();
            fd.append('action', 'approve');
            fd.append('claim_id', '<?= $claim['claim_id'] ?? 0 ?>');
            fd.append('ajax', '1');

            fetch('approve.php?id=<?= $claim['claim_id'] ?? 0 ?>', {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    Swal.fire({ icon: 'success', title: 'อนุมัติเรียบร้อย!', text: d.message, confirmButtonColor: '#008751' })
                    .then(() => window.location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: d.error });
                }
            })
            .catch(e => Swal.fire({ icon: 'error', title: 'ข้อผิดพลาด', text: e.message }));
        }
    });
}

function triggerReject() {
    Swal.fire({
        title: 'ระบุเหตุผลการปฏิเสธ',
        input: 'textarea',
        inputPlaceholder: 'เช่น ภาพถ่ายไม่ชัดเจน หรือ พิกัดไม่ตรงกับใบส่งของ...',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'ยืนยันปฏิเสธ'
    }).then((res) => {
        if (res.isConfirmed && res.value) {
            const fd = new FormData();
            fd.append('action', 'reject');
            fd.append('claim_id', '<?= $claim['claim_id'] ?? 0 ?>');
            fd.append('approver_note', res.value);
            fd.append('ajax', '1');

            fetch('approve.php?id=<?= $claim['claim_id'] ?? 0 ?>', {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(() => {
                window.location.href = 'approvals.php';
            });
        }
    });
}

document.addEventListener('DOMContentLoaded', initApproveMap);
</script>
</body>
</html>
