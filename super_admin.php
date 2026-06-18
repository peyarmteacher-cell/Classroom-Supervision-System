<?php
// ==========================================
// super_admin.php - ระบบดูแลและบริการโรงเรียนกลุ่มภายนอก (Super Admin Control Panel)
// ==========================================

require_once 'config.php';

// ตรวจวัดความปลอดภัยสิทธิ์ Super Admin
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: dashboard.php");
    exit;
}

$success_msg = '';
$error_msg = '';

// การอัปเดตสถานะโรงเรียนและการจัดลงทะเบียนตั้งต้น (Bootstrap school)
if (isset($_GET['approve_code'])) {
    $approve_code = trim($_GET['approve_code']);
    
    // โหลดรายละเอียดโรงเรียน
    $stmt = $pdo->prepare("SELECT * FROM schools WHERE school_code = ?");
    $stmt->execute([$approve_code]);
    $school = $stmt->fetch();
    
    if ($school) {
        $pdo->beginTransaction();
        try {
            // อัปเดตสถานะในตารางโรงเรียน
            $stmt_up = $pdo->prepare("UPDATE schools SET status = 'approved' WHERE school_code = ?");
            $stmt_up->execute([$approve_code]);
            
            // ตรวจสอบเช็คว่ามีการจัดลงตั้งชื่อตารางตั้งค่าไว้หรือยัง
            $check_settings = $pdo->prepare("SELECT COUNT(*) FROM school_settings WHERE school_code = ?");
            $check_settings->execute([$approve_code]);
            if ($check_settings->fetchColumn() == 0) {
                // ลงข้อมูลตั้งค่าเริ่มต้นสำหรับโรงเรียนใหม่นี้
                $stmt_sett = $pdo->prepare("INSERT INTO school_settings (school_code, setting_key, setting_value) VALUES (?, ?, ?)");
                $stmt_sett->execute([$approve_code, 'school_logo', '']);
                $stmt_sett->execute([$approve_code, 'school_name', "ระบบนิเทศการจัดการเรียนการสอน" . htmlspecialchars($school['school_name']) . " " . htmlspecialchars($school['affiliation'])]);
            }
            
            // ลงระดับชั้นเรียนตั้งต้นตามมาตรฐานไทย
            $check_classes = $pdo->prepare("SELECT COUNT(*) FROM classrooms WHERE school_code = ?");
            $check_classes->execute([$approve_code]);
            if ($check_classes->fetchColumn() == 0) {
                $default_classes = [
                    'ประถมศึกษาปีที่ 1', 'ประถมศึกษาปีที่ 2', 'ประถมศึกษาปีที่ 3',
                    'ประถมศึกษาปีที่ 4', 'ประถมศึกษาปีที่ 5', 'ประถมศึกษาปีที่ 6',
                    'มัธยมศึกษาปีที่ 1', 'มัธยมศึกษาปีที่ 2', 'มัธยมศึกษาปีที่ 3',
                    'มัธยมศึกษาปีที่ 4', 'มัธยมศึกษาปีที่ 5', 'มัธยมศึกษาปีที่ 6'
                ];
                $stmt_class = $pdo->prepare("INSERT INTO classrooms (class_name, school_code) VALUES (?, ?)");
                foreach ($default_classes as $cls) {
                    $stmt_class->execute([$cls, $approve_code]);
                }
            }
            
            // ลงปีการศึกษาตั้งต้นเพื่อไม่ให้แดชบอร์ดล้มคำนวณ
            $check_years = $pdo->prepare("SELECT COUNT(*) FROM academic_years WHERE school_code = ?");
            $check_years->execute([$approve_code]);
            if ($check_years->fetchColumn() == 0) {
                $current_year = date('Y') + 543;
                $new_year_id = "YR{$current_year}-1";
                $stmt_year = $pdo->prepare("INSERT INTO academic_years (year_id, year, semester, school_code) VALUES (?, ?, '1', ?)");
                $stmt_year->execute([$new_year_id, $current_year, $approve_code]);
            }

            $pdo->commit();
            $success_msg = "อนุมัติเปิดสิทธิ์งานใช้งานโรงเรียน '{$school['school_name']}' และลงรหัสข้อมูลมาตรฐานเริ่มต้นพร้อมทำงานแล้วเรียบร้อย";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = "ผิดพลาดในระบบอนุมัติบูทสแตรป: " . $e->getMessage();
        }
    } else {
        $error_msg = "ไม่พบโรงเรียนในสารบบบันทึกประวัติ";
    }
}

// การระงับสิทธิ์การใช้งาน
if (isset($_GET['deactivate_code'])) {
    $deactivate_code = trim($_GET['deactivate_code']);
    $stmt = $pdo->prepare("UPDATE schools SET status = 'deactivated' WHERE school_code = ?");
    $stmt->execute([$deactivate_code]);
    $success_msg = "ระงับสิทธิ์เข้าใช้งานระบบตามรหัส SMISS {$deactivate_code} เรียบร้อยแล้ว";
}

// การตั้งค่า/แก้ไขพาธ Google Drive และพารามิเตอร์ Apps Script ของแต่ละโรงเรียน
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_gdrive'])) {
    $edit_school_code = trim($_POST['edit_school_code'] ?? '');
    $gdrive_app_url = trim($_POST['gdrive_app_url'] ?? '');
    $gdrive_folder_id = trim($_POST['gdrive_folder_id'] ?? '');
    
    if (!empty($edit_school_code)) {
        $stmt_g = $pdo->prepare("UPDATE schools SET gdrive_app_url = ?, gdrive_folder_id = ? WHERE school_code = ?");
        $stmt_g->execute([$gdrive_app_url, $gdrive_folder_id, $edit_school_code]);
        $success_msg = "ปรับปรุงข้อมูลจุดเชื่อมต่อ Google Drive สำหรับโรงเรียนรหัส {$edit_school_code} เรียบร้อยแล้ว";
    } else {
        $error_msg = "กรุณาระบุกรอกข้อมูลโรงเรียนให้ถูกต้อง";
    }
}

// โหลดรายชื่อโรงเรียนทั้งหมดในฐานระบบ
$all_schools = $pdo->query("SELECT * FROM schools ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ส่วนควบคุมส่วนระบบสูงสุด - Super Admin Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Sarabun', 'Inter', sans-serif; } </style>
</head>
<body class="bg-[#FAF8F5] min-h-screen text-slate-900 duration-200">

    <!-- Navbar Super Admin Portal -->
    <header class="bg-[#0f172a] text-white border-b-4 border-amber-500 shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-4 flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="flex items-center gap-3">
                <span class="text-3xl">🛡️</span>
                <div>
                    <h1 class="text-base sm:text-lg font-extrabold tracking-wide text-white leading-snug">
                        หน้าต่างควบคุมและพิจารณาอนุมัติระบบเครือข่ายโรงเรียน (Super Admin Portal)
                    </h1>
                    <p class="text-[10px] text-amber-400 font-bold block uppercase tracking-wider">
                        Consolidated Client-Tenant Network Administration Dashboard
                    </p>
                </div>
            </div>

            <!-- Profile & Portal buttons -->
            <div class="flex items-center gap-3 text-xs bg-slate-800 p-2 px-4 rounded-xl border border-white/10">
                <div class="text-right">
                    <span class="font-bold text-amber-200 block text-[11px]">ADMINISTRATOR</span>
                    <span class="text-[9px] text-slate-300">สิทธิ์: SUPER ADMIN</span>
                </div>
                <div>
                    <a href="logout.php" class="bg-red-650 hover:bg-red-700 text-white font-bold p-1 px-2.5 rounded text-[10px] text-center transition shadow block">ออกจากระบบ</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Workspace -->
    <main class="max-w-7xl mx-auto px-4 py-8 space-y-6">

        <!-- Top Overview Alerts -->
        <?php if ($success_msg): ?>
            <div class="text-xs bg-emerald-50 border border-emerald-250 text-emerald-800 p-4 rounded-2xl font-bold">
                🎉 สำเร็จ: <?php echo htmlspecialchars($success_msg); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="text-xs bg-rose-50 border border-rose-250 text-rose-800 p-4 rounded-2xl font-bold">
                ⚠️ พลาด: <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

            <!-- Section 1: Schools Management Table (lg:col-span-8) -->
            <div class="lg:col-span-8 bg-white border border-slate-200 rounded-3xl shadow-sm p-6 space-y-4">
                <div class="border-b pb-3 flex justify-between items-center flex-wrap gap-2">
                    <div>
                        <h2 class="font-extrabold text-[#0A3370] text-sm">สารบบโรงเรียนเครือข่ายพันธมิตรร่วมระบบ</h2>
                        <p class="text-[10px] text-slate-400 mt-0.5">พิจารณากลุ่มสิทธิ์ความปลอดภัย การสมัครงานเข้าใช้ และตั้งค่าที่เก็บไฟล์ของแต่ละโรงเรียน</p>
                    </div>
                    <span class="px-2.5 py-0.5 bg-[#0f172a] text-amber-400 text-[10px] font-bold rounded-full">
                        ลงทะเบียนแล้ว <?php echo count($all_schools); ?> โรงเรียน
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-slate-50 text-slate-650 font-extrabold text-[10.5px] uppercase">
                            <tr>
                                <th class="p-3 border-b border-slate-100">รหัส SMISS</th>
                                <th class="p-3 border-b border-slate-100">ชื่อโรงเรียน / สังกัดผู้บริการ</th>
                                <th class="p-3 border-b border-slate-100 text-center">สถานะ</th>
                                <th class="p-3 border-b border-slate-100 text-center">ปฏิบัติการพิกัดสิทธิ์</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-xs text-slate-700">
                            <?php if (empty($all_schools)): ?>
                                <tr>
                                    <td colspan="4" class="p-8 text-center text-slate-500">📭 ไม่มีประวัติโรงเรียนอื่นเข้ามาขอสมัครขณะนี้</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($all_schools as $sch): ?>
                                    <tr class="hover:bg-slate-50/70 transition">
                                        <!-- SMISS Code -->
                                        <td class="p-3 font-mono font-bold text-[#0A3370]">
                                            <?php echo htmlspecialchars($sch['school_code']); ?>
                                        </td>
                                        <!-- School context -->
                                        <td class="p-3 font-medium">
                                            <span class="font-bold text-slate-900 block"><?php echo htmlspecialchars($sch['school_name']); ?></span>
                                            <span class="text-[9.5px] text-slate-500 block mt-0.5"><?php echo htmlspecialchars($sch['affiliation']); ?></span>
                                            
                                            <!-- Configured Drive info badge -->
                                            <?php if (!empty($sch['gdrive_app_url']) && !empty($sch['gdrive_folder_id'])): ?>
                                                <div class="mt-1 flex items-center gap-1">
                                                    <span class="px-1.5 py-0.2 bg-indigo-50 text-indigo-700 border border-indigo-150 rounded text-[8.5px] font-bold">☁️ เชื่อมต่อ Google Drive สำเร็จ</span>
                                                </div>
                                            <?php else: ?>
                                                <div class="mt-1 flex items-center gap-1">
                                                    <span class="px-1.5 py-0.2 bg-slate-50 text-slate-400 border border-slate-150 rounded text-[8.5px]">💾 บันทึกรูปภาพในเซิร์ฟเวอร์หลัก (Local)</span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <!-- Status Badge -->
                                        <td class="p-3 text-center">
                                            <?php if ($sch['status'] === 'approved'): ?>
                                                <span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded font-bold text-[10px]">🟢 อนุมัติใช้งาน</span>
                                            <?php elseif ($sch['status'] === 'deactivated'): ?>
                                                <span class="px-2 py-0.5 bg-rose-100 text-rose-800 rounded font-bold text-[10px]">🔴 ระงับใช้งาน</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 bg-amber-100 text-amber-800 rounded font-bold text-[10px] animate-pulse">🟡 รอพิจารณาอนุมัติ</span>
                                            <?php endif; ?>
                                        </td>
                                        <!-- Operations actions -->
                                        <td class="p-3 text-center">
                                            <div class="flex flex-col gap-1 items-center justify-center">
                                                <div class="flex gap-1">
                                                    <?php if ($sch['status'] !== 'approved'): ?>
                                                        <a href="super_admin.php?approve_code=<?php echo urlencode($sch['school_code']); ?>" class="p-1 px-2.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-250 rounded font-bold text-[10px]" title="อนุมัติการใช้งาน">
                                                            ✅ อนุมัติ
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="super_admin.php?deactivate_code=<?php echo urlencode($sch['school_code']); ?>" onclick="return confirm('ยืนยันประสงค์ต้องการระงับสิทธิ์การใช้งานของโรงเรียนนี้ชั่วคราว? ผู้ใช้งานทั้งหมดสังกัดโรงเรียนจะไม่สามารถเข้าสู่ระบบประเมินได้')" class="p-1 px-2.5 bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-250 rounded font-bold text-[10px]" title="ระงับความสิทธิ์">
                                                            🚫 ระงับใช้งาน
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Javascript toggle formula to display Drive input modal/panel below -->
                                                    <button type="button" onclick="toggleDriveForm('<?php echo htmlspecialchars($sch['school_code']); ?>', '<?php echo htmlspecialchars($sch['gdrive_app_url']); ?>', '<?php echo htmlspecialchars($sch['gdrive_folder_id']); ?>')" class="p-1 px-2 bg-slate-50 hover:bg-indigo-50 text-indigo-700 border border-slate-200 rounded font-bold text-[10px]" title="ตั้งค่าคลังภาพ Google Drive">
                                                        ⚙️ ตั้งค่าคลังภาพ
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Hidden / Dynamic Drive Configuration Card -->
                <div id="drive_config_card" class="hidden bg-slate-50 border border-slate-200 rounded-2xl p-4 mt-4 space-y-3">
                    <h3 class="font-extrabold text-[#0A3370] text-xs">ตั้งค่าคลังจัดเก็บ Google Drive รายโรงเรียน <span id="drive_target_school" class="font-mono text-amber-600 bg-amber-50 px-1.5 py-0.5 rounded ml-1"></span></h3>
                    <form method="POST" class="grid grid-cols-1 md:grid-cols-12 gap-3 text-xs">
                        <input type="hidden" name="action_save_gdrive" value="1">
                        <input type="hidden" name="edit_school_code" id="drive_input_school_code" value="">
                        
                        <div class="md:col-span-5 space-y-1">
                            <label class="font-bold text-slate-500 block">Google Apps Script Web App URL *</label>
                            <input type="url" name="gdrive_app_url" id="drive_input_app_url" placeholder="https://script.google.com/macros/s/.../exec" required class="w-full p-2 bg-white border border-slate-200 rounded-lg outline-none font-mono">
                        </div>
                        <div class="md:col-span-4 space-y-1">
                            <label class="font-bold text-slate-500 block">Google Drive Folder ID *</label>
                            <input type="text" name="gdrive_folder_id" id="drive_input_folder_id" placeholder="รหัสโฟลเดอร์ใน Google Drive" required class="w-full p-2 bg-white border border-slate-200 rounded-lg outline-none font-mono">
                        </div>
                        <div class="md:col-span-3 flex items-end gap-1">
                            <button type="submit" class="w-full py-2 bg-[#0A3370] text-white font-bold rounded-lg transition-transform hover:scale-95 cursor-pointer text-center">
                                บันทึกชุดตั้งค่า
                            </button>
                            <button type="button" onclick="document.getElementById('drive_config_card').classList.add('hidden')" class="py-2 px-3 bg-slate-200 text-slate-600 font-bold rounded-lg hover:bg-slate-300">
                                ปิด
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Section 2: Instructions and Google Apps Script Code block (lg:col-span-4) -->
            <div class="lg:col-span-4 bg-[#0f172a] text-slate-200 border border-slate-800 rounded-3xl p-6 shadow-xl space-y-4">
                <div>
                    <h2 class="font-extrabold text-amber-400 text-sm flex items-center gap-1">🛠️ คู่มือชุดเชื่อม Google Drive (GAS Engine)</h2>
                    <p class="text-[9.5px] text-slate-400 mt-1 leading-relaxed">คัดลอกรหัสซอร์สโค้ดด้านล่าง เพื่อนำไปใช้สร้างชุดบริการ Google Apps Script ไปยังสัญญูของบุคคลผู้ใช้อีเมลโรงเรียนนั้นๆ เพื่อให้อัปโหลดจัดเก็บรูปคุณครูและรูปประเมินนิเทศลง Google Drive ของโรงเรียนนั้นโดยอัตโนมัติ</p>
                </div>

                <!-- Steps Description -->
                <div class="text-[10px] space-y-2 text-slate-300 border-l border-slate-700 pl-3 leading-relaxed">
                    <p><strong class="text-amber-300">ขั้นตอนที่ 1:</strong> เข้าสู่หน้าเว็บ <a href="https://script.google.com" target="_blank" class="text-sky-400 underline">script.google.com</a> ด้วยบัญชีของโรงเรียนนั้นๆ</p>
                    <p><strong class="text-amber-300">ขั้นตอนที่ 2:</strong> กดสร้าง "โครงการใหม่" (New Project)</p>
                    <p><strong class="text-amber-300">ขั้นตอนที่ 3:</strong> ลบโค้ดเดิมออกทั้งหมด และวางสคริปต์ (GAS Code) ด้านล่างลงไปแทนที่</p>
                    <p><strong class="text-amber-300">ขั้นตอนที่ 4:</strong> กดปุ่ม "การทำให้ใช้งานได้" (Deploy) > เลือก "การทำให้ใช้งานได้เพื่อเป็นเว็บแอป" (New Deployment as Web App)</p>
                    <p><strong class="text-amber-300">ขั้นตอนที่ 5:</strong> ตั่งคำสั่งสิทธิ์เข้าถึง: <strong>"ผู้ที่มีสิทธิ์เข้าถึง: ทุกคน (Anyone)"</strong> และคลิก Deploy เพื่อเชื่อมอนุญาตยอมรับสิทธิ์ไดรฟ์</p>
                    <p><strong class="text-amber-300">ขั้นตอนที่ 6:</strong> คัดลอก "URL ของเว็บแอป" (Web App URL) แนะนำส่งให้ Super Admin กรอกใส่ระบบพอร์ตโรงเรียน</p>
                </div>

                <!-- Google Apps Script Code Copy area -->
                <div class="space-y-1">
                    <div class="flex justify-between items-center text-[9px] text-slate-400">
                        <span>📋 ซอร์สโค้ด Google Apps Script (คลิกเพื่อคัดลอกทั้งหมด):</span>
                    </div>
                    <textarea class="w-full h-44 bg-slate-950 text-emerald-400 font-mono text-[9px] p-2.5 rounded-xl border border-slate-800 focus:outline-none cursor-pointer" readonly onclick="this.select(); alert('คัดลอกซอร์สโค้ด GAS เรียบร้อยแล้ว! สามารถนำไปวางที่หน้า Google Apps Script ได้ทันทีครับ')"><?php echo htmlspecialchars('/**
 * ระบบเชื่อมตรงคลังรูปภาพนิเทศ (Classroom Evaluation Google Drive Bridge)
 * พัฒนาเพื่อบูรณาการส่งข้อมูลเซ็นเซอร์เก็บไฟล์ในไดรฟ์ของเครือข่ายโรงเรียน
 */
function doPost(e) {
  try {
    var data = JSON.parse(e.postData.contents);
    var folderId = data.folder_id;
    var filename = data.filename;
    var filedata = data.filedata; // ชุดรูปแปลง Base64
    var mimeType = data.mime_type || "image/jpeg";
    
    // ตรวจหาโฟลเดอร์ปลายทาง
    var folder = DriveApp.getFolderById(folderId);
    var decoded = Utilities.base64Decode(filedata);
    var blob = Utilities.newBlob(decoded, mimeType, filename);
    var file = folder.createFile(blob);
    
    // กำหนดสิทธิ์ให้สากลลิงก์สามารถเปิดดูเพื่อดึงเป็นรูปประกอบรายงาน
    file.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW);
    
    return ContentService.createTextOutput(JSON.stringify({
      success: true,
      url: "https://lh3.googleusercontent.com/d/" + file.getId(),
      id: file.getId()
    })).setMimeType(ContentService.MimeType.JSON);
    
  } catch (error) {
    return ContentService.createTextOutput(JSON.stringify({
      success: false,
      error: error.toString()
    })).setMimeType(ContentService.MimeType.JSON);
  }
}'); ?></textarea>
                </div>

                <div class="text-[9.5px] bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-amber-500 leading-relaxed font-bold">
                    💡 โน้ตความปลอดภัย: ระบบภาพคุณครูและภาพนิเทศบรรยากาศจะถูกส่งขึ้นไดรฟ์ของเจ้าของโรงเรียนนั้นๆ ส่งผลให้ลดภาระที่จัดเก็บ และทำให้ภาพนั้นอยู่ในการรับผิดชอบของบุคลากรโรงเรียนที่ดูแลสิทธิ์โดยตรง
                </div>
            </div>

        </div>

        <div class="pt-6 border-t border-slate-200">
            <a href="dashboard.php" class="px-5 py-2.5 bg-gradient-to-r from-slate-700 to-slate-800 hover:opacity-95 text-xs text-white font-bold rounded-xl shadow transition tracking-wide block text-center md:inline-block">
                🔙 ย้อนกลับไปยัง แดชบอร์ดหลักของระบบ
            </a>
        </div>

    </main>

    <!-- JS Utility formula -->
    <script>
        function toggleDriveForm(schoolCode, appUrl, folderId) {
            const card = document.getElementById('drive_config_card');
            const targetSchoolSpan = document.getElementById('drive_target_school');
            const inputSchoolCode = document.getElementById('drive_input_school_code');
            const inputAppUrl = document.getElementById('drive_input_app_url');
            const inputFolderId = document.getElementById('drive_input_folder_id');
            
            targetSchoolSpan.innerText = 'รหัส SMISS: ' + schoolCode;
            inputSchoolCode.value = schoolCode;
            inputAppUrl.value = appUrl;
            inputFolderId.value = folderId;
            
            card.classList.remove('hidden');
            card.scrollIntoView({ behavior: 'smooth' });
        }
    </script>

    <!-- Footer block -->
    <footer class="py-6 mt-12 border-t border-slate-200 bg-white text-center text-[11px] text-slate-400 select-none">
        <p>ระบบเว็บแอพพลิเคชันเพื่อสุขภาวะและวิทยฐานะประกอบคุณครู เครือข่ายการจัดการศึกษาสู่ฐานสมรรถนะ</p>
    </footer>

</body>
</html>
