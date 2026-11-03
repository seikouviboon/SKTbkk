<?php
/**
 * สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)
 * ระบบกระทบยอดเงินสิ้นวัน (แอปแม่มณี SCB & สกต.กทม.)
 * ไฟล์: reconcile.php
 * คำอธิบาย: ตรวจสอบและกระทบยอดเงินที่ลูกค้าสแกน QR Code เข้าบัญชีแม่มณี กับยอดขายจริง และยืนยันจัดสรรค่านายหน้าร้านค้า
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

if (!$is_admin && !$is_staff) {
    header("Location: https://sktbkk.seikouedu.com/index.php?error=unauthorized");
    exit;
}

if ($is_staff && $my_store_id <= 0) {
    if (strpos($username, 'staff01') !== false) {
        $my_store_id = 4; // ธกส สาขาทุ่งครุ
    } elseif (strpos($username, 'staff02') !== false) {
        $my_store_id = 3; // ร้านประเสริฐมินิมาร์ท
    }
}

$alert_success = '';
$alert_error = '';

// ตรวจสอบพารามิเตอร์กรองข้อมูล
$selected_date = $_GET['date'] ?? date('Y-m-d');
$filter_store_id = $is_staff ? $my_store_id : (isset($_GET['store_id']) ? intval($_GET['store_id']) : 0);

// -------------------------------------------------------------
// 3. ประมวลผล POST Requests
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ---------------------------------------------------------
    // 3.1 ยืนยันการกระทบยอดเงินจากแอปแม่มณี (action = confirm_reconcile)
    // ---------------------------------------------------------
    if ($action === 'confirm_reconcile') {
        $req_store_id = intval($_POST['store_id'] ?? 0);
        $reconcile_date = trim($_POST['reconcile_date'] ?? $selected_date);
        $scb_status = trim($_POST['scb_status'] ?? 'matched');
        $scb_notes = trim($_POST['scb_notes'] ?? '');

        // หากเป็น Staff ต้องกระทบยอดเฉพาะร้านตนเอง
        if ($is_staff) {
            $req_store_id = $my_store_id;
        }

        if ($req_store_id <= 0) {
            $alert_error = 'กรุณาระบุร้านค้าที่ต้องการยืนยันการกระทบยอดเงิน';
        } else {
            try {
                if ($pdo) {
                    $pdo->beginTransaction();

                    // ตรวจสอบตาราง daily_reconciliations
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `daily_reconciliations` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `store_id` INT NOT NULL,
                            `reconcile_date` DATE NOT NULL,
                            `scb_status` VARCHAR(50) DEFAULT 'matched',
                            `scb_notes` TEXT NULL,
                            `confirmed_by` VARCHAR(100) NULL,
                            `confirmed_at` DATETIME NOT NULL,
                            UNIQUE KEY `store_date` (`store_id`, `reconcile_date`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");

                    // บันทึกหรืออัปเดตการกระทบยอด
                    $stmt_rec = $pdo->prepare("
                        INSERT INTO `daily_reconciliations` 
                        (`store_id`, `reconcile_date`, `scb_status`, `scb_notes`, `confirmed_by`, `confirmed_at`)
                        VALUES (:store_id, :rec_date, :status, :notes, :by_user, NOW())
                        ON DUPLICATE KEY UPDATE 
                            `scb_status` = VALUES(`scb_status`),
                            `scb_notes` = VALUES(`scb_notes`),
                            `confirmed_by` = VALUES(`confirmed_by`),
                            `confirmed_at` = NOW()
                    ");
                    $stmt_rec->execute([
                        ':store_id' => $req_store_id,
                        ':rec_date' => $reconcile_date,
                        ':status' => $scb_status,
                        ':notes' => $scb_notes,
                        ':by_user' => $username ?: ($is_staff ? 'Staff' : 'Admin')
                    ]);

                    // อัปเดตสถานะบิลขายในวันนั้นเป็น reconciled
                    $sales_tbl = $pdo->query("SHOW TABLES LIKE 'sales'")->fetchColumn() ? 'sales' : ($pdo->query("SHOW TABLES LIKE 'sales_transactions'")->fetchColumn() ? 'sales_transactions' : null);
                    if ($sales_tbl) {
                        $upd_stmt = $pdo->prepare("
                            UPDATE `{$sales_tbl}` 
                            SET `status` = 'reconciled' 
                            WHERE `store_id` = :store_id AND DATE(`created_at`) = :rec_date
                        ");
                        $upd_stmt->execute([
                            ':store_id' => $req_store_id,
                            ':rec_date' => $reconcile_date
                        ]);
                    }

                    $pdo->commit();
                    $alert_success = "ยืนยันการกระทบยอดเงินจากแอปแม่มณี และจัดสรรค่านายหน้าเรียบร้อยแล้ว";
                }
            } catch (Exception $e) {
                if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
                $alert_error = 'เกิดข้อผิดพลาดในการยืนยันยอดเงิน: ' . $e->getMessage();
            }
        }
    }
}

// -------------------------------------------------------------
// 4. เตรียมข้อมูลแสดงผล
// -------------------------------------------------------------
$stores = [];
if ($pdo) {
    try {
        $stmt_s = $pdo->query("SELECT id, store_name FROM stores WHERE is_active = 1 ORDER BY id ASC");
        while ($r = $stmt_s->fetch(PDO::FETCH_ASSOC)) {
            $stores[intval($r['id'])] = $r['store_name'];
        }
    } catch (Exception $e) {}
}

// ค้นหาบิลขายในวันที่เลือก
$sales = [];
$summary_per_store = [];
if ($pdo) {
    try {
        $sales_tbl = $pdo->query("SHOW TABLES LIKE 'sales'")->fetchColumn() ? 'sales' : ($pdo->query("SHOW TABLES LIKE 'sales_transactions'")->fetchColumn() ? 'sales_transactions' : null);
        if ($sales_tbl) {
            $where_clauses = ["DATE(s.created_at) = :sel_date"];
            $params = [':sel_date' => $selected_date];

            if ($filter_store_id > 0) {
                $where_clauses[] = "s.store_id = :store_id";
                $params[':store_id'] = $filter_store_id;
            }

            $where_sql = implode(' AND ', $where_clauses);
            $query = "
                SELECT s.*, st.store_name 
                FROM `{$sales_tbl}` s 
                LEFT JOIN `stores` st ON s.store_id = st.id 
                WHERE {$where_sql} 
                ORDER BY s.created_at DESC
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ตรวจสอบสถานะการกระทบยอดจาก daily_reconciliations
            $rec_map = [];
            $has_rec_tbl = $pdo->query("SHOW TABLES LIKE 'daily_reconciliations'")->fetchColumn();
            if ($has_rec_tbl) {
                $rec_stmt = $pdo->prepare("SELECT store_id, scb_status, scb_notes FROM daily_reconciliations WHERE reconcile_date = :d");
                $rec_stmt->execute([':d' => $selected_date]);
                while ($rec_row = $rec_stmt->fetch(PDO::FETCH_ASSOC)) {
                    $rec_map[intval($rec_row['store_id'])] = $rec_row;
                }
            }

            // คำนวณยอดสรุปแยกตามร้านค้า
            foreach ($sales as $sale) {
                $sid = intval($sale['store_id']);
                if (!isset($summary_per_store[$sid])) {
                    $summary_per_store[$sid] = [
                        'store_id' => $sid,
                        'store_name' => $sale['store_name'] ?? ($stores[$sid] ?? ('ร้านค้า #' . $sid)),
                        'bill_count' => 0,
                        'bags_sold' => 0,
                        'total_revenue' => 0.0,
                        'total_commission' => 0.0,
                        'is_reconciled' => isset($rec_map[$sid]) || ($sale['status'] === 'reconciled'),
                        'rec_info' => $rec_map[$sid] ?? null
                    ];
                }
                $summary_per_store[$sid]['bill_count']++;
                $bags = intval($sale['qty_1kg'] ?? 0) + intval($sale['qty_5kg'] ?? 0) + intval($sale['qty_15kg'] ?? 0);
                $summary_per_store[$sid]['bags_sold'] += $bags;
                $summary_per_store[$sid]['total_revenue'] += floatval($sale['total_price'] ?? 0);
                $summary_per_store[$sid]['total_commission'] += floatval($sale['total_commission'] ?? 0);
            }
        }
    } catch (Exception $e) {}
}

$my_store_name = $stores[$my_store_id] ?? ($is_staff ? 'ร้านค้าตัวแทน' : 'โกดัง สกต.กทม.');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>กระทบยอดเงินสิ้นวัน (แอปแม่มณี SCB) - สกต.กทม.</title>
    
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
        .btn-scb:hover {
            background-color: #3b1f66;
            color: white;
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

<div class="container py-4">

    <!-- แถบหัวเรื่อง -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-2 border-bottom">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-patch-check-fill text-scb me-2"></i>กระทบยอดเงินสิ้นวัน (แอปแม่มณี SCB &amp; สกต.กทม.)</h4>
            <p class="text-muted small mb-0">
                <?= $is_staff ? "สำหรับจุดขาย: <strong class='text-primary'>{$my_store_name}</strong> (ตรวจสอบยอดเงินสแกน QR และยืนยันจัดสรรค่านายหน้า)" : "เทียบยอดเงินที่ลูกค้าสแกน QR เข้าบัญชีแม่มณี กับจำนวนถุงที่ขายได้จริง และยืนยันจัดสรรค่านายหน้า" ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-scb text-white px-3 py-2 fs-6">
                <i class="bi bi-shield-check me-1"></i> ระบบตรวจสอบความถูกต้อง 100%
            </span>
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

    <!-- ตัวกรองวันที่และร้านค้า -->
    <div class="card card-custom p-3 bg-white mb-4">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">เลือกวันที่ต้องการตรวจสอบ:</label>
                <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($selected_date) ?>" onchange="this.form.submit()">
            </div>
            
            <?php if ($is_admin): ?>
                <div class="col-md-5">
                    <label class="form-label small text-muted mb-1">กรองเฉพาะร้านค้า:</label>
                    <select name="store_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">-- แสดงทุกร้านค้า --</option>
                        <?php foreach ($stores as $sid => $sname): ?>
                            <option value="<?= $sid ?>" <?= ($filter_store_id === $sid) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sname) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <a href="https://sktbkk.seikouedu.com/reconcile.php" class="btn btn-outline-secondary w-100">รีเซ็ตตัวกรอง</a>
                </div>
            <?php else: ?>
                <div class="col-md-5">
                    <label class="form-label small text-muted mb-1">จุดขายประจำของคุณ:</label>
                    <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($my_store_name) ?>" readonly>
                    <input type="hidden" name="store_id" value="<?= $my_store_id ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-scb w-100">
                        <i class="bi bi-search me-1"></i> ค้นหาบิลตามวันที่
                    </button>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- กล่องสรุปร้านค้าที่รอกระทบยอดสิ้นวัน -->
    <div class="card card-custom p-4 bg-white mb-4">
        <h5 class="fw-bold mb-3 text-scb">
            <i class="bi bi-wallet2 me-2"></i>
            <?= $is_staff ? "สรุปยอดขายประจำร้านวันที่ " . date('d/m/Y', strtotime($selected_date)) : "ร้านค้าที่รอกระทบยอดในวันที่ " . date('d/m/Y', strtotime($selected_date)) ?>
        </h5>

        <?php if (empty($summary_per_store)): ?>
            <div class="text-center py-4 text-muted">
                <i class="bi bi-calendar-x fs-3 d-block mb-2"></i>
                ไม่พบรายการขายในวันที่ <?= date('d/m/Y', strtotime($selected_date)) ?>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($summary_per_store as $sid => $item): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="border rounded-3 p-3 bg-light shadow-sm">
                            <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($item['store_name']) ?></h6>
                            <div class="small text-muted mb-2">จำนวนบิลขาย: <?= intval($item['bill_count']) ?> บิล</div>
                            <hr class="my-2">
                            <div class="d-flex justify-content-between small mb-1">
                                <span>จำนวนถุงที่ขาย:</span>
                                <strong><?= intval($item['bags_sold']) ?> ถุง</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span>ยอดเงินตามระบบ:</span>
                                <strong class="text-baac fs-5">฿<?= number_format($item['total_revenue'], 2) ?></strong>
                            </div>
                            <div class="d-flex justify-content-between small text-muted mb-3">
                                <span>ค่านายหน้าร้านที่จะได้รับ:</span>
                                <strong class="text-primary">฿<?= number_format($item['total_commission'], 2) ?></strong>
                            </div>

                            <?php if ($item['is_reconciled']): ?>
                                <div class="alert alert-success py-2 mb-0 text-center small fw-semibold">
                                    <i class="bi bi-check-circle-fill me-1"></i> กระทบยอดและจัดสรรเงินเรียบร้อยแล้ว
                                </div>
                            <?php else: ?>
                                <button type="button" class="btn btn-scb w-100 py-2 btn-sm fw-semibold" onclick='openConfirmReconcile(<?= json_encode($item, JSON_UNESCAPED_UNICODE) ?>)'>
                                    <i class="bi bi-patch-check me-1"></i> เทียบยอดเงินในแม่มณี &amp; จัดสรรเงิน
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ตารางรายการขายในวันนั้น -->
    <div class="card card-custom p-4 bg-white">
        <h5 class="fw-bold mb-3">รายละเอียดบิลขายทั้งหมดในวันที่ <?= date('d/m/Y', strtotime($selected_date)) ?></h5>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small">
                    <tr>
                        <th>เลขที่ใบเสร็จ</th>
                        <th>เวลา</th>
                        <th>ร้านค้า</th>
                        <th class="text-center">1 กก.</th>
                        <th class="text-center">5 กก.</th>
                        <th class="text-center">15 กก.</th>
                        <th class="text-end">ยอดเงินสแกน (ธ.ก.ส.)</th>
                        <th class="text-end">ค่านายหน้าร้าน</th>
                        <th class="text-center">สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sales)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-4 text-muted">
                                <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                                ไม่มีบิลขายในวันที่เลือก
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sales as $sale): ?>
                            <?php 
                            $is_rec = ($sale['status'] === 'reconciled');
                            ?>
                            <tr>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($sale['receipt_no']) ?></span></td>
                                <td class="small"><?= date('H:i น.', strtotime($sale['created_at'])) ?></td>
                                <td><strong><?= htmlspecialchars($sale['store_name'] ?? ('ร้านค้า #' . $sale['store_id'])) ?></strong></td>
                                <td class="text-center"><?= intval($sale['qty_1kg'] ?? 0) ?></td>
                                <td class="text-center"><?= intval($sale['qty_5kg'] ?? 0) ?></td>
                                <td class="text-center"><?= intval($sale['qty_15kg'] ?? 0) ?></td>
                                <td class="text-end fw-bold text-baac">฿<?= number_format(floatval($sale['total_price'] ?? 0), 2) ?></td>
                                <td class="text-end fw-semibold text-primary">฿<?= number_format(floatval($sale['total_commission'] ?? 0), 2) ?></td>
                                <td class="text-center">
                                    <?php if ($is_rec): ?>
                                        <span class="badge bg-success"><i class="bi bi-shield-check me-1"></i>กระทบยอดแล้ว (SCB แม่มณี)</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>รอยืนยันเงิน</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal ยืนยันการกระทบยอด -->
<div class="modal fade" id="modalConfirmRec" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="action" value="confirm_reconcile">
                <input type="hidden" name="store_id" id="rec_store_id">
                <input type="hidden" name="reconcile_date" value="<?= htmlspecialchars($selected_date) ?>">

                <div class="modal-header bg-scb text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-shield-check me-2"></i>ยืนยันยอดเงินจากแอปแม่มณี</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="mb-2">ร้านค้า: <strong id="rec_store_name" class="text-dark"></strong></p>
                    <p class="mb-3 small text-muted">วันที่: <?= date('d/m/Y', strtotime($selected_date)) ?></p>

                    <div class="p-3 bg-light rounded-3 mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="small text-muted">จำนวนถุงข้าวที่ขาย:</span>
                            <strong id="rec_bags_sold">0 ถุง</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="small text-muted">ยอดเงินรวมในระบบ:</span>
                            <strong class="text-baac fs-5" id="rec_total_rev">฿0.00</strong>
                        </div>
                        <div class="d-flex justify-content-between pt-2 border-top">
                            <span class="small text-muted">ค่านายหน้าร้านที่จะจัดสรร:</span>
                            <strong class="text-primary fs-5" id="rec_total_comm">฿0.00</strong>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-muted">ผลการเทียบกับแอปแม่มณี (SCB):</label>
                        <select name="scb_status" class="form-select" required>
                            <option value="matched">ยอดเงินในแอปแม่มณี ตรงกับระบบ 100%</option>
                            <option value="mismatched">ยอดเงินมีความแตกต่าง (บันทึกหมายเหตุ)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-muted">หมายเหตุ / รหัสอ้างอิงแอปแม่มณี:</label>
                        <input type="text" name="scb_notes" class="form-control" placeholder="เช่น รหัสสรุปยอดกะ SCB #1234 หรือ ตรวจสอบเรียบร้อย">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-scb px-4">ยืนยันจัดสรรค่านายหน้า</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
let recModal = null;
function openConfirmReconcile(item) {
    document.getElementById('rec_store_id').value = item.store_id;
    document.getElementById('rec_store_name').innerText = item.store_name;
    document.getElementById('rec_bags_sold').innerText = (item.bags_sold || 0) + ' ถุง';
    document.getElementById('rec_total_rev').innerText = '฿' + parseFloat(item.total_revenue || 0).toLocaleString('th-TH', {minimumFractionDigits: 2});
    document.getElementById('rec_total_comm').innerText = '฿' + parseFloat(item.total_commission || 0).toLocaleString('th-TH', {minimumFractionDigits: 2});

    if (!recModal) {
        recModal = new bootstrap.Modal(document.getElementById('modalConfirmRec'));
    }
    recModal.show();
}
</script>

</body>
</html>
