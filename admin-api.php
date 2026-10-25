<?php
/* ============================================================
   واجهة لوحة التحكم على استضافة Namecheap
   ------------------------------------------------------------
   تحفظ content.json وترفع الصور والفيديو مباشرة على الاستضافة،
   بدون GitHub وبدون مفاتيح وصول.

   >>> قبل الرفع: غيّر كلمة المرور بالسطر التحت <<<
   ============================================================ */

const ADMIN_PASSWORD = 'change-me-now';   // ← غيّرها

const CONTENT_FILE = __DIR__ . '/content.json';
const UPLOAD_DIR   = __DIR__ . '/assets/uploads';
const MAX_UPLOAD   = 64 * 1024 * 1024;    // ٦٤ ميغا

const ALLOWED = [
  'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
  'gif'  => 'image/gif',  'webp' => 'image/webp', 'svg' => 'image/svg+xml',
  'mp4'  => 'video/mp4',  'webm' => 'video/webm', 'mov' => 'video/quicktime',
];

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function isAuthed(): bool {
    return !empty($_SESSION['aa_ok']);
}
function requireAuth(): void {
    if (!isAuthed()) out(['error' => 'auth'], 401);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ---------- هل أنا مسجّل دخول؟ ---------- */
if ($action === 'status') {
    out(['ok' => true, 'authed' => isAuthed()]);
}

/* ---------- تسجيل الدخول ---------- */
if ($action === 'login') {
    $pw = (string)($_POST['password'] ?? '');
    if (ADMIN_PASSWORD === 'change-me-now') {
        out(['error' => 'setup', 'message' => 'لازم تغيّر كلمة المرور بملف admin-api.php'], 500);
    }
    if (!hash_equals(ADMIN_PASSWORD, $pw)) {
        usleep(600000);                       // يبطّئ محاولات التخمين
        out(['error' => 'bad', 'message' => 'كلمة المرور غلط'], 403);
    }
    session_regenerate_id(true);
    $_SESSION['aa_ok'] = true;
    out(['ok' => true]);
}

/* ---------- تسجيل الخروج ---------- */
if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    out(['ok' => true]);
}

/* ---------- حفظ المحتوى ---------- */
if ($action === 'save') {
    requireAuth();
    $raw = $_POST['content'] ?? '';
    $data = json_decode($raw, true);
    if (!is_array($data)) out(['error' => 'json', 'message' => 'محتوى غير صالح'], 400);

    $pretty = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($pretty === false) out(['error' => 'encode'], 500);

    /* نسخة احتياطية من آخر نسخة شغالة قبل ما نكتب فوقها */
    if (is_file(CONTENT_FILE)) @copy(CONTENT_FILE, CONTENT_FILE . '.bak');

    /* كتابة ذرّية: ما يصير ملف نص-نص إذا انقطع الاتصال */
    $tmp = CONTENT_FILE . '.tmp';
    if (file_put_contents($tmp, $pretty, LOCK_EX) === false) {
        out(['error' => 'write', 'message' => 'ما كدرت أكتب الملف — تأكد من صلاحيات المجلد'], 500);
    }
    if (!@rename($tmp, CONTENT_FILE)) {
        @unlink($tmp);
        out(['error' => 'write', 'message' => 'ما كدرت أحفظ الملف'], 500);
    }
    out(['ok' => true, 'bytes' => strlen($pretty)]);
}

/* ---------- رفع ملف ---------- */
if ($action === 'upload') {
    requireAuth();
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['file']['error'] ?? -1;
        $msg = ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE)
             ? 'الملف أكبر من الحد المسموح بالاستضافة'
             : 'فشل الرفع';
        out(['error' => 'upload', 'message' => $msg], 400);
    }
    $f = $_FILES['file'];
    if ($f['size'] > MAX_UPLOAD) {
        out(['error' => 'size', 'message' => 'الملف أكبر من ' . (MAX_UPLOAD / 1048576) . 'MB'], 400);
    }

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!isset(ALLOWED[$ext])) {
        out(['error' => 'type', 'message' => 'نوع الملف غير مسموح'], 400);
    }

    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0775, true)) {
        out(['error' => 'dir', 'message' => 'ما كدرت أسوي مجلد الرفع'], 500);
    }

    /* اسم آمن تماماً — ما نثق باسم الملف الجاي من المتصفح */
    $base = preg_replace('/[^a-z0-9]+/', '-', strtolower(pathinfo($f['name'], PATHINFO_FILENAME)));
    $base = trim($base, '-');
    if ($base === '') $base = 'file';
    $name = substr($base, 0, 40) . '-' . time() . '.' . $ext;
    $dest = UPLOAD_DIR . '/' . $name;

    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        out(['error' => 'move', 'message' => 'ما كدرت أحفظ الملف — تأكد من صلاحيات assets/uploads'], 500);
    }
    @chmod($dest, 0644);
    out(['ok' => true, 'path' => 'assets/uploads/' . $name]);
}

out(['error' => 'unknown'], 400);
