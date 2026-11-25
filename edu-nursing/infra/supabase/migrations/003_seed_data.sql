/* ====================================================================
   Edu Nursing – Seed Data
   Migration 003: Initial demo data (clearly marked as demo)
   ==================================================================== */

-- ============ DEFAULT SETTINGS ============
INSERT INTO settings (key, value) VALUES
  ('academy_name', '"Edu Nursing – Medical Education Academy"'),
  ('owner_name', '"د. خالد عبد الناصر محمد المرسي"'),
  ('owner_title', '"محاضر دولي معتمد – Certified International Lecturer"'),
  ('whatsapp_number', '"01289492477"'),
  ('payment_number', '"01061709999"'),
  ('currency', '"EGP"'),
  ('payment_methods', '["Vodafone Cash", "InstaPay"]'),
  ('payment_instructions', '"يرجى التحويل على الرقم 01061709999 عبر فودافون كاش أو إنستاباي ثم رفع صورة الإيصال"'),
  ('theme_primary', '"#006d5b"'),
  ('theme_secondary', '"#0a1f44"'),
  ('theme_accent', '"#2ecc71"'),
  ('logo_url', '""'),
  ('max_upload_size_mb', '100')
ON CONFLICT (key) DO NOTHING;

-- ============ CATEGORIES ============
INSERT INTO categories (name_ar, name_en, slug, sort_order) VALUES
  ('التمريض العام', 'General Nursing', 'general-nursing', 1),
  ('العناية المركزة', 'Intensive Care', 'intensive-care', 2),
  ('تمريض العمليات', 'Operating Room Nursing', 'or-nursing', 3),
  ('الحضانات', 'NICU Nursing', 'nicu-nursing', 4),
  ('التمريض النفسي', 'Psychiatric Nursing', 'psychiatric-nursing', 5),
  ('الطوارئ', 'Emergency Nursing', 'emergency-nursing', 6),
  ('الإدمان', 'Addiction Nursing', 'addiction-nursing', 7),
  ('المقابلات المهنية', 'Medical Interviews', 'medical-interviews', 8)
ON CONFLICT (slug) DO NOTHING;

-- ============ DEMO COURSES ============
-- ⚠️  These are demo/sample courses – not real paid content.

INSERT INTO courses (title, slug, description, marketing_description, objectives, price, discount_price, status, category_id, duration_hours, is_featured) VALUES
  (
    'أساسيات التمريض',
    'nursing-basics',
    'كورس شامل يغطي أساسيات مهنة التمريض من الألف إلى الياء. [بيانات تجريبية]',
    'ابدأ رحلتك المهنية في التمريض مع هذا الكورس الشامل',
    ARRAY['فهم أساسيات التمريض', 'التعرف على أدوات العمل', 'مهارات التواصل مع المرضى'],
    500.00, 350.00, 'published', 1, 25.0, TRUE
  ),
  (
    'العناية المركزة ICU',
    'icu-care',
    'كورس متقدم للعمل في وحدات العناية المركزة. [بيانات تجريبية]',
    'أتقن مهارات العمل في ICU مع خبراء متخصصين',
    ARRAY['قراءة الأجهزة الطبية', 'التعامل مع الحالات الحرجة', 'بروتوكولات ICU'],
    800.00, 600.00, 'published', 2, 40.0, TRUE
  ),
  (
    'تمريض العمليات',
    'or-nursing',
    'كورس متخصص في تمريض غرف العمليات. [بيانات تجريبية]',
    'تعلم كل ما تحتاج لمعرفته عن العمل في غرف العمليات',
    ARRAY['التعقيم والتطهير', 'أدوات العمليات', 'دور الممرض أثناء العمليات'],
    700.00, NULL, 'published', 3, 30.0, FALSE
  ),
  (
    'تمريض الحضانات NICU',
    'nicu-nursing',
    'كورس متخصص في العناية بحديثي الولادة. [بيانات تجريبية]',
    'احترف التمريض في وحدات حضانات الأطفال حديثي الولادة',
    ARRAY['العناية بحديثي الولادة', 'أجهزة الحضانات', 'التغذية والأدوية'],
    650.00, 500.00, 'published', 4, 35.0, TRUE
  ),
  (
    'التمريض النفسي',
    'psychiatric-nursing',
    'كورس في أسس التمريض النفسي والصحة النفسية. [بيانات تجريبية]',
    'افهم الصحة النفسية وطرق التعامل مع المرضى النفسيين',
    ARRAY['أساسيات الصحة النفسية', 'التواصل العلاجي', 'الأدوية النفسية'],
    450.00, NULL, 'draft', 5, 20.0, FALSE
  ),
  (
    'الطوارئ وأدوية الطوارئ',
    'emergency-nursing',
    'كورس شامل في تمريض الطوارئ وأهم الأدوية. [بيانات تجريبية]',
    'كن مستعدًا لأي حالة طارئة مع هذا الكورس المتخصص',
    ARRAY['بروتوكولات الطوارئ', 'أدوية الطوارئ', 'الإنعاش القلبي الرئوي'],
    600.00, 450.00, 'published', 6, 28.0, TRUE
  ),
  (
    'الإدمان',
    'addiction-nursing',
    'كورس في تمريض مرضى الإدمان وإعادة التأهيل. [بيانات تجريبية]',
    'تعلم كيفية التعامل مع مرضى الإدمان بأساليب علمية',
    ARRAY['أنواع الإدمان', 'مراحل العلاج', 'دور الممرض في إعادة التأهيل'],
    400.00, NULL, 'draft', 7, 18.0, FALSE
  ),
  (
    'أسئلة الانترفيو الطبي',
    'medical-interview-questions',
    'كورس يجهزك لاجتياز المقابلات الطبية بنجاح. [بيانات تجريبية]',
    'استعد لمقابلات العمل الطبية مع أهم الأسئلة والإجابات النموذجية',
    ARRAY['أسئلة المقابلات الشائعة', 'كيفية الإجابة', 'نصائح للنجاح'],
    300.00, 200.00, 'published', 8, 10.0, FALSE
  )
ON CONFLICT (slug) DO NOTHING;

-- ============ DEMO MODULES & LESSONS ============
-- For the "Nursing Basics" course (id = 1, assumed)

INSERT INTO modules (course_id, title, sort_order) VALUES
  (1, 'الوحدة الأولى: مقدمة في التمريض', 1),
  (1, 'الوحدة الثانية: مهارات التمريض الأساسية', 2),
  (1, 'الوحدة الثالثة: التواصل مع المرضى', 3)
ON CONFLICT DO NOTHING;

INSERT INTO lessons (module_id, title, type, duration_seconds, is_free, sort_order) VALUES
  (1, 'ما هو التمريض؟ [درس تجريبي]', 'video', 600, TRUE, 1),
  (1, 'تاريخ مهنة التمريض [درس تجريبي]', 'video', 900, FALSE, 2),
  (1, 'أخلاقيات المهنة [درس تجريبي]', 'text', 0, FALSE, 3),
  (2, 'قياس العلامات الحيوية [درس تجريبي]', 'video', 1200, FALSE, 1),
  (2, 'إعطاء الحقن [درس تجريبي]', 'video', 1500, FALSE, 2),
  (3, 'مهارات التواصل الفعال [درس تجريبي]', 'video', 800, TRUE, 1)
ON CONFLICT DO NOTHING;

-- ============ DEMO BOOKS ============
-- ⚠️  These are demo/sample books – not real products.

INSERT INTO books (title_ar, title_en, slug, description, objectives, type, price, discount_price, stock, is_featured) VALUES
  (
    'كتاب أساسيات تمريض عملي ونظري',
    'Practical & Theoretical Nursing Basics',
    'nursing-basics-book',
    'كتاب شامل يغطي الجوانب العملية والنظرية لأساسيات التمريض. [بيانات تجريبية]',
    ARRAY['فهم الأساسيات النظرية', 'التطبيق العملي', 'مراجع علمية'],
    'both', 250.00, 200.00, 50, TRUE
  ),
  (
    'كتاب العناية المركزة',
    'Intensive Care Nursing Guide',
    'icu-guide-book',
    'دليل شامل للعمل في وحدات العناية المركزة. [بيانات تجريبية]',
    ARRAY['بروتوكولات ICU', 'قراءة الأجهزة', 'أدوية العناية المركزة'],
    'digital', 180.00, NULL, 0, TRUE
  ),
  (
    'كتاب أشهر أسئلة الانترفيو',
    'Top Medical Interview Questions',
    'interview-questions-book',
    'أهم الأسئلة المتوقعة في مقابلات التمريض والتخصصات الطبية. [بيانات تجريبية]',
    ARRAY['الأسئلة الشائعة', 'الإجابات النموذجية', 'نصائح عملية'],
    'print', 120.00, 90.00, 100, FALSE
  )
ON CONFLICT (slug) DO NOTHING;

-- ============ HOMEPAGE SECTIONS ============
INSERT INTO homepage_sections (section_key, title, content, is_visible, sort_order) VALUES
  ('hero', 'Hero Section', '{"heading": "Edu Nursing – أكاديمية التعليم الطبي", "subheading": "تعلم، تطور، تميز في عالم التمريض والرعاية الصحية", "cta_text": "ابدأ رحلتك الآن", "cta_link": "/courses"}', TRUE, 1),
  ('about', 'عن المؤسس', '{"name": "د. خالد عبد الناصر محمد المرسي", "title": "محاضر دولي معتمد", "bio": "متخصص في التعليم والتدريب الطبي والتمريض مع سنوات من الخبرة في تأهيل الكوادر الطبية.", "image": ""}', TRUE, 2),
  ('featured_courses', 'الكورسات المميزة', '{"max_display": 4}', TRUE, 3),
  ('featured_books', 'أحدث الكتب', '{"max_display": 3}', TRUE, 4),
  ('stats', 'إحصائيات الأكاديمية', '{"students": 0, "courses": 0, "books": 0, "instructors": 0}', TRUE, 5),
  ('features', 'مميزاتنا', '{"items": ["محتوى تعليمي متخصص", "محاضرون معتمدون دوليًا", "شهادات إتمام", "دعم فني متواصل"]}', TRUE, 6),
  ('testimonials', 'آراء الطلاب', '{"reviews": []}', TRUE, 7),
  ('banners', 'الإعلانات', '{"items": []}', TRUE, 8),
  ('cta', 'سجل الآن', '{"heading": "انضم إلى آلاف الطلاب", "text": "ابدأ رحلتك في عالم التمريض المتخصص", "button_text": "سجل مجانًا", "button_link": "/register"}', TRUE, 9),
  ('contact', 'تواصل معنا', '{"whatsapp": "01289492477", "email": "", "address": ""}', TRUE, 10)
ON CONFLICT (section_key) DO NOTHING;

-- ============ POLICY PAGES ============
INSERT INTO policy_pages (slug, title, content) VALUES
  ('terms', 'الشروط والأحكام', 'هذه صفحة الشروط والأحكام. يرجى تعديل المحتوى من لوحة الإدارة. [بيانات تجريبية]'),
  ('privacy', 'سياسة الخصوصية', 'هذه صفحة سياسة الخصوصية. يرجى تعديل المحتوى من لوحة الإدارة. [بيانات تجريبية]'),
  ('refund', 'سياسة الاسترجاع', 'هذه صفحة سياسة الاسترجاع. يرجى تعديل المحتوى من لوحة الإدارة. [بيانات تجريبية]')
ON CONFLICT (slug) DO NOTHING;

-- ============ INTEGRATIONS (stubs) ============
INSERT INTO integrations (service_name, config, is_connected) VALUES
  ('zoom', '{"note": "أضف بيانات Zoom OAuth من لوحة الإدارة"}', FALSE),
  ('smtp', '{"note": "أضف بيانات SMTP لإرسال البريد الإلكتروني"}', FALSE),
  ('sms', '{"note": "أضف بيانات مزود الرسائل القصيرة"}', FALSE),
  ('payment_gateway', '{"note": "أضف بيانات بوابة الدفع (Paymob أو غيرها) عند الجاهزية"}', FALSE),
  ('cloud_storage', '{"note": "أضف بيانات التخزين السحابي (S3 أو ما يعادله)"}', FALSE)
ON CONFLICT (service_name) DO NOTHING;
