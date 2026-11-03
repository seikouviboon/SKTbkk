<?php
/**
 * สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)
 * ไฟล์: travel/install.php
 * คำอธิบาย: ตัวติดตั้งและปรับปรุงสคีมาฐานข้อมูลอัตโนมัติ (Self-Healing Schema Migrator & Seeder)
 */

session_start();
header('Content-Type: text/html; charset=utf-8');

function get_db_connection() {
    $possible_files = [
        __DIR__ . '/../config/database.php',
        __DIR__ . '/../config.php',
        __DIR__ . '/../includes/db.php',
        __DIR__ . '/../db.php',
        __DIR__ . '/../api/config.php',
        __DIR__ . '/config/database.php',
        __DIR__ . '/config.php',
        __DIR__ . '/includes/db.php',
        __DIR__ . '/db.php'
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
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        return $GLOBALS['conn'];
    }

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
$msg = [];
$error = null;

if (isset($_GET['inspect_db']) && $db instanceof PDO) {
    $stmt = $db->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $tbl_counts = [];
    foreach ($tables as $t) {
        $c = $db->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        $tbl_counts[$t] = intval($c);
    }
    $fin_source = '';
    if (file_exists(__DIR__ . '/../financial_reports.php')) {
        $fin_source = file_get_contents(__DIR__ . '/../financial_reports.php');
    } elseif (file_exists(__DIR__ . '/financial_reports.php')) {
        $fin_source = file_get_contents(__DIR__ . '/financial_reports.php');
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'tables' => $tbl_counts,
        'financial_reports_len' => strlen($fin_source),
        'financial_reports_source' => $fin_source
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function get_table_columns($db, $table) {
    try {
        if ($db instanceof PDO) {
            $stmt = $db->query("SHOW COLUMNS FROM `$table`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($db instanceof mysqli) {
            $res = $db->query("SHOW COLUMNS FROM `$table`");
            $rows = [];
            while ($r = $res->fetch_assoc()) $rows[] = $r;
        } else {
            return [];
        }
        $cols = [];
        foreach ($rows as $r) {
            $cols[strtolower($r['Field'])] = $r;
        }
        return $cols;
    } catch (Exception $e) {
        return [];
    }
}

function ensure_column($db, $table, $col_name, $col_def) {
    $cols = get_table_columns($db, $table);
    if (!isset($cols[strtolower($col_name)])) {
        $sql = "ALTER TABLE `$table` ADD COLUMN `$col_name` $col_def";
        try {
            if ($db instanceof PDO) {
                $db->exec($sql);
            } elseif ($db instanceof mysqli) {
                $db->query($sql);
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    return false;
}

function safe_insert_record($db, $table, $row_data) {
    $cols = get_table_columns($db, $table);
    $valid_data = [];
    foreach ($row_data as $k => $v) {
        if (isset($cols[strtolower($k)])) {
            $valid_data[$k] = $v;
        }
    }

    // กรณีตารางมี id แต่ data ให้ trip_id / waypoint_id / claim_id
    if (isset($cols['id'])) {
        if (isset($row_data['trip_id']) && !isset($valid_data['id'])) {
            $valid_data['id'] = $row_data['trip_id'];
        } elseif (isset($row_data['waypoint_id']) && !isset($valid_data['id'])) {
            $valid_data['id'] = $row_data['waypoint_id'];
        } elseif (isset($row_data['claim_id']) && !isset($valid_data['id'])) {
            $valid_data['id'] = $row_data['claim_id'];
        }
    }

    if (empty($valid_data)) return false;

    $fields = array_keys($valid_data);
    $placeholders = array_map(function($f) { return ":$f"; }, $fields);
    
    $updates = [];
    foreach ($fields as $f) {
        if (!in_array($f, ['id', 'trip_id', 'waypoint_id', 'claim_id'])) {
            $updates[] = "`$f` = VALUES(`$f`)";
        }
    }
    $update_str = !empty($updates) ? " ON DUPLICATE KEY UPDATE " . implode(', ', $updates) : "";

    $sql = "INSERT INTO `$table` (`" . implode('`, `', $fields) . "`) VALUES (" . implode(', ', $placeholders) . ")" . $update_str;
    
    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        $exec_params = [];
        foreach ($valid_data as $k => $v) {
            $exec_params[":$k"] = $v;
        }
        $stmt->execute($exec_params);
    } elseif ($db instanceof mysqli) {
        $escaped = [];
        foreach ($valid_data as $k => $v) {
            if ($v === null) {
                $escaped[] = "NULL";
            } else {
                $escaped[] = "'" . $db->real_escape_string($v) . "'";
            }
        }
        $raw_sql = "INSERT INTO `$table` (`" . implode('`, `', $fields) . "`) VALUES (" . implode(', ', $escaped) . ")" . $update_str;
        $db->query($raw_sql);
    }
    return true;
}

if (!$db) {
    $error = "ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบไฟล์ config/database.php";
} else {
    try {
        // =========================================================================
        // 1. ตรวจสอบและปรับปรุงตาราง travel_trips
        // =========================================================================
        $table_trips_exists = count(get_table_columns($db, 'travel_trips')) > 0;
        if (!$table_trips_exists) {
            $sql_create_trips = "
                CREATE TABLE IF NOT EXISTS `travel_trips` (
                    `trip_id` int(11) NOT NULL AUTO_INCREMENT,
                    `trip_code` varchar(50) DEFAULT NULL,
                    `user_id` int(11) DEFAULT 1,
                    `officer_name` varchar(255) DEFAULT NULL,
                    `officer_position` varchar(255) DEFAULT NULL,
                    `vehicle_plate` varchar(50) DEFAULT NULL,
                    `trip_date` date DEFAULT NULL,
                    `start_time` time DEFAULT NULL,
                    `end_time` time DEFAULT NULL,
                    `start_location` varchar(255) DEFAULT NULL,
                    `end_location` varchar(255) DEFAULT NULL,
                    `purpose` text DEFAULT NULL,
                    `total_waypoints` int(11) DEFAULT 0,
                    `total_distance_km` decimal(10,2) DEFAULT 0.00,
                    `status` varchar(50) DEFAULT 'approved',
                    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`trip_id`),
                    UNIQUE KEY `uk_trip_code` (`trip_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            if ($db instanceof PDO) $db->exec($sql_create_trips);
            else $db->query($sql_create_trips);
            $msg[] = "สร้างตาราง travel_trips ใหม่เรียบร้อย";
        } else {
            // ปรับปรุงคอลัมน์เดิมให้สมบูรณ์
            $cols = get_table_columns($db, 'travel_trips');
            if (!isset($cols['trip_id'])) {
                if (isset($cols['id'])) {
                    if ($db instanceof PDO) {
                        $db->exec("ALTER TABLE `travel_trips` ADD COLUMN `trip_id` int(11) NULL AFTER `id`");
                        $db->exec("UPDATE `travel_trips` SET `trip_id` = `id` WHERE `trip_id` IS NULL OR `trip_id` = 0");
                        try { $db->exec("ALTER TABLE `travel_trips` ADD INDEX `idx_trip_id` (`trip_id`)"); } catch(Exception $e){}
                    }
                } else {
                    if ($db instanceof PDO) $db->exec("ALTER TABLE `travel_trips` ADD COLUMN `trip_id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
                }
            }
            ensure_column($db, 'travel_trips', 'trip_code', 'varchar(50) DEFAULT NULL');
            ensure_column($db, 'travel_trips', 'user_id', 'int(11) DEFAULT 1');
            ensure_column($db, 'travel_trips', 'officer_name', 'varchar(255) DEFAULT NULL');
            ensure_column($db, 'travel_trips', 'officer_position', 'varchar(255) DEFAULT NULL');
            ensure_column($db, 'travel_trips', 'vehicle_plate', 'varchar(50) DEFAULT NULL');
            ensure_column($db, 'travel_trips', 'trip_date', 'date DEFAULT NULL');
            ensure_column($db, 'travel_trips', 'start_time', 'time DEFAULT NULL');
            ensure_column($db, 'travel_trips', 'end_time', 'time DEFAULT NULL');
            ensure_column($db, 'travel_trips', 'start_location', 'varchar(255) DEFAULT NULL');
            ensure_column($db, 'travel_trips', 'end_location', 'varchar(255) DEFAULT NULL');
            ensure_column($db, 'travel_trips', 'purpose', 'text DEFAULT NULL');
            ensure_column($db, 'travel_trips', 'total_waypoints', 'int(11) DEFAULT 0');
            ensure_column($db, 'travel_trips', 'total_distance_km', 'decimal(10,2) DEFAULT 0.00');
            ensure_column($db, 'travel_trips', 'status', "varchar(50) DEFAULT 'approved'");
            ensure_column($db, 'travel_trips', 'created_at', 'datetime DEFAULT CURRENT_TIMESTAMP');
            $msg[] = "ตรวจสอบและอัปเกรดโครงสร้างตาราง travel_trips พร้อมใช้งาน";
        }

        // =========================================================================
        // 2. ตรวจสอบและปรับปรุงตาราง travel_waypoints
        // =========================================================================
        $table_wp_exists = count(get_table_columns($db, 'travel_waypoints')) > 0;
        if (!$table_wp_exists) {
            $sql_create_wp = "
                CREATE TABLE IF NOT EXISTS `travel_waypoints` (
                    `waypoint_id` int(11) NOT NULL AUTO_INCREMENT,
                    `trip_id` int(11) NOT NULL,
                    `step_no` int(11) NOT NULL,
                    `point_type` varchar(50) DEFAULT 'waypoint',
                    `location_name` varchar(255) DEFAULT NULL,
                    `latitude` decimal(10,6) DEFAULT NULL,
                    `longitude` decimal(10,6) DEFAULT NULL,
                    `distance_km` decimal(10,2) DEFAULT 0.00,
                    `action_time` datetime DEFAULT NULL,
                    `objective` text DEFAULT NULL,
                    `is_gps_verified` tinyint(1) DEFAULT 1,
                    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`waypoint_id`),
                    KEY `idx_trip_id` (`trip_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            if ($db instanceof PDO) $db->exec($sql_create_wp);
            else $db->query($sql_create_wp);
            $msg[] = "สร้างตาราง travel_waypoints ใหม่เรียบร้อย";
        } else {
            $cols = get_table_columns($db, 'travel_waypoints');
            if (!isset($cols['waypoint_id'])) {
                if (isset($cols['id'])) {
                    if ($db instanceof PDO) {
                        $db->exec("ALTER TABLE `travel_waypoints` ADD COLUMN `waypoint_id` int(11) NULL AFTER `id`");
                        $db->exec("UPDATE `travel_waypoints` SET `waypoint_id` = `id` WHERE `waypoint_id` IS NULL OR `waypoint_id` = 0");
                        try { $db->exec("ALTER TABLE `travel_waypoints` ADD INDEX `idx_waypoint_id` (`waypoint_id`)"); } catch(Exception $e){}
                    }
                } else {
                    if ($db instanceof PDO) $db->exec("ALTER TABLE `travel_waypoints` ADD COLUMN `waypoint_id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
                }
            }
            ensure_column($db, 'travel_waypoints', 'trip_id', 'int(11) NOT NULL DEFAULT 0');
            ensure_column($db, 'travel_waypoints', 'step_no', 'int(11) NOT NULL DEFAULT 1');
            ensure_column($db, 'travel_waypoints', 'point_type', "varchar(50) DEFAULT 'waypoint'");
            ensure_column($db, 'travel_waypoints', 'location_name', 'varchar(255) DEFAULT NULL');
            ensure_column($db, 'travel_waypoints', 'latitude', 'decimal(10,6) DEFAULT NULL');
            ensure_column($db, 'travel_waypoints', 'longitude', 'decimal(10,6) DEFAULT NULL');
            ensure_column($db, 'travel_waypoints', 'distance_km', 'decimal(10,2) DEFAULT 0.00');
            ensure_column($db, 'travel_waypoints', 'action_time', 'datetime DEFAULT NULL');
            ensure_column($db, 'travel_waypoints', 'objective', 'text DEFAULT NULL');
            ensure_column($db, 'travel_waypoints', 'is_gps_verified', 'tinyint(1) DEFAULT 1');
            ensure_column($db, 'travel_waypoints', 'photo_path', 'varchar(255) DEFAULT NULL');
            ensure_column($db, 'travel_waypoints', 'ai_place', 'varchar(255) DEFAULT NULL');
            ensure_column($db, 'travel_waypoints', 'created_at', 'datetime DEFAULT CURRENT_TIMESTAMP');
            $msg[] = "ตรวจสอบและอัปเกรดโครงสร้างตาราง travel_waypoints พร้อมใช้งาน";
        }

        // สร้างโฟลเดอร์สำหรับจัดเก็บรูปภาพจุดแวะ GPS
        $p_dir = __DIR__ . '/../uploads/travel_photos/';
        if (!file_exists($p_dir)) @mkdir($p_dir, 0777, true);
        $p_dir_loc = __DIR__ . '/uploads/travel_photos/';
        if (!file_exists($p_dir_loc)) @mkdir($p_dir_loc, 0777, true);

        // =========================================================================
        // 3. ตรวจสอบและปรับปรุงตาราง travel_claims
        // =========================================================================
        $table_claims_exists = count(get_table_columns($db, 'travel_claims')) > 0;
        if (!$table_claims_exists) {
            $sql_create_claims = "
                CREATE TABLE IF NOT EXISTS `travel_claims` (
                    `claim_id` int(11) NOT NULL AUTO_INCREMENT,
                    `trip_id` int(11) NOT NULL,
                    `user_id` int(11) DEFAULT 1,
                    `claim_type` varchar(50) DEFAULT 'distance',
                    `rate_id` int(11) DEFAULT 1,
                    `distance_km` decimal(10,2) DEFAULT 0.00,
                    `fuel_rate` decimal(10,2) DEFAULT 4.00,
                    `wear_rate` decimal(10,2) DEFAULT 1.00,
                    `fuel_amount` decimal(10,2) DEFAULT 0.00,
                    `wear_amount` decimal(10,2) DEFAULT 0.00,
                    `total_amount` decimal(10,2) DEFAULT 0.00,
                    `claim_status` varchar(50) DEFAULT 'approved',
                    `approved_by` varchar(255) DEFAULT NULL,
                    `approved_at` datetime DEFAULT NULL,
                    `approver_note` text DEFAULT NULL,
                    `remark` text DEFAULT NULL,
                    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`claim_id`),
                    KEY `idx_trip_id` (`trip_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            if ($db instanceof PDO) $db->exec($sql_create_claims);
            else $db->query($sql_create_claims);
            $msg[] = "สร้างตาราง travel_claims ใหม่เรียบร้อย";
        } else {
            $cols = get_table_columns($db, 'travel_claims');
            if (!isset($cols['claim_id'])) {
                if (isset($cols['id'])) {
                    if ($db instanceof PDO) {
                        $db->exec("ALTER TABLE `travel_claims` ADD COLUMN `claim_id` int(11) NULL AFTER `id`");
                        $db->exec("UPDATE `travel_claims` SET `claim_id` = `id` WHERE `claim_id` IS NULL OR `claim_id` = 0");
                        try { $db->exec("ALTER TABLE `travel_claims` ADD INDEX `idx_claim_id` (`claim_id`)"); } catch(Exception $e){}
                    }
                } else {
                    if ($db instanceof PDO) $db->exec("ALTER TABLE `travel_claims` ADD COLUMN `claim_id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
                }
            }
            ensure_column($db, 'travel_claims', 'trip_id', 'int(11) NOT NULL DEFAULT 0');
            ensure_column($db, 'travel_claims', 'user_id', 'int(11) DEFAULT 1');
            ensure_column($db, 'travel_claims', 'claim_type', "varchar(50) DEFAULT 'distance'");
            ensure_column($db, 'travel_claims', 'rate_id', 'int(11) DEFAULT 1');
            ensure_column($db, 'travel_claims', 'distance_km', 'decimal(10,2) DEFAULT 0.00');
            ensure_column($db, 'travel_claims', 'fuel_rate', 'decimal(10,2) DEFAULT 4.00');
            ensure_column($db, 'travel_claims', 'wear_rate', 'decimal(10,2) DEFAULT 1.00');
            ensure_column($db, 'travel_claims', 'fuel_amount', 'decimal(10,2) DEFAULT 0.00');
            ensure_column($db, 'travel_claims', 'wear_amount', 'decimal(10,2) DEFAULT 0.00');
            ensure_column($db, 'travel_claims', 'total_amount', 'decimal(10,2) DEFAULT 0.00');
            ensure_column($db, 'travel_claims', 'claim_status', "varchar(50) DEFAULT 'approved'");
            ensure_column($db, 'travel_claims', 'approved_by', 'varchar(255) DEFAULT NULL');
            ensure_column($db, 'travel_claims', 'approved_at', 'datetime DEFAULT NULL');
            ensure_column($db, 'travel_claims', 'approver_note', 'text DEFAULT NULL');
            ensure_column($db, 'travel_claims', 'remark', 'text DEFAULT NULL');
            ensure_column($db, 'travel_claims', 'created_at', 'datetime DEFAULT CURRENT_TIMESTAMP');
            $msg[] = "ตรวจสอบและอัปเกรดโครงสร้างตาราง travel_claims พร้อมใช้งาน";
        }

        // =========================================================================
        // 4. ตรวจสอบและสร้างตาราง stock_audits สำหรับ AI ตรวจนับข้าวสาร
        // =========================================================================
        $table_audit_exists = count(get_table_columns($db, 'stock_audits')) > 0;
        if (!$table_audit_exists) {
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
            if ($db instanceof PDO) $db->exec($sql_audit_table);
            else $db->query($sql_audit_table);
            $msg[] = "สร้างตาราง stock_audits สำหรับระบบ AI สำเร็จ";
        } else {
            ensure_column($db, 'stock_audits', 'store_id', 'int(11) NOT NULL DEFAULT 1');
            ensure_column($db, 'stock_audits', 'photo_path', 'varchar(500) DEFAULT NULL');
            ensure_column($db, 'stock_audits', 'expected_1kg', 'int(11) DEFAULT 0');
            ensure_column($db, 'stock_audits', 'expected_5kg', 'int(11) DEFAULT 0');
            ensure_column($db, 'stock_audits', 'expected_15kg', 'int(11) DEFAULT 0');
            ensure_column($db, 'stock_audits', 'expected_count', 'int(11) DEFAULT 0');
            ensure_column($db, 'stock_audits', 'ai_1kg', 'int(11) DEFAULT 0');
            ensure_column($db, 'stock_audits', 'ai_5kg', 'int(11) DEFAULT 0');
            ensure_column($db, 'stock_audits', 'ai_15kg', 'int(11) DEFAULT 0');
            ensure_column($db, 'stock_audits', 'detected_count', 'int(11) DEFAULT 0');
            ensure_column($db, 'stock_audits', 'discrepancy', 'int(11) DEFAULT 0');
            ensure_column($db, 'stock_audits', 'audit_status', "varchar(50) DEFAULT 'normal'");
            ensure_column($db, 'stock_audits', 'ai_notes', 'text DEFAULT NULL');
            ensure_column($db, 'stock_audits', 'created_at', 'datetime DEFAULT CURRENT_TIMESTAMP');
            $msg[] = "ตรวจสอบโครงสร้างตาราง stock_audits พร้อมใช้งาน";
        }

        // =========================================================================
        // 5. นำเข้าข้อมูลจำลอง 5 วัน (32 จุดแวะ) แบบ Dynamic Safe Insert
        // =========================================================================
        $trips_data = [
            [
                'trip_id' => 101,
                'trip_code' => 'TRIP-20261102-001',
                'user_id' => 1,
                'officer_name' => 'นายสมเกียรติ สิทธิวงศ์',
                'officer_position' => 'เจ้าหน้าที่ส่งเสริมและตรวจติดตามจุดขาย',
                'vehicle_plate' => '1กข-4589 กทม.',
                'trip_date' => '2026-11-02',
                'start_time' => '08:30:00',
                'end_time' => '17:00:00',
                'start_location' => 'สำนักงาน สกต.กทม.',
                'end_location' => 'สำนักงาน สกต.กทม.',
                'purpose' => 'ตรวจสต็อกข้าวหอมมะลิและติดตามจุดขาย โซนมีนบุรี-บางกะปิ-ลาดกระบัง',
                'total_waypoints' => 6,
                'total_distance_km' => 68.50,
                'status' => 'approved',
                'created_at' => '2026-11-02 08:30:00'
            ],
            [
                'trip_id' => 102,
                'trip_code' => 'TRIP-20261103-002',
                'user_id' => 1,
                'officer_name' => 'นายสมเกียรติ สิทธิวงศ์',
                'officer_position' => 'เจ้าหน้าที่ส่งเสริมและตรวจติดตามจุดขาย',
                'vehicle_plate' => '1กข-4589 กทม.',
                'trip_date' => '2026-11-03',
                'start_time' => '08:15:00',
                'end_time' => '17:15:00',
                'start_location' => 'สำนักงาน สกต.กทม.',
                'end_location' => 'สำนักงาน สกต.กทม.',
                'purpose' => 'ส่งมอบสินค้าและตรวจนับสต็อกข้าวสารบรรจุถุง โซนฝั่งธนบุรี & พระราม 2',
                'total_waypoints' => 8,
                'total_distance_km' => 74.20,
                'status' => 'approved',
                'created_at' => '2026-11-03 08:15:00'
            ],
            [
                'trip_id' => 103,
                'trip_code' => 'TRIP-20261104-003',
                'user_id' => 1,
                'officer_name' => 'นายสมเกียรติ สิทธิวงศ์',
                'officer_position' => 'เจ้าหน้าที่ส่งเสริมและตรวจติดตามจุดขาย',
                'vehicle_plate' => '1กข-4589 กทม.',
                'trip_date' => '2026-11-04',
                'start_time' => '08:45:00',
                'end_time' => '16:30:00',
                'start_location' => 'สำนักงาน สกต.กทม.',
                'end_location' => 'สำนักงาน สกต.กทม.',
                'purpose' => 'ประชุมเจรจาความร่วมมือขยายจุดจำหน่ายข้าวสาร โซนหลักสี่-ดอนเมือง',
                'total_waypoints' => 4,
                'total_distance_km' => 52.80,
                'status' => 'approved',
                'created_at' => '2026-11-04 08:45:00'
            ],
            [
                'trip_id' => 104,
                'trip_code' => 'TRIP-20261105-004',
                'user_id' => 1,
                'officer_name' => 'นายสมเกียรติ สิทธิวงศ์',
                'officer_position' => 'เจ้าหน้าที่ส่งเสริมและตรวจติดตามจุดขาย',
                'vehicle_plate' => '1กข-4589 กทม.',
                'trip_date' => '2026-11-05',
                'start_time' => '08:00:00',
                'end_time' => '17:30:00',
                'start_location' => 'สำนักงาน สกต.กทม.',
                'end_location' => 'สำนักงาน สกต.กทม.',
                'purpose' => 'สุ่มตรวจมาตรฐานความชื้นและจัดเรียงชั้นวางสินค้า โซนในเมือง-ธนบุรีชั้นใน',
                'total_waypoints' => 9,
                'total_distance_km' => 61.40,
                'status' => 'submitted',
                'created_at' => '2026-11-05 08:00:00'
            ],
            [
                'trip_id' => 105,
                'trip_code' => 'TRIP-20261106-005',
                'user_id' => 1,
                'officer_name' => 'นายสมเกียรติ สิทธิวงศ์',
                'officer_position' => 'เจ้าหน้าที่ส่งเสริมและตรวจติดตามจุดขาย',
                'vehicle_plate' => '1กข-4589 กทม.',
                'trip_date' => '2026-11-06',
                'start_time' => '08:30:00',
                'end_time' => '16:45:00',
                'start_location' => 'สำนักงาน สกต.กทม.',
                'end_location' => 'สำนักงาน สกต.กทม.',
                'purpose' => 'ตรวจสต็อกสิ้นสัปดาห์และเก็บเงินสดจุดฝากขาย โซนตะวันตก-ปริมณฑล',
                'total_waypoints' => 5,
                'total_distance_km' => 69.80,
                'status' => 'submitted',
                'created_at' => '2026-11-06 08:30:00'
            ]
        ];

        foreach ($trips_data as $trip) {
            safe_insert_record($db, 'travel_trips', $trip);
        }
        $msg[] = "บันทึกข้อมูลการเดินทางหลัก 5 วัน (travel_trips) เรียบร้อย";

        // จุดแวะ (32 จุด)
        $waypoints_data = [
            // วันที่ 1 (6 จุด)
            ['trip_id'=>101, 'step_no'=>1, 'point_type'=>'start', 'location_name'=>'สำนักงาน สกต.กทม. (จุดเริ่มต้น)', 'latitude'=>13.756300, 'longitude'=>100.501800, 'distance_km'=>0.00, 'action_time'=>'2026-11-02 08:30:00', 'objective'=>'เช็กอินเริ่มต้นปฏิบัติงาน ตรวจความพร้อมเอกสารใบส่งของและแบบฟอร์มตรวจสต็อก', 'is_gps_verified'=>1, 'created_at'=>'2026-11-02 08:30:00'],
            ['trip_id'=>101, 'step_no'=>2, 'point_type'=>'waypoint', 'location_name'=>'ธ.ก.ส. สาขาบางกะปิ', 'latitude'=>13.766200, 'longitude'=>100.645000, 'distance_km'=>16.20, 'action_time'=>'2026-11-02 09:45:00', 'objective'=>'ตรวจเช็กสต็อกข้าวหอมมะลิ 105 (ถุง 5 กก.) และสรุปยอดขายประจำสัปดาห์', 'is_gps_verified'=>1, 'created_at'=>'2026-11-02 09:45:00'],
            ['trip_id'=>101, 'step_no'=>3, 'point_type'=>'waypoint', 'location_name'=>'ร้านค้าสหกรณ์เคหะมีนบุรี', 'latitude'=>13.814000, 'longitude'=>100.730500, 'distance_km'=>12.80, 'action_time'=>'2026-11-02 11:15:00', 'objective'=>'ตรวจสอบสภาพบรรจุภัณฑ์ข้าวสาร จัดเรียงชั้นวางและป้ายโปรโมชัน', 'is_gps_verified'=>1, 'created_at'=>'2026-11-02 11:15:00'],
            ['trip_id'=>101, 'step_no'=>4, 'point_type'=>'waypoint', 'location_name'=>'ธ.ก.ส. สาขามีนบุรี', 'latitude'=>13.815200, 'longitude'=>100.728900, 'distance_km'=>1.50, 'action_time'=>'2026-11-02 13:30:00', 'objective'=>'ประสานงาน ผจก.สาขา ขยายพื้นที่วางจำหน่ายข้าวไรซ์เบอร์รีและข้าว กข43', 'is_gps_verified'=>1, 'created_at'=>'2026-11-02 13:30:00'],
            ['trip_id'=>101, 'step_no'=>5, 'point_type'=>'waypoint', 'location_name'=>'จุดจำหน่ายเครือข่ายประชารัฐ ลาดกระบัง', 'latitude'=>13.722500, 'longitude'=>100.785000, 'distance_km'=>14.50, 'action_time'=>'2026-11-02 15:00:00', 'objective'=>'ติดตามยอดขายข้าวสารบรรจุถุง และรับคำสั่งซื้อล่วงหน้าสำหรับรอบสัปดาห์ถัดไป', 'is_gps_verified'=>1, 'created_at'=>'2026-11-02 15:00:00'],
            ['trip_id'=>101, 'step_no'=>6, 'point_type'=>'checkout', 'location_name'=>'สำนักงาน สกต.กทม. (จุดสิ้นสุด/เช็คเอาต์)', 'latitude'=>13.756300, 'longitude'=>100.501800, 'distance_km'=>23.50, 'action_time'=>'2026-11-02 17:00:00', 'objective'=>'เช็คเอาต์สิ้นสุดภารกิจ นำส่งรายงานและสรุปข้อมูลผ่านระบบดิจิทัล', 'is_gps_verified'=>1, 'created_at'=>'2026-11-02 17:00:00'],

            // วันที่ 2 (8 จุด)
            ['trip_id'=>102, 'step_no'=>1, 'point_type'=>'start', 'location_name'=>'สำนักงาน สกต.กทม. (จุดเริ่มต้น)', 'latitude'=>13.756300, 'longitude'=>100.501800, 'distance_km'=>0.00, 'action_time'=>'2026-11-03 08:15:00', 'objective'=>'เช็กอินเริ่มต้นเดินทาง ตรวจเช็กใบกำกับภาษีและใบส่งมอบสินค้า', 'is_gps_verified'=>1, 'created_at'=>'2026-11-03 08:15:00'],
            ['trip_id'=>102, 'step_no'=>2, 'point_type'=>'waypoint', 'location_name'=>'ธ.ก.ส. สาขาบางขุนเทียน', 'latitude'=>13.659000, 'longitude'=>100.435000, 'distance_km'=>15.40, 'action_time'=>'2026-11-03 09:10:00', 'objective'=>'ส่งมอบรายงานยอดจำหน่ายข้าวสาร และตรวจสอบจุดวางจำหน่ายโถงบริการ', 'is_gps_verified'=>1, 'created_at'=>'2026-11-03 09:10:00'],
            ['trip_id'=>102, 'step_no'=>3, 'point_type'=>'waypoint', 'location_name'=>'สหกรณ์การเกษตรบางบอน จำกัด', 'latitude'=>13.665500, 'longitude'=>100.398000, 'distance_km'=>5.80, 'action_time'=>'2026-11-03 10:20:00', 'objective'=>'ตรวจนับสต็อกข้าวหอมมะลิคัดพิเศษ และหารือแนวทางการจัดโปรโมชันร่วม', 'is_gps_verified'=>1, 'created_at'=>'2026-11-03 10:20:00'],
            ['trip_id'=>102, 'step_no'=>4, 'point_type'=>'waypoint', 'location_name'=>'ร้านทวีทรัพย์การค้า พระราม 2 (ร้านฝากขาย)', 'latitude'=>13.638000, 'longitude'=>100.412000, 'distance_km'=>4.60, 'action_time'=>'2026-11-03 11:30:00', 'objective'=>'ส่งมอบข้าวหอมมะลิ 100 ถุง พร้อมให้คำแนะนำเรื่องการควบคุมความชื้นในการเก็บรักษา', 'is_gps_verified'=>1, 'created_at'=>'2026-11-03 11:30:00'],
            ['trip_id'=>102, 'step_no'=>5, 'point_type'=>'waypoint', 'location_name'=>'ธ.ก.ส. สาขาทุ่งครุ (หน้าร้าน staff01)', 'latitude'=>13.631500, 'longitude'=>100.508000, 'distance_km'=>11.20, 'action_time'=>'2026-11-03 13:15:00', 'objective'=>'ตรวจสต็อกหน้าร้านจำหน่าย สกต.กทม. ตรวจสอบยอดเงินโอน QR Payment แม่มณี SCB', 'is_gps_verified'=>1, 'created_at'=>'2026-11-03 13:15:00'],
            ['trip_id'=>102, 'step_no'=>6, 'point_type'=>'waypoint', 'location_name'=>'ร้านประเสริฐมินิมาร์ท ทุ่งครุ (staff02)', 'latitude'=>13.642000, 'longitude'=>100.521000, 'distance_km'=>2.10, 'action_time'=>'2026-11-03 14:30:00', 'objective'=>'ตรวจนับสต็อกข้าวสารฝากขาย สรุปยอดขายเงินสด และเก็บเงินนำส่งบัญชี', 'is_gps_verified'=>1, 'created_at'=>'2026-11-03 14:30:00'],
            ['trip_id'=>102, 'step_no'=>7, 'point_type'=>'waypoint', 'location_name'=>'ธ.ก.ส. สาขาราษฎร์บูรณะ', 'latitude'=>13.682000, 'longitude'=>100.495000, 'distance_km'=>6.80, 'action_time'=>'2026-11-03 15:45:00', 'objective'=>'ตรวจนับสต็อกคงเหลือปลายเดือน และประสานงานส่งข้าวสารเพิ่มในสัปดาห์หน้า', 'is_gps_verified'=>1, 'created_at'=>'2026-11-03 15:45:00'],
            ['trip_id'=>102, 'step_no'=>8, 'point_type'=>'checkout', 'location_name'=>'สำนักงาน สกต.กทม. (จุดสิ้นสุด/เช็คเอาต์)', 'latitude'=>13.756300, 'longitude'=>100.501800, 'distance_km'=>28.30, 'action_time'=>'2026-11-03 17:15:00', 'objective'=>'เช็คเอาต์ นำส่งเงินสดจากการจำหน่ายเข้าฝ่ายการเงิน และสรุปรายงานการเดินทาง', 'is_gps_verified'=>1, 'created_at'=>'2026-11-03 17:15:00'],

            // วันที่ 3 (4 จุด)
            ['trip_id'=>103, 'step_no'=>1, 'point_type'=>'start', 'location_name'=>'สำนักงาน สกต.กทม. (จุดเริ่มต้น)', 'latitude'=>13.756300, 'longitude'=>100.501800, 'distance_km'=>0.00, 'action_time'=>'2026-11-04 08:45:00', 'objective'=>'เช็กอินเริ่มต้น นำตัวอย่างข้าวสารฤดูกาลใหม่และเอกสารเสนอราคา', 'is_gps_verified'=>1, 'created_at'=>'2026-11-04 08:45:00'],
            ['trip_id'=>103, 'step_no'=>2, 'point_type'=>'waypoint', 'location_name'=>'สหกรณ์การเกษตรหลักสี่ จำกัด', 'latitude'=>13.886000, 'longitude'=>100.582000, 'distance_km'=>19.50, 'action_time'=>'2026-11-04 10:00:00', 'objective'=>'ประชุมเจรจาความร่วมมือนำข้าวสารบรรจุถุง สกต.กทม. วางขายในเครือข่ายร้านค้าสหกรณ์', 'is_gps_verified'=>1, 'created_at'=>'2026-11-04 10:00:00'],
            ['trip_id'=>103, 'step_no'=>3, 'point_type'=>'waypoint', 'location_name'=>'ธ.ก.ส. สาขาดอนเมือง', 'latitude'=>13.918000, 'longitude'=>100.598000, 'distance_km'=>6.80, 'action_time'=>'2026-11-04 13:30:00', 'objective'=>'ตรวจเยี่ยมบูธประชาสัมพันธ์ข้าวสารคุณภาพ ธ.ก.ส. และพบลูกค้าสมาชิกผู้ซื้อประจำ', 'is_gps_verified'=>1, 'created_at'=>'2026-11-04 13:30:00'],
            ['trip_id'=>103, 'step_no'=>4, 'point_type'=>'checkout', 'location_name'=>'สำนักงาน สกต.กทม. (จุดสิ้นสุด/เช็คเอาต์)', 'latitude'=>13.756300, 'longitude'=>100.501800, 'distance_km'=>26.50, 'action_time'=>'2026-11-04 16:30:00', 'objective'=>'เช็คเอาต์ สรุปผลการเจรจาขยายจุดจำหน่ายสินค้าเข้าที่ประชุมฝ่ายการตลาด', 'is_gps_verified'=>1, 'created_at'=>'2026-11-04 16:30:00'],

            // วันที่ 4 (9 จุด)
            ['trip_id'=>104, 'step_no'=>1, 'point_type'=>'start', 'location_name'=>'สำนักงาน สกต.กทม. (จุดเริ่มต้น)', 'latitude'=>13.756300, 'longitude'=>100.501800, 'distance_km'=>0.00, 'action_time'=>'2026-11-05 08:00:00', 'objective'=>'เช็กอินเริ่มต้นภารกิจ สุ่มตรวจรอบใหญ่ใจกลางเมืองหลวง', 'is_gps_verified'=>1, 'created_at'=>'2026-11-05 08:00:00'],
            ['trip_id'=>104, 'step_no'=>2, 'point_type'=>'waypoint', 'location_name'=>'ธ.ก.ส. สาขาคลองเตย', 'latitude'=>13.719000, 'longitude'=>100.560000, 'distance_km'=>9.50, 'action_time'=>'2026-11-05 08:50:00', 'objective'=>'สุ่มตรวจสอบมาตรฐานความชื้นและอายุข้าวสารบนชั้นวางหน้าร้านสาขา', 'is_gps_verified'=>1, 'created_at'=>'2026-11-05 08:50:00'],
            ['trip_id'=>104, 'step_no'=>3, 'point_type'=>'waypoint', 'location_name'=>'ร้านค้าสวัสดิการชุมชนบ่อนไก่', 'latitude'=>13.725000, 'longitude'=>100.548000, 'distance_km'=>2.20, 'action_time'=>'2026-11-05 09:45:00', 'objective'=>'เช็กสต็อกข้าวเหนียว กข6 และข้าวกล้องเพื่อสุขภาพ สอบถามความพึงพอใจชุมชน', 'is_gps_verified'=>1, 'created_at'=>'2026-11-05 09:45:00'],
            ['trip_id'=>104, 'step_no'=>4, 'point_type'=>'waypoint', 'location_name'=>'ธ.ก.ส. สาขายานนาวา', 'latitude'=>13.702000, 'longitude'=>100.534000, 'distance_km'=>4.10, 'action_time'=>'2026-11-05 11:00:00', 'objective'=>'ตรวจป้ายราคาสินค้า ป้ายส่งเสริมการขาย สกต. และสื่อตั้งโต๊ะประชาสัมพันธ์', 'is_gps_verified'=>1, 'created_at'=>'2026-11-05 11:00:00'],
            ['trip_id'=>104, 'step_no'=>5, 'point_type'=>'waypoint', 'location_name'=>'จุดจำหน่ายสหกรณ์เคหะพระราม 3', 'latitude'=>13.689000, 'longitude'=>100.528000, 'distance_km'=>2.30, 'action_time'=>'2026-11-05 12:30:00', 'objective'=>'เก็บรวบรวมข้อมูลสถิติการซื้อซ้ำของผู้บริโภคคอนโดมิเนียมและที่อยู่อาศัย', 'is_gps_verified'=>1, 'created_at'=>'2026-11-05 12:30:00'],
            ['trip_id'=>104, 'step_no'=>6, 'point_type'=>'waypoint', 'location_name'=>'ธ.ก.ส. สาขาบางคอแหลม', 'latitude'=>13.698000, 'longitude'=>100.505000, 'distance_km'=>3.40, 'action_time'=>'2026-11-05 13:45:00', 'objective'=>'รับคำสั่งซื้อข้าวหอมมะลิสำหรับเป็นของขวัญปีใหม่จากหน่วยงานภาครัฐ', 'is_gps_verified'=>1, 'created_at'=>'2026-11-05 13:45:00'],
            ['trip_id'=>104, 'step_no'=>7, 'point_type'=>'waypoint', 'location_name'=>'ร้านเพื่อนเกษตร วงเวียนใหญ่ (ตัวแทนจำหน่าย)', 'latitude'=>13.726000, 'longitude'=>100.493000, 'distance_km'=>5.20, 'action_time'=>'2026-11-05 14:50:00', 'objective'=>'ตรวจสต็อกและส่งมอบข้าวสารหอมมะลิ 100% เพิ่มเติมจำนวน 50 ถุง', 'is_gps_verified'=>1, 'created_at'=>'2026-11-05 14:50:00'],
            ['trip_id'=>104, 'step_no'=>8, 'point_type'=>'waypoint', 'location_name'=>'ธ.ก.ส. สาขาบางกอกน้อย', 'latitude'=>13.755000, 'longitude'=>100.472000, 'distance_km'=>6.80, 'action_time'=>'2026-11-05 16:00:00', 'objective'=>'ส่งมอบใบเสร็จรับเงิน/ใบส่งของ และตรวจนับสต็อกคงเหลือ', 'is_gps_verified'=>1, 'created_at'=>'2026-11-05 16:00:00'],
            ['trip_id'=>104, 'step_no'=>9, 'point_type'=>'checkout', 'location_name'=>'สำนักงาน สกต.กทม. (จุดสิ้นสุด/เช็คเอาต์)', 'latitude'=>13.756300, 'longitude'=>100.501800, 'distance_km'=>27.90, 'action_time'=>'2026-11-05 17:30:00', 'objective'=>'เช็คเอาต์ สรุปรายงานการตรวจสอบ 8 จุดตรวจเข้าสู่ระบบ AI Dashboard', 'is_gps_verified'=>1, 'created_at'=>'2026-11-05 17:30:00'],

            // วันที่ 5 (5 จุด)
            ['trip_id'=>105, 'step_no'=>1, 'point_type'=>'start', 'location_name'=>'สำนักงาน สกต.กทม. (จุดเริ่มต้น)', 'latitude'=>13.756300, 'longitude'=>100.501800, 'distance_km'=>0.00, 'action_time'=>'2026-11-06 08:30:00', 'objective'=>'เช็กอินเริ่มต้น เตรียมรายงานสรุปยอดขายรอบสัปดาห์เพื่อนำเสนอจุดจำหน่าย', 'is_gps_verified'=>1, 'created_at'=>'2026-11-06 08:30:00'],
            ['trip_id'=>105, 'step_no'=>2, 'point_type'=>'waypoint', 'location_name'=>'ธ.ก.ส. สาขาตลิ่งชัน', 'latitude'=>13.778000, 'longitude'=>100.445000, 'distance_km'=>11.50, 'action_time'=>'2026-11-06 09:40:00', 'objective'=>'ตรวจจุดจำหน่ายข้าวสารโครงการพิเศษเพื่อสวัสดิการเกษตรกรและประชาชน', 'is_gps_verified'=>1, 'created_at'=>'2026-11-06 09:40:00'],
            ['trip_id'=>105, 'step_no'=>3, 'point_type'=>'waypoint', 'location_name'=>'สหกรณ์การเกษตรทวีวัฒนา จำกัด', 'latitude'=>13.785000, 'longitude'=>100.354000, 'distance_km'=>12.20, 'action_time'=>'2026-11-06 11:15:00', 'objective'=>'ตรวจนับสต็อกสิ้นสัปดาห์ร่วมกับเจ้าหน้าที่คลังสินค้าสหกรณ์', 'is_gps_verified'=>1, 'created_at'=>'2026-11-06 11:15:00'],
            ['trip_id'=>105, 'step_no'=>4, 'point_type'=>'waypoint', 'location_name'=>'ร้านศาลายาการเกษตร (ร้านฝากขายตัวแทน)', 'latitude'=>13.801000, 'longitude'=>100.322000, 'distance_km'=>5.40, 'action_time'=>'2026-11-06 14:00:00', 'objective'=>'กระทบยอดขายและรับชำระเงินค่าข้าวสารฝากขายรอบสัปดาห์เข้าบัญชี สกต.กทม.', 'is_gps_verified'=>1, 'created_at'=>'2026-11-06 14:00:00'],
            ['trip_id'=>105, 'step_no'=>5, 'point_type'=>'checkout', 'location_name'=>'สำนักงาน สกต.กทม. (จุดสิ้นสุด/เช็คเอาต์)', 'latitude'=>13.756300, 'longitude'=>100.501800, 'distance_km'=>40.70, 'action_time'=>'2026-11-06 16:45:00', 'objective'=>'เช็คเอาต์ปิดรอบสัปดาห์ นำส่งใบเดินทางและรายงานสรุป 5 วัน ต่อผู้บริหาร', 'is_gps_verified'=>1, 'created_at'=>'2026-11-06 16:45:00']
        ];

        // ล้าง waypoints จำลองเดิมเฉพาะของ 5 ทริปนี้ก่อนใส่ใหม่
        try {
            if ($db instanceof PDO) {
                $db->exec("DELETE FROM `travel_waypoints` WHERE `trip_id` IN (101, 102, 103, 104, 105)");
            }
        } catch(Exception $e) {}

        foreach ($waypoints_data as $wp) {
            safe_insert_record($db, 'travel_waypoints', $wp);
        }
        $msg[] = "บันทึกจุดแวะและพิกัดดาวเทียม 32 จุด (travel_waypoints) เรียบร้อย";

        // ใบขอเบิกค่าเดินทาง (travel_claims)
        $claims_data = [
            [
                'claim_id' => 101,
                'trip_id' => 101,
                'user_id' => 1,
                'claim_type' => 'distance',
                'rate_id' => 1,
                'distance_km' => 68.50,
                'fuel_rate' => 4.00,
                'wear_rate' => 1.00,
                'fuel_amount' => 274.00,
                'wear_amount' => 68.50,
                'total_amount' => 342.50,
                'claim_status' => 'approved',
                'approved_by' => 'ผู้จัดการ สกต.กทม.',
                'approved_at' => '2026-11-02 17:30:00',
                'approver_note' => 'ตรวจสอบภาพถ่าย GPS ครบถ้วน ถูกต้องตามระเบียบ',
                'remark' => 'ตรวจสอบภาพถ่าย GPS ครบถ้วน ถูกต้องตามระเบียบ'
            ],
            [
                'claim_id' => 102,
                'trip_id' => 102,
                'user_id' => 1,
                'claim_type' => 'distance',
                'rate_id' => 1,
                'distance_km' => 74.20,
                'fuel_rate' => 4.00,
                'wear_rate' => 1.00,
                'fuel_amount' => 296.80,
                'wear_amount' => 74.20,
                'total_amount' => 371.00,
                'claim_status' => 'approved',
                'approved_by' => 'ผู้จัดการ สกต.กทม.',
                'approved_at' => '2026-11-03 17:45:00',
                'approver_note' => 'นำส่งเงินสดเข้าบัญชีเรียบร้อย เอกสารถูกต้อง',
                'remark' => 'นำส่งเงินสดเข้าบัญชีเรียบร้อย เอกสารถูกต้อง'
            ],
            [
                'claim_id' => 103,
                'trip_id' => 103,
                'user_id' => 1,
                'claim_type' => 'distance',
                'rate_id' => 1,
                'distance_km' => 52.80,
                'fuel_rate' => 4.00,
                'wear_rate' => 1.00,
                'fuel_amount' => 211.20,
                'wear_amount' => 52.80,
                'total_amount' => 264.00,
                'claim_status' => 'approved',
                'approved_by' => 'ผู้จัดการ สกต.กทม.',
                'approved_at' => '2026-11-04 17:00:00',
                'approver_note' => 'การเจรจาขยายจุดขายมีบันทึกรายงานแนบ',
                'remark' => 'การเจรจาขยายจุดขายมีบันทึกรายงานแนบ'
            ],
            [
                'claim_id' => 104,
                'trip_id' => 104,
                'user_id' => 1,
                'claim_type' => 'distance',
                'rate_id' => 1,
                'distance_km' => 61.40,
                'fuel_rate' => 4.00,
                'wear_rate' => 1.00,
                'fuel_amount' => 245.60,
                'wear_amount' => 61.40,
                'total_amount' => 307.00,
                'claim_status' => 'submitted',
                'approved_by' => null,
                'approved_at' => null,
                'approver_note' => null,
                'remark' => 'รอการพิจารณาอนุมัติการเดินทางและเบิกจ่ายประจำวัน (Day 4)'
            ],
            [
                'claim_id' => 105,
                'trip_id' => 105,
                'user_id' => 1,
                'claim_type' => 'distance',
                'rate_id' => 1,
                'distance_km' => 69.80,
                'fuel_rate' => 4.00,
                'wear_rate' => 1.00,
                'fuel_amount' => 279.20,
                'wear_amount' => 69.80,
                'total_amount' => 349.00,
                'claim_status' => 'submitted',
                'approved_by' => null,
                'approved_at' => null,
                'approver_note' => null,
                'remark' => 'รอการพิจารณาอนุมัติการเดินทางและเบิกจ่ายประจำวัน (Day 5)'
            ]
        ];

        foreach ($claims_data as $claim) {
            safe_insert_record($db, 'travel_claims', $claim);
        }
        $msg[] = "บันทึกใบขอเบิกและรายการอนุมัติ 5 รายการ (travel_claims) เรียบร้อย";

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
    <div class="mb-4 text-start">
        <a href="https://sktbkk.seikouedu.com/dashboard.php" class="btn btn-warning btn-sm fw-bold px-3 py-2 shadow-sm d-inline-flex align-items-center gap-1" style="border-radius: 8px;">
            <i class="bi bi-house-door-fill text-dark"></i>
            <span class="text-dark">หน้าแรก สกต. (Home)</span>
        </a>
    </div>

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

    <!-- เมนูทางลัดพิเศษ: สำหรับประชุมคณะกรรมการพรุ่งนี้ -->
    <div class="card p-3 mb-4 bg-light border-0 text-start">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <strong class="text-dark"><i class="bi bi-briefcase-fill text-success me-1"></i> ระบบเตรียมข้อมูลประชุมคณะกรรมการ สกต.กทม.</strong>
            <span class="badge bg-success">สำคัญ</span>
        </div>
        <p class="small text-muted mb-3">
            จำลองข้อมูลรายงานการเงินประจำเดือน (ยอดขาย ~฿200,000, กำไรสุทธิ ~฿20,400) พร้อมระบบสำรองข้อมูลเดิมอัตโนมัติก่อนทำ และกู้คืนข้อมูลเดิมได้ทันทีหลังประชุมเสร็จ
        </p>
        <div class="d-flex flex-wrap gap-2">
            <a href="backup_and_mockup_finance.php?action=mockup" class="btn btn-success btn-sm fw-bold px-3 py-2" onclick="return confirm('ยืนยันสร้าง Mock-up การเงินสำหรับประชุมคณะกรรมการ? (ระบบจะสำรองข้อมูลเดิมไว้อัตโนมัติ)')">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> นำเข้า Mock-up การเงินประชุมคณะกรรมการ
            </a>
            <a href="restore_initial_data.php" class="btn btn-outline-danger btn-sm fw-semibold px-3 py-2">
                <i class="bi bi-arrow-counterclockwise me-1"></i> คืนค่าข้อมูลเดิม (Restore)
            </a>
            <a href="backup_and_mockup_finance.php" class="btn btn-outline-primary btn-sm px-3 py-2">
                <i class="bi bi-gear-fill me-1"></i> จัดการระบบสำรองข้อมูล
            </a>
        </div>
    </div>

    <div class="d-grid gap-2 d-sm-flex justify-content-sm-center">
        <a href="approvals.php" class="btn btn-baac">
            <i class="bi bi-shield-check me-2"></i>เปิดหน้าศูนย์พิจารณาอนุมัติ (Approvals)
        </a>
        <a href="../financial_reports.php" class="btn btn-outline-success px-4 py-2" style="border-radius: 10px;">
            <i class="bi bi-journal-bookmark-fill me-1"></i>รายงานการเงินประจำเดือน
        </a>
        <a href="../ai_audit.php" class="btn btn-outline-secondary px-4 py-2" style="border-radius: 10px;">
            <i class="bi bi-robot me-1"></i>ไประบบ AI ตรวจสต็อก
        </a>
    </div>
</div>
</body>
</html>
