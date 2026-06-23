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
    
    // ตารางโรงเรียนที่ลงทะเบียนใช้งานระบบ
    $pdo->exec("CREATE TABLE IF NOT EXISTS `schools` (
        `school_code` VARCHAR(8) NOT NULL,
        `school_name` VARCHAR(255) NOT NULL,
        `affiliation` VARCHAR(255) NOT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `gdrive_app_url` VARCHAR(255) DEFAULT NULL,
        `gdrive_folder_id` VARCHAR(255) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`school_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // เสียบข้อมูลโรงเรียนเริ่มต้นตั้งต้น (โรงเรียนบ้านหนองหว้า) อนุมัติใช้งานทันที
    $check_default_school = $pdo->query("SELECT COUNT(*) FROM `schools` WHERE `school_code` = '31054002'")->fetchColumn();
    if ($check_default_school == 0) {
        $pdo->exec("INSERT INTO `schools` (`school_code`, `school_name`, `affiliation`, `status`) VALUES 
            ('31054002', 'โรงเรียนบ้านหนองหว้า', 'สำนักงานเขตพื้นที่การศึกษาประถมศึกษาบุรีรัมย์ เขต 3', 'approved')");
    }

    // เพิ่มคอลัมน์ school_code เพื่อแยกข้อมูลโรงเรียนแยกจากกันอย่างชัดเจนตามกฎ 8 หลัก SMISS
    $tables_to_alter = [
        'teachers' => "ALTER TABLE `teachers` ADD COLUMN `school_code` VARCHAR(8) NOT NULL DEFAULT '31054002'",
        'academic_years' => "ALTER TABLE `academic_years` ADD COLUMN `school_code` VARCHAR(8) NOT NULL DEFAULT '31054002'",
        'supervisions' => "ALTER TABLE `supervisions` ADD COLUMN `school_code` VARCHAR(8) NOT NULL DEFAULT '31054002'",
        'classrooms' => "ALTER TABLE `classrooms` ADD COLUMN `school_code` VARCHAR(8) NOT NULL DEFAULT '31054002'",
        'users' => "ALTER TABLE `users` ADD COLUMN `school_code` VARCHAR(8) DEFAULT '31054002'",
        'school_settings' => "ALTER TABLE `school_settings` ADD COLUMN `school_code` VARCHAR(8) NOT NULL DEFAULT '31054002'"
    ];

    foreach ($tables_to_alter as $table => $alter_sql) {
        try {
            $check_col = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'school_code'")->fetch();
            if (!$check_col) {
                $pdo->exec($alter_sql);
            }
        } catch (Exception $e) {
            // ดำเนินการผ่านอย่างปลอดภัยกรณีคอลัมน์มีอยู่แล้ว
        }
    }

    // ปรับให้ตาราง school_settings มี composite key (school_code, setting_key)
    try {
        $pdo->exec("ALTER TABLE `school_settings` DROP PRIMARY KEY, ADD PRIMARY KEY (`school_code`, `setting_key`);");
    } catch (Exception $e) {
        // ดำเนินการผ่านอย่างปลอดภัยกรณีระบุ composite key สำเร็จอยู่แล้ว
    }

    // ตารางคุณครูผู้สอน
    $pdo->exec("CREATE TABLE IF NOT EXISTS `teachers` (
        `teacher_id` VARCHAR(50) NOT NULL,
        `teacher_name` VARCHAR(150) NOT NULL,
        `position` VARCHAR(100) NOT NULL,
        `subject_group` VARCHAR(150) NOT NULL,
        `phone` VARCHAR(20) DEFAULT NULL,
        `username` VARCHAR(50) DEFAULT NULL,
        `photo_path` VARCHAR(255) DEFAULT NULL,
        `school_code` VARCHAR(8) NOT NULL DEFAULT '31054002',
        PRIMARY KEY (`teacher_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // ตรวจสอบโครงสร้างตาราง teachers เผื่อตารางสร้างไปก่อนแล้วเพื่อเติมคอลัมน์คลังรูปภาพครู
    try {
        $check_col = $pdo->query("SHOW COLUMNS FROM `teachers` LIKE 'photo_path'")->fetch();
        if (!$check_col) {
            $pdo->exec("ALTER TABLE `teachers` ADD COLUMN `photo_path` VARCHAR(255) DEFAULT NULL;");
        }
    } catch (Exception $col_err) {}

    // คอลัมน์เพิ่มเติมสำหรับการแสดงผลหน้าเริ่มประเมินนิเทศ
    $cols_to_check = [
        'classroom' => "VARCHAR(100) DEFAULT 'ชั้นประถมศึกษาปีที่ 1/1'",
        'teaching_hours' => "INT DEFAULT 8",
        'work_status' => "VARCHAR(50) DEFAULT 'ปกติ'"
    ];
    foreach ($cols_to_check as $col_name => $col_definition) {
        try {
            $check_col = $pdo->query("SHOW COLUMNS FROM `teachers` LIKE '{$col_name}'")->fetch();
            if (!$check_col) {
                $pdo->exec("ALTER TABLE `teachers` ADD COLUMN `{$col_name}` {$col_definition};");
            }
        } catch (Exception $col_err) {}
    }

    // สร้างโฟลเดอร์จัดเก็บภาพคุณครูและภาพนิเทศเพื่อความพร้อมในการทำงานจริง
    if (!is_dir(__DIR__ . '/uploads')) {
        mkdir(__DIR__ . '/uploads', 0755, true);
    }

    // ตารางปีการศึกษา
    $pdo->exec("CREATE TABLE IF NOT EXISTS `academic_years` (
        `year_id` VARCHAR(50) NOT NULL,
        `year` VARCHAR(10) NOT NULL,
        `semester` VARCHAR(5) NOT NULL,
        `school_code` VARCHAR(8) NOT NULL DEFAULT '31054002',
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
        `photos_json` MEDIUMTEXT DEFAULT NULL,
        `evaluator_name` VARCHAR(150) NOT NULL,
        `evaluator_position` VARCHAR(100) NOT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
        `school_code` VARCHAR(8) NOT NULL DEFAULT '31054002',
        PRIMARY KEY (`record_id`),
        KEY `teacher_id` (`teacher_id`),
        KEY `year_id` (`year_id`),
        CONSTRAINT `fk_supervision_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_supervision_year` FOREIGN KEY (`year_id`) REFERENCES `academic_years` (`year_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // ดำเนินการอัปเดตชนิดคอลัมน์ photos_json ของระบบเดิม (หากมีอยู่แล้ว) ให้เป็น MEDIUMTEXT เพื่อรองรับภาพความละเอียดเซ็นเซอร์มือถือ
    try {
        $pdo->exec("ALTER TABLE `supervisions` MODIFY COLUMN `photos_json` MEDIUMTEXT DEFAULT NULL;");
    } catch (Exception $alter_err) {
        // เงียบไว้หากดำเนินการเสร็จสิ้นแล้ว หรือฐานข้อมูลไม่รองรับคำสั่ง ALTER
    }

    // ตารางบัญชีผู้ใช้ระบบ
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `username` VARCHAR(50) NOT NULL,
        `password` VARCHAR(255) NOT NULL,
        `fullname` VARCHAR(150) NOT NULL,
        `role` VARCHAR(20) NOT NULL DEFAULT 'teacher',
        `school_code` VARCHAR(8) DEFAULT '31054002',
        PRIMARY KEY (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // ตารางระดับชั้นสำหรับสร้างตัวเลือกมาตรฐาน
    $pdo->exec("CREATE TABLE IF NOT EXISTS `classrooms` (
        `class_id` INT AUTO_INCREMENT,
        `class_name` VARCHAR(100) NOT NULL,
        `school_code` VARCHAR(8) NOT NULL DEFAULT '31054002',
        PRIMARY KEY (`class_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // ถอดคีย์ UNIQUE เดิมที่เป็นปัญหาในการแยกโรงเรียนออก และรองรับการทำ composite key แทน
    try {
        $pdo->exec("ALTER TABLE `classrooms` DROP INDEX `class_name`;");
    } catch (Exception $e) {
        // ข้ามหากไม่มีอินเด็กซ์เดี่ยว
    }
    try {
        $pdo->exec("ALTER TABLE `classrooms` ADD UNIQUE KEY `uq_class_school` (`class_name`, `school_code`);");
    } catch (Exception $e) {
        // ข้ามกรณีมีอยู่แล้ว
    }

    // ตารางเก็บการตั้งค่าโรงเรียน เช่น โลโก้
    $pdo->exec("CREATE TABLE IF NOT EXISTS `school_settings` (
        `school_code` VARCHAR(8) NOT NULL DEFAULT '31054002',
        `setting_key` VARCHAR(100) NOT NULL,
        `setting_value` LONGTEXT DEFAULT NULL,
        PRIMARY KEY (`school_code`, `setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // ==========================================
    // ตรวจสอบความถูกต้องและลงข้อมูลเริ่มต้นอัตโนมัติ (Automated Database Seeding)
    // ==========================================
    
    // 1. ลงผู้ใช้แอดมินและผู้อำนวยการ
    // เปลี่ยนมาตรวจสอบด้วย username โดยตรงแทนการเช็ก school_code ตั้งต้น เพื่อป้องกันข้อผิดพลาด Duplicate entry 'admin' / 'director' for key 'PRIMARY' หลังโรงงานเปลี่ยนรหัสโรงเรียน
    $check_admin_exists = $pdo->query("SELECT COUNT(*) FROM `users` WHERE `username` = 'admin'")->fetchColumn();
    if ($check_admin_exists == 0) {
        $admin_pwd = password_hash('123456', PASSWORD_DEFAULT);
        $stmt_admin = $pdo->prepare("INSERT INTO `users` (`username`, `password`, `fullname`, `role`, `school_code`) VALUES (?, ?, ?, ?, ?)");
        $stmt_admin->execute(['admin', $admin_pwd, 'ผู้ดูแลระบบคอมพิวเตอร์โรงเรียน', 'admin', '31054002']);
    }

    $check_director_exists = $pdo->query("SELECT COUNT(*) FROM `users` WHERE `username` = 'director'")->fetchColumn();
    if ($check_director_exists == 0) {
        $director_pwd = password_hash('123456', PASSWORD_DEFAULT);
        $stmt_director = $pdo->prepare("INSERT INTO `users` (`username`, `password`, `fullname`, `role`, `school_code`) VALUES (?, ?, ?, ?, ?)");
        $stmt_director->execute(['director', $director_pwd, 'ดร. นิพัทธ์ สรรพวิเชียร', 'director', '31054002']);
    }

    // สร้างบัญชี Super Admin สำหรับจัดการระบบเปิดปิดโรงเรียน
    $check_super = $pdo->query("SELECT COUNT(*) FROM `users` WHERE `username` = 'superadmin'")->fetchColumn();
    if ($check_super == 0) {
        $super_pwd = password_hash('123456', PASSWORD_DEFAULT);
        $stmt_super = $pdo->prepare("INSERT INTO `users` (`username`, `password`, `fullname`, `role`, `school_code`) VALUES (?, ?, ?, ?, NULL)");
        $stmt_super->execute(['superadmin', $super_pwd, 'ผู้ดูแลระบบระบบสูงสุด (Super Admin)', 'super_admin']);
    }

    // 2. ลงเกณฑ์การประเมิน 20 ข้อ (อัปเดตสตรีมตามคำขอของผู้ใช้เพื่อย้ายเข้าสถานะจริง 4 หมวดหลัก)
    $first_item_name = $pdo->query("SELECT item_name FROM `evaluation_items` WHERE `item_id` = '1'")->fetchColumn();
    if ($first_item_name !== 'มีป้ายนิเทศเพื่อเผยแพร่ข่าวสารและความรู้ต่าง ๆ') {
        $pdo->exec("DELETE FROM `evaluation_items` WHERE 1");
        $items = [
            // 1. สภาพห้องเรียน
            ['1', 'มีป้ายนิเทศเพื่อเผยแพร่ข่าวสารและความรู้ต่าง ๆ', '1. สภาพห้องเรียน'],
            ['2', 'มีป้ายแสดงข้อมูลสถิติของห้องเรียนที่เป็นปัจจุบัน', '1. สภาพห้องเรียน'],
            ['3', 'มีสัญลักษณ์ชาติ ศาสนา พระมหากษัตริย์', '1. สภาพห้องเรียน'],
            ['4', 'มีการแสดงผลงานนักเรียน', '1. สภาพห้องเรียน'],
            ['5', 'บรรยากาศในห้องเรียนเอื้อต่อการเรียนรู้', '1. สภาพห้องเรียน'],
            
            // 2. การบริหารจัดการห้องเรียน
            ['6', 'ใช้การเสริมแรงเชิงบวกในการจัดการเรียนรู้ (Positive Reinforcement)', '2. การบริหารจัดการห้องเรียน'],
            ['7', 'ใช้วิธีการทำงานเป็นกลุ่ม (Working in Groups)', '2. การบริหารจัดการห้องเรียน'],
            ['8', 'นักเรียนทุกคนมีส่วนร่วมในการจัดการเรียนรู้ (Involve Everyone)', '2. การบริหารจัดการห้องเรียน'],
            
            // 3. ครูผู้สอน
            ['9', 'มีการจัดทำแผนการจัดการเรียนรู้', '3. ครูผู้สอน'],
            ['10', 'จัดกิจกรรมการเรียนรู้เน้นผู้เรียนเป็นสำคัญ', '3. ครูผู้สอน'],
            ['11', 'ใช้สื่อเทคโนโลยีในการจัดการเรียนรู้', '3. ครูผู้สอน'],
            ['12', 'มีข้อมูลนักเรียนเป็นรายบุคคล', '3. ครูผู้สอน'],
            ['13', 'มีวิจัยในชั้นเรียนเพื่อการพัฒนาการเรียนรู้', '3. ครูผู้สอน'],
            ['14', 'ดูแลเอาใจใส่นักเรียนอย่างทั่วถึง', '3. ครูผู้สอน'],
            ['15', 'แต่งกายเหมาะสมกับความเป็นครู', '3. ครูผู้สอน'],
            
            // 4. นักเรียน
            ['16', 'ตั้งใจปฏิบัติกิจกรรมการเรียนที่ได้รับมอบหมาย', '4. นักเรียน'],
            ['17', 'นักเรียนบรรลุจุดมุ่งหมาย', '4. นักเรียน'],
            ['18', 'นักเรียนกระตือรือร้นและกล้าซักถามครู', '4. นักเรียน'],
            ['19', 'นักเรียนมีระเบียบวินัย', '4. นักเรียน'],
            ['20', 'นักเรียนแต่งกายสะอาดถูกต้องตามระเบียบ', '4. นักเรียน']
        ];
        $stmt = $pdo->prepare("INSERT INTO `evaluation_items` (`item_id`, `item_name`, `category`) VALUES (?, ?, ?)");
        foreach ($items as $item) {
            $stmt->execute($item);
        }
    }

    // 3. ลงคุณครูตั้งต้นตัวอย่าง (รวมถึง ปีการศึกษา และชั้นเรียน จะยอมให้ทำงานเมื่อยังไม่มีการทำเครื่องหมายระบบลงข้อมูลเริ่มต้นแล้วเท่านั้น ป้องกันการคืนค่าเมื่อผู้ใช้ทำการลบออกเพื่อตั้งค่าจริง)
    $system_init_seeded = $pdo->query("SELECT COUNT(*) FROM `school_settings` WHERE `setting_key` = 'system_init_seeded'")->fetchColumn();
    
    if ($system_init_seeded == 0) {
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

        // 5. ลงระดับชั้นเรียนตั้งต้นตัวอย่างเพื่อความสะดวก
        $check_classes = $pdo->query("SELECT COUNT(*) FROM `classrooms`")->fetchColumn();
        if ($check_classes == 0) {
            $default_classes = [
                'ประถมศึกษาปีที่ 1', 'ประถมศึกษาปีที่ 2', 'ประถมศึกษาปีที่ 3',
                'ประถมศึกษาปีที่ 4', 'ประถมศึกษาปีที่ 5', 'ประถมศึกษาปีที่ 6',
                'มัธยมศึกษาปีที่ 1', 'มัธยมศึกษาปีที่ 2', 'มัธยมศึกษาปีที่ 3',
                'มัธยมศึกษาปีที่ 4', 'มัธยมศึกษาปีที่ 5', 'มัธยมศึกษาปีที่ 6'
            ];
            $stmt_class = $pdo->prepare("INSERT IGNORE INTO `classrooms` (`class_name`) VALUES (?)");
            foreach ($default_classes as $cls) {
                $stmt_class->execute([$cls]);
            }
        }

        // ลงบันทึกค่าสถานะตั้งต้นในตารางตั้งค่าเพื่อล็อกไม่ให้กลับมารีเซ็ตอีก
        $pdo->exec("INSERT INTO `school_settings` (`setting_key`, `setting_value`) VALUES ('system_init_seeded', '1')");
    }

    // 6. ลงตั้งค่าระดับบริการตั้งต้นตัวอย่าง
    $check_logo_setting = $pdo->query("SELECT COUNT(*) FROM `school_settings` WHERE `setting_key` = 'school_logo'")->fetchColumn();
    if ($check_logo_setting == 0) {
        $pdo->exec("INSERT INTO `school_settings` (`setting_key`, `setting_value`) VALUES ('school_logo', '')");
    }
    $check_name_setting = $pdo->query("SELECT COUNT(*) FROM `school_settings` WHERE `setting_key` = 'school_name'")->fetchColumn();
    if ($check_name_setting == 0) {
        $pdo->exec("INSERT INTO `school_settings` (`setting_key`, `setting_value`) VALUES ('school_name', 'ระบบนิเทศการจัดการเรียนการสอนโรงเรียนบ้านหนองหว้า อำเภอหนองกี่ จังหวัดบุรีรัมย์')");
    } else {
        // อัปเดตสถานประกอบการให้สอดรับตามความประสงค์ล่าสุด
        $stmt_up_sname = $pdo->prepare("UPDATE `school_settings` SET `setting_value` = ? WHERE `setting_key` = 'school_name' AND `setting_value` = 'ระบบนิเทศการจัดการเรียนการสอนระดับโรงเรียน'");
        $stmt_up_sname->execute(['ระบบนิเทศการจัดการเรียนการสอนโรงเรียนบ้านหนองหว้า อำเภอหนองกี่ จังหวัดบุรีรัมย์']);
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

// ฟังก์ชันอำนวยความสะดวกในการจัดอัปโหลดรูปภาพไปยัง Google Drive ผ่านโค้ด GAS Web App
function upload_image_to_gdrive_if_configured($image_data, $filename, $school_code, $pdo) {
    if (empty($image_data)) {
        return null;
    }
    
    // หากข้อมูลภาพเป็น URL เว็บอยู่แล้ว ให้ส่งกลับทันทีโดยไม่ต้องประมวลผลเพิ่ม
    if (strpos($image_data, 'http://') === 0 || strpos($image_data, 'https://') === 0) {
        return $image_data;
    }
    
    // โหลดการตั้งค่าการเชื่อมต่อ Google Drive ของแต่ละโรงเรียน
    $stmt = $pdo->prepare("SELECT gdrive_app_url, gdrive_folder_id FROM schools WHERE school_code = ?");
    $stmt->execute([$school_code]);
    $gdrive = $stmt->fetch();
    
    // กาหาไม่พบ หรือยังไม่ได้เซ็ตอัป Google Drive ให้บันทึกโหมด fallback เก็บใส่เครื่อง /uploads
    if (!$gdrive || empty($gdrive['gdrive_app_url']) || empty($gdrive['gdrive_folder_id'])) {
        if (strpos($image_data, 'data:image/') === 0) {
            $parts = explode(',', $image_data);
            $meta = $parts[0];
            $payload = $parts[1] ?? '';
            
            $ext = 'jpg';
            if (strpos($meta, 'image/png') !== false) {
                $ext = 'png';
            } elseif (strpos($meta, 'image/gif') !== false) {
                $ext = 'gif';
            }
            
            $file_content = base64_decode($payload);
            $new_file_name = 'local_' . uniqid() . '_' . time() . '.' . $ext;
            $dest_path = 'uploads/' . $new_file_name;
            if (file_put_contents(__DIR__ . '/' . $dest_path, $file_content)) {
                return $dest_path;
            }
        }
        return $image_data; // ส่งค่าเดิมกลับหากไม่ใช่ base64 string
    }
    
    // สกัดข้อมูล Base64 และ Mime Type เพื่อส่งเข้า Google Apps Script
    $base64_payload = '';
    $mime_type = 'image/jpeg';
    
    if (strpos($image_data, 'data:image/') === 0) {
        $parts = explode(',', $image_data);
        $meta = $parts[0];
        $base64_payload = $parts[1] ?? '';
        
        if (preg_match('/data:([^;]+);base64/', $meta, $matches)) {
            $mime_type = $matches[1];
        }
    } else {
        // หากเป็นตำแหน่งไฟล์บนเครื่อง (เช่น รูปภาพครูที่ถูกย้ายจาก $_FILES)
        if (file_exists(__DIR__ . '/' . $image_data)) {
            $base64_payload = base64_encode(file_get_contents(__DIR__ . '/' . $image_data));
            $mime_type = mime_content_type(__DIR__ . '/' . $image_data) ?: 'image/jpeg';
        } elseif (file_exists($image_data)) {
            $base64_payload = base64_encode(file_get_contents($image_data));
            $mime_type = mime_content_type($image_data) ?: 'image/jpeg';
        } else {
            return $image_data; // ส่งค่าคืนหากไม่พบไฟล์จริง
        }
    }
    
    // แปลงชุดข้อมูลแบบ JSON ส่งตรงไปยัง Google Apps Script Web App
    $post_data = json_encode([
        'folder_id' => $gdrive['gdrive_folder_id'],
        'filename' => $filename,
        'filedata' => $base64_payload,
        'mime_type' => $mime_type
    ]);
    
    $ch = curl_init($gdrive['gdrive_app_url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && !empty($response)) {
        $result = json_decode($response, true);
        if ($result && !empty($result['success']) && !empty($result['url'])) {
            return $result['url']; // คืนเฉพาะ URL แสดงผลตรงบนเซิร์ฟเวอร์กูเกิลไดรฟ์
        }
    }
    
    // กรณี GAS ขัดข้อง ให้ทำการบันทึกประวัติใส่เครื่อง /uploads ชั่วคราวป้องกันงานสูญหาย
    if (strpos($image_data, 'data:image/') === 0) {
        $parts = explode(',', $image_data);
        $payload = $parts[1] ?? '';
        $file_content = base64_decode($payload);
        $new_file_name = 'gas_fallback_' . uniqid() . '.' . (strpos($mime_type, 'png') !== false ? 'png' : 'jpg');
        $dest_path = 'uploads/' . $new_file_name;
        file_put_contents(__DIR__ . '/' . $dest_path, $file_content);
        return $dest_path;
    }
    
    return $image_data;
}

// เริ่มต้น Session สำหรับใช้งานบัญชีลงทะเบียนทั่วไป
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
