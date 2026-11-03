<?php
/**
 * สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)
 * ระบบโอนย้ายสต็อกข้าวสาร (โกดัง สกต. & ร้านค้าตัวแทน)
 * ไฟล์: transfers.php
 * คำอธิบาย: บริหารจัดการการโอนย้ายสต็อกข้าวสารระหว่างโกดังใหญ่ สกต.กทม. และร้านค้าตัวแทนฝากขาย
 *           รองรับสิทธิ์การใช้งานทั้ง Admin, Superadmin, SKT01, ผู้จัดการ และ พนักงานขายหน้าร้าน (Staff)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -------------------------------------------------------------
// 1. เชื่อมต่อฐานข้อมูลแบบปลอดภัย (Auto-detect config)
// -------------------------------------------------------------
$pdo = null;
$possible_db_files = [
    __DIR__ . '/config/database.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/db.php',
    __DIR__ . '/db.php',
    __DIR__ . '/../config/database.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/../db.php',
    __DIR__ . '/api/config.php',
    __DIR__ . '/../api/config.php'
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

// -------------------------------------------------------------
// 2. ตรวจสอบสิทธิ์การเข้าใช้งาน (Authorization)
// -------------------------------------------------------------
$user = $_SESSION['user'] ?? [];
$role = $user['role'] ?? $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';
$username = $user['username'] ?? $_SESSION['username'] ?? '';
$user_id = $user['id'] ?? $_SESSION['user_id'] ?? 1;
$my_store_id = intval($user['store_id'] ?? $_SESSION['store_id'] ?? 0);

$is_admin = in_array($role, ['admin', 'superadmin', 'skt01', 'manager']);
$is_staff = ($role === 'staff' || (strpos($username, 'staff') !== false && !$is_admin));

// หากยังไม่ได้ล็อกอิน หรือไม่มีสิทธิ์ใดๆ ให้กลับหน้าล็อกอิน
if (!$is_admin && !$is_staff) {
    header("Location: https://sktbkk.seikouedu.com/index.php?error=unauthorized");
    exit;
}

// หากเป็น Staff แต่ยังไม่ระบุ store_id ให้ค้นหาจาก username
if ($is_staff && $my_store_id <= 0) {
    if (strpos($username, 'staff01') !== false) {
        $my_store_id = 4; // ธกส สาขาทุ่งครุ
    } elseif (strpos($username, 'staff02') !== false) {
        $my_store_id = 3; // ร้านประเสริฐมินิมาร์ท
    }
}

$alert_success = '';
$alert_error = '';

// ฟังก์ชันดึงสต็อกโกดังกลาง
function get_central_stock($pdo) {
    $stock = ['1kg' => 70, '5kg' => 150, '15kg' => 10];
    if (!$pdo) return $stock;
    try {
        // ลองดึงจากตาราง lots หรือ rice_lots
        $table = 'lots';
        $check = $pdo->query("SHOW TABLES LIKE 'lots'")->fetchColumn();
        if (!$check) {
            $check2 = $pdo->query("SHOW TABLES LIKE 'rice_lots'")->fetchColumn();
            if ($check2) $table = 'rice_lots';
            else $table = null;
        }
        if ($table) {
            $stmt = $pdo->query("SELECT SUM(central_stock_1kg) AS s1, SUM(central_stock_5kg) AS s5, SUM(central_stock_15kg) AS s15 FROM `{$table}`");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && ($row['s1'] !== null || $row['s5'] !== null || $row['s15'] !== null)) {
                $stock['1kg'] = intval($row['s1']);
                $stock['5kg'] = intval($row['s5']);
                $stock['15kg'] = intval($row['s15']);
            }
        }
    } catch (Exception $e) {}
    return $stock;
}

// ฟังก์ชันดึงสต็อกร้านค้า
function get_stores_with_stock($pdo) {
    $stores = [];
    if (!$pdo) return $stores;
    try {
        $stmt = $pdo->query("SELECT id, store_name, contact_name, phone, address, min_stock_alert FROM stores WHERE is_active = 1 ORDER BY id ASC");
        $raw_stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ตรวจสอบตาราง store_inventory
        $has_inv = $pdo->query("SHOW TABLES LIKE 'store_inventory'")->fetchColumn();
        $inv_data = [];
        if ($has_inv) {
            $inv_stmt = $pdo->query("
                SELECT store_id,
                    COALESCE(SUM(CASE WHEN weight_kg = 1 OR product_name LIKE '%1 กก%' OR product_name LIKE '%1กก%' THEN current_stock ELSE 0 END), 0) AS s1,
                    COALESCE(SUM(CASE WHEN weight_kg = 5 OR product_name LIKE '%5 กก%' OR product_name LIKE '%5กก%' THEN current_stock ELSE 0 END), 0) AS s5,
                    COALESCE(SUM(CASE WHEN weight_kg = 15 OR product_name LIKE '%15 กก%' OR product_name LIKE '%15กก%' THEN current_stock ELSE 0 END), 0) AS s15
                FROM store_inventory
                GROUP BY store_id
            ");
            while ($r = $inv_stmt->fetch(PDO::FETCH_ASSOC)) {
                $inv_data[intval($r['store_id'])] = [
                    '1kg' => intval($r['s1']),
                    '5kg' => intval($r['s5']),
                    '15kg' => intval($r['s15'])
                ];
            }
        }

        foreach ($raw_stores as $s) {
            $sid = intval($s['id']);
            if (isset($inv_data[$sid])) {
                $s['stock_1kg'] = $inv_data[$sid]['1kg'];
                $s['stock_5kg'] = $inv_data[$sid]['5kg'];
                $s['stock_15kg'] = $inv_data[$sid]['15kg'];
            } else {
                // ค่าเริ่มต้นตามระบบเดิม
                if ($sid === 3) {
                    $s['stock_1kg'] = 10; $s['stock_5kg'] = 20; $s['stock_15kg'] = 30;
                } elseif ($sid === 4) {
                    $s['stock_1kg'] = 19; $s['stock_5kg'] = 28; $s['stock_15kg'] = 10;
                } else {
                    $s['stock_1kg'] = 10; $s['stock_5kg'] = 20; $s['stock_15kg'] = 5;
                }
            }
            $stores[$sid] = $s;
        }
    } catch (Exception $e) {}
    return $stores;
}

// -------------------------------------------------------------
// 3. ประมวลผล POST Requests
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ---------------------------------------------------------
    // 3.1 ดำเนินการโอนย้ายสต็อก (action = transfer)
    // ---------------------------------------------------------
    if ($action === 'transfer') {
        $transfer_type = $_POST['transfer_type'] ?? 'central_to_store';
        $from_store_id = !empty($_POST['from_store_id']) ? intval($_POST['from_store_id']) : null;
        $to_store_id = !empty($_POST['store_id']) ? intval($_POST['store_id']) : (!empty($_POST['to_store_id']) ? intval($_POST['to_store_id']) : null);
        $qty_1kg  = max(0, intval($_POST['qty_1kg'] ?? 0));
        $qty_5kg  = max(0, intval($_POST['qty_5kg'] ?? 0));
        $qty_15kg = max(0, intval($_POST['qty_15kg'] ?? 0));
        $notes    = trim($_POST['notes'] ?? '');

        // หากเป็นพนักงานหน้าร้าน (Staff) บังคับต้นทางหรือปลายทางให้เป็นร้านของตนเอง
        if ($is_staff) {
            if ($transfer_type === 'central_to_store') {
                $to_store_id = $my_store_id;
                $from_store_id = null;
            } elseif ($transfer_type === 'store_to_central') {
                $from_store_id = $my_store_id;
                $to_store_id = null;
            } elseif ($transfer_type === 'store_to_store') {
                $from_store_id = $my_store_id;
            }
        }

        if ($qty_1kg + $qty_5kg + $qty_15kg <= 0) {
            $alert_error = 'กรุณาระบุจำนวนข้าวสารที่ต้องการโอนย้ายอย่างน้อย 1 ถุง';
        } else {
            try {
                if ($pdo) {
                    $pdo->beginTransaction();

                    // ตรวจสอบตาราง stock_transfers
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `stock_transfers` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `transfer_code` VARCHAR(50) NOT NULL UNIQUE,
                            `transfer_type` VARCHAR(30) NOT NULL,
                            `from_store_id` INT NULL,
                            `to_store_id` INT NULL,
                            `qty_1kg` INT DEFAULT 0,
                            `qty_5kg` INT DEFAULT 0,
                            `qty_15kg` INT DEFAULT 0,
                            `notes` TEXT NULL,
                            `created_by` VARCHAR(100) NULL,
                            `created_at` DATETIME NOT NULL
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");

                    $transfer_code = 'TR-' . date('Ymd-His') . '-' . rand(10, 99);
                    $stmt_ins = $pdo->prepare("
                        INSERT INTO `stock_transfers` 
                        (`transfer_code`, `transfer_type`, `from_store_id`, `to_store_id`, `qty_1kg`, `qty_5kg`, `qty_15kg`, `notes`, `created_by`, `created_at`)
                        VALUES (:code, :type, :from_id, :to_id, :q1, :q5, :q15, :notes, :created_by, NOW())
                    ");
                    $stmt_ins->execute([
                        ':code' => $transfer_code,
                        ':type' => $transfer_type,
                        ':from_id' => $from_store_id,
                        ':to_id' => $to_store_id,
                        ':q1' => $qty_1kg,
                        ':q5' => $qty_5kg,
                        ':q15' => $qty_15kg,
                        ':notes' => $notes,
                        ':created_by' => $username ?: ($is_staff ? 'Staff' : 'Admin')
                    ]);

                    // ตัด/เพิ่ม สต็อกโกดังกลาง (lots หรือ rice_lots)
                    $lot_tbl = $pdo->query("SHOW TABLES LIKE 'lots'")->fetchColumn() ? 'lots' : ($pdo->query("SHOW TABLES LIKE 'rice_lots'")->fetchColumn() ? 'rice_lots' : null);
                    if ($lot_tbl) {
                        if ($transfer_type === 'central_to_store') {
                            $pdo->exec("UPDATE `{$lot_tbl}` SET 
                                central_stock_1kg = GREATEST(0, central_stock_1kg - {$qty_1kg}),
                                central_stock_5kg = GREATEST(0, central_stock_5kg - {$qty_5kg}),
                                central_stock_15kg = GREATEST(0, central_stock_15kg - {$qty_15kg})
                                ORDER BY lot_id ASC LIMIT 1");
                        } elseif ($transfer_type === 'store_to_central') {
                            $pdo->exec("UPDATE `{$lot_tbl}` SET 
                                central_stock_1kg = central_stock_1kg + {$qty_1kg},
                                central_stock_5kg = central_stock_5kg + {$qty_5kg},
                                central_stock_15kg = central_stock_15kg + {$qty_15kg}
                                ORDER BY lot_id ASC LIMIT 1");
                        }
                    }

                    // อัปเดตสต็อกร้านค้าใน store_inventory
                    $has_store_inv = $pdo->query("SHOW TABLES LIKE 'store_inventory'")->fetchColumn();
                    if ($has_store_inv) {
                        // ปลายทางรับเข้า
                        if ($to_store_id) {
                            $pdo->exec("UPDATE `store_inventory` SET current_stock = current_stock + {$qty_1kg} WHERE store_id = {$to_store_id} AND weight_kg = 1");
                            $pdo->exec("UPDATE `store_inventory` SET current_stock = current_stock + {$qty_5kg} WHERE store_id = {$to_store_id} AND weight_kg = 5");
                            $pdo->exec("UPDATE `store_inventory` SET current_stock = current_stock + {$qty_15kg} WHERE store_id = {$to_store_id} AND weight_kg = 15");
                        }
                        // ต้นทางโอนออก
                        if ($from_store_id) {
                            $pdo->exec("UPDATE `store_inventory` SET current_stock = GREATEST(0, current_stock - {$qty_1kg}) WHERE store_id = {$from_store_id} AND weight_kg = 1");
                            $pdo->exec("UPDATE `store_inventory` SET current_stock = GREATEST(0, current_stock - {$qty_5kg}) WHERE store_id = {$from_store_id} AND weight_kg = 5");
                            $pdo->exec("UPDATE `store_inventory` SET current_stock = GREATEST(0, current_stock - {$qty_15kg}) WHERE store_id = {$from_store_id} AND weight_kg = 15");
                        }
                    }

                    $pdo->commit();
                    $alert_success = "บันทึกการโอนย้ายสต็อกเรียบร้อยแล้ว (รหัสใบโอน: {$transfer_code})";
                }
            } catch (Exception $e) {
                if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
                $alert_error = 'เกิดข้อผิดพลาดในการโอนย้าย: ' . $e->getMessage();
            }
        }
    }

    // ---------------------------------------------------------
    // 3.2 ดำเนินการยกเลิกใบโอนย้าย (action = delete_transfer)
    // ---------------------------------------------------------
    if ($action === 'delete_transfer') {
        $transfer_id = intval($_POST['transfer_id'] ?? 0);
        if ($transfer_id > 0 && $pdo) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM `stock_transfers` WHERE id = :id");
                $stmt->execute([':id' => $transfer_id]);
                $t = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($t) {
                    // หากเป็น Staff ต้องเกี่ยวข้องกับร้านตนเอง
                    if ($is_staff && intval($t['from_store_id']) !== $my_store_id && intval($t['to_store_id']) !== $my_store_id) {
                        $alert_error = 'คุณไม่มีสิทธิ์ยกเลิกใบโอนย้ายที่ไม่เกี่ยวข้องกับร้านค้าของคุณ';
                    } else {
                        $pdo->beginTransaction();

                        $q1 = intval($t['qty_1kg']);
                        $q5 = intval($t['qty_5kg']);
                        $q15 = intval($t['qty_15kg']);
                        $type = $t['transfer_type'];
                        $from_id = $t['from_store_id'];
                        $to_id = $t['to_store_id'];

                        // คืนสต็อกโกดังกลาง
                        $lot_tbl = $pdo->query("SHOW TABLES LIKE 'lots'")->fetchColumn() ? 'lots' : ($pdo->query("SHOW TABLES LIKE 'rice_lots'")->fetchColumn() ? 'rice_lots' : null);
                        if ($lot_tbl) {
                            if ($type === 'central_to_store') {
                                $pdo->exec("UPDATE `{$lot_tbl}` SET 
                                    central_stock_1kg = central_stock_1kg + {$q1},
                                    central_stock_5kg = central_stock_5kg + {$q5},
                                    central_stock_15kg = central_stock_15kg + {$q15}
                                    ORDER BY lot_id ASC LIMIT 1");
                            } elseif ($type === 'store_to_central') {
                                $pdo->exec("UPDATE `{$lot_tbl}` SET 
                                    central_stock_1kg = GREATEST(0, central_stock_1kg - {$q1}),
                                    central_stock_5kg = GREATEST(0, central_stock_5kg - {$q5}),
                                    central_stock_15kg = GREATEST(0, central_stock_15kg - {$q15})
                                    ORDER BY lot_id ASC LIMIT 1");
                            }
                        }

                        // คืนสต็อกร้านค้าใน store_inventory
                        $has_store_inv = $pdo->query("SHOW TABLES LIKE 'store_inventory'")->fetchColumn();
                        if ($has_store_inv) {
                            if ($to_id) {
                                $pdo->exec("UPDATE `store_inventory` SET current_stock = GREATEST(0, current_stock - {$q1}) WHERE store_id = {$to_id} AND weight_kg = 1");
                                $pdo->exec("UPDATE `store_inventory` SET current_stock = GREATEST(0, current_stock - {$q5}) WHERE store_id = {$to_id} AND weight_kg = 5");
                                $pdo->exec("UPDATE `store_inventory` SET current_stock = GREATEST(0, current_stock - {$q15}) WHERE store_id = {$to_id} AND weight_kg = 15");
                            }
                            if ($from_id) {
                                $pdo->exec("UPDATE `store_inventory` SET current_stock = current_stock + {$q1} WHERE store_id = {$from_id} AND weight_kg = 1");
                                $pdo->exec("UPDATE `store_inventory` SET current_stock = current_stock + {$q5} WHERE store_id = {$from_id} AND weight_kg = 5");
                                $pdo->exec("UPDATE `store_inventory` SET current_stock = current_stock + {$q15} WHERE store_id = {$from_id} AND weight_kg = 15");
                            }
                        }

                        $del_stmt = $pdo->prepare("DELETE FROM `stock_transfers` WHERE id = :id");
                        $del_stmt->execute([':id' => $transfer_id]);

                        $pdo->commit();
                        $alert_success = "ยกเลิกใบโอนย้าย {$t['transfer_code']} และคืนสต็อกกลับสู่ต้นทางเรียบร้อยแล้ว";
                    }
                } else {
                    $alert_error = 'ไม่พบรหัสใบโอนย้ายที่ต้องการยกเลิก';
                }
            } catch (Exception $e) {
                if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
                $alert_error = 'เกิดข้อผิดพลาดในการยกเลิกใบโอน: ' . $e->getMessage();
            }
        }
    }
}

// -------------------------------------------------------------
// 4. เตรียมข้อมูลแสดงผล
// -------------------------------------------------------------
$centralStock = get_central_stock($pdo);
$stores = get_stores_with_stock($pdo);

// ดึงประวัติการโอนย้ายสต็อก
$transfers = [];
if ($pdo) {
    try {
        if ($is_admin) {
            $sql = "
                SELECT t.*, fs.store_name AS from_store_name, ts.store_name AS to_store_name 
                FROM `stock_transfers` t 
                LEFT JOIN `stores` fs ON t.from_store_id = fs.id 
                LEFT JOIN `stores` ts ON t.to_store_id = ts.id 
                ORDER BY t.created_at DESC LIMIT 100
            ";
            $transfers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare("
                SELECT t.*, fs.store_name AS from_store_name, ts.store_name AS to_store_name 
                FROM `stock_transfers` t 
                LEFT JOIN `stores` fs ON t.from_store_id = fs.id 
                LEFT JOIN `stores` ts ON t.to_store_id = ts.id 
                WHERE t.from_store_id = :sid OR t.to_store_id = :sid 
                ORDER BY t.created_at DESC LIMIT 100
            ");
            $stmt->execute([':sid' => $my_store_id]);
            $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
}

$my_store_name = $stores[$my_store_id]['store_name'] ?? ($is_staff ? 'ร้านค้าตัวแทน' : 'โกดัง สกต.กทม.');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>โอนย้ายสต็อกข้าวสาร (โกดัง &amp; ร้านค้า) - สกต.กทม.</title>
    
    <!-- Google Fonts: Prompt -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        :root {
            --primary-baac: #008751;
            --primary-dark: #005f38;
            --accent-gold: #f59e0b;
            --scb-purple: #4e2a84;
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
            transition: all 0.2s ease-in-out;
        }
        .card-custom:hover {
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }
        .btn-baac {
            background-color: var(--primary-baac);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 500;
        }
        .btn-baac:hover {
            background-color: var(--primary-dark);
            color: white;
        }
        .btn-scb {
            background-color: var(--scb-purple);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 500;
        }
        .text-baac { color: var(--primary-baac); }
        .text-scb { color: var(--scb-purple); }
        .badge-role {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 6px;
        }
        .alert-banner {
            border-radius: 12px;
            font-size: 0.95rem;
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

<?php
if (file_exists(__DIR__ . '/includes/navbar.php')) {
    include __DIR__ . '/includes/navbar.php';
} else {
?>
<nav class="navbar navbar-expand-lg navbar-dark navbar-baac sticky-top py-2">
    <div class="container-fluid px-3">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="https://sktbkk.seikouedu.com/dashboard.php">
            <span class="fs-4">🌾</span>
            <div>
                <span class="d-block lh-1 fs-5">สกต.กทม.</span>
                <small class="text-white-50" style="font-size: 0.72rem; font-weight: normal;">ระบบจัดการข้าวสาร & ฝากขาย</small>
            </div>
        </a>
        <div class="d-flex align-items-center gap-2">
            <a href="https://sktbkk.seikouedu.com/pos.php" class="btn btn-warning btn-sm fw-bold">
                <i class="bi bi-house-door-fill me-1"></i> หน้าแรก สกต. (Home)
            </a>
        </div>
    </div>
</nav>
<?php } ?>

<div class="container-fluid px-3 px-lg-4 py-4">

    <!-- แถบหัวเรื่อง -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-2 border-bottom">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-arrow-left-right text-baac me-2"></i>ระบบโอนย้ายสต็อกข้าวสาร (โกดัง สกต. &amp; ร้านค้าตัวแทน)</h4>
            <p class="text-muted small mb-0">
                <?= $is_staff ? "สำหรับจุดขาย: <strong class='text-primary'>{$my_store_name}</strong> (ควบคุมสต็อกอัตโนมัติ)" : "ควบคุมสต็อกอัตโนมัติ: ห้ามโอนเกินสต็อกคงเหลือของต้นทาง" ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-baac px-3 py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#modalTransfer" onclick="onSourceChange()">
                <i class="bi bi-send-plus me-1"></i> ทำรายการโอนย้ายสต็อก
            </button>
        </div>
    </div>

    <!-- แจ้งเตือนสถานะการทำงาน -->
    <?php if ($alert_success): ?>
        <div class="alert alert-success alert-dismissible fade show alert-banner d-flex align-items-center mb-4" role="alert">
            <i class="bi bi-check-circle-fill fs-5 me-2"></i>
            <div><?= htmlspecialchars($alert_success) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($alert_error): ?>
        <div class="alert alert-danger alert-dismissible fade show alert-banner d-flex align-items-center mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-5 me-2"></i>
            <div><?= htmlspecialchars($alert_error) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- แผงสรุปสต็อกคงเหลือปัจจุบัน -->
    <div class="row g-3 mb-4">
        <!-- โกดัง สกต. -->
        <div class="col-md-4">
            <div class="card card-custom p-3 bg-white border-start border-4 border-success h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold text-baac mb-0"><i class="bi bi-building me-1"></i> โกดังใหญ่ สกต.กทม.</h6>
                    <span class="badge bg-success">คลังหลัก</span>
                </div>
                <div class="p-2 bg-light rounded border d-flex justify-content-around text-center mt-2">
                    <div>
                        <small class="text-muted d-block">ขนาด 1 กก.</small>
                        <strong class="fs-5 text-dark"><?= intval($centralStock['1kg']) ?></strong> <small>ถุง</small>
                    </div>
                    <div class="border-start ps-3">
                        <small class="text-muted d-block">ขนาด 5 กก.</small>
                        <strong class="fs-5 text-dark"><?= intval($centralStock['5kg']) ?></strong> <small>ถุง</small>
                    </div>
                    <div class="border-start ps-3">
                        <small class="text-muted d-block">ขนาด 15 กก.</small>
                        <strong class="fs-5 text-dark"><?= intval($centralStock['15kg']) ?></strong> <small>ถุง</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- สต็อกแต่ละร้านค้า -->
        <div class="col-md-8">
            <div class="card card-custom p-3 bg-white h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold text-primary mb-0"><i class="bi bi-shop me-1"></i> สต็อกคงเหลือประจำร้านค้าตัวแทนขณะนี้</h6>
                    <?php if ($is_staff): ?>
                        <span class="badge bg-primary">ร้านของคุณ: <?= htmlspecialchars($my_store_name) ?></span>
                    <?php endif; ?>
                </div>
                <div class="row g-2">
                    <?php foreach ($stores as $sid => $st): ?>
                        <?php 
                        $is_this_my_store = ($is_staff && $sid === $my_store_id); 
                        if ($is_staff && !$is_this_my_store) continue; // หากเป็น staff แสดงเฉพาะร้านตนเองเป็นหลัก
                        ?>
                        <div class="<?= $is_staff ? 'col-12' : 'col-sm-6 col-lg-4' ?>">
                            <div class="p-2 border rounded <?= $is_this_my_store ? 'bg-primary-subtle border-primary' : 'bg-light' ?> small">
                                <div class="fw-bold text-truncate <?= $is_this_my_store ? 'text-primary' : '' ?>">
                                    <?= htmlspecialchars($st['store_name']) ?>
                                    <?= $is_this_my_store ? '<span class="badge bg-primary ms-1">ร้านของคุณ</span>' : '' ?>
                                </div>
                                <div class="text-muted mt-1">
                                    1กก: <b class="text-dark"><?= intval($st['stock_1kg'] ?? 0) ?></b> | 
                                    5กก: <b class="text-dark"><?= intval($st['stock_5kg'] ?? 0) ?></b> | 
                                    15กก: <b class="text-dark"><?= intval($st['stock_15kg'] ?? 0) ?></b> ถุง
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ตารางประวัติการโอนย้ายสต็อก -->
    <div class="card card-custom p-4 bg-white">
        <h5 class="fw-bold mb-3">
            <i class="bi bi-clock-history me-2 text-baac"></i>
            <?= $is_staff ? "ประวัติการโอนย้ายสต็อกของร้าน (" . htmlspecialchars($my_store_name) . ")" : "ประวัติการโอนย้ายสต็อกทั้งหมด" ?>
        </h5>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small">
                    <tr>
                        <th>รหัสใบโอน</th>
                        <th>วันที่ / เวลา</th>
                        <th>ประเภท</th>
                        <th>เส้นทางการโอน</th>
                        <th class="text-center">1 กก.</th>
                        <th class="text-center">5 กก.</th>
                        <th class="text-center">15 กก.</th>
                        <th class="text-center">รวม (ถุง)</th>
                        <th>หมายเหตุ</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transfers)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-4 text-muted">
                                <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                                ยังไม่มีรายการโอนย้ายสต็อกในระบบ
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transfers as $t): ?>
                            <?php
                            $type_badge = '<span class="badge bg-secondary">ไม่ระบุ</span>';
                            $from_text = '<strong>โกดัง สกต.กทม.</strong>';
                            $to_text = '<strong>โกดัง สกต.กทม.</strong>';

                            if ($t['transfer_type'] === 'central_to_store') {
                                $type_badge = '<span class="badge bg-success">โกดัง ➔ ร้านค้า</span>';
                                $from_text = '<strong>โกดัง สกต.กทม.</strong>';
                                $to_text = '<strong class="text-primary">' . htmlspecialchars($t['to_store_name'] ?? ('ร้านค้า #' . $t['to_store_id'])) . '</strong>';
                            } elseif ($t['transfer_type'] === 'store_to_central') {
                                $type_badge = '<span class="badge bg-warning text-dark">ร้านค้า ➔ คืนโกดัง</span>';
                                $from_text = '<strong class="text-danger">' . htmlspecialchars($t['from_store_name'] ?? ('ร้านค้า #' . $t['from_store_id'])) . '</strong>';
                                $to_text = '<strong>โกดัง สกต.กทม.</strong>';
                            } elseif ($t['transfer_type'] === 'store_to_store') {
                                $type_badge = '<span class="badge bg-primary">ร้านค้า ➔ ร้านค้า</span>';
                                $from_text = '<strong class="text-danger">' . htmlspecialchars($t['from_store_name'] ?? ('ร้านค้า #' . $t['from_store_id'])) . '</strong>';
                                $to_text = '<strong class="text-primary">' . htmlspecialchars($t['to_store_name'] ?? ('ร้านค้า #' . $t['to_store_id'])) . '</strong>';
                            }

                            $q1 = intval($t['qty_1kg']);
                            $q5 = intval($t['qty_5kg']);
                            $q15 = intval($t['qty_15kg']);
                            $total_b = $q1 + $q5 + $q15;
                            ?>
                            <tr>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($t['transfer_code']) ?></span></td>
                                <td class="small text-nowrap"><?= date('d/m/Y H:i', strtotime($t['created_at'])) ?></td>
                                <td><?= $type_badge ?></td>
                                <td><?= $from_text ?> <i class="bi bi-arrow-right text-muted mx-1"></i> <?= $to_text ?></td>
                                <td class="text-center"><?= $q1 ?></td>
                                <td class="text-center"><?= $q5 ?></td>
                                <td class="text-center"><?= $q15 ?></td>
                                <td class="text-center fw-bold text-baac"><?= $total_b ?> ถุง</td>
                                <td class="small text-muted"><?= htmlspecialchars($t['notes'] ?: '-') ?></td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmDeleteTransfer(<?= intval($t['id']) ?>, '<?= htmlspecialchars($t['transfer_code']) ?>')" title="ยกเลิกรายการและดึงสต็อกกลับ">
                                        <i class="bi bi-trash3"></i> ยกเลิก
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Form ซ่อนสำหรับยกเลิกใบโอนย้าย -->
<form id="formDeleteTransfer" method="POST" class="d-none">
    <input type="hidden" name="action" value="delete_transfer">
    <input type="hidden" name="transfer_id" id="delete_transfer_id">
</form>

<!-- Modal ทำรายการโอนย้ายสต็อก -->
<div class="modal fade" id="modalTransfer" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <form method="POST" id="formTransfer" onsubmit="return validateBeforeSubmit(event)">
                <input type="hidden" name="action" value="transfer">
                <div class="modal-header bg-baac text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-arrow-left-right me-2"></i>ทำรายการโอนย้ายสต็อกข้าวสาร</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">

                    <!-- 1. เลือกรูปแบบการโอนย้าย -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">1. เลือกรูปแบบการโอนย้าย:</label>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <input type="radio" class="btn-check" name="transfer_type" id="type_c2s" value="central_to_store" checked onchange="toggleTransferFields()">
                                <label class="btn btn-outline-success w-100 py-2 text-start" for="type_c2s">
                                    <i class="bi bi-truck fs-5 d-block mb-1"></i>
                                    <strong>โกดัง สกต. ➔ ร้านค้า</strong>
                                    <small class="d-block opacity-75">ส่งข้าวไปวางขายที่ร้าน</small>
                                </label>
                            </div>
                            <div class="col-md-4">
                                <input type="radio" class="btn-check" name="transfer_type" id="type_s2s" value="store_to_store" onchange="toggleTransferFields()">
                                <label class="btn btn-outline-primary w-100 py-2 text-start" for="type_s2s">
                                    <i class="bi bi-arrow-left-right fs-5 d-block mb-1"></i>
                                    <strong>ร้านค้า ➔ ร้านค้าอื่น</strong>
                                    <small class="d-block opacity-75">โยกสต็อกระหว่างร้าน</small>
                                </label>
                            </div>
                            <div class="col-md-4">
                                <input type="radio" class="btn-check" name="transfer_type" id="type_s2c" value="store_to_central" onchange="toggleTransferFields()">
                                <label class="btn btn-outline-warning text-dark w-100 py-2 text-start" for="type_s2c">
                                    <i class="bi bi-building-up fs-5 d-block mb-1"></i>
                                    <strong>ร้านค้า ➔ คืนโกดัง สกต.</strong>
                                    <small class="d-block opacity-75">ดึงข้าวกลับเข้าคลังใหญ่</small>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- 2. เลือกต้นทางและปลายทาง -->
                    <div class="row g-3 mb-3">
                        <!-- ต้นทาง (เมื่อโอนจากร้านค้า) -->
                        <div class="col-md-6 d-none" id="box_from_store">
                            <label class="form-label fw-semibold text-danger"><i class="bi bi-box-arrow-up-right me-1"></i> ร้านค้าต้นทาง (โอนออก):</label>
                            <select name="from_store_id" id="from_store_id" class="form-select" onchange="onSourceChange()">
                                <?php foreach ($stores as $sid => $st): ?>
                                    <?php if ($is_staff && $sid !== $my_store_id) continue; ?>
                                    <option value="<?= $sid ?>" <?= ($is_staff && $sid === $my_store_id) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($st['store_name']) ?> (เหลือ 1กก:<?= intval($st['stock_1kg'] ?? 0) ?>, 5กก:<?= intval($st['stock_5kg'] ?? 0) ?>, 15กก:<?= intval($st['stock_15kg'] ?? 0) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- ปลายทาง (เมื่อโอนไปร้านค้า) -->
                        <div class="col-md-12" id="box_to_store">
                            <label class="form-label fw-semibold text-primary"><i class="bi bi-box-arrow-in-down-right me-1"></i> ร้านค้าปลายทาง (รับเข้า):</label>
                            <select name="store_id" id="to_store_id" class="form-select">
                                <?php foreach ($stores as $sid => $st): ?>
                                    <?php if ($is_staff && $sid !== $my_store_id) continue; ?>
                                    <option value="<?= $sid ?>" <?= ($is_staff && $sid === $my_store_id) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($st['store_name']) ?> (เหลือ 1กก:<?= intval($st['stock_1kg'] ?? 0) ?>, 5กก:<?= intval($st['stock_5kg'] ?? 0) ?>, 15กก:<?= intval($st['stock_15kg'] ?? 0) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- แถบแจ้งเตือนสีแดงเมื่อกรอกตัวเลขเกินสต็อก -->
                    <div id="overStockAlert" class="alert alert-danger d-none py-2 px-3 mb-3 shadow-sm">
                        <i class="bi bi-exclamation-octagon-fill me-2 fs-5"></i>
                        <strong id="overStockMsg">โอนเกินสต็อกคงเหลือ!</strong>
                    </div>

                    <!-- 3. ระบุจำนวนถุง (พร้อมแสดงจำนวนคงเหลือสูงสุดที่โอนได้) -->
                    <div class="p-3 bg-light rounded-3 border mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-bold mb-0">ระบุจำนวนถุงที่ต้องการโอนย้าย:</label>
                            <span class="badge bg-dark" id="sourceLabelBadge">โอนออกจาก: โกดัง สกต.กทม.</span>
                        </div>

                        <div class="row g-3">
                            <!-- ขนาด 1 กก. -->
                            <div class="col-md-4">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span>ขนาด 1 กก. (ถุง):</span>
                                    <span class="text-success fw-bold">มีอยู่: <span id="max_1kg">0</span> ถุง</span>
                                </div>
                                <input type="number" min="0" name="qty_1kg" id="inp_qty_1kg" class="form-control" value="0" oninput="checkStockLimit('1kg')" required>
                                <div class="invalid-feedback fw-bold">⚠️ โอนเกินสต็อก! (มีแค่ <span id="err_max_1kg">0</span> ถุง)</div>
                            </div>

                            <!-- ขนาด 5 กก. -->
                            <div class="col-md-4">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span>ขนาด 5 กก. (ถุง):</span>
                                    <span class="text-success fw-bold">มีอยู่: <span id="max_5kg">0</span> ถุง</span>
                                </div>
                                <input type="number" min="0" name="qty_5kg" id="inp_qty_5kg" class="form-control" value="0" oninput="checkStockLimit('5kg')" required>
                                <div class="invalid-feedback fw-bold">⚠️ โอนเกินสต็อก! (มีแค่ <span id="err_max_5kg">0</span> ถุง)</div>
                            </div>

                            <!-- ขนาด 15 กก. -->
                            <div class="col-md-4">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span>ขนาด 15 กก. (ถุง):</span>
                                    <span class="text-success fw-bold">มีอยู่: <span id="max_15kg">0</span> ถุง</span>
                                </div>
                                <input type="number" min="0" name="qty_15kg" id="inp_qty_15kg" class="form-control" value="0" oninput="checkStockLimit('15kg')" required>
                                <div class="invalid-feedback fw-bold">⚠️ โอนเกินสต็อก! (มีแค่ <span id="err_max_15kg">0</span> ถุง)</div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small text-muted">หมายเหตุ / เหตุผลการโอนย้าย / ทะเบียนรถ:</label>
                        <input type="text" name="notes" class="form-control" placeholder="เช่น โยกข้าวไปช่วยร้านที่ขายดี หรือ ส่งข้าววางขายหน้าร้าน">
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" id="btnSubmitTransfer" class="btn btn-baac px-4">
                        <i class="bi bi-check2-circle me-1"></i> ยืนยันการโอนย้ายสต็อก
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ข้อมูลสต็อกคงเหลือของโกดัง สกต. และของแต่ละร้านค้า
const centralStockData = <?= json_encode($centralStock) ?>;
const storeStockData = <?= json_encode(array_map(function($s) {
    return [
        'name' => $s['store_name'],
        '1kg' => intval($s['stock_1kg'] ?? 0),
        '5kg' => intval($s['stock_5kg'] ?? 0),
        '15kg' => intval($s['stock_15kg'] ?? 0)
    ];
}, $stores), JSON_UNESCAPED_UNICODE) ?>;

let currentMaxLimits = { '1kg': 0, '5kg': 0, '15kg': 0 };

function toggleTransferFields() {
    const isC2S = document.getElementById('type_c2s').checked;
    const isS2S = document.getElementById('type_s2s').checked;
    const isS2C = document.getElementById('type_s2c').checked;

    const boxFrom = document.getElementById('box_from_store');
    const boxTo = document.getElementById('box_to_store');

    if (isC2S) {
        boxFrom.classList.add('d-none');
        boxTo.classList.remove('d-none');
        boxTo.className = 'col-md-12';
    } else if (isS2S) {
        boxFrom.classList.remove('d-none');
        boxTo.classList.remove('d-none');
        boxFrom.className = 'col-md-6';
        boxTo.className = 'col-md-6';
        const fromSelect = document.getElementById('from_store_id');
        const toSelect = document.getElementById('to_store_id');
        if (fromSelect && toSelect && fromSelect.value === toSelect.value && toSelect.options.length > 1) {
            toSelect.selectedIndex = (fromSelect.selectedIndex + 1) % toSelect.options.length;
        }
    } else if (isS2C) {
        boxFrom.classList.remove('d-none');
        boxFrom.className = 'col-md-12';
        boxTo.classList.add('d-none');
    }

    onSourceChange();
}

function onSourceChange() {
    const isC2S = document.getElementById('type_c2s').checked;
    const badge = document.getElementById('sourceLabelBadge');

    if (isC2S) {
        currentMaxLimits = { ...centralStockData };
        badge.innerText = 'โอนออกจาก: โกดังใหญ่ สกต.กทม.';
    } else {
        const fromStoreId = document.getElementById('from_store_id') ? document.getElementById('from_store_id').value : '';
        const st = storeStockData[fromStoreId] || { name: '-', '1kg': 0, '5kg': 0, '15kg': 0 };
        currentMaxLimits = {
            '1kg': st['1kg'],
            '5kg': st['5kg'],
            '15kg': st['15kg']
        };
        badge.innerText = 'โอนออกจาก: ' + st.name;
    }

    ['1kg', '5kg', '15kg'].forEach(size => {
        document.getElementById('max_' + size).innerText = currentMaxLimits[size];
        document.getElementById('err_max_' + size).innerText = currentMaxLimits[size];
    });

    checkStockLimit();
}

function checkStockLimit(changedSize = null) {
    let hasOverStock = false;
    let overMessages = [];

    ['1kg', '5kg', '15kg'].forEach(size => {
        const inp = document.getElementById('inp_qty_' + size);
        const val = parseInt(inp.value || '0', 10);
        const maxVal = currentMaxLimits[size];

        if (val > maxVal) {
            inp.classList.add('is-invalid');
            hasOverStock = true;
            overMessages.push(`ขนาด ${size} โอนเกินสต็อก (ระบุ ${val} ถุง แต่มีสต็อกคงเหลือเพียง ${maxVal} ถุง)`);
        } else {
            inp.classList.remove('is-invalid');
        }
    });

    const alertBox = document.getElementById('overStockAlert');
    const alertMsg = document.getElementById('overStockMsg');
    const btnSubmit = document.getElementById('btnSubmitTransfer');

    if (hasOverStock) {
        alertBox.classList.remove('d-none');
        alertMsg.innerHTML = 'ห้ามโอนเกินสต็อกคงเหลือ!<br><small>' + overMessages.join('<br>') + '</small>';
        btnSubmit.disabled = true;

        if (changedSize) {
            const inp = document.getElementById('inp_qty_' + changedSize);
            const val = parseInt(inp.value || '0', 10);
            const maxVal = currentMaxLimits[changedSize];
            if (val > maxVal) {
                Swal.fire({
                    icon: 'error',
                    title: 'โอนเกินสต็อกคงเหลือ!',
                    html: `ข้าวขนาด <strong>${changedSize}</strong> มีสต็อกคงเหลือเพียง <strong>${maxVal} ถุง</strong><br>ไม่สามารถโอนจำนวน <strong>${val} ถุง</strong> ได้`,
                    confirmButtonColor: '#dc3545',
                    confirmButtonText: 'รับทราบ'
                });
            }
        }
    } else {
        alertBox.classList.add('d-none');
        btnSubmit.disabled = false;
    }

    return !hasOverStock;
}

function validateBeforeSubmit(e) {
    if (!checkStockLimit()) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'โอนเกินสต็อกคงเหลือ!',
            text: 'กรุณาปรับจำนวนถุงให้ไม่เกินสต็อกที่มีอยู่ก่อนยืนยัน',
            confirmButtonColor: '#dc3545'
        });
        return false;
    }

    const q1 = parseInt(document.getElementById('inp_qty_1kg').value || '0', 10);
    const q5 = parseInt(document.getElementById('inp_qty_5kg').value || '0', 10);
    const q15 = parseInt(document.getElementById('inp_qty_15kg').value || '0', 10);

    if (q1 + q5 + q15 <= 0) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'ยังไม่ได้ระบุจำนวนถุง',
            text: 'กรุณาระบุจำนวนข้าวที่ต้องการโอนอย่างน้อย 1 ถุง',
            confirmButtonColor: '#008751'
        });
        return false;
    }

    return true;
}

function confirmDeleteTransfer(id, code) {
    Swal.fire({
        title: 'ยกเลิกรายการโอนย้าย?',
        html: `ต้องการยกเลิกใบโอน <strong>${code}</strong> ใช่หรือไม่?<br><small class="text-danger">ระบบจะดึงสต็อกกลับคืนสู่ต้นทางอัตโนมัติ</small>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'ยืนยัน ยกเลิกรายการ',
        cancelButtonText: 'ปิด'
    }).then((res) => {
        if (res.isConfirmed) {
            document.getElementById('delete_transfer_id').value = id;
            document.getElementById('formDeleteTransfer').submit();
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    onSourceChange();
});
</script>

</body>
</html>
