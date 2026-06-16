<?php
// ==========================================
// config.php - ระบบตั้งค่าการเชื่อมต่อและการอัพเดทฐานข้อมูลอัตโนมัติ
// Designed for deployment on school servers and Shared Hosting.
// ==========================================

header('Content-Type: text/html; charset=utf-8');

// ไฟล์จัดเก็บการเชื่อมต่อแบบไดนามิกเพื่อความสะดวกในการจัดการ
define('DB_CONFIG_FILE', __DIR__ . '/db_config.json');

// ค่าตั้งต้นมาตรฐาน
$db_host = 'localhost:3306';
$db_name = 'schoolos_ClassroomSupervision';
$db_user = 'ClassroomSupervision';
$db_pass = 'lp8t@Spe!pCBq04u';

// โหลดข้อมูลการเชื่อมต่อจากไฟล์ที่บันทึกไว้ในระบบอัตโนมัติ
if (file_exists(DB_CONFIG_FILE)) {
    $saved_config = json_decode(file_get_contents(DB_CONFIG_FILE), true);
    if ($saved_config) {
        $db_host = $saved_config['db_host'] ?? $db_host;
        $db_name = $saved_config['db_name'] ?? $db_name;
        $db_user = $saved_config['db_user'] ?? $db_user;
        $db_pass = $saved_config['db_pass'] ?? $db_pass;
    }
} else {
    // บันทึกไฟล์ตั้งต้นเมื่อเรียกใช้งานครั้งแรก
    $default_config = [
        'db_host' => $db_host,
        'db_name' => $db_name,
        'db_user' => $db_user,
        'db_pass' => $db_pass
    ];
    file_put_contents(DB_CONFIG_FILE, json_encode($default_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ตรวจสอบการบันทึกการเชื่อมต่อใหม่จากฟอร์ม Setup (กรณีเชื่อมต่อนอกระบบ)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_db_config'])) {
    $new_host = trim($_POST['db_host'] ?? '');
    $new_name = trim($_POST['db_name'] ?? '');
    $new_user = trim($_POST['db_user'] ?? '');
    $new_pass = trim($_POST['db_pass'] ?? '');

    if (!empty($new_host) && !empty($new_name) && !empty($new_user)) {
        $update_config = [
            'db_host' => $new_host,
            'db_name' => $new_name,
            'db_user' => $new_user,
            'db_pass' => $new_pass
        ];
        file_put_contents(DB_CONFIG_FILE, json_encode($update_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// เริ่มต้นพยายามเชื่อมต่อฐานข้อมูล PDO
try {
    // แยก host และ port หากมีการระบุมาในรูปแบบ host:port เช่น localhost:3306
    $host_parts = explode(':', $db_host);
    $host_only = $host_parts[0];
    $port_only = $host_parts[1] ?? '3306';

    $pdo = null;
    
    // 1. พยายามเชื่อมตรงเข้าสู่ Database ทันที (ดีที่สุดสำหรับระบบปิด/Shared hosting ที่จำกัดสิทธิ์ผู้ใช้)
    try {
        $dsn = "mysql:host={$host_only};port={$port_only};dbname={$db_name};charset=utf8mb4";
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]);
    } catch (PDOException $db_err) {
        // หากเชื่อมไม่ผ่านเพราะไม่มีก้อน Database ให้พยายามสร้างขึ้นมาใหม่ (เช่น รันบน Local/XAMPP ครั้งแรก)
        $err_msg = $db_err->getMessage();
        if ($db_err->getCode() == 1049 || strpos($err_msg, 'Unknown database') !== false || strpos($err_msg, '1049') !== false) {
            $dsn_no_db = "mysql:host={$host_only};port={$port_only};charset=utf8mb4";
            $pdo = new PDO($dsn_no_db, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$db_name}`");
        } else {
            // หากพลาดจากรหัสผ่าน/ชื่อผู้ใช้งาน ให้ส่งต่อ Error ออกไป
            throw $db_err;
        }
    }

    // =========================================================
    // ระบบการอัพเดทฐานข้อมูลและตารางโดยอัตโนมัติ (Automated Schema Installer)
    // =========================================================
    
    // ตารางคุณครูผู้สอน
    $pdo->exec("CREATE TABLE IF NOT EXISTS `teachers` (
        `teacher_id` VARCHAR(50) NOT NULL,
        `teacher_name` VARCHAR(150) NOT NULL,
        `position` VARCHAR(100) NOT NULL,
        `subject_group` VARCHAR(150) NOT NULL,
        `phone` VARCHAR(20) DEFAULT NULL,
        `username` VARCHAR(50) DEFAULT NULL,
        PRIMARY KEY (`teacher_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // ตารางปีการศึกษา
    $pdo->exec("CREATE TABLE IF NOT EXISTS `academic_years` (
        `year_id` VARCHAR(50) NOT NULL,
        `year` VARCHAR(10) NOT NULL,
        `semester` VARCHAR(5) NOT NULL,
        PRIMARY KEY (`year_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // ตารางเกณฑ์การประเมิน 20 รายการ
    $pdo->exec("CREATE TABLE IF NOT EXISTS `evaluation_items` (
        `item_id` VARCHAR(10) NOT NULL,
        `item_name` TEXT NOT NULL,
        `category` VARCHAR(150) NOT NULL,
        PRIMARY KEY (`item_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // ตารางผลประเมินนิเทศ
    $pdo->exec("CREATE TABLE IF NOT EXISTS `supervisions` (
        `record_id` VARCHAR(50) NOT NULL,
        `teacher_id` VARCHAR(50) NOT NULL,
        `year_id` VARCHAR(50) NOT NULL,
        `class_name` VARCHAR(100) NOT NULL,
        `subject_name` VARCHAR(150) NOT NULL,
        `date_string` DATE NOT NULL,
        `scores_json` TEXT NOT NULL,
        `comments_strengths` TEXT DEFAULT NULL,
        `comments_suggestions` TEXT DEFAULT NULL,
        `comments_development` TEXT DEFAULT NULL,
        `photos_json` TEXT DEFAULT NULL,
        `evaluator_name` VARCHAR(150) NOT NULL,
        `evaluator_position` VARCHAR(100) NOT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
        PRIMARY KEY (`record_id`),
        KEY `teacher_id` (`teacher_id`),
        KEY `year_id` (`year_id`),
        CONSTRAINT `fk_supervision_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_supervision_year` FOREIGN KEY (`year_id`) REFERENCES `academic_years` (`year_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // ตารางบัญชีผู้ใช้ระบบ
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `username` VARCHAR(50) NOT NULL,
        `password` VARCHAR(255) NOT NULL,
        `fullname` VARCHAR(150) NOT NULL,
        `role` VARCHAR(20) NOT NULL DEFAULT 'teacher',
        PRIMARY KEY (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // ==========================================
    // ตรวจสอบความถูกต้องและลงข้อมูลเริ่มต้นอัตโนมัติ (Automated Database Seeding)
    // ==========================================
    
    // 1. ลงผู้ใช้แอดมินและผู้อำนวยการ
    $check_users = $pdo->query("SELECT COUNT(*) FROM `users`")->fetchColumn();
    if ($check_users == 0) {
        $admin_pwd = password_hash('123456', PASSWORD_DEFAULT);
        $director_pwd = password_hash('123456', PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("INSERT INTO `users` (`username`, `password`, `fullname`, `role`) VALUES (?, ?, ?, ?)");
        $stmt->execute(['admin', $admin_pwd, 'ผู้ดูแลระบบคอมพิวเตอร์โรงเรียน', 'admin']);
        $stmt->execute(['director', $director_pwd, 'ดร. นิพัทธ์ สรรพวิเชียร', 'director']);
    }

    // 2. ลงเกณฑ์การประเมิน 20 ข้อ (สตรีมมาตรฐานกระทรวงศึกษาธิการ 5 หมวดหลัก)
    $check_items = $pdo->query("SELECT COUNT(*) FROM `evaluation_items`")->fetchColumn();
    if ($check_items == 0) {
        $items = [
            ['1', 'การวิเคราะห์หลักสูตรและมาตรฐานการเรียนรู้รายปี', 'หมวดที่ 1: การเตรียมการจัดการเรียนรู้'],
            ['2', 'การจัดทำแผนการจัดการเรียนรู้ที่เน้นผู้เรียนเป็นสำคัญ', 'หมวดที่ 1: การเตรียมการจัดการเรียนรู้'],
            ['3', 'การจัดหาสื่อ อุปกรณ์ และนวัตกรรมการเรียนรู้ที่สอดคล้อง', 'หมวดที่ 1: การเตรียมการจัดการเรียนรู้'],
            ['4', 'การจัดเตรียมเครื่องมือวัดและประเมินผลอย่างเป็นรูปธรรม', 'หมวดที่ 1: การเตรียมการจัดการเรียนรู้'],
            ['5', 'การจัดสภาพแวดล้อมทางกายภาพในชั้นเรียนเพื่อส่งเสริมความปลอดภัย', 'หมวดที่ 2: บรรยากาศและการจัดชั้นเรียน'],
            ['6', 'การจัดระบบกลุ่มเพื่อนสร้างสรรค์และการส่งพฤติกรรมเชิงบวก', 'หมวดที่ 2: บรรยากาศและการจัดชั้นเรียน'],
            ['7', 'การกระตุ้นและการดึงดูดความสนใจใส่ใจแก่ผู้เรียนระยะยาว', 'หมวดที่ 2: บรรยากาศและการจัดชั้นเรียน'],
            ['8', 'บุคลิกภายนอก ท่าทางสง่าผ่าเผยและการควบคุมน้ำเสียงของคุณครู', 'หมวดที่ 2: บรรยากาศและการจัดชั้นเรียน'],
            ['9', 'การกระตุ้นบทนำเข้าสู่เนื้อหาวิชาและสืบสวนต่อความรู้พื้นฐาน', 'หมวดที่ 3: กระบวนการจัดเรียนรู้เชิงรุก (Active Learning)'],
            ['10', 'การจัดกระบวนการทำงานแบบ Active Learning หรือการฝึกปฏิบัติ', 'หมวดที่ 3: กระบวนการจัดเรียนรู้เชิงรุก (Active Learning)'],
            ['11', 'กระบวนการส่งความรู้จากเพื่อนสู่เพื่อนหรือทำงานเป็นทีมสอดประสาน', 'หมวดที่ 3: กระบวนการจัดเรียนรู้เชิงรุก (Active Learning)'],
            ['12', 'การตั้งคำถามปลายเปิดเพื่อจุดประกายสร้างเหตุผลแก้ปัญหาซับซ้อน', 'หมวดที่ 3: กระบวนการจัดเรียนรู้เชิงรุก (Active Learning)'],
            ['13', 'การใช้เทคโนโลยีรอบด้าน เครื่องฉาย เว็บบล็อก หรือโปรแกรมประเมิน', 'หมวดที่ 4: การใช้สื่อและเครื่องมือวัดผลการศึกษา'],
            ['14', 'ออกแบบคู่มือสื่อประกอบกิจกรรมให้เข้าใจง่าย มีประสิทธิภาพ', 'หมวดที่ 4: การใช้สื่อและเครื่องมือวัดผลการศึกษา'],
            ['15', 'กลยุทธ์การตรวจวัดผลงานรายบุคคลในระหว่างดำเนินกิจกรรม', 'หมวดที่ 4: การใช้สื่อและเครื่องมือวัดผลการศึกษา'],
            ['16', 'การส่งเสริมความก้าวหน้าและการสะท้อนข้อมูลกลับทันถ่วงที (Feedback)', 'หมวดที่ 4: การใช้สื่อและเครื่องมือวัดผลการศึกษา'],
            ['17', 'ผลสัมฤทธิ์ปลายทางของบทเรียนที่ผู้เรียนบรรลุมิติเป้าหมาย', 'หมวดที่ 5: ผลลัพธ์และคุณลักษณะนิสัยผู้เรียน'],
            ['18', 'พฤติกรรมวินัย เจตคติส่วนบุคคลความพร้อมในการศึกษาต่อเนื่อง', 'หมวดที่ 5: ผลลัพธ์และคุณลักษณะนิสัยผู้เรียน'],
            ['19', 'ผลงาน แฟ้มสะสมงาน หรือภาระงานประจักษ์เสร็จสิ้นของเด็กนักเรียน', 'หมวดที่ 5: ผลลัพธ์และคุณลักษณะนิสัยผู้เรียน'],
            ['20', 'การประเมินวิเคราะห์คุณลักษณะตามพึงประสงค์ของกระทรวงศึกษา', 'หมวดที่ 5: ผลลัพธ์และคุณลักษณะนิสัยผู้เรียน']
        ];
        $stmt = $pdo->prepare("INSERT INTO `evaluation_items` (`item_id`, `item_name`, `category`) VALUES (?, ?, ?)");
        foreach ($items as $item) {
            $stmt->execute($item);
        }
    }

    // 3. ลงคุณครูตั้งต้นตัวอย่าง
    $check_teachers = $pdo->query("SELECT COUNT(*) FROM `teachers`")->fetchColumn();
    if ($check_teachers == 0) {
        $teachers = [
            ['T-001', 'นางสาวมาลี รักการเรียน', 'ครู คศ.1', 'กลุ่มสาระการเรียนรู้ภาษาไทย', '081-2345678', 'teacher_t1'],
            ['T-002', 'นายสมยศ สดใส', 'ครูชำนาญการ', 'กลุ่มสาระการเรียนรู้คณิตศาสตร์', '089-8765432', 'teacher_t2'],
            ['T-003', 'นางดรุณี ดวงดี', 'ครูชำนาญการพิเศษ', 'กลุ่มสาระการเรียนรู้วิทยาศาสตร์และเทคโนโลยี', '082-9988776', 'teacher_t3']
        ];
        $stmt = $pdo->prepare("INSERT INTO `teachers` (`teacher_id`, `teacher_name`, `position`, `subject_group`, `phone`, `username`) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($teachers as $t) {
            $stmt->execute($t);
        }
    }

    // 4. ลงปีการศึกษาตั้งต้นตัวอย่าง
    $check_years = $pdo->query("SELECT COUNT(*) FROM `academic_years`")->fetchColumn();
    if ($check_years == 0) {
        $years = [
            ['YR2569-1', '2569', '1'],
            ['YR2569-2', '2569', '2']
        ];
        $stmt = $pdo->prepare("INSERT INTO `academic_years` (`year_id`, `year`, `semester`) VALUES (?, ?, ?)");
        foreach ($years as $y) {
            $stmt->execute($y);
        }
    }

} catch (PDOException $e) {
    // กรณีการเชื่อมต่อล้มเหลว แสดงหน้าเว็บออกแบบมาเพื่อให้เจ้าหน้าที่ไอทีโรงเรียนบันทึกตั้งค่าได้ทันที
    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <title>ตั้งค่าระบบฐานข้อมูลโรงเรียน</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700;800&display=swap" rel="stylesheet">
        <style> body { font-family: 'Sarabun', sans-serif; } </style>
    </head>
    <body class="bg-[#FAF8F5] min-h-screen flex items-center justify-center p-4">
        <div class="bg-white border-2 border-slate-100 rounded-3xl shadow-xl p-8 w-full max-w-lg space-y-6">
            <div class="text-center space-y-2">
                <div class="text-4xl">⚙️</div>
                <h1 class="text-xl font-bold text-slate-850">เชื่อมต่อฐานข้อมูลล้มเหลว</h1>
                <p class="text-xs text-slate-500 leading-relaxed">
                    ไม่สามารถเชื่อมต่อไปยังโฮสต์ MySQL ได้ด้วยบัญชีที่ระบุในไฟล์ <code class="bg-[#F1F5F9] px-1 rounded font-mono font-bold text-rose-500">db_config.json</code><br>
                    กรุณาแก้ไขหรือกรอกข้อมูลด้านล่างเพื่อทำการอัพเดทและบันทึกลงระบบเซิร์ฟเวอร์โรงเรียนโดยอัตโนมัติ
                </p>
            </div>

            <div class="bg-red-50 border border-red-150 text-red-700 p-4 rounded-xl text-xs space-y-1">
                <p class="font-bold">❌ Error Message:</p>
                <div class="font-mono bg-black/5 p-2 rounded leading-relaxed break-words text-[11px]"><?php echo htmlspecialchars($e->getMessage()); ?></div>
            </div>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="action_save_db_config" value="1">
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-600">Database Host</label>
                        <input type="text" name="db_host" required value="<?php echo htmlspecialchars($db_host); ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-600">Database Name</label>
                        <input type="text" name="db_name" required value="<?php echo htmlspecialchars($db_name); ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-600">Username (MySQL)</label>
                        <input type="text" name="db_user" required value="<?php echo htmlspecialchars($db_user); ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-600">Password (MySQL)</label>
                        <input type="password" name="db_pass" value="<?php echo htmlspecialchars($db_pass); ?>" placeholder="รหัสผ่านเชื่อมต่อ" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                </div>

                <button type="submit" class="w-full py-2.5 bg-[#0A3370] hover:bg-[#082855] text-white font-bold rounded-xl text-xs shadow-sm transition">
                    💾 บันทึกและพยายามเชื่อมต่อใหม่ (Save Connection & Retry)
                </button>
            </form>

            <div class="text-[11px] text-slate-400 space-y-0.5 leading-relaxed bg-slate-50 p-3 rounded-xl border border-dashed border-slate-200">
                <span class="font-bold text-amber-600 block">💡 ข้อแนะนำสำหรับ IT โรงเรียน:</span>
                - สกุลฐานข้อมูลจะทำการสร้าง Database ชื่อ <code class="font-bold">school_supervision</code> ให้อัตโนมัติ<br>
                - หากใช้งานบนเครื่องส่วนบุคคลทั่วไป (XAMPP / WampServer) ให้ใช้ Host: <code class="font-semibold">localhost</code>, User: <code class="font-semibold">root</code> และ Password ว่างเปล่า
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// เริ่มต้น Session สำหรับใช้งานบัญชีลงทะเบียนทั่วไป
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
