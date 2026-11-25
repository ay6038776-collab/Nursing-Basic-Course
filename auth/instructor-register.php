<?php
require_once __DIR__ . '/../api/config/session.php';
nbc_start_session('instructor');

if (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'instructor') {
    header('Location: ../instructor.html');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طلب انضمام محاضر | Edu Nursing Academy</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
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
            max-width: 540px;
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
            <h1 class="brand-title">انضمام <span>محاضر / مدرب سريري</span></h1>
            <p style="color:var(--text-sub);font-size:12.5px;margin:4px 0 0;">سجل بياناتك وخبراتك للانضمام إلى هيئة التدريس</p>
        </div>

        <div class="role-switch-container">
            <button type="button" class="role-tab-btn" id="studentRole">🎓 طالب</button>
            <button type="button" class="role-tab-btn active" id="instructorRole">👨‍🏫 محاضر</button>
        </div>

        <div id="message" class="message-toast" role="alert"></div>

        <form id="registerForm">
            <div class="form-group-custom">
                <label class="form-label" for="full_name">الاسم واللقب الأكاديمي *</label>
                <input class="modern-input" type="text" id="full_name" name="full_name" maxlength="150" placeholder="مثال: د. سارة محمود إبراهيم" required>
            </div>

            <div class="form-row">
                <div class="form-group-custom">
                    <label class="form-label" for="age">السن (بالسنوات) *</label>
                    <input class="modern-input" type="number" id="age" name="age" min="20" max="85" placeholder="32" required>
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
                    <label class="form-label" for="email">البريد الإلكتروني (لتلقي الإشعارات) *</label>
                    <input class="modern-input" type="email" id="email" name="email" maxlength="190" placeholder="doctor@example.com" required>
                </div>

                <div class="form-group-custom">
                    <label class="form-label" for="phone">الهاتف / واتساب *</label>
                    <input class="modern-input" type="tel" id="phone" name="phone" maxlength="30" placeholder="01XXXXXXXXX" required>
                </div>
            </div>

            <div class="form-group-custom">
                <label class="form-label" for="specialization">التخصص التمريضي / الطبي الدقيق *</label>
                <select class="modern-input" id="specialization" name="specialization" required>
                    <option value="">اختر التخصص التمريضي...</option>
                    <option value="تمريض العناية المركزة والطوارئ (ICU & ER)">تمريض العناية المركزة والطوارئ (ICU & ER)</option>
                    <option value="تمريض باطني وجراحي">تمريض باطني وجراحي</option>
                    <option value="تمريض الأطفال وحديثي الولادة (NICU)">تمريض الأطفال وحديثي الولادة (NICU)</option>
                    <option value="تمريض النساء والتوليد">تمريض النساء والتوليد</option>
                    <option value="تمريض صحة المجتمع والرعاية الأولية">تمريض صحة المجتمع والرعاية الأولية</option>
                    <option value="مكافحة العدوى والجودة الصحية">مكافحة العدوى والجودة الصحية</option>
                    <option value="فارماكولوجي وإعطاء الأدوية">فارماكولوجي وإعطاء الأدوية السريرية</option>
                    <option value="أخرى">تخصص طبي / تمريضي آخر</option>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group-custom">
                    <label class="form-label" for="experience_years">سنوات الخبرة العملية *</label>
                    <input class="modern-input" type="number" id="experience_years" name="experience_years" min="0" max="60" placeholder="مثال: 5" required>
                </div>

                <div class="form-group-custom">
                    <label class="form-label" for="workplace">جهة ومكان العمل الحالي *</label>
                    <input class="modern-input" type="text" id="workplace" name="workplace" maxlength="190" placeholder="مثال: مستشفى قصر العيني" required>
                </div>
            </div>

            <div class="form-group-custom">
                <label class="form-label" for="experience">تفاصيل الخبرة العملية والمناصب السابقة</label>
                <textarea class="modern-input" id="experience" name="experience" rows="2" placeholder="الأقسام التي عملت بها (مشرف عناية، مدرب سريري، إلخ)..."></textarea>
            </div>

            <div class="form-group-custom">
                <label class="form-label" for="cv_url">رابط السيرة الذاتية (Google Drive / LinkedIn / PDF Link)</label>
                <input class="modern-input" type="url" id="cv_url" name="cv_url" placeholder="https://drive.google.com/... أو رابط السيرة الذاتية">
            </div>

            <div class="form-group-custom">
                <label class="form-label" for="bio">نبذة تعريفية (Bio)</label>
                <textarea class="modern-input" id="bio" name="bio" rows="2" placeholder="نبذة مختصرة تظهر للطلاب في ملفك..."></textarea>
            </div>

            <div class="form-group-custom" style="position:relative">
                <label class="form-label" for="password">كلمة المرور *</label>
                <input class="modern-input" type="password" id="password" name="password" minlength="8" placeholder="••••••••" required style="padding-left:40px">
                <button type="button" class="toggle-password" id="togglePassword">👁</button>
            </div>

            <button type="submit" class="btn-modern-submit" id="submitButton">
                <span>إرسال طلب الانضمام كمحاضر</span>
                <span>←</span>
            </button>
        </form>

        <div class="auth-footer-links">
            لديك حساب بالفعل؟ <a href="login.php">تسجيل الدخول</a>
        </div>
    </div>
</div>

<script>
    const form = document.getElementById('registerForm');
    const message = document.getElementById('message');
    const submitButton = document.getElementById('submitButton');
    const passwordInput = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');

    togglePassword.addEventListener('click', () => {
        const isPassword = passwordInput.type === 'password';
        passwordInput.type = isPassword ? 'text' : 'password';
        togglePassword.textContent = isPassword ? '🙈' : '👁';
    });

    document.getElementById('studentRole').addEventListener('click', function () {
        window.location.href = 'register.php';
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        message.className = 'message-toast';
        message.textContent = '';
        message.style.display = 'none';
        submitButton.disabled = true;
        submitButton.innerHTML = '<span>جارٍ إرسال الطلب وإشعار البريد الإلكتروني...</span>';

        const formData = new FormData(form);
        const payload = {
            full_name: formData.get('full_name').trim(),
            email: formData.get('email').trim(),
            phone: formData.get('phone').trim(),
            age: formData.get('age') ? parseInt(formData.get('age')) : null,
            gender: formData.get('gender'),
            specialization: formData.get('specialization'),
            experience_years: formData.get('experience_years') ? parseInt(formData.get('experience_years')) : null,
            workplace: formData.get('workplace').trim(),
            experience: formData.get('experience').trim(),
            cv_url: formData.get('cv_url').trim(),
            bio: formData.get('bio').trim(),
            password: formData.get('password')
        };

        try {
            const response = await fetch('../api/auth/register-instructor.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                message.className = 'message-toast error';
                message.textContent = result.message || 'تعذّر إرسال الطلب.';
                message.style.display = 'block';
                submitButton.disabled = false;
                submitButton.innerHTML = '<span>إرسال طلب الانضمام كمحاضر</span> <span>←</span>';
                return;
            }

            message.className = 'message-toast success';
            message.textContent = result.message;
            message.style.display = 'block';
            form.reset();

            setTimeout(function () {
                window.location.href = 'login.php';
            }, 3000);

        } catch (error) {
            message.className = 'message-toast error';
            message.textContent = 'تعذّر الاتصال بالخادم. حاول مرة أخرى.';
            message.style.display = 'block';
            submitButton.disabled = false;
            submitButton.innerHTML = '<span>إرسال طلب الانضمام كمحاضر</span> <span>←</span>';
        }
    });
</script>

<link rel="stylesheet" href="../asset/medical-themes.css?v=20261120-1">
<link rel="stylesheet" href="../asset/appearance-system.css?v=20261120-1">
<script src="../asset/js/medical-theme.js?v=20261120-1" defer></script>
</body>
</html>
