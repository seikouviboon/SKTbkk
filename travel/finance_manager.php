<?php
/**
 * สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)
 * ระบบจัดการสำรองข้อมูลและ Mockup การเงินสำหรับประชุมคณะกรรมการ
 * ไฟล์: finance_manager.php
 */

session_start();

// เชื่อมต่อฐานข้อมูล
$pdo = null;
$possible_db_files = [
    __DIR__ . '/config/database.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/db.php',
    __DIR__ . '/db.php',
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

if (!$pdo) {
    die(json_encode(['error' => 'Database connection failed']));
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'inspect');

if ($action === 'inspect') {
    // แสดงรายชื่อตารางทั้งหมดในฐานข้อมูล พร้อมจำนวนแถว
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $result = [];
    foreach ($tables as $t) {
        $cnt_stmt = $pdo->query("SELECT COUNT(*) FROM `{$t}`");
        $result[$t] = intval($cnt_stmt->fetchColumn());
    }

    $source = '';
    if (file_exists(__DIR__ . '/financial_reports.php')) {
        $source = file_get_contents(__DIR__ . '/financial_reports.php');
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true, 
        'tables' => $result,
        'source_length' => strlen($source),
        'source' => $source
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
