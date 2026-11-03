<?php
/**
 * สหกรณ์การเกษตรเพื่อการตลาดลูกค้า ธ.ก.ส. กรุงเทพมหานคร จำกัด (สกต.กทม.)
 * ไฟล์: test_login.php
 * วัตถุประสงค์: สำหรับข้ามหน้าล็อกอินตรงเข้าสู่ระบบ Admin ทันทีในระหว่างการทดสอบระบบ
 * วิธีใช้งาน: เปิดผ่านเบราว์เซอร์ https://sktbkk.seikouedu.com/travel/test_login.php
 */

session_start();

// กำหนดสิทธิ์เป็น Admin / ผู้จัดการ สกต.กทม. ทันที
$_SESSION['user'] = [
    'id' => 1,
    'username' => 'admin',
    'role' => 'admin',
    'name' => 'ผู้จัดการ สกต.กทม.',
    'email' => 'admin@sktbkk.com',
    'logged_in_time' => date('Y-m-d H:i:s')
];

// ส่งตรงเข้าสู่หน้าหลักระบบบันทึกการเดินทางทันที
header('Location: index.php?mode=testing_admin_bypass');
exit;
