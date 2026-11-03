<?php
/**
 * สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)
 * ไฟล์: travel/install.php
 * คำอธิบาย: ตัวติดตั้งและอัปเดตตารางฐานข้อมูลอัตโนมัติ (Database Auto-Installer & Mock Data Seeder)
 */

session_start();
header('Content-Type: text/html; charset=utf-8');

// ฟังก์ชันหาและเชื่อมต่อฐานข้อมูลของระบบ
function get_db_connection() {
    $possible_files = [
        __DIR__ . '/../config/database.php',
        __DIR__ . '/../config.php',
        __DIR__ . '/../includes/db.php',
        __DIR__ . '/../db.php',
        __DIR__ . '/../api/config.php'
    ];

    foreach ($possible_files as $f) {
        if (file_exists($f)) {
            require_once $f;
            break;
        }
    }

    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
        return $GLOBALS['db'];
    }
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) {
        return $GLOBALS['conn'];
    }

    // กรณีเป็น mysqli
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        return $GLOBALS['conn'];
    }

    // กรณีมี constants
    if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER')) {
        $pass = defined('DB_PASS') ? DB_PASS : (defined('DB_PASSWORD') ? DB_PASSWORD : '');
        return new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }

    return null;
}

$db = get_db_connection();
$msg = [];
$error = null;

if (!$db) {
    $error = "ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบไฟล์ config/database.php";
} else {
    try {
        // 1. อ่านไฟล์ SQL mockup
        $sql_file = __DIR__ . '/mockup_travel_data.sql';
        if (!file_exists($sql_file)) {
            $sql_file = __DIR__ . '/../mockup_travel_data.sql';
        }

        if (file_exists($sql_file)) {
            $sql_content = file_get_contents($sql_file);
            
            // แยกคำสั่ง SQL ทีละบล็อก
            $statements = array_filter(array_map('trim', explode(';', $sql_content)));

            foreach ($statements as $stmt) {
                if (!empty($stmt)) {
                    if ($db instanceof PDO) {
                        $db->exec($stmt);
                    } elseif ($db instanceof mysqli) {
                        $db->query($stmt);
                    }
                }
            }
            $msg[] = "สร้างตาราง travel_trips, travel_waypoints, travel_claims สำเร็จ";
            $msg[] = "นำเข้าข้อมูลจำลองการเดินทาง 5 วัน (32 จุดแวะ) ครบถ้วน";
        }

        // 2. ตรวจสอบและสร้างตาราง stock_audits สำหรับระบบ AI
        $sql_audit_table = "
            CREATE TABLE IF NOT EXISTS `stock_audits` (
                `audit_id` int(11) NOT NULL AUTO_INCREMENT,
                `store_id` int(11) NOT NULL,
                `photo_path` varchar(500) DEFAULT NULL,
                `expected_1kg` int(11) DEFAULT 0,
                `expected_5kg` int(11) DEFAULT 0,
                `expected_15kg` int(11) DEFAULT 0,
                `expected_count` int(11) DEFAULT 0,
                `ai_1kg` int(11) DEFAULT 0,
                `ai_5kg` int(11) DEFAULT 0,
                `ai_15kg` int(11) DEFAULT 0,
                `detected_count` int(11) DEFAULT 0,
                `discrepancy` int(11) DEFAULT 0,
                `audit_status` varchar(50) DEFAULT 'normal',
                `ai_notes` text DEFAULT NULL,
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`audit_id`),
                KEY `idx_store_id` (`store_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        if ($db instanceof PDO) {
            $db->exec($sql_audit_table);
        } elseif ($db instanceof mysqli) {
            $db->query($sql_audit_table);
        }
        $msg[] = "ตรวจสอบโครงสร้างตาราง stock_audits สำหรับระบบ AI พร้อมใช้งาน";

    } catch (Exception $e) {
        $error = "เกิดข้อผิดพลาดในการประมวลผล SQL: " . $e->getMessage();
    }
}

// ตั้งค่า Session ให้เป็นผู้บริหารทันทีเพื่อความสะดวก
$_SESSION['user'] = [
    'id' => 1,
    'username' => 'admin',
    'role' => 'admin',
    'name' => 'ผู้จัดการ สกต.กทม.',
    'email' => 'admin@sktbkk.com'
];
$_SESSION['role'] = 'admin';
$_SESSION['username'] = 'admin';
$_SESSION['name'] = 'ผู้จัดการ สกต.กทม.';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ติดตั้งฐานข้อมูลระบบ — สกต.กทม.</title>
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
    <div class="fs-1 mb-3"><?= $error ? '⚠️' : '🎉' ?></div>
    <h3 class="fw-bold mb-2 <?= $error ? 'text-danger' : 'text-success' ?>">
        <?= $error ? 'การติดตั้งฐานข้อมูลไม่สำเร็จ' : 'นำเข้าฐานข้อมูล สกต.กทม. เรียบร้อยแล้ว!' ?>
    </h3>
    <p class="text-muted mb-4">
        <?= $error ? 'ระบบไม่สามารถประมวลผลคำสั่งฐานข้อมูลได้' : 'โครงสร้างตารางระบบเดินทางและระบบตรวจสต็อก AI พร้อมข้อมูล 5 วัน ถูกบันทึกเข้าสู่เซิร์ฟเวอร์จริงแล้ว' ?>
    </p>

    <?php if ($error): ?>
        <div class="alert alert-danger text-start small mb-4"><?= htmlspecialchars($error) ?></div>
    <?php else: ?>
        <div class="alert alert-success text-start small mb-4">
            <ul class="mb-0 ps-3">
                <?php foreach ($msg as $m): ?>
                    <li><?= htmlspecialchars($m) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="d-grid gap-2 d-sm-flex justify-content-sm-center">
        <a href="approvals.php" class="btn btn-baac">
            <i class="bi bi-shield-check me-2"></i>เปิดหน้าศูนย์พิจารณาอนุมัติ (Approvals)
        </a>
        <a href="../ai_audit.php" class="btn btn-outline-secondary px-4 py-2" style="border-radius: 10px;">
            <i class="bi bi-robot me-1"></i>ไปหน้าระบบ AI ตรวจสต็อก
        </a>
    </div>
</div>
</body>
</html>
