<?php
require_once __DIR__ . '/../api/config/session.php';
nbc_start_session('student');

if (isset($_SESSION['user_id'])) {
    header('Location: ' . (($_SESSION['user_role'] ?? '') === 'instructor' ? '../instructor.html' : (($_SESSION['user_role'] ?? '') === 'admin' ? '../admin.html' : '../student.html')));
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إنشاء حساب طالب جديد | Edu Nursing</title>
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

        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background: radial-gradient(circle at 50% 15%, #132742 0%, #070d1a 100%);
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-main);
            position: relative;
            box-sizing: border-box;
            padding: 30px 16px;
        }

        .ambient-shape-1 {
            position: fixed;
            width: 450px;
            height: 450px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(13, 148, 136, 0.22) 0%, rgba(13, 148, 136, 0) 70%);
            top: -100px;
            right: -100px;
            pointer-events: none;
        }
        .ambient-shape-2 {
            position: fixed;
            width: 400px;
            height: 400px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(56, 189, 248, 0.16) 0%, rgba(56, 189, 248, 0) 70%);
            bottom: -80px;
            left: -80px;
            pointer-events: none;
        }

        .auth-container {
            width: 100%;
            max-width: 500px;
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
            padding: 30px 28px;
            position: relative;
            box-sizing: border-box;
        }

        .brand-header {
            text-align: center;
            margin-bottom: 18px;
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
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .brand-title span {
            color: #2dd4bf;
        }

        .role-switch-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.06);
            padding: 3px;
            border-radius: 12px;
            margin-bottom: 18px;
        }

        .role-tab-btn {
            font-size: 13px;
            font-weight: 700;
            padding: 8px 4px;
            text-align: center;
            border-radius: 9px;
            color: var(--text-sub);
            border: none;
            background: none;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .role-tab-btn.active {
            background: rgba(13, 148, 136, 0.25);
            color: #2dd4bf;
            border: 1px solid rgba(45, 212, 191, 0.35);
        }

        .form-row {
            display: flex;
            gap: 12px;
        }
        .form-row .form-group-custom {
            flex: 1;
        }

        .form-label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 6px;
        }

        .form-group-custom {
            position: relative;
            margin-bottom: 14px;
        }

        .modern-input {
            width: 100%;
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 10px 14px;
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

        .toggle-password {
            position: absolute;
            left: 12px;
            top: 36px;
            background: none;
            border: none;
            color: #64748b;
            cursor: pointer;
            padding: 0;
            font-size: 15px;
        }
        .toggle-password:hover {
            color: #2dd4bf;
        }

        .btn-modern-submit {
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
            margin-top: 10px;
        }

        .btn-modern-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 24px -4px rgba(13, 148, 136, 0.6);
            background: linear-gradient(135deg, #0f766e 0%, #0369a1 100%);
        }

        .btn-modern-submit:disabled {
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

        .code-verify-box {
            display: none;
            background: rgba(13, 148, 136, 0.08);
            border: 2px dashed rgba(45, 212, 191, 0.4);
            border-radius: 14px;
            padding: 22px;
            text-align: center;
            margin-top: 10px;
        }

        .code-input {
            letter-spacing: 8px;
            font-size: 24px;
            text-align: center;
            font-weight: 800;
            background: rgba(15, 23, 42, 0.8);
            color: #2dd4bf;
        }

        .auto-code-pill {
            display: inline-block;
            background: rgba(2, 132, 199, 0.2);
            color: #38bdf8;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12.5px;
            margin-bottom: 12px;
            border: 1px solid rgba(56, 189, 248, 0.3);
            font-weight: 600;
        }

        .auth-footer-links {
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            text-align: center;
            font-size: 13px;
            color: var(--text-sub);
        }

        .auth-footer-links a {
            color: #2dd4bf;
            text-decoration: none;
            font-weight: 700;
        }

        .home-back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--text-sub);
            text-decoration: none;
            font-size: 12px;
            margin-bottom: 12px;
        }
        .home-back-link:hover {
            color: #ffffff;
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
            <h1 class="brand-title">إنشاء حساب <span>طالب جديد</span></h1>
            <p style="color:var(--text-sub);font-size:12.5px;margin:4px 0 0;">انضم لمنصة التعليم والتدريب السريري التمريضي</p>
        </div>

        <div class="role-switch-container">
            <button type="button" class="role-tab-btn active" id="studentRole">🎓 طالب</button>
            <button type="button" class="role-tab-btn" id="instructorRole">👨‍🏫 محاضر</button>
        </div>

        <div id="message" class="message-toast" role="alert"></div>

        <form id="registerForm">
            <div class="form-group-custom">
                <label class="form-label" for="full_name">الاسم بالكامل (ثلاثي أو رباعي) *</label>
                <input class="modern-input" type="text" id="full_name" name="full_name" maxlength="150" placeholder="مثال: أحمد يحيى العراقي" required>
            </div>

            <div class="form-row">
                <div class="form-group-custom">
                    <label class="form-label" for="age">السن (بالسنوات) *</label>
                    <input class="modern-input" type="number" id="age" name="age" min="14" max="90" placeholder="20" required>
                </div>

                <div class="form-group-custom">
                    <label class="form-label" for="gender">النوع *</label>
                    <select class="modern-input" id="gender" name="gender" required>
                        <option value="">اختر...</option>
                        <option value="male">ذكر</option>
                        <option value="female">أنثى</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group-custom">
                    <label class="form-label" for="email">البريد الإلكتروني *</label>
                    <input class="modern-input" type="email" id="email" name="email" maxlength="190" placeholder="name@example.com" required>
                </div>

                <div class="form-group-custom">
                    <label class="form-label" for="phone">الهاتف / واتساب *</label>
                    <input class="modern-input" type="tel" id="phone" name="phone" maxlength="30" placeholder="01012345678" required>
                </div>
            </div>

            <div class="form-group-custom">
                <label class="form-label" for="extra_info">الكلية / المعهد / المؤهل الدراسي</label>
                <input class="modern-input" type="text" id="extra_info" name="extra_info" placeholder="مثال: كلية التمريض - جامعة القاهرة">
            </div>

            <div class="form-group-custom" style="position:relative">
                <label class="form-label" for="password">كلمة المرور *</label>
                <input class="modern-input" type="password" id="password" name="password" minlength="8" placeholder="••••••••" required style="padding-left:40px">
                <button type="button" class="toggle-password" id="togglePassword">👁</button>
            </div>

            <button type="submit" class="btn-modern-submit" id="submitButton">
                <span>إنشاء الحساب واستلام كود الدخول</span>
                <span>←</span>
            </button>
        </form>

        <!-- صندوق كود التفعيل التلقائي -->
        <div id="verifyBox" class="code-verify-box">
            <div class="auto-code-pill" id="autoCodeBadge">💡 تم توليد كود التحقق السريع</div>
            <h3 style="margin:0 0 6px;color:#2dd4bf;font-size:17px">تأكيد كود الحساب</h3>
            <p style="font-size:12.5px;color:var(--text-sub);margin-bottom:12px">
                تم إرسال الكود للبريد الإلكتروني وتم إدراجه تلقائياً لسرعة دخولك:
            </p>
            <div class="form-group-custom">
                <input class="modern-input code-input" type="text" id="verifyCodeInput" maxlength="6" placeholder="000000">
            </div>
            <button type="button" class="btn-modern-submit" id="confirmCodeBtn">
                <span>تأكيد الكود والدخول إلى لوحة الطالب</span>
                <span>🚀</span>
            </button>
        </div>

        <div class="auth-footer-links">
            لديك حساب بالفعل؟ <a href="login.php">تسجيل الدخول</a>
        </div>
    </div>
</div>

<script>
    const form = document.getElementById('registerForm');
    const message = document.getElementById('message');
    const submitButton = document.getElementById('submitButton');
    const verifyBox = document.getElementById('verifyBox');
    const verifyCodeInput = document.getElementById('verifyCodeInput');
    const confirmCodeBtn = document.getElementById('confirmCodeBtn');
    const autoCodeBadge = document.getElementById('autoCodeBadge');
    const passwordInput = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');
    let registeredEmail = '';

    togglePassword.addEventListener('click', () => {
        const isPassword = passwordInput.type === 'password';
        passwordInput.type = isPassword ? 'text' : 'password';
        togglePassword.textContent = isPassword ? '🙈' : '👁';
    });

    document.getElementById('instructorRole').addEventListener('click', function () {
        window.location.href = 'instructor-register.php';
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        message.className = 'message-toast';
        message.textContent = '';
        message.style.display = 'none';

        const formData = new FormData(form);
        const payload = {
            full_name: formData.get('full_name').trim(),
            email: formData.get('email').trim(),
            phone: formData.get('phone').trim(),
            age: formData.get('age') ? parseInt(formData.get('age')) : null,
            gender: formData.get('gender'),
            extra_info: formData.get('extra_info').trim(),
            password: formData.get('password')
        };

        registeredEmail = payload.email;
        submitButton.disabled = true;
        submitButton.innerHTML = '<span>جارٍ تسجيل الحساب وتوليد الكود...</span>';

        try {
            const response = await fetch('../api/auth/register.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                message.className = 'message-toast error';
                message.textContent = result.message || 'تعذّر إنشاء الحساب.';
                message.style.display = 'block';
                submitButton.disabled = false;
                submitButton.innerHTML = '<span>إنشاء الحساب واستلام كود الدخول</span> <span>←</span>';
                return;
            }

            message.className = 'message-toast success';
            message.textContent = result.message;
            message.style.display = 'block';

            form.style.display = 'none';
            verifyBox.style.display = 'block';

            if (result.auto_code) {
                verifyCodeInput.value = result.auto_code;
                autoCodeBadge.innerHTML = `✨ كود التحقق الخاص بك هو: <strong>${result.auto_code}</strong>`;
            }

        } catch (error) {
            message.className = 'message-toast error';
            message.textContent = 'تعذّر الاتصال بالخادم. حاول مرة أخرى.';
            message.style.display = 'block';
            submitButton.disabled = false;
            submitButton.innerHTML = '<span>إنشاء الحساب واستلام كود الدخول</span> <span>←</span>';
        }
    });

    confirmCodeBtn.addEventListener('click', async function() {
        const code = verifyCodeInput.value.trim();
        if (!code || code.length !== 6) {
            alert('يرجى كتابة كود التحقق المكون من 6 أرقام.');
            return;
        }

        confirmCodeBtn.disabled = true;
        confirmCodeBtn.innerHTML = '<span>جارٍ التحقق وتفعيل حسابك...</span>';

        try {
            const res = await fetch('../api/auth/verify-code.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: registeredEmail, code: code })
            });
            const data = await res.json();

            if (!res.ok || !data.success) {
                message.className = 'message-toast error';
                message.textContent = data.message || 'الكود غير صحيح.';
                message.style.display = 'block';
                confirmCodeBtn.disabled = false;
                confirmCodeBtn.innerHTML = '<span>تأكيد الكود والدخول إلى لوحة الطالب</span> <span>🚀</span>';
                return;
            }

            message.className = 'message-toast success';
            message.textContent = data.message;
            message.style.display = 'block';

            setTimeout(() => {
                window.location.href = '../' + (data.redirect_url || 'student.html');
            }, 1000);

        } catch (err) {
            message.className = 'message-toast error';
            message.textContent = 'حدث خطأ في الاتصال أثناء التحقق من الكود.';
            message.style.display = 'block';
            confirmCodeBtn.disabled = false;
            confirmCodeBtn.innerHTML = '<span>تأكيد الكود والدخول إلى لوحة الطالب</span> <span>🚀</span>';
        }
    });
</script>

<script src="../asset/js/medical-theme.js?v=20261120-1" defer></script>
</body>
</html>
