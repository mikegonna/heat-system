<?php 
  
  session_start();
  if(!$_SESSION["user_id"])
    {
        header ("Location : login.php");
        exit();
    }
$current_user_name = isset($_GET['user']) ? htmlspecialchars($_GET['user']) : '';
?>

<html
  lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../assets/"
  data-template="vertical-menu-template-free"
>
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
    />

    <title>แจ้งเตือน Telegram</title>


    <meta name="description" content="" />

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon/favicon.ico" />

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
      rel="stylesheet"
    />



    <!-- Page CSS -->

    <!-- Helpers -->
    <script src="../assets/vendor/js/helpers.js"></script>
    
    <!-- bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  </head>

  <body>
    <!-- Layout wrapper -->








<!--BAR 1 -->

<?php

require_once ('database/db_conn.php');




?>


<div class="content-wrapper">
  <!-- Content -->
  <div class="container-xxl flex-grow-2 container-p-y">


    <style>
           .kanitfont  {
               font-family: 'Kanit', sans-serif;
           }


     </style>




     <link rel="preconnect" href="https://fonts.gstatic.com">


   <style>

   @import url('https://fonts.googleapis.com/css2?family=Kanit:wght@600&display=swap');
   </style>


       <style>
               li,a,.fontKanit  {
                   font-family: 'Kanit', sans-serif;

               }
               body {
         font-family: 'Kanit', sans-serif;



         background-repeat: no-repeat;
         background-attachment: fixed;
         background-size: cover;

       }
       head {
         font-family: 'Kanit', sans-serif;

       }
       div {
         font-family: 'Kanit', sans-serif;





       }
           </style>





           <div class="kanitfont">

           </head>
           <style type="text/css">
                .tableFix { /* Scrollable parent element */
             position: relative;
             overflow: auto;
             height: 500px;
           }

           .tableFix table{
             width: 100%;
             border-collapse: collapse;
           }

           .tableFix th,
           .tableFix td{
             padding: 8px;
             text-align: left;
           }

           .tableFix thead th {
             position: sticky;  /* Edge, Chrome, FF */
             top: 0px;
             background: #fff;  /* Some background is needed */
           }
           #errorMessage,
           #successMessage {
             display: none;
           }
           </style>
           <body>







             <?php
             function DateThai($strDate)
                 {
                     $strYear = date("Y",strtotime($strDate))+543;
                     $strMonth= date("n",strtotime($strDate));
                     $strDay= date("j",strtotime($strDate));
                     $strHour= date("H",strtotime($strDate));
                     $strMinute= date("i",strtotime($strDate));
                     $strSeconds= date("s",strtotime($strDate));
                     $strMonthCut = Array("","มกราคม","กุมภาพันธ์","มีนาคม","เมษายน","พฤษภาคม","มิถุนายน","กรกฎาคม","สิงหาคม","กันยายน","ตุลาคม","พฤศจิกายน","ธันวาคม");
                     $strMonthThai=$strMonthCut[$strMonth];
                     return "$strDay $strMonthThai  $strYear";
                 }



             ?>

             <script>
             function showDiv(divId, element)
             {
                 document.getElementById(divId).style.display = element.value >= 3 ? 'block' : 'none';
             }
             </script>
             <style>
             #hidden_div {
                 display: none;
             }
             </style>



<a href="javascript:history.back()" class="btn btn-outline-primary btn-sm">
    ย้อนกลับ
</a>
<br><br>

<center>
              <div class="row">
                <!-- Order Statistics -->
                <div class="col-md-12 col-lg-12 col-xl-12 order-0 mb-12">
                  <div class="card h-100">




                    <div class="card-body">




                       <style>
                              .kanitfont  {
                                  font-family: 'Kanit', sans-serif;
                              }


                        </style>














<div class="d-flex justify-content-center align-items-center" style="min-height: 80vh;">
    <div class="card shadow" style="width: 100%; max-width: 500px;">

        <div class="card-header text-center fw-bold">
            บันทึกข้อมูลผู้ป่วยเจ็บ
        </div>

        <div class="card-body">

            <form method="post">

                <!-- หน่วยฝึก -->
                <div class="mb-3">
                    <label class="form-label">
                        <span class="text-danger">*</span> ระบุหน่วยฝึก
                    </label>
                    <input type="text" name="type" class="form-control" required>
                </div>

                <!-- จำนวน -->
                <div class="mb-3">
                    <label class="form-label">
                        <span class="text-danger">*</span> จำนวนผู้ป่วย
                    </label>
                    <input type="number" name="count" class="form-control" required>
                </div>

                <!-- ชื่อ -->
                <div class="mb-3">
                    <label class="form-label">ยศ ชื่อสกุล ผู้ส่งข้อมูล</label>
                    <input type="text" name="name1" class="form-control">
                </div>

                <!-- โทร -->
                <div class="mb-3">
                    <label class="form-label">เบอร์โทร ผู้ส่งข้อมูล</label>
                    <input type="text" name="tel1" class="form-control">
                </div>

                <!-- hidden -->
                <input type="hidden" name="user" value="<?php echo isset($_GET['user']) ? $_GET['user'] : ''; ?>">
                <input type="hidden" name="save" value="1">

                <!-- ปุ่ม -->
                <div class="d-flex justify-content-between mt-4">

                    <button type="submit" class="btn btn-primary">
                        บันทึกข้อมูล
                    </button>

                    <?php if(isset($_GET["user"]) && $_GET["user"] != "") { ?>
                        <a class="btn btn-danger"
                           href="?delid=<?php echo $_GET["user"]; ?>"
                           onclick="return confirm('ต้องการลบข้อมูลผู้บาดเจ็บรายนี้ .... ?');">
                           ลบข้อมูล
                        </a>
                    <?php } ?>

                </div>

            </form>

        </div>
    </div>
</div>



            <!-- / Content -->
            <!-- Footer -->

            <!-- / Footer -->

            <div class="content-backdrop fade"></div>
          </div>
          <!-- Content wrapper -->
          </div>
          <!-- / Layout page -->
          </div>

          <!-- Overlay -->
          <div class="layout-overlay layout-menu-toggle"></div>
          </div>
          <!-- / Layout wrapper -->



          <!-- Core JS -->
          <!-- build:js assets/vendor/js/core.js -->
          <script src="../assets/vendor/libs/jquery/jquery.js"></script>
          <script src="../assets/vendor/libs/popper/popper.js"></script>
          <script src="../assets/vendor/js/bootstrap.js"></script>
          <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>

          <script src="../assets/vendor/js/menu.js"></script>
          <!-- endbuild -->

          <!-- Vendors JS -->
          <script src="../assets/vendor/libs/apex-charts/apexcharts.js"></script>

          <!-- Main JS -->
          <script src="../assets/js/main.js"></script>

          <!-- Page JS -->



<?php


// INSERT

if (isset($_POST["count"])) {





// ตั้งค่า Bot Token และ Chat ID
$botToken = "7403074195:AAFXlSN8JljXJ2Vjvjs7OlylDkzkSs9a_ys"; // ใส่ Telegram bot token ของคุณ
$chatId = "-4745966683"; // ใส่ chat ID ของคุณ

// รับค่าจากฟอร์ม
$type = $_POST["type"];
$count  = $_POST["count"];
$name1  = $_POST["name1"];
$tel1  = $_POST["tel1"];

// สร้างข้อความ
$message = "{$type} นำส่งผู้ป่วย Heat Injury {$count} ราย ที่ห้องฉุกเฉิน\nผู้บันทึก : {$name1} เบอร์ : {$tel1}\nhttps://anan-hosp.go.th/ananHeat2";

$telegramApiUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";

$data = [
    'chat_id' => $chatId,
    'text' => $message,
    'parse_mode' => 'HTML' // หรือจะใช้ Markdown ก็ได้
];

// เริ่มส่ง
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $telegramApiUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
$response = curl_exec($ch);


// ตรวจสอบผลลัพธ์
if(curl_error($ch)){
    echo 'Error: ' . curl_error($ch);
} else {
    $responseDecoded = json_decode($response, true);
    if ($responseDecoded["ok"]) {
    echo "<script>";
    echo "alert('บันทึกข้อมูลของท่านเรียบร้อยแล้ว');";
    echo "window.history.back();";
    echo "</script>";
} else {
    echo "ส่งไม่สำเร็จ: " . $responseDecoded["description"];
}
}
curl_close($ch);
}
?>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        </body>
      </html>
