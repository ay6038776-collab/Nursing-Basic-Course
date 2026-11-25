<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$courseId = (int)($_GET['course_id'] ?? 0);
$lessonId = (int)($_GET['lesson_id'] ?? 0);
$lessonSource = (string)($_GET['lesson_source'] ?? '');

if ($courseId <= 0) {
    http_response_code(400);
    exit('معرف الكورس غير صالح.');
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
} else {
    // Try student session
    session_write_close();
    session_name('NBC_STUDENT_SESSID');
    @session_start();
    if (!empty($_SESSION['user_id'])) {
        $userId = (int)$_SESSION['user_id'];
    }
}

if ($userId) {
    $stmt = $pdo->prepare('SELECT full_name, email, phone, role, status FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if ($u && $u['status'] === 'active') {
        $roles = [(string)$u['role']];
        try {
            $roleStmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id=?');
            $roleStmt->execute([$userId]);
            $roles = array_merge($roles, $roleStmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {}
        $isAdmin = in_array('admin', $roles, true);
        $isInstructor = in_array('instructor', $roles, true);
        $userName = $u['full_name'];
        $userEmail = $u['email'];
        $userPhone = $u['phone'] ?? '';
    } else {
        $userId = null;
        $isAdmin = false;
    }
}

// Check enrollment
$isEnrolled = false;
if ($isAdmin) {
    $isEnrolled = true;
} elseif ($userId) {
    $enrollmentColumns = $pdo->query('SHOW COLUMNS FROM enrollments')->fetchAll(PDO::FETCH_COLUMN);
    $accessFilter = in_array('access_status', $enrollmentColumns, true) ? "AND access_status IN ('granted','allowed')" : '';
    $startsFilter = in_array('starts_at', $enrollmentColumns, true) ? 'AND (starts_at IS NULL OR starts_at <= NOW())' : '';
    $expiresFilter = in_array('expires_at', $enrollmentColumns, true) ? 'AND (expires_at IS NULL OR expires_at >= NOW())' : '';
    $checkEnr = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND course_id = ? AND status IN ('active', 'completed') $accessFilter $startsFilter $expiresFilter LIMIT 1");
    $checkEnr->execute([$userId, $courseId]);
    if ($checkEnr->fetch()) {
        $isEnrolled = true;
    }
    if (!$isEnrolled && !empty($isInstructor)) {
        $owner = $pdo->prepare('SELECT 1 FROM courses WHERE id=? AND instructor_id=? LIMIT 1');
        $owner->execute([$courseId, $userId]);
        $isEnrolled = (bool)$owner->fetchColumn();
    }
}

// Fetch course
$stmt = $pdo->prepare("
    SELECT c.*, u.full_name AS instructor_name 
    FROM courses c 
    LEFT JOIN users u ON u.id = c.instructor_id 
    WHERE c.id = ? AND c.status = 'published'
");
$stmt->execute([$courseId]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    exit('الكورس غير موجود أو غير منشور.');
}

// Prefer the section-based curriculum created by the admin course editor.
$stmt = $pdo->prepare("SELECT l.id,l.title,l.description,l.video_url,l.duration_minutes,MIN(s.sort_order) AS section_order,MIN(sl.sort_order) AS sort_order,l.is_preview,
                              'course_lessons' AS lesson_source,MIN(s.title) AS section_title
                       FROM course_section_lessons sl
                       JOIN course_sections s ON s.id=sl.section_id AND s.course_id=? AND s.status='published'
                       JOIN course_lessons l ON l.id=sl.lesson_id AND l.course_id=?
                       WHERE sl.lesson_source='course_lessons' AND l.status='published'
                       GROUP BY l.id,l.title,l.description,l.video_url,l.duration_minutes,l.is_preview
                       UNION ALL
                       SELECT l.id,l.title,l.description,l.video_url,l.duration_minutes,MIN(s.sort_order) AS section_order,MIN(sl.sort_order) AS sort_order,l.is_preview,
                              'lessons' AS lesson_source,MIN(s.title) AS section_title
                       FROM course_section_lessons sl
                       JOIN course_sections s ON s.id=sl.section_id AND s.course_id=? AND s.status='published'
                       JOIN lessons l ON l.id=sl.lesson_id AND l.course_id=?
                       WHERE sl.lesson_source='lessons' AND l.status='published'
                       GROUP BY l.id,l.title,l.description,l.video_url,l.duration_minutes,l.is_preview
                       ORDER BY section_order ASC,sort_order ASC,id ASC");
$stmt->execute([$courseId, $courseId, $courseId, $courseId]);
$lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($lessons)) {
    $stmt = $pdo->prepare("SELECT id,title,description,video_url,duration_minutes,sort_order,is_preview,'lessons' AS lesson_source,'' AS section_title
                           FROM lessons WHERE course_id=? AND status='published' ORDER BY sort_order ASC,id ASC");
    $stmt->execute([$courseId]);
    $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (empty($lessons)) {
    exit('لا توجد دروس مضافة لهذا الكورس بعد.');
}

// Determine active lesson
$activeLesson = null;
if ($lessonId > 0) {
    foreach ($lessons as $l) {
        if ((int)$l['id'] === $lessonId && ($lessonSource === '' || $lessonSource === $l['lesson_source'])) {
            $activeLesson = $l;
            break;
        }
    }
}
if (!$activeLesson) {
    $activeLesson = $lessons[0];
}

// Check access permission for this lesson
$canWatch = $isEnrolled || (bool)$activeLesson['is_preview'];

if (!$canWatch) {
    http_response_code(403);
    exit('<!DOCTYPE html><html dir="rtl"><body style="font-family:sans-serif;text-align:center;padding:50px;"><h2>عذراً، هذا الدرس مخصص للمشتركين فقط. يرجى الاشتراك في الكورس لمشاهدة جميع الدروس.</h2><p><a href="../../index.html#course/' . $courseId . '">العودة لصفحة الكورس</a></p></body></html>');
}

// Fetch student progress for this lesson if logged in
$savedPosition = 0;
if ($userId && ($activeLesson['lesson_source'] ?? '') === 'course_lessons') {
    $pStmt = $pdo->prepare("SELECT lp.last_position_seconds FROM lesson_progress lp JOIN enrollments e ON e.id=lp.enrollment_id WHERE e.student_id=? AND e.course_id=? AND lp.lesson_id=? ORDER BY lp.updated_at DESC LIMIT 1");
    $pStmt->execute([$userId, $courseId, $activeLesson['id']]);
    $savedPosition = (int)($pStmt->fetchColumn() ?: 0);
}

$lessonFiles = [];
if (($activeLesson['lesson_source'] ?? '') === 'course_lessons') {
$filesStmt = $pdo->prepare('SELECT id,original_name,file_path,mime_type,size_bytes FROM course_lesson_files WHERE course_id=? AND lesson_id=? ORDER BY id');
    $filesStmt->execute([$courseId, (int)$activeLesson['id']]);
    $lessonFiles = $filesStmt->fetchAll(PDO::FETCH_ASSOC);
}

$videoPath = (string)($activeLesson['video_url'] ?? '');
$videoExtension = strtolower(pathinfo(parse_url($videoPath, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
$videoMime = ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime'][$videoExtension] ?? 'video/mp4';
$videoSrc = preg_match('#^uploads/course-content/videos/(?:[a-f0-9]{40}|course[0-9]+_[a-f0-9]{24})\.(mp4|webm|mov)$#i', $videoPath)
    ? '../../api/courses/media.php?course_id=' . $courseId . '&lesson_id=' . (int)$activeLesson['id'] . '&lesson_source=' . rawurlencode((string)$activeLesson['lesson_source'])
    : ((preg_match('#^https?://#i', $videoPath) && filter_var($videoPath, FILTER_VALIDATE_URL)) ? $videoPath : '');
$activeIndex = 0;
foreach ($lessons as $index => $lessonRow) {
    if ((int)$lessonRow['id'] === (int)$activeLesson['id'] && $lessonRow['lesson_source'] === $activeLesson['lesson_source']) { $activeIndex = $index; break; }
}
$previousLesson = $lessons[$activeIndex - 1] ?? null;
$nextLesson = $lessons[$activeIndex + 1] ?? null;

$watermarkText = htmlspecialchars($userName . ' (' . ($userPhone ?: 'ID: ' . $userId) . ') — Edu Nursing Academy');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($activeLesson['title']) ?> | <?= htmlspecialchars($course['title']) ?> — Edu Nursing</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
  :root {
    --teal: #0d9488;
    --navy: #0a1f3d;
    --dark: #070d18;
    --panel: #0e1829;
    --line: #1c2b42;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    padding: 0;
    font-family: 'Cairo', system-ui, sans-serif;
    background: var(--dark);
    color: #f1f5f9;
    height: 100vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .player-topbar {
    height: 60px;
    background: var(--navy);
    border-bottom: 1px solid var(--line);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 20px;
    flex-shrink: 0;
  }
  .player-topbar a {
    color: #cbd5e1;
    text-decoration: none;
    font-weight: 700;
    font-size: 13.5px;
  }
  .player-topbar a:hover { color: #fff; }
  .course-brand-title {
    font-size: 15px;
    font-weight: 800;
    color: #fff;
  }
  .player-layout {
    flex: 1;
    display: grid;
    grid-template-columns: minmax(0, 1fr) 340px;
    overflow: hidden;
  }
  .main-stage {
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    padding: 20px;
  }
  .video-container {
    width: 100%;
    aspect-ratio: 16 / 9;
    background: #000;
    border-radius: 18px;
    overflow: hidden;
    position: relative;
    box-shadow: 0 24px 70px rgba(0,0,0,0.5), 0 0 0 1px rgba(255,255,255,.08);
    isolation: isolate;
  }
  .video-container::after{content:"";position:absolute;inset:45% 0 0;z-index:2;pointer-events:none;background:linear-gradient(transparent,rgba(0,0,0,.72));opacity:.8}
  .video-container video{background:#02050b;cursor:pointer}
  .video-container video[hidden],.video-container .player-center-play[hidden]{display:none!important}
  .player-top-overlay{position:absolute;inset:0 0 auto;z-index:4;padding:18px 22px;background:linear-gradient(rgba(0,0,0,.58),transparent);pointer-events:none}
  .player-course-label{display:block;color:#a9f3e9;font-size:11px;font-weight:800;letter-spacing:.06em;margin-bottom:4px}
  .player-video-title{font-size:15px;font-weight:800;text-shadow:0 2px 8px #000}
  .player-center-play{position:absolute;z-index:5;left:50%;top:50%;transform:translate(-50%,-50%);width:76px;height:76px;border:1px solid rgba(255,255,255,.42);border-radius:50%;background:rgba(13,148,136,.92);color:white;display:grid;place-items:center;cursor:pointer;box-shadow:0 10px 36px rgba(0,0,0,.4);transition:.2s}
  .player-center-play:hover{transform:translate(-50%,-50%) scale(1.07);background:#14b8a6}
  .player-center-play svg{width:28px;height:28px;fill:currentColor}
  .player-controls{position:absolute;z-index:5;inset:auto 0 0;padding:26px 18px 14px;color:#fff;transition:opacity .25s,transform .25s}
  .video-container.controls-hidden:not(:hover):not(:focus-within) .player-controls{opacity:0;transform:translateY(8px)}
  .player-seek{width:100%;height:5px;margin:0 0 10px;display:block;appearance:none;border-radius:8px;outline:none;cursor:pointer;background:linear-gradient(to right,#20c7b2 0%,#20c7b2 var(--seek,0%),rgba(255,255,255,.32) var(--seek,0%),rgba(255,255,255,.32) 100%)}
  .player-seek::-webkit-slider-thumb{appearance:none;width:12px;height:12px;border-radius:50%;background:#fff;box-shadow:0 0 0 3px rgba(32,199,178,.35)}
  .player-seek::-moz-range-thumb{width:12px;height:12px;border:0;border-radius:50%;background:#fff}
  .player-controls-row{display:flex;align-items:center;justify-content:space-between;gap:12px}
  .player-controls-group{display:flex;align-items:center;gap:10px}
  .player-control-btn{border:0;background:transparent;color:#f8fafc;min-width:34px;height:34px;display:inline-grid;place-items:center;border-radius:9px;cursor:pointer;font:inherit}
  .player-control-btn:hover{background:rgba(255,255,255,.15)}
  .player-control-btn svg{width:20px;height:20px;fill:currentColor}
  .player-time{font:600 12px/1.2 system-ui,sans-serif;color:#e2e8f0;direction:ltr;white-space:nowrap}
  .player-volume{width:78px;accent-color:#20c7b2}
  .player-speed{height:30px;border:1px solid rgba(255,255,255,.25);border-radius:8px;background:rgba(10,15,25,.72);color:#fff;padding:0 6px;font:600 11px system-ui}
  .video-empty{position:absolute;z-index:3;inset:0;display:grid;place-content:center;text-align:center;padding:24px;color:#cbd5e1;background:radial-gradient(ellipse at center,rgba(20,184,166,.12),transparent 62%)}
  .video-empty strong{font-size:17px;color:white}
  .video-empty span{font-size:13px;margin-top:6px;color:#94a3b8}
  .player-nav{display:flex;justify-content:space-between;gap:12px;margin-top:14px}
  .player-nav a{color:#d1fae5;text-decoration:none;padding:9px 13px;border:1px solid var(--line);background:var(--panel);border-radius:10px;font-size:12px;font-weight:700}
  .lesson-files{margin-top:16px;padding-top:14px;border-top:1px solid var(--line)}
  .lesson-files h2{font-size:14px;margin:0 0 10px}
  .lesson-file-link{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;margin-top:7px;border-radius:10px;background:rgba(255,255,255,.035);border:1px solid var(--line);color:#cbd5e1;text-decoration:none;font-size:12px}
  .lesson-file-link:hover{border-color:var(--teal);color:white}
  .player-back{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:9px;background:rgba(255,255,255,.06)}
  .playlist-unit{padding:12px 16px 5px;color:#71859d;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
  .playlist-item{border-radius:9px;margin:2px 8px}
  .player-topbar .course-brand-title{display:flex;align-items:center;gap:10px}
  .player-brand-mark{width:30px;height:30px;border-radius:10px;background:linear-gradient(135deg,#20c7b2,#087f75);display:grid;place-items:center;color:#042f2e;font-size:15px}
  .player-topbar-actions{display:flex;align-items:center;gap:16px}
  .playlist-toggle{display:none!important;border:1px solid var(--line);background:var(--panel);color:#fff;border-radius:8px;padding:7px 10px;font:inherit}
  .lesson-eyebrow{color:#5eead4;font-size:11px;font-weight:800;margin-bottom:6px}
  .no-video .player-center-play,.no-video .player-controls{display:none}
  .player-layout.is-fullscreen{grid-template-columns:minmax(0,1fr) 340px}
  .video-container:fullscreen{width:100vw;height:100vh;max-width:none;aspect-ratio:auto;border-radius:0}
  .video-container:fullscreen video{object-fit:contain}
  .video-container:fullscreen .video-watermark{top:28px}
  @media (max-width: 900px) {
    body{height:auto;min-height:100vh;overflow:auto}
    .player-topbar{padding:0 12px;height:54px}
    .player-topbar .course-brand-title{font-size:12px;max-width:62%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .player-topbar-actions{gap:8px}
    .player-topbar-actions .player-back{font-size:11px;padding:7px 9px}
    .playlist-toggle{display:inline-flex!important}
    .player-layout{display:flex;flex-direction:column;overflow-y:auto}
    .main-stage{padding:12px;overflow:visible}
    .video-container{flex:none;border-radius:12px}
    .player-center-play{width:58px;height:58px}
    .player-center-play svg{width:22px;height:22px}
    .player-controls{padding:22px 10px 9px}
    .player-volume{width:54px}
    .player-controls-group{gap:4px}
    .player-control-btn{min-width:30px;height:30px}
    .player-time{font-size:10px}
    .player-speed{height:28px}
    .video-watermark{top:9px!important;left:9px!important;font-size:10px!important;padding:3px 7px!important}
    .player-top-overlay{padding:12px 12px}
    .player-video-title{font-size:12px}
    .lesson-details-box{margin-top:12px;padding:15px}
    .sidebar-playlist{max-height:0;overflow:hidden;border:0;transition:max-height .25s ease}
    .sidebar-playlist.mobile-open{max-height:55vh;overflow-y:auto;border-top:1px solid var(--line)}
    .playlist-header{padding:12px 16px}
    .playlist-item{padding:11px 14px}
    .player-layout.is-fullscreen{display:flex}
  }
  @media (max-width:480px){
    .player-speed{font-size:10px;max-width:58px}
    .player-volume{width:42px}
    .player-time{font-size:9px}
    .player-controls-group{gap:2px}
    .player-nav a{font-size:11px;padding:8px}
  }
  video {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: contain;
  }
  /* Floating Dynamic Security Watermark */
  .video-watermark {
    position: absolute;
    top: 20px;
    left: 20px;
    color: rgba(255, 255, 255, 0.4);
    font-size: 13px;
    font-weight: 800;
    pointer-events: none;
    z-index: 10;
    background: rgba(0,0,0,0.3);
    padding: 4px 10px;
    border-radius: 6px;
    transition: all 1s ease;
  }
  .lesson-details-box {
    margin-top: 20px;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 20px;
  }
  .lesson-details-box h1 {
    margin: 0 0 10px;
    font-size: 20px;
    color: #fff;
  }
  .lesson-details-box p {
    color: #94a3b8;
    margin: 0;
    font-size: 14px;
    line-height: 1.7;
  }
  .sidebar-playlist {
    background: var(--panel);
    border-inline-start: 1px solid var(--line);
    display: flex;
    flex-direction: column;
    overflow-y: auto;
  }
  .playlist-header {
    padding: 18px 20px;
    border-bottom: 1px solid var(--line);
    font-weight: 800;
    font-size: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .playlist-items {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }
  .playlist-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    color: #cbd5e1;
    text-decoration: none;
    border-bottom: 1px solid rgba(255,255,255,0.03);
    transition: background 0.2s;
    font-size: 13.5px;
    font-weight: 600;
  }
  .playlist-item:hover {
    background: rgba(20, 184, 166, 0.08);
    color: #fff;
  }
  .playlist-item.active {
    background: rgba(20, 184, 166, 0.15);
    color: var(--teal);
    border-inline-start: 4px solid var(--teal);
  }
  .item-num {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    background: rgba(255,255,255,0.06);
    display: grid;
    place-items: center;
    font-size: 11px;
    flex-shrink: 0;
  }
</style>
</head>
<body>

<header class="player-topbar">
  <div class="course-brand-title"><span class="player-brand-mark">▶</span><span>Edu Nursing <small style="display:block;color:#94a3b8;font-size:10px;font-weight:600"><?= htmlspecialchars($course['title']) ?></small></span></div>
  <div class="player-topbar-actions">
    <button type="button" class="playlist-toggle" id="playlistToggle">☷ الدروس</button>
    <a class="player-back" href="../../index.html#course/<?= $courseId ?>">← <span>العودة للكورس</span></a>
  </div>
</header>

<div class="player-layout">
  <main class="main-stage">
    <div class="video-container <?= $videoSrc === '' ? 'no-video' : '' ?>" id="videoBox" tabindex="0" aria-label="مشغل فيديو الدرس">
      <?php if ($videoSrc !== ''): ?>
        <video id="mainVideo" playsinline preload="metadata" controlsList="nodownload" disablePictureInPicture>
          <source src="<?= htmlspecialchars($videoSrc, ENT_QUOTES, 'UTF-8') ?>" type="<?= htmlspecialchars($videoMime, ENT_QUOTES, 'UTF-8') ?>">
        </video>
      <?php else: ?>
        <video id="mainVideo" playsinline preload="none" hidden></video>
        <div class="video-empty"><strong>الفيديو غير متاح لهذا الدرس حاليًا</strong><span>يمكنك اختيار درس آخر من قائمة المنهج.</span></div>
      <?php endif; ?>
      <div class="video-watermark" id="watermark"><?= $watermarkText ?></div>
      <?php if ($videoSrc !== ''): ?>
      <div class="player-top-overlay"><span class="player-course-label"><?= htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8') ?></span><div class="player-video-title"><?= htmlspecialchars($activeLesson['title'], ENT_QUOTES, 'UTF-8') ?></div></div>
      <button type="button" class="player-center-play" id="centerPlay" aria-label="تشغيل الفيديو"><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg></button>
      <div class="player-controls" id="playerControls">
        <input class="player-seek" id="videoSeek" type="range" min="0" max="1000" value="0" aria-label="تقدم الفيديو">
        <div class="player-controls-row">
          <div class="player-controls-group">
            <button type="button" class="player-control-btn" id="playToggle" aria-label="تشغيل"><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg></button>
            <button type="button" class="player-control-btn" id="backTen" aria-label="رجوع 10 ثوانٍ">↶</button>
            <button type="button" class="player-control-btn" id="forwardTen" aria-label="تقديم 10 ثوانٍ">↷</button>
            <span class="player-time"><span id="currentTime">0:00</span> / <span id="durationTime">0:00</span></span>
          </div>
          <div class="player-controls-group">
            <button type="button" class="player-control-btn" id="volumeToggle" aria-label="كتم الصوت"><svg viewBox="0 0 24 24"><path d="M3 9v6h4l5 4V5L7 9H3zm13.5 3a4.5 4.5 0 0 0-2.5-4.03v8.05a4.5 4.5 0 0 0 2.5-4.02zM14 3.23v2.06a7 7 0 0 1 0 13.42v2.06a9 9 0 0 0 0-17.54z"/></svg></button>
            <input class="player-volume" id="volumeRange" type="range" min="0" max="1" step="0.05" value="1" aria-label="مستوى الصوت">
            <select class="player-speed" id="speedSelect" aria-label="سرعة التشغيل"><option value="0.75">0.75×</option><option value="1" selected>عادي</option><option value="1.25">1.25×</option><option value="1.5">1.5×</option><option value="1.75">1.75×</option><option value="2">2×</option></select>
            <button type="button" class="player-control-btn" id="fullscreenToggle" aria-label="ملء الشاشة"><svg viewBox="0 0 24 24"><path d="M4 4h6v2H6v4H4V4zm10 0h6v6h-2V6h-4V4zM4 14h2v4h4v2H4v-6zm14 0h2v6h-6v-2h4v-4z"/></svg></button>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div class="lesson-details-box">
      <div class="lesson-eyebrow"><?= htmlspecialchars((string)($activeLesson['section_title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
      <h1><?= htmlspecialchars($activeLesson['title']) ?></h1>
      <p><?= nl2br(htmlspecialchars($activeLesson['description'] ?: 'لا يوجد وصف تفصيلي لهذا الدرس.')) ?></p>
      <div class="player-nav">
        <?php if ($previousLesson): ?><a href="?course_id=<?= $courseId ?>&lesson_id=<?= (int)$previousLesson['id'] ?>&lesson_source=<?= urlencode($previousLesson['lesson_source']) ?>">→ الدرس السابق</a><?php else: ?><span></span><?php endif; ?>
        <?php if ($nextLesson): ?><a href="?course_id=<?= $courseId ?>&lesson_id=<?= (int)$nextLesson['id'] ?>&lesson_source=<?= urlencode($nextLesson['lesson_source']) ?>">الدرس التالي ←</a><?php endif; ?>
      </div>
      <?php if ($lessonFiles): ?>
      <div class="lesson-files"><h2>ملفات الدرس</h2>
        <?php foreach ($lessonFiles as $file): ?>
          <a class="lesson-file-link" href="../../api/courses/media.php?course_id=<?= $courseId ?>&lesson_id=<?= (int)$activeLesson['id'] ?>&lesson_source=course_lessons&file_id=<?= (int)$file['id'] ?>" target="_blank" rel="noopener"><span>📄 <?= htmlspecialchars($file['original_name'], ENT_QUOTES, 'UTF-8') ?></span><span>فتح الملف ↗</span></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </main>

  <aside class="sidebar-playlist">
    <div class="playlist-header">
      <span>محتوى الكورس</span>
      <small style="color:var(--teal)"><?= count($lessons) ?> دروس</small>
    </div>

    <div class="playlist-items">
      <?php foreach ($lessons as $idx => $ls): ?>
        <?php if ($idx === 0 || ($ls['section_title'] ?? '') !== ($lessons[$idx - 1]['section_title'] ?? '')): ?><div class="playlist-unit"><?= htmlspecialchars($ls['section_title'] ?: 'دروس الكورس', ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <a href="?course_id=<?= $courseId ?>&lesson_id=<?= (int)$ls['id'] ?>&lesson_source=<?= urlencode($ls['lesson_source']) ?>" 
           class="playlist-item <?= (int)$ls['id'] === (int)$activeLesson['id'] && $ls['lesson_source'] === $activeLesson['lesson_source'] ? 'active' : '' ?>">
          <span class="item-num"><?= $idx + 1 ?></span>
          <span style="flex:1"><?= htmlspecialchars($ls['title']) ?></span>
          <?php if ((bool)$ls['is_preview']): ?>
            <span style="font-size:10px;background:#0d9488;color:#fff;padding:2px 6px;border-radius:4px;">معاينة مجانية</span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </aside>
</div>

<script>
(() => {
  const video = document.getElementById('mainVideo');
  const box = document.getElementById('videoBox');
  const lessonId = <?= (int)$activeLesson['id'] ?>;
  const lessonSource = <?= json_encode($activeLesson['lesson_source'], JSON_UNESCAPED_UNICODE) ?>;
  const initialPos = <?= (int)$savedPosition ?>;
  const seek = document.getElementById('videoSeek');
  const playButton = document.getElementById('playToggle');
  const centerPlay = document.getElementById('centerPlay');
  const playlistToggle = document.getElementById('playlistToggle');
  const playlist = document.querySelector('.sidebar-playlist');
  const playIcon = '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';
  const pauseIcon = '<svg viewBox="0 0 24 24"><path d="M6 5h4v14H6zm8 0h4v14h-4z"/></svg>';
  const fmt = seconds => {
    if (!Number.isFinite(seconds)) return '0:00';
    const n = Math.max(0, Math.floor(seconds));
    return `${Math.floor(n / 60)}:${String(n % 60).padStart(2, '0')}`;
  };

  playlistToggle?.addEventListener('click', () => playlist?.classList.toggle('mobile-open'));
  if (!video || video.hidden || !seek) return;

  const setPlaying = playing => {
    box.classList.toggle('is-playing', playing);
    centerPlay.hidden = playing;
    playButton.innerHTML = playing ? pauseIcon : playIcon;
    playButton.setAttribute('aria-label', playing ? 'إيقاف مؤقت' : 'تشغيل');
  };
  const togglePlayback = () => video.paused ? video.play().catch(() => {}) : video.pause();
  centerPlay.addEventListener('click', togglePlayback);
  playButton.addEventListener('click', togglePlayback);
  video.addEventListener('click', togglePlayback);
  video.addEventListener('play', () => { setPlaying(true); revealControls(); });
  video.addEventListener('pause', () => setPlaying(false));
  video.addEventListener('ended', () => setPlaying(false));
  video.addEventListener('loadedmetadata', () => {
    document.getElementById('durationTime').textContent = fmt(video.duration);
    if (initialPos > 0 && initialPos < video.duration) video.currentTime = initialPos;
  });
  video.addEventListener('timeupdate', () => {
    document.getElementById('currentTime').textContent = fmt(video.currentTime);
    const pct = video.duration ? (video.currentTime / video.duration) * 100 : 0;
    seek.value = String(Math.round(pct * 10));
    seek.style.setProperty('--seek', `${pct}%`);
  });
  seek.addEventListener('input', () => {
    if (video.duration) video.currentTime = video.duration * Number(seek.value) / 1000;
  });
  document.getElementById('backTen').addEventListener('click', () => { video.currentTime = Math.max(0, video.currentTime - 10); });
  document.getElementById('forwardTen').addEventListener('click', () => { video.currentTime = Math.min(video.duration || Infinity, video.currentTime + 10); });
  document.getElementById('volumeRange').addEventListener('input', event => { video.volume = Number(event.target.value); video.muted = video.volume === 0; });
  document.getElementById('volumeToggle').addEventListener('click', () => { video.muted = !video.muted; });
  document.getElementById('speedSelect').addEventListener('change', event => { video.playbackRate = Number(event.target.value); });
  document.getElementById('fullscreenToggle').addEventListener('click', async () => {
    try {
      if (document.fullscreenElement) await document.exitFullscreen();
      else await box.requestFullscreen();
    } catch (_) {}
  });
  box.addEventListener('keydown', event => {
    if (['INPUT','SELECT','BUTTON'].includes(event.target.tagName) && event.code !== 'Space') return;
    if (event.code === 'Space') { event.preventDefault(); togglePlayback(); }
    else if (event.code === 'ArrowRight') video.currentTime = Math.max(0, video.currentTime - 5);
    else if (event.code === 'ArrowLeft') video.currentTime = Math.min(video.duration || Infinity, video.currentTime + 5);
    else if (event.key.toLowerCase() === 'f') document.getElementById('fullscreenToggle').click();
  });

  let hideTimer;
  const revealControls = () => {
    box.classList.remove('controls-hidden');
    clearTimeout(hideTimer);
    if (!video.paused) hideTimer = setTimeout(() => box.classList.add('controls-hidden'), 2600);
  };
  box.addEventListener('mousemove', revealControls);
  box.addEventListener('touchstart', revealControls, { passive: true });
  box.addEventListener('mouseleave', () => { if (!video.paused) box.classList.add('controls-hidden'); });

  setInterval(() => {
    if (!video.paused && video.currentTime > 0) fetch('../courses/track.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ course_id: <?= $courseId ?>, lesson_id: lessonId, lesson_source: lessonSource, position_seconds: Math.floor(video.currentTime), is_completed: video.duration && video.currentTime / video.duration > 0.85 ? 1 : 0 })
    }).catch(() => {});
  }, 10000);

  const watermark = document.getElementById('watermark');
  setInterval(() => {
    watermark.style.top = `${Math.floor(Math.random() * 62) + 16}%`;
    watermark.style.left = `${Math.floor(Math.random() * 58) + 16}%`;
  }, 7000);
})();
</script>
</body>
</html>
