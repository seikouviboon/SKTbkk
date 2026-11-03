<?php
/**
 * สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)
 * ไฟล์: includes/navbar.php
 * คำอธิบาย: แถบเมนูด้านบน (Navigation Bar)
 * หมายเหตุ: ปรับปรุงตามคำสั่ง - หน้าร้านค้า (staff) เอาปุ่ม 'เดินทางปฏิบัติงาน' ออก
 *           ส่วน Admin / ผู้จัดการ / ผู้บริหาร ยังคงมีเมนูเดินทาง (GPS) ครบถ้วนตามเดิม
 */

if (!isset($_SESSION)) {
    session_start();
}

$current_user = $_SESSION['user'] ?? null;
$role = $current_user['role'] ?? 'guest';
$current_page = basename($_SERVER['PHP_SELF'] ?? '');
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

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-3">

            <?php if ($role === 'staff'): ?>
                <!-- ========================================== -->
                <!-- 1. สำหรับพนักงานขายหน้าร้านค้า (Staff / จุดฝากขาย) -->
                <!-- (ตัดปุ่ม 'เดินทางปฏิบัติงาน' ออกตามคำสั่ง คงเหลือเฉพาะระบบขายและสต็อก) -->
                <!-- ========================================== -->
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === 'pos.php') ? 'active fw-bold' : '' ?>" href="https://sktbkk.seikouedu.com/pos.php">
                        <i class="bi bi-cart-check-fill me-1"></i> บันทึกขาย (POS)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === 'ai_audit.php') ? 'active fw-bold' : '' ?>" href="https://sktbkk.seikouedu.com/ai_audit.php">
                        <i class="bi bi-camera-fill me-1"></i> ถ่ายรูปนับสต็อก (AI)
                    </a>
                </li>
                <!-- ปุ่มเดินทางปฏิบัติงาน (GPS) ถูกเอาออกเฉพาะหน้าร้านค้าตามคำสั่ง -->

            <?php elseif (in_array($role, ['admin', 'superadmin', 'skt01', 'manager'])): ?>
                <!-- ========================================== -->
                <!-- 2. สำหรับผู้จัดการ สกต. (Admin) & ผู้บริหาร / ประธาน (Super Admin / skt01) -->
                <!-- (คงเมนูเดิมครบถ้วน 100% พร้อมระบบเดินทางปฏิบัติงาน) -->
                <!-- ========================================== -->
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === 'dashboard.php') ? 'active fw-bold' : '' ?>" href="https://sktbkk.seikouedu.com/dashboard.php">
                        <i class="bi bi-grid-fill me-1"></i> ภาพรวม
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-info <?= ($current_page === 'financial_reports.php') ? 'active fw-bold' : '' ?>" href="https://sktbkk.seikouedu.com/financial_reports.php">
                        <i class="bi bi-journal-bookmark-fill me-1"></i> รายงานประจำเดือน
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === 'pos.php') ? 'active fw-bold' : '' ?>" href="https://sktbkk.seikouedu.com/pos.php">
                        <i class="bi bi-calculator me-1"></i> หน้าขาย (POS)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-warning <?= ($current_page === 'letter_to_president.php') ? 'active fw-bold' : '' ?>" href="https://sktbkk.seikouedu.com/letter_to_president.php">
                        <i class="bi bi-file-earmark-text-fill me-1"></i> หนังสือถึงประธาน
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === 'expenses.php') ? 'active fw-bold' : '' ?>" href="https://sktbkk.seikouedu.com/expenses.php">
                        <i class="bi bi-wallet2 me-1"></i> ค่าใช้จ่าย/ต้นทุน
                    </a>
                </li>

                <!-- เมนูเดินทาง (GPS) สำหรับผู้บริหารและ Admin -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= (strpos($_SERVER['REQUEST_URI'] ?? '', '/travel/') !== false) ? 'active fw-bold' : '' ?>" href="#" id="travelNavDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-geo-alt-fill me-1 text-warning"></i> เดินทาง (GPS)
                    </a>
                    <ul class="dropdown-menu shadow border-0" aria-labelledby="travelNavDropdown">
                        <li>
                            <a class="dropdown-item py-2" href="https://sktbkk.seikouedu.com/travel/index.php">
                                <i class="bi bi-list-ul text-primary me-2"></i>ประวัติการเดินทาง & รออนุมัติ
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item py-2" href="https://sktbkk.seikouedu.com/travel/checkin.php">
                                <i class="bi bi-play-circle-fill text-success me-2"></i>เช็คอิน / บันทึกจุดแวะ GPS
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item py-2" href="https://sktbkk.seikouedu.com/travel/rates.php">
                                <i class="bi bi-speedometer2 text-warning me-2"></i>อัตราค่าเดินทาง (มติคณะกรรมการ)
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item py-2" href="https://sktbkk.seikouedu.com/travel/approvals.php">
                                <i class="bi bi-shield-check text-success me-2"></i>ศูนย์พิจารณาอนุมัติใบเบิก
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item py-2" href="https://sktbkk.seikouedu.com/travel/report.php">
                                <i class="bi bi-bar-chart-fill text-info me-2"></i>สรุปรายงานค่าเดินทางรายเดือน
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === 'lots.php') ? 'active fw-bold' : '' ?>" href="https://sktbkk.seikouedu.com/lots.php">
                        <i class="bi bi-box-seam me-1"></i> ล็อตข้าว & คลัง สกต.
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === 'transfers.php') ? 'active fw-bold' : '' ?>" href="https://sktbkk.seikouedu.com/transfers.php">
                        <i class="bi bi-arrow-left-right me-1"></i> โอนย้ายสต็อก
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === 'stores.php') ? 'active fw-bold' : '' ?>" href="https://sktbkk.seikouedu.com/stores.php">
                        <i class="bi bi-shop me-1"></i> ร้านค้า & ค่านายหน้า
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === 'reconcile.php') ? 'active fw-bold' : '' ?>" href="https://sktbkk.seikouedu.com/reconcile.php">
                        <i class="bi bi-patch-check-fill me-1"></i> ตรวจยอดแม่มณี
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === 'ai_audit.php') ? 'active fw-bold' : '' ?>" href="https://sktbkk.seikouedu.com/ai_audit.php">
                        <i class="bi bi-robot me-1"></i> ตรวจสต็อก AI
                    </a>
                </li>
            <?php endif; ?>

            </ul>

            <div class="d-flex align-items-center gap-2 text-white">
                <!-- เมนูดรอปดาวน์คู่มือการใช้งานระบบแยกตามทุกระบบ (PDF) -->
                <div class="dropdown">
                    <button class="btn btn-warning btn-sm fw-bold dropdown-toggle d-flex align-items-center gap-1 shadow-sm px-2 py-1" type="button" id="manualDropdown" data-bs-toggle="dropdown" aria-expanded="false" title="เปิดดูคู่มือการปฏิบัติงานแยกตามระบบ (PDF)">
                        <i class="bi bi-journal-bookmark-fill"></i>
                        <span class="d-none d-md-inline">คู่มือระบบ</span>
                        <span class="badge bg-danger text-white rounded-pill px-1 ms-1" style="font-size: 0.68rem;">PDF</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-lg py-2 border-0" aria-labelledby="manualDropdown" style="min-width: 340px; max-height: 520px; overflow-y: auto; font-size: 0.88rem;">
                        <?php if ($role === 'staff'): ?>
                            <!-- สำหรับพนักงานร้านค้าตัวแทน: แสดงเฉพาะระบบที่ใช้งานจริง -->
                            <li class="dropdown-header text-baac fw-bold border-bottom pb-2 mb-1">
                                <i class="bi bi-shop me-1"></i> คู่มือปฏิบัติงานหน้าร้านค้าตัวแทน (PDF)
                            </li>
                            <li>
                                <a class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" href="https://sktbkk.seikouedu.com/manuals/manual_pos.pdf" target="_blank">
                                    <span class="text-success fs-5">🛒</span>
                                    <div>
                                        <strong class="text-dark d-block">1. บันทึกการขายหน้าร้าน (POS) & ตัดสต็อก</strong>
                                        <small class="text-muted d-block" style="font-size: 0.75rem;">เปิดบิลขาย, สแกน QR SCB แม่มณี, ตัดสต็อกอัตโนมัติ</small>
                                    </div>
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" href="https://sktbkk.seikouedu.com/manuals/manual_ai_audit.pdf" target="_blank">
                                    <span class="text-primary fs-5">🤖</span>
                                    <div>
                                        <strong class="text-dark d-block">2. ตรวจนับสต็อกอัจฉริยะ (AI Vision)</strong>
                                        <small class="text-muted d-block" style="font-size: 0.75rem;">ถ่ายรูปกองข้าวนับถุงอัตโนมัติด้วย AI สิ้นวัน</small>
                                    </div>
                                </a>
                            </li>
                        <?php else: ?>
                            <!-- สำหรับ Admin / ผู้บริหาร: แสดงคู่มือทุกระบบครบถ้วน -->
                            <li class="dropdown-header text-baac fw-bold border-bottom pb-2 mb-1">
                                <i class="bi bi-book-half me-1"></i> คู่มือปฏิบัติงานทุกระบบ (PDF)
                            </li>
                            <li>
                                <a class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" href="https://sktbkk.seikouedu.com/manuals/manual_pos.pdf" target="_blank">
                                    <span class="text-success fs-5">🛒</span>
                                    <div>
                                        <strong class="text-dark d-block">1. บันทึกการขายหน้าร้าน (POS)</strong>
                                        <small class="text-muted d-block" style="font-size: 0.75rem;">เปิดบิลขาย, สแกน QR SCB แม่มณี</small>
                                    </div>
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" href="https://sktbkk.seikouedu.com/manuals/manual_travel_gps.pdf" target="_blank">
                                    <span class="text-warning fs-5">📷</span>
                                    <div>
                                        <strong class="text-dark d-block">2. ระบบบันทึกการเดินทาง & ถ่ายรูปพิกัด GPS</strong>
                                        <small class="text-muted d-block" style="font-size: 0.75rem;">การบันทึกจุดแวะ และคำนวณเบิกค่าพาหนะ</small>
                                    </div>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="text-end d-none d-sm-block">
                    <div class="fw-semibold lh-1" style="font-size: 0.9rem;"><?= htmlspecialchars($current_user['name'] ?? $current_user['username'] ?? 'ผู้ใช้งาน') ?></div>
                    <small class="text-white-50" style="font-size: 0.75rem;">
                        <?= htmlspecialchars($current_user['store_name'] ?? 'สำนักงาน สกต.กทม.') ?>
                        <span class="badge bg-light text-dark badge-role ms-1"><?= ($role === 'staff') ? 'พนักงานขาย' : 'ผู้บริหาร/แอดมิน' ?></span>
                    </small>
                </div>
                <a href="https://sktbkk.seikouedu.com/logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3" title="ออกจากระบบ">
                    <i class="bi bi-box-arrow-right me-1"></i> ออก
                </a>
            </div>
        </div>
    </div>
</nav>
