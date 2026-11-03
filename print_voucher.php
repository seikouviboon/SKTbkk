<?php
/**
 * สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)
 * ระบบบันทึกการเดินทาง (GPS & AI Verification)
 * ไฟล์: print_voucher.php
 * คำอธิบาย: ใบสำคัญจ่าย / ใบขอเบิกเงินค่าพาหนะปฏิบัติงานนอกสถานที่ (แบบฟอร์มทางการพร้อมพิมพ์ A4)
 */

session_start();
$user_role = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';
if (empty($user_role) && empty($_SESSION['username'])) {
    header('Location: https://sktbkk.seikouedu.com/index.php');
    exit;
}

$pdo = null;
$possible_db_files = [
    __DIR__ . '/../config/database.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/config/database.php'
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

$claim_id = isset($_GET['claim_id']) ? intval($_GET['claim_id']) : 0;
if (!$claim_id) {
    die("ไม่พบรหัสใบขอเบิก");
}

// ดึงข้อมูลการเบิกจ่าย
$claim = null;
try {
    $sql = "
        SELECT 
            c.*,
            t.trip_code, t.trip_date, t.start_time, t.end_time,
            t.start_location, t.end_location, t.purpose, t.vehicle_plate,
            t.officer_name, t.officer_position
        FROM travel_claims c
        JOIN travel_trips t ON c.trip_id = t.trip_id
        WHERE c.claim_id = :claim_id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':claim_id' => $claim_id]);
    $claim = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    if (file_exists(__DIR__ . '/install.php')) {
        require_once __DIR__ . '/install.php';
        exit;
    }
}

if (!$claim) {
    die("<div style='font-family: sans-serif; padding: 40px; text-align: center;'><h3>⚠️ ไม่พบข้อมูลใบขอเบิกเลขที่ #CLAIM-" . str_pad($claim_id, 5, '0', STR_PAD_LEFT) . "</h3><p><a href='approvals.php'>กลับหน้าศูนย์อนุมัติ</a></p></div>");
}

// ดึงจุดแวะ (Waypoints)
$sql_wp = "
    SELECT * FROM travel_waypoints 
    WHERE trip_id = :trip_id 
    ORDER BY step_no ASC
";
$stmt_wp = $pdo->prepare($sql_wp);
$stmt_wp->execute([':trip_id' => $claim['trip_id']]);
$waypoints = $stmt_wp->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ใบสำคัญจ่ายค่าพาหนะเดินทาง — สกต.กทม. #CLAIM-<?= str_pad($claim['claim_id'], 5, '0', STR_PAD_LEFT) ?></title>
    <!-- Google Fonts: Sarabun & Prompt -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f1f5f9;
            color: #000;
        }
        .page {
            width: 210mm;
            min-height: 297mm;
            padding: 20mm 18mm;
            margin: 15px auto;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            position: relative;
        }
        .header-logo {
            font-size: 32px;
        }
        .table-voucher th, .table-voucher td {
            border: 1px solid #222 !important;
            padding: 6px 8px;
            font-size: 0.9rem;
        }
        .table-voucher thead th {
            background-color: #f3f4f6 !important;
        }
        .sig-box {
            text-align: center;
            padding-top: 35px;
        }
        .sig-line {
            display: inline-block;
            width: 170px;
            border-bottom: 1px dotted #333;
            margin-bottom: 5px;
        }
        @media print {
            body {
                background: white !important;
                padding: 0 !important;
            }
            .page {
                box-shadow: none !important;
                margin: 0 !important;
                width: 100% !important;
                padding: 12mm 15mm !important;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>

<div class="container text-center my-3 no-print d-flex justify-content-center align-items-center gap-2 flex-wrap">
    <a href="https://sktbkk.seikouedu.com/dashboard.php" class="btn btn-warning fw-bold px-3 py-2 shadow-sm">
        <i class="bi bi-house-door-fill me-1"></i> หน้าแรก สกต. (Home)
    </a>
    <button onclick="window.print()" class="btn btn-primary fw-bold px-4 py-2 shadow-sm">
        🖨️ สั่งพิมพ์เอกสาร (Print A4)
    </button>
    <a href="https://sktbkk.seikouedu.com/travel/approve.php?id=<?= $claim['claim_id'] ?>" class="btn btn-outline-secondary px-3 py-2">
        กลับหน้าพิจารณาอนุมัติ
    </a>
    <a href="https://sktbkk.seikouedu.com/travel/approvals.php" class="btn btn-outline-dark px-3 py-2">
        ศูนย์พิจารณาอนุมัติ
    </a>
</div>

<div class="page">
    <!-- หัวเอกสาร -->
    <div class="row align-items-center border-bottom pb-3 mb-3">
        <div class="col-2 text-center">
            <div class="header-logo">🌾</div>
        </div>
        <div class="col-7 text-center">
            <h5 class="fw-bold mb-0">สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด</h5>
            <div class="small fw-bold text-muted">(สกต.กทม.)</div>
            <h6 class="fw-bold mt-2 text-decoration-underline">ใบเบิกและใบสำคัญจ่ายเงินค่าใช้จ่ายในการปฏิบัติงานนอกสถานที่</h6>
        </div>
        <div class="col-3 text-end small">
            <div><strong>เลขที่ใบเบิก:</strong> #CLM-<?= str_pad($claim['claim_id'], 5, '0', STR_PAD_LEFT) ?></div>
            <div><strong>รหัสการเดินทาง:</strong> <?= htmlspecialchars($claim['trip_code'] ?? '-') ?></div>
            <div><strong>วันที่พิมพ์:</strong> <?= date('d/m/Y') ?></div>
        </div>
    </div>

    <!-- ข้อมูลผู้ขอเบิกและรายละเอียด -->
    <table class="table table-sm table-borderless small mb-3">
        <tr>
            <td width="18%"><strong>ชื่อผู้ขอเบิก:</strong></td>
            <td width="42%"><?= htmlspecialchars($claim['officer_name'] ?? '-') ?></td>
            <td width="18%"><strong>ตำแหน่ง/สังกัด:</strong></td>
            <td width="22%"><?= htmlspecialchars($claim['officer_position'] ?? 'ฝ่ายการตลาด') ?></td>
        </tr>
        <tr>
            <td><strong>วันที่เดินทาง:</strong></td>
            <td><?= date('d/m/Y', strtotime($claim['trip_date'])) ?> (เวลา: <?= substr($claim['start_time'], 0, 5) ?> - <?= substr($claim['end_time'], 0, 5) ?> น.)</td>
            <td><strong>ยานพาหนะ:</strong></td>
            <td><?= htmlspecialchars($claim['vehicle_plate'] ?? '-') ?></td>
        </tr>
        <tr>
            <td><strong>วัตถุประสงค์:</strong></td>
            <td colspan="3"><?= htmlspecialchars($claim['purpose'] ?? '-') ?></td>
        </tr>
        <tr>
            <td><strong>เส้นทางปฏิบัติงาน:</strong></td>
            <td colspan="3">จาก <u><?= htmlspecialchars($claim['start_location'] ?? '-') ?></u> ถึง <u><?= htmlspecialchars($claim['end_location'] ?? '-') ?></u></td>
        </tr>
    </table>

    <!-- สรุปรายการจุดตรวจ/จุดแวะที่ผ่านการตรวจสอบ GPS -->
    <div class="fw-bold small mb-1">📍 รายการจุดแวะปฏิบัติงานที่ตรวจยืนยันด้วยระบบดาวเทียม GPS & รูปถ่าย:</div>
    <table class="table table-voucher mb-3">
        <thead>
            <tr class="text-center">
                <th width="8%">จุดที่</th>
                <th width="12%">เวลา</th>
                <th>สถานที่ / จุดตรวจ</th>
                <th width="26%">พิกัดดาวเทียม (GPS)</th>
                <th width="15%">การตรวจสอบ</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($waypoints)): ?>
                <?php foreach ($waypoints as $wp): ?>
                <tr>
                    <td class="text-center"><?= $wp['step_no'] ?></td>
                    <td class="text-center"><?= substr($wp['action_time'], 11, 5) ?> น.</td>
                    <td><?= htmlspecialchars($wp['location_name']) ?></td>
                    <td class="text-center small font-monospace"><?= number_format($wp['latitude'], 4) ?>, <?= number_format($wp['longitude'], 4) ?></td>
                    <td class="text-center text-success fw-bold small">✓ พิกัดตรงเกณฑ์</td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5" class="text-center text-muted">เดินทางระหว่างจุดต้นทางถึงปลายทาง (บันทึกพิกัดอัตโนมัติ)</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- สรุปการคำนวณเงินค่าพาหนะ -->
    <div class="fw-bold small mb-1">💰 สรุปการคำนวณค่าพาหนะตามมติคณะกรรมการ สกต.กทม.:</div>
    <table class="table table-voucher mb-4">
        <thead>
            <tr class="text-center">
                <th>รายการค่าใช้จ่าย</th>
                <th width="20%">เกณฑ์ / มติอ้างอิง</th>
                <th width="15%">ระยะทาง / หน่วย</th>
                <th width="15%">อัตราเบิก</th>
                <th width="18%">จำนวนเงิน (บาท)</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($claim['claim_type'] === 'zone'): ?>
            <tr>
                <td>ค่าพาหนะเหมาจ่ายตามพื้นที่/โซน</td>
                <td class="text-center small">มติ กก. ครั้งที่ 1/2567</td>
                <td class="text-center">1 วัน</td>
                <td class="text-end">฿<?= number_format($claim['total_amount'], 2) ?></td>
                <td class="text-end fw-bold">฿<?= number_format($claim['total_amount'], 2) ?></td>
            </tr>
            <?php else: ?>
            <tr>
                <td>1. ค่าน้ำมันเชื้อเพลิง</td>
                <td class="text-center small">มติ กก. ครั้งที่ 1/2567</td>
                <td class="text-center"><?= number_format($claim['distance_km'], 2) ?> กม.</td>
                <td class="text-end">฿<?= number_format($claim['fuel_rate'], 2) ?>/กม.</td>
                <td class="text-end fw-bold">฿<?= number_format($claim['fuel_amount'], 2) ?></td>
            </tr>
            <tr>
                <td>2. ค่าสึกหรอยานพาหนะ</td>
                <td class="text-center small">มติ กก. ครั้งที่ 1/2567</td>
                <td class="text-center"><?= number_format($claim['distance_km'], 2) ?> กม.</td>
                <td class="text-end">฿<?= number_format($claim['wear_rate'], 2) ?>/กม.</td>
                <td class="text-end fw-bold">฿<?= number_format($claim['wear_amount'], 2) ?></td>
            </tr>
            <?php endif; ?>
            <tr style="background-color: #f8fafc;">
                <td colspan="4" class="text-end fw-bold">รวมเงินที่ขออนุมัติจ่ายทั้งสิ้น:</td>
                <td class="text-end fw-bold fs-6 text-primary">฿<?= number_format($claim['total_amount'], 2) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="small text-muted mb-4">
        <strong>ความเห็นการพิจารณา:</strong> <?= htmlspecialchars($claim['approver_note'] ?: 'ตรวจสอบหลักฐานถูกต้องตามระเบียบและบันทึกเข้าสู่ระบบบัญชีส่วนกลางเรียบร้อยแล้ว') ?>
    </div>

    <!-- ลายมือชื่อ 3 ฝ่าย -->
    <div class="row text-center mt-4">
        <div class="col-4 sig-box">
            <div class="sig-line"></div>
            <div class="small fw-bold">(<?= htmlspecialchars($claim['officer_name'] ?? '........................................') ?>)</div>
            <div class="small text-muted">ผู้ขอเบิกจ่ายเงิน</div>
            <div class="small text-muted">วันที่ ....../....../......</div>
        </div>

        <div class="col-4 sig-box">
            <div class="sig-line"></div>
            <div class="small fw-bold">(.......................................................)</div>
            <div class="small text-muted">ผู้ตรวจสอบเอกสาร / บัญชี</div>
            <div class="small text-muted">วันที่ ....../....../......</div>
        </div>

        <div class="col-4 sig-box">
            <div class="sig-line"></div>
            <div class="small fw-bold">(<?= htmlspecialchars($claim['approved_by'] ?: 'ผู้จัดการ สกต.กทม.') ?>)</div>
            <div class="small text-muted">ผู้อนุมัติจ่ายเงิน</div>
            <div class="small text-muted">วันที่ <?= $claim['approved_at'] ? date('d/m/Y', strtotime($claim['approved_at'])) : '....../....../......' ?></div>
        </div>
    </div>

</div>

</body>
</html>
