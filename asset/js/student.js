'use strict';

/**
 * =========================================================================
 * Edu Nursing — Official Student Dashboard Engine (asset/js/student.js)
 * Medical Education Academy • Ultra-Modern Student Experience
 * =========================================================================
 */

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({
  '&': '&amp;',
  '<': '&lt;',
  '>': '&gt;',
  '"': '&quot;',
  "'": '&#39;'
}[c]));

const $ = id => document.getElementById(id);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

const ROOT = './';

let user = null;
let csrfToken = '';
let dashboardData = null;
let currentTab = 'overview';
let currentLang = localStorage.getItem('nbc_lang') || 'ar';

// Multi-Language Dictionary
const I18N = {
  ar: {
    portal_title: 'بوابة الطالب الطبية',
    sidebar_group_main: 'الرئيسية والملخص',
    sidebar_group_edu: 'التعليم والمسارات',
    sidebar_group_eval: 'التقييم والشهادات',
    sidebar_group_fin: 'المالية والدعم',
    sidebar_status: 'حساب طالب معتمد ونشط',
    nav_overview: 'الرئيسية والملخص',
    nav_my_courses: 'كورساتي المسجل بها',
    nav_explore: 'تصفح الكورسات المتاحة',
    nav_meetings: 'محاضرات Zoom المباشرة',
    nav_quizzes: 'الاختبارات السريرية',
    nav_grades: 'النتائج وسجل الدرجات',
    nav_notes: 'المذكرات والمراجع PDF',
    nav_certificates: 'الشهادات المعتمدة',
    nav_orders: 'الطلبات والمدفوعات',
    nav_support: 'الدعم الفني والمساعدة',
    nav_profile: 'الملف الشخصي والبطاقة',

    breadcrumb_root: 'بوابة الطالب',
    search_placeholder: 'بحث شامل في الكورسات، المحاضرات، المراجع... (Ctrl+K)',
    search_quick_title: 'الأقسام السريعة',
    refresh_btn: 'تحديث البيانات',
    refresh_success: 'تم تحديث كافة البيانات بنجاح من قاعدة البيانات.',
    my_badge: 'بطاقتي الطبية 🪪',
    role_student: 'طالب معتمد',
    logout_btn: 'تسجيل الخروج',
    logout_confirm: 'هل تريد تسجيل الخروج من حسابك الطلابي؟',

    hero_badge: 'EDU NURSING • المسار الأكاديمي السريري',
    hero_welcome_prefix: 'أهلاً بك يا دكتور',
    hero_desc: 'تابع مسيرتك التعليمية السريرية، شاهد أحدث المحاضرات المباشرة، وتدرب على أسئلة امتحانات المزاولة والبورد.',
    hero_btn_courses: 'كورساتي ودراستي 📚',
    hero_btn_id: 'بطاقتي والـ QR 🪪',

    kpi_enrolled: 'الكورسات المسجل بها',
    kpi_enrolled_sub: 'دبلومات ومسارات مفعلة ↗',
    kpi_certs: 'الشهادات المعتمدة',
    kpi_certs_sub: 'شهادة موثقة بكود تحقق ↗',
    kpi_quizzes: 'الاختبارات السريرية',
    kpi_quizzes_sub: 'اختبار متاح للتقييم ↗',
    kpi_meetings: 'محاضرات زوم القادمة',
    kpi_meetings_sub: 'جلسة تفاعلية مباشرة ↗',

    continue_title: 'متابعة التعلم السريري ▶',
    continue_desc: 'استكمل تدريبك من آخر درس توقفت عنده بنقرة واحدة',
    next_zoom_title: 'أقرب جلسة تفاعلية زوم 📡',
    next_zoom_desc: 'بث مباشر لمناقشة الحالات السريرية مع الدكتور',

    enrolled_tab_title: 'كورساتي ودراستي السريرية 📚',
    enrolled_tab_desc: 'الكورسات والدبلومات المسجل بها رسمياً مع متابعة نسبة إنجازك لكل مسار',
    explore_tab_title: 'الكورسات والدبلومات المتاحة للشراء 🛒',
    explore_tab_desc: 'طوّر مهاراتك الطبية واشترك في الدبلومات المعتمدة بإشراف د. خالد عبد الناصر',
    meetings_tab_title: 'جدول المحاضرات واللقاءات المباشرة عبر Zoom 📡',
    meetings_tab_desc: 'مواعيد المحاضرات التفاعلية لكورساتك مع روابط الانضمام المباشر',
    quizzes_tab_title: 'الاختبارات والتقييم السريري 📝',
    quizzes_tab_desc: 'اختبر معلوماتك وتدرب على محاكاة امتحانات مزاولة المهنة والبورد',
    grades_tab_title: 'النتائج والدرجات وسجل المحاولات 🎯',
    grades_tab_desc: 'سجل تفصيلي لكافة الاختبارات التي خضتها ودرجاتك ونسبة النجاح',
    notes_tab_title: 'المذكرات والمراجع والكتب السريرية 📖',
    notes_tab_desc: 'كتب ومذكرات وملخصات المحاضرات المعتمدة والمتاحة لك للتحميل والقراءة',
    certs_tab_title: 'الشهادات المستحقة والمعتمدة 📜',
    certs_tab_desc: 'شهادات إتمام الكورسات والدبلومات الطبية مع كود التوثيق والرقم التعريفي',
    orders_tab_title: 'سجل الطلبات والمدفوعات والفواتير 💳',
    orders_tab_desc: 'متابعة حالة مدفوعاتك (Vodafone Cash / InstaPay) وفواتير الشراء',
    support_tab_title: 'تذاكر الدعم الفني الطبي 💬',
    support_tab_desc: 'متابعة استفساراتك ومشكلاتك مع الفريق التقني والأكاديمي',
    support_new_ticket: '+ تذكرة جديدة',
    support_direct_title: 'تواصل مباشر مع الدعم الأكاديمي',
    support_direct_desc: 'نحن بجانبك على مدار 24 ساعة للإجابة عن كل استفسار حول الكورسات والشهادات.',
    support_whatsapp: '💬 محادثة واتساب فورية: 01289492477',
    support_call: '📞 اتصال هاتفي: 01289492477',
    support_pay_numbers: 'أرقام تحويل الرسوم والاشتراكات:',

    prof_title: 'بيانات الطالب الأكاديمية 👤',
    prof_desc: 'الاسم الكامل، رقم الهاتف، والبيانات المسجلة في الشهادات',
    prof_avatar_title: 'الصورة الشخصية للحساب',
    prof_avatar_sub: 'تظهر الصورة في شهاداتك وبطاقتك الأكاديمية',
    prof_upload_btn: '📷 رفع وتغيير الصورة',
    prof_lbl_name: 'الاسم الكامل (كما يظهر في الشهادات)',
    prof_lbl_email: 'البريد الإلكتروني (محمي)',
    prof_lbl_phone: 'رقم الهاتف / الواتساب',
    prof_btn_save: 'حفظ تعديلات الملف الشخصي',
    prof_pwd_title: 'تغيير كلمة المرور 🔒',
    prof_lbl_oldpwd: 'كلمة المرور الحالية',
    prof_lbl_newpwd: 'كلمة المرور الجديدة',
    prof_lbl_confirmpwd: 'تأكيد كلمة المرور الجديدة',
    prof_btn_updatepwd: 'تحديث كلمة المرور',
    prof_id_badge: 'بطاقة الهوية الأكاديمية الطبية',
    prof_id_sub: 'رمز الاستجابة السريعة (QR) الخاص بك للتحقق السريري، حضور المحاضرات، وتوثيق الساعات المعتمدة.',

    // Table columns
    th_meeting_title: 'عنوان المحاضرة',
    th_course: 'الكورس المرتبط',
    th_meeting_date: 'الموعد والتاريخ',
    th_status: 'الحالة',
    th_join: 'الانضمام للبث',
    th_quiz_title: 'عنوان الاختبار',
    th_quiz_questions: 'عدد الأسئلة',
    th_quiz_duration: 'المدة المقررة',
    th_action: 'إجراء',
    th_attempt: 'المحاولة',
    th_grade: 'الدرجة والنسبة',
    th_result: 'التقييم النهائي',
    th_date: 'تاريخ الإرسال',
    th_review_details: 'التفاصيل',
    th_order_num: 'رقم الطلب',
    th_order_amount: 'المبلغ الإجمالي',
    th_order_method: 'وسيلة الدفع',
    th_order_receipt: 'الإيصال',
    th_ticket_num: 'رقم التذكرة',
    th_ticket_subject: 'الموضوع',
    th_ticket_cat: 'التصنيف',

    // Modals
    modal_close: '✕ إغلاق',
    modal_cancel: 'إلغاء',
    modal_qr_sub: 'بطاقة التحقق السريري الرقمية',
    modal_qr_note: 'أظهر هذا الكود للمشرف السريري لإثبات حضورك الساعات المعتمدة.',
    modal_qr_print: 'طباعة البطاقة 🖨️',
    modal_quiz_title: 'تقديم الاختبار السريري',
    modal_quiz_note: 'يرجى الإجابة عن كافة الأسئلة قبل الضغط على تسليم',
    modal_quiz_submit: 'تسليم الإجابات واعتماد النتيجة 📝',
    modal_cert_badge: 'شهادة إتمام معتمدة رسمياً',
    modal_cert_text1: 'تشهد أكاديمية Edu Nursing بأن المتدرب/ـة:',
    modal_cert_text2: 'قد أتم بنجاح كافة الساعات التدريبية والاختبارات السريرية المقررة.',
    modal_cert_num_lbl: 'رقم التوثيق المعتمد:',
    modal_cert_date_lbl: 'تاريخ الإصدار:',
    modal_cert_print: 'طباعة الشهادة 🖨️',
    modal_checkout_title: 'تأكيد الاشتراك والطلب 🛒',
    modal_checkout_amount: 'المبلغ المطلوب سداده:',
    modal_checkout_methods: 'طرق السداد المعتمدة للأكاديمية:',
    modal_checkout_method: 'طريقة الدفع المستخدمة',
    modal_checkout_tx: 'الرقم المحوّل منه أو كود المعاملة',
    modal_checkout_receipt: 'صورة إيصال التحويل (اختياري)',
    modal_checkout_wa: 'إرسال عبر واتساب 💬',
    modal_checkout_submit: 'تأكيد وتسجيل الطلب 💳',
    modal_ticket_title: 'فتح تذكرة دعم فني جديدة 💬',
    modal_ticket_sub: 'اكتب استفسارك وسيقوم المشرف السريري بالرد عليك',
    modal_ticket_subject_lbl: 'موضوع التذكرة',
    modal_ticket_cat_lbl: 'التصنيف',
    modal_ticket_desc_lbl: 'تفاصيل الرسالة',
    modal_ticket_send: 'إرسال التذكرة 🚀',
    modal_reply_send: 'إرسال',
    modal_notifs_title: 'الإشعارات والتنبيهات 🔔',
    modal_review_title: 'تقييم الكورس والمحاضر ⭐',
    modal_review_rating_lbl: 'درجة التقييم',
    modal_review_comment_lbl: 'رأيك وتجربتك في الكورس والتدريب السريري',
    modal_review_submit: 'إرسال التقييم للمراجعة ⭐',
    modal_order_title: 'تفاصيل الطلب والفاتورة 📄',

    // Dynamic Render Strings
    last_studied: 'آخر كورس درسته',
    current_lesson: 'الدرس الحالي:',
    continue_last_lesson: 'متابعة التعلم من آخر درس ▶',
    no_enrolled_courses: 'لست مسجلاً في أي كورس تدريبي حالياً',
    no_enrolled_sub: 'اشترك في إحدى دبلومات التمريض السريري المعتمدة للبدء في المشاهدة واستلام الشهادة.',
    browse_courses_btn: 'تصفح الكورسات المتاحة',
    upcoming_zoom_card: 'محاضرة زوم القادمة 📡',
    join_zoom_now: 'انضمام الآن عبر Zoom 🚀',
    no_zoom_today: 'لا توجد محاضرات زوم مجدولة اليوم.',
    completed_progress: 'مكتمل',
    enrolled_active_badge: 'مُسجل ومفعّل ✅',
    lessons_count_text: 'درس',
    minutes_text: 'دقيقة',
    progress_ratio: 'نسبة الإنجاز',
    continue_learning: 'متابعة التعلم ▶',
    review_btn: 'تقييم',
    review_pending: 'قيد المراجعة',
    review_rejected: 'مرفوض، يمكنك التعديل',
    all_enrolled_msg: 'أنت مشترك في جميع الكورسات المتاحة حالياً! مبارك لك.',
    currency_egp: 'ج.م',
    enroll_now_btn: 'اشترك الآن 🛒',
    zoom_live_now: 'مباشر الآن 🔴',
    zoom_scheduled: 'مجدولة ⏱️',
    no_zoom_meetings: 'لا توجد محاضرات زوم مباشرة مجدولة حالياً لكورساتك.',
    no_quizzes: 'لا توجد اختبارات سريرية منشورة لكورساتك حالياً.',
    quiz_passed: 'تم الاجتياز بنجاح 🎉',
    quiz_available: 'متاح للتقديم 📝',
    quiz_retake: 'إعادة الاختبار',
    quiz_start: 'بدء الاختبار الآن',
    quiz_attempts_exhausted: 'اكتملت المحاولات',
    quiz_result_pending: 'النتيجة قيد المراجعة أو النشر',
    exam_question: 'السؤال',
    exam_answered: 'تمت الإجابة',
    questions_count: 'سؤال',
    no_grades: 'لم تقدم أي اختبارات بعد. ابدأ أول اختبار من تبويب الاختبارات.',
    grade_passed: 'ناجح (Passed) ✅',
    grade_failed: 'راسب (Retake) ✕',
    attempt_prefix: 'المحاولة #',
    out_of: 'من',
    no_notes: 'لا توجد مذكرات أو كتب منشورة حالياً.',
    note_available: 'متاحة لك للقراءة ✅',
    note_accredited: 'مذكرة معتمدة 📖',
    pages_count: 'صفحة',
    note_in_account: 'مفعلة بحسابك',
    read_download_pdf: 'قراءة وتحميل PDF 📥',
    order_note_btn: 'طلب المذكرة 🛒',
    no_certs: 'لا توجد شهادات مستحقة حتى الآن',
    no_certs_sub: 'أكمل مشاهدة كافة دروس الكورس واجتز الاختبار السريري النهائي لإصدار شهادتك المعتمدة تلقائياً.',
    cert_verified: 'معتمدة وموثقة',
    granted_to: 'ممنوحة للمتدرب:',
    cert_code: 'كود التوثيق:',
    issued_date: 'تاريخ الإصدار:',
    view_cert_btn: 'عرض الشهادة المعتمدة 📜',
    no_orders: 'لا توجد طلبات أو فواتير مسجلة بعد.',
    order_pending: 'قيد المراجعة ⏳',
    order_completed: 'مكتمل ومفعل ✅',
    order_rejected: 'مرفوض ✕',
    order_pending_pay: 'بانتظار السداد ⏳',
    view_details_btn: 'عرض التفاصيل 📄',
    no_tickets: 'لا توجد تذاكر دعم فني مفتوحة حالياً. هل تواجه مشكلة؟ اضغط "+ تذكرة جديدة".',
    view_reply_btn: 'عرض والرد 💬'
  },
  en: {
    portal_title: 'Student Medical Portal',
    sidebar_group_main: 'Main & Overview',
    sidebar_group_edu: 'Education & Paths',
    sidebar_group_eval: 'Assessments & Certs',
    sidebar_group_fin: 'Finance & Support',
    sidebar_status: 'Certified Active Student',
    nav_overview: 'Overview & Summary',
    nav_my_courses: 'My Enrolled Courses',
    nav_explore: 'Explore Courses',
    nav_meetings: 'Live Zoom Lectures',
    nav_quizzes: 'Clinical Quizzes',
    nav_grades: 'Grades & Results',
    nav_notes: 'Books & Notes (PDF)',
    nav_certificates: 'Earned Certificates',
    nav_orders: 'Orders & Payments',
    nav_support: 'Help & Tech Support',
    nav_profile: 'Profile & Student ID',

    breadcrumb_root: 'Student Portal',
    search_placeholder: 'Search courses, lectures, study notes... (Ctrl+K)',
    search_quick_title: 'Quick Jump',
    refresh_btn: 'Refresh Data',
    refresh_success: 'All dashboard data updated successfully from database.',
    my_badge: 'My Medical ID 🪪',
    role_student: 'Certified Student',
    logout_btn: 'Logout',
    logout_confirm: 'Are you sure you want to log out from student portal?',

    hero_badge: 'EDU NURSING • Clinical Academic Track',
    hero_welcome_prefix: 'Welcome, Dr.',
    hero_desc: 'Track your clinical journey, attend live interactive lectures, and simulate board licensure exams.',
    hero_btn_courses: 'My Courses 📚',
    hero_btn_id: 'My ID & QR 🪪',

    kpi_enrolled: 'Enrolled Courses',
    kpi_enrolled_sub: 'Active programs & diplomas ↗',
    kpi_certs: 'Earned Certificates',
    kpi_certs_sub: 'Verified credential badges ↗',
    kpi_quizzes: 'Clinical Quizzes',
    kpi_quizzes_sub: 'Available assessments ↗',
    kpi_meetings: 'Upcoming Zoom Sessions',
    kpi_meetings_sub: 'Live interactive lectures ↗',

    continue_title: 'Resume Clinical Learning ▶',
    continue_desc: 'Pick up exactly where you left off with one click',
    next_zoom_title: 'Next Live Zoom Session 📡',
    next_zoom_desc: 'Live broadcast discussing clinical cases with Dr. Khaled',

    enrolled_tab_title: 'My Enrolled Courses & Diplomas 📚',
    enrolled_tab_desc: 'Officially enrolled academic programs with real-time completion tracking',
    explore_tab_title: 'Explore & Purchase Courses 🛒',
    explore_tab_desc: 'Upgrade your clinical skills with accredited diplomas supervised by Dr. Khaled',
    meetings_tab_title: 'Live Zoom Lecture Schedule 📡',
    meetings_tab_desc: 'Upcoming interactive live sessions with direct access links',
    quizzes_tab_title: 'Clinical Quizzes & Assessments 📝',
    quizzes_tab_desc: 'Test your knowledge and practice board licensure exam questions',
    grades_tab_title: 'Results, Grades & Attempts Log 🎯',
    grades_tab_desc: 'Detailed record of all quiz attempts, passing rates, and scores',
    notes_tab_title: 'Clinical Books, Notes & References 📖',
    notes_tab_desc: 'Accredited lecture summaries, references, and textbooks available to read and download',
    certs_tab_title: 'Certified Credentials & Diplomas 📜',
    certs_tab_desc: 'Official certificates of completion with QR verification and identification code',
    orders_tab_title: 'Orders, Payments & Billing Invoices 💳',
    orders_tab_desc: 'Track payment status (Vodafone Cash, InstaPay) and enrollment invoices',
    support_tab_title: 'Medical Support Tickets 💬',
    support_tab_desc: 'Submit inquiries and resolve clinical or technical issues with our team',
    support_new_ticket: '+ New Ticket',
    support_direct_title: 'Direct Academic Support',
    support_direct_desc: 'We are available 24/7 to answer all your questions about courses and certificates.',
    support_whatsapp: '💬 Instant WhatsApp Chat: 01289492477',
    support_call: '📞 Phone Call: 01289492477',
    support_pay_numbers: 'Official Payment Transfer Numbers:',

    prof_title: 'Academic Student Profile 👤',
    prof_desc: 'Full name, phone number, and data registered on your certificates',
    prof_avatar_title: 'Account Avatar',
    prof_avatar_sub: 'Appears on your certificates and academic ID card',
    prof_upload_btn: '📷 Upload & Change Photo',
    prof_lbl_name: 'Full Name (as printed on certificates)',
    prof_lbl_email: 'Email Address (protected)',
    prof_lbl_phone: 'Phone / WhatsApp Number',
    prof_btn_save: 'Save Profile Changes',
    prof_pwd_title: 'Change Password 🔒',
    prof_lbl_oldpwd: 'Current Password',
    prof_lbl_newpwd: 'New Password',
    prof_lbl_confirmpwd: 'Confirm New Password',
    prof_btn_updatepwd: 'Update Password',
    prof_id_badge: 'Clinical Medical Student ID',
    prof_id_sub: 'Your personal QR code for clinical verification, session attendance, and credit hours.',

    // Table columns
    th_meeting_title: 'Session Title',
    th_course: 'Related Course',
    th_meeting_date: 'Date & Time',
    th_status: 'Status',
    th_join: 'Join Stream',
    th_quiz_title: 'Quiz Title',
    th_quiz_questions: 'Questions',
    th_quiz_duration: 'Duration',
    th_action: 'Action',
    th_attempt: 'Attempt',
    th_grade: 'Score & Ratio',
    th_result: 'Final Result',
    th_date: 'Date',
    th_review_details: 'Details',
    th_order_num: 'Order #',
    th_order_amount: 'Total Amount',
    th_order_method: 'Payment Method',
    th_order_receipt: 'Receipt',
    th_ticket_num: 'Ticket #',
    th_ticket_subject: 'Subject',
    th_ticket_cat: 'Category',

    // Modals
    modal_close: '✕ Close',
    modal_cancel: 'Cancel',
    modal_qr_sub: 'Digital Clinical Verification Card',
    modal_qr_note: 'Present this QR code to the clinical supervisor for credit hours verification.',
    modal_qr_print: 'Print ID Card 🖨️',
    modal_quiz_title: 'Take Clinical Quiz',
    modal_quiz_note: 'Please answer all questions before submitting',
    modal_quiz_submit: 'Submit Answers & Calculate Grade 📝',
    modal_cert_badge: 'Officially Accredited Certificate of Completion',
    modal_cert_text1: 'Edu Nursing Academy certifies that the trainee:',
    modal_cert_text2: 'has successfully completed all clinical training hours and assessments.',
    modal_cert_num_lbl: 'Credential ID:',
    modal_cert_date_lbl: 'Issued Date:',
    modal_cert_print: 'Print Certificate 🖨️',
    modal_checkout_title: 'Confirm Order & Enrollment 🛒',
    modal_checkout_amount: 'Total Amount Due:',
    modal_checkout_methods: 'Official Academy Payment Methods:',
    modal_checkout_method: 'Payment Method Used',
    modal_checkout_tx: 'Sender Phone or Transaction ID',
    modal_checkout_receipt: 'Transfer Receipt Screenshot (optional)',
    modal_checkout_wa: 'Send via WhatsApp 💬',
    modal_checkout_submit: 'Confirm & Place Order 💳',
    modal_ticket_title: 'Open New Support Ticket 💬',
    modal_ticket_sub: 'Write your question and our clinical team will reply promptly',
    modal_ticket_subject_lbl: 'Ticket Subject',
    modal_ticket_cat_lbl: 'Category',
    modal_ticket_desc_lbl: 'Message Details',
    modal_ticket_send: 'Submit Ticket 🚀',
    modal_reply_send: 'Send Reply',
    modal_notifs_title: 'Notifications & Alerts 🔔',
    modal_review_title: 'Rate Course & Instructor ⭐',
    modal_review_rating_lbl: 'Rating Score',
    modal_review_comment_lbl: 'Your clinical experience & feedback',
    modal_review_submit: 'Submit review for approval ⭐',
    modal_order_title: 'Order Details & Invoice 📄',

    // Dynamic Render Strings
    last_studied: 'Last Enrolled Course',
    current_lesson: 'Current Lesson:',
    continue_last_lesson: 'Resume From Last Lesson ▶',
    no_enrolled_courses: 'You are not enrolled in any course yet',
    no_enrolled_sub: 'Enroll in one of our accredited clinical diplomas to start learning and get certified.',
    browse_courses_btn: 'Browse Available Courses',
    upcoming_zoom_card: 'Next Live Zoom 📡',
    join_zoom_now: 'Join Now on Zoom 🚀',
    no_zoom_today: 'No Zoom lectures scheduled for today.',
    completed_progress: 'completed',
    enrolled_active_badge: 'Enrolled & Active ✅',
    lessons_count_text: 'lessons',
    minutes_text: 'min',
    progress_ratio: 'Progress Ratio',
    continue_learning: 'Continue Learning ▶',
    review_btn: 'Review',
    review_pending: 'Under review',
    review_rejected: 'Rejected, you can revise',
    all_enrolled_msg: 'You are enrolled in all currently available courses! Congratulations.',
    currency_egp: 'EGP',
    enroll_now_btn: 'Enroll Now 🛒',
    zoom_live_now: 'Live Now 🔴',
    zoom_scheduled: 'Scheduled ⏱️',
    no_zoom_meetings: 'No live Zoom lectures currently scheduled for your courses.',
    no_quizzes: 'No clinical quizzes currently published for your courses.',
    quiz_passed: 'Passed Successfully 🎉',
    quiz_available: 'Available to Take 📝',
    quiz_retake: 'Retake Quiz',
    quiz_start: 'Start Quiz Now',
    quiz_attempts_exhausted: 'Attempts used',
    quiz_result_pending: 'Result pending review or release',
    exam_question: 'Question',
    exam_answered: 'Answered',
    questions_count: 'questions',
    no_grades: 'No completed quiz attempts yet. Start your first quiz from the Quizzes tab.',
    grade_passed: 'Passed ✅',
    grade_failed: 'Retake Needed ✕',
    attempt_prefix: 'Attempt #',
    out_of: 'out of',
    no_notes: 'No study notes or books published at this time.',
    note_available: 'Available to Read ✅',
    note_accredited: 'Accredited Note 📖',
    pages_count: 'pages',
    note_in_account: 'Active in Account',
    read_download_pdf: 'Read & Download PDF 📥',
    order_note_btn: 'Order Book 🛒',
    no_certs: 'No earned certificates yet',
    no_certs_sub: 'Complete all course lessons and pass the final clinical quiz to automatically receive your accredited certificate.',
    cert_verified: 'Accredited & Verified',
    granted_to: 'Granted to:',
    cert_code: 'Credential ID:',
    issued_date: 'Issued Date:',
    view_cert_btn: 'View Certificate 📜',
    no_orders: 'No registered orders or invoices yet.',
    order_pending: 'Pending Review ⏳',
    order_completed: 'Completed & Active ✅',
    order_rejected: 'Rejected ✕',
    order_pending_pay: 'Awaiting Payment ⏳',
    view_details_btn: 'View Details 📄',
    no_tickets: 'No open support tickets. Need help? Click "+ New Ticket".',
    view_reply_btn: 'View & Reply 💬'
  }
};

const STUDENT_I18N_TRANSLATIONS = Object.fromEntries(Object.keys(I18N.ar)
  .filter(key => typeof I18N.ar[key] === 'string' && typeof I18N.en[key] === 'string')
  .map(key => [I18N.ar[key], I18N.en[key]]));
const t = (key, fallback) => (I18N[currentLang]?.[key] ?? fallback ?? key);

/* -------------------------------------------------------------------------
   1) Central Fetch & API Helpers
   ------------------------------------------------------------------------- */
async function requestJSON(url, options = {}) {
  const method = (options.method || 'GET').toUpperCase();
  const headers = { ...(options.headers || {}) };

  if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method) && csrfToken) {
    headers['X-CSRF-Token'] = csrfToken;
  }

  const response = await fetch(url, {
    credentials: 'same-origin',
    cache: 'no-store',
    ...options,
    headers
  });

  const data = await response.json().catch(() => null);

  if (!data) {
    throw new Error(`استجابة الخادم غير صالحة (HTTP ${response.status}).`);
  }

  if (data.csrf_token) {
    csrfToken = data.csrf_token;
  }

  if (response.status === 401) {
    location.href = 'auth/login.php';
    throw new Error('انتهت الجلسة. سجّل الدخول مرة أخرى.');
  }

  if (!response.ok || !data.success) {
    throw new Error(data.message || `حدث خطأ في الخادم (${response.status}).`);
  }

  return data;
}

async function studentApi(action, body = {}) {
  return requestJSON(`${ROOT}api/student/api.php?a=${encodeURIComponent(action)}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  });
}

function fileToBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

function notify(message, type = 'success') {
  const box = $('globalAlert');
  if (!box) return;
  box.textContent = message;
  box.className = `st-alert st-alert-${type}`;
  box.hidden = false;
  window.scrollTo({ top: 0, behavior: 'smooth' });
  setTimeout(() => { box.hidden = true; }, 5000);
}

/* -------------------------------------------------------------------------
   2) Language & Theme Systems
   ------------------------------------------------------------------------- */
function setLanguage(lang) {
  currentLang = lang === 'en' ? 'en' : 'ar';
  if (localStorage.getItem('nbc_lang') !== currentLang) localStorage.setItem('nbc_lang', currentLang);
  document.documentElement.setAttribute('lang', currentLang);
  document.documentElement.setAttribute('dir', currentLang === 'ar' ? 'rtl' : 'ltr');
  document.title = currentLang === 'en' ? 'Student Academic Portal | Edu Nursing' : 'بوابة الطالب الأكاديمية | Edu Nursing';

  const dict = I18N[currentLang];
  $$('[data-i18n]').forEach(el => {
    const key = el.dataset.i18n;
    if (dict[key]) {
      if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
        el.placeholder = dict[key];
      } else {
        el.textContent = dict[key];
      }
    }
  });

  // Direct phrase translation map for any non-tagged elements
  const STUDENT_TEXT_MAP = {
    'الرئيسية': 'Dashboard',
    'لوحة التحكم الأكاديمية': 'Academic Dashboard',
    'كورساتي': 'My Courses',
    'المسارات التدريبية المسجل بها': 'Enrolled Training Tracks',
    'الجدول والمحاضرات': 'Schedule & Classes',
    'محاضرات الزوم والبث المباشر': 'Live Classes & Zoom',
    'الاختبارات': 'Quizzes',
    'الواجبات والتكليفات': 'Assignments',
    'شهاداتي': 'Certificates',
    'الشهادات والاعتمادات الصادرة': 'Issued Certificates',
    'سجل المدفوعات': 'Payments Log',
    'الفواتير والاشتراكات': 'Invoices & Subscriptions',
    'الدعم الأكاديمي': 'Academic Support',
    'الأسئلة الشائعة والمساعدة': 'FAQ & Help Center',
    'تعديل الملف': 'Edit Profile',
    'البيانات الشخصية': 'Personal Details',
    'تحديث البيانات': 'Refresh Data',
    'بطاقتي الطبية 🪪': 'My Medical ID 🪪',
    'تسجيل الخروج': 'Logout',
    'الوضع الليلي': 'Dark Mode',
    'الوضع النهاري': 'Light Mode',
    'إجمالي الكورسات': 'Total Courses',
    'الدروس المنجزة': 'Completed Lessons',
    'متوسط التقدم': 'Average Progress',
    'الشهادات الصادرة': 'Certificates',
    'الساعات المعتمدة': 'Credit Hours',
    'المحاضرات القادمة': 'Upcoming Classes',
    'عرض التفاصيل': 'View Details',
    'متابعة التعلم ←': 'Continue Learning ←',
    'بدء الكورس ←': 'Start Course ←',
    'مكتمل': 'Completed',
    'قيد الدراسة': 'In Progress',
    'نشط': 'Active',
    'معلّق': 'Pending',
    'مؤكد': 'Confirmed',
    'ملغي': 'Cancelled',
    'ناجح': 'Passed',
    'راسب': 'Failed',
    'حفظ التعديلات': 'Save Changes',
    'إغلاق': 'Close'
  };

  if (currentLang === 'en') {
    $('button, h1, h2, h3, h4, th, .label, .st-stat-label, .st-item-text, .st-pill, .badge, small').forEach(el => {
      if (el.children.length === 0) {
        const txt = el.textContent.trim();
        if (STUDENT_TEXT_MAP[txt]) {
          if (!el.dataset.origText) el.dataset.origText = txt;
          el.textContent = STUDENT_TEXT_MAP[txt];
        }
      }
    });
  } else {
    $$('[data-orig-text]').forEach(el => {
      el.textContent = el.dataset.origText;
      delete el.dataset.origText;
    });
  }

  const searchInput = $('globalSearchInput');
  if (searchInput) {
    searchInput.placeholder = t('search_placeholder');
  }

  const langLabel = $('langToggleLabel');
  if (langLabel) {
    langLabel.textContent = currentLang === 'ar' ? 'EN' : 'AR';
  }

  const allTranslations = { ...STUDENT_I18N_TRANSLATIONS, ...STUDENT_TEXT_MAP };
  window.PlatformLanguage?.setDictionary(allTranslations);
  window.PlatformLanguage?.apply(document, currentLang, allTranslations);

  const currentBreadcrumb = $('breadcrumbCurrent');
  if (currentBreadcrumb) {
    const activeBtn = $(`.st-nav-item[data-tab="${currentTab}"]`);
    if (activeBtn) {
      currentBreadcrumb.textContent = activeBtn.querySelector('.st-item-text')?.textContent || currentTab;
    }
  }

  // Re-render all dynamic data if already loaded
  if (dashboardData) {
    renderAll();
  }
}

function toggleLanguage() {
  setLanguage(currentLang === 'ar' ? 'en' : 'ar');
}

window.addEventListener('platform:languagechange', event => {
  const next = event.detail === 'en' ? 'en' : 'ar';
  if (currentLang !== next) setLanguage(next);
});
window.addEventListener('platform:languageruntime-ready', () => setLanguage(currentLang), { once: true });

function initTheme() {
  function apply(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.style.removeProperty('background-color');
    localStorage.setItem('nbc_student_theme', theme);
    localStorage.setItem('nbc_theme', theme);

    const sun = $('themeSunIcon');
    const moon = $('themeMoonIcon');
    if (sun && moon) {
      sun.hidden = (theme !== 'dark');
      moon.hidden = (theme === 'dark');
    }
  }

  const saved = localStorage.getItem('nbc_theme') || localStorage.getItem('nbc_student_theme') || 'light';
  apply(saved);

  window.addEventListener('storage', event => {
    if (event.key === 'nbc_theme' && (event.newValue === 'dark' || event.newValue === 'light')) apply(event.newValue);
  });

  const toggleBtn = $('themeToggleBtn');
  if (toggleBtn) {
    toggleBtn.onclick = () => {
      const cur = document.documentElement.getAttribute('data-theme') || 'light';
      apply(cur === 'dark' ? 'light' : 'dark');
    };
  }
}

function initGlobalSearch() {
  const searchInput = $('globalSearchInput');
  const searchDropdown = $('searchDropdown');
  if (!searchInput) return;

  // Shortcut Ctrl+K / Cmd+K
  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      searchInput.focus();
      if (searchDropdown) searchDropdown.hidden = false;
    }
  });

  searchInput.addEventListener('focus', () => {
    if (searchDropdown) searchDropdown.hidden = false;
  });

  document.addEventListener('click', e => {
    if (searchDropdown && !e.target.closest('#topbarSearch')) {
      searchDropdown.hidden = true;
    }
  });

  $$('[data-search-tab]').forEach(btn => {
    btn.addEventListener('click', () => {
      const tab = btn.dataset.searchTab;
      if (tab) {
        switchTab(tab);
        if (searchDropdown) searchDropdown.hidden = true;
        searchInput.value = '';
      }
    });
  });

  searchInput.addEventListener('input', () => {
    const query = searchInput.value.trim().toLowerCase();
    if (!query) {
      if (searchDropdown) searchDropdown.hidden = false;
      return;
    }
  });
}

function initRefreshBtn() {
  const refreshBtn = $('refreshBtn');
  if (!refreshBtn) return;
  refreshBtn.addEventListener('click', async () => {
    refreshBtn.classList.add('spinning');
    try {
      await loadDashboard();
      notify(t('refresh_success', 'تم تحديث كافة البيانات بنجاح من قاعدة البيانات.'), 'success');
    } catch (err) {
      notify(err.message, 'danger');
    } finally {
      refreshBtn.classList.remove('spinning');
    }
  });
}

/* -------------------------------------------------------------------------
   3) Tab Navigation
   ------------------------------------------------------------------------- */
function switchTab(tabId) {
  const changedTab = currentTab !== tabId;
  if (changedTab) window.DashboardEffects?.show();
  currentTab = tabId;

  $$('.st-nav-item').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.tab === tabId);
  });

  $$('.st-panel').forEach(p => p.hidden = true);
  const target = $(`${tabId}Panel`);
  if (target) target.hidden = false;
  if (changedTab) window.DashboardEffects?.enter(target);

  const currentLabel = $('breadcrumbCurrent');
  if (currentLabel) {
    const activeBtn = $(`.st-nav-item[data-tab="${tabId}"]`);
    currentLabel.textContent = activeBtn ? activeBtn.querySelector('.st-item-text')?.textContent : tabId;
  }

  closeMobileMenu();
}

function closeMobileMenu() {
  const sb = $('studentSidebar');
  const bd = $('sidebarBackdrop');
  if (sb) sb.classList.remove('open');
  if (bd) bd.classList.remove('open');
}

/* -------------------------------------------------------------------------
   4) Modals
   ------------------------------------------------------------------------- */
window.openModal = function(id) {
  const m = $(id);
  if (m) m.hidden = false;
};
window.closeModal = function(id) {
  const m = $(id);
  if (m) m.hidden = true;
};
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') $$('.st-modal-overlay').forEach(m => m.hidden = true);
});
document.addEventListener('click', e => {
  if (e.target.classList.contains('st-modal-overlay')) e.target.hidden = true;
});

/* -------------------------------------------------------------------------
   5) Load Dashboard Data
   ------------------------------------------------------------------------- */
async function loadDashboard() {
  try {
    const res = await requestJSON(`${ROOT}api/student/dashboard.php`);
    dashboardData = res;
    user = res.student;

    renderAll();
  } catch (err) {
    console.error('Failed to load student dashboard:', err);
    notify(err.message, 'danger');
  }
}

function renderAll() {
  if (!dashboardData) return;
  if (window.studentAssessmentData) {
    dashboardData.available_quizzes = window.studentAssessmentData.quizzes || [];
    dashboardData.quiz_results = window.studentAssessmentData.attempts || [];
  }
  renderOverview();
  renderEnrolledCourses();
  renderAvailableCourses();
  renderMeetings();
  renderQuizzes();
  renderGrades();
  renderNotes();
  renderCertificates();
  renderOrders();
  renderSupport();
  renderProfile();
  renderNotifications();
}

/* -------------------------------------------------------------------------
   6) Renderers
   ------------------------------------------------------------------------- */

// 1. Overview Tab
function renderOverview() {
  if (!dashboardData || !user) return;

  const s = user;
  if ($('studentWelcomeName')) $('studentWelcomeName').textContent = s.full_name || (currentLang === 'en' ? 'Student' : 'طالب الأكاديمية');
  if ($('topStudentName')) $('topStudentName').textContent = s.full_name || (currentLang === 'en' ? 'Student' : 'طالب');

  const avatarCircle = $('topAvatarCircle');
  if (avatarCircle) {
    if (s.avatar) {
      avatarCircle.innerHTML = `<img src="${esc(s.avatar)}" style="width:100%;height:100%;object-fit:cover;">`;
    } else {
      avatarCircle.textContent = (s.full_name || 'ط').charAt(0);
    }
  }

  // Stat Counters
  if ($('statEnrolledCount')) $('statEnrolledCount').textContent = dashboardData.enrolled_courses?.length || 0;
  if ($('statCertCount')) $('statCertCount').textContent = dashboardData.certificates?.length || 0;
  if ($('statQuizCount')) $('statQuizCount').textContent = dashboardData.available_quizzes?.length || 0;
  if ($('statMeetingsCount')) $('statMeetingsCount').textContent = dashboardData.upcoming_meetings?.length || 0;

  // "Continue Learning" Quick Card
  const quickContinueBox = $('quickContinueBox');
  const enrolled = dashboardData.enrolled_courses || [];
  if (quickContinueBox) {
    if (enrolled.length > 0) {
      const activeC = enrolled[0];
      const lessonId = activeC.last_lesson_id || 1;
      quickContinueBox.innerHTML = `
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
          <div>
            <span class="st-badge st-badge-teal" style="margin-bottom:6px;">${t('last_studied')}</span>
            <h3 style="font-size:18px;font-weight:800;margin:0 0 4px;">${esc(activeC.title)}</h3>
            <p style="font-size:13px;color:var(--text-muted);margin:0;">
              ${t('current_lesson')} <strong>${esc(activeC.last_lesson_title || (currentLang === 'en' ? 'Course Intro' : 'مقدمة الكورس'))}</strong> (${activeC.progress_percent}% ${t('completed_progress')})
            </p>
          </div>
          <a href="api/courses/player.php?course_id=${activeC.id}&lesson_id=${lessonId}&lesson_source=course_lessons" class="st-btn st-btn-primary" target="_blank">
            ${t('continue_last_lesson')}
          </a>
        </div>
      `;
    } else {
      quickContinueBox.innerHTML = `
        <div style="text-align:center;padding:16px;">
          <h4 style="margin:0 0 6px;">${t('no_enrolled_courses')}</h4>
          <p style="font-size:13px;color:var(--text-muted);margin:0 0 12px;">${t('no_enrolled_sub')}</p>
          <button type="button" class="st-btn st-btn-sm st-btn-primary" onclick="switchTab('explore-courses')">${t('browse_courses_btn')} ↗</button>
        </div>
      `;
    }
  }

  // Next Lecture Quick Card
  const nextMeetBox = $('nextLectureBox');
  const meets = dashboardData.upcoming_meetings || [];
  if (nextMeetBox) {
    if (meets.length > 0) {
      const m = meets[0];
      nextMeetBox.innerHTML = `
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;">
          <div>
            <span class="st-badge st-badge-emerald" style="margin-bottom:4px;">${t('upcoming_zoom_card')}</span>
            <div style="font-weight:800;font-size:15px;">${esc(m.title)}</div>
            <div style="font-size:12px;color:var(--text-muted);">${esc(m.course_title)} • ${esc(m.starts_at)}</div>
          </div>
          <a href="${esc(m.meeting_url)}" target="_blank" class="st-btn st-btn-sm st-btn-primary">
            ${t('join_zoom_now')}
          </a>
        </div>
      `;
    } else {
      nextMeetBox.innerHTML = `<div style="color:var(--text-muted);font-size:13px;text-align:center;padding:12px;">${t('no_zoom_today')}</div>`;
    }
  }
}

// 2. Enrolled Courses Tab
function renderEnrolledCourses() {
  const grid = $('enrolledCoursesGrid');
  if (!grid) return;

  const courses = dashboardData.enrolled_courses || [];
  if (!courses.length) {
    grid.innerHTML = `
      <div style="grid-column: 1/-1; text-align: center; padding: 50px 20px; background: var(--bg-surface); border-radius: 20px; border: 1px dashed var(--border-color);">
        <div style="font-size: 40px; margin-bottom: 12px;">🩺</div>
        <h3 style="margin:0 0 8px;">${t('no_enrolled_courses')}</h3>
        <p style="color:var(--text-muted);margin:0 0 16px;">${t('no_enrolled_sub')}</p>
        <button type="button" class="st-btn st-btn-primary" onclick="switchTab('explore-courses')">${t('browse_courses_btn')}</button>
      </div>
    `;
    return;
  }

  grid.innerHTML = courses.map(c => {
    const thumbHtml = c.thumbnail ? 
      `<img src="${esc(c.thumbnail)}" alt="${esc(c.title)}" class="st-course-thumb">` :
      `<div class="st-course-thumb st-course-thumb-empty">🩺</div>`;

    const lessonId = c.last_lesson_id || 1;

    return `
      <div class="st-course-card">
        ${thumbHtml}
        <div class="st-course-body">
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <span class="st-badge st-badge-teal">${esc(c.category || (currentLang === 'en' ? 'Clinical Diploma' : 'دبلومة سريرية'))}</span>
            <span class="st-badge st-badge-emerald">${t('enrolled_active_badge')}</span>
          </div>

          <h3 class="st-course-name">${esc(c.title)}</h3>

          <div style="display:flex;gap:12px;font-size:12px;color:var(--text-muted);margin-bottom:14px;">
            <span>🎬 ${c.completed_lessons} / ${c.total_lessons} ${t('lessons_count_text')}</span>
            <span>⏱️ ${c.duration_minutes || 0} ${t('minutes_text')}</span>
          </div>

          <div class="st-progress-box">
            <div class="st-progress-head">
              <span>${t('progress_ratio')}</span>
              <span style="color:var(--st-teal-600);">${c.progress_percent}%</span>
            </div>
            <div class="st-bar-wrap">
              <div class="st-bar-fill" style="width: ${c.progress_percent}%;"></div>
            </div>
          </div>

          <div style="margin-top:16px;display:flex;gap:8px;">
            <a href="api/courses/player.php?course_id=${c.id}&lesson_id=${lessonId}&lesson_source=course_lessons" class="st-btn st-btn-primary" style="flex:1;" target="_blank">
              ${t('continue_learning')}
            </a>
            <button type="button" class="st-btn st-btn-outline" onclick="openCourseReviewModal(${c.id}, '${esc(c.title)}', ${c.user_review ? c.user_review.rating : 5}, '${esc(c.user_review ? c.user_review.comment : '')}')" title="${t('review_btn')}">
              ${c.user_review ? `⭐ ${c.user_review.rating}/5${c.user_review.status === 'pending' ? ` · ${t('review_pending')}` : c.user_review.status === 'rejected' ? ` · ${t('review_rejected')}` : ''}` : `⭐ ${t('review_btn')}`}
            </button>
          </div>
        </div>
      </div>
    `;
  }).join('');
}

// 3. Available Courses Tab
function renderAvailableCourses() {
  const grid = $('availableCoursesGrid');
  if (!grid) return;

  const courses = dashboardData.available_courses || [];
  if (!courses.length) {
    grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--text-muted);">${t('all_enrolled_msg')}</div>`;
    return;
  }

  const curr = t('currency_egp');
  grid.innerHTML = courses.map(c => {
    const thumbHtml = c.thumbnail ? 
      `<img src="${esc(c.thumbnail)}" alt="${esc(c.title)}" class="st-course-thumb">` :
      `<div class="st-course-thumb st-course-thumb-empty">🩺</div>`;

    const priceHtml = c.discounted_price ?
      `<strong>${c.discounted_price} ${curr}</strong> <span style="text-decoration:line-through;color:var(--text-muted);font-size:12px;margin-right:4px;">${c.price} ${curr}</span>` :
      `<strong>${c.price} ${curr}</strong>`;

    return `
      <div class="st-course-card">
        ${thumbHtml}
        <div class="st-course-body">
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <span class="st-badge st-badge-teal">${esc(c.category || (currentLang === 'en' ? 'Clinical Track' : 'مسار طبي'))}</span>
            <span class="st-badge st-badge-gold">${esc(c.level || (currentLang === 'en' ? 'Beginner' : 'مبتدئ'))}</span>
          </div>

          <h3 class="st-course-name">${esc(c.title)}</h3>
          <p class="st-course-desc">${esc(c.description || (currentLang === 'en' ? 'Advanced clinical training program supervised by Dr. Khaled.' : 'كورس تدريبي سريري متقدم بإشراف د. خالد عبد الناصر.'))}</p>

          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:auto;padding-top:14px;border-top:1px solid var(--border-color);">
            <div style="font-size:15px;color:var(--st-navy-900);font-weight:800;">
              ${priceHtml}
            </div>
            <button type="button" class="st-btn st-btn-sm st-btn-primary" onclick="openEnrollCheckoutModal(${c.id}, '${esc(c.title)}', ${c.discounted_price || c.price}, 'course')">
              ${t('enroll_now_btn')}
            </button>
          </div>
        </div>
      </div>
    `;
  }).join('');
}

// 4. Meetings Tab
function renderMeetings() {
  const tbody = $('meetingsTbody');
  if (!tbody) return;

  const meets = dashboardData.upcoming_meetings || [];
  if (!meets.length) {
    tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:30px;color:var(--text-muted);">${t('no_zoom_meetings')}</td></tr>`;
    return;
  }

  tbody.innerHTML = meets.map(m => {
    const isLive = m.status === 'live';
    const badge = isLive ?
      `<span class="st-badge" style="background:rgba(239,68,68,0.15);color:var(--st-danger);font-weight:800;">${t('zoom_live_now')}</span>` :
      `<span class="st-badge st-badge-emerald">${t('zoom_scheduled')}</span>`;

    return `
      <tr>
        <td><strong style="font-size:14.5px;">${esc(m.title)}</strong></td>
        <td><span class="st-badge st-badge-teal">${esc(m.course_title)}</span></td>
        <td>${esc(m.starts_at)}</td>
        <td>${badge}</td>
        <td>
          <a href="${esc(m.meeting_url)}" target="_blank" class="st-btn st-btn-sm ${isLive ? 'st-btn-primary' : 'st-btn-outline'}">
            ${t('join_zoom_now')}
          </a>
        </td>
      </tr>
    `;
  }).join('');
}

// 5. Quizzes Tab
function renderQuizzes() {
  const tbody = $('quizzesTbody');
  if (!tbody) return;

  const quizzes = window.studentAssessmentData?.quizzes || dashboardData.available_quizzes || [];
  if (!quizzes.length) {
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);">${t('no_quizzes')}</td></tr>`;
    return;
  }

  tbody.innerHTML = quizzes.map(q => {
    const isPassed = Boolean(q.last_passed);
    const noAttempts = q.attempts_remaining !== null && q.attempts_remaining !== undefined && Number(q.attempts_remaining) <= 0;
    const passBadge = isPassed ? 
      `<span class="st-badge st-badge-emerald">${t('quiz_passed')}</span>` : 
      `<span class="st-badge st-badge-teal">${t('quiz_available')}</span>`;

    return `
      <tr>
        <td><strong>${esc(q.title)}</strong></td>
        <td>${esc(q.course_title)}</td>
        <td>${q.questions_count || 0} ${t('questions_count')}</td>
        <td>${q.time_limit_minutes || 30} ${t('minutes_text')}</td>
        <td>${passBadge}</td>
        <td>
          <button type="button" class="st-btn st-btn-sm st-btn-primary" onclick="launchQuiz(${q.id})" ${noAttempts ? 'disabled' : ''}>
            ${noAttempts ? t('quiz_attempts_exhausted') : isPassed ? t('quiz_retake') : t('quiz_start')}
          </button>
        </td>
      </tr>
    `;
  }).join('');
}

// 6. Grades & Quiz Results Tab
function renderGrades() {
  const tbody = $('gradesTbody');
  if (!tbody) return;

  const results = window.studentAssessmentData?.attempts || dashboardData.quiz_results || [];
  if (!results.length) {
    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text-muted);">${t('no_grades')}</td></tr>`;
    return;
  }

  tbody.innerHTML = results.map(r => {
    const passed = Boolean(r.passed);
    const pending = r.passed === null || r.passed === undefined;
    const statusBadge = pending ?
      `<span class="st-badge st-badge-teal">${t('quiz_result_pending')}</span>` : passed ?
      `<span class="st-badge st-badge-emerald">${t('grade_passed')}</span>` :
      `<span class="st-badge" style="background:rgba(239,68,68,0.12);color:var(--st-danger)">${t('grade_failed')}</span>`;

    return `
      <tr>
        <td><strong>${esc(r.quiz_title)}</strong></td>
        <td>${esc(r.course_title)}</td>
        <td>${t('attempt_prefix')}${r.attempt_number || 1}</td>
        <td>${pending ? t('quiz_result_pending') : `<strong style="font-size:15px;color:var(--st-teal-600);">${r.percent_score}%</strong> (${r.score} ${t('out_of')} ${r.max_score})`}</td>
        <td>${statusBadge}</td>
        <td>${esc(r.submitted_at || '-')}</td>
        <td><button type="button" class="st-btn st-btn-sm st-btn-outline" data-student-result="${Number(r.id)}" ${pending ? 'disabled' : ''}>${currentLang === 'en' ? 'Review' : 'التفاصيل'}</button></td>
      </tr>
    `;
  }).join('');
}

// 7. Study Notes & Books Tab
function renderNotes() {
  const grid = $('notesGrid');
  if (!grid) return;

  const notes = dashboardData.study_notes || [];
  if (!notes.length) {
    grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:30px;color:var(--text-muted);">${t('no_notes')}</div>`;
    return;
  }

  const curr = t('currency_egp');
  grid.innerHTML = notes.map(n => {
    const hasAccess = Boolean(Number(n.has_access));
    const title = (currentLang === 'en' && n.title_en) ? n.title_en : (n.title_ar || n.title_en || (currentLang === 'en' ? 'Clinical Reference' : 'مذكرة سريرية'));
    return `
      <div class="st-card" style="padding:22px;display:flex;flex-direction:column;gap:14px;border:1px solid ${hasAccess ? 'rgba(13,148,136,0.3)' : 'var(--border-color)'};">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <span class="st-badge ${hasAccess ? 'st-badge-emerald' : 'st-badge-gold'}">
            ${hasAccess ? t('note_available') : t('note_accredited')}
          </span>
          <span style="font-size:12px;color:var(--text-muted);">${n.pages_count || 120} ${t('pages_count')}</span>
        </div>
        <div style="display:flex;align-items:center;gap:14px;">
          <div style="width:52px;height:66px;background:linear-gradient(135deg,var(--st-navy-900),var(--st-teal-700));border-radius:10px;display:grid;place-items:center;color:#fff;font-size:26px;flex-shrink:0;box-shadow:0 4px 10px rgba(0,0,0,0.1);">
            📖
          </div>
          <div>
            <h4 style="margin:0 0 6px;font-size:15px;font-weight:800;">${esc(title)}</h4>
            <div style="font-size:12.5px;color:var(--text-muted);">${esc(n.author || (currentLang === 'en' ? 'Dr. Khaled Abdel Nasser' : 'د. خالد عبد الناصر'))}</div>
          </div>
        </div>
        <div style="margin-top:auto;display:flex;justify-content:space-between;align-items:center;padding-top:12px;border-top:1px solid var(--border-color);">
          <div>
            ${hasAccess ? `<strong style="color:var(--st-teal-600);font-size:13px;">${t('note_in_account')}</strong>` : `<strong style="font-size:15px;color:var(--st-navy-900);">${n.price || 0} ${curr}</strong>`}
          </div>
          <div>
            ${hasAccess ? (
              n.pdf_path ? `
                <a href="${esc(n.pdf_path)}" target="_blank" class="st-btn st-btn-sm st-btn-primary">
                  ${t('read_download_pdf')}
                </a>
              ` : `
                <button type="button" class="st-btn st-btn-sm st-btn-primary" onclick="alert('${currentLang === 'en' ? 'Study note is active in your account.' : 'المذكرة مفعلة في حسابك وسيتم تفعيل رابط التحميل المباشر فور اعتماد الملف الأكاديمي.'}')">
                  ${t('note_in_account')} ✅
                </button>
              `
            ) : `
              <button type="button" class="st-btn st-btn-sm st-btn-primary" onclick="openEnrollCheckoutModal(${n.id}, '${esc(title)}', ${n.price}, 'book')">
                ${t('order_note_btn')}
              </button>
            `}
          </div>
        </div>
      </div>
    `;
  }).join('');
}

// 8. Certificates Tab
function renderCertificates() {
  const grid = $('certificatesGrid');
  if (!grid) return;

  const certs = dashboardData.certificates || [];
  if (!certs.length) {
    grid.innerHTML = `
      <div style="grid-column:1/-1;text-align:center;padding:40px 20px;background:var(--bg-surface);border-radius:20px;border:1px dashed var(--border-color);">
        <div style="font-size:42px;margin-bottom:10px;">📜</div>
        <h3 style="margin:0 0 6px;">${t('no_certs')}</h3>
        <p style="color:var(--text-muted);margin:0;">${t('no_certs_sub')}</p>
      </div>
    `;
    return;
  }

  grid.innerHTML = certs.map(c => `
    <div class="st-card" style="padding:24px;border:2px solid rgba(212,175,55,0.3);background:linear-gradient(135deg,var(--bg-surface),rgba(212,175,55,0.04));">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;">
        <div style="font-size:32px;">🏆</div>
        <span class="st-badge st-badge-gold">${t('cert_verified')}</span>
      </div>
      <h3 style="font-size:17px;font-weight:900;margin:0 0 6px;">${esc(c.course_title)}</h3>
      <p style="font-size:12.5px;color:var(--text-muted);margin:0 0 14px;">
        ${t('granted_to')} <strong>${esc(c.student_name_snapshot)}</strong><br>
        ${t('cert_code')} <code style="font-weight:800;color:var(--st-teal-600);">${esc(c.certificate_number)}</code><br>
        ${t('issued_date')} ${esc(c.issued_at)}
      </p>
      <div style="display:flex;gap:8px;">
        <button type="button" class="st-btn st-btn-sm st-btn-primary" onclick="showCertificateModal('${esc(c.certificate_number)}', '${esc(c.course_title)}', '${esc(c.student_name_snapshot)}', '${esc(c.issued_at)}')">
          ${t('view_cert_btn')}
        </button>
      </div>
    </div>
  `).join('');
}

// 9. Orders & Invoices Tab
function renderOrders() {
  const tbody = $('ordersTbody');
  if (!tbody) return;

  const orders = dashboardData.orders || [];
  if (!orders.length) {
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);">${t('no_orders')}</td></tr>`;
    return;
  }

  tbody.innerHTML = orders.map(o => {
    let statusBadge = `<span class="st-badge st-badge-teal">${t('order_pending')}</span>`;
    if (o.status === 'paid') {
      statusBadge = `<span class="st-badge st-badge-emerald">${t('order_completed')}</span>`;
    } else if (o.status === 'rejected') {
      statusBadge = `<span class="st-badge" style="background:rgba(239,68,68,0.12);color:var(--st-danger)">${t('order_rejected')}</span>`;
    } else if (o.status === 'pending_payment') {
      statusBadge = `<span class="st-badge st-badge-gold">${t('order_pending_pay')}</span>`;
    }

    return `
      <tr>
        <td><strong>${esc(o.order_number)}</strong></td>
        <td><strong style="color:var(--st-teal-600);">${o.total_amount} ${esc(o.currency || t('currency_egp'))}</strong></td>
        <td>${esc(o.payment_method || 'Vodafone Cash')}</td>
        <td>${statusBadge}</td>
        <td>${esc(o.created_at)}</td>
        <td>
          <button type="button" class="st-btn st-btn-sm st-btn-outline" onclick="viewOrderDetails(${o.id})">
            ${t('view_details_btn')}
          </button>
        </td>
      </tr>
    `;
  }).join('');
}

// 10. Support Tickets Tab
function renderSupport() {
  const tbody = $('ticketsTbody');
  if (!tbody) return;

  const tickets = dashboardData.support_tickets || [];
  if (!tickets.length) {
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);">${t('no_tickets')}</td></tr>`;
    return;
  }

  tbody.innerHTML = tickets.map(tItem => `
    <tr>
      <td><strong>${esc(tItem.ticket_number)}</strong></td>
      <td>${esc(tItem.subject)}</td>
      <td><span class="st-badge st-badge-teal">${esc(tItem.category)}</span></td>
      <td><span class="st-badge st-badge-emerald">${esc(tItem.status)}</span></td>
      <td>${esc(tItem.created_at)}</td>
      <td>
        <button type="button" class="st-btn st-btn-sm st-btn-outline" onclick="openTicketMessages(${tItem.id})">
          ${t('view_reply_btn')}
        </button>
      </td>
    </tr>
  `).join('');
}

// 11. Profile & Student ID Card
function renderProfile() {
  if (!user) return;
  if ($('profFullName')) $('profFullName').value = user.full_name || '';
  if ($('profEmail')) $('profEmail').value = user.email || '';
  if ($('profPhone')) $('profPhone').value = user.phone || '';

  const profPreview = $('profAvatarPreview');
  if (profPreview) {
    if (user.avatar) {
      profPreview.innerHTML = `<img src="${esc(user.avatar)}" style="width:100%;height:100%;object-fit:cover;">`;
    } else {
      profPreview.textContent = (user.full_name || 'ط').charAt(0);
    }
  }

  // Render QR Code in Digital Student Card & Modal
  const qrImg = $('studentQrImage');
  const qrTokenSpan = $('studentQrTokenText');
  const modalQrImg = $('modalQrImage');
  const modalQrTok = $('modalQrToken');
  const token = user.qr_token || `EDU-STU-${user.id}`;
  const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=${encodeURIComponent(token)}`;

  if (qrImg) qrImg.src = qrUrl;
  if (qrTokenSpan) qrTokenSpan.textContent = token;
  if (modalQrImg) modalQrImg.src = qrUrl;
  if (modalQrTok) modalQrTok.textContent = token;
}

// Notifications dropdown
function renderNotifications() {
  const countBadge = $('notifBadge');
  const count = dashboardData.unread_notifications_count || 0;
  if (countBadge) {
    countBadge.textContent = count;
    countBadge.hidden = (count === 0);
  }

  const list = $('notifList');
  if (!list) return;

  const notifs = dashboardData.notifications || [];
  if (!notifs.length) {
    list.innerHTML = '<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13px;">لا توجد إشعارات جديدة.</div>';
    return;
  }

  list.innerHTML = notifs.slice(0, 15).map(n => `
    <div style="padding:12px 16px;border-bottom:1px solid var(--border-color);display:flex;gap:10px;align-items:flex-start;">
      <div style="font-size:18px;">🔔</div>
      <div style="flex:1;">
        <div style="font-weight:700;font-size:13px;">${esc(n.title)}</div>
        <div style="font-size:12px;color:var(--text-muted);">${esc(n.message)}</div>
        <div style="font-size:11px;color:var(--st-teal-600);margin-top:2px;">${esc(n.created_at)}</div>
      </div>
    </div>
  `).join('');
}

window.openNotificationsModal = function() {
  openModal('notificationsModal');
  studentApi('mark_notifs_read').catch(() => {});
  const badge = $('notifBadge');
  if (badge) badge.hidden = true;
};

/* -------------------------------------------------------------------------
   7) Interactive Operations: Quiz, Checkout, Support
   ------------------------------------------------------------------------- */
window.launchQuiz = async function(quizId) {
  try {
    const res = await studentApi('take_quiz', { quiz_id: quizId });
    const q = res.quiz;

    $('quizModalTitle').textContent = q.title;
    $('quizModalId').value = q.id;

    const body = $('quizModalBody');
    if (!q.questions || !q.questions.length) {
      body.innerHTML = '<div style="padding:20px;text-align:center;">لا توجد أسئلة مضافة في هذا الاختبار حالياً.</div>';
      openModal('quizModal');
      return;
    }

    body.innerHTML = q.questions.map((qst, idx) => `
      <div style="margin-bottom:20px;padding:16px;border:1px solid var(--border-color);border-radius:14px;background:var(--bg-surface);">
        <div style="font-weight:800;font-size:14.5px;margin-bottom:12px;">
          سؤال ${idx + 1}: ${esc(qst.question_text)} (${qst.points} درجات)
        </div>
        <div style="display:flex;flex-direction:column;gap:8px;">
          ${qst.options.map(opt => `
            <label style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid var(--border-color);border-radius:10px;cursor:pointer;">
              <input type="radio" name="qst_${qst.id}" value="${opt.id}" required style="accent-color:var(--st-teal-600)">
              <span>${esc(opt.option_text)}</span>
            </label>
          `).join('')}
        </div>
      </div>
    `).join('');

    openModal('quizModal');

  } catch (err) {
    alert(err.message);
  }
};

window.submitActiveQuiz = async function(e) {
  e.preventDefault();
  const quizId = Number($('quizModalId').value);
  const answers = {};

  const inputs = $$('#quizModalBody input[type="radio"]:checked');
  inputs.forEach(inp => {
    const qstId = inp.name.replace('qst_', '');
    answers[qstId] = Number(inp.value);
  });

  const submitBtn = $('btnSubmitQuiz');
  const origText = submitBtn ? submitBtn.innerHTML : '';
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.innerHTML = 'جاري تصحيح الإجابات واعتماد النتيجة...';
  }

  try {
    const res = await studentApi('submit_quiz', {
      quiz_id: quizId,
      answers: answers
    });

    closeModal('quizModal');
    notify(res.message, res.passed ? 'success' : 'danger');
    await loadDashboard();
    switchTab('grades');

  } catch (err) {
    alert(err.message);
  } finally {
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = origText;
    }
  }
};

window.openEnrollCheckoutModal = function(itemId, title, price, itemType = 'course') {
  $('checkoutCourseId').value = itemId;
  $('checkoutItemType').value = itemType;
  $('checkoutCourseTitle').textContent = `${currentLang === 'en' ? (itemType === 'course' ? 'Course: ' : 'Book: ') : (itemType === 'course' ? 'كورس: ' : 'مذكرة: ')} ${title}`;
  $('checkoutAmount').textContent = `${new Intl.NumberFormat(currentLang === 'en' ? 'en-US' : 'ar-EG').format(Number(price || 0))} ${currentLang === 'en' ? 'EGP' : 'ج.م'}`;
  const support = dashboardData?.support_contact || {};
  $('checkoutVodafone').textContent = support.vodafone_cash || '01061709999';
  $('checkoutInstaPay').textContent = support.instapay || '01061709999';

  const waBtn = $('checkoutWhatsappBtn');
  if (waBtn) {
    const waNum = (support.whatsapp || '201289492477').replace(/[^0-9]/g, '');
    const waMessage = currentLang === 'en'
      ? `Hello Edu Nursing, I would like to confirm my enrollment in (${title}) for ${new Intl.NumberFormat('en-US').format(Number(price || 0))} EGP. The transfer receipt is attached.`
      : `مرحباً أكاديمية Edu Nursing، أريد تأكيد اشتراكي في (${title}) بمبلغ ${new Intl.NumberFormat('ar-EG').format(Number(price || 0))} ج.م، مرفق إيصال التحويل.`;
    const waText = encodeURIComponent(waMessage);
    waBtn.href = `https://wa.me/${waNum}?text=${waText}`;
  }

  if ($('checkoutTxRef')) $('checkoutTxRef').value = '';
  if ($('checkoutReceiptFile')) $('checkoutReceiptFile').value = '';
  openModal('checkoutModal');
};

window.showCertificateModal = function(certNum, courseTitle, studentName, issueDate) {
  $('certModalTitle').textContent = courseTitle;
  $('certModalStudent').textContent = studentName;
  $('certModalNumber').textContent = certNum;
  $('certModalDate').textContent = issueDate;
  const qrElem = $('certModalQr');
  if (qrElem) {
    qrElem.src = `https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=${encodeURIComponent('https://edunursing.academy/verify?cert=' + certNum)}`;
  }
  openModal('certificateModal');
};

window.openCourseReviewModal = function(courseId, title, rating = 5, comment = '') {
  $('reviewCourseId').value = courseId;
  $('reviewCourseTitle').textContent = title;
  $('reviewRatingInput').value = rating;
  $('reviewCommentText').value = comment || '';
  updateStarRatingDisplay(rating);
  openModal('reviewModal');
};

function updateStarRatingDisplay(rating) {
  $$('#starRatingBox .star-btn').forEach(btn => {
    const val = Number(btn.dataset.val);
    btn.style.color = val <= rating ? '#f59e0b' : '#cbd5e1';
  });
}

window.viewOrderDetails = async function(orderId) {
  try {
    const res = await studentApi('order_details', { order_id: orderId });
    const o = res.order;
    const items = res.items || [];

    $('modalOrderNumber').textContent = o.order_number;
    const body = $('modalOrderBody');
    body.innerHTML = `
      <div style="background:var(--bg-app);border-radius:12px;padding:16px;margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
          <span>تاريخ العملية:</span>
          <strong>${esc(o.created_at)}</strong>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
          <span>طريقة الدفع:</span>
          <strong>${esc(o.payment_method)}</strong>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
          <span>الحالة:</span>
          <span class="st-badge ${o.status === 'paid' ? 'st-badge-emerald' : 'st-badge-gold'}">
            ${o.status === 'paid' ? 'مكتمل ومفعل ✅' : (o.status === 'rejected' ? 'مرفوض ✕' : 'قيد المراجعة والاعتماد ⏳')}
          </span>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
          <span>إجمالي المبلغ:</span>
          <strong style="color:var(--st-teal-600);font-size:16px;">${o.total_amount} ${esc(o.currency || 'EGP')}</strong>
        </div>
        ${o.transaction_reference ? `
          <div style="display:flex;justify-content:space-between;">
            <span>مرجع المعاملة:</span>
            <code>${esc(o.transaction_reference)}</code>
          </div>
        ` : ''}
      </div>

      <div style="margin-bottom:16px;">
        <h4 style="margin:0 0 10px;font-size:14px;">العناصر والبنود المطلوبة:</h4>
        <div style="display:flex;flex-direction:column;gap:8px;">
          ${items.map(it => `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border:1px solid var(--border-color);border-radius:10px;">
              <div>
                <strong>${esc(it.title_snapshot)}</strong>
                <span style="font-size:12px;color:var(--text-muted);margin-right:6px;">(${it.item_type === 'course' ? 'كورس' : 'مذكرة'})</span>
              </div>
              <strong style="color:var(--st-navy-900);">${it.subtotal} ج.م</strong>
            </div>
          `).join('')}
        </div>
      </div>

      ${o.proof_path ? `
        <div style="margin-top:14px;">
          <h4 style="margin:0 0 8px;font-size:13px;">صورة إيصال التحويل المرفقة:</h4>
          <a href="${esc(o.proof_path)}" target="_blank">
            <img src="${esc(o.proof_path)}" alt="Receipt Proof" style="max-height:160px;border-radius:10px;border:1px solid var(--border-color);object-fit:cover;">
          </a>
        </div>
      ` : ''}
    `;

    openModal('orderViewModal');
  } catch (err) {
    alert(err.message);
  }
};

window.openNewTicketModal = function() {
  $('ticketSubject').value = '';
  $('ticketDesc').value = '';
  openModal('ticketModal');
};

window.openTicketMessages = async function(ticketId) {
  try {
    const res = await studentApi('ticket_messages', { ticket_id: ticketId });
    $('msgTicketNumber').textContent = res.ticket.ticket_number;
    $('msgTicketSubject').textContent = res.ticket.subject;
    $('activeTicketId').value = res.ticket.id;

    const list = $('ticketMessagesList');
    list.innerHTML = res.messages.map(m => `
      <div style="margin-bottom:12px;padding:12px 16px;border-radius:12px;background:${m.sender_id == user.id ? 'rgba(13,148,136,0.1)' : 'var(--bg-app)'};">
        <div style="font-weight:800;font-size:12.5px;color:var(--st-teal-600);margin-bottom:4px;">
          ${esc(m.sender_name)} (${m.sender_role === 'student' ? 'أنت' : 'فريق الدعم الفني الطبي'})
        </div>
        <div style="font-size:13.5px;line-height:1.6;">${esc(m.message)}</div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">${esc(m.created_at)}</div>
      </div>
    `).join('');

    openModal('ticketViewModal');
  } catch (err) {
    alert(err.message);
  }
};

window.sendTicketReply = async function(e) {
  e.preventDefault();
  const ticketId = Number($('activeTicketId').value);
  const message = $('replyMessageText').value.trim();
  if (!message) return;

  try {
    await studentApi('reply_ticket', { ticket_id: ticketId, message });
    $('replyMessageText').value = '';
    openTicketMessages(ticketId);
    notify('تم إرسال ردك بنجاح.');
  } catch (err) {
    alert(err.message);
  }
};

/* -------------------------------------------------------------------------
   8) Initialization Bootstrap
   ------------------------------------------------------------------------- */
(async () => {
  initTheme();
  initGlobalSearch();
  initRefreshBtn();
  setLanguage(currentLang);

  // Bind nav tabs
  $$('.st-nav-item').forEach(btn => {
    btn.onclick = () => switchTab(btn.dataset.tab);
  });

  // Mobile menu toggle
  const menuBtn = $('menuToggleBtn');
  const sidebar = $('studentSidebar');
  const backdrop = $('sidebarBackdrop');
  if (menuBtn && sidebar && backdrop) {
    menuBtn.onclick = () => {
      sidebar.classList.toggle('open');
      backdrop.classList.toggle('open');
    };
    backdrop.onclick = closeMobileMenu;
  }

  // Language switcher
  const langBtn = $('langToggleBtn');
  if (langBtn) langBtn.onclick = toggleLanguage;

  // Logout
  const logoutBtn = $('logoutBtn');
  if (logoutBtn) {
    logoutBtn.onclick = async () => {
      if (!confirm(I18N[currentLang].logout_confirm)) return;
      try {
        await fetch(`${ROOT}api/auth/logout.php`, { method: 'POST', credentials: 'same-origin', headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {} });
      } catch (_) {}
      location.href = `${ROOT}auth/login.php`;
    };
  }

  // Profile Form
  const profForm = $('profileForm');
  if (profForm) {
    profForm.onsubmit = async e => {
      e.preventDefault();
      try {
        const res = await studentApi('update_profile', {
          full_name: $('profFullName').value,
          phone: $('profPhone').value
        });
        notify(res.message);
        user.full_name = $('profFullName').value;
        user.phone = $('profPhone').value;
        renderOverview();
      } catch (err) {
        alert(err.message);
      }
    };
  }

  // Student Avatar Upload
  const stuAvatarInp = $('stuAvatarInput');
  if (stuAvatarInp) {
    stuAvatarInp.onchange = async () => {
      if (!stuAvatarInp.files || !stuAvatarInp.files[0]) return;
      try {
        notify('جاري رفع وتحديث صورتك الشخصية...', 'info');
        const b64 = await fileToBase64(stuAvatarInp.files[0]);
        const res = await studentApi('upload_avatar', { image_data: b64 });
        user.avatar = res.avatar_url;
        renderOverview();
        renderProfile();
        notify(res.message);
      } catch (err) {
        alert(err.message);
      }
    };
  }

  // Password Form
  const pwForm = $('passwordForm');
  if (pwForm) {
    pwForm.onsubmit = async e => {
      e.preventDefault();
      const oldP = $('oldPassword').value;
      const newP = $('newPassword').value;
      const confP = $('confirmPassword').value;

      if (newP !== confP) {
        alert('كلمتا المرور الجديدتان غير متطابقتين.');
        return;
      }

      try {
        const res = await studentApi('change_password', {
          old_password: oldP,
          new_password: newP
        });
        notify(res.message);
        pwForm.reset();
      } catch (err) {
        alert(err.message);
      }
    };
  }

  // Ticket create form
  const tickForm = $('newTicketForm');
  if (tickForm) {
    tickForm.onsubmit = async e => {
      e.preventDefault();
      try {
        const res = await studentApi('create_ticket', {
          subject: $('ticketSubject').value,
          category: $('ticketCategory').value,
          description: $('ticketDesc').value
        });
        closeModal('ticketModal');
        notify(res.message);
        await loadDashboard();
        switchTab('support');
      } catch (err) {
        alert(err.message);
      }
    };
  }

  // Checkout Order Form
  const orderForm = $('checkoutOrderForm');
  if (orderForm) {
    orderForm.onsubmit = async e => {
      e.preventDefault();
      const itemId = Number($('checkoutCourseId').value);
      const itemType = $('checkoutItemType').value || 'course';
      const payMethod = $('checkoutPaymentMethod').value;
      const txRef = $('checkoutTxRef').value.trim();
      const fileInput = $('checkoutReceiptFile');

      const submitBtn = $('btnSubmitOrder');
      const origText = submitBtn ? submitBtn.innerHTML : '';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = 'جاري تسجيل طلبك...';
      }

      let receiptData = '';
      if (fileInput && fileInput.files && fileInput.files[0]) {
        try {
          receiptData = await fileToBase64(fileInput.files[0]);
        } catch (_) {}
      }

      try {
        const res = await studentApi('create_order', {
          item_id: itemId,
          item_type: itemType,
          payment_method: payMethod,
          transaction_reference: txRef,
          receipt_data: receiptData
        });

        closeModal('checkoutModal');
        notify(res.message);
        await loadDashboard();
        switchTab('orders');
      } catch (err) {
        alert(err.message);
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = origText;
        }
      }
    };
  }

  // Course Review Form
  const reviewForm = $('courseReviewForm');
  if (reviewForm) {
    reviewForm.onsubmit = async e => {
      e.preventDefault();
      const courseId = Number($('reviewCourseId').value);
      const rating = Number($('reviewRatingInput').value);
      const comment = $('reviewCommentText').value.trim();

      const btn = $('btnSubmitReview');
      if (btn) btn.disabled = true;

      try {
        const res = await studentApi('submit_review', {
          course_id: courseId,
          rating,
          comment
        });
        closeModal('reviewModal');
        notify(res.message);
        await loadDashboard();
      } catch (err) {
        alert(err.message);
      } finally {
        if (btn) btn.disabled = false;
      }
    };
  }

  // Star Rating Interactive Clicks
  $$('#starRatingBox .star-btn').forEach(btn => {
    btn.onclick = () => {
      const val = Number(btn.dataset.val);
      $('reviewRatingInput').value = val;
      updateStarRatingDisplay(val);
    };
  });

  // Initial Load
  await loadDashboard();
})();
