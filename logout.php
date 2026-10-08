<?php
session_start();

// 1. ล้างตัวแปร Session ทั้งหมด
session_unset();

// 2. ทำลาย Session ทิ้ง
session_destroy();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ออกจากระบบ</title>
    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Prompt', sans-serif;
            background-color: #f0f2f0;
        }
    </style>
</head>
<body>
    <script>
        Swal.fire({
            icon: 'success',
            title: 'ออกจากระบบสำเร็จ',
            text: 'ขอบคุณที่เข้าใช้งานระบบ',
            showConfirmButton: false, // ไม่ต้องมีปุ่มกด
            timer: 500,             // นับถอยหลัง 0.5 วินาที
            timerProgressBar: true,
            allowOutsideClick: false // ห้ามคลิกข้างนอก
        }).then(() => {
            // เมื่อครบเวลา ให้ย้ายหน้า
            // ** สำคัญ: เช็ค Path ตรงนี้ **
            // ถ้าไฟล์นี้อยู่ใน folder "user" และ login อยู่หน้าหลัก ให้ใช้ '../login.php'
            // ถ้าไฟล์นี้อยู่หน้าหลัก ให้ใช้ 'login.php'
            window.location.href = 'login.php'; 
        });
    </script>
</body>
</html>