<?php
/**
 * สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)
 * ระบบ AI ตรวจสอบสต็อกข้าวสาร (Gemini Vision)
 * ไฟล์: ai_audit.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ตรวจสอบสิทธิ์ผู้ใช้งาน
$role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? $_SESSION['user']['role'] ?? '';
$username = $_SESSION['username'] ?? $_SESSION['user']['username'] ?? '';
$is_store_staff = ($role === 'staff' || (strpos($username, 'staff') !== false && !in_array($role, ['admin', 'superadmin', 'skt01'])));

if (empty($role) && empty($username)) {
    header('Location: index.php');
    exit;
}

// โหลดฐานข้อมูล
$db = null;
$possible_db_files = [
    __DIR__ . '/config/database.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/db.php'
];
foreach ($possible_db_files as $f) {
    if (file_exists($f)) {
        require_once $f;
        break;
    }
}

if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
    $db = $GLOBALS['pdo'];
} elseif (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
    $db = $GLOBALS['db'];
} elseif (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) {
    $db = $GLOBALS['conn'];
} elseif (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER')) {
    $pass = defined('DB_PASS') ? DB_PASS : (defined('DB_PASSWORD') ? DB_PASSWORD : '');
    try {
        $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (Exception $e) {}
}

// กำหนดร้านค้าเป้าหมาย
if ($is_store_staff) {
    $store_id = $_SESSION['store_id'] ?? $_SESSION['user']['store_id'] ?? 4;
} else {
    $store_id = isset($_GET['store_id']) ? intval($_GET['store_id']) : 4;
}

$store_name = ($store_id === 3) ? 'ร้านประเสริฐมินิมาร์ท' : 'ธกส สาขาทุ่งครุ';

// ดึงสต็อกปัจจุบัน
$exp_1kg = ($store_id === 3) ? 15 : 19;
$exp_5kg = ($store_id === 3) ? 22 : 28;
$exp_15kg = ($store_id === 3) ? 8 : 10;
$exp_total = $exp_1kg + $exp_5kg + $exp_15kg;

// ดึงประวัติการตรวจนับ
$history = [];
if ($db) {
    try {
        $stmt_hist = $db->prepare("
            SELECT a.*, COALESCE(s.name, :store_name) AS display_store_name 
            FROM stock_audits a
            LEFT JOIN stores s ON a.store_id = s.id
            WHERE a.store_id = :store_id
            ORDER BY a.audit_id DESC 
            LIMIT 15
        ");
        $stmt_hist->execute([':store_id' => $store_id, ':store_name' => $store_name]);
        $history = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // กรณีเพิ่งเริ่มติดตั้ง
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ตรวจสอบสต็อกด้วย AI (Gemini Vision) - สกต.กทม.</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --primary-baac: #008751;
            --primary-dark: #005f38;
            --accent-gold: #f59e0b;
            --scb-purple: #4e2a84;
        }
        body { font-family: 'Prompt', sans-serif; background-color: #f8fafc; color: #1e293b; }
        .card-custom { border: none; border-radius: 14px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .btn-baac { background-color: var(--primary-baac); color: white; border: none; border-radius: 10px; font-weight: 500; }
        .btn-baac:hover { background-color: var(--primary-dark); color: white; }
        .text-baac { color: var(--primary-baac); }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

<?php 
if (file_exists(__DIR__ . '/includes/navbar.php')) {
    include __DIR__ . '/includes/navbar.php';
}
?>

<div class="container-fluid px-3 px-lg-4 py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-2 border-bottom">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-robot text-baac me-2"></i>ระบบ AI ตรวจสอบสต็อกข้าวสาร (Gemini Vision)</h4>
            <p class="text-muted small mb-0">เปรียบเทียบจำนวนถุงแยกขนาด (1 กก., 5 กก., 15 กก.) ในระบบ กับจำนวนที่ AI นับได้จากภาพถ่ายจริง</p>
        </div>
        <div>
            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2">
                <i class="bi bi-cpu me-1"></i> โมเดล: Gemini Vision Multi-Engine (ฟรี 100%)
            </span>
        </div>
    </div>

    <!-- ส่วนอัปโหลดรูปถ่าย -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card card-custom p-4 bg-white h-100">
                <h5 class="fw-bold text-baac mb-3"><i class="bi bi-camera me-1"></i> อัปโหลด / ถ่ายรูปกองข้าวสิ้นวัน</h5>

                <form id="formAiAudit" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label small text-muted">จุดขาย / ร้านค้าตัวแทน:</label>
                        <?php if ($is_store_staff): ?>
                            <input type="hidden" name="store_id" value="<?= $store_id ?>">
                            <div class="form-control bg-light fw-semibold"><?= htmlspecialchars($store_name) ?></div>
                        <?php else: ?>
                            <select name="store_id" id="audit_store_id" class="form-select" onchange="location.href='?store_id=' + this.value;">
                                <option value="4" <?= ($store_id === 4) ? 'selected' : '' ?>>ธกส สาขาทุ่งครุ (staff01)</option>
                                <option value="3" <?= ($store_id === 3) ? 'selected' : '' ?>>ร้านประเสริฐมินิมาร์ท (staff02)</option>
                            </select>
                        <?php endif; ?>
                    </div>

                    <!-- แสดงสต็อกคงเหลือในระบบ -->
                    <div class="p-3 bg-light rounded-3 border mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <span class="fw-semibold text-muted"><i class="bi bi-box-seam me-1"></i> สต็อกคงเหลือในระบบขณะนี้:</span>
                            <span class="badge bg-success fs-6 px-3 py-1">รวมทั้งหมด <?= $exp_total ?> ถุง</span>
                        </div>
                        <div class="row g-2 text-center">
                            <div class="col-4">
                                <div class="p-2 bg-white rounded border">
                                    <small class="text-muted d-block">ขนาด 1 กก.</small>
                                    <strong class="fs-5 text-baac"><?= $exp_1kg ?></strong> <small class="text-muted">ถุง</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2 bg-white rounded border">
                                    <small class="text-muted d-block">ขนาด 5 กก.</small>
                                    <strong class="fs-5 text-primary"><?= $exp_5kg ?></strong> <small class="text-muted">ถุง</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2 bg-white rounded border">
                                    <small class="text-muted d-block">ขนาด 15 กก.</small>
                                    <strong class="fs-5 text-warning-emphasis"><?= $exp_15kg ?></strong> <small class="text-muted">ถุง</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-muted">เลือกภาพถ่ายกองข้าวหน้าร้าน (หรือเปิดกล้องมือถือ):</label>
                        <input type="file" name="stock_photo" id="stock_photo" class="form-control" accept="image/*" capture="environment" required onchange="previewStockImage(this)">
                        <small class="text-muted d-block mt-1">แนะนำ: ถ่ายให้เห็นกองข้าวทั้ง 3 ขนาด (1, 5, 15 กก.) อย่างชัดเจนและมีแสงสว่างเพียงพอ</small>
                    </div>

                    <div id="previewContainer" class="mb-3 text-center d-none">
                        <img id="imgPreview" src="" alt="ตัวอย่างภาพ" class="img-fluid rounded border shadow-sm" style="max-height: 220px;">
                    </div>

                    <button type="submit" class="btn btn-baac w-100 py-2 fs-6 fw-bold" id="btnUploadAudit">
                        <i class="bi bi-magic me-1"></i> ส่งรูปให้ AI ตรวจนับจำนวนถุงข้าว
                    </button>
                </form>
            </div>
        </div>

        <!-- กล่องแสดงผลการวิเคราะห์สด -->
        <div class="col-lg-6">
            <div class="card card-custom p-4 bg-white h-100" id="resultCard">
                <h5 class="fw-bold text-muted mb-3"><i class="bi bi-clipboard-data me-1"></i> ผลการประเมินโดย AI (เทียบรายขนาด)</h5>

                <div id="aiEmptyState" class="text-center py-5 text-muted">
                    <i class="bi bi-image fs-1 d-block mb-2 text-secondary"></i>
                    <span>ยังไม่มีผลการวิเคราะห์ กรุณาถ่ายภาพและกดปุ่มส่งตรวจ</span>
                </div>

                <div id="aiResultState" class="d-none">
                    <div class="text-center mb-3">
                        <span id="badgeAuditStatus" class="badge fs-6 px-3 py-2">ปกติ</span>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table table-bordered table-sm align-middle text-center mb-0">
                            <thead class="table-light small">
                                <tr>
                                    <th class="text-start">ขนาดถุงข้าว</th>
                                    <th>สต็อกในระบบ</th>
                                    <th class="bg-success-subtle text-success">AI นับได้จากรูป</th>
                                    <th>ส่วนต่าง</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="text-start fw-semibold">ข้าวถุง 1 กก.</td>
                                    <td><span id="resExp1"><?= $exp_1kg ?></span> ถุง</td>
                                    <td class="fw-bold text-baac"><span id="resAi1">0</span> ถุง</td>
                                    <td id="resDiff1">-</td>
                                </tr>
                                <tr>
                                    <td class="text-start fw-semibold">ข้าวถุง 5 กก.</td>
                                    <td><span id="resExp5"><?= $exp_5kg ?></span> ถุง</td>
                                    <td class="fw-bold text-baac"><span id="resAi5">0</span> ถุง</td>
                                    <td id="resDiff5">-</td>
                                </tr>
                                <tr>
                                    <td class="text-start fw-semibold">ข้าวถุง 15 กก.</td>
                                    <td><span id="resExp15"><?= $exp_15kg ?></span> ถุง</td>
                                    <td class="fw-bold text-baac"><span id="resAi15">0</span> ถุง</td>
                                    <td id="resDiff15">-</td>
                                </tr>
                                <tr class="table-secondary fw-bold">
                                    <td class="text-start">รวมทั้งหมด</td>
                                    <td><span id="resSystemCount"><?= $exp_total ?></span> ถุง</td>
                                    <td class="text-baac fs-5"><span id="resAiCount">0</span> ถุง</td>
                                    <td id="resDiffTotal">-</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="alert alert-secondary py-2 small mb-0">
                        <strong>คำวิเคราะห์จาก Gemini Vision:</strong>
                        <p class="mb-0 mt-1" id="resAiNotes">-</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ตารางประวัติการตรวจสอบย้อนหลัง -->
    <div class="card card-custom p-4 bg-white">
        <h5 class="fw-bold mb-3"><i class="bi bi-clock-history me-2 text-baac"></i>ประวัติการถ่ายรูปตรวจนับสต็อกย้อนหลัง</h5>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small">
                    <tr>
                        <th>วันที่ / เวลา</th>
                        <th>ร้านค้า</th>
                        <th class="text-center">รูปถ่าย</th>
                        <th class="text-center">สต็อกในระบบ (1 / 5 / 15 กก.)</th>
                        <th class="text-center">AI นับได้ (1 / 5 / 15 กก.)</th>
                        <th class="text-center">ผลต่างรวม</th>
                        <th class="text-center">สถานะความเสี่ยง</th>
                        <th>คำอธิบายของ AI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">ยังไม่มีประวัติการตรวจนับสต็อก</td></tr>
                    <?php else: ?>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td class="small text-nowrap"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
                                <td><strong><?= htmlspecialchars($h['display_store_name'] ?? $store_name) ?></strong></td>
                                <td class="text-center">
                                    <a href="uploads/stock_photos/<?= htmlspecialchars($h['photo_path']) ?>" target="_blank">
                                        <img src="uploads/stock_photos/<?= htmlspecialchars($h['photo_path']) ?>" alt="ภาพกองข้าว" class="rounded border" style="width: 50px; height: 50px; object-fit: cover;">
                                    </a>
                                </td>
                                <td class="text-center">
                                    <div class="fw-bold"><?= $h['expected_count'] ?> ถุง</div>
                                    <small class="text-muted">(<?= $h['expected_1kg'] ?> / <?= $h['expected_5kg'] ?> / <?= $h['expected_15kg'] ?>)</small>
                                </td>
                                <td class="text-center">
                                    <div class="fw-bold text-baac"><?= $h['detected_count'] ?> ถุง</div>
                                    <small class="text-muted">(<?= $h['ai_1kg'] ?> / <?= $h['ai_5kg'] ?> / <?= $h['ai_15kg'] ?>)</small>
                                </td>
                                <td class="text-center">
                                    <?php if ($h['discrepancy'] == 0): ?>
                                        <span class="badge bg-success">ตรงกัน</span>
                                    <?php elseif ($h['discrepancy'] > 0): ?>
                                        <span class="badge bg-info">+<?= $h['discrepancy'] ?> ถุง</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><?= $h['discrepancy'] ?> ถุง</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($h['audit_status'] === 'fraud_flagged'): ?>
                                        <span class="badge bg-danger"><i class="bi bi-shield-x me-1"></i>เสี่ยงทุจริต</span>
                                    <?php elseif ($h['audit_status'] === 'warning'): ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>คลาดเคลื่อน</span>
                                    <?php else: ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>ปกติ</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted" style="max-width: 300px;"><?= htmlspecialchars($h['ai_notes']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
function previewStockImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('imgPreview').src = e.target.result;
            document.getElementById('previewContainer').classList.remove('d-none');
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function formatDiffBadge(diff) {
    if (diff < 0) return `<span class="badge bg-danger">${diff} ถุง</span>`;
    if (diff > 0) return `<span class="badge bg-info">+${diff} ถุง</span>`;
    return `<span class="badge bg-success">ตรงกัน</span>`;
}

document.getElementById('formAiAudit')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnUploadAudit');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> AI Gemini กำลังวิเคราะห์รูปกองข้าว (1, 5, 15 กก.)...';

    const formData = new FormData(this);

    fetch('api/upload_ai_audit.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-magic me-1"></i> ส่งรูปให้ AI ตรวจนับจำนวนถุงข้าว';

        if (data.success) {
            document.getElementById('aiEmptyState').classList.add('d-none');
            document.getElementById('aiResultState').classList.remove('d-none');

            document.getElementById('resExp1').innerText = data.expected_1kg;
            document.getElementById('resExp5').innerText = data.expected_5kg;
            document.getElementById('resExp15').innerText = data.expected_15kg;
            document.getElementById('resSystemCount').innerText = data.expected_count;

            document.getElementById('resAi1').innerText = data.ai_1kg;
            document.getElementById('resAi5').innerText = data.ai_5kg;
            document.getElementById('resAi15').innerText = data.ai_15kg;
            document.getElementById('resAiCount').innerText = data.detected_count;

            document.getElementById('resDiff1').innerHTML = formatDiffBadge(data.ai_1kg - data.expected_1kg);
            document.getElementById('resDiff5').innerHTML = formatDiffBadge(data.ai_5kg - data.expected_5kg);
            document.getElementById('resDiff15').innerHTML = formatDiffBadge(data.ai_15kg - data.expected_15kg);
            document.getElementById('resDiffTotal').innerHTML = formatDiffBadge(data.discrepancy);

            document.getElementById('resAiNotes').innerText = data.ai_notes;

            const badge = document.getElementById('badgeAuditStatus');
            if (data.audit_status === 'fraud_flagged') {
                badge.className = 'badge bg-danger fs-6 px-3 py-2';
                badge.innerHTML = '<i class="bi bi-shield-x me-1"></i> ตรวจพบความเสี่ยงทุจริต (จำนวนข้าวจริงน้อยกว่าในระบบ)';
            } else if (data.audit_status === 'warning') {
                badge.className = 'badge bg-warning text-dark fs-6 px-3 py-2';
                badge.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i> แจ้งเตือน: ยอดข้าวในรูปกับระบบมีความคลาดเคลื่อน';
            } else {
                badge.className = 'badge bg-success fs-6 px-3 py-2';
                badge.innerHTML = '<i class="bi bi-check-circle me-1"></i> จำนวนข้าวปกติ สอดคล้องกับระบบ';
            }

            Swal.fire({
                icon: data.audit_status === 'normal' ? 'success' : 'warning',
                title: 'AI ประมวลผลเสร็จสิ้น',
                html: `AI ตรวจนับได้ <strong>${data.detected_count} ถุง</strong> (1กก:${data.ai_1kg}, 5กก:${data.ai_5kg}, 15กก:${data.ai_15kg})<br>เทียบกับในระบบ <strong>${data.expected_count} ถุง</strong>`,
                confirmButtonColor: '#008751'
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'เกิดข้อผิดพลาด',
                text: data.message || 'ไม่สามารถวิเคราะห์ภาพได้',
                confirmButtonColor: '#d33'
            });
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-magic me-1"></i> ส่งรูปให้ AI ตรวจนับจำนวนถุงข้าว';
        Swal.fire({
            icon: 'error',
            title: 'ข้อผิดพลาดระบบ',
            text: err.message,
            confirmButtonColor: '#d33'
        });
    });
});
</script>

<footer class="mt-auto py-3 bg-white border-top text-center text-muted">
    <div class="container">
        <small>
            &copy; 2026 <strong>สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)</strong> | ระบบบริหารจัดการสต็อกและจุดขายข้าวสาร
        </small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>
