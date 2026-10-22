<?php
// --- Logic การเลือกสี Footer ---

// 1. ถ้าหน้านั้นๆ มีการประกาศตัวแปร $footerColor ไว้ก่อนเรียกไฟล์นี้ ให้ใช้สีนั้นเลย (Priority สูงสุด)
if (isset($footerColor)) {
    $bgColor = $footerColor;
} 
// 2. ถ้ามีตัวแปร $isICU (จากหน้า treatmentWard.php)
elseif (isset($isICU) && $isICU) {
    $bgColor = '#FF8C00'; // สีธีม ICU (สีส้มเข้ม เพื่อให้อ่านตัวหนังสือสีขาวชัดเจน)
}
// 3. ถ้าไม่มี ให้เช็คจาก Session ของ User ที่ Login อยู่ (Auto Theme)
elseif (isset($_SESSION['name'])) {
    $role = strtolower($_SESSION['name']);
    
    switch ($role) {
        case 'icu':
            $bgColor = '#FF8C00'; // สีธีม ICU (ส้มเข้ม Orange-600)
            break;
        case 'pmr':
            $bgColor = '#8b5cf6'; // สีธีม PMR (ม่วงอ่อนหรูหรา)
            break;
        case 'med':
        case 'occ':
            $bgColor = '#0284c7'; // สีธีม MED (ฟ้า Sky-600)
            break;
        case 'er':
            $bgColor = '#059669'; // สีธีม ER (เขียว)
            break;
        case 'pct':
            $bgColor = '#2c5364'; // สีธีม PCT
            break;
        case 'pcu':
        case 'coc':
            $bgColor = '#d6728d'; // สีธีม Visit (ชมพูละมุน)
            break;
        default:
            $bgColor = '#327c62'; // สี Default (Ward ทั่วไป)
            break;
    }
} 
// 4. Default สุดท้าย
else {
    $bgColor = '#005461';
}
?>

</div> <style>
    body {
        display: flex;
        flex-direction: column;
        min-height: 100vh; /* บังคับให้ความสูงขั้นต่ำเท่ากับ 100% ของหน้าจอ */
    }
    footer {
        margin-top: auto !important; /* ดัน Footer ไปอยู่ล่างสุด */
        width: 100%;
    }
</style>

<footer style="background-color: <?php echo $bgColor; ?> !important; transition: background-color 0.3s;" class="text-center text-lg-start border-top shadow-sm mt-auto">
    <div class="container p-4">
        <div class="col-lg-6 col-md-6 mb-2 mb-md-2 mx-auto text-center">
            <h5 class="text-uppercase text-white font-prompt">
                <i class="fa-solid fa-hospital me-2" style="font-size: 24px;"></i> 
                โรงพยาบาลอานันทมหิดล
            </h5>
            <p class="text-white-50 small mb-0 font-sarabun">
                ระบบบริหารจัดการโรคลมร้อน
            </p>
        </div>
    </div>
    <div class="text-center p-2 text-white small" style="background-color: rgba(0, 0, 0, 0.1);">
        © <?php echo date('Y'); ?> สำนักงานวิชาการ โรงพยาบาลอานันทมหิดล
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php if(isset($_SESSION['statusMsg']) && isset($_SESSION['statusType'])): ?>
    <script>
        Swal.fire({
            icon: '<?php echo $_SESSION['statusType']; ?>',
            title: 'แจ้งเตือน',
            text: '<?php echo $_SESSION['statusMsg']; ?>',
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '<?php echo $bgColor; ?>', // ปรับสีปุ่ม Alert ให้ตรงธีมด้วย
            timer: 2500,
            timerProgressBar: true
        });
    </script>
    <?php 
    unset($_SESSION['statusMsg']); 
    unset($_SESSION['statusType']); 
    ?>
<?php endif; ?>

</body>
</html>