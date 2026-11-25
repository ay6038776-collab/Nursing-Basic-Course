/**
 * ============================================================================
 * Student Features Extension — asset/js/student-features.js
 * Extends student.js with live API calls for Quizzes, Grades, Certificates,
 * Support Tickets, and Nursing Skills Library tabs.
 * ============================================================================
 */
'use strict';

/* ============================================================
   Helpers (safe fall-back if student.js already defined them)
   ============================================================ */
const _sf_esc = typeof esc === 'function' ? esc : s =>
  String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

const _sf_$ = id => document.getElementById(id);
const _sf_$$ = (sel, root = document) => [...root.querySelectorAll(sel)];

const SF_ROOT = './';

/** POST JSON to a student API endpoint */
async function sfStudentPost(path, data = {}) {
  const r = await fetch(SF_ROOT + path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(data),
  });
  const json = await r.json();
  if (!json.success) throw new Error(json.message || json.error || 'خطأ في الخادم');
  return json;
}

/** GET from a student API endpoint */
async function sfStudentGet(path) {
  const r = await fetch(SF_ROOT + path, { credentials: 'same-origin' });
  const json = await r.json();
  if (!json.success && !json.categories && !json.skills && !json.attempts && !json.certificates && !json.tickets) {
    throw new Error(json.message || json.error || 'خطأ في الخادم');
  }
  return json;
}

/** Show a toast notification */
function sfNotify(msg, type = 'success') {
  if (typeof notify === 'function') { notify(msg, type); return; }
  const d = document.createElement('div');
  d.style.cssText = 'position:fixed;bottom:20px;right:20px;padding:12px 20px;border-radius:10px;color:#fff;z-index:9999;font-size:14px;';
  d.style.background = type === 'danger' ? '#e53e3e' : type === 'warning' ? '#d97706' : '#38a169';
  d.textContent = msg;
  document.body.appendChild(d);
  setTimeout(() => d.remove(), 3500);
}

/* ============================================================
   QUIZZES TAB — Live loading with start/submit
   ============================================================ */
let sfQuizState = { active: null, answers: {}, timer: null };

async function sfLoadQuizzes() {
  const tbody = _sf_$('quizzesTbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;">⏳ جارٍ التحميل...</td></tr>';
  try {
    const res = await sfStudentGet('api/student/quizzes.php?action=list');
    const quizzes = res.quizzes || [];
    if (!quizzes.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);">لا توجد اختبارات متاحة حالياً</td></tr>';
      return;
    }
    tbody.innerHTML = quizzes.map(q => {
      const statusBadge = q.my_best_score !== null
        ? `<span class="st-badge ${q.my_best_score >= (q.pass_percent || 60) ? 'st-badge-emerald' : 'st-badge-gold'}">${q.my_best_score}%</span>`
        : '<span class="st-badge st-badge-teal">لم يبدأ</span>';
      const canStart = q.attempts_remaining === null || q.attempts_remaining > 0;
      return `
        <tr>
          <td><strong>${_sf_esc(q.title_ar || q.title_en || '')}</strong></td>
          <td>${_sf_esc(q.course_title || '-')}</td>
          <td>${q.duration_minutes ? q.duration_minutes + ' دقيقة' : 'بلا وقت'}</td>
          <td>${q.total_points || '-'} درجة</td>
          <td>${statusBadge}</td>
          <td>
            ${canStart ? `<button type="button" class="st-btn st-btn-sm st-btn-primary" onclick="sfStartQuiz(${q.id})">ابدأ الاختبار</button>` : '<span style="color:var(--text-muted);font-size:12px;">انتهت المحاولات</span>'}
            <button type="button" class="st-btn st-btn-sm st-btn-outline" onclick="sfShowAttempts(${q.id})" style="margin-right:4px;">النتائج</button>
          </td>
        </tr>
      `;
    }).join('');
  } catch (err) {
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:#e53e3e;padding:20px;">${_sf_esc(err.message)}</td></tr>`;
  }
}

window.sfStartQuiz = async function(quizId) {
  try {
    const res = await sfStudentPost('api/student/quizzes.php', { action: 'start', quiz_id: quizId });
    const q = res.quiz;
    sfQuizState = { active: q, answers: {}, timer: null };
    sfRenderQuizModal(q);
  } catch (err) {
    sfNotify(err.message, 'danger');
  }
};

function sfRenderQuizModal(q) {
  let modal = _sf_$('sfQuizModal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'sfQuizModal';
    modal.className = 'st-modal-overlay';
    document.body.appendChild(modal);
  }

  const mins = q.duration_minutes || 0;

  const questionsHtml = (q.questions || []).map((ques, qi) => {
    let inputHtml = '';
    if (ques.type === 'mcq' || ques.type === 'true_false') {
      inputHtml = (ques.options || []).map((opt, oi) => `
        <label style="display:flex;align-items:center;gap:10px;padding:10px;border-radius:8px;cursor:pointer;border:1px solid var(--border-color);margin-bottom:6px;">
          <input type="radio" name="q_${ques.id}" value="${oi}" onchange="sfQuizState.answers[${ques.id}]=${oi}" style="accent-color:var(--st-teal-600);">
          <span>${_sf_esc(opt.text_ar || opt.text_en || opt.text || '')}</span>
        </label>
      `).join('');
    } else {
      inputHtml = `<textarea name="q_${ques.id}" rows="3" style="width:100%;border-radius:8px;padding:10px;border:1px solid var(--border-color);resize:vertical;"
        onchange="sfQuizState.answers[${ques.id}]=this.value" placeholder="اكتب إجابتك هنا..."></textarea>`;
    }
    return `
      <div style="margin-bottom:20px;padding:16px;border-radius:12px;background:var(--bg-surface);border:1px solid var(--border-color);">
        <div style="font-weight:700;margin-bottom:12px;font-size:15px;">${qi + 1}. ${_sf_esc(ques.text_ar || ques.text_en || ques.question_text || '')}</div>
        ${inputHtml}
        <div style="font-size:12px;color:var(--text-muted);margin-top:8px;">الدرجة: ${ques.points || 1}</div>
      </div>
    `;
  }).join('');

  modal.innerHTML = `
    <div class="st-modal" style="max-width:700px;max-height:85vh;overflow-y:auto;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h3 style="margin:0;">${_sf_esc(q.title_ar || q.title_en || '')}</h3>
        <div style="display:flex;align-items:center;gap:12px;">
          ${mins ? `<div id="sfQuizTimer" style="font-size:18px;font-weight:800;color:var(--st-teal-600);">⏱ ${mins}:00</div>` : ''}
          <button type="button" onclick="sfCancelQuiz()" style="background:transparent;border:none;font-size:20px;cursor:pointer;">✕</button>
        </div>
      </div>
      <div>${questionsHtml}</div>
      <div style="text-align:left;margin-top:20px;">
        <button type="button" class="st-btn st-btn-primary" onclick="sfSubmitQuiz(${q.id}, ${q.attempt_id || 0})">
          📤 تسليم الاختبار
        </button>
      </div>
    </div>
  `;
  modal.hidden = false;

  // Start timer
  if (mins > 0) {
    let seconds = mins * 60;
    const timerEl = _sf_$('sfQuizTimer');
    sfQuizState.timer = setInterval(() => {
      seconds--;
      if (timerEl) {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        timerEl.textContent = `⏱ ${m}:${String(s).padStart(2,'0')}`;
        if (seconds <= 60) timerEl.style.color = '#e53e3e';
      }
      if (seconds <= 0) {
        clearInterval(sfQuizState.timer);
        sfSubmitQuiz(q.id, q.attempt_id || 0);
      }
    }, 1000);
  }
}

window.sfCancelQuiz = function() {
  if (sfQuizState.timer) clearInterval(sfQuizState.timer);
  const modal = _sf_$('sfQuizModal');
  if (modal) modal.hidden = true;
  sfQuizState = { active: null, answers: {}, timer: null };
};

window.sfSubmitQuiz = async function(quizId, attemptId) {
  if (sfQuizState.timer) clearInterval(sfQuizState.timer);

  const answers = Object.entries(sfQuizState.answers).map(([qId, ans]) => ({
    question_id: parseInt(qId),
    answer: ans,
  }));

  try {
    const res = await sfStudentPost('api/student/quizzes.php', {
      action: 'submit',
      quiz_id: quizId,
      attempt_id: attemptId,
      answers,
    });
    const modal = _sf_$('sfQuizModal');
    if (modal) modal.hidden = true;

    // Show result
    const pct = res.score_percent ?? res.score ?? 0;
    const passed = res.passed;
    sfNotify(`${passed ? '✅ نجحت!' : '❌ لم تنجح.'} نتيجتك: ${pct}%`, passed ? 'success' : 'warning');

    // Refresh quizzes list
    await sfLoadQuizzes();
    await sfLoadGrades();
  } catch (err) {
    sfNotify(err.message, 'danger');
  }
};

window.sfShowAttempts = async function(quizId) {
  try {
    const res = await sfStudentGet(`api/student/quizzes.php?action=my_attempts&quiz_id=${quizId}`);
    const attempts = res.attempts || [];
    let modal = _sf_$('sfAttemptsModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'sfAttemptsModal';
      modal.className = 'st-modal-overlay';
      modal.onclick = e => { if (e.target === modal) modal.hidden = true; };
      document.body.appendChild(modal);
    }
    modal.innerHTML = `
      <div class="st-modal" style="max-width:600px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
          <h3 style="margin:0;">نتائج الاختبار</h3>
          <button type="button" onclick="this.closest('.st-modal-overlay').hidden=true" style="background:transparent;border:none;font-size:20px;cursor:pointer;">✕</button>
        </div>
        ${attempts.length ? `
          <table style="width:100%;border-collapse:collapse;">
            <thead><tr style="background:var(--bg-surface);">
              <th style="padding:10px;text-align:right;">المحاولة</th>
              <th style="padding:10px;">النتيجة</th>
              <th style="padding:10px;">الحالة</th>
              <th style="padding:10px;">التاريخ</th>
            </tr></thead>
            <tbody>
              ${attempts.map((a, i) => `
                <tr style="border-bottom:1px solid var(--border-color);">
                  <td style="padding:10px;text-align:right;">#${i + 1}</td>
                  <td style="padding:10px;text-align:center;"><strong>${a.score_percent ?? '-'}%</strong></td>
                  <td style="padding:10px;text-align:center;">
                    <span class="st-badge ${a.status === 'passed' ? 'st-badge-emerald' : 'st-badge-gold'}">${a.status === 'passed' ? 'نجح' : 'لم ينجح'}</span>
                  </td>
                  <td style="padding:10px;text-align:center;font-size:12px;">${_sf_esc(a.created_at || '')}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        ` : '<p style="text-align:center;color:var(--text-muted);">لا توجد محاولات سابقة</p>'}
      </div>
    `;
    modal.hidden = false;
  } catch (err) {
    sfNotify(err.message, 'danger');
  }
};

/* ============================================================
   GRADES TAB — All quiz attempt results
   ============================================================ */
async function sfLoadGrades() {
  const tbody = _sf_$('gradesTbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;">⏳ جارٍ التحميل...</td></tr>';
  try {
    const res = await sfStudentGet('api/student/quizzes.php?action=my_attempts');
    const attempts = res.attempts || [];
    if (!attempts.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);">لا توجد نتائج حتى الآن</td></tr>';
      return;
    }
    tbody.innerHTML = attempts.map(a => `
      <tr>
        <td><strong>${_sf_esc(a.quiz_title || a.title_ar || '')}</strong></td>
        <td>${_sf_esc(a.course_title || '-')}</td>
        <td style="text-align:center;"><strong>${a.score_percent ?? '-'}%</strong></td>
        <td style="text-align:center;">${a.total_points || '-'}</td>
        <td style="text-align:center;">
          <span class="st-badge ${a.status === 'passed' ? 'st-badge-emerald' : 'st-badge-gold'}">
            ${a.status === 'passed' ? '✅ نجح' : '❌ لم ينجح'}
          </span>
        </td>
        <td style="text-align:center;font-size:12px;">${_sf_esc(a.created_at || '')}</td>
      </tr>
    `).join('');
  } catch (err) {
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:#e53e3e;padding:20px;">${_sf_esc(err.message)}</td></tr>`;
  }
}

/* ============================================================
   CERTIFICATES TAB — Live loading with download link
   ============================================================ */
async function sfLoadCertificates() {
  const grid = _sf_$('certificatesGrid');
  if (!grid) return;
  grid.innerHTML = '<div style="text-align:center;padding:40px;">⏳ جارٍ التحميل...</div>';
  try {
    const res = await sfStudentGet('api/student/certificates.php?action=list');
    const certs = res.certificates || [];
    if (!certs.length) {
      grid.innerHTML = `
        <div style="grid-column:1/-1;text-align:center;padding:50px;color:var(--text-muted);">
          <div style="font-size:48px;margin-bottom:12px;">🎓</div>
          <h3 style="margin:0 0 8px;">لا توجد شهادات بعد</h3>
          <p style="margin:0;">أكمل كورسًا للحصول على شهادتك</p>
        </div>`;
      return;
    }
    grid.innerHTML = certs.map(c => `
      <div class="st-course-card" style="text-align:center;padding:24px;">
        <div style="font-size:48px;margin-bottom:12px;">🎓</div>
        <h3 style="margin:0 0 6px;font-size:16px;">${_sf_esc(c.course_title || c.title_ar || '')}</h3>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">
          رقم الشهادة: <strong>${_sf_esc(c.certificate_number || '')}</strong>
        </div>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">
          تاريخ الإصدار: ${_sf_esc(c.issued_at || '')}
        </div>
        <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
          <a href="verify-certificate.html?cert=${encodeURIComponent(c.certificate_number || '')}" target="_blank" class="st-btn st-btn-sm st-btn-outline">
            🔍 تحقق
          </a>
          ${c.pdf_path ? `<a href="${_sf_esc(c.pdf_path)}" download class="st-btn st-btn-sm st-btn-primary">📄 تحميل PDF</a>` : ''}
        </div>
      </div>
    `).join('');
  } catch (err) {
    grid.innerHTML = `<div style="text-align:center;color:#e53e3e;padding:30px;">${_sf_esc(err.message)}</div>`;
  }
}

/* ============================================================
   SUPPORT TAB — Live loading + create + reply
   ============================================================ */
let sfSupportPage = 1;

async function sfLoadSupport(page = 1) {
  const tbody = _sf_$('ticketsTbody');
  if (!tbody) return;
  sfSupportPage = page;
  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;">⏳ جارٍ التحميل...</td></tr>';
  try {
    const res = await sfStudentGet(`api/student/support.php?action=list&page=${page}`);
    const tickets = res.tickets || [];
    if (!tickets.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);">لا توجد تذاكر دعم حتى الآن</td></tr>';
      return;
    }
    const statusColors = {
      open: 'st-badge-teal',
      in_progress: 'st-badge-gold',
      resolved: 'st-badge-emerald',
      closed: '',
    };
    const statusLabels = {
      open: 'مفتوحة',
      in_progress: 'قيد المعالجة',
      resolved: 'تم الحل',
      closed: 'مغلقة',
    };
    tbody.innerHTML = tickets.map(tk => `
      <tr>
        <td><strong>#${_sf_esc(tk.ticket_number || tk.id)}</strong></td>
        <td>${_sf_esc(tk.subject)}</td>
        <td><span class="st-badge st-badge-teal">${_sf_esc(tk.category || '')}</span></td>
        <td><span class="st-badge ${statusColors[tk.status] || ''}">${statusLabels[tk.status] || tk.status}</span></td>
        <td style="font-size:12px;">${_sf_esc(tk.created_at || '')}</td>
        <td>
          <button type="button" class="st-btn st-btn-sm st-btn-outline" onclick="sfOpenTicket(${tk.id})">
            💬 عرض / رد
          </button>
        </td>
      </tr>
    `).join('');
  } catch (err) {
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:#e53e3e;padding:20px;">${_sf_esc(err.message)}</td></tr>`;
  }
}

window.sfOpenTicket = async function(ticketId) {
  try {
    const res = await sfStudentGet(`api/student/support.php?action=get&id=${ticketId}`);
    const ticket = res.ticket;
    const messages = res.messages || [];
    let modal = _sf_$('sfTicketModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'sfTicketModal';
      modal.className = 'st-modal-overlay';
      modal.onclick = e => { if (e.target === modal) modal.hidden = true; };
      document.body.appendChild(modal);
    }
    modal.innerHTML = `
      <div class="st-modal" style="max-width:600px;max-height:80vh;overflow-y:auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
          <h3 style="margin:0;">${_sf_esc(ticket.subject)}</h3>
          <button type="button" onclick="this.closest('.st-modal-overlay').hidden=true" style="background:transparent;border:none;font-size:20px;cursor:pointer;">✕</button>
        </div>
        <div style="max-height:300px;overflow-y:auto;border:1px solid var(--border-color);border-radius:10px;padding:12px;margin-bottom:16px;">
          <div style="padding:12px;background:var(--bg-surface);border-radius:8px;margin-bottom:8px;">
            <strong>رسالتك الأصلية:</strong>
            <p style="margin:6px 0 0;">${_sf_esc(ticket.description || ticket.message || '')}</p>
          </div>
          ${messages.map(m => `
            <div style="padding:10px;border-radius:8px;margin-bottom:6px;${m.sender_role === 'admin' || m.sender_role === 'instructor' ? 'background:var(--bg-surface);border-right:3px solid var(--st-teal-600);' : 'background:rgba(56,161,105,0.1);border-right:3px solid #38a169;'}">
              <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">
                ${m.sender_role === 'admin' || m.sender_role === 'instructor' ? '🛠 الدعم الفني' : '👤 أنت'} — ${_sf_esc(m.created_at || '')}
              </div>
              <div>${_sf_esc(m.message || m.body || '')}</div>
            </div>
          `).join('')}
        </div>
        ${ticket.status !== 'closed' ? `
          <form id="sfReplyForm">
            <textarea id="sfReplyText" rows="3" placeholder="اكتب ردك هنا..." style="width:100%;border:1px solid var(--border-color);border-radius:8px;padding:10px;resize:vertical;"></textarea>
            <button type="submit" class="st-btn st-btn-primary" style="margin-top:8px;" data-ticket="${ticketId}">📤 إرسال الرد</button>
          </form>
        ` : '<p style="text-align:center;color:var(--text-muted);">التذكرة مغلقة</p>'}
      </div>
    `;
    modal.hidden = false;

    const form = _sf_$('sfReplyForm');
    if (form) {
      form.onsubmit = async e => {
        e.preventDefault();
        const msg = (_sf_$('sfReplyText')?.value || '').trim();
        if (!msg) return;
        try {
          await sfStudentPost('api/student/support.php', { action: 'reply', ticket_id: ticketId, message: msg });
          sfNotify('تم إرسال ردك بنجاح');
          modal.hidden = true;
          sfLoadSupport(sfSupportPage);
        } catch (err) {
          sfNotify(err.message, 'danger');
        }
      };
    }
  } catch (err) {
    sfNotify(err.message, 'danger');
  }
};

// Make openNewTicketModal available (hook into existing or create)
window.openNewTicketModal = function() {
  let modal = _sf_$('sfNewTicketModal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'sfNewTicketModal';
    modal.className = 'st-modal-overlay';
    modal.onclick = e => { if (e.target === modal) modal.hidden = true; };
    modal.innerHTML = `
      <div class="st-modal" style="max-width:500px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
          <h3 style="margin:0;">تذكرة دعم جديدة</h3>
          <button type="button" onclick="this.closest('.st-modal-overlay').hidden=true" style="background:transparent;border:none;font-size:20px;cursor:pointer;">✕</button>
        </div>
        <form id="sfNewTicketForm">
          <div style="margin-bottom:12px;">
            <label style="display:block;font-weight:600;margin-bottom:6px;">الموضوع *</label>
            <input type="text" id="sfTicketSubject" required style="width:100%;border:1px solid var(--border-color);border-radius:8px;padding:10px;">
          </div>
          <div style="margin-bottom:12px;">
            <label style="display:block;font-weight:600;margin-bottom:6px;">التصنيف *</label>
            <select id="sfTicketCategory" style="width:100%;border:1px solid var(--border-color);border-radius:8px;padding:10px;">
              <option value="technical">مشكلة تقنية</option>
              <option value="payment">الدفع والاشتراكات</option>
              <option value="content">محتوى الكورس</option>
              <option value="certificate">الشهادات</option>
              <option value="other">أخرى</option>
            </select>
          </div>
          <div style="margin-bottom:16px;">
            <label style="display:block;font-weight:600;margin-bottom:6px;">تفاصيل المشكلة *</label>
            <textarea id="sfTicketDesc" rows="4" required style="width:100%;border:1px solid var(--border-color);border-radius:8px;padding:10px;resize:vertical;"></textarea>
          </div>
          <button type="submit" class="st-btn st-btn-primary" style="width:100%;">📤 إرسال التذكرة</button>
        </form>
      </div>
    `;
    document.body.appendChild(modal);

    _sf_$('sfNewTicketForm').onsubmit = async e => {
      e.preventDefault();
      try {
        await sfStudentPost('api/student/support.php', {
          action: 'create',
          subject: _sf_$('sfTicketSubject').value,
          category: _sf_$('sfTicketCategory').value,
          description: _sf_$('sfTicketDesc').value,
        });
        modal.hidden = true;
        sfNotify('تم إرسال التذكرة بنجاح');
        sfLoadSupport(1);
      } catch (err) {
        sfNotify(err.message, 'danger');
      }
    };
  }
  modal.hidden = false;
};

/* ============================================================
   SKILLS LIBRARY TAB
   ============================================================ */
let sfSkillsState = { categoryId: 0, page: 1, search: '' };

async function sfLoadSkills() {
  // Ensure skills panel exists in DOM
  let panel = _sf_$('skillsPanel');
  if (!panel) {
    panel = document.createElement('section');
    panel.id = 'skillsPanel';
    panel.className = 'st-panel';
    panel.hidden = true;
    panel.innerHTML = sfBuildSkillsPanelHtml();
    // Insert before last panel or at end of main content
    const lastPanel = document.querySelector('.st-panel:last-of-type');
    if (lastPanel) lastPanel.after(panel);
    else document.querySelector('main')?.appendChild(panel);

    // Bind search
    const searchInput = _sf_$('sfSkillsSearch');
    if (searchInput) {
      let searchTimer;
      searchInput.oninput = () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
          sfSkillsState.search = searchInput.value;
          sfSkillsState.page = 1;
          sfFetchSkills();
        }, 400);
      };
    }
  }

  await sfLoadSkillCategories();
  await sfFetchSkills();
}

function sfBuildSkillsPanelHtml() {
  return `
    <div class="st-panel-header">
      <h2>🩺 مكتبة المهارات التمريضية</h2>
      <p style="color:var(--text-muted);font-size:14px;margin:4px 0 0;">
        تعلّم المهارات التمريضية خطوة بخطوة مع إرشادات الأمان والمعدات المطلوبة
      </p>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;align-items:center;">
      <input type="text" id="sfSkillsSearch" placeholder="🔍 ابحث في المهارات..." style="flex:1;min-width:200px;border:1px solid var(--border-color);border-radius:10px;padding:10px 14px;">
      <select id="sfSkillsCatFilter" onchange="sfFilterSkillCat()" style="border:1px solid var(--border-color);border-radius:10px;padding:10px 14px;">
        <option value="0">جميع التصنيفات</option>
      </select>
    </div>
    <div id="sfSkillsCatGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:24px;"></div>
    <div id="sfSkillsGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;"></div>
    <div id="sfSkillsPagination" style="text-align:center;margin-top:20px;"></div>

    <!-- Skill Detail Modal -->
    <div id="sfSkillDetailModal" class="st-modal-overlay" hidden onclick="if(event.target===this)this.hidden=true">
      <div class="st-modal" style="max-width:700px;max-height:85vh;overflow-y:auto;" id="sfSkillDetailContent"></div>
    </div>
  `;
}

async function sfLoadSkillCategories() {
  try {
    const res = await sfStudentGet('api/student/skills.php?action=categories');
    const cats = res.categories || [];
    const catGrid = _sf_$('sfSkillsCatGrid');
    const catSelect = _sf_$('sfSkillsCatFilter');

    if (catGrid) {
      catGrid.innerHTML = `
        <div class="sf-cat-chip ${sfSkillsState.categoryId === 0 ? 'active' : ''}" onclick="sfSetSkillCat(0)" style="padding:12px;border-radius:12px;border:2px solid ${sfSkillsState.categoryId===0?'var(--st-teal-600)':'var(--border-color)'};cursor:pointer;text-align:center;background:var(--bg-surface);">
          <div style="font-size:24px;">📚</div>
          <div style="font-size:12px;font-weight:600;margin-top:4px;">الكل</div>
        </div>
        ${cats.map(cat => `
          <div class="sf-cat-chip" onclick="sfSetSkillCat(${cat.id})" style="padding:12px;border-radius:12px;border:2px solid ${sfSkillsState.categoryId===cat.id?'var(--st-teal-600)':'var(--border-color)'};cursor:pointer;text-align:center;background:var(--bg-surface);">
            <div style="font-size:24px;">${_sf_esc(cat.icon || '🔬')}</div>
            <div style="font-size:12px;font-weight:600;margin-top:4px;">${_sf_esc(cat.name_ar || cat.name_en || '')}</div>
            <div style="font-size:11px;color:var(--text-muted);">${cat.skills_count} مهارة</div>
          </div>
        `).join('')}
      `;
    }
    if (catSelect) {
      catSelect.innerHTML = '<option value="0">جميع التصنيفات</option>' +
        cats.map(cat => `<option value="${cat.id}" ${sfSkillsState.categoryId===cat.id?'selected':''}>${_sf_esc(cat.name_ar||cat.name_en||'')}</option>`).join('');
    }
  } catch (err) {
    console.warn('Skills categories load failed:', err.message);
  }
}

window.sfSetSkillCat = function(catId) {
  sfSkillsState.categoryId = catId;
  sfSkillsState.page = 1;
  sfLoadSkillCategories();
  sfFetchSkills();
};

window.sfFilterSkillCat = function() {
  const sel = _sf_$('sfSkillsCatFilter');
  sfSkillsState.categoryId = sel ? parseInt(sel.value) : 0;
  sfSkillsState.page = 1;
  sfFetchSkills();
};

async function sfFetchSkills() {
  const grid = _sf_$('sfSkillsGrid');
  const pagination = _sf_$('sfSkillsPagination');
  if (!grid) return;
  grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:30px;">⏳ جارٍ التحميل...</div>';
  try {
    const params = new URLSearchParams({
      action: 'list',
      page: sfSkillsState.page,
      limit: 12,
    });
    if (sfSkillsState.categoryId) params.set('category_id', sfSkillsState.categoryId);
    if (sfSkillsState.search)    params.set('search', sfSkillsState.search);

    const res = await sfStudentGet(`api/student/skills.php?${params}`);
    const skills = res.skills || [];

    if (!skills.length) {
      grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--text-muted);">لا توجد مهارات في هذا التصنيف</div>';
      if (pagination) pagination.innerHTML = '';
      return;
    }

    const diffColors = { beginner: '#38a169', intermediate: '#d97706', advanced: '#e53e3e' };
    const diffLabels = { beginner: 'مبتدئ', intermediate: 'متوسط', advanced: 'متقدم' };

    grid.innerHTML = skills.map(sk => `
      <div class="st-course-card" style="cursor:pointer;" onclick="sfViewSkill(${sk.id})">
        <div style="height:120px;background:linear-gradient(135deg,var(--st-teal-600),#2d6a4f);border-radius:12px 12px 0 0;display:flex;align-items:center;justify-content:center;font-size:48px;">
          ${_sf_esc(sk.category_icon || '🩺')}
        </div>
        <div class="st-course-body">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <span class="st-badge" style="background:${diffColors[sk.difficulty_level]||'#718096'};color:#fff;">${diffLabels[sk.difficulty_level]||sk.difficulty_level||''}</span>
            ${!sk.is_accessible ? '<span class="st-badge st-badge-gold">🔒 يتطلب تسجيل</span>' : ''}
          </div>
          <h3 class="st-course-name" style="font-size:15px;">${_sf_esc(sk.title_ar || sk.title_en || '')}</h3>
          <p style="font-size:13px;color:var(--text-muted);margin:0 0 12px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">${_sf_esc(sk.summary_ar || sk.summary_en || '')}</p>
          <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);">
            <span>📂 ${_sf_esc(sk.category_name_ar || '')}</span>
            <span>⏱ ${sk.duration_minutes || '-'} دقيقة</span>
          </div>
        </div>
      </div>
    `).join('');

    // Pagination
    if (pagination && res.pages > 1) {
      let pHtml = '';
      for (let i = 1; i <= res.pages; i++) {
        pHtml += `<button type="button" class="st-btn st-btn-sm ${i === sfSkillsState.page ? 'st-btn-primary' : 'st-btn-outline'}" onclick="sfSkillsPage(${i})" style="margin:0 3px;">${i}</button>`;
      }
      pagination.innerHTML = pHtml;
    } else if (pagination) {
      pagination.innerHTML = '';
    }
  } catch (err) {
    grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;color:#e53e3e;padding:30px;">${_sf_esc(err.message)}</div>`;
  }
}

window.sfSkillsPage = function(page) {
  sfSkillsState.page = page;
  sfFetchSkills();
};

window.sfViewSkill = async function(skillId) {
  const modal = _sf_$('sfSkillDetailModal');
  const content = _sf_$('sfSkillDetailContent');
  if (!modal || !content) return;
  content.innerHTML = '<div style="text-align:center;padding:40px;">⏳ جارٍ التحميل...</div>';
  modal.hidden = false;

  try {
    const res = await sfStudentGet(`api/student/skills.php?action=detail&skill_id=${skillId}`);
    const sk = res.skill;

    const renderList = (arr, icon = '•') => Array.isArray(arr) && arr.length
      ? `<ul style="margin:8px 0;padding-right:20px;">${arr.map(item => `<li style="margin-bottom:6px;">${icon} ${_sf_esc(typeof item === 'string' ? item : (item.text_ar || item.text || JSON.stringify(item)))}</li>`).join('')}</ul>`
      : '<p style="color:var(--text-muted);">—</p>';

    const renderSteps = arr => Array.isArray(arr) && arr.length
      ? `<ol style="margin:8px 0;padding-right:20px;">${arr.map(step => `<li style="margin-bottom:10px;padding:8px;border-radius:8px;background:var(--bg-surface);">${_sf_esc(typeof step === 'string' ? step : (step.text_ar || step.description || JSON.stringify(step)))}</li>`).join('')}</ol>`
      : '<p style="color:var(--text-muted);">—</p>';

    content.innerHTML = `
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
        <div>
          <h2 style="margin:0 0 6px;">${_sf_esc(sk.title_ar || sk.title_en || '')}</h2>
          <div style="color:var(--text-muted);font-size:14px;">${_sf_esc(sk.category_name_ar || '')} · ${sk.duration_minutes || '-'} دقيقة</div>
        </div>
        <button type="button" onclick="this.closest('.st-modal-overlay').hidden=true" style="background:transparent;border:none;font-size:24px;cursor:pointer;">✕</button>
      </div>

      ${sk.summary_ar ? `<p style="border-right:4px solid var(--st-teal-600);padding-right:12px;color:var(--text-secondary);margin-bottom:20px;">${_sf_esc(sk.summary_ar)}</p>` : ''}

      <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:10px;padding:12px;margin-bottom:20px;font-size:13px;">
        ⚠️ <strong>تنبيه طبي:</strong> هذه المهارات للأغراض التعليمية فقط. يجب ممارستها تحت إشراف مشرف طبي مؤهل.
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;">
        <div>
          <h4 style="color:var(--st-teal-600);margin:0 0 8px;">🎯 الأهداف التعليمية</h4>
          ${renderList(sk.objectives, '✓')}
        </div>
        <div>
          <h4 style="color:var(--st-teal-600);margin:0 0 8px;">🧰 المعدات المطلوبة</h4>
          ${renderList(sk.equipment_list, '📦')}
        </div>
      </div>

      <div style="margin-top:20px;">
        <h4 style="color:var(--st-teal-600);margin:0 0 8px;">📋 خطوات الأداء</h4>
        ${renderSteps(sk.steps)}
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;margin-top:20px;">
        <div>
          <h4 style="color:#e53e3e;margin:0 0 8px;">🛡️ ملاحظات السلامة</h4>
          ${renderList(sk.safety_notes, '⚠️')}
        </div>
        <div>
          <h4 style="color:#d97706;margin:0 0 8px;">❗ الأخطاء الشائعة</h4>
          ${renderList(sk.common_mistakes, '✗')}
        </div>
      </div>

      ${Array.isArray(sk.review_questions) && sk.review_questions.length ? `
        <div style="margin-top:20px;padding:16px;border-radius:12px;background:var(--bg-surface);">
          <h4 style="color:var(--st-teal-600);margin:0 0 12px;">❓ أسئلة المراجعة</h4>
          <ol style="padding-right:20px;">
            ${sk.review_questions.map(q => `<li style="margin-bottom:8px;">${_sf_esc(typeof q === 'string' ? q : (q.question_ar || q.question || JSON.stringify(q)))}</li>`).join('')}
          </ol>
        </div>
      ` : ''}
    `;
  } catch (err) {
    content.innerHTML = `
      <div style="text-align:center;padding:40px;">
        ${err.message.includes('تسجيل') ? `
          <div style="font-size:48px;margin-bottom:16px;">🔒</div>
          <h3>يتطلب تسجيلاً في الكورس</h3>
          <p style="color:var(--text-muted);">سجّل في الكورس المرتبط للوصول إلى تفاصيل هذه المهارة</p>
          <button type="button" class="st-btn st-btn-primary" onclick="sfSetSkillCat(0);this.closest('.st-modal-overlay').hidden=true">استعرض الكورسات</button>
        ` : `<p style="color:#e53e3e;">${_sf_esc(err.message)}</p>`}
      </div>
    `;
  }
};

/* ============================================================
   TAB HOOK — Intercept tab switching to load live data
   ============================================================ */
function sfHookTabSwitching() {
  const tabLoaders = {
    quizzes:      sfLoadQuizzes,
    grades:       sfLoadGrades,
    certificates: sfLoadCertificates,
    support:      sfLoadSupport,
    skills:       sfLoadSkills,
  };

  // Intercept clicks on nav items
  document.addEventListener('click', e => {
    const btn = e.target.closest('[data-tab]');
    if (!btn) return;
    const tab = btn.dataset.tab;
    const loader = tabLoaders[tab];
    if (loader) {
      // Small delay to let existing switchTab() run first
      setTimeout(() => loader(), 50);
    }

    // Handle skills tab panel visibility (may not exist yet)
    if (tab === 'skills') {
      setTimeout(() => {
        const panel = _sf_$('skillsPanel');
        if (panel) panel.hidden = false;
        // Hide all other panels
        document.querySelectorAll('.st-panel:not(#skillsPanel)').forEach(p => p.hidden = true);
      }, 60);
    }
  });

  // Also hook search-item shortcuts
  document.addEventListener('click', e => {
    const btn = e.target.closest('[data-search-tab]');
    if (!btn) return;
    const tab = btn.dataset.searchTab;
    const loader = tabLoaders[tab];
    if (loader) setTimeout(() => loader(), 50);
  });
}

/* ============================================================
   ADD SKILLS NAV ITEM to sidebar if not already present
   ============================================================ */
function sfAddSkillsNav() {
  if (document.querySelector('[data-tab="skills"]')) return; // Already exists
  const supportBtn = document.querySelector('[data-tab="support"]');
  if (!supportBtn) return;

  const skillsBtn = document.createElement('button');
  skillsBtn.type = 'button';
  skillsBtn.className = 'st-nav-item';
  skillsBtn.dataset.tab = 'skills';
  skillsBtn.innerHTML = `
    <span class="st-nav-icon">🩺</span>
    <span class="st-item-text">مكتبة المهارات</span>
  `;
  supportBtn.parentNode.insertBefore(skillsBtn, supportBtn);
}

/* ============================================================
   INIT
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
  sfAddSkillsNav();
  sfHookTabSwitching();

  // Load initial tab data based on URL hash or current active tab
  const hash = window.location.hash.replace('#', '');
  if (hash === 'quizzes')       sfLoadQuizzes();
  else if (hash === 'grades')   sfLoadGrades();
  else if (hash === 'certs')    sfLoadCertificates();
  else if (hash === 'support')  sfLoadSupport();
  else if (hash === 'skills')   sfLoadSkills();
});

// Export for external use
window.sfLoadQuizzes       = sfLoadQuizzes;
window.sfLoadGrades        = sfLoadGrades;
window.sfLoadCertificates  = sfLoadCertificates;
window.sfLoadSupport       = sfLoadSupport;
window.sfLoadSkills        = sfLoadSkills;
