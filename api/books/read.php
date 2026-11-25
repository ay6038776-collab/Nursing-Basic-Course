<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$bookId = (int)($_GET['id'] ?? 0);
if ($bookId <= 0) {
    http_response_code(400);
    exit('معرف الكتاب غير صالح.');
}

// Authentication & Authorization check
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

$userId = null;
$userName = 'طالب الأكاديمية';
$userEmail = '';
$userPhone = '';
$isAdmin = false;

// Try admin session
session_name('NBC_ADMIN_SESSID');
@session_start();
if (!empty($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
    $isAdmin = true;
    $stmt = $pdo->prepare('SELECT full_name, email, phone FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if ($u) {
        $userName = $u['full_name'];
        $userEmail = $u['email'];
        $userPhone = $u['phone'] ?? '';
    }
} else {
    // Try student session
    session_write_close();
    session_name('NBC_STUDENT_SESSID');
    @session_start();
    if (!empty($_SESSION['user_id'])) {
        $userId = (int)$_SESSION['user_id'];
        $stmt = $pdo->prepare('SELECT full_name, email, phone FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        if ($u) {
            $userName = $u['full_name'];
            $userEmail = $u['email'];
            $userPhone = $u['phone'] ?? '';
        }
    }
}

if (!$userId) {
    http_response_code(401);
    exit('<!DOCTYPE html><html dir="rtl"><body style="font-family:sans-serif;text-align:center;padding:50px;"><h2>يجب تسجيل الدخول لقراءة الكتاب</h2></body></html>');
}

$identityStmt = $pdo->prepare('SELECT role,status FROM users WHERE id=? LIMIT 1');
$identityStmt->execute([(int)$userId]);
$identity = $identityStmt->fetch(PDO::FETCH_ASSOC);
$roles = $identity ? [(string)$identity['role']] : [];
if ($identity) {
    try {
        $roleStmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id=?');
        $roleStmt->execute([(int)$userId]);
        $roles = array_merge($roles, $roleStmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) {}
}
if (!$identity || $identity['status'] !== 'active' || (!in_array('student', $roles, true) && !in_array('admin', $roles, true))) {
    $userId = null;
    $isAdmin = false;
    http_response_code(403);
    exit('الحساب غير نشط أو غير مصرح له.');
}
$isAdmin = in_array('admin', $roles, true);

// Check authorization in book_access if not admin
if (!$isAdmin) {
    $stmt = $pdo->prepare("SELECT id FROM book_access WHERE student_id = ? AND book_id = ? AND status = 'active'");
    $stmt->execute([$userId, $bookId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        exit('<!DOCTYPE html><html dir="rtl"><body style="font-family:sans-serif;text-align:center;padding:50px;"><h2>عذراً، هذا الكتاب مخصص للطلاب المشتركين فقط. يرجى شراء الكتاب أولاً من المتجر.</h2></body></html>');
    }
}

// Fetch book info
$stmt = $pdo->prepare('SELECT id, title_ar, author, pages_count, pdf_path, is_downloadable FROM books WHERE id = ?');
$stmt->execute([$bookId]);
$book = $stmt->fetch();

if (!$book) {
    http_response_code(404);
    exit('الكتاب غير موجود.');
}

$watermarkText = $userName . ' — ' . $userEmail . ' (' . ($userPhone ?: 'ID: ' . $userId) . ') — Edu Nursing Academy';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>القارئ الطبي الآمن | <?= htmlspecialchars($book['title_ar']) ?> — Edu Nursing</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
  :root {
    --teal: #0d9488;
    --navy: #0a1f3d;
    --dark: #0f172a;
    --reader-bg: #1e293b;
    --card: #ffffff;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    padding: 0;
    font-family: 'Cairo', system-ui, sans-serif;
    background: var(--reader-bg);
    color: #f1f5f9;
    height: 100vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    user-select: none;
    -webkit-user-select: none;
  }
  .reader-toolbar {
    height: 60px;
    background: var(--navy);
    border-bottom: 1px solid rgba(255,255,255,0.1);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 20px;
    flex-shrink: 0;
    z-index: 100;
  }
  .book-info h2 {
    margin: 0;
    font-size: 15px;
    font-weight: 800;
    color: #fff;
  }
  .book-info small {
    color: var(--teal);
    font-size: 12px;
  }
  .reader-controls {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .ctrl-btn {
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.2);
    color: #fff;
    padding: 6px 12px;
    border-radius: 6px;
    cursor: pointer;
    font-family: inherit;
    font-size: 13px;
    font-weight: 700;
    transition: all 0.2s;
  }
  .ctrl-btn:hover {
    background: var(--teal);
    border-color: var(--teal);
  }
  .page-nav {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: #cbd5e1;
  }
  .page-nav input {
    width: 50px;
    text-align: center;
    padding: 4px;
    border-radius: 4px;
    border: 1px solid rgba(255,255,255,0.2);
    background: #0f172a;
    color: #fff;
    font-family: inherit;
  }
  .reader-canvas-wrap {
    flex: 1;
    overflow-y: auto;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 30px 15px;
  }
  /* Floating dynamic watermark protecting the medical content */
  .watermark-overlay {
    position: absolute;
    inset: 0;
    pointer-events: none;
    z-index: 50;
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    grid-template-rows: repeat(4, 1fr);
    overflow: hidden;
  }
  .watermark-cell {
    display: flex;
    align-items: center;
    justify-content: center;
    transform: rotate(-25deg);
    font-size: 14px;
    font-weight: 800;
    color: rgba(255, 255, 255, 0.08);
    text-shadow: 0 0 1px rgba(0,0,0,0.2);
    white-space: nowrap;
  }
  .medical-page {
    width: 100%;
    max-width: 800px;
    min-height: 1050px;
    background: #fff;
    color: #0f172a;
    border-radius: 10px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.5);
    margin-bottom: 30px;
    padding: 50px 60px;
    position: relative;
    line-height: 1.8;
  }
  .page-header {
    display: flex;
    justify-content: space-between;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 15px;
    margin-bottom: 30px;
    font-size: 12px;
    color: #64748b;
    font-weight: 700;
  }
  .page-footer {
    display: flex;
    justify-content: space-between;
    border-top: 1px solid #e2e8f0;
    padding-top: 15px;
    margin-top: 40px;
    font-size: 11px;
    color: #94a3b8;
  }
  .medical-page h3 {
    color: #0a1f3d;
    font-size: 20px;
    margin-top: 0;
    border-inline-start: 4px solid #0d9488;
    padding-inline-start: 10px;
  }
  .medical-box {
    background: #f0fdfa;
    border: 1px solid #ccfbf1;
    border-radius: 8px;
    padding: 16px;
    margin: 20px 0;
    font-size: 13.5px;
  }
  .medical-box strong { color: #0f766e; }
</style>
</head>
<body>

<header class="reader-toolbar">
  <div class="book-info">
    <h2>📖 <?= htmlspecialchars($book['title_ar']) ?></h2>
    <small>المؤلف: <?= htmlspecialchars($book['author']) ?> | قارئ آمن مخصص للأكاديمية</small>
  </div>

  <div class="reader-controls">
    <div class="page-nav">
      <span>صفحة</span>
      <input type="number" id="pageNum" value="1" min="1" max="<?= (int)$book['pages_count'] ?>" onchange="jumpToPage(this.value)">
      <span>من <?= (int)$book['pages_count'] ?></span>
    </div>
    <button class="ctrl-btn" onclick="zoomIn()">➕ تكبير</button>
    <button class="ctrl-btn" onclick="zoomOut()">➖ تصغير</button>
    <button class="ctrl-btn" onclick="window.close()">✕ إغلاق</button>
  </div>
</header>

<div class="reader-canvas-wrap" id="canvasWrap">
  <div class="watermark-overlay">
    <?php for($i = 0; $i < 8; $i++): ?>
      <div class="watermark-cell"><?= htmlspecialchars($watermarkText) ?></div>
    <?php endfor; ?>
  </div>

  <!-- Medical PDF Page 1 -->
  <article class="medical-page" id="page-1">
    <div class="page-header">
      <span>Edu Nursing – Medical Education Academy</span>
      <span><?= htmlspecialchars($book['title_ar']) ?></span>
    </div>

    <h3>الفصل الأول: الأساسيات السريرية والممارسة الطبية المتقدمة</h3>
    <p>تُعد مهنة التمريض الركيزة الأساسية للرعاية الصحية في جميع المؤسسات والمستشفيات الحديثة. يتطلب التمريض فهماً دقيقاً للفسيولوجيا البشرية، آليات الاستجابة للأدوية، والتدخل الفوري عند اضطراب العلامات الحيوية.</p>

    <div class="medical-box">
      <strong>بروتوكول الأمان والسلامة السريرية:</strong>
      <p>يجب التأكد من القواعد الخمس لإعطاء الدواء (المريض الصحيح، الدواء الصحيح، الجرعة الصحيحة، الوقت الصحيح، والطريقة الصحيحة) قبل الشروع في أي تدخل علاجي.</p>
    </div>

    <p>يتناول هذا المرجع السريري الموثوق أهم الشروحات والبروتوكولات التمريضية التي يحتاجها كل ممارس صحي وممرض في أقسام الطوارئ والعناية المركزة وأقسام الباطنة والجراحة، إعداد وإشراف <strong><?= htmlspecialchars($book['author']) ?></strong>.</p>

    <div class="page-footer">
      <span>نسخة رقمية معتمدة ومرخصة لـ: <?= htmlspecialchars($userName) ?></span>
      <span>صفحة 1 من <?= (int)$book['pages_count'] ?></span>
    </div>
  </article>

  <!-- Medical PDF Page 2 -->
  <article class="medical-page" id="page-2">
    <div class="page-header">
      <span>Edu Nursing – Medical Education Academy</span>
      <span>التقييم السريري والعلامات الحيوية</span>
    </div>

    <h3>الفصل الثاني: الملاحظة السريرية والعلامات الحيوية المتقدمة</h3>
    <p>قياس العلامات الحيوية بدقة يمثل المؤشر الأول لحالة استقرار المريض. يُلزم الممرض بملاحظة النبض الشرياني ومعدل التنفس وضغط الدم الشرياني ودرجة الحرارة وتشبع الأكسجين المحيطي.</p>

    <div class="medical-box">
      <strong>مؤشرات الخطر الحاد (Critical Clinical Alerts):</strong>
      <p>أي انخفاض في تشبع الأكسجين SpO2 إلى أقل من 92% أو انخفاض ضغط الدم الانقباضي لأقل من 90 مم زئبق يستوجب التبليغ والتدخل الطبي العاجل وتجهيز جهاز الصدمات وخط المحاليل الوريدية.</p>
    </div>

    <div class="page-footer">
      <span>نسخة رقمية معتمدة ومرخصة لـ: <?= htmlspecialchars($userName) ?></span>
      <span>صفحة 2 من <?= (int)$book['pages_count'] ?></span>
    </div>
  </article>
</div>

<script>
let currentScale = 1.0;
function zoomIn() {
  currentScale = Math.min(1.5, currentScale + 0.1);
  applyScale();
}
function zoomOut() {
  currentScale = Math.max(0.7, currentScale - 0.1);
  applyScale();
}
function applyScale() {
  document.querySelectorAll('.medical-page').forEach(el => {
    el.style.transform = `scale(${currentScale})`;
    el.style.transformOrigin = 'top center';
  });
}
function jumpToPage(num) {
  const el = document.getElementById('page-' + num);
  if (el) el.scrollIntoView({ behavior: 'smooth' });
}
</script>
</body>
</html>
