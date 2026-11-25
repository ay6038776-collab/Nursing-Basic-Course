<?php
declare(strict_types=1);

// Unified Auth: Check if user already logged into any portal
require_once __DIR__ . '/../api/config/session.php';

// Check admin session
try {
    nbc_start_session('admin');
    if (!empty($_SESSION['user_id']) && (($_SESSION['user_role'] ?? '') === 'admin')) {
        header('Location: ../admin.html');
        exit;
    }
    session_write_close();
} catch (Throwable $e) {}

// Check instructor session
try {
    nbc_start_session('instructor');
    if (!empty($_SESSION['user_id']) && (($_SESSION['user_role'] ?? '') === 'instructor')) {
        header('Location: ../instructor.html');
        exit;
    }
    session_write_close();
} catch (Throwable $e) {}

// Check student session
try {
    nbc_start_session('student');
    if (!empty($_SESSION['user_id']) && (($_SESSION['user_role'] ?? '') === 'student')) {
        header('Location: ../student.html');
        exit;
    }
    session_write_close();
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول الموحد | Edu Nursing – المنصة الطبية التمريضية</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Alexandria:wght@300;400;500;600;700;800;900&family=Almarai:wght@300;400;700;800&family=Cairo:wght@300;400;500;600;700;800;900&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Noto+Sans+Arabic:wght@300;400;500;600;700;800;900&family=Readex+Pro:wght@300;400;500;600;700&family=Tajawal:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../asset/style.css">
    <style>
        :root {
            --primary: #0d9488;
            --primary-hover: #0f766e;
            --primary-light: rgba(13, 148, 136, 0.12);
            --bg-body: #0b1329;
            --card-bg: rgba(17, 24, 39, 0.94);
            --border-glow: rgba(13, 148, 136, 0.25);
            --text-main: #f8fafc;
            --text-sub: #94a3b8;
        }

        html, body {
            height: 100%;
            overflow: hidden; /* إزالة السكرول نهائياً */
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background: radial-gradient(circle at 50% 20%, #132742 0%, #070d1a 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-main);
            position: relative;
            box-sizing: border-box;
            padding: 16px;
        }

        /* Medical ambient glow effects */
        .ambient-shape-1 {
            position: absolute;
            width: 450px;
            height: 450px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(13, 148, 136, 0.22) 0%, rgba(13, 148, 136, 0) 70%);
            top: -100px;
            right: -100px;
            pointer-events: none;
            animation: pulse 8s ease-in-out infinite alternate;
        }
        .ambient-shape-2 {
            position: absolute;
            width: 400px;
            height: 400px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(56, 189, 248, 0.16) 0%, rgba(56, 189, 248, 0) 70%);
            bottom: -80px;
            left: -80px;
            pointer-events: none;
            animation: pulse 10s ease-in-out infinite alternate;
        }

        @keyframes pulse {
            0% { transform: scale(0.95) translate(0, 0); opacity: 0.6; }
            100% { transform: scale(1.1) translate(20px, 20px); opacity: 0.9; }
        }

        .auth-container {
            width: 100%;
            max-width: 430px;
            position: relative;
            z-index: 10;
        }

        .auth-glass-card {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.7), 0 0 25px var(--border-glow);
            border-radius: 22px;
            padding: 26px 28px;
            position: relative;
            box-sizing: border-box;
        }

        .brand-header {
            text-align: center;
            margin-bottom: 16px;
        }

        .brand-icon-wrapper {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #0d9488 0%, #0284c7 100%);
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 20px -4px rgba(13, 148, 136, 0.5);
            margin-bottom: 8px;
        }

        .brand-title {
            font-size: 22px;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.5px;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .brand-title span {
            color: #2dd4bf;
        }

        .portal-tabs {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.06);
            padding: 3px;
            border-radius: 12px;
            margin-bottom: 18px;
        }

        .portal-tab {
            font-size: 12px;
            font-weight: 700;
            padding: 6px 3px;
            text-align: center;
            border-radius: 9px;
            color: var(--text-sub);
            transition: all 0.2s ease;
            user-select: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .portal-tab.active {
            background: rgba(13, 148, 136, 0.22);
            color: #2dd4bf;
            border: 1px solid rgba(45, 212, 191, 0.35);
        }

        .form-label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 6px;
        }

        .input-group-custom {
            position: relative;
            margin-bottom: 14px;
        }

        .input-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 16px;
            pointer-events: none;
        }

        .toggle-password {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #64748b;
            cursor: pointer;
            padding: 0;
            font-size: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .toggle-password:hover {
            color: #2dd4bf;
        }

        .modern-input {
            width: 100%;
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 10px 38px 10px 38px;
            color: #ffffff;
            font-size: 13.5px;
            font-family: inherit;
            box-sizing: border-box;
            transition: all 0.2s ease;
        }

        .modern-input:focus {
            outline: none;
            border-color: #2dd4bf;
            background: rgba(15, 23, 42, 0.9);
            box-shadow: 0 0 0 3px rgba(45, 212, 191, 0.15);
        }

        .btn-modern-login {
            width: 100%;
            background: linear-gradient(135deg, #0d9488 0%, #0284c7 100%);
            color: #ffffff;
            border: none;
            border-radius: 11px;
            padding: 12px;
            font-size: 14.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 8px 18px -4px rgba(13, 148, 136, 0.5);
            margin-top: 8px;
        }

        .btn-modern-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 24px -4px rgba(13, 148, 136, 0.6);
            background: linear-gradient(135deg, #0f766e 0%, #0369a1 100%);
        }

        .btn-modern-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .message-toast {
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 12.5px;
            font-weight: 600;
            margin-bottom: 14px;
            display: none;
            line-height: 1.4;
            animation: fadeIn 0.3s ease;
        }

        .message-toast.error {
            display: block;
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .message-toast.success {
            display: block;
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #6ee7b7;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .auth-footer-links {
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12.5px;
        }

        .auth-footer-links a {
            color: #2dd4bf;
            text-decoration: none;
            font-weight: 700;
            transition: color 0.2s;
        }

        .auth-footer-links a:hover {
            color: #5eead4;
            text-decoration: underline;
        }

        .home-back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--text-sub);
            text-decoration: none;
            font-size: 12px;
            margin-bottom: 12px;
            transition: color 0.2s;
        }

        .home-back-link:hover {
            color: #ffffff;
        }

        .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: #ffffff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
    <link rel="stylesheet" href="../asset/medical-themes.css?v=20261117-3">
    <link rel="stylesheet" href="../asset/appearance-system.css?v=20261120-1">
</head>

<body>

<div class="ambient-shape-1"></div>
<div class="ambient-shape-2"></div>

<div class="auth-container">
    <a href="../index.html" class="home-back-link">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        العودة إلى الصفحة الرئيسية
    </a>

    <div class="auth-glass-card">
        <div class="brand-header">
            <div class="brand-icon-wrapper">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
                </svg>
            </div>
            <h1 class="brand-title">Edu <span>Nursing</span></h1>
            <p style="color:var(--text-sub);font-size:12.5px;margin:4px 0 0;">بوابة الدخول الموحد للطلاب، المحاضرين، والإدارة</p>
        </div>

        <div class="portal-tabs">
            <div class="portal-tab active" id="tabStudent">🎓 طالب</div>
            <div class="portal-tab" id="tabInstructor">👨‍🏫 محاضر</div>
            <div class="portal-tab" id="tabAdmin"></div>
        </div>

        <div id="message" class="message-toast" role="alert"></div>

        <form id="loginForm" method="post" action="#" novalidate>
            <div class="input-group-custom">
                <label class="form-label" for="email">البريد الإلكتروني</label>
                <div style="position:relative">
                    <input
                        class="modern-input"
                        type="email"
                        id="email"
                        name="email"
                        autocomplete="email"
                        placeholder="doctor@edunursing.com"
                        required
                    >
                    <span class="input-icon">✉️</span>
                </div>
            </div>

            <div class="input-group-custom">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                    <label class="form-label" for="password" style="margin:0">كلمة المرور</label>
                    <span style="font-size:11px;color:var(--text-sub)">تشفير آمن 🔒</span>
                </div>
                <div style="position:relative">
                    <input
                        class="modern-input"
                        type="password"
                        id="password"
                        name="password"
                        autocomplete="current-password"
                        placeholder="••••••••"
                        required
                    >
                    <span class="input-icon">🔑</span>
                    <button type="button" class="toggle-password" id="togglePassword" aria-label="إظهار / إخفاء كلمة المرور">
                        👁
                    </button>
                </div>
            </div>

            <button type="submit" id="submitBtn" class="btn-modern-login">
                <span>دخول آمن إلى حسابك</span>
                <span id="btnIcon">←</span>
            </button>
        </form>

        <div class="auth-footer-links">
            <div>
                طالب جديد؟ <a href="register.php">سجل الآن</a>
            </div>
            <div>
                انضم كمحاضر؟ <a href="instructor-register.php">تقديم طلب</a>
            </div>
        </div>
    </div>
</div>

<script>
const form = document.getElementById('loginForm');
const message = document.getElementById('message');
const submitBtn = document.getElementById('submitBtn');
const btnIcon = document.getElementById('btnIcon');
const emailInput = document.getElementById('email');
const passwordInput = document.getElementById('password');
const togglePassword = document.getElementById('togglePassword');

togglePassword.addEventListener('click', () => {
    const isPassword = passwordInput.type === 'password';
    passwordInput.type = isPassword ? 'text' : 'password';
    togglePassword.textContent = isPassword ? '🙈' : '👁';
});

emailInput.addEventListener('input', () => {
    const val = emailInput.value.toLowerCase();
    document.querySelectorAll('.portal-tab').forEach(t => t.classList.remove('active'));
    if (val.includes('admin')) {
        document.getElementById('tabAdmin')?.classList.add('active');
    } else if (val.includes('instructor') || val.includes('dr.') || val.includes('doctor')) {
        document.getElementById('tabInstructor')?.classList.add('active');
    } else {
        document.getElementById('tabStudent')?.classList.add('active');
    }
});

form.addEventListener('submit', async function (event) {
    event.preventDefault();

    message.className = 'message-toast';
    message.textContent = '';
    message.style.display = 'none';

    const email = emailInput.value.trim();
    const password = passwordInput.value;

    if (!email || !password) {
        message.className = 'message-toast error';
        message.textContent = 'يرجى ملء جميع الحقول المطلوبة.';
        message.style.display = 'block';
        return;
    }

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner"></span> <span>جارٍ التحقق وتوجيهك...</span>';
    const controller = new AbortController();
    const loginTimeout = setTimeout(() => controller.abort(), 15000);

    try {
        const response = await fetch('../api/auth/login.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ email, password }),
            signal: controller.signal
        });

        const result = await response.json().catch(() => null);
        if (!result) throw new Error('استجابة الخادم غير صالحة. تأكد من تشغيل Apache وMySQL في XAMPP.');

        if (!response.ok || !result.success) {
            message.className = 'message-toast error';
            message.textContent = result.message || 'تعذّر تسجيل الدخول. تحقق من صحة البريد أو كلمة المرور.';
            message.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<span>دخول آمن إلى حسابك</span> <span>←</span>';
            return;
        }

        message.className = 'message-toast success';
        message.textContent = result.message || 'تم تسجيل الدخول بنجاح! جارٍ تحويلك للوحة التحكم...';
        message.style.display = 'block';

        document.querySelectorAll('.portal-tab').forEach(t => t.classList.remove('active'));
        if (result.portal === 'admin') document.getElementById('tabAdmin')?.classList.add('active');
        else if (result.portal === 'instructor') document.getElementById('tabInstructor')?.classList.add('active');
        else document.getElementById('tabStudent')?.classList.add('active');

        setTimeout(() => {
            if (result.redirect_url) {
                window.location.href = '../' + result.redirect_url;
            } else if (result.portal === 'admin') {
                window.location.href = '../admin.html';
            } else if (result.portal === 'instructor') {
                window.location.href = '../instructor.html';
            } else {
                window.location.href = '../student.html';
            }
        }, 500);

    } catch (error) {
        console.error('Login error details:', error);
        message.className = 'message-toast error';
        message.textContent = error.name === 'AbortError'
            ? 'تأخر رد الخادم. تأكد من أن Apache وMySQL يعملان في XAMPP، ثم حاول مجددًا.'
            : (error.message || 'تعذّر الاتصال بالخادم. تأكد من تشغيل Apache وMySQL في XAMPP ثم حاول مجددًا.');
        message.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<span>دخول آمن إلى حسابك</span> <span>←</span>';
    } finally {
        clearTimeout(loginTimeout);
    }
});
</script>

<script src="../asset/js/medical-theme.js?v=20261120-1" defer></script>
</body>
</html>
