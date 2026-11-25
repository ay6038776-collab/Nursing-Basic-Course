/**
 * admin-features.js — Handles Enrollments, Payments, Quizzes,
 * Certificates, Skills, Notifications, Support, Reports panels
 * NBC v3.0
 */
(function () {
  'use strict';

  /* ═══════════════════════════════════════════════
     HELPERS
  ═══════════════════════════════════════════════ */
  const API = (path, params = {}) => {
    const url = new URL('api/' + path, location.origin + location.pathname.replace(/\/[^/]*$/, '/'));
    Object.entries(params).forEach(([k, v]) => v !== '' && v !== null && v !== undefined && url.searchParams.set(k, v));
    return url.toString();
  };
  const locale = () => document.documentElement.lang === 'en' ? 'en-US' : 'ar-EG';

  async function apiFetch(path, params = {}, options = {}) {
    try {
      const headers = { Accept: 'application/json', ...(options.headers || {}) };
      const csrf = window.AdminCore ? window.AdminCore.csrf() : '';
      if (csrf) {
        headers['X-CSRF-Token'] = csrf;
      }
      const res = await fetch(API(path, params), { credentials: 'same-origin', ...options, headers });
      const data = await res.json();
      if (data && data.csrf_token && window.AdminCore) {
        // keep CSRF token refreshed
      }
      return data;
    } catch (e) {
      return { success: false, message: e.message };
    }
  }

  async function apiPost(path, body = {}, params = {}) {
    const csrf = window.AdminCore ? window.AdminCore.csrf() : '';
    const payload = (csrf && typeof body === 'object' && body !== null) 
      ? { ...body, csrf_token: csrf } 
      : body;

    return apiFetch(path, params, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
  }

  function showMsg(el, msg, isError = false) {
    if (!el) return;
    el.textContent = msg;
    el.className = 'notice ' + (isError ? 'notice-error' : 'notice-success');
    el.hidden = false;
    setTimeout(() => { el.hidden = true; }, 5000);
  }

  function statusBadge(status) {
    const map = {
      active: ['نشط', '#d1fae5', '#065f46'],
      pending: ['معلق', '#fef3c7', '#92400e'],
      completed: ['مكتمل', '#dbeafe', '#1e40af'],
      cancelled: ['ملغي', '#fee2e2', '#991b1b'],
      suspended: ['موقوف', '#f3e8ff', '#6b21a8'],
      paid: ['مؤكد', '#d1fae5', '#065f46'],
      new: ['جديد', '#fef3c7', '#92400e'],
      rejected: ['مرفوض', '#fee2e2', '#991b1b'],
      refunded: ['مسترد', '#e0e7ff', '#3730a3'],
      granted: ['مفتوح', '#d1fae5', '#065f46'],
      blocked: ['محجوب', '#fee2e2', '#991b1b'],
      restricted: ['مقيد', '#fef3c7', '#92400e'],
      issued: ['صادرة', '#d1fae5', '#065f46'],
      revoked: ['مسحوبة', '#fee2e2', '#991b1b'],
      open: ['مفتوحة', '#fef3c7', '#92400e'],
      in_progress: ['جارية', '#dbeafe', '#1e40af'],
      waiting: ['انتظار', '#f3e8ff', '#6b21a8'],
      resolved: ['محلولة', '#d1fae5', '#065f46'],
      closed: ['مغلقة', '#f1f5f9', '#64748b'],
    };
    const [label, bg, color] = map[status] || [status, '#f1f5f9', '#64748b'];
    return `<span style="background:${bg};color:${color};padding:2px 10px;border-radius:20px;font-size:12px;font-weight:600">${label}</span>`;
  }

  function formatDate(dt) {
    if (!dt) return '—';
    return new Date(dt).toLocaleDateString(locale(), { year: 'numeric', month: 'short', day: 'numeric' });
  }

  function formatMoney(n) {
    if (n === null || n === undefined || n === '') return '—';
    return parseFloat(n).toLocaleString(locale()) + (document.documentElement.lang === 'en' ? ' EGP' : ' ج.م');
  }

  function paginator(containerId, meta, onPage) {
    const c = document.getElementById(containerId);
    if (!c || !meta) return;
    c.innerHTML = '';
    if (meta.pages <= 1) return;
    for (let p = 1; p <= meta.pages; p++) {
      const btn = document.createElement('button');
      btn.className = 'btn ' + (p === meta.page ? 'btn-primary' : 'btn-ghost');
      btn.style.minWidth = '36px';
      btn.textContent = p;
      btn.onclick = () => onPage(p);
      c.appendChild(btn);
    }
  }

  /* ═══════════════════════════════════════════════
     PANEL LOADER — hook into existing tab system
  ═══════════════════════════════════════════════ */
  let currentPanel = '';

  document.addEventListener('click', e => {
    const btn = e.target.closest('[data-tab]');
    if (!btn) return;
    const tab = btn.dataset.tab;
    if (tab === currentPanel) return;
    currentPanel = tab;
    if (tab === 'enrollments') loadEnrollments();
    if (tab === 'payments')    loadPayments();
    if (tab === 'quizzes')     initQuizzes();
    if (tab === 'certificates') loadCertificates();
    if (tab === 'skills')      loadSkills();
    if (tab === 'notifications') loadNotifications();
    if (tab === 'support')     loadSupport();
    if (tab === 'reports')     loadReports();
    if (tab === 'progress')    loadProgressOverview();
  });

  /* ═══════════════════════════════════════════════
     10. ENROLLMENTS
  ═══════════════════════════════════════════════ */
  let enrPage = 1;

  function loadEnrollments(page = 1) {
    enrPage = page;
    const search   = document.getElementById('enrSearch')?.value || '';
    const status   = document.getElementById('enrStatus')?.value || '';
    const tbody    = document.getElementById('enrollmentsTbody');
    const countEl  = document.getElementById('enrCount');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px">جارٍ التحميل...</td></tr>';

    apiFetch('admin/enrollments.php', { action: 'list', page, search, status }).then(data => {
      if (!data.success) { tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:red">${data.message}</td></tr>`; return; }
      const rows = data.enrollments || [];
      if (countEl) countEl.textContent = `إجمالي: ${data.meta?.total || 0} تسجيل`;
      tbody.innerHTML = rows.length ? rows.map(r => `
        <tr>
          <td>${r.id}</td>
          <td><strong>${r.student_name}</strong><br><small style="color:var(--slate-400)">${r.student_email}</small></td>
          <td>${r.course_title}</td>
          <td>${statusBadge(r.status)}</td>
          <td>${statusBadge(r.access_status)}</td>
          <td>${formatDate(r.enrolled_at)}</td>
          <td>${r.expires_at ? formatDate(r.expires_at) : '∞'}</td>
          <td style="display:flex;gap:4px;flex-wrap:wrap">
            <button class="btn btn-ghost" style="font-size:11px" onclick="adminEnrHistory(${r.id})">📋 سجل</button>
            ${r.status === 'active' ? `<button class="btn btn-ghost" style="font-size:11px;color:#991b1b" onclick="adminEnrCancel(${r.id})">❌ إلغاء</button>` : ''}
            ${r.status !== 'active' ? `<button class="btn btn-ghost" style="font-size:11px;color:#065f46" onclick="adminEnrActivate(${r.id})">✅ تفعيل</button>` : ''}
            <button class="btn btn-ghost" style="font-size:11px" onclick="adminEnrExtend(${r.id})">📅 تمديد</button>
          </td>
        </tr>`).join('') : '<tr><td colspan="8" style="text-align:center;padding:32px;color:var(--slate-400)">لا توجد تسجيلات</td></tr>';

      paginator('enrPagination', data.meta, p => loadEnrollments(p));
    });
  }

  window.adminEnrHistory = async id => {
    const data = await apiFetch('admin/enrollments.php', { action: 'history', enrollment_id: id });
    const modal = document.getElementById('enrollHistoryModal');
    const content = document.getElementById('enrollHistoryContent');
    if (!modal || !content) return;
    if (!data.success) { alert(data.message); return; }
    content.innerHTML = data.events.length
      ? `<table class="data-table"><thead><tr><th>الحالة الجديدة</th><th>الحالة القديمة</th><th>المسؤول</th><th>الملاحظة</th><th>التاريخ</th></tr></thead><tbody>${
        data.events.map(e => `<tr><td>${statusBadge(e.new_status)}</td><td>${statusBadge(e.old_status || 'pending')}</td><td>${e.actor_name || '—'}</td><td>${e.note || '—'}</td><td>${formatDate(e.created_at)}</td></tr>`).join('')
      }</tbody></table>`
      : '<p style="text-align:center;color:var(--slate-400);padding:24px">لا توجد أحداث مسجلة</p>';
    modal.hidden = false;
  };

  window.adminEnrCancel = async id => {
    const reason = prompt('سبب الإلغاء (اختياري):') ?? '';
    if (!confirm('هل تريد إلغاء هذا التسجيل؟')) return;
    const data = await apiPost('admin/enrollments.php', { enrollment_id: id, reason }, { action: 'cancel' });
    showMsg(document.getElementById('enrollmentsMsg'), data.message, !data.success);
    if (data.success) loadEnrollments(enrPage);
  };

  window.adminEnrActivate = async id => {
    const data = await apiPost('admin/enrollments.php', { enrollment_id: id, status: 'active', access_status: 'granted' }, { action: 'update_status' });
    showMsg(document.getElementById('enrollmentsMsg'), data.message, !data.success);
    if (data.success) loadEnrollments(enrPage);
  };

  window.adminEnrExtend = async id => {
    const d = prompt('تاريخ الانتهاء الجديد (YYYY-MM-DD):');
    if (!d) return;
    const data = await apiPost('admin/enrollments.php', { enrollment_id: id, expires_at: d }, { action: 'extend' });
    showMsg(document.getElementById('enrollmentsMsg'), data.message, !data.success);
    if (data.success) loadEnrollments(enrPage);
  };

  // New enrollment modal
  document.getElementById('btnNewEnrollment')?.addEventListener('click', () => {
    document.getElementById('newEnrollmentModal').hidden = false;
  });
  document.getElementById('closeEnrollModal')?.addEventListener('click', () => {
    document.getElementById('newEnrollmentModal').hidden = true;
  });
  document.getElementById('btnCancelEnroll')?.addEventListener('click', () => {
    document.getElementById('newEnrollmentModal').hidden = true;
  });
  document.getElementById('btnDoEnroll')?.addEventListener('click', async () => {
    const data = await apiPost('admin/enrollments.php', {
      student_id: document.getElementById('enrStudentId').value,
      course_id:  document.getElementById('enrCourseId').value,
      starts_at:  document.getElementById('enrStartDate').value,
      expires_at: document.getElementById('enrEndDate').value,
      note:       document.getElementById('enrNote').value,
    }, { action: 'create' });
    showMsg(document.getElementById('enrollFormMsg'), data.message, !data.success);
    if (data.success) { document.getElementById('newEnrollmentModal').hidden = true; loadEnrollments(enrPage); }
  });
  document.getElementById('btnLoadEnrollments')?.addEventListener('click', () => loadEnrollments(1));
  document.getElementById('closeEnrollHistory')?.addEventListener('click', () => {
    document.getElementById('enrollHistoryModal').hidden = true;
  });

  /* ═══════════════════════════════════════════════
     11. PAYMENTS
  ═══════════════════════════════════════════════ */
  let payPage = 1;

  async function loadPayments(page = 1) {
    payPage = page;
    const tbody = document.getElementById('paymentsTbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:24px">جارٍ التحميل...</td></tr>';

    const params = {
      action: 'list', page,
      search:    document.getElementById('paySearch')?.value || '',
      status:    document.getElementById('payStatus')?.value || '',
      date_from: document.getElementById('payDateFrom')?.value || '',
      date_to:   document.getElementById('payDateTo')?.value || '',
    };

    const [data, statsData] = await Promise.all([
      apiFetch('admin/payments.php', params),
      apiFetch('admin/payments.php', { action: 'stats' })
    ]);

    // Stats cards
    if (statsData.success) {
      const bs = statsData.by_status || {};
      document.getElementById('pStatConfirmed').textContent = formatMoney(bs.paid?.total || 0);
      document.getElementById('pStatPending').textContent   = formatMoney(bs.new?.total || 0);
      document.getElementById('pStatRejected').textContent  = bs.rejected?.count || 0;
      document.getElementById('pStatRefunded').textContent  = formatMoney(bs.refunded?.total || 0);
    }

    if (!data.success) { tbody.innerHTML = `<tr><td colspan="9" style="color:red;text-align:center">${data.message}</td></tr>`; return; }

    const orders = data.orders || [];
    tbody.innerHTML = orders.length ? orders.map(o => {
      const courseName = o.items?.[0]?.course_title || '—';
      const proofLink  = o.payment_proof_path
        ? `<a href="${o.payment_proof_path}" target="_blank" style="color:var(--teal-600);font-size:12px">📎 عرض</a>`
        : '<span style="color:var(--slate-400);font-size:12px">لا يوجد</span>';
      const actions = o.status === 'new' || o.status === 'pending_review'
        ? `<button class="btn btn-ghost" style="font-size:11px;color:#065f46" onclick="payApprove(${o.id})">✅ تأكيد</button>
           <button class="btn btn-ghost" style="font-size:11px;color:#991b1b" onclick="payReject(${o.id})">❌ رفض</button>`
        : o.status === 'paid'
          ? `<button class="btn btn-ghost" style="font-size:11px;color:#6b21a8" onclick="payRefund(${o.id})">↩ استرداد</button>`
          : '—';
      return `<tr>
        <td><small>${o.order_number}</small></td>
        <td><strong>${o.student_name}</strong><br><small style="color:var(--slate-400)">${o.student_email}</small></td>
        <td>${courseName}</td>
        <td style="font-weight:700;color:var(--teal-700)">${formatMoney(o.total_amount)}</td>
        <td>${o.payment_method || '—'}</td>
        <td>${statusBadge(o.status)}</td>
        <td>${formatDate(o.created_at)}</td>
        <td>${proofLink}</td>
        <td style="display:flex;gap:4px;flex-wrap:wrap">${actions}</td>
      </tr>`;
    }).join('') : '<tr><td colspan="9" style="text-align:center;padding:32px;color:var(--slate-400)">لا توجد طلبات</td></tr>';

    paginator('payPagination', data.meta, p => loadPayments(p));
  }

  window.payApprove = async id => {
    const note = prompt('ملاحظة التأكيد (اختياري):') ?? '';
    if (!confirm('تأكيد اعتماد هذه العملية وتفعيل التسجيل؟')) return;
    const data = await apiPost('admin/payments.php', { order_id: id, note }, { action: 'approve' });
    showMsg(document.getElementById('paymentsMsg'), data.message, !data.success);
    if (data.success) loadPayments(payPage);
  };
  window.payReject = async id => {
    const reason = prompt('سبب الرفض:');
    if (!reason) return;
    const data = await apiPost('admin/payments.php', { order_id: id, reason }, { action: 'reject' });
    showMsg(document.getElementById('paymentsMsg'), data.message, !data.success);
    if (data.success) loadPayments(payPage);
  };
  window.payRefund = async id => {
    const reason = prompt('سبب الاسترداد:');
    if (!reason) return;
    if (!confirm('هل تريد تسجيل استرداد وإلغاء التسجيل؟')) return;
    const data = await apiPost('admin/payments.php', { order_id: id, reason, cancel_enrollment: true }, { action: 'refund' });
    showMsg(document.getElementById('paymentsMsg'), data.message, !data.success);
    if (data.success) loadPayments(payPage);
  };

  document.getElementById('btnLoadPayments')?.addEventListener('click', () => loadPayments(1));

  /* ═══════════════════════════════════════════════
     12. QUIZZES
  ═══════════════════════════════════════════════ */
  let currentQuizId = null;

  async function initQuizzes() {
    // Load courses for filter
    const courseSel = document.getElementById('quizCourseFilter');
    const formCourseSel = document.getElementById('quizFormCourse');
    if (courseSel && !courseSel.dataset.loaded) {
      const data = await apiFetch('admin/courses.php', { action: 'list', per_page: 100 });
      const courses = data.courses || data.data || [];
      const opts = courses.map(c => `<option value="${c.id}">${c.title_ar}</option>`).join('');
      courseSel.innerHTML = '<option value="">كل الكورسات</option>' + opts;
      if (formCourseSel) formCourseSel.innerHTML = '<option value="">اختر كورساً</option>' + opts;
      courseSel.dataset.loaded = '1';
    }
    loadQuizList();
  }

  async function loadQuizList() {
    const container = document.getElementById('quizListContainer');
    if (!container) return;
    container.innerHTML = '<p style="text-align:center;padding:16px;color:var(--slate-400)">جارٍ التحميل...</p>';
    const courseId = document.getElementById('quizCourseFilter')?.value || '';
    const data = await apiFetch('admin/quizzes.php', { action: 'list', course_id: courseId });
    if (!data.success) { container.innerHTML = `<p style="color:red;padding:12px">${data.message}</p>`; return; }
    const quizzes = data.quizzes || [];
    container.innerHTML = quizzes.length
      ? quizzes.map(q => `
        <div class="quiz-list-item" onclick="loadQuizDetail(${q.id})"
          style="padding:12px 16px;border-bottom:1px solid var(--line);cursor:pointer;${currentQuizId===q.id?'background:var(--teal-50)':''}"
          data-quiz-id="${q.id}">
          <div style="font-weight:600;font-size:14px">${q.title_ar}</div>
          <div style="font-size:12px;color:var(--slate-500);margin-top:4px">${q.course_title || ''}</div>
          <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap">
            <span style="font-size:11px;background:#f1f5f9;padding:2px 8px;border-radius:12px">${q.question_count || 0} سؤال</span>
            <span style="font-size:11px;background:#f1f5f9;padding:2px 8px;border-radius:12px">${q.attempt_count || 0} محاولة</span>
            ${q.is_published ? '<span style="font-size:11px;background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:12px">منشور</span>' : '<span style="font-size:11px;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:12px">مسودة</span>'}
          </div>
        </div>`).join('')
      : '<p style="text-align:center;padding:24px;color:var(--slate-400)">لا توجد اختبارات</p>';
  }

  window.loadQuizDetail = async id => {
    currentQuizId = id;
    const panel = document.getElementById('quizDetailPanel');
    if (!panel) return;
    panel.innerHTML = '<div style="padding:24px;text-align:center;color:var(--slate-400)">جارٍ التحميل...</div>';
    // Highlight selected
    document.querySelectorAll('.quiz-list-item').forEach(el => el.style.background = el.dataset.quizId == id ? 'var(--teal-50)' : '');

    const data = await apiFetch('admin/quizzes.php', { action: 'get', quiz_id: id });
    if (!data.success) { panel.innerHTML = `<div style="padding:24px;color:red">${data.message}</div>`; return; }
    const q = data.quiz;

    panel.innerHTML = `
      <div class="surface-head" style="padding:16px;border-bottom:1px solid var(--line)">
        <div>
          <h3 style="margin:0">${q.title_ar}</h3>
          <p style="font-size:13px;color:var(--slate-500);margin:4px 0">${q.description_ar || ''}</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn btn-outline" style="font-size:12px" onclick="openQuizForm(${q.id})">✏️ تعديل</button>
          <button class="btn btn-primary" style="font-size:12px" onclick="openQuestionModal(${q.id})">+ سؤال</button>
          <button class="btn btn-ghost" style="font-size:12px" onclick="showAttempts(${q.id},'${q.title_ar}')">📊 النتائج</button>
          <button class="btn btn-ghost" style="font-size:12px;color:#991b1b" onclick="deleteQuiz(${q.id})">🗑</button>
        </div>
      </div>
      <div style="padding:16px;display:flex;gap:12px;flex-wrap:wrap">
        <span class="stat-mini"><b>${q.question_count || (q.questions?.length || 0)}</b> سؤال</span>
        <span class="stat-mini"><b>${q.total_points || 0}</b> درجة</span>
        <span class="stat-mini">النجاح: <b>${q.pass_score}%</b></span>
        <span class="stat-mini">المحاولات: <b>${q.max_attempts}</b></span>
        ${q.time_limit_minutes ? `<span class="stat-mini">المدة: <b>${q.time_limit_minutes} د</b></span>` : ''}
        ${q.shuffle_questions ? '<span class="stat-mini">🔀 عشوائي</span>' : ''}
        ${q.show_answers_after_submit ? '<span class="stat-mini">✅ عرض الإجابات</span>' : ''}
        ${statusBadge(q.is_published ? 'active' : 'pending')}
      </div>
      <div style="padding:0 16px 16px" id="questionsList">
        ${(q.questions || []).map((qn, i) => `
          <div style="border:1px solid var(--line);border-radius:8px;padding:12px;margin-bottom:8px">
            <div style="display:flex;align-items:flex-start;gap:8px">
              <span style="background:var(--teal-100);color:var(--teal-700);padding:2px 8px;border-radius:20px;font-size:12px;font-weight:700">${i+1}</span>
              <div style="flex:1">
                <div style="font-weight:500;font-size:14px">${qn.question_text}</div>
                <div style="font-size:12px;color:var(--slate-400);margin-top:4px">${{mcq:'اختيار متعدد',true_false:'صح/خطأ',short_answer:'إجابة قصيرة'}[qn.question_type]} | ${qn.points} درجة</div>
                ${(qn.options || []).map(op => `<div style="font-size:12px;padding:2px 0;${op.is_correct?'color:#065f46;font-weight:700':'color:var(--slate-500)'}">${op.is_correct?'✓':'○'} ${op.option_text}</div>`).join('')}
              </div>
              <button class="btn btn-ghost" style="font-size:11px;color:#991b1b;padding:4px 8px" onclick="deleteQuestion(${qn.id},${q.id})">🗑</button>
            </div>
          </div>`).join('') || '<p style="text-align:center;color:var(--slate-400);padding:16px">لا توجد أسئلة بعد. اضغط "+ سؤال" لإضافة أول سؤال.</p>'}
      </div>`;
  };

  window.openQuizForm = id => {
    const modal = document.getElementById('quizFormModal');
    document.getElementById('quizFormId').value = id || '';
    document.getElementById('quizFormTitle').textContent = id ? 'تعديل الاختبار' : 'اختبار جديد';
    if (id && currentQuizId === id) {
      // Load current quiz data - fill form
      apiFetch('admin/quizzes.php', { action: 'get', quiz_id: id }).then(data => {
        if (!data.success) return;
        const q = data.quiz;
        document.getElementById('quizFormTitleAr').value     = q.title_ar || '';
        document.getElementById('quizFormTitleEn').value     = q.title_en || '';
        document.getElementById('quizFormPassScore').value   = q.pass_score || 60;
        document.getElementById('quizFormMaxAttempts').value = q.max_attempts || 1;
        document.getElementById('quizFormTimeMins').value    = q.time_limit_minutes || 0;
        document.getElementById('quizFormShuffle').checked   = !!q.shuffle_questions;
        document.getElementById('quizFormShuffleOpts').checked = !!q.shuffle_options;
        document.getElementById('quizFormShowAnswers').checked = !!q.show_answers_after_submit;
        document.getElementById('quizFormPublished').checked   = !!q.is_published;
        document.getElementById('quizFormCourse').value = q.course_id;
      });
    } else {
      ['quizFormTitleAr','quizFormTitleEn'].forEach(id => { const el = document.getElementById(id); if(el) el.value = ''; });
    }
    modal.hidden = false;
  };

  document.getElementById('btnNewQuiz')?.addEventListener('click', () => openQuizForm(null));
  document.getElementById('closeQuizModal')?.addEventListener('click', () => { document.getElementById('quizFormModal').hidden = true; });
  document.getElementById('btnCancelQuizForm')?.addEventListener('click', () => { document.getElementById('quizFormModal').hidden = true; });

  document.getElementById('btnSaveQuiz')?.addEventListener('click', async () => {
    const id = document.getElementById('quizFormId').value;
    const body = {
      quiz_id:    id || undefined,
      course_id:  document.getElementById('quizFormCourse').value,
      title_ar:   document.getElementById('quizFormTitleAr').value,
      title_en:   document.getElementById('quizFormTitleEn').value,
      pass_score: document.getElementById('quizFormPassScore').value,
      max_attempts: document.getElementById('quizFormMaxAttempts').value,
      time_limit_minutes: document.getElementById('quizFormTimeMins').value,
      shuffle_questions: document.getElementById('quizFormShuffle').checked,
      shuffle_options:   document.getElementById('quizFormShuffleOpts').checked,
      show_answers_after_submit: document.getElementById('quizFormShowAnswers').checked,
      is_published: document.getElementById('quizFormPublished').checked,
    };
    const action = id ? 'update_quiz' : 'create_quiz';
    const data = await apiPost('admin/quizzes.php', body, { action });
    showMsg(document.getElementById('quizFormMsg'), data.message, !data.success);
    if (data.success) {
      document.getElementById('quizFormModal').hidden = true;
      loadQuizList();
      if (data.quiz_id || id) loadQuizDetail(data.quiz_id || parseInt(id));
    }
  });

  document.getElementById('quizCourseFilter')?.addEventListener('change', loadQuizList);

  window.deleteQuiz = async id => {
    if (!confirm('حذف هذا الاختبار نهائياً؟ لا يمكن التراجع.')) return;
    const data = await apiPost('admin/quizzes.php', { quiz_id: id }, { action: 'delete_quiz' });
    showMsg(document.getElementById('quizzesMsg'), data.message, !data.success);
    if (data.success) { currentQuizId = null; loadQuizList(); document.getElementById('quizDetailPanel').innerHTML = '<div style="padding:40px;text-align:center;color:var(--slate-400)"><div style="font-size:48px">📝</div><p>اختر اختباراً لعرض تفاصيله.</p></div>'; }
  };

  window.deleteQuestion = async (qId, quizId) => {
    if (!confirm('حذف هذا السؤال؟')) return;
    const data = await apiPost('admin/quizzes.php', { question_id: qId }, { action: 'delete_question' });
    if (data.success) loadQuizDetail(quizId);
  };

  // Question Modal
  window.openQuestionModal = quizId => {
    document.getElementById('qFormQuizId').value = quizId;
    document.getElementById('qFormText').value = '';
    document.getElementById('qFormPoints').value = '1';
    document.getElementById('qFormExpl').value = '';
    renderOptionInputs('mcq');
    document.getElementById('questionModal').hidden = false;
  };
  document.getElementById('closeQuestionModal')?.addEventListener('click', () => { document.getElementById('questionModal').hidden = true; });
  document.getElementById('btnCancelQuestion')?.addEventListener('click', () => { document.getElementById('questionModal').hidden = true; });

  document.getElementById('qFormType')?.addEventListener('change', e => renderOptionInputs(e.target.value));

  function renderOptionInputs(type) {
    const sec = document.getElementById('qOptionsSection');
    const list = document.getElementById('qOptionsList');
    const addBtn = document.getElementById('btnAddOption');
    if (!sec || !list) return;

    if (type === 'short_answer') { sec.style.display = 'none'; return; }
    sec.style.display = '';

    if (type === 'true_false') {
      addBtn.style.display = 'none';
      list.innerHTML = `
        <div class="opt-row" style="display:flex;gap:8px;align-items:center;margin-bottom:6px"><input type="radio" name="tfCorrect" value="0"> <input type="text" value="صحيح" style="flex:1;padding:6px;border-radius:6px;border:1px solid var(--line)"></div>
        <div class="opt-row" style="display:flex;gap:8px;align-items:center;margin-bottom:6px"><input type="radio" name="tfCorrect" value="1"> <input type="text" value="خطأ" style="flex:1;padding:6px;border-radius:6px;border:1px solid var(--line)"></div>`;
    } else {
      addBtn.style.display = '';
      if (!list.querySelector('.opt-row')) {
        list.innerHTML = '';
        for (let i = 0; i < 3; i++) addOptionRow(list);
      }
    }
  }

  function addOptionRow(list) {
    const div = document.createElement('div');
    div.className = 'opt-row';
    div.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px';
    div.innerHTML = `<input type="checkbox" class="opt-correct"> <input type="text" placeholder="نص الاختيار" style="flex:1;padding:6px;border-radius:6px;border:1px solid var(--line)"> <button type="button" style="color:#991b1b;background:none;border:none;cursor:pointer;font-size:16px" onclick="this.parentElement.remove()">✕</button>`;
    list.appendChild(div);
  }

  document.getElementById('btnAddOption')?.addEventListener('click', () => {
    addOptionRow(document.getElementById('qOptionsList'));
  });

  document.getElementById('btnSaveQuestion')?.addEventListener('click', async () => {
    const type = document.getElementById('qFormType').value;
    const quizId = document.getElementById('qFormQuizId').value;
    let options = [];

    if (type === 'true_false') {
      const rows = document.querySelectorAll('#qOptionsList .opt-row');
      const selected = document.querySelector('#qOptionsList input[name="tfCorrect"]:checked')?.value;
      rows.forEach((row, i) => {
        options.push({ text: row.querySelector('input[type="text"]').value, is_correct: String(i) === selected });
      });
    } else if (type === 'mcq') {
      document.querySelectorAll('#qOptionsList .opt-row').forEach(row => {
        const text = row.querySelector('input[type="text"]')?.value || '';
        const correct = row.querySelector('.opt-correct')?.checked || false;
        if (text) options.push({ text, is_correct: correct });
      });
    }

    const data = await apiPost('admin/quizzes.php', {
      quiz_id: quizId,
      question_text: document.getElementById('qFormText').value,
      question_type: type,
      points: document.getElementById('qFormPoints').value,
      explanation: document.getElementById('qFormExpl').value,
      options
    }, { action: 'add_question' });

    showMsg(document.getElementById('questionFormMsg'), data.message, !data.success);
    if (data.success) { document.getElementById('questionModal').hidden = true; loadQuizDetail(parseInt(quizId)); }
  });

  window.showAttempts = async (quizId, title) => {
    const sec = document.getElementById('quizAttemptsSection');
    const tbody = document.getElementById('attemptsTbody');
    document.getElementById('quizAttemptsTitle').textContent = `نتائج: ${title}`;
    sec.hidden = false;
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:16px">جارٍ التحميل...</td></tr>';
    const data = await apiFetch('admin/quizzes.php', { action: 'list_attempts', quiz_id: quizId });
    tbody.innerHTML = (data.attempts || []).map(a =>
      `<tr><td>${a.student_name}</td><td>${a.score || 0}/${a.total_points || '?'}</td><td>${a.score_percent || 0}%</td><td>${a.is_passed ? '✅ ناجح' : '❌ راسب'}</td><td>${formatDate(a.submitted_at)}</td></tr>`
    ).join('') || '<tr><td colspan="5" style="text-align:center;padding:16px;color:var(--slate-400)">لا توجد محاولات</td></tr>';
  };

  document.getElementById('btnCloseAttempts')?.addEventListener('click', () => { document.getElementById('quizAttemptsSection').hidden = true; });

  /* ═══════════════════════════════════════════════
     14. CERTIFICATES
  ═══════════════════════════════════════════════ */
  async function loadCertificates() {
    const tbody = document.getElementById('certsTbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px">جارٍ التحميل...</td></tr>';
    const search = document.getElementById('certSearch')?.value || '';
    const data = await apiFetch('admin/certificates.php', { action: 'list', search });
    const certs = data.certificates || [];
    tbody.innerHTML = certs.length ? certs.map(c => `
      <tr>
        <td><code style="font-size:11px">${c.certificate_number}</code></td>
        <td>${c.student_name}</td>
        <td>${c.course_title}</td>
        <td>${formatDate(c.issued_at)}</td>
        <td>${statusBadge(c.status)}</td>
        <td><a href="/NursingBasicCourse/api/public/verify-certificate.php?cert=${c.certificate_number}" target="_blank" style="color:var(--teal-600);font-size:12px">🔗 تحقق</a></td>
        <td>${c.status === 'issued' ? `<button class="btn btn-ghost" style="font-size:11px;color:#991b1b" onclick="revokeCert(${c.id})">🚫 سحب</button>` : '—'}</td>
      </tr>`).join('')
    : '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--slate-400)">لا توجد شهادات</td></tr>';
  }

  document.getElementById('btnIssueCertificate')?.addEventListener('click', () => {
    document.getElementById('issueCertForm').hidden = !document.getElementById('issueCertForm').hidden;
  });
  document.getElementById('btnCancelIssueCert')?.addEventListener('click', () => {
    document.getElementById('issueCertForm').hidden = true;
  });
  document.getElementById('certSearch')?.addEventListener('input', loadCertificates);

  document.getElementById('btnDoIssueCert')?.addEventListener('click', async () => {
    const data = await apiPost('admin/certificates.php', {
      student_id: document.getElementById('certStudentId').value,
      course_id:  document.getElementById('certCourseId').value,
      force:      document.getElementById('certForce').checked,
    }, { action: 'issue' });
    showMsg(document.getElementById('certificatesMsg'), data.message, !data.success);
    if (data.success) { document.getElementById('issueCertForm').hidden = true; loadCertificates(); }
  });

  window.revokeCert = async id => {
    const reason = prompt('سبب سحب الشهادة:');
    if (!reason) return;
    const data = await apiPost('admin/certificates.php', { certificate_id: id, reason }, { action: 'revoke' });
    showMsg(document.getElementById('certificatesMsg'), data.message, !data.success);
    if (data.success) loadCertificates();
  };

  /* ═══════════════════════════════════════════════
     15. SKILLS
  ═══════════════════════════════════════════════ */
  let currentSkillCatId = null;

  async function loadSkills() {
    const catList = document.getElementById('skillCatList');
    const skillEditorCat = document.getElementById('skillEditorCat');
    if (!catList) return;
    const data = await apiFetch('admin/skills.php', { action: 'list_categories' });
    const cats = data.categories || [];

    catList.innerHTML = cats.map(c => `
      <div class="skill-cat-item" data-cat-id="${c.id}" onclick="loadSkillsByCategory(${c.id},'${c.name_ar}')"
        style="padding:10px 12px;border-radius:8px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;${currentSkillCatId===c.id?'background:var(--teal-50);font-weight:700':''}">
        <span>${c.icon} ${c.name_ar}</span>
        <span style="font-size:11px;background:#f1f5f9;padding:2px 8px;border-radius:12px">${c.skill_count}</span>
      </div>`).join('') || '<p style="color:var(--slate-400);padding:12px;font-size:13px">لا توجد تصنيفات</p>';

    if (skillEditorCat) {
      skillEditorCat.innerHTML = cats.map(c => `<option value="${c.id}">${c.name_ar}</option>`).join('');
    }
  }

  window.loadSkillsByCategory = async (catId, catName) => {
    currentSkillCatId = catId;
    // Highlight
    document.querySelectorAll('.skill-cat-item').forEach(el =>
      el.style.background = el.dataset.catId == catId ? 'var(--teal-50)' : '');
    document.querySelectorAll('.skill-cat-item').forEach(el =>
      el.style.fontWeight = el.dataset.catId == catId ? '700' : '');

    const container = document.getElementById('skillsListContainer');
    const title = document.getElementById('skillsListTitle');
    if (!container) return;
    if (title) title.textContent = `مهارات: ${catName}`;
    container.innerHTML = '<p style="text-align:center;padding:16px;color:var(--slate-400)">جارٍ التحميل...</p>';

    const data = await apiFetch('admin/skills.php', { action: 'list_skills', category_id: catId });
    const skills = data.skills || [];
    container.innerHTML = skills.length
      ? `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px">` +
        skills.map(s => `
          <div style="border:1px solid var(--line);border-radius:10px;padding:14px;background:var(--white)">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
              <strong style="font-size:14px">${s.title_ar}</strong>
              <div style="display:flex;gap:4px">
                <button class="btn btn-ghost" style="font-size:11px;padding:4px 8px" onclick="editSkill(${s.id})">✏️</button>
                <button class="btn btn-ghost" style="font-size:11px;padding:4px 8px;color:#991b1b" onclick="deleteSkill(${s.id})">🗑</button>
              </div>
            </div>
            <div style="font-size:12px;color:var(--slate-400);margin-bottom:6px">${s.title_en || ''}</div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <span style="font-size:11px;background:#f1f5f9;padding:2px 8px;border-radius:12px">${{beginner:'مبتدئ',intermediate:'متوسط',advanced:'متقدم'}[s.difficulty_level]}</span>
              <span style="font-size:11px;background:#f1f5f9;padding:2px 8px;border-radius:12px">⏱ ${s.estimated_minutes} د</span>
              ${s.requires_supervision ? '<span style="font-size:11px;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:12px">👨‍⚕️ إشراف مطلوب</span>' : ''}
              ${s.is_published ? '<span style="font-size:11px;background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:12px">✅ منشور</span>' : ''}
            </div>
          </div>`).join('') + '</div>'
      : '<p style="text-align:center;padding:32px;color:var(--slate-400)">لا توجد مهارات في هذا التصنيف</p>';
  };

  window.editSkill = async id => {
    const data = await apiFetch('admin/skills.php', { action: 'get_skill', skill_id: id });
    if (!data.success) { alert(data.message); return; }
    const s = data.skill;
    const modal = document.getElementById('skillEditorModal');
    document.getElementById('skillEditorId').value = s.id;
    document.getElementById('skillEditorTitle').textContent = 'تعديل مهارة';
    document.getElementById('skillEditorCat').value = s.category_id;
    document.getElementById('skillEditorLevel').value = s.difficulty_level;
    document.getElementById('skillEditorNameAr').value = s.title_ar;
    document.getElementById('skillEditorNameEn').value = s.title_en || '';
    document.getElementById('skillEditorDesc').value = s.description_ar || '';
    document.getElementById('skillEditorTime').value = s.estimated_minutes;
    document.getElementById('skillEditorSupervision').checked = !!s.requires_supervision;
    document.getElementById('skillEditorPublished').checked = !!s.is_published;
    document.getElementById('skillEditorObjectives').value = Array.isArray(s.objectives) ? s.objectives.join('\n') : '';
    document.getElementById('skillEditorEquipment').value = Array.isArray(s.equipment) ? s.equipment.join('\n') : '';
    document.getElementById('skillEditorSteps').value = Array.isArray(s.steps) ? s.steps.join('\n') : '';
    document.getElementById('skillEditorSafety').value = Array.isArray(s.safety_precautions) ? s.safety_precautions.join('\n') : '';
    document.getElementById('skillEditorMistakes').value = Array.isArray(s.common_mistakes) ? s.common_mistakes.join('\n') : '';
    document.getElementById('skillEditorQuestions').value = Array.isArray(s.review_questions) ? s.review_questions.join('\n') : '';
    modal.hidden = false;
  };

  document.getElementById('btnNewSkill')?.addEventListener('click', () => {
    document.getElementById('skillEditorId').value = '';
    document.getElementById('skillEditorTitle').textContent = 'مهارة جديدة';
    ['skillEditorNameAr','skillEditorNameEn','skillEditorDesc','skillEditorObjectives','skillEditorEquipment','skillEditorSteps','skillEditorSafety','skillEditorMistakes','skillEditorQuestions'].forEach(id => { const el = document.getElementById(id); if(el) el.value = ''; });
    document.getElementById('skillEditorTime').value = '30';
    document.getElementById('skillEditorSupervision').checked = true;
    document.getElementById('skillEditorPublished').checked = false;
    document.getElementById('skillEditorModal').hidden = false;
  });

  document.getElementById('closeSkillEditor')?.addEventListener('click', () => document.getElementById('skillEditorModal').hidden = true);
  document.getElementById('btnCancelSkill')?.addEventListener('click', () => document.getElementById('skillEditorModal').hidden = true);

  document.getElementById('btnSaveSkill')?.addEventListener('click', async () => {
    const id = document.getElementById('skillEditorId').value;
    const toArr = val => val.split('\n').map(s => s.trim()).filter(Boolean);
    const body = {
      skill_id: id || undefined,
      category_id: document.getElementById('skillEditorCat').value,
      title_ar: document.getElementById('skillEditorNameAr').value,
      title_en: document.getElementById('skillEditorNameEn').value,
      description_ar: document.getElementById('skillEditorDesc').value,
      difficulty_level: document.getElementById('skillEditorLevel').value,
      estimated_minutes: document.getElementById('skillEditorTime').value,
      requires_supervision: document.getElementById('skillEditorSupervision').checked,
      is_published: document.getElementById('skillEditorPublished').checked,
      objectives: toArr(document.getElementById('skillEditorObjectives').value),
      equipment:  toArr(document.getElementById('skillEditorEquipment').value),
      steps:      toArr(document.getElementById('skillEditorSteps').value),
      safety_precautions: toArr(document.getElementById('skillEditorSafety').value),
      common_mistakes:    toArr(document.getElementById('skillEditorMistakes').value),
      review_questions:   toArr(document.getElementById('skillEditorQuestions').value),
    };
    const data = await apiPost('admin/skills.php', body, { action: id ? 'update_skill' : 'create_skill' });
    showMsg(document.getElementById('skillsMsg'), data.message, !data.success);
    if (data.success) {
      document.getElementById('skillEditorModal').hidden = true;
      loadSkills();
      if (currentSkillCatId) loadSkillsByCategory(currentSkillCatId, '');
    }
  });

  window.deleteSkill = async id => {
    if (!confirm('حذف هذه المهارة؟')) return;
    const data = await apiPost('admin/skills.php', { skill_id: id }, { action: 'delete_skill' });
    showMsg(document.getElementById('skillsMsg'), data.message, !data.success);
    if (data.success && currentSkillCatId) loadSkillsByCategory(currentSkillCatId, '');
  };

  document.getElementById('btnNewSkillCat')?.addEventListener('click', async () => {
    const name = prompt('اسم التصنيف الجديد (عربي):');
    if (!name) return;
    const icon = prompt('أيقونة (رمز تعبيري):') || '🩺';
    const data = await apiPost('admin/skills.php', { name_ar: name, icon }, { action: 'create_category' });
    showMsg(document.getElementById('skillsMsg'), data.success ? 'تم إنشاء التصنيف' : data.message, !data.success);
    if (data.success) loadSkills();
  });

  /* ═══════════════════════════════════════════════
     16. NOTIFICATIONS
  ═══════════════════════════════════════════════ */
  async function loadNotifications() {
    const listEl = document.getElementById('recentNotifList');
    const statsEl = document.getElementById('notifStatsBox');
    if (!listEl) return;
    listEl.innerHTML = '<p style="text-align:center;padding:16px;color:var(--slate-400)">&#x23F3; &#x62C;&#x627;&#x631;&#x64D; &#x627;&#x644;&#x62A;&#x62D;&#x645;&#x64A;&#x644;...</p>';
    const [sentRes, statsRes] = await Promise.allSettled([
      apiFetch('admin/notifications.php', { action: 'list_sent', limit: 20 }),
      apiFetch('admin/notifications.php', { action: 'stats' }),
    ]);
    if (statsEl && statsRes.status === 'fulfilled') {
      const s = statsRes.value;
      statsEl.innerHTML = `<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
        <div class="stat-card"><div class="stat-num">${s.total||0}</div><div class="stat-lbl">&#x625;&#x62C;&#x645;&#x627;&#x644;&#x64A;</div></div>
        <div class="stat-card"><div class="stat-num" style="color:#d97706">${s.unread||0}</div><div class="stat-lbl">&#x63A;&#x64A;&#x631; &#x645;&#x642;&#x631;&#x648;&#x621;</div></div>
        <div class="stat-card"><div class="stat-num" style="color:#38a169">${s.today||0}</div><div class="stat-lbl">&#x627;&#x644;&#x64A;&#x648;&#x645;</div></div>
      </div>`;
    }
    const notifs = sentRes.status === 'fulfilled' ? (sentRes.value.notifications || []) : [];
    const typeIcons = { info:'&#x2139;&#xFE0F;', success:'&#x2705;', warning:'&#x26A0;&#xFE0F;', danger:'&#x1F6A8;', system:'&#x2699;&#xFE0F;' };
    listEl.innerHTML = notifs.length
      ? notifs.map(n => `<div style="border-bottom:1px solid var(--line);padding:12px 0;${n.is_read?'opacity:0.65':''}">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
            <div style="display:flex;gap:8px;">
              <span>${typeIcons[n.type]||'&#x2139;&#xFE0F;'}</span>
              <div>
                <div style="font-weight:600;font-size:13px;">${n.title}</div>
                <div style="font-size:12px;color:var(--slate-500);margin-top:2px;">${n.body||''}</div>
                <div style="font-size:11px;color:var(--slate-400);margin-top:4px;">&#x625;&#x644;&#x649;: ${n.user_name||'-'} (${n.user_role||'-'}) &middot; ${formatDate(n.created_at)}</div>
              </div>
            </div>
            <span style="font-size:11px;white-space:nowrap;color:${n.is_read?'#38a169':'#d97706'};">${n.is_read?'&#x2713; &#x645;&#x642;&#x631;&#x648;&#x621;':'&#x25CF; &#x62C;&#x62F;&#x64A;&#x62F;'}</span>
          </div>
        </div>`).join('')
      : '<p style="color:var(--slate-400);text-align:center;padding:24px;">&#x644;&#x627; &#x62A;&#x648;&#x62C;&#x62F; &#x625;&#x634;&#x639;&#x627;&#x631;&#x627;&#x62A; &#x645;&#x631;&#x633;&#x644;&#x629; &#x628;&#x639;&#x62F;</p>';
  }

  document.getElementById('btnSendNotification')?.addEventListener('click', async () => {
    const title   = document.getElementById('notifTitle').value.trim();
    const message = document.getElementById('notifMessage').value.trim();
    const type    = document.getElementById('notifType').value;
    const target  = document.getElementById('notifTarget').value;
    const msgEl   = document.getElementById('notifSendMsg');
    if (!title || !message) { showMsg(msgEl, '&#x627;&#x644;&#x639;&#x646;&#x648;&#x627;&#x646; &#x648;&#x627;&#x644;&#x645;&#x62D;&#x62A;&#x648;&#x649; &#x645;&#x637;&#x644;&#x648;&#x628;&#x627;&#x646;.', true); return; }
    const data = await apiPost('admin/notifications.php', { action: 'broadcast', title, body: message, type, role: target || '' });
    showMsg(msgEl, data.success ? `&#x2705; &#x62A;&#x645; &#x627;&#x644;&#x625;&#x631;&#x633;&#x627;&#x644; &#x644;&#x640; ${data.sent_to||'?'} &#x645;&#x633;&#x62A;&#x62E;&#x62F;&#x645;` : (data.message || '&#x62E;&#x637;&#x623;'), !data.success);
    if (data.success) {
      document.getElementById('notifTitle').value = '';
      document.getElementById('notifMessage').value = '';
      loadNotifications();
    }
  });

  /* ═══════════════════════════════════════════════
     17. SUPPORT
  ═══════════════════════════════════════════════ */
  let currentTicketId = null;

  async function loadSupport() {
    const container = document.getElementById('ticketListContainer');
    if (!container) return;
    container.innerHTML = '<p style="text-align:center;padding:16px;color:var(--slate-400)">جارٍ التحميل...</p>';

    const status = document.getElementById('supportStatusFilter')?.value || '';
    const search = document.getElementById('supportSearch')?.value || '';
    const data = await apiFetch('admin/support.php', { action: 'list', status, search });
    const tickets = data.tickets || [];

    const priorityColor = { urgent: '#fca5a5', high: '#fcd34d', medium: '#93c5fd', low: '#d1fae5' };

    container.innerHTML = tickets.length
      ? tickets.map(t => `
        <div class="ticket-item" data-ticket-id="${t.id}" onclick="loadTicketDetail(${t.id})"
          style="padding:12px;border-radius:8px;border:1px solid var(--line);margin-bottom:8px;cursor:pointer;border-left:4px solid ${priorityColor[t.priority]||'#e2e8f0'};${currentTicketId===t.id?'background:var(--teal-50)':'background:var(--white)'}">
          <div style="display:flex;justify-content:space-between;align-items:flex-start">
            <div>
              <div style="font-weight:600;font-size:13px">${t.subject}</div>
              <div style="font-size:11px;color:var(--slate-500);margin-top:2px">${t.student_name} · ${t.category}</div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px">
              ${statusBadge(t.status)}
              <span style="font-size:11px;color:var(--slate-400)">${t.reply_count} رد</span>
            </div>
          </div>
          <div style="font-size:11px;color:var(--slate-400);margin-top:6px">${formatDate(t.updated_at)}</div>
        </div>`).join('')
      : '<p style="text-align:center;padding:24px;color:var(--slate-400)">لا توجد تذاكر</p>';
  }

  window.loadTicketDetail = async id => {
    currentTicketId = id;
    document.querySelectorAll('.ticket-item').forEach(el =>
      el.style.background = el.dataset.ticketId == id ? 'var(--teal-50)' : 'var(--white)');

    const panel = document.getElementById('ticketDetailPanel');
    if (!panel) return;
    panel.innerHTML = '<div style="padding:24px;text-align:center;color:var(--slate-400)">جارٍ التحميل...</div>';

    const data = await apiFetch('admin/support.php', { action: 'get', ticket_id: id });
    if (!data.success) { panel.innerHTML = `<div style="padding:24px;color:red">${data.message}</div>`; return; }
    const t = data.ticket;
    const msgs = t.messages || [];

    panel.innerHTML = `
      <div style="padding:16px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:flex-start">
        <div>
          <h3 style="margin:0;font-size:16px">${t.subject}</h3>
          <div style="font-size:12px;color:var(--slate-500);margin-top:4px">${t.student_name} (${t.student_email}) · ${t.category} · ${t.priority}</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          ${statusBadge(t.status)}
          <select id="ticketStatusChange" onchange="changeTicketStatus(${t.id},this.value)" style="padding:4px 8px;border-radius:6px;border:1px solid var(--line);font-size:12px">
            <option value="">تغيير الحالة</option>
            <option value="open">مفتوحة</option>
            <option value="in_progress">قيد المعالجة</option>
            <option value="waiting">انتظار الطالب</option>
            <option value="resolved">محلولة</option>
            <option value="closed">مغلقة</option>
          </select>
        </div>
      </div>
      <div id="ticketMsgThread" style="padding:16px;max-height:350px;overflow-y:auto;display:flex;flex-direction:column;gap:12px">
        ${msgs.map(m => {
          const isAdmin = m.sender_role !== 'student';
          return `<div style="display:flex;${isAdmin?'justify-content:flex-start':'justify-content:flex-end'}">
            <div style="max-width:75%;background:${isAdmin?'var(--teal-50)':'var(--slate-100)'};border-radius:12px;padding:10px 14px;border:1px solid ${isAdmin?'var(--teal-200)':'var(--line)'}">
              <div style="font-weight:600;font-size:12px;color:${isAdmin?'var(--teal-700)':'var(--slate-600)'};margin-bottom:4px">${m.sender_name} ${isAdmin?'(الدعم)':'(الطالب)'}</div>
              <div style="font-size:13px;white-space:pre-wrap">${m.message}</div>
              <div style="font-size:11px;color:var(--slate-400);margin-top:4px;text-align:${isAdmin?'left':'right'}">${formatDate(m.created_at)}</div>
            </div>
          </div>`;
        }).join('')}
      </div>
      <div style="padding:16px;border-top:1px solid var(--line)">
        <div id="ticketReplyMsg" class="notice" hidden style="margin-bottom:8px"></div>
        <textarea id="ticketReplyText" rows="3" placeholder="اكتب ردك هنا..." style="width:100%;padding:10px;border-radius:8px;border:1px solid var(--line);margin-bottom:8px;resize:vertical"></textarea>
        <button class="btn btn-primary" onclick="sendTicketReply(${t.id})">📤 إرسال الرد</button>
      </div>`;
  };

  window.changeTicketStatus = async (id, status) => {
    if (!status) return;
    const data = await apiPost('admin/support.php', { ticket_id: id, status }, { action: 'update_status' });
    if (data.success) { loadSupport(); loadTicketDetail(id); }
  };

  window.sendTicketReply = async id => {
    const text = document.getElementById('ticketReplyText')?.value?.trim();
    if (!text) return;
    const data = await apiPost('admin/support.php', { ticket_id: id, message: text }, { action: 'reply' });
    showMsg(document.getElementById('ticketReplyMsg'), data.message, !data.success);
    if (data.success) loadTicketDetail(id);
  };

  document.getElementById('supportStatusFilter')?.addEventListener('change', loadSupport);
  document.getElementById('supportSearch')?.addEventListener('input', loadSupport);

  /* ═══════════════════════════════════════════════
     18. REPORTS
  ═══════════════════════════════════════════════ */
  async function loadReports() {
    // Set default dates if empty
    const fromEl = document.getElementById('rptDateFrom');
    const toEl   = document.getElementById('rptDateTo');
    if (fromEl && !fromEl.value) {
      const now = new Date();
      fromEl.value = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-01`;
      toEl.value   = now.toISOString().split('T')[0];
    }

    // Load courses for filter
    const courseSel = document.getElementById('rptCourseFilter');
    if (courseSel && !courseSel.dataset.loaded) {
      const cData = await apiFetch('admin/courses.php', { action: 'list', per_page: 100 });
      (cData.courses || cData.data || []).forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id; opt.textContent = c.title_ar;
        courseSel.appendChild(opt);
      });
      courseSel.dataset.loaded = '1';
    }

    const dateFrom  = fromEl?.value || '';
    const dateTo    = toEl?.value   || '';
    const courseId  = courseSel?.value || '';

    const [summary, topCourses, quizPerf, instructors, revenue] = await Promise.all([
      apiFetch('admin/reports.php', { action: 'summary', date_from: dateFrom, date_to: dateTo }),
      apiFetch('admin/reports.php', { action: 'enrollments_by_period', date_from: dateFrom, date_to: dateTo, course_id: courseId }),
      apiFetch('admin/reports.php', { action: 'quiz_performance', date_from: dateFrom, date_to: dateTo, course_id: courseId }),
      apiFetch('admin/reports.php', { action: 'instructor_activity' }),
      apiFetch('admin/reports.php', { action: 'revenue', date_from: dateFrom, date_to: dateTo, course_id: courseId }),
    ]);

    // Summary cards
    if (summary.success) {
      const r = summary.report;
      const totalEnr = r.enrollments_by_day?.reduce((s,d) => s + parseInt(d.cnt), 0) || 0;
      document.getElementById('rptNewEnrollments').textContent = totalEnr;
      document.getElementById('rptNewStudents').textContent   = r.new_students || 0;
      document.getElementById('rptRevenue').textContent       = formatMoney(r.revenue?.confirmed_revenue || 0);
      document.getElementById('rptPending').textContent       = formatMoney(r.revenue?.pending_revenue || 0);
    }

    // Top courses
    const topTbody = document.getElementById('rptTopCoursesTbody');
    if (topTbody && summary.success) {
      topTbody.innerHTML = (summary.report.top_courses || []).slice(0,10).map((c,i) =>
        `<tr><td>${i+1}</td><td>${c.title_ar}</td><td>${c.enrollment_count || 0}</td><td>${formatMoney(c.revenue)}</td></tr>`
      ).join('') || '<tr><td colspan="4" style="text-align:center;color:var(--slate-400)">—</td></tr>';
    }

    // Quiz performance
    const quizTbody = document.getElementById('rptQuizPerfTbody');
    if (quizTbody && quizPerf.success) {
      quizTbody.innerHTML = (quizPerf.rows || []).map(q =>
        `<tr><td>${q.title_ar}</td><td>${q.total_attempts || 0}</td><td>${q.pass_rate || 0}%</td><td>${q.avg_score || 0}%</td></tr>`
      ).join('') || '<tr><td colspan="4" style="text-align:center;color:var(--slate-400)">—</td></tr>';
    }

    // Instructors
    const insTbody = document.getElementById('rptInstructorTbody');
    if (insTbody && instructors.success) {
      insTbody.innerHTML = (instructors.rows || []).map(r =>
        `<tr><td>${r.full_name}</td><td>${r.course_count || 0}</td><td>${r.student_count || 0}</td><td>${r.avg_rating ? parseFloat(r.avg_rating).toFixed(1) + ' ⭐' : '—'}</td></tr>`
      ).join('') || '<tr><td colspan="4" style="text-align:center;color:var(--slate-400)">—</td></tr>';
    }

    // Revenue detail
    const revTbody = document.getElementById('rptRevenueTbody');
    if (revTbody && revenue.success) {
      revTbody.innerHTML = (revenue.rows || []).slice(0, 50).map(r =>
        `<tr><td><small>${r.order_number}</small></td><td>${r.student_name}</td><td>${r.course_title || '—'}</td><td style="font-weight:700">${formatMoney(r.total_amount)}</td><td>${statusBadge(r.status)}</td><td>${formatDate(r.created_at)}</td></tr>`
      ).join('') || '<tr><td colspan="6" style="text-align:center;color:var(--slate-400)">—</td></tr>';
    }

    // CSV exports
    document.getElementById('btnExportRevenue')?.setAttribute('onclick', `window.open('api/admin/reports.php?action=revenue&format=csv&date_from=${dateFrom}&date_to=${dateTo}&course_id=${courseId}','_blank')`);
    document.getElementById('btnExportInstructors')?.setAttribute('onclick', `window.open('api/admin/reports.php?action=instructor_activity&format=csv','_blank')`);
  }

  document.getElementById('btnLoadReports')?.addEventListener('click', loadReports);


  /* ==========================================================================
     18. PROGRESS PANEL
     ========================================================================== */
  async function loadProgressOverview() {
    // Load courses into filter dropdown
    const sel = document.getElementById('progressCourseFilter');
    if (sel && sel.options.length <= 1) {
      try {
        const cData = await apiFetch('admin/courses.php', { action: 'list', limit: 100 });
        const courses = cData.courses || cData.data || [];
        courses.forEach(c => {
          const opt = document.createElement('option');
          opt.value = c.id;
          opt.textContent = c.title_ar || c.title_en || 'Course ' + c.id;
          sel.appendChild(opt);
        });
      } catch (e) { console.warn('Could not load courses for progress filter', e); }
    }

    // Load overview stats
    try {
      const data = await apiFetch('admin/progress.php', { action: 'overview' });
      const statsRow = document.getElementById('progressStatsRow');
      if (statsRow) {
        statsRow.innerHTML = [
          { num: data.total_enrolled  || 0, lbl: '&#x637;&#x644;&#x627;&#x628; &#x645;&#x633;&#x62C;&#x644;&#x648;&#x646;', color: '' },
          { num: data.total_completed || 0, lbl: '&#x623;&#x643;&#x645;&#x644;&#x648;&#x627; &#x643;&#x648;&#x631;&#x633;&#x627;&#x64B;', color: '#38a169' },
          { num: (data.avg_progress || 0) + '%', lbl: '&#x645;&#x62A;&#x648;&#x633;&#x637; &#x627;&#x644;&#x62A;&#x642;&#x62F;&#x645;', color: '#d97706' },
        ].map(s => '<div class="stat-card"><div class="stat-num" style="color:' + s.color + '">' + s.num + '</div><div class="stat-lbl">' + s.lbl + '</div></div>').join('');
      }
    } catch (e) { console.warn('Progress overview failed', e); }
  }

  window.loadCourseProgress = async function() {
    const courseId = document.getElementById('progressCourseFilter')?.value;
    const search   = document.getElementById('progressSearch')?.value || '';
    const tbody    = document.getElementById('progressTbody');
    const title    = document.getElementById('progressTableTitle');
    if (!tbody || !courseId) return;

    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:20px;">&#x23F3; &#x62C;&#x627;&#x631;&#x64D; &#x627;&#x644;&#x62A;&#x62D;&#x645;&#x64A;&#x644;...</td></tr>';
    try {
      const data = await apiFetch('admin/progress.php', { action: 'course_progress', course_id: courseId, search });
      if (title) title.textContent = (data.course?.title_ar || '&#x62A;&#x642;&#x62F;&#x645; &#x627;&#x644;&#x637;&#x644;&#x627;&#x628;') + ' (' + (data.total || 0) + ')';
      const rows = data.students || [];
      tbody.innerHTML = rows.length ? rows.map(s => {
        const pct = parseFloat(s.progress_percent) || 0;
        const barColor = pct >= 80 ? '#38a169' : pct >= 40 ? '#d97706' : '#e53e3e';
        return '<tr>' +
          '<td><strong>' + (s.full_name || '-') + '</strong></td>' +
          '<td style="font-size:12px;">' + (s.email || '-') + '</td>' +
          '<td><div style="display:flex;align-items:center;gap:8px;">' +
            '<div style="flex:1;height:8px;background:#e2e8f0;border-radius:4px;min-width:60px;">' +
              '<div style="height:100%;width:' + pct + '%;background:' + barColor + ';border-radius:4px;"></div>' +
            '</div>' +
            '<span style="font-size:12px;font-weight:700;">' + pct + '%</span>' +
          '</div></td>' +
          '<td style="text-align:center;">' + (s.completed_lessons || 0) + '/' + (s.total_lessons || '?') + '</td>' +
          '<td style="text-align:center;">&#x2705; ' + (s.quizzes_passed || 0) + ' / &#x274C; ' + (s.quizzes_failed || 0) + '</td>' +
          '<td style="font-size:11px;color:var(--slate-500);">' + formatDate(s.last_activity) + '</td>' +
          '<td><button class="btn btn-sm btn-outline" onclick="viewStudentLessons(' + s.student_id + ',' + courseId + ')">&#x1F4CB; &#x62A;&#x641;&#x627;&#x635;&#x64A;&#x644;</button></td>' +
        '</tr>';
      }).join('') : '<tr><td colspan="7" style="text-align:center;padding:30px;color:var(--slate-400);">&#x644;&#x627; &#x64A;&#x648;&#x62C;&#x62F; &#x637;&#x644;&#x627;&#x628; &#x645;&#x633;&#x62C;&#x644;&#x648;&#x646; &#x641;&#x64A; &#x647;&#x630;&#x627; &#x627;&#x644;&#x643;&#x648;&#x631;&#x633;</td></tr>';
    } catch (err) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#e53e3e;padding:20px;">' + err.message + '</td></tr>';
    }
  };

  window.loadStrugglingStudents = async function() {
    const panel = document.getElementById('strugglingPanel');
    const list  = document.getElementById('strugglingList');
    if (!panel || !list) return;
    panel.style.display = 'block';
    list.innerHTML = '<p style="text-align:center;padding:16px;">&#x23F3; &#x62C;&#x627;&#x631;&#x64D; &#x627;&#x644;&#x62A;&#x62D;&#x645;&#x64A;&#x644;...</p>';
    try {
      const data = await apiFetch('admin/progress.php', { action: 'struggling_students', threshold: 30 });
      const rows = data.students || [];
      list.innerHTML = rows.length ? '<table class="data-table"><thead><tr><th>&#x627;&#x644;&#x637;&#x627;&#x644;&#x628;</th><th>&#x627;&#x644;&#x643;&#x648;&#x631;&#x633;</th><th>&#x627;&#x644;&#x62A;&#x642;&#x62F;&#x645;</th><th>&#x623;&#x64A;&#x627;&#x645; &#x645;&#x636;&#x62A;</th></tr></thead><tbody>' +
        rows.map(s => '<tr>' +
          '<td>' + s.full_name + '<br><small style="color:var(--slate-500);">' + (s.phone || '') + '</small></td>' +
          '<td>' + (s.course_title || '-') + '</td>' +
          '<td><span style="color:#e53e3e;font-weight:700;">' + (s.progress_percent || 0) + '%</span></td>' +
          '<td>' + (s.days_enrolled || 0) + ' &#x64A;&#x648;&#x645;</td>' +
        '</tr>').join('') +
        '</tbody></table>' : '<p style="text-align:center;color:#38a169;padding:20px;">&#x1F44D; &#x62C;&#x645;&#x64A;&#x639; &#x627;&#x644;&#x637;&#x644;&#x627;&#x628; &#x64A;&#x62A;&#x642;&#x62F;&#x645;&#x648;&#x646; &#x628;&#x634;&#x643;&#x644; &#x62C;&#x64A;&#x62F;</p>';
    } catch (err) {
      list.innerHTML = '<p style="color:#e53e3e;">' + err.message + '</p>';
    }
  };

  window.viewStudentLessons = async function(studentId, courseId) {
    try {
      const data = await apiFetch('admin/progress.php', { action: 'lesson_detail', student_id: studentId, course_id: courseId });
      let modal = document.getElementById('lessonDetailModal');
      if (!modal) {
        modal = document.createElement('div');
        modal.id = 'lessonDetailModal';
        modal.className = 'modal-overlay';
        modal.innerHTML = '<div class="modal" style="max-width:650px;max-height:80vh;overflow-y:auto;" id="lessonDetailContent"></div>';
        modal.onclick = e => { if (e.target === modal) modal.hidden = true; };
        document.body.appendChild(modal);
      }
      const lessons = data.lessons || [];
      const student = data.student || {};
      const statusIcons = { completed: '&#x2705;', in_progress: '&#x23F3;', not_started: '&#x25CB;' };
      document.getElementById('lessonDetailContent').innerHTML =
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">' +
          '<h3 style="margin:0;">&#x1F4CB; ' + (student.full_name || '') + '</h3>' +
          '<button onclick="this.closest(\'.modal-overlay\').hidden=true" style="background:transparent;border:none;font-size:20px;cursor:pointer;">&#x2715;</button>' +
        '</div>' +
        '<table class="data-table"><thead><tr><th>#</th><th>&#x627;&#x644;&#x62F;&#x631;&#x633;</th><th>&#x627;&#x644;&#x62D;&#x627;&#x644;&#x629;</th><th>&#x627;&#x644;&#x645;&#x634;&#x627;&#x647;&#x62F;&#x629;</th></tr></thead><tbody>' +
        lessons.map((l, i) => '<tr>' +
          '<td>' + (i + 1) + '</td>' +
          '<td>' + (l.title_ar || l.title_en || 'Ø¯Ø±Ø³ ' + l.id) + '</td>' +
          '<td>' + (statusIcons[l.status] || '&#x25CB;') + ' ' + (l.status || 'not_started') + '</td>' +
          '<td>' + (l.watch_percent || 0) + '%</td>' +
        '</tr>').join('') +
        '</tbody></table>';
      modal.hidden = false;
    } catch (err) {
      alert(err.message);
    }
  };

  // Expose loaders globally for admin.js tab switching
  window.loadEnrollments = loadEnrollments;
  window.loadPayments = loadPayments;
  window.initQuizzes = initQuizzes;
  window.loadCertificates = loadCertificates;
  window.loadSkills = loadSkills;
  window.loadNotifications = loadNotifications;
  window.loadSupport = loadSupport;
  window.loadReports = loadReports;
  window.loadProgressOverview = loadProgressOverview;

  // Hook tab click for progress
  document.querySelectorAll('[data-tab="progress"]').forEach(btn => {
    btn.addEventListener('click', loadProgressOverview);
  });
})();
