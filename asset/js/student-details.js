(() => {
  'use strict';

  const $ = (selector, root = document) => root.querySelector(selector);

  const getContentEl = () => $('#studentDetailsContent') || $('#content');
  const getMessageEl = () => $('#studentDetailsMessage') || $('#message');
  const getEditCardEl = () => $('#studentDetailsEditCard') || $('#editCard');
  const getEditFormEl = () => $('#studentDetailsEditForm') || $('#editForm');

  let csrfToken = '';
  let student = null;
  let studentId = 0;
  let requestVersion = 0;

  const getLang = () =>
    localStorage.getItem('nbc_admin_lang') ||
    document.documentElement.getAttribute('lang') ||
    'ar';

  const I18N_DETAILS = {
    ar: {
      student_id: 'رقم الطالب',
      no_phone: 'لا يوجد هاتف',
      edit_data: 'تعديل البيانات',
      suspend_acc: 'إيقاف الحساب',
      activate_acc: 'تفعيل الحساب',
      kpi_courses: 'الكورسات المسجلة',
      kpi_courses_desc: 'دبلومات ومسارات فعالة',
      kpi_progress: 'متوسط التقدم',
      kpi_progress_desc: 'نسبة الإنجاز الشاملة',
      kpi_lessons: 'الدروس المكتملة',
      kpi_lessons_desc: 'دروس تم الانتهاء منها',
      kpi_quizzes: 'محاولات الاختبارات',
      kpi_quizzes_desc: 'اختبارات تم تسليمها',
      account_info_title: 'بيانات الحساب والتواصل',
      account_info_subtitle: 'معلومات الحساب والهوية وبيانات الاتصال المعتمدة',
      info_email: 'البريد الإلكتروني',
      info_phone: 'رقم الهاتف',
      info_registered_at: 'تاريخ التسجيل',
      info_last_login: 'آخر تسجيل دخول',
      info_status: 'حالة الحساب',
      info_id: 'معرّف الطالب',
      academic_summary_title: 'الملخص والأداء الأكاديمي',
      academic_summary_subtitle: 'نظرة شاملة على تقدم الطالب وإنجازاته في المنظومة',
      summary_completed_courses: 'الكورسات المكتملة',
      summary_active_enrollments: 'التسجيلات الفعالة',
      summary_passed_quizzes: 'الاختبارات الناجحة',
      summary_assignments: 'التكليفات والواجبات',
      summary_certificates: 'الشهادات الصادرة',
      summary_total_payments: 'إجمالي المدفوعات',
      courses_title: 'الكورسات والمسارات المسجلة',
      courses_subtitle: 'قائمة الكورسات والتقدم الفعلي وصلاحيات الوصول',
      col_course: 'الكورس',
      col_enroll_status: 'حالة التسجيل',
      col_access: 'إتاحة الوصول',
      col_completed_lessons: 'الدروس المكتملة',
      col_progress: 'التقدم الدراسي',
      col_enrolled_at: 'تاريخ التسجيل',
      quizzes_title: 'نتائج الاختبارات والتقييم',
      quizzes_subtitle: 'سجل محاولات الاختبارات والدرجات ونسب النجاح',
      col_quiz: 'الاختبار',
      col_attempt: 'رقم المحاولة',
      col_score: 'الدرجة',
      col_percent: 'النسبة',
      col_result: 'النتيجة',
      col_submitted_at: 'تاريخ التسليم',
      assignments_title: 'التكليفات والواجبات',
      assignments_subtitle: 'سجل التكليفات العملية ودرجات التقييم وملاحظات المحاضر',
      col_assignment: 'التكليف',
      col_status: 'الحالة',
      col_grade: 'الدرجة',
      col_feedback: 'ملاحظات المحاضر',
      payments_title: 'سجل المدفوعات والعمليات',
      payments_subtitle: 'المعاملات المالية والاشتراكات المسجلة باسم الطالب',
      col_amount: 'المبلغ',
      col_method: 'طريقة الدفع',
      col_trans_ref: 'رقم المعاملة',
      col_date: 'التاريخ',
      attendance_title: 'حضور المحاضرات المباشرة',
      attendance_subtitle: 'سجل الحضور في جلسات الزوم والبث الحي التفاعلي',
      col_session: 'الجلسة',
      col_joined_at: 'وقت الانضمام',
      col_left_at: 'وقت المغادرة',
      certificates_title: 'الشهادات والاعتمادات الأكاديمية',
      certificates_subtitle: 'الشهادات الصادرة والمعتمدة لإتمام المسارات التدريبية',
      col_cert_name: 'اسم الطالب بالشهادة',
      col_issued_at: 'تاريخ الإصدار',
      no_data: 'لا توجد بيانات متاحة',
      no_data_desc: 'لم يتم تسجيل أي سجلات حتى الآن.',
      save_success: 'تم حفظ بيانات الطالب بنجاح.',
      status_success: 'تم تحديث حالة الحساب بنجاح.',
      confirm_suspend: 'هل تريد إيقاف حساب الطالب؟',
      confirm_activate: 'هل تريد تفعيل حساب الطالب؟'
    },
    en: {
      student_id: 'Student ID',
      no_phone: 'No phone recorded',
      edit_data: 'Edit Details',
      suspend_acc: 'Suspend Account',
      activate_acc: 'Activate Account',
      kpi_courses: 'Enrolled Courses',
      kpi_courses_desc: 'Active diplomas & tracks',
      kpi_progress: 'Avg. Progress',
      kpi_progress_desc: 'Overall completion rate',
      kpi_lessons: 'Completed Lessons',
      kpi_lessons_desc: 'Lessons finished',
      kpi_quizzes: 'Quiz Attempts',
      kpi_quizzes_desc: 'Evaluations taken',
      account_info_title: 'Account & Contact Details',
      account_info_subtitle: 'Verified credentials, identity and contact info',
      info_email: 'Email Address',
      info_phone: 'Phone Number',
      info_registered_at: 'Registration Date',
      info_last_login: 'Last Login',
      info_status: 'Account Status',
      info_id: 'Student ID',
      academic_summary_title: 'Academic Performance Summary',
      academic_summary_subtitle: 'Comprehensive view of student achievements & metrics',
      summary_completed_courses: 'Completed Courses',
      summary_active_enrollments: 'Active Enrollments',
      summary_passed_quizzes: 'Passed Quizzes',
      summary_assignments: 'Assignments Submitted',
      summary_certificates: 'Certificates Earned',
      summary_total_payments: 'Total Payments',
      courses_title: 'Enrolled Courses & Tracks',
      courses_subtitle: 'Course catalog, live progress and access privileges',
      col_course: 'Course',
      col_enroll_status: 'Enrollment Status',
      col_access: 'Access Level',
      col_completed_lessons: 'Completed Lessons',
      col_progress: 'Progress',
      col_enrolled_at: 'Enrolled On',
      quizzes_title: 'Quiz & Exam Results',
      quizzes_subtitle: 'Submissions log, scores and pass/fail standings',
      col_quiz: 'Quiz',
      col_attempt: 'Attempt #',
      col_score: 'Score',
      col_percent: 'Percent',
      col_result: 'Result',
      col_submitted_at: 'Submitted At',
      assignments_title: 'Assignments & Homework',
      assignments_subtitle: 'Practical homework submissions, grades and instructor feedback',
      col_assignment: 'Assignment',
      col_status: 'Status',
      col_grade: 'Grade',
      col_feedback: 'Feedback',
      payments_title: 'Payments & Transactions',
      payments_subtitle: 'Invoices, transaction references and payment methods',
      col_amount: 'Amount',
      col_method: 'Method',
      col_trans_ref: 'Transaction Ref',
      col_date: 'Date',
      attendance_title: 'Live Session Attendance',
      attendance_subtitle: 'Logs for interactive Zoom sessions and webinars',
      col_session: 'Session',
      col_joined_at: 'Joined At',
      col_left_at: 'Left At',
      certificates_title: 'Certificates & Credentials',
      certificates_subtitle: 'Issued course completion credentials and accreditations',
      col_cert_name: 'Name on Certificate',
      col_issued_at: 'Issue Date',
      no_data: 'No data available',
      no_data_desc: 'No records found for this section.',
      save_success: 'Student information saved successfully.',
      status_success: 'Account status updated successfully.',
      confirm_suspend: 'Are you sure you want to suspend this student account?',
      confirm_activate: 'Are you sure you want to activate this student account?'
    }
  };

  const t = (key, fallback = '') => {
    const lang = getLang();
    return I18N_DETAILS[lang]?.[key] || I18N_DETAILS.ar[key] || fallback || key;
  };

  const esc = value =>
    String(value ?? '').replace(/[&<>"']/g, char => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    })[char]);

  const num = value => {
    const lang = getLang();
    const locale = lang === 'en' ? 'en-US' : 'ar-EG';
    return Number(value ?? 0).toLocaleString(locale);
  };

  const money = (value, currency = 'EGP') => {
    const lang = getLang();
    const locale = lang === 'en' ? 'en-US' : 'ar-EG';
    return `${Number(value ?? 0).toLocaleString(locale, { maximumFractionDigits: 2 })} ${esc(currency)}`;
  };

  const date = value => {
    if (!value) return '—';
    const lang = getLang();
    const locale = lang === 'en' ? 'en-US' : 'ar-EG';
    try {
      return new Date(String(value).replace(' ', 'T') + 'Z').toLocaleString(locale, {
        dateStyle: 'short',
        timeStyle: 'short'
      });
    } catch (_) {
      return esc(value);
    }
  };

  const dateOnly = value => {
    if (!value) return '—';
    const lang = getLang();
    const locale = lang === 'en' ? 'en-US' : 'ar-EG';
    try {
      return new Date(String(value).replace(' ', 'T') + 'Z').toLocaleDateString(locale);
    } catch (_) {
      return esc(value);
    }
  };

  const labels = {
    ar: {
      active: 'نشط',
      inactive: 'غير نشط',
      suspended: 'موقوف',
      completed: 'مكتمل',
      in_progress: 'قيد التنفيذ',
      not_started: 'لم يبدأ',
      cancelled: 'ملغي',
      allowed: 'مسموح',
      blocked: 'محظور',
      expired: 'منتهي',
      pending: 'معلّق',
      under_review: 'قيد المراجعة',
      confirmed: 'مؤكد',
      rejected: 'مرفوض',
      refunded: 'مسترد',
      failed: 'راسب',
      present: 'حاضر',
      absent: 'غائب',
      late: 'متأخر',
      submitted: 'تم التسليم',
      graded: 'تم التصحيح',
      passed: 'ناجح',
      issued: 'صادرة',
      revoked: 'ملغاة'
    },
    en: {
      active: 'Active',
      inactive: 'Inactive',
      suspended: 'Suspended',
      completed: 'Completed',
      in_progress: 'In Progress',
      not_started: 'Not Started',
      cancelled: 'Cancelled',
      allowed: 'Allowed',
      blocked: 'Blocked',
      expired: 'Expired',
      pending: 'Pending',
      under_review: 'Under Review',
      confirmed: 'Confirmed',
      rejected: 'Rejected',
      refunded: 'Refunded',
      failed: 'Failed',
      present: 'Present',
      absent: 'Absent',
      late: 'Late',
      submitted: 'Submitted',
      graded: 'Graded',
      passed: 'Passed',
      issued: 'Issued',
      revoked: 'Revoked'
    }
  };

  const badge = value => {
    const lang = getLang();
    const dict = labels[lang] || labels.ar;
    const text = dict[value] || labels.ar[value] || value || '—';
    return `<span class="badge ${esc(value)}">${esc(text)}</span>`;
  };

  function getInitials(name) {
    if (!name) return '🩺';
    const parts = name.trim().split(/\s+/);
    if (parts.length >= 2) {
      return `${parts[0][0]} ${parts[1][0]}`.toUpperCase();
    }
    return name.slice(0, 2).toUpperCase();
  }

  function notice(message, error = false) {
    const element = getMessageEl();
    if (!element) return;
    element.textContent = message;
    element.className = 'notice' + (error ? ' error' : '');
    element.hidden = false;
  }

  function clearNotice() {
    const element = getMessageEl();
    if (!element) return;
    element.textContent = '';
    element.hidden = true;
  }

  async function api(url, options = {}) {
    const headers = {
      Accept: 'application/json',
      ...(options.headers || {})
    };

    if (options.body) {
      headers['Content-Type'] = 'application/json';
    }

    const method = (options.method || 'GET').toUpperCase();
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method) && csrfToken) {
      headers['X-CSRF-Token'] = csrfToken;
    }

    const response = await fetch(url, {
      ...options,
      headers,
      credentials: 'same-origin'
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.success === false) {
      throw new Error(data.message || `HTTP ${response.status}`);
    }

    if (data.csrf_token) {
      csrfToken = data.csrf_token;
    }

    return data;
  }

  function table(headers, rows) {
    if (!rows?.length) {
      return `
        <div class="detail-empty">
          <span class="empty-icon">📂</span>
          <strong>${esc(t('no_data'))}</strong>
          <span>${esc(t('no_data_desc'))}</span>
        </div>
      `;
    }

    return `
      <div class="detail-table-wrapper">
        <table class="detail-table">
          <thead>
            <tr>
              ${headers.map(header => `<th>${esc(header)}</th>`).join('')}
            </tr>
          </thead>
          <tbody>
            ${rows.join('')}
          </tbody>
        </table>
      </div>
    `;
  }

  function row(cells) {
    return `<tr>${cells.map(cell => `<td>${cell}</td>`).join('')}</tr>`;
  }

  // ==========================================
  // عرض تفاصيل الطالب
  // ==========================================

  function render(data) {
    const content = getContentEl();
    if (!content) {
      throw new Error('عنصر عرض بيانات الطالب غير موجود.');
    }

    student = data.student;
    if (!student) {
      throw new Error('لم يتم العثور على بيانات الطالب.');
    }

    const enrollments = data.enrollments || [];
    const quizzes = data.quiz_attempts || [];
    const assignments = data.assignments || [];
    const payments = data.payments || [];
    const attendance = data.attendance || [];
    const certificates = data.certificates || [];

    const completedLessons = enrollments.reduce(
      (total, enrollment) => total + Number(enrollment.completed_lessons || 0),
      0
    );

    const totalLessons = enrollments.reduce(
      (total, enrollment) => total + Number(enrollment.total_lessons || 0),
      0
    );

    const averageProgress = enrollments.length
      ? enrollments.reduce(
          (total, enrollment) => total + Number(enrollment.progress_percent || 0),
          0
        ) / enrollments.length
      : 0;

    const completedCoursesCount = enrollments.filter(e => e.status === 'completed').length;
    const activeEnrollmentsCount = enrollments.filter(e => e.status === 'active').length;
    const passedQuizzesCount = quizzes.filter(q => q.passed == 1).length;
    const totalConfirmedPayments = payments
      .filter(p => p.status === 'confirmed')
      .reduce((sum, p) => sum + Number(p.amount || 0), 0);

    const who = $('#who');
    if (who) {
      who.textContent = student.full_name || 'ملف الطالب';
    }

    content.className = '';

    content.innerHTML = `
      <!-- 1. Hero Profile Card (نسبة وتناسب متناسقة) -->
      <div class="detail-hero">
        <div class="student-ident">
          <div class="detail-avatar">${esc(getInitials(student.full_name))}</div>
          <div class="detail-hero-info">
            <h2>
              <span>${esc(student.full_name)}</span>
              ${badge(student.status)}
            </h2>
            <div class="student-email">
              <span>✉️</span>
              <span>${esc(student.email)}</span>
            </div>
            <div class="detail-hero-meta">
              <span class="detail-meta-pill">📞 ${esc(student.phone || t('no_phone'))}</span>
              <span class="detail-meta-pill">🎓 ${esc(t('student_id'))}: #${esc(student.id)}</span>
              <span class="detail-meta-pill">📅 ${esc(t('info_registered_at'))}: ${esc(dateOnly(student.created_at))}</span>
            </div>
          </div>
        </div>

        <div class="detail-actions">
          <button class="btn btn-primary" id="editStudentDetailsBtn" type="button">
            <span>✏️</span>
            <span>${esc(t('edit_data'))}</span>
          </button>

          <button class="btn btn-outline" id="toggleStudentDetailsStatus" type="button">
            ${student.status === 'active' ? esc(t('suspend_acc')) : esc(t('activate_acc'))}
          </button>
        </div>
      </div>

      <!-- 2. Statistics KPI Cards (4 Balanced Proportional Cards) -->
      <div class="detail-kpis">
        <div class="detail-kpi">
          <div class="detail-kpi-header">
            <span class="detail-kpi-label">${esc(t('kpi_courses'))}</span>
            <div class="detail-kpi-icon">📚</div>
          </div>
          <div class="detail-kpi-value">${num(enrollments.length)}</div>
          <div class="detail-kpi-description">${esc(t('kpi_courses_desc'))}</div>
        </div>

        <div class="detail-kpi">
          <div class="detail-kpi-header">
            <span class="detail-kpi-label">${esc(t('kpi_progress'))}</span>
            <div class="detail-kpi-icon">📊</div>
          </div>
          <div class="detail-kpi-value">${num(averageProgress.toFixed(1))}%</div>
          <div class="detail-kpi-description">${esc(t('kpi_progress_desc'))}</div>
        </div>

        <div class="detail-kpi">
          <div class="detail-kpi-header">
            <span class="detail-kpi-label">${esc(t('kpi_lessons'))}</span>
            <div class="detail-kpi-icon">🎬</div>
          </div>
          <div class="detail-kpi-value">${num(completedLessons)} <small style="font-size:16px;font-weight:600;color:var(--slate-400)">/ ${num(totalLessons)}</small></div>
          <div class="detail-kpi-description">${esc(t('kpi_lessons_desc'))}</div>
        </div>

        <div class="detail-kpi">
          <div class="detail-kpi-header">
            <span class="detail-kpi-label">${esc(t('kpi_quizzes'))}</span>
            <div class="detail-kpi-icon">📝</div>
          </div>
          <div class="detail-kpi-value">${num(quizzes.length)}</div>
          <div class="detail-kpi-description">${esc(t('kpi_quizzes_desc'))}</div>
        </div>
      </div>

      <!-- 3. Main Details Grid (2 Proportional Equal-Width Columns) -->
      <div class="detail-grid">
        <!-- Card 1: Account Information -->
        <section class="detail-card">
          <div class="detail-card-header">
            <div class="detail-card-header-left">
              <div class="detail-card-icon">👤</div>
              <div>
                <h3>${esc(t('account_info_title'))}</h3>
                <p>${esc(t('account_info_subtitle'))}</p>
              </div>
            </div>
          </div>
          <div class="detail-info-list">
            <div class="detail-info-item">
              <span class="detail-info-label">${esc(t('info_email'))}</span>
              <span class="detail-info-value">${esc(student.email || '—')}</span>
            </div>
            <div class="detail-info-item">
              <span class="detail-info-label">${esc(t('info_phone'))}</span>
              <span class="detail-info-value">${esc(student.phone || '—')}</span>
            </div>
            <div class="detail-info-item">
              <span class="detail-info-label">${esc(t('info_registered_at'))}</span>
              <span class="detail-info-value">${esc(dateOnly(student.created_at))}</span>
            </div>
            <div class="detail-info-item">
              <span class="detail-info-label">${esc(t('info_last_login'))}</span>
              <span class="detail-info-value">${esc(date(student.last_login_at))}</span>
            </div>
            <div class="detail-info-item">
              <span class="detail-info-label">${esc(t('info_status'))}</span>
              <span class="detail-info-value">${badge(student.status)}</span>
            </div>
            <div class="detail-info-item">
              <span class="detail-info-label">${esc(t('info_id'))}</span>
              <span class="detail-info-value">#${esc(student.id)}</span>
            </div>
          </div>
        </section>

        <!-- Card 2: Academic Summary -->
        <section class="detail-card">
          <div class="detail-card-header">
            <div class="detail-card-header-left">
              <div class="detail-card-icon">🎓</div>
              <div>
                <h3>${esc(t('academic_summary_title'))}</h3>
                <p>${esc(t('academic_summary_subtitle'))}</p>
              </div>
            </div>
          </div>
          <div class="detail-info-list">
            <div class="detail-info-item">
              <span class="detail-info-label">${esc(t('summary_completed_courses'))}</span>
              <span class="detail-info-value">${num(completedCoursesCount)}</span>
            </div>
            <div class="detail-info-item">
              <span class="detail-info-label">${esc(t('summary_active_enrollments'))}</span>
              <span class="detail-info-value">${num(activeEnrollmentsCount)}</span>
            </div>
            <div class="detail-info-item">
              <span class="detail-info-label">${esc(t('summary_passed_quizzes'))}</span>
              <span class="detail-info-value">${num(passedQuizzesCount)}</span>
            </div>
            <div class="detail-info-item">
              <span class="detail-info-label">${esc(t('summary_assignments'))}</span>
              <span class="detail-info-value">${num(assignments.length)}</span>
            </div>
            <div class="detail-info-item">
              <span class="detail-info-label">${esc(t('summary_certificates'))}</span>
              <span class="detail-info-value">${num(certificates.length)}</span>
            </div>
            <div class="detail-info-item">
              <span class="detail-info-label">${esc(t('summary_total_payments'))}</span>
              <span class="detail-info-value">${money(totalConfirmedPayments)}</span>
            </div>
          </div>
        </section>

        <!-- 4. Full-Width Cards: Enrolled Courses & Progress -->
        <section class="detail-card full-width">
          <div class="detail-card-header">
            <div class="detail-card-header-left">
              <div class="detail-card-icon">📚</div>
              <div>
                <h3>${esc(t('courses_title'))}</h3>
                <p>${esc(t('courses_subtitle'))}</p>
              </div>
            </div>
          </div>

          ${table(
            [
              t('col_course'),
              t('col_enroll_status'),
              t('col_access'),
              t('col_completed_lessons'),
              t('col_progress'),
              t('col_enrolled_at')
            ],
            enrollments.map(enrollment => {
              const pct = Math.min(100, Math.max(0, Number(enrollment.progress_percent || 0)));
              return row([
                `<strong>${esc(enrollment.course_title)}</strong>`,
                badge(enrollment.status),
                badge(enrollment.access_status),
                `${num(enrollment.completed_lessons || 0)} / ${num(enrollment.total_lessons || 0)}`,
                `<div class="detail-progress-wrap">
                  <div class="detail-progress">
                    <div class="detail-progress-bar" style="width: ${pct}%"></div>
                  </div>
                  <span class="detail-progress-text">${num(pct.toFixed(0))}%</span>
                </div>`,
                esc(dateOnly(enrollment.enrolled_at))
              ]);
            })
          )}
        </section>

        <!-- 5. Full-Width Card: Quizzes -->
        <section class="detail-card full-width">
          <div class="detail-card-header">
            <div class="detail-card-header-left">
              <div class="detail-card-icon">📝</div>
              <div>
                <h3>${esc(t('quizzes_title'))}</h3>
                <p>${esc(t('quizzes_subtitle'))}</p>
              </div>
            </div>
          </div>

          ${table(
            [
              t('col_quiz'),
              t('col_attempt'),
              t('col_score'),
              t('col_percent'),
              t('col_result'),
              t('col_submitted_at')
            ],
            quizzes.map(quiz =>
              row([
                `<strong>${esc(quiz.quiz_title)}</strong>`,
                num(quiz.attempt_number),
                `${esc(quiz.score ?? '—')} / ${esc(quiz.max_score ?? '—')}`,
                `${num(quiz.percent_score || 0)}%`,
                quiz.passed == 1
                  ? badge('passed')
                  : quiz.passed === 0
                    ? badge('failed')
                    : '—',
                esc(date(quiz.submitted_at))
              ])
            )
          )}
        </section>

        <!-- 6. Full-Width Card: Assignments -->
        <section class="detail-card full-width">
          <div class="detail-card-header">
            <div class="detail-card-header-left">
              <div class="detail-card-icon">📋</div>
              <div>
                <h3>${esc(t('assignments_title'))}</h3>
                <p>${esc(t('assignments_subtitle'))}</p>
              </div>
            </div>
          </div>

          ${table(
            [
              t('col_assignment'),
              t('col_status'),
              t('col_grade'),
              t('col_submitted_at'),
              t('col_feedback')
            ],
            assignments.map(assignment =>
              row([
                `<strong>${esc(assignment.assignment_title)}</strong>`,
                badge(assignment.status),
                `<strong>${esc(assignment.grade ?? '—')}</strong>`,
                esc(date(assignment.submitted_at)),
                esc(assignment.feedback || '—')
              ])
            )
          )}
        </section>

        <!-- 7. Full-Width Card: Payments -->
        <section class="detail-card full-width">
          <div class="detail-card-header">
            <div class="detail-card-header-left">
              <div class="detail-card-icon">💳</div>
              <div>
                <h3>${esc(t('payments_title'))}</h3>
                <p>${esc(t('payments_subtitle'))}</p>
              </div>
            </div>
          </div>

          ${table(
            [
              t('col_course'),
              t('col_amount'),
              t('col_method'),
              t('col_status'),
              t('col_trans_ref'),
              t('col_date')
            ],
            payments.map(payment =>
              row([
                `<strong>${esc(payment.course_title || '—')}</strong>`,
                `<strong>${money(payment.amount, payment.currency)}</strong>`,
                esc(payment.payment_method || '—'),
                badge(payment.status),
                `<code>${esc(payment.transaction_reference || '—')}</code>`,
                esc(date(payment.paid_at || payment.created_at))
              ])
            )
          )}
        </section>

        <!-- 8. Full-Width Card: Attendance -->
        <section class="detail-card full-width">
          <div class="detail-card-header">
            <div class="detail-card-header-left">
              <div class="detail-card-icon">📡</div>
              <div>
                <h3>${esc(t('attendance_title'))}</h3>
                <p>${esc(t('attendance_subtitle'))}</p>
              </div>
            </div>
          </div>

          ${table(
            [
              t('col_session'),
              t('col_status'),
              t('col_joined_at'),
              t('col_left_at')
            ],
            attendance.map(item =>
              row([
                `<strong>${esc(item.session_title || 'جلسة تدريبية مباشرة')}</strong>`,
                badge(item.status),
                esc(date(item.joined_at)),
                esc(date(item.left_at))
              ])
            )
          )}
        </section>

        <!-- 9. Full-Width Card: Certificates -->
        <section class="detail-card full-width">
          <div class="detail-card-header">
            <div class="detail-card-header-left">
              <div class="detail-card-icon">🏆</div>
              <div>
                <h3>${esc(t('certificates_title'))}</h3>
                <p>${esc(t('certificates_subtitle'))}</p>
              </div>
            </div>
          </div>

          ${table(
            [
              t('col_course'),
              t('col_cert_name'),
              t('col_issued_at'),
              t('col_status')
            ],
            certificates.map(certificate =>
              row([
                `<strong>${esc(certificate.course_title_snapshot || certificate.course_title || '—')}</strong>`,
                esc(certificate.student_name_snapshot || student.full_name),
                esc(dateOnly(certificate.issued_at)),
                certificate.revoked_at ? badge('revoked') : badge('issued')
              ])
            )
          )}
        </section>
      </div>
    `;

    // Bind Edit Button
    $('#editStudentDetailsBtn')?.addEventListener('click', () => {
      openEditForm();
    });

    // Bind Status Toggle Button
    $('#toggleStudentDetailsStatus')?.addEventListener('click', async event => {
      const button = event.currentTarget;
      const nextStatus = student.status === 'active' ? 'suspended' : 'active';
      const confirmation = nextStatus === 'suspended' ? t('confirm_suspend') : t('confirm_activate');

      if (!window.confirm(confirmation)) return;
      button.disabled = true;

      try {
        await api(`api/admin/student-details.php?id=${studentId}`, {
          method: 'POST',
          body: JSON.stringify({ action: 'status', status: nextStatus })
        });

        notice(t('status_success'));
        await loadStudent(studentId);
      } catch (error) {
        notice(error.message, true);
      } finally {
        button.disabled = false;
      }
    });
  }

  // ==========================================
  // نموذج تعديل الطالب
  // ==========================================

  function openEditForm() {
    if (!student) return;

    const form = getEditFormEl();
    const card = getEditCardEl();

    if (!form || !card) {
      notice('نموذج تعديل الطالب غير موجود.', true);
      return;
    }

    if (form.elements.full_name) {
      form.elements.full_name.value = student.full_name || '';
    }
    if (form.elements.email) {
      form.elements.email.value = student.email || '';
    }
    if (form.elements.phone) {
      form.elements.phone.value = student.phone || '';
    }
    if (form.elements.status) {
      form.elements.status.value = student.status || 'active';
    }

    card.hidden = false;
    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  async function saveStudent(event) {
    event.preventDefault();
    if (!studentId || !student) {
      notice('افتح ملف الطالب أولًا.', true);
      return;
    }

    const form = event.currentTarget;
    const formData = new FormData(form);
    const submitButton = form.querySelector('[type="submit"]');

    if (submitButton) {
      submitButton.disabled = true;
    }

    try {
      await api(`api/admin/student-details.php?id=${studentId}`, {
        method: 'POST',
        body: JSON.stringify({
          action: 'update',
          full_name: formData.get('full_name'),
          email: formData.get('email'),
          phone: formData.get('phone'),
          status: formData.get('status')
        })
      });

      const editCard = getEditCardEl();
      if (editCard) editCard.hidden = true;

      notice(t('save_success'));
      await loadStudent(studentId);
    } catch (error) {
      notice(error.message, true);
    } finally {
      if (submitButton) {
        submitButton.disabled = false;
      }
    }
  }

  // ==========================================
  // تحميل بيانات الطالب
  // ==========================================

  async function loadStudent(id) {
    const currentRequest = ++requestVersion;
    const content = getContentEl();

    if (!content) {
      throw new Error('عنصر عرض بيانات الطالب غير موجود.');
    }

    content.innerHTML = `
      <div class="loading" style="padding:40px;text-align:center;color:var(--slate-500,#64748b);">
        <div style="font-size:32px;margin-bottom:12px;">⏳</div>
        <div>جارٍ تحميل بيانات الطالب...</div>
      </div>
    `;

    clearNotice();

    try {
      const data = await api(`api/admin/student-details.php?id=${encodeURIComponent(id)}`);
      if (currentRequest !== requestVersion) return;
      render(data);
    } catch (error) {
      if (currentRequest !== requestVersion) return;
      content.innerHTML = `
        <div class="detail-empty" style="color:var(--danger,#dc2626);">
          <span class="empty-icon">⚠️</span>
          <strong>تعذر تحميل بيانات الطالب</strong>
          <div>${esc(error.message || 'خطأ غير معروف')}</div>
        </div>
      `;
      throw error;
    }
  }

  // ==========================================
  // التصدير والرجوع
  // ==========================================

  function exportStudentCsv() {
    if (!studentId) {
      notice('لا يوجد طالب محدد للتصدير.', true);
      return;
    }
    window.location.href = `api/admin/student-details.php?id=${encodeURIComponent(studentId)}&export=csv`;
  }

  function close() {
    requestVersion++;
    studentId = 0;
    student = null;

    const editCard = getEditCardEl();
    const editForm = getEditFormEl();
    if (editCard) editCard.hidden = true;
    if (editForm) editForm.reset();
    clearNotice();
  }

  // ربط الأحداث
  const editForm = getEditFormEl();
  editForm?.addEventListener('submit', saveStudent);

  const cancelEditBtn = $('#cancelStudentDetailsEdit') || $('#cancelEdit');
  cancelEditBtn?.addEventListener('click', () => {
    const card = getEditCardEl();
    if (card) card.hidden = true;
    clearNotice();
  });

  const exportBtn = $('#exportStudentCsv') || $('#exportBtn');
  exportBtn?.addEventListener('click', exportStudentCsv);

  $('#logout')?.addEventListener('click', async () => {
    try {
      await fetch('api/auth/logout.php', { method: 'POST', credentials: 'same-origin' });
      window.location.href = 'auth/login.php';
    } catch (_) {}
    window.location.href = 'admin.html';
  });

  // واجهة يستدعيها admin.js عند اختيار طالب
  window.StudentDetails = {
    async open(id) {
      const parsedId = Number(id);
      if (!Number.isInteger(parsedId) || parsedId <= 0) {
        throw new Error('رقم الطالب غير صحيح.');
      }
      studentId = parsedId;
      const editCard = getEditCardEl();
      if (editCard) editCard.hidden = true;
      const editForm = getEditFormEl();
      if (editForm) editForm.reset();
      await loadStudent(studentId);
    },
    close,
    rerender() {
      if (studentId) loadStudent(studentId);
    }
  };

  // تهيئة الصفحة إذا تم فتح student-details.html مباشرة
  const initFromUrl = () => {
    const params = new URLSearchParams(window.location.search);
    const paramId = params.get('id') || params.get('student_id');

    if (paramId && Number(paramId) > 0) {
      window.StudentDetails.open(paramId).catch(err => {
        console.error('Error loading student details:', err);
      });
    } else if ($('#content')) {
      const content = $('#content');
      content.className = '';
      content.innerHTML = `
        <div style="text-align:center;padding:48px 20px;background:var(--white,#fff);border:1px solid #e2e8f0;border-radius:18px;margin-top:20px;box-shadow:0 4px 20px rgba(0,0,0,0.03);">
          <div style="font-size:48px;margin-bottom:14px;">👨‍🎓</div>
          <h3 style="font-size:19px;margin-bottom:8px;color:#0f172a;font-weight:800;">يرجى اختيار طالب من لوحة الإدارة</h3>
          <p style="color:#64748b;margin-bottom:24px;font-size:14px;">لم يتم تمرير معرّف الطالب في الرابط (الرابط يحتاج إلى ?id=...).</p>
          <a href="admin.html#students" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;">الانتقال إلى قائمة الطلاب في لوحة الإدارة ←</a>
        </div>
      `;
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFromUrl);
  } else {
    initFromUrl();
  }
})();
