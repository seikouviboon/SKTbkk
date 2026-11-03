<?php
/**
 * สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)
 * ระบบคืนค่าข้อมูลเดิมอัตโนมัติ (1-Click Database Restore after Board Meeting)
 * ไฟล์: restore_initial_data.php
 */

session_start();
header('Content-Type: text/html; charset=utf-8');

function get_db_connection() {
    $possible_files = [
        __DIR__ . '/config/database.php',
        __DIR__ . '/config.php',
        __DIR__ . '/includes/db.php',
        __DIR__ . '/db.php',
        __DIR__ . '/../config/database.php',
        __DIR__ . '/../config.php',
        __DIR__ . '/../includes/db.php',
        __DIR__ . '/../db.php',
        __DIR__ . '/api/config.php'
    ];

    foreach ($possible_files as $f) {
        if (file_exists($f)) {
            require_once $f;
            break;
        }
    }

    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) return $GLOBALS['db'];
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) return $GLOBALS['conn'];

    if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER')) {
        $pass = defined('DB_PASS') ? DB_PASS : (defined('DB_PASSWORD') ? DB_PASSWORD : '');
        try {
            return new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (Exception $e) {}
    }
    return null;
}

$db = get_db_connection();
$backup_file = __DIR__ . '/backups/backup_before_board_meeting_20261103.sql';
$backup_file_root = __DIR__ . '/backup_before_board_meeting_20261103.sql';
$file_to_use = file_exists($backup_file) ? $backup_file : (file_exists($backup_file_root) ? $backup_file_root : null);

$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$msg = null;
$error = null;

if ($action === 'confirm_restore') {
    if (!$db) {
        $error = "ไม่สามารถเชื่อมต่อฐานข้อมูลได้";
    } elseif (!$file_to_use) {
        $error = "ไม่พบไฟล์สำรองข้อมูลเริ่มต้น (backup_before_board_meeting_20261103.sql)";
    } else {
        $sql = file_get_contents($file_to_use);
        if (empty($sql)) {
            $error = "ไฟล์สำรองข้อมูลว่างเปล่า";
        } else {
            try {
                $db->exec("SET FOREIGN_KEY_CHECKS = 0;");
                $db->exec($sql);
                $db->exec("SET FOREIGN_KEY_CHECKS = 1;");
                $msg = "คืนค่าข้อมูลเดิมทั้งหมดกลับสู่จุดเริ่มต้นก่อนการประชุมคณะกรรมการเรียบร้อยแล้ว 100%!";
            } catch (Exception $e) {
                $error = "เกิดข้อผิดพลาดในการคืนค่าข้อมูล: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>คืนค่าข้อมูลเดิม (Restore Database) — สกต.กทม.</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: 'Prompt', sans-serif; background-color: #f8fafc; padding: 40px 15px; }
        .card-custom { max-width: 650px; margin: auto; border-radius: 16px; border: none; box-shadow: 0 8px 30px rgba(0,0,0,0.08); }
        .btn-baac { background-color: #008751; color: white; border-radius: 10px; font-weight: 600; padding: 12px 24px; }
        .btn-baac:hover { background-color: #005f38; color: white; }
    </style>
</head>
<body>

<div class="card card-custom p-4 p-md-5 bg-white text-center">

    <!-- ปุ่ม Home สีทองเด่นชัด -->
    <div class="mb-4 text-start">
        <a href="https://sktbkk.seikouedu.com/dashboard.php" class="btn btn-warning btn-sm fw-bold px-3 py-2 shadow-sm d-inline-flex align-items-center gap-1" style="border-radius: 8px;">
            <i class="bi bi-house-door-fill text-dark"></i>
            <span class="text-dark">หน้าแรก สกต. (Home)</span>
        </a>
    </div>

    <?php if ($msg): ?>
        <div class="fs-1 mb-2">🎉</div>
        <h3 class="fw-bold mb-2 text-success">คืนค่าข้อมูลเดิมเรียบร้อยแล้ว!</h3>
        <p class="text-muted mb-4">
            ฐานข้อมูลถูกย้อนกลับสู่จุดเริ่มต้นก่อนการสร้าง Mock-up เรียบร้อยแล้ว (ข้อมูลจริงปลอดภัย 100%)
        </p>
        <div class="alert alert-success text-start small mb-4">
            <i class="bi bi-check2-circle me-1"></i> <?= htmlspecialchars($msg) ?>
        </div>
        <div class="d-grid gap-2 d-sm-flex justify-content-sm-center">
            <a href="https://sktbkk.seikouedu.com/financial_reports.php" class="btn btn-baac">
                <i class="bi bi-journal-bookmark-fill me-1"></i>ดูรายงานการเงินประจำเดือน
            </a>
            <a href="https://sktbkk.seikouedu.com/dashboard.php" class="btn btn-outline-secondary px-4 py-2" style="border-radius: 10px;">
                <i class="bi bi-grid-fill me-1"></i>ไปแดชบอร์ดภาพรวม
            </a>
        </div>

    <?php elseif ($error): ?>
        <div class="fs-1 mb-2">⚠️</div>
        <h3 class="fw-bold mb-2 text-danger">เกิดข้อผิดพลาดในการคืนค่า</h3>
        <p class="text-muted mb-4"><?= htmlspecialchars($error) ?></p>
        <a href="backup_and_mockup_finance.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับหน้าจัดการระบบสำรองข้อมูล
        </a>

    <?php else: ?>
        <div class="fs-1 mb-2">⏪</div>
        <h3 class="fw-bold mb-2 text-danger">ยืนยันคืนค่าข้อมูลเดิม (Restore Database)</h3>
        <p class="text-muted mb-4">
            คุณต้องการล้าง Mock-up ข้อมูลการเงินสำหรับการประชุมคณะกรรมการ<br>
            และ <strong>ย้อนกลับสู่ข้อมูลเดิมก่อนทำ Mock-up 100%</strong> ใช่หรือไม่?
        </p>

        <?php if ($file_to_use): ?>
            <div class="alert alert-info text-start small mb-4">
                <div><i class="bi bi-file-earmark-check me-1"></i> <strong>ไฟล์สำรองที่พร้อมใช้งาน:</strong> <code>backup_before_board_meeting_20261103.sql</code></div>
                <div class="text-muted mt-1">ขนาด: <?= number_format(filesize($file_to_use) / 1024, 2) ?> KB | วันที่สำรอง: <?= date('d/m/Y H:i:s', filemtime($file_to_use)) ?></div>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="confirm_restore">
                <div class="d-grid gap-2 d-sm-flex justify-content-sm-center">
                    <button type="submit" class="btn btn-danger px-4 py-3 fw-bold shadow-sm" style="border-radius: 10px;" onclick="return confirm('ยืนยันล้าง Mock-up และคืนค่าข้อมูลเดิมเดี๋ยวนี้?')">
                        <i class="bi bi-arrow-counterclockwise me-2"></i>ยืนยัน คืนค่าข้อมูลเดิมเดี๋ยวนี้
                    </button>
                    <a href="https://sktbkk.seikouedu.com/financial_reports.php" class="btn btn-outline-secondary px-4 py-3" style="border-radius: 10px;">
                        ยกเลิก / กลับหน้ารายงาน
                    </a>
                </div>
            </form>
        <?php else: ?>
            <div class="alert alert-warning text-start small mb-4">
                <i class="bi bi-exclamation-triangle-fill me-1"></i> ไม่พบไฟล์สำรองข้อมูลเดิม กรุณาเปิด <a href="backup_and_mockup_finance.php" class="fw-bold">ระบบสำรองข้อมูล</a> เพื่อตรวจสอบ
            </div>
        <?php endif; ?>

    <?php endif; ?>

</div>

</body>
</html>
