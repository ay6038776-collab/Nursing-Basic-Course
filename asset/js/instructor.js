'use strict';

/**
 * =========================================================================
 * Edu Nursing — Official Instructor Dashboard Engine (asset/js/instructor.js)
 * Medical Education Academy  —  Modern Clinical Architecture
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
const currentLocale = () => document.documentElement.lang === 'en' ? 'en-US' : 'ar-EG';

let user = null;
let csrfToken = '';
window.nbcInstructorCsrfToken = () => csrfToken;
let currentTab = 'overview';
let cachedCourses = [];
let cachedStudents = { subscribers: [], visitors: [] };
let cachedLessons = [];
let cachedReviews = [];
let activeStudentSubTab = 'subscribers';
let selectedLessonCourseId = 0;
let cachedSections = [];
let cachedMeetings = [];
let lessonsCurrentView = 'curriculum';
let meetingsCurrentView = 'cards';

// Tab Label Mapping for Breadcrumb
const TAB_TITLES = {
  overview: 'نظرة عامة والتحليلات',
  courses: 'كورساتي التدريبية',
  lessons: 'إدارة الدروس والمحتوى',
  'question-bank': 'بنك الأسئلة',
  quizzes: 'الاختبارات والتقييم',
  students: 'سجل الطلاب والتواصل',
  reviews: 'تقييمات وآراء الطلاب',
  meetings: 'محاضرات Zoom المباشرة',
  announcements: 'الإعلانات والتنبيهات',
  profile: 'الملف المهني والبيو'
};

/* -------------------------------------------------------------------------
   1) Centralized Fetch / AJAX Helper with CSRF Support
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
    throw new Error(`استجابة الخادم غير صالحة (HTTP ${response.status}). راجع ملفات PHP.`);
  }

  if (data.csrf_token) {
    csrfToken = data.csrf_token;
  }

  if (response.status === 401) {
    showLoginView();
    throw new Error('انتهت الجلسة. يرجى تسجيل الدخول مجدداً.');
  }

  if (!response.ok || !data.success) {
    throw new Error(data.message || `حدث خطأ في الخادم (${response.status}).`);
  }

  return data;
}

async function api(action, body = {}) {
  return requestJSON(`${ROOT}api/instructor/api.php?a=${encodeURIComponent(action)}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  });
}

function notify(message, type = 'success') {
  const box = $('globalNotice');
  if (!box) return;
  box.textContent = message;
  box.className = `notice notice-${type}`;
  box.style.display = 'block';
  box.hidden = false;
  window.scrollTo({ top: 0, behavior: 'smooth' });
  setTimeout(() => {
    box.hidden = true;
    box.style.display = 'none';
  }, 6000);
}

/* -------------------------------------------------------------------------
   2) Login / App Shell Visibility
   ------------------------------------------------------------------------- */
function showLoginView() {
  user = null;
  window.location.replace(`${ROOT}auth/login.php`);
}

function showAppView() {
  const lView = $('loginView');
  const aView = $('appView');
  if (lView) lView.hidden = true;
  if (aView) aView.hidden = false;
  document.title = 'لوحة المحاضر الأكاديمية | Edu Nursing';
}

/* -------------------------------------------------------------------------
   3) Modal Open / Close Helpers
   ------------------------------------------------------------------------- */
window.openModal = function(id) {
  const m = $(id);
  if (m) m.hidden = false;
};

window.closeModal = function(id) {
  const m = $(id);
  if (m) m.hidden = true;
};

window.closeVideoPlayer = function() {
  const m = $('videoPlayerModal');
  const container = $('videoPlayerContainer');
  if (container) container.innerHTML = '';
  if (m) m.hidden = true;
};

// Close modal on Escape key or backdrop click
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    $$('.ins-modal-overlay').forEach(m => {
      m.hidden = true;
    });
    closeVideoPlayer();
  }
});
document.addEventListener('click', e => {
  if (e.target.classList.contains('ins-modal-overlay')) {
    e.target.hidden = true;
    if (e.target.id === 'videoPlayerModal') closeVideoPlayer();
  }
});

/* -------------------------------------------------------------------------
   4) Theme Switcher (Dark / Light)
   ------------------------------------------------------------------------- */
function initTheme() {
  function apply(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.style.removeProperty('background-color');
    localStorage.setItem('nbc_instructor_theme', theme);
    localStorage.setItem('nbc_theme', theme);

    $$('.theme-toggle-btn, .topbar-icon-btn#themeToggle').forEach(btn => {
      const sun = btn.querySelector('.theme-icon-sun');
      const moon = btn.querySelector('.theme-icon-moon');
      const label = btn.querySelector('.theme-btn-label');
      if (sun) sun.hidden = (theme !== 'dark');
      if (moon) moon.hidden = (theme === 'dark');
      if (label) label.textContent = (theme === 'dark') ? 'الوضع النهاري' : 'الوضع الليلي';
    });
  }

  const saved = localStorage.getItem('nbc_theme') || localStorage.getItem('nbc_instructor_theme') || 'dark';
  apply(saved);
  window.addEventListener('storage', event => {
    if (event.key === 'nbc_theme' && (event.newValue === 'dark' || event.newValue === 'light')) apply(event.newValue);
  });

  // Bind app topbar theme toggle
  const topbarThemeBtn = $('themeToggle');
  if (topbarThemeBtn) {
    topbarThemeBtn.onclick = () => {
      const cur = document.documentElement.getAttribute('data-theme') || 'dark';
      apply(cur === 'dark' ? 'light' : 'dark');
    };
  }

  // Bind login view theme toggle
  const loginThemeBtn = $('loginThemeToggle');
  if (loginThemeBtn) {
    loginThemeBtn.onclick = () => {
      const cur = document.documentElement.getAttribute('data-theme') || 'dark';
      apply(cur === 'dark' ? 'light' : 'dark');
    };
  }
}

/* -------------------------------------------------------------------------
   5) Sidebar & Navigation
   ------------------------------------------------------------------------- */
function switchTab(tabId) {
  const changedTab = currentTab !== tabId;
  if (changedTab) window.DashboardEffects?.show();
  currentTab = tabId;

  // Update sidebar active buttons
  $$('.side-link.tab').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.tab === tabId);
  });

  // Update mobile tabs
  $$('.tabs .tab').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.tab === tabId);
  });

  // Update breadcrumb
  const bc = $('breadcrumbCurrent');
  if (bc) bc.textContent = TAB_TITLES[tabId] || tabId;

  // Hide all panels, show target
  $$('.admin-content .panel').forEach(p => p.hidden = true);
  const target = $(tabId === 'question-bank' ? 'questionBankPanel' : `${tabId}Panel`);
  if (target) target.hidden = false;
  if (changedTab) window.DashboardEffects?.enter(target);

  // Close mobile sidebar if open
  closeMobileSidebar();

  // Load data for this tab
  loadTabData(tabId);
}

function closeMobileSidebar() {
  const sb = $('instructorSidebar');
  const bd = $('sidebarBackdrop');
  if (sb) sb.classList.remove('open', 'mobile-open');
  if (bd) bd.classList.remove('open', 'mobile-open');
}

function initNavigation() {
  $$('.side-link.tab, .tabs .tab').forEach(btn => {
    btn.onclick = () => switchTab(btn.dataset.tab);
  });

  // Mobile sidebar toggle
  const toggleBtn = $('sidebarToggle');
  const bd = $('sidebarBackdrop');
  if (toggleBtn) {
    toggleBtn.onclick = () => {
      const sb = $('instructorSidebar');
      if (sb) {
        sb.classList.toggle('open');
        sb.classList.toggle('mobile-open');
      }
      if (bd) {
        bd.classList.toggle('open');
        bd.classList.toggle('mobile-open');
      }
    };
  }
  if (bd) {
    bd.onclick = closeMobileSidebar;
  }

  // Quick Action Buttons in Overview
  if ($('btnQuickCourse')) $('btnQuickCourse').onclick = () => { switchTab('courses'); openNewCourseModal(); };
  if ($('btnQuickLesson')) $('btnQuickLesson').onclick = () => { switchTab('lessons'); openNewLessonModal(); };
  if ($('btnQuickMeeting')) $('btnQuickMeeting').onclick = () => { switchTab('meetings'); openNewMeetingModal(); };
  if ($('btnQuickAnnouncement')) $('btnQuickAnnouncement').onclick = () => { switchTab('announcements'); openNewAnnouncementModal(); };

  // Primary Header Action Buttons across panels
  if ($('btnOpenNewCourseModal')) $('btnOpenNewCourseModal').onclick = () => window.openNewCourseModal();
  if ($('btnOpenAddLessonModal')) $('btnOpenAddLessonModal').onclick = () => window.openNewLessonModal(selectedLessonCourseId);
  if ($('btnOpenNewMeetingModal')) $('btnOpenNewMeetingModal').onclick = () => window.openNewMeetingModal();
  if ($('btnOpenNewAnnouncementModal')) $('btnOpenNewAnnouncementModal').onclick = () => window.openNewAnnouncementModal();

  if ($('refreshBtn')) $('refreshBtn').onclick = () => {
    loadTabData(currentTab);
    notify('تم تحديث البيانات من قاعدة البيانات بنجاح.');
  };

  // Global search (Ctrl + K)
  const sInput = $('globalSearchInput');
  const sDropdown = $('searchDropdown');

  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      if (sInput) sInput.focus();
    }
  });

  if (sInput) {
    sInput.addEventListener('focus', () => {
      if (sDropdown) sDropdown.hidden = false;
    });

    sInput.addEventListener('blur', () => {
      setTimeout(() => { if (sDropdown) sDropdown.hidden = true; }, 250);
    });

    sInput.addEventListener('input', e => {
      const q = e.target.value.trim().toLowerCase();
      handleGlobalSearch(q);
    });
  }

  // Quick search jump buttons
  $$('.search-item[data-search-tab]').forEach(item => {
    item.onmousedown = () => {
      const tab = item.dataset.searchTab;
      if (tab) switchTab(tab);
      if (sDropdown) sDropdown.hidden = true;
    };
  });
}

function handleGlobalSearch(q) {
  if (!q) {
    loadTabData(currentTab);
    return;
  }

  if (currentTab === 'courses') {
    renderCoursesGrid(cachedCourses.filter(c => 
      c.title.toLowerCase().includes(q) || 
      (c.category && c.category.toLowerCase().includes(q))
    ));
  } else if (currentTab === 'lessons') {
    renderLessonsTable(cachedLessons.filter(l => 
      l.title.toLowerCase().includes(q) || 
      (l.description && l.description.toLowerCase().includes(q))
    ));
  } else if (currentTab === 'students') {
    const list = (activeStudentSubTab === 'subscribers') ? cachedStudents.subscribers : cachedStudents.visitors;
    renderStudentsTable(list.filter(s => 
      s.full_name.toLowerCase().includes(q) || 
      (s.phone && s.phone.includes(q)) || 
      (s.course && s.course.toLowerCase().includes(q)) ||
      (s.email && s.email.toLowerCase().includes(q))
    ));
  } else if (currentTab === 'reviews') {
    renderReviewsTable(cachedReviews.filter(r => 
      r.student_name.toLowerCase().includes(q) || 
      r.course_title.toLowerCase().includes(q) || 
      (r.comment && r.comment.toLowerCase().includes(q))
    ));
  }
}

/* -------------------------------------------------------------------------
   6) Loader for Tab Data
   ------------------------------------------------------------------------- */
async function loadTabData(tabId) {
  try {
    switch (tabId) {
      case 'overview':
        await loadOverview();
        break;
      case 'courses':
        await loadCourses();
        break;
      case 'lessons':
        await loadLessonsView();
        break;
      case 'question-bank':
        await window.AssessmentInstructor?.loadQuestionBank();
        break;
      case 'quizzes':
        await loadQuizzes();
        break;
      case 'students':
        await loadStudents();
        break;
      case 'reviews':
        await loadReviews();
        break;
      case 'meetings':
        await loadMeetings();
        break;
      case 'announcements':
        await loadAnnouncements();
        break;
      case 'profile':
        loadProfileData();
        break;
    }
  } catch (err) {
    console.error(`Error loading tab [${tabId}]:`, err);
    notify(err.message, 'error');
  }
}

/* -------------------------------------------------------------------------
   7) Overview Panel Logic
   ------------------------------------------------------------------------- */
async function loadOverview() {
  const res = await api('stats');
  const totals = res.totals || {};

  if ($('statCourses')) $('statCourses').textContent = totals.courses || 0;
  if ($('statLessons')) $('statLessons').textContent = totals.lessons || 0;
  if ($('statSubscribers')) $('statSubscribers').textContent = totals.subscribers || 0;
  if ($('statVisitors')) $('statVisitors').textContent = totals.visitors || 0;

  // Populate course filter in Overview
  const fCourse = $('fCourse');
  if (fCourse && res.courses) {
    fCourse.innerHTML = '<option value="">كل كورساتي</option>' + 
      res.courses.map(c => `<option value="${c.id}">${esc(c.title)}</option>`).join('');
  }

  // 1) Daily visits table
  const visitsTbody = $('dailyVisitsTbody');
  if (visitsTbody) {
    if (!res.daily || !res.daily.length) {
      visitsTbody.innerHTML = '<tr><td colspan="2" style="text-align:center;color:var(--slate-400)">لا توجد زيارات مسجلة خلال الفترة الأخيرة.</td></tr>';
    } else {
      let totalVisits = 0;
      visitsTbody.innerHTML = res.daily.map(row => {
        totalVisits += Number(row.n || 0);
        return `
          <tr>
            <td><strong>${esc(row.d)}</strong></td>
            <td><span class="pill" style="font-weight:700;">${Number(row.n).toLocaleString(currentLocale())} ${document.documentElement.lang === 'en' ? 'visits and lectures' : 'زيارة ومحاضرة'}</span></td>
          </tr>
        `;
      }).join('');
      if ($('visitsTotalPill')) $('visitsTotalPill').textContent = `${totalVisits.toLocaleString(currentLocale())} ${document.documentElement.lang === 'en' ? 'visits' : 'زيارة'}`;
    }
  }

  // 2) Course Completion Rates
  const compList = $('completionList');
  if (compList) {
    if (!res.completion_rates || !res.completion_rates.length) {
      compList.innerHTML = '<div style="text-align:center;color:var(--slate-400);padding:20px;">لا توجد بيانات تقدم دراسي كافية حتى الآن.</div>';
    } else {
      compList.innerHTML = res.completion_rates.map(c => {
        const pct = Math.min(100, Math.max(0, c.avg_progress || 0));
        return `
          <div style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:13.5px;font-weight:700;margin-bottom:4px;">
              <span>${esc(c.title)} <small style="color:var(--slate-500);font-weight:500;">(${c.subscribers} مشترك  —  ${c.completed_students} أتموا الكورس)</small></span>
              <span style="color:var(--teal-600);">${pct}%</span>
            </div>
            <div class="progress-bar-wrap">
              <div class="progress-bar-fill" style="width: ${pct}%;"></div>
            </div>
          </div>
        `;
      }).join('');
    }
  }

  // 3) Recent Subscribers with WhatsApp Contact
  const subsTbody = $('recentSubscribersTbody');
  if (subsTbody) {
    if (!res.recent_subscribers || !res.recent_subscribers.length) {
      subsTbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--slate-400)">لا يوجد مشتركون جدد بعد.</td></tr>';
    } else {
      subsTbody.innerHTML = res.recent_subscribers.map(s => {
        const cleanPhone = String(s.phone || '').replace(/\D/g, '');
        const waMsg = encodeURIComponent(`مرحباً دكتور/أستاذ ${s.full_name || ''}، معك د. ${user ? user.full_name : 'المحاضر'} بخصوص كورس ${s.course_title || ''}. يسعدني متابعة تدريبك السريري.`);
        const waLink = cleanPhone ? `https://wa.me/2${cleanPhone}?text=${waMsg}` : '';
        const pct = Number(s.progress_percent || 0);

        return `
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--teal-600),var(--navy-900));color:#fff;display:grid;place-items:center;font-weight:800;font-size:13px;flex-shrink:0;">
                  ${esc((s.full_name || 'ط').charAt(0))}
                </div>
                <div>
                  <div style="font-weight:700;">${esc(s.full_name || 'طالب')}</div>
                  <div style="font-size:11px;color:var(--slate-400);">${esc(s.email || '')}</div>
                </div>
              </div>
            </td>
            <td><strong>${esc(s.course_title || '')}</strong></td>
            <td>
              <div style="display:flex;align-items:center;gap:8px;">
                <span style="font-size:12px;font-weight:800;color:var(--teal-600);">${pct}%</span>
                <div class="progress-bar-wrap" style="width:80px;">
                  <div class="progress-bar-fill" style="width:${pct}%;"></div>
                </div>
              </div>
            </td>
            <td>
              ${waLink ? `
                <a href="${waLink}" target="_blank" class="btn-whatsapp" title="محادثة واتساب مباشرة">
                  <span>💬 واتساب</span>
                </a>
              ` : '<span style="color:var(--slate-400);font-size:12px;">غير متوفر</span>'}
            </td>
          </tr>
        `;
      }).join('');
    }
  }

  // 4) Recent Reviews
  const revTbody = $('recentReviewsTbody');
  if (revTbody) {
    if (!res.recent_reviews || !res.recent_reviews.length) {
      revTbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--slate-400)">لا توجد مراجعات مسجلة حتى الآن.</td></tr>';
    } else {
      revTbody.innerHTML = res.recent_reviews.map(r => `
        <tr>
          <td><strong>${esc(r.student_name || 'طالب')}</strong></td>
          <td>${esc(r.course_title || '')}</td>
          <td><span class="star-rating">${'★'.repeat(r.rating || 5)}${'☆'.repeat(5 - (r.rating || 5))}</span></td>
          <td style="font-size:12.5px;color:var(--slate-600);">${esc(r.comment || 'تقييم ممتاز')}</td>
        </tr>
      `).join('');
    }
  }

  // 5) Upcoming live meetings
  const meetTbody = $('upcomingMeetingsTbody');
  if (meetTbody) {
    if (!res.upcoming_meetings || !res.upcoming_meetings.length) {
      meetTbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--slate-400)">لا توجد محاضرات زوم مجدولة قريباً.</td></tr>';
    } else {
      meetTbody.innerHTML = res.upcoming_meetings.map(m => `
        <tr>
          <td><strong>${esc(m.title)}</strong></td>
          <td>${esc(m.course_title)}</td>
          <td><span class="pill">${esc(m.scheduled_at || m.starts_at)}</span></td>
          <td>
            <a href="${esc(m.meeting_url)}" target="_blank" class="btn btn-sm btn-primary">
              انضمام الآن ↗
            </a>
          </td>
        </tr>
      `).join('');
    }
  }
}

/* -------------------------------------------------------------------------
   8) Courses Panel Logic
   ------------------------------------------------------------------------- */
async function loadCourses() {
  const res = await api('courses');
  cachedCourses = res.courses || [];
  renderCoursesGrid(cachedCourses);

  // Populate course dropdowns in modals and lessons tab
  populateCourseDropdowns(cachedCourses);
}

function renderCoursesGrid(courses) {
  const grid = $('instructorCoursesGrid');
  if (!grid) return;

  if (!courses.length) {
    grid.innerHTML = `
      <div style="grid-column: 1/-1; text-align: center; padding: 48px 20px; background: var(--white); border-radius: var(--radius-lg); border: 1px dashed var(--line);">
        <div style="font-size: 38px; margin-bottom: 12px;">🩺</div>
        <h3 style="margin: 0 0 8px;">لم تقم بإنشاء أو إسناد أي كورس تدريبي بعد</h3>
        <p style="color: var(--slate-500); margin: 0 0 16px;">ابدأ الآن بإعداد أول مسار تدريبي لطلابك ورفع الشروحات السريرية.</p>
        <button type="button" class="btn btn-primary" onclick="openNewCourseModal()">+ إنشاء كورس تدريبي جديد</button>
      </div>
    `;
    return;
  }

  grid.innerHTML = courses.map(c => {
    const statusLabels = {
      published: '<span class="pill" style="background:rgba(16,185,129,0.12);color:#10b981;border-color:rgba(16,185,129,0.3)">منشور متاح للطلاب</span>',
      draft: '<span class="pill" style="background:rgba(245,158,11,0.12);color:#f59e0b;border-color:rgba(245,158,11,0.3)">مسودة قيد الإعداد</span>',
      archived: '<span class="pill" style="background:rgba(100,116,139,0.12);color:#64748b;border-color:rgba(100,116,139,0.3)">مؤرشف</span>'
    };

    const thumbHtml = c.thumbnail ? 
      `<img src="${esc(c.thumbnail)}" alt="${esc(c.title)}" class="ins-course-thumb">` :
      `<div class="ins-course-thumb" style="display:grid;place-items:center;color:#fff;font-size:32px;">🩺</div>`;

    const priceHtml = c.discounted_price ? 
      `<strong>${c.discounted_price} ج.م</strong> <span style="text-decoration:line-through;color:var(--slate-400);font-size:12px;margin-right:6px;">${c.price} ج.م</span>` :
      `<strong>${c.price} ج.م</strong>`;

    return `
      <div class="ins-course-card">
        ${thumbHtml}
        <div class="ins-course-body">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <span class="badge-source">${esc(c.category || 'تمريض سريري')}</span>
            ${statusLabels[c.status] || ''}
          </div>
          <h3 class="ins-course-title">${esc(c.title)}</h3>
          <p class="ins-course-desc">${esc(c.description || 'لا يوجد وصف متاح للكورس.')}</p>
          
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;font-size:12.5px;color:var(--slate-500);">
            <span>🎬 ${c.lessons || 0} درس</span>
            <span>👥 ${c.subscribers || 0} مشترك</span>
            <span>⏱️ ${c.duration_minutes || 0} دقيقة</span>
          </div>

          <div class="ins-course-meta">
            <div>${priceHtml}</div>
            <div style="display:flex;gap:6px;">
              <button type="button" class="btn btn-sm btn-outline" onclick="openLessonsForCourse(${c.id})" title="إدارة الدروس">
                الدروس 🎬
              </button>
              <button type="button" class="btn btn-sm btn-outline" onclick="openEditCourseModal(${c.id})" title="تعديل الكورس">
                تعديل ✏️
              </button>
              <button type="button" class="btn btn-sm btn-ghost" onclick="confirmDeleteCourse(${c.id})" style="color:var(--danger)" title="حذف الكورس">
                ✕
              </button>
            </div>
          </div>
        </div>
      </div>
    `;
  }).join('');
}

function populateCourseDropdowns(courses) {
  const options = courses.map(c => `<option value="${c.id}">${esc(c.title)}</option>`).join('');

  if ($('lessonCourseSelect')) {
    $('lessonCourseSelect').innerHTML = '<option value="">-- اختر الكورس لعرض دروسه --</option>' + options;
    if (selectedLessonCourseId) $('lessonCourseSelect').value = selectedLessonCourseId;
  }
  if ($('meetingCourseSelect')) $('meetingCourseSelect').innerHTML = options;
  if ($('announcementCourseSelect')) {
    $('announcementCourseSelect').innerHTML = '<option value="">جميع كورساتي (إشعار عام لكافة طلابي)</option>' + options;
  }
  if ($('studentCourseFilter')) {
    $('studentCourseFilter').innerHTML = '<option value="">جميع الكورسات</option>' + options;
  }
  if ($('reviewsCourseSelect')) {
    $('reviewsCourseSelect').innerHTML = '<option value="">كل الكورسات</option>' + options;
  }
}

window.openNewCourseModal = function() {
  $('courseModalId').value = '0';
  $('courseModalTitle').textContent = 'إضافة كورس تدريبي جديد';
  $('courseTitleAr').value = '';
  $('courseTitleEn').value = '';
  $('courseDesc').value = '';
  $('coursePrice').value = '450';
  $('courseDiscountPrice').value = '';
  $('courseDuration').value = '120';
  $('courseCategory').value = 'أساسيات التمريض السريري';
  $('courseLevel').value = 'beginner';
  $('courseStatus').value = 'published';
  $('courseThumbnail').value = '';
  openModal('courseModal');
};

window.openEditCourseModal = function(courseId) {
  const c = cachedCourses.find(item => item.id === courseId);
  if (!c) return;

  $('courseModalId').value = c.id;
  $('courseModalTitle').textContent = `تعديل كورس: ${c.title}`;
  $('courseTitleAr').value = c.title_ar || c.title;
  $('courseTitleEn').value = c.title_en || '';
  $('courseDesc').value = c.description || '';
  $('coursePrice').value = c.price;
  $('courseDiscountPrice').value = c.discounted_price || '';
  $('courseDuration').value = c.duration_minutes || 0;
  $('courseCategory').value = c.category || '';
  $('courseLevel').value = c.level || 'beginner';
  $('courseStatus').value = c.status || 'published';
  $('courseThumbnail').value = '';
  openModal('courseModal');
};

window.confirmDeleteCourse = async function(courseId) {
  const c = cachedCourses.find(item => item.id === courseId);
  if (!confirm(`هل أنت متأكد من حذف كورس "${c ? c.title : ''}"؟\nسيتم حذف كافة الدروس والاشتراكات المرتبطة به نهائياً.`)) return;

  try {
    await api('delete_course', { course_id: courseId });
    notify('تم حذف الكورس بنجاح.');
    await loadCourses();
  } catch (err) {
    alert(err.message);
  }
};

/* -------------------------------------------------------------------------
   9) Lessons Panel Logic
   ------------------------------------------------------------------------- */
window.openLessonsForCourse = function(courseId) {
  selectedLessonCourseId = courseId;
  switchTab('lessons');
  if ($('lessonCourseSelect')) $('lessonCourseSelect').value = courseId;
  loadCourseLessons(courseId);
};

async function loadLessonsView() {
  if (!cachedCourses.length) {
    await loadCourses();
  }
  const select = $('lessonCourseSelect');
  if (select && select.value) {
    loadCourseLessonsAndCurriculum(Number(select.value));
  } else if (cachedCourses.length) {
    selectedLessonCourseId = cachedCourses[0].id;
    if (select) select.value = selectedLessonCourseId;
    loadCourseLessonsAndCurriculum(selectedLessonCourseId);
  }
}

async function loadCourseLessonsAndCurriculum(courseId) {
  selectedLessonCourseId = Number(courseId);
  const secGrid = $('curriculumSectionsGrid');
  const tbody = $('lessonsTableBody');

  if (!selectedLessonCourseId) {
    if (secGrid) secGrid.innerHTML = '<div style="padding:40px;text-align:center;color:var(--slate-400)">اختر كورساً لعرض منهجه ووحداته ودروسه.</div>';
    if (tbody) tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--slate-400)">اختر كورساً من القائمة أعلاه لعرض دروسه.</td></tr>';
    return;
  }

  if (secGrid) secGrid.innerHTML = '<div style="padding:30px;text-align:center;color:var(--slate-400)">جارٍ تحميل المنهج والدروس...</div>';
  if (tbody) tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--slate-400)">جارٍ تحميل الدروس...</td></tr>';

  try {
    const [lRes, sRes] = await Promise.all([
      api('lessons', { course_id: selectedLessonCourseId }),
      api('sections', { course_id: selectedLessonCourseId }).catch(() => ({ sections: [] }))
    ]);

    cachedLessons = lRes.lessons || [];
    cachedSections = sRes.sections || [];

    renderCurriculum(cachedSections, cachedLessons);
    renderLessonsTable(cachedLessons);
  } catch (err) {
    if (secGrid) secGrid.innerHTML = `<div style="padding:30px;text-align:center;color:var(--danger)">${esc(err.message)}</div>`;
    if (tbody) tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;color:var(--danger)">${esc(err.message)}</td></tr>`;
  }
}

window.loadCourseLessons = loadCourseLessonsAndCurriculum;

function renderCurriculum(sections, lessons) {
  const container = $('curriculumSectionsGrid');
  if (!container) return;

  if ($('lessonsSectionsBadge')) $('lessonsSectionsBadge').textContent = `${sections.length} وحدات منهجية`;

  if (!sections.length && !lessons.length) {
    container.innerHTML = `
      <div style="background:var(--slate-50);border:2px dashed var(--line);border-radius:18px;padding:48px 24px;text-align:center;">
        <div style="font-size:36px;margin-bottom:12px;">📚</div>
        <h3 style="margin:0 0 8px;font-size:18px;">لم يتم إنشاء وحدات منهجية أو دروس لهذا الكورس بعد</h3>
        <p style="color:var(--slate-500);font-size:13.5px;max-width:480px;margin:0 auto 20px;">
          ابدأ ببناء منهج الكورس وتقسيمه إلى وحدات دراسية (مثل: الوحدة الأولى: مكافحة العدوى، الوحدة الثانية: العلامات الحيوية).
        </p>
        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
          <button type="button" class="btn btn-outline" onclick="openNewSectionModal(${selectedLessonCourseId})">+ إضافة وحدة / منهج جديد</button>
          <button type="button" class="btn btn-primary" onclick="openNewLessonModal(${selectedLessonCourseId})">+ إضافة درس مباشر</button>
        </div>
      </div>
    `;
    return;
  }

  // Group lessons by section_id
  const sectionMap = {};
  const unassigned = [];
  lessons.forEach(l => {
    const sId = Number(l.section_id || 0);
    if (sId > 0) {
      if (!sectionMap[sId]) sectionMap[sId] = [];
      sectionMap[sId].push(l);
    } else {
      unassigned.push(l);
    }
  });

  let html = '';

  // Render each section card
  sections.forEach((sec, idx) => {
    const secLessons = sectionMap[sec.id] || [];
    let secDuration = 0;
    secLessons.forEach(l => { secDuration += Number(l.duration_minutes || 0); });

    html += `
      <div class="curriculum-unit-card" id="sec-card-${sec.id}" style="margin-bottom:16px;">
        <div class="unit-head">
          <div class="unit-title-box">
            <div class="unit-badge-num">${idx + 1}</div>
            <div>
              <h3 class="unit-title">${esc(sec.title)}</h3>
              ${sec.description ? `<div class="unit-desc">${esc(sec.description)}</div>` : ''}
              <div style="font-size:11.5px;color:var(--slate-500);margin-top:4px;">
                <span>${secLessons.length} دروس</span>  —  <span>${secDuration} دقيقة</span>
              </div>
            </div>
          </div>
          <div class="unit-actions">
            <button type="button" class="btn btn-sm btn-outline" onclick="openNewLessonModal(${selectedLessonCourseId}, ${sec.id})" style="border-color:var(--teal-600);color:var(--teal-600);font-weight:700;">
              + إضافة درس للوحدة
            </button>
            <button type="button" class="btn btn-sm btn-ghost" onclick="openEditSectionModal(${sec.id})">
              ✏️ تعديل
            </button>
            <button type="button" class="btn btn-sm btn-ghost" onclick="confirmDeleteSection(${sec.id})" style="color:var(--danger)">
              🗑️ حذف
            </button>
          </div>
        </div>

        <div class="unit-lessons-list">
          ${secLessons.length ? secLessons.map((l, lIdx) => renderLessonRowHtml(l, lIdx + 1)).join('') : `
            <div style="padding:14px;text-align:center;color:var(--slate-400);font-size:13px;">
              لا توجد دروس في هذه الوحدة حتى الآن. اضغط على "+ إضافة درس للوحدة".
            </div>
          `}
        </div>
      </div>
    `;
  });

  // Render unassigned lessons if any
  if (unassigned.length) {
    html += `
      <div class="curriculum-unit-card" style="margin-bottom:16px;border-style:dashed;">
        <div class="unit-head" style="background:rgba(245,158,11,0.08);">
          <div class="unit-title-box">
            <div class="unit-badge-num" style="background:#f59e0b;">*</div>
            <div>
              <h3 class="unit-title">دروس عامة (غير مصنفة ضمن وحدة)</h3>
              <div class="unit-desc">يمكنك تعديل أي درس وربطه بالوحدة المناسبة له.</div>
            </div>
          </div>
          <div class="unit-actions">
            <button type="button" class="btn btn-sm btn-outline" onclick="openNewLessonModal(${selectedLessonCourseId}, 0)">
              + إضافة درس عام
            </button>
          </div>
        </div>
        <div class="unit-lessons-list">
          ${unassigned.map((l, lIdx) => renderLessonRowHtml(l, lIdx + 1)).join('')}
        </div>
      </div>
    `;
  }

  container.innerHTML = html;
}

function renderLessonRowHtml(l, index) {
  const previewBadge = l.is_preview ? 
    '<span class="pill" style="background:rgba(16,185,129,0.12);color:#10b981;font-size:11px;">معاينة مجانية 🎁</span>' :
    '<span class="pill" style="font-size:11px;color:var(--slate-500);">مشتركين 🔒</span>';

  return `
    <div class="unit-lesson-row">
      <div class="lesson-info">
        <span class="lesson-index">#${index}</span>
        <div class="lesson-text">
          <div class="lesson-name">${esc(l.title)}</div>
          <div class="lesson-meta">
            <span>⏱️ ${l.duration_minutes || 0} دقيقة</span>
            ${previewBadge}
            ${l.video_url ? `<span style="color:var(--teal-600);font-weight:600;">🎬 مرفق فيديو</span>` : '<span style="color:var(--slate-400);">بدون فيديو</span>'}
          </div>
        </div>
      </div>
      <div class="lesson-row-actions">
        ${l.video_url ? `
          <button type="button" class="btn btn-sm btn-outline" onclick="openVideoPlayer('${esc(l.video_url)}', '${esc(l.title)}')">
            ▶️ معاينة
          </button>
        ` : ''}
        <button type="button" class="btn btn-sm btn-ghost" onclick="openEditLessonModal(${l.id})">
          ✏️ تعديل
        </button>
        <button type="button" class="btn btn-sm btn-ghost" onclick="confirmDeleteLesson(${l.id})" style="color:var(--danger)">
          ✕
        </button>
      </div>
    </div>
  `;
}

function renderLessonsTable(lessons) {
  const tbody = $('lessonsTableBody');
  if (!tbody) return;

  let totalMinutes = 0;
  lessons.forEach(l => { totalMinutes += Number(l.duration_minutes || 0); });

  if ($('lessonsTotalBadge')) $('lessonsTotalBadge').textContent = `${lessons.length} درس`;
  if ($('lessonsDurationBadge')) $('lessonsDurationBadge').textContent = `${totalMinutes} دقيقة إجمالي`;

  if (!lessons.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--slate-400);padding:30px;">لم تتم إضافة دروس في هذا الكورس بعد. اضغط على "+ إضافة درس جديد".</td></tr>';
    return;
  }

  tbody.innerHTML = lessons.map(l => {
    const previewBadge = l.is_preview ? 
      '<span class="pill" style="background:rgba(16,185,129,0.12);color:#10b981;border-color:rgba(16,185,129,0.3)">معاينة مجانية 🎁</span>' :
      '<span class="pill" style="color:var(--slate-500)">للمشتركين 🔒</span>';

    // Video provider badge
    let provBadge = '<span class="badge-source">فيديو</span>';
    const vUrl = String(l.video_url || '');
    if (vUrl.includes('youtube') || vUrl.includes('youtu.be')) provBadge = '<span class="badge-source" style="color:#ef4444">YouTube</span>';
    else if (vUrl.includes('drive.google')) provBadge = '<span class="badge-source" style="color:#0ea5e9">Google Drive</span>';
    else if (vUrl.includes('vimeo')) provBadge = '<span class="badge-source" style="color:#06b6d4">Vimeo</span>';
    else if (vUrl.endsWith('.mp4')) provBadge = '<span class="badge-source" style="color:#10b981">MP4 Direct</span>';

    return `
      <tr>
        <td style="font-weight:800;color:var(--slate-400);text-align:center;">#${l.sort_order || 1}</td>
        <td>
          <div style="font-weight:700;font-size:14px;color:var(--navy-900);">${esc(l.title)}</div>
          ${l.description ? `<div style="font-size:12px;color:var(--slate-500);margin-top:2px;">${esc(l.description)}</div>` : ''}
        </td>
        <td><strong>${l.duration_minutes || 0} دقيقة</strong></td>
        <td>${previewBadge}</td>
        <td>
          <div style="display:flex;align-items:center;gap:6px;">
            ${provBadge}
            ${vUrl ? `
              <button type="button" class="btn btn-sm btn-outline" onclick="playLessonVideo('${esc(vUrl)}', '${esc(l.title)}')" title="تشغيل ومعاينة الفيديو">
                ▶ تشغيل
              </button>
            ` : '<span style="color:var(--slate-400);font-size:12px;">لا يوجد رابط</span>'}
          </div>
        </td>
        <td style="text-align:center;">
          <button type="button" class="btn btn-sm btn-outline" onclick="openEditLessonModal(${l.id})" title="تعديل الدرس">
            تعديل ✏️
          </button>
          <button type="button" class="btn btn-sm btn-ghost" onclick="confirmDeleteLesson(${l.id})" style="color:var(--danger)" title="حذف الدرس">
            ✕
          </button>
        </td>
      </tr>
    `;
  }).join('');
}

window.playLessonVideo = function(url, title) {
  if (!url) return;
  const container = $('videoPlayerContainer');
  const modalTitle = $('videoPlayerTitle');
  if (modalTitle) modalTitle.textContent = `معاينة: ${title}`;

  let embedHtml = '';
  // YouTube conversion
  if (url.includes('youtube.com/watch?v=')) {
    const vidId = url.split('v=')[1]?.split('&')[0];
    embedHtml = `<iframe width="100%" height="450" src="https://www.youtube.com/embed/${vidId}?autoplay=1" frameborder="0" allowfullscreen allow="autoplay"></iframe>`;
  } else if (url.includes('youtu.be/')) {
    const vidId = url.split('youtu.be/')[1]?.split('?')[0];
    embedHtml = `<iframe width="100%" height="450" src="https://www.youtube.com/embed/${vidId}?autoplay=1" frameborder="0" allowfullscreen allow="autoplay"></iframe>`;
  } else if (url.includes('drive.google.com')) {
    const previewUrl = url.replace('/view', '/preview');
    embedHtml = `<iframe src="${previewUrl}" width="100%" height="450" frameborder="0" allow="autoplay"></iframe>`;
  } else {
    // Native HTML5 video or direct link
    embedHtml = `<video src="${esc(url)}" controls autoplay style="width:100%;max-height:450px;border-radius:8px;"></video>`;
  }

  if (container) container.innerHTML = embedHtml;
  openModal('videoPlayerModal');
};

window.openNewLessonModal = async function(courseId, targetSectionId = 0) {
  if (!cachedCourses.length) {
    await loadCourses();
  }
  if (!cachedCourses.length) {
    alert('يرجى إنشاء كورس أولاً لإضافة الدروس إليه.');
    return;
  }

  const cId = Number(courseId || selectedLessonCourseId || cachedCourses[0].id);
  selectedLessonCourseId = cId;

  // Populate course dropdown inside lesson modal
  const cSel = $('lessonModalCourseSelect');
  if (cSel) {
    cSel.innerHTML = cachedCourses.map(c => `<option value="${c.id}">${esc(c.title)}</option>`).join('');
    cSel.value = cId;
    cSel.onchange = () => updateLessonModalSections(Number(cSel.value));
  }

  // Populate section dropdown
  await updateLessonModalSections(cId, targetSectionId);

  $('lessonModalId').value = '0';
  $('lessonModalTitle').textContent = 'إضافة درس جديد للمنهج 🎬';
  $('lessonTitle').value = '';
  $('lessonVideoFile').value = '';
  $('lessonDuration').value = '25';
  $('lessonSortOrder').value = (cachedLessons.length + 1);
  $('lessonDesc').value = '';
  $('lessonIsPreview').checked = false;
  openModal('lessonModal');
};

async function updateLessonModalSections(courseId, selectedSecId = 0) {
  const sSel = $('lessonModalSectionSelect');
  if (!sSel) return;
  sSel.innerHTML = '<option value="">-- بدون وحدة (درس عام في الكورس) --</option>';

  try {
    const res = await api('sections', { course_id: courseId });
    const secs = res.sections || [];
    secs.forEach(s => {
      sSel.innerHTML += `<option value="${s.id}">${esc(s.title)}</option>`;
    });
    if (selectedSecId) sSel.value = selectedSecId;
  } catch (_) {}
}

window.openEditLessonModal = async function(lessonId) {
  const l = cachedLessons.find(item => item.id === lessonId);
  if (!l) return;

  const cId = Number(l.course_id || selectedLessonCourseId);
  const cSel = $('lessonModalCourseSelect');
  if (cSel) {
    cSel.innerHTML = cachedCourses.map(c => `<option value="${c.id}">${esc(c.title)}</option>`).join('');
    cSel.value = cId;
    cSel.onchange = () => updateLessonModalSections(Number(cSel.value));
  }

  await updateLessonModalSections(cId, Number(l.section_id || 0));

  $('lessonModalId').value = l.id;
  $('lessonModalTitle').textContent = `تعديل درس: ${l.title}`;
  $('lessonTitle').value = l.title;
  $('lessonVideoFile').value = '';
  $('lessonDuration').value = l.duration_minutes || 0;
  $('lessonSortOrder').value = l.sort_order || 1;
  $('lessonDesc').value = l.description || '';
  $('lessonIsPreview').checked = Boolean(Number(l.is_preview));
  openModal('lessonModal');
};

// Section Modal Logic
window.openNewSectionModal = function(courseId) {
  const cId = Number(courseId || selectedLessonCourseId || (cachedCourses[0] ? cachedCourses[0].id : 0));
  if (!cId) {
    alert('يرجى إنشاء كورس أولاً.');
    return;
  }
  const cSel = $('sectionCourseSelect');
  if (cSel) {
    cSel.innerHTML = cachedCourses.map(c => `<option value="${c.id}">${esc(c.title)}</option>`).join('');
    cSel.value = cId;
  }
  $('sectionModalId').value = '0';
  $('sectionModalTitle').textContent = 'إضافة وحدة منهجية جديدة 📚';
  $('sectionTitle').value = '';
  $('sectionSortOrder').value = (cachedSections.length + 1);
  $('sectionDesc').value = '';
  openModal('sectionModal');
};

window.openEditSectionModal = function(secId) {
  const sec = cachedSections.find(s => s.id === secId);
  if (!sec) return;
  const cSel = $('sectionCourseSelect');
  if (cSel) {
    cSel.innerHTML = cachedCourses.map(c => `<option value="${c.id}">${esc(c.title)}</option>`).join('');
    cSel.value = sec.course_id;
  }
  $('sectionModalId').value = sec.id;
  $('sectionModalTitle').textContent = `تعديل الوحدة: ${sec.title}`;
  $('sectionTitle').value = sec.title;
  $('sectionSortOrder').value = sec.sort_order || 1;
  $('sectionDesc').value = sec.description || '';
  openModal('sectionModal');
};

window.confirmDeleteSection = async function(secId) {
  if (!confirm('هل تريد بالتأكيد حذف هذه الوحدة الدراسية؟ (ستبقى الدروس محفوظة كدروس عامة غير مصنفة).')) return;
  try {
    await api('delete_section', { section_id: secId });
    notify('تم حذف الوحدة المنهجية بنجاح.');
    loadCourseLessonsAndCurriculum(selectedLessonCourseId);
  } catch (err) {
    alert(err.message);
  }
};

window.confirmDeleteLesson = async function(lessonId) {
  if (!confirm('هل أنت متأكد من حذف هذا الدرس؟')) return;
  try {
    await api('delete_lesson', { lesson_id: lessonId });
    notify('تم حذف الدرس بنجاح.');
    loadCourseLessons(selectedLessonCourseId);
  } catch (err) {
    alert(err.message);
  }
};

/* -------------------------------------------------------------------------
   10) Students Panel Logic
   ------------------------------------------------------------------------- */
async function loadStudents() {
  const res = await api('students');
  cachedStudents = {
    subscribers: res.subscribers || [],
    visitors: res.visitors || []
  };

  const list = (activeStudentSubTab === 'subscribers') ? cachedStudents.subscribers : cachedStudents.visitors;
  renderStudentsTable(list);
}

function renderStudentsTable(students) {
  const tbody = $('studentsTableBody');
  if (!tbody) return;

  if (!students.length) {
    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;color:var(--slate-400);padding:30px;">لا توجد سجلات مطابقة في هذا القسم.</td></tr>`;
    return;
  }

  tbody.innerHTML = students.map(s => {
    const cleanPhone = String(s.phone || '').replace(/\D/g, '');
    const waMsg = encodeURIComponent(`مرحباً دكتور/أستاذ ${s.full_name || ''}، معك د. ${user ? user.full_name : 'المحاضر'} بخصوص ${s.course || 'الكورس الطبي'}. يسعدني التواصل معك.`);
    const waLink = cleanPhone ? `https://wa.me/2${cleanPhone}?text=${waMsg}` : '';
    const pct = Number(s.progress_percent || 0);

    return `
      <tr>
        <td>
          <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--navy-900),var(--teal-700));color:#fff;display:grid;place-items:center;font-weight:800;font-size:14px;flex-shrink:0;">
              ${esc((s.full_name || 'ط').charAt(0))}
            </div>
            <div>
              <div style="font-weight:700;font-size:14px;">${esc(s.full_name || 'طالب')}</div>
              <div style="font-size:11px;color:var(--slate-400);">${esc(s.email || '')}</div>
            </div>
          </div>
        </td>
        <td>${esc(s.email || '-')}</td>
        <td><strong>${esc(s.course || '-')}</strong></td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:12px;font-weight:800;color:var(--teal-600);">${pct}%</span>
            <div class="progress-bar-wrap" style="width:90px;">
              <div class="progress-bar-fill" style="width:${pct}%;"></div>
            </div>
          </div>
          ${s.total_lessons ? `<div style="font-size:11px;color:var(--slate-400);margin-top:2px;">${s.completed_lessons || 0} من ${s.total_lessons} درس</div>` : ''}
        </td>
        <td><span class="pill">${esc(s.at || '-')}</span></td>
        <td dir="ltr" style="text-align:right;">${esc(s.phone || 'غير مسجل')}</td>
        <td style="text-align:center;">
          ${waLink ? `
            <a href="${waLink}" target="_blank" class="btn-whatsapp">
              <span>💬 تواصل واتساب</span>
            </a>
          ` : '<span style="color:var(--slate-400);font-size:12px;">بدون هاتف</span>'}
        </td>
      </tr>
    `;
  }).join('');
}

// Export CSV
function exportStudentsToCSV() {
  const list = (activeStudentSubTab === 'subscribers') ? cachedStudents.subscribers : cachedStudents.visitors;
  if (!list.length) {
    alert('لا توجد بيانات لتصديرها.');
    return;
  }

  let csv = '\uFEFF'; // UTF-8 BOM
  csv += 'اسم الطالب,البريد الإلكتروني,رقم الهاتف,الكورس,نسبة التقدم,تاريخ التفاعل\n';

  list.forEach(s => {
    csv += `"${(s.full_name || '').replace(/"/g, '""')}","${s.email || ''}","${s.phone || ''}","${(s.course || '').replace(/"/g, '""')}","${s.progress_percent || 0}%","${s.at || ''}"\n`;
  });

  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `EduNursing_Students_${activeStudentSubTab}_${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

/* -------------------------------------------------------------------------
   11) Reviews Panel Logic
   ------------------------------------------------------------------------- */
async function loadReviews() {
  const cSelect = $('reviewsCourseSelect');
  const courseId = cSelect ? Number(cSelect.value || 0) : 0;

  const res = await api('reviews', { course_id: courseId });
  cachedReviews = res.reviews || [];
  renderReviewsTable(cachedReviews);
}

function renderReviewsTable(reviews) {
  const tbody = $('reviewsTableBody');
  if (!tbody) return;

  if (reviews.length) {
    let sum = 0;
    reviews.forEach(r => sum += Number(r.rating || 5));
    const avg = (sum / reviews.length).toFixed(1);
    if ($('reviewsAverageBadge')) $('reviewsAverageBadge').textContent = `⭐ متوسط التقييم: ${avg} (${reviews.length} مراجعة)`;
  }

  if (!reviews.length) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--slate-400);padding:30px;">لا توجد مراجعات مسجلة في هذا الكورس بعد.</td></tr>';
    return;
  }

  tbody.innerHTML = reviews.map(r => `
    <tr>
      <td>
        <div style="font-weight:700;">${esc(r.student_name || 'طالب')}</div>
        <div style="font-size:11px;color:var(--slate-400);">${esc(r.student_email || '')}</div>
      </td>
      <td><strong>${esc(r.course_title || '')}</strong></td>
      <td><span class="star-rating">${'★'.repeat(r.rating || 5)}${'☆'.repeat(5 - (r.rating || 5))}</span></td>
      <td style="font-size:13px;line-height:1.6;color:var(--slate-700);">${esc(r.comment || 'تقييم ممتاز.')}</td>
      <td><span class="pill">${esc(r.created_at || '')}</span></td>
    </tr>
  `).join('');
}

/* -------------------------------------------------------------------------
   12) Live Meetings Panel Logic
   ------------------------------------------------------------------------- */
async function loadMeetings() {
  const res = await api('meetings');
  cachedMeetings = res.meetings || [];
  renderTimetableCards(cachedMeetings);
  renderMeetingsTable(cachedMeetings);
}

function renderMeetingsTable(meetings) {
  const tbody = $('meetingsTableBody');
  if (!tbody) return;

  if (!meetings.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--slate-400);padding:30px;">لا توجد محاضرات في الجدول حالياً. اضغط على "+ إضافة موعد في الجدول".</td></tr>';
    return;
  }

  tbody.innerHTML = meetings.map(m => {
    return `
      <tr>
        <td><strong>${esc(m.title)}</strong></td>
        <td><span class="pill" style="font-weight:700;">${esc(m.course_title)}</span></td>
        <td>${esc(formatDateTimeArabic(m.scheduled_at || m.starts_at))}</td>
        <td>${esc(m.duration_minutes || 60)} دقيقة</td>
        <td><span class="pill" style="color:var(--teal-600);font-weight:700;">${esc(m.status || 'مجدولة')}</span></td>
        <td>
          <a href="${esc(m.meeting_url)}" target="_blank" class="btn btn-sm btn-primary">
            انضمام Zoom 🚀
          </a>
        </td>
        <td style="text-align:center;">
          <button type="button" class="btn btn-sm btn-outline" onclick="copyMeetingLink('${esc(m.meeting_url)}')">📋 نسخ الرابط</button>
          <button type="button" class="btn btn-sm btn-ghost" onclick="confirmDeleteMeeting(${m.id})" style="color:var(--danger)">
            ✕ حذف
          </button>
        </td>
      </tr>
    `;
  }).join('');
}

function renderTimetableCards(meetings) {
  const grid = $('meetingsCardsGrid');
  if (!grid) return;

  if (!meetings.length) {
    grid.innerHTML = `
      <div style="background:var(--slate-50);border:2px dashed var(--line);border-radius:18px;padding:48px 24px;text-align:center;grid-column:1/-1;">
        <div style="font-size:36px;margin-bottom:12px;">📅</div>
        <h3 style="margin:0 0 8px;font-size:18px;">جدول المحاضرات والتدريب السريري فارغ</h3>
        <p style="color:var(--slate-500);font-size:13.5px;max-width:440px;margin:0 auto 20px;">
          قم بإضافة أول موعد محاضرة أو ورشة سريرية مباشرة على Zoom/Meet وسيتم إشعار الطلاب المشتركين فوراً.
        </p>
        <button type="button" class="btn btn-primary" onclick="openNewMeetingModal()">+ إضافة موعد في الجدول</button>
      </div>
    `;
    return;
  }

  grid.innerHTML = meetings.map(m => {
    const timeStr = formatDateTimeArabic(m.scheduled_at || m.starts_at);
    return `
      <div class="timetable-card">
        <div class="timetable-head">
          <span class="timetable-course">${esc(m.course_title)}</span>
          <span class="pill" style="background:rgba(13,148,136,0.12);color:var(--teal-700);font-weight:700;">${esc(m.status || 'مجدولة')}</span>
        </div>
        <h3 class="timetable-title">${esc(m.title)}</h3>
        ${m.description ? `<p style="font-size:12.5px;color:var(--slate-500);margin:0;line-height:1.5;">${esc(m.description)}</p>` : ''}

        <div class="timetable-time-box">
          <span class="time-icon">⏰</span>
          <div class="time-details">
            <div><strong>${timeStr}</strong></div>
            <div style="font-size:11.5px;color:var(--slate-500);">المدة: ${m.duration_minutes || 60} دقيقة  —  بث مباشر</div>
          </div>
        </div>

        <div class="timetable-actions">
          <a href="${esc(m.meeting_url)}" target="_blank" class="btn btn-primary">
            انضمام Zoom 🚀
          </a>
          <button type="button" class="btn btn-outline" onclick="copyMeetingLink('${esc(m.meeting_url)}')">
            📋 نسخ الرابط
          </button>
          <button type="button" class="btn btn-ghost" onclick="confirmDeleteMeeting(${m.id})" style="color:var(--danger)">
            ✕ إلغاء
          </button>
        </div>
      </div>
    `;
  }).join('');
}

function formatDateTimeArabic(dateStr) {
  if (!dateStr) return '-';
  try {
    const d = new Date(dateStr);
    const days = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
    const day = days[d.getDay()] || '';
    const dateFormatted = d.toLocaleDateString(currentLocale(), { year: 'numeric', month: 'short', day: 'numeric' });
    const timeFormatted = d.toLocaleTimeString(currentLocale(), { hour: '2-digit', minute: '2-digit' });
    return `${day} ${dateFormatted}  —  ${timeFormatted}`;
  } catch (_) {
    return dateStr;
  }
}

window.copyMeetingLink = function(url) {
  if (!url) return;
  navigator.clipboard.writeText(url).then(() => {
    notify('تم نسخ رابط المحاضرة بنجاح.');
  }).catch(() => {
    prompt('انسخ الرابط التالي:', url);
  });
};

window.openNewMeetingModal = function() {
  if (!cachedCourses.length) {
    alert('يجب إنشاء كورس واحد على الأقل قبل جدولة محاضرة.');
    return;
  }
  populateCourseDropdowns(cachedCourses);
  $('meetingTitle').value = '';
  $('meetingUrl').value = '';
  $('meetingDesc').value = '';
  // Default starts_at to tomorrow 8 PM
  const tmrw = new Date();
  tmrw.setDate(tmrw.getDate() + 1);
  tmrw.setHours(20, 0, 0, 0);
  const pad = n => String(n).padStart(2, '0');
  $('meetingStartsAt').value = `${tmrw.getFullYear()}-${pad(tmrw.getMonth()+1)}-${pad(tmrw.getDate())}T${pad(tmrw.getHours())}:${pad(tmrw.getMinutes())}`;
  openModal('meetingModal');
};

window.confirmDeleteMeeting = async function(meetingId) {
  if (!confirm('هل تريد إلغاء وحذف هذه المحاضرة المباشرة؟')) return;
  try {
    await api('delete_meeting', { meeting_id: meetingId });
    notify('تم حذف المحاضرة المباشرة بنجاح.');
    loadMeetings();
  } catch (err) {
    alert(err.message);
  }
};

/* -------------------------------------------------------------------------
   13) Announcements Panel Logic
   ------------------------------------------------------------------------- */
async function loadAnnouncements() {
  const res = await api('announcements');
  const tbody = $('announcementsTableBody');
  if (!tbody) return;

  const announcements = res.announcements || [];
  if (!announcements.length) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--slate-400);padding:30px;">لا توجد إعلانات منشورة بعد. اضغط على "+ إرسال تنبيه جديد".</td></tr>';
    return;
  }

  tbody.innerHTML = announcements.map(a => `
    <tr>
      <td><strong>${esc(a.title)}</strong></td>
      <td><span class="pill">${esc(a.course_title || 'كافة الطلاب')}</span></td>
      <td style="font-size:13px;color:var(--slate-700);">${esc(a.body)}</td>
      <td><span class="pill">${esc(a.created_at)}</span></td>
      <td style="text-align:center;">
        <button type="button" class="btn btn-sm btn-ghost" onclick="confirmDeleteAnnouncement(${a.id})" style="color:var(--danger)">
          ✕ حذف
        </button>
      </td>
    </tr>
  `).join('');
}

window.openNewAnnouncementModal = async function() {
  if ($('announcementTitle')) $('announcementTitle').value = '';
  if ($('announcementBody')) $('announcementBody').value = '';
  if (!cachedCourses || !cachedCourses.length) {
    try {
      const res = await api('courses');
      cachedCourses = res.courses || [];
    } catch (e) {
      console.warn('Could not load courses for announcements', e);
    }
  }
  populateCourseDropdowns(cachedCourses);
  openModal('announcementModal');
};

window.confirmDeleteAnnouncement = async function(annId) {
  if (!confirm('هل أنت متأكد من حذف هذا الإعلان؟')) return;
  try {
    await api('delete_announcement', { announcement_id: annId });
    notify('تم حذف الإعلان بنجاح.');
    loadAnnouncements();
  } catch (err) {
    alert(err.message);
  }
};

/* -------------------------------------------------------------------------
   14) Profile & Avatar Logic
   ------------------------------------------------------------------------- */
function loadProfileData() {
  if (!user) return;
  if ($('profFullName')) $('profFullName').value = user.full_name || '';
  if ($('profEmail')) $('profEmail').value = user.email || '';
  if ($('profPhone')) $('profPhone').value = user.phone || '';
  if ($('profSpecialization')) $('profSpecialization').value = user.specialization || '';
  if ($('profExperienceYears')) $('profExperienceYears').value = user.experience_years || 0;
  if ($('profWorkplace')) $('profWorkplace').value = user.workplace || '';
  if ($('profExperience')) $('profExperience').value = user.experience || '';
  if ($('profBio')) $('profBio').value = user.bio || '';

  const avatarBox = $('avatarBigPreview');
  if (avatarBox) {
    if (user.avatar) {
      avatarBox.innerHTML = `<img src="${esc(user.avatar)}" style="width:100%;height:100%;object-fit:cover;">`;
    } else {
      avatarBox.textContent = (user.full_name || 'د').charAt(0);
    }
  }
}

/* -------------------------------------------------------------------------
   15) Form Submissions & Initialization
   ------------------------------------------------------------------------- */
function initForms() {
  // Course Form
  const courseForm = $('courseForm');
  if (courseForm) {
    courseForm.onsubmit = async e => {
      e.preventDefault();
      const courseId = Number($('courseModalId').value || 0);
      const payload = {
        course_id: courseId,
        title: $('courseTitleAr').value,
        title_ar: $('courseTitleAr').value,
        title_en: $('courseTitleEn').value,
        description: $('courseDesc').value,
        price: $('coursePrice').value,
        discounted_price: $('courseDiscountPrice').value,
        duration_minutes: $('courseDuration').value,
        category: $('courseCategory').value,
        level: $('courseLevel').value,
        status: $('courseStatus').value,
        thumbnail: ''
      };

      try {
        const res = await api('save_course', payload);
        const thumbnailFile = $('courseThumbnail').files?.[0];
        if (thumbnailFile) {
          const data = new FormData(); data.append('course_id', String(res.course_id)); data.append('file', thumbnailFile);
          await requestJSON(`${ROOT}api/instructor/api.php?a=course_thumbnail_upload`, {method:'POST', body:data});
        }
        notify(res.message || 'تم حفظ الكورس بنجاح.');
        closeModal('courseModal');
        await loadCourses();
      } catch (err) {
        alert(err.message);
      }
    };
  }

  // Lesson Form
  const lessonForm = $('lessonForm');
  if (lessonForm) {
    lessonForm.onsubmit = async e => {
      e.preventDefault();
      const lessonId = Number($('lessonModalId').value || 0);
      const cId = Number($('lessonModalCourseSelect')?.value || selectedLessonCourseId);
      const sId = Number($('lessonModalSectionSelect')?.value || 0) || null;
      selectedLessonCourseId = cId;

      const payload = {
        course_id: cId,
        section_id: sId,
        lesson_id: lessonId,
        title: $('lessonTitle').value,
        video_url: '',
        duration_minutes: $('lessonDuration').value,
        sort_order: $('lessonSortOrder').value,
        description: $('lessonDesc').value,
        is_preview: $('lessonIsPreview').checked ? 1 : 0
      };

      try {
        const res = await api('save_lesson', payload);
        const videoFile = $('lessonVideoFile').files?.[0];
        if (videoFile) {
          const data = new FormData(); data.append('course_id', String(cId)); data.append('lesson_id', String(res.lesson_id)); data.append('file', videoFile);
          await requestJSON(`${ROOT}api/instructor/api.php?a=lesson_video_upload`, {method:'POST', body:data});
        }
        notify(res.message || 'تم حفظ الدرس بنجاح.');
        closeModal('lessonModal');
        if ($('lessonCourseSelect')) $('lessonCourseSelect').value = cId;
        loadCourseLessonsAndCurriculum(cId);
      } catch (err) {
        alert(err.message);
      }
    };
  }

  // Section / Curriculum Unit Form
  const sectionForm = $('sectionForm');
  if (sectionForm) {
    sectionForm.onsubmit = async e => {
      e.preventDefault();
      const sId = Number($('sectionModalId').value || 0);
      const cId = Number($('sectionCourseSelect').value || selectedLessonCourseId);
      const payload = {
        course_id: cId,
        section_id: sId,
        title: $('sectionTitle').value,
        description: $('sectionDesc').value,
        sort_order: Number($('sectionSortOrder').value || 1)
      };

      try {
        const res = await api('save_section', payload);
        notify(res.message || 'تم حفظ الوحدة المنهجية بنجاح.');
        closeModal('sectionModal');
        selectedLessonCourseId = cId;
        if ($('lessonCourseSelect')) $('lessonCourseSelect').value = cId;
        loadCourseLessonsAndCurriculum(cId);
      } catch (err) {
        alert(err.message);
      }
    };
  }

  // View switches in Lessons Panel (Curriculum vs Flat Table)
  const btnCurriculum = $('insViewCurriculumBtn');
  const btnLessonsTable = $('insViewLessonsTableBtn');
  if (btnCurriculum && btnLessonsTable) {
    btnCurriculum.onclick = () => {
      lessonsCurrentView = 'curriculum';
      if ($('curriculumViewWrap')) $('curriculumViewWrap').hidden = false;
      if ($('lessonsTableViewWrap')) $('lessonsTableViewWrap').hidden = true;
      btnCurriculum.classList.add('active');
      btnLessonsTable.classList.remove('active');
    };
    btnLessonsTable.onclick = () => {
      lessonsCurrentView = 'table';
      if ($('curriculumViewWrap')) $('curriculumViewWrap').hidden = true;
      if ($('lessonsTableViewWrap')) $('lessonsTableViewWrap').hidden = false;
      btnLessonsTable.classList.add('active');
      btnCurriculum.classList.remove('active');
    };
  }

  // View switches in Meetings / Schedule Panel (Agenda Cards vs Timetable)
  const btnTimetableCards = $('insViewTimetableCardsBtn');
  const btnTimetableTable = $('insViewTimetableTableBtn');
  if (btnTimetableCards && btnTimetableTable) {
    btnTimetableCards.onclick = () => {
      meetingsCurrentView = 'cards';
      if ($('meetingsCardsGrid')) $('meetingsCardsGrid').hidden = false;
      if ($('meetingsTableWrap')) $('meetingsTableWrap').hidden = true;
      btnTimetableCards.classList.add('active');
      btnTimetableTable.classList.remove('active');
    };
    btnTimetableTable.onclick = () => {
      meetingsCurrentView = 'table';
      if ($('meetingsCardsGrid')) $('meetingsCardsGrid').hidden = true;
      if ($('meetingsTableWrap')) $('meetingsTableWrap').hidden = false;
      btnTimetableTable.classList.add('active');
      btnTimetableCards.classList.remove('active');
    };
  }

  // Button to open section modal
  if ($('btnOpenAddSectionModal')) {
    $('btnOpenAddSectionModal').onclick = () => {
      openNewSectionModal(selectedLessonCourseId);
    };
  }

  // Meeting Form
  const meetingForm = $('meetingForm');
  if (meetingForm) {
    meetingForm.onsubmit = async e => {
      e.preventDefault();
      const payload = {
        course_id: Number($('meetingCourseSelect').value),
        title: $('meetingTitle').value,
        starts_at: $('meetingStartsAt').value,
        duration_minutes: Number($('meetingDuration').value || 60),
        meeting_url: $('meetingUrl').value,
        description: $('meetingDesc').value
      };

      try {
        const res = await api('save_meeting', payload);
        notify(res.message || 'تمت جدولة المحاضرة وإشعار الطلاب بنجاح.');
        closeModal('meetingModal');
        loadMeetings();
      } catch (err) {
        alert(err.message);
      }
    };
  }

  // Announcement Form
  const announcementForm = $('announcementForm');
  if (announcementForm) {
    announcementForm.onsubmit = async e => {
      e.preventDefault();
      const submitBtn = announcementForm.querySelector('button[type="submit"]');
      const origText = submitBtn ? submitBtn.innerHTML : '';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = 'جاري النشر والإشعار...';
      }

      const payload = {
        course_id: $('announcementCourseSelect').value || null,
        title: $('announcementTitle').value.trim(),
        body: $('announcementBody').value.trim()
      };

      try {
        const res = await api('save_announcement', payload);
        notify(res.message || 'تم نشر التنبيه وإرسال الإشعار للطلاب بنجاح.');
        closeModal('announcementModal');
        if ($('announcementTitle')) $('announcementTitle').value = '';
        if ($('announcementBody')) $('announcementBody').value = '';
        loadAnnouncements();
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

  // Profile Form
  const profileForm = $('profileForm');
  if (profileForm) {
    profileForm.onsubmit = async e => {
      e.preventDefault();
      const payload = {
        full_name: $('profFullName').value,
        phone: $('profPhone').value,
        specialization: $('profSpecialization').value,
        experience_years: $('profExperienceYears').value,
        workplace: $('profWorkplace').value,
        experience: $('profExperience').value,
        bio: $('profBio').value
      };

      try {
        const res = await api('update_profile', payload);
        notify(res.message || 'تم تحديث البيانات بنجاح.');
        user.full_name = payload.full_name;
        user.phone = payload.phone;
        user.specialization = payload.specialization;
        user.experience_years = payload.experience_years;
        user.workplace = payload.workplace;
        user.experience = payload.experience;
        user.bio = payload.bio;
        if ($('who')) $('who').textContent = `د. ${payload.full_name}`;
      } catch (err) {
        alert(err.message);
      }
    };
  }

  // Password Form
  const passwordForm = $('passwordForm');
  if (passwordForm) {
    passwordForm.onsubmit = async e => {
      e.preventDefault();
      const oldP = $('oldPassword').value;
      const newP = $('newPassword').value;
      const confP = $('confirmPassword').value;

      if (newP !== confP) {
        alert('كلمتا المرور الجديدتان غير متطابقتين.');
        return;
      }

      try {
        const res = await api('change_password', {
          old_password: oldP,
          new_password: newP
        });
        notify(res.message || 'تم تحديث كلمة المرور بنجاح.');
        passwordForm.reset();
      } catch (err) {
        alert(err.message);
      }
    };
  }

  // Avatar Upload
  const btnUploadAvatar = $('btnUploadAvatar');
  const avatarInput = $('avatarFileInput');
  if (btnUploadAvatar && avatarInput) {
    btnUploadAvatar.onclick = async () => {
      if (!avatarInput.files || !avatarInput.files[0]) {
        alert('اختر صورة أولاً للرفع.');
        return;
      }
      const formData = new FormData();
      formData.append('avatar', avatarInput.files[0]);

      try {
        const res = await fetch(`${ROOT}api/profile/avatar.php`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {},
          body: formData
        });
        const d = await res.json();
        if (!d.success) throw new Error(d.message || 'فشل رفع الصورة.');

        notify('تم تحديث الصورة الشخصية بنجاح.');
        user.avatar = d.avatar;
        loadProfileData();
        const topAv = $('topAvatar');
        if (topAv && d.avatar) {
          topAv.innerHTML = `<img src="${esc(d.avatar)}" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">`;
        }
      } catch (err) {
        alert(err.message);
      }
    };
  }

  // Course selector change in lessons panel
  const lCourseSelect = $('lessonCourseSelect');
  if (lCourseSelect) {
    lCourseSelect.onchange = () => {
      loadCourseLessons(Number(lCourseSelect.value));
    };
  }

  // Reviews course select change
  const rCourseSelect = $('reviewsCourseSelect');
  if (rCourseSelect) {
    rCourseSelect.onchange = () => {
      loadReviews();
    };
  }

  // Students sub-tabs
  const btnSubscribers = $('tabSubscribersBtn');
  const btnLeads = $('tabLeadsBtn');
  if (btnSubscribers && btnLeads) {
    btnSubscribers.onclick = () => {
      activeStudentSubTab = 'subscribers';
      btnSubscribers.className = 'btn btn-sm btn-primary';
      btnLeads.className = 'btn btn-sm btn-outline';
      renderStudentsTable(cachedStudents.subscribers);
    };

    btnLeads.onclick = () => {
      activeStudentSubTab = 'visitors';
      btnLeads.className = 'btn btn-sm btn-primary';
      btnSubscribers.className = 'btn btn-sm btn-outline';
      renderStudentsTable(cachedStudents.visitors);
    };
  }

  // Export CSV button
  const btnExportCsv = $('exportStudentsCsvBtn');
  if (btnExportCsv) {
    btnExportCsv.onclick = exportStudentsToCSV;
  }

  // Student course filter
  const stCourseFilter = $('studentCourseFilter');
  if (stCourseFilter) {
    stCourseFilter.onchange = () => {
      const cTitle = stCourseFilter.options[stCourseFilter.selectedIndex].text;
      const list = (activeStudentSubTab === 'subscribers') ? cachedStudents.subscribers : cachedStudents.visitors;
      if (!stCourseFilter.value) {
        renderStudentsTable(list);
      } else {
        renderStudentsTable(list.filter(s => s.course_id == stCourseFilter.value || (s.course && s.course.includes(cTitle))));
      }
    };
  }

  // Student filter input
  const stFilterInput = $('studentsFilterInput');
  if (stFilterInput) {
    stFilterInput.oninput = e => {
      const q = e.target.value.trim().toLowerCase();
      const list = (activeStudentSubTab === 'subscribers') ? cachedStudents.subscribers : cachedStudents.visitors;
      if (!q) {
        renderStudentsTable(list);
      } else {
        renderStudentsTable(list.filter(s => 
          (s.full_name && s.full_name.toLowerCase().includes(q)) || 
          (s.phone && s.phone.includes(q)) || 
          (s.course && s.course.toLowerCase().includes(q)) ||
          (s.email && s.email.toLowerCase().includes(q))
        ));
      }
    };
  }

  // Lesson search
  const lSearch = $('lessonSearch');
  if (lSearch) {
    lSearch.oninput = e => {
      const q = e.target.value.trim().toLowerCase();
      if (!q) {
        renderLessonsTable(cachedLessons);
      } else {
        renderLessonsTable(cachedLessons.filter(l => 
          l.title.toLowerCase().includes(q) || 
          (l.description && l.description.toLowerCase().includes(q))
        ));
      }
    };
  }

  // Course filter button
  const btnSearchCourses = $('searchCoursesBtn');
  if (btnSearchCourses) {
    btnSearchCourses.onclick = () => {
      const q = ($('courseSearch')?.value || '').trim().toLowerCase();
      const status = $('courseStatusFilter')?.value || '';
      const level = $('courseLevelFilter')?.value || '';

      let filtered = cachedCourses;
      if (q) filtered = filtered.filter(c => c.title.toLowerCase().includes(q) || (c.category && c.category.toLowerCase().includes(q)));
      if (status) filtered = filtered.filter(c => c.status === status);
      if (level) filtered = filtered.filter(c => c.level === level);
      renderCoursesGrid(filtered);
    };
  }

  // Overview Filters Apply & Reset
  const btnApplyFilters = $('applyFilters');
  const btnResetFilters = $('resetFilters');
  if (btnApplyFilters) {
    btnApplyFilters.onclick = () => {
      const q = ($('fSearch')?.value || '').trim().toLowerCase();
      const cId = $('fCourse')?.value || '';
      if (q) handleGlobalSearch(q);
      notify('تم تطبيق الفلتر على المؤشرات.');
    };
  }
  if (btnResetFilters) {
    btnResetFilters.onclick = () => {
      if ($('fFrom')) $('fFrom').value = '';
      if ($('fTo')) $('fTo').value = '';
      if ($('fCourse')) $('fCourse').value = '';
      if ($('fSearch')) $('fSearch').value = '';
      loadOverview();
      notify('تمت إعادة ضبط الفلاتر.');
    };
  }

  // Login Form Submission
  const loginForm = $('loginForm');
  const loginMsg = $('loginMessage');
  const loginBtn = $('loginSubmitBtn');
  if (loginForm) {
    loginForm.onsubmit = async e => {
      e.preventDefault();
      if (loginMsg) { loginMsg.hidden = true; loginMsg.textContent = ''; }
      if (loginBtn) { loginBtn.disabled = true; loginBtn.textContent = 'جارٍ تسجيل الدخول...'; }

      const email = $('loginEmail')?.value.trim();
      const password = $('loginPassword')?.value;

      try {
        const res = await requestJSON(`${ROOT}api/auth/instructor-login.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email, password })
        });

        if (res.success && res.user) {
          user = res.user;
          if (res.csrf_token) csrfToken = res.csrf_token;
          setupUserSession();
          showAppView();
          await loadTabData('overview');
          notify(`مرحباً بعودتك يا دكتور ${user.full_name || ''} 🩺`);
        } else {
          throw new Error(res.message || 'فشل تسجيل الدخول.');
        }
      } catch (err) {
        if (loginMsg) {
          loginMsg.hidden = false;
          loginMsg.className = 'notice notice-error';
          loginMsg.textContent = err.message;
        }
      } finally {
        if (loginBtn) { loginBtn.disabled = false; loginBtn.textContent = 'تسجيل الدخول الآمن ←'; }
      }
    };
  }

  // Logout Button
  const btnLogout = $('logoutBtn');
  if (btnLogout) {
    btnLogout.onclick = async () => {
      if (!confirm('هل تريد تسجيل الخروج؟')) return;
      try {
        await fetch(`${ROOT}api/instructor/logout.php`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}) }
        });
      } catch (_) {}
      window.location.replace(`${ROOT}auth/login.php`);
    };
  }
}

function setupUserSession() {
  if (!user) return;
  if ($('who')) $('who').textContent = `د. ${user.full_name || user.name || 'المحاضر المعتمد'}`;
  if ($('welcomeTitle')) $('welcomeTitle').textContent = `أهلاً بك يا دكتور ${user.full_name || ''} 🩺`;

  const topAv = $('topAvatar');
  if (topAv) {
    if (user.avatar) {
      topAv.innerHTML = `<img src="${esc(user.avatar)}" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">`;
    } else {
      topAv.textContent = (user.full_name || 'د').charAt(0);
    }
  }
}

/* -------------------------------------------------------------------------
   15.5) Quizzes & Exams Management Logic
   ------------------------------------------------------------------------- */
let cachedQuizzes = [];

async function loadQuizzes() {
  const cSel = $('quizzesCourseFilter');
  const courseId = cSel ? cSel.value : '';

  if (cSel && cachedCourses.length && cSel.options.length <= 1) {
    cSel.innerHTML = '<option value="">جميع الكورسات التدريبية</option>' +
      cachedCourses.map(c => `<option value="${c.id}">${esc(c.title)}</option>`).join('');
  }

  const tbody = $('quizzesTableBody');
  if (tbody) {
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--slate-400)">⏳ جاري تحميل بيانات الاختبارات...</td></tr>';
  }

  try {
    const res = await api('quizzes', { course_id: courseId });
    cachedQuizzes = res.quizzes || [];
    renderQuizzes(cachedQuizzes);
  } catch (err) {
    if (tbody) {
      tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;color:var(--danger)">${esc(err.message)}</td></tr>`;
    }
  }
}

function renderQuizzes(quizzes) {
  const tbody = $('quizzesTableBody');
  if (!tbody) return;

  if (!quizzes.length) {
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--slate-400);padding:30px;">لا توجد اختبارات منشأة بعد. اضغط على "+ إنشاء اختبار جديد" لإضافة أول اختبار لطلابك.</td></tr>';
    return;
  }

  tbody.innerHTML = quizzes.map(q => {
    const isPub = q.status === 'published';
    const avgScore = q.avg_score !== null ? `${q.avg_score}%` : '—';
    return `
      <tr>
        <td><strong>${esc(q.title)}</strong></td>
        <td><span style="font-size:12.5px;color:var(--teal,#0d9488);font-weight:700;">${esc(q.course_title)}</span></td>
        <td>${q.time_limit_minutes > 0 ? `${q.time_limit_minutes} دقيقة` : 'بدون توقيت'}</td>
        <td><span class="badge" style="background:rgba(13,148,136,0.1);color:var(--teal);">${q.pass_percent}%</span></td>
        <td><strong>${q.questions_count || 0}</strong> سؤال</td>
        <td><span class="pill" style="cursor:pointer;" onclick="openQuizAttempts(${q.id})">👥 ${q.attempts_count || 0} محاولة</span></td>
        <td><strong>${avgScore}</strong></td>
        <td><span class="badge badge-${isPub ? 'success' : 'muted'}">${isPub ? 'منشور' : 'مسودة'}</span></td>
        <td>
          <div style="display:flex;gap:6px;align-items:center;">
            <button type="button" class="btn btn-sm btn-outline" onclick="openQuizAttempts(${q.id})" title="عرض نتائج الطلاب">📊 النتائج</button>
            <button type="button" class="btn btn-sm btn-outline" onclick="openEditQuizModal(${q.id})" title="تعديل الاختبار">✎</button>
            <button type="button" class="btn btn-sm btn-danger" onclick="confirmDeleteQuiz(${q.id})" title="حذف">🗑</button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

window.openNewQuizModal = function() {
  const cSel = $('quizModalCourse');
  if (cSel) {
    cSel.innerHTML = '<option value="">اختر الكورس...</option>' +
      cachedCourses.map(c => `<option value="${c.id}">${esc(c.title)}</option>`).join('');
  }
  $('quizModalId').value = '';
  $('quizModalTitle').textContent = 'إنشاء اختبار جديد 📝';
  $('quizModalName').value = '';
  $('quizModalDuration').value = '30';
  $('quizModalPassPercent').value = '60';
  $('quizModalMaxAttempts').value = '3';
  $('quizModalStatus').value = 'draft';
  $('quizModalDescription').value = '';
  $('quizModalStartsAt').value = '';
  $('quizModalEndsAt').value = '';
  $('quizModalRandomCount').value = '0';
  $('quizModalNegativeMarking').value = '0';
  $('quizModalResultRelease').value = 'immediate';
  $('quizModalResultsAt').value = '';
  $('quizModalShuffleQuestions').checked = false;
  $('quizModalShuffleOptions').checked = false;
  $('quizModalAutoSubmit').checked = true;
  $('quizModalSequential').checked = false;
  $('quizModalShowAnswers').checked = false;
  window.AssessmentInstructor?.loadQuestionBank().then(() => window.AssessmentInstructor?.populateQuizQuestionSelection()).catch(() => {});
  openModal('quizModal');
};

window.openEditQuizModal = function(quizId) {
  const q = cachedQuizzes.find(item => Number(item.id) === Number(quizId));
  if (!q) return;

  const cSel = $('quizModalCourse');
  if (cSel) {
    cSel.innerHTML = '<option value="">اختر الكورس...</option>' +
      cachedCourses.map(c => `<option value="${c.id}">${esc(c.title)}</option>`).join('');
    cSel.value = q.course_id;
  }
  $('quizModalId').value = q.id;
  $('quizModalTitle').textContent = `تعديل اختبار: ${q.title}`;
  $('quizModalName').value = q.title;
  $('quizModalDuration').value = q.time_limit_minutes || 30;
  $('quizModalPassPercent').value = q.pass_percent || 60;
  $('quizModalMaxAttempts').value = q.max_attempts || 3;
  $('quizModalStatus').value = q.status || 'published';
  $('quizModalDescription').value = q.description || '';
  window.AssessmentInstructor?.loadQuestionBank().then(() => window.AssessmentInstructor?.loadExam(q.id)).catch(error => notify(error.message, 'error'));
  openModal('quizModal');
};

window.closeQuizModal = function() {
  closeModal('quizModal');
};

window.confirmDeleteQuiz = async function(quizId) {
  if (!confirm('هل تريد أرشفة هذا الاختبار؟ ستظل محاولات الطلاب ونتائجهم محفوظة.')) return;
  try {
    if (window.AssessmentInstructor) {
      await requestJSON('api/instructor/assessments.php?action=exam_archive', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({id:Number(quizId)}) });
    } else {
      await api('delete_quiz', { quiz_id: quizId });
    }
    notify('تمت أرشفة الاختبار وحفظ نتائجه.');
    await loadQuizzes();
  } catch (err) {
    notify(err.message, 'error');
  }
};

window.openQuizAttempts = async function(quizId) {
  openModal('quizAttemptsModal');
  const tbody = $('quizAttemptsTableBody');
  if (window.AssessmentInstructor?.loadAttempts) {
    if (tbody) tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--slate-400)">⏳ جاري تحميل نتائج الطلاب...</td></tr>';
    try { await window.AssessmentInstructor.loadAttempts(quizId); }
    catch (err) { if (tbody) tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--danger)">${esc(err.message)}</td></tr>`; }
    return;
  }
  if (tbody) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--slate-400)">⏳ جاري تحميل نتائج الطلاب...</td></tr>';
  }
  try {
    const res = await api('quiz_attempts', { quiz_id: quizId });
    const attempts = res.attempts || [];
    if (!attempts.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--slate-400);padding:24px;">لم يقم أي طالب بإجراء هذا الاختبار بعد.</td></tr>';
      return;
    }
    tbody.innerHTML = attempts.map(a => {
      const isPass = Boolean(Number(a.passed));
      return `
        <tr>
          <td>
            <strong>${esc(a.student_name)}</strong>
            <br><small style="color:var(--slate-500);">${esc(a.student_email)}</small>
            ${a.student_phone ? `<br><a href="https://wa.me/${a.student_phone.replace(/\D/g, '')}" target="_blank" class="small-btn btn-whatsapp" style="font-size:11px;padding:2px 6px;margin-top:3px;display:inline-flex;">💬 واتساب</a>` : ''}
          </td>
          <td>محاولة #${a.attempt_number || 1}</td>
          <td><strong>${Number(a.score || 0).toFixed(1)}</strong> / ${Number(a.max_score || 0).toFixed(1)}</td>
          <td><span class="badge" style="background:rgba(13,148,136,0.15);font-weight:700;">${Number(a.percent_score || 0).toFixed(1)}%</span></td>
          <td><span class="badge badge-${isPass ? 'success' : 'danger'}">${isPass ? 'ناجح ✓' : 'راسب ✕'}</span></td>
          <td>${esc(formatDateTimeArabic(a.submitted_at || a.started_at))}</td>
        </tr>
      `;
    }).join('');
  } catch (err) {
    if (tbody) {
      tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--danger)">${esc(err.message)}</td></tr>`;
    }
  }
};

window.closeQuizAttemptsModal = function() {
  closeModal('quizAttemptsModal');
};

function initQuizEvents() {
  if ($('btnOpenAddQuizModal')) $('btnOpenAddQuizModal').onclick = window.openNewQuizModal;
  if ($('btnCloseQuizModal')) $('btnCloseQuizModal').onclick = window.closeQuizModal;
  if ($('btnCancelQuizModal')) $('btnCancelQuizModal').onclick = window.closeQuizModal;
  if ($('btnCloseQuizAttemptsModal')) $('btnCloseQuizAttemptsModal').onclick = window.closeQuizAttemptsModal;
  if ($('quizzesCourseFilter')) $('quizzesCourseFilter').onchange = () => loadQuizzes();
  if ($('btnRefreshQuizzes')) $('btnRefreshQuizzes').onclick = () => loadQuizzes();

  const qForm = $('quizForm');
  if (qForm) {
    qForm.onsubmit = async (e) => {
      e.preventDefault();
      const saveBtn = $('btnSaveQuiz');
      if (saveBtn) saveBtn.disabled = true;

      const payload = {
        id: Number($('quizModalId').value) || 0,
        course_id: $('quizModalCourse').value,
        title_ar: $('quizModalName').value,
        time_limit_minutes: $('quizModalDuration').value,
        pass_percent: $('quizModalPassPercent').value,
        max_attempts: $('quizModalMaxAttempts').value,
        status: $('quizModalStatus').value,
        description_ar: $('quizModalDescription').value,
        ...(window.AssessmentInstructor?.getExamOptions() || {})
      };

      try {
        const res = await requestJSON('api/instructor/assessments.php?action=exam_save', {
          method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
        });
        notify(res.message || 'تم حفظ الاختبار بنجاح.');
        closeQuizModal();
        await loadQuizzes();
      } catch (err) {
        notify(err.message, 'error');
      } finally {
        if (saveBtn) saveBtn.disabled = false;
      }
    };
  }
}

/* -------------------------------------------------------------------------
   16) Main Application Bootstrap
   ------------------------------------------------------------------------- */
(async () => {
  initTheme();
  initNavigation();
  initForms();
  initQuizEvents();

  try {
    const data = await requestJSON(`${ROOT}api/instructor/me.php`);

    if (!data.user || data.user.role !== 'instructor') {
      showLoginView();
      return;
    }

    user = data.user;
    if (data.csrf_token) {
      csrfToken = data.csrf_token;
    }

    showAppView();
    setupUserSession();

    // Load initial tab
    await loadTabData('overview');

  } catch (err) {
    console.warn('Authentication check note:', err.message);
    showLoginView();
  }

  // ==========================================
  // Instructor Language Toggle & I18N Engine (AR / EN)
  // ==========================================
  const INSTRUCTOR_I18N = {
    ar: {
      doc_title: 'لوحة المحاضر الأكاديمية | Edu Nursing',
      ins_portal_subtitle: 'لوحة المحاضر الأكاديمية',
      nav_main_ctrl: 'الرئيسية والتحكم',
      nav_overview_analytics: 'نظرة عامة والتحليلات',
      nav_edu_curriculum: 'التعليم والمنهج',
      nav_my_courses: 'كورساتي التدريبية',
      nav_lessons_curriculum: 'إدارة الدروس والمنهج',
      nav_question_bank: 'بنك الأسئلة',
      nav_quizzes_eval: 'الاختبارات والتقييم',
      nav_students_follow: 'الطلاب والمتابعة',
      nav_students_comm: 'سجل الطلاب والتواصل',
      nav_reviews: 'تقييمات وآراء الطلاب',
      nav_interaction_live: 'التفاعل والبث المباشر',
      nav_meetings_zoom: 'جدول المحاضرات والزوم',
      nav_announcements: 'الإعلانات والتنبيهات',
      nav_account_settings: 'إعدادات الحساب',
      nav_profile_bio: 'الملف المهني والبيو',
      nav_quick_links: 'روابط سريعة',
      nav_student_portal: 'زيارة منصة الطلاب',
      nav_central_admin: 'بوابة الإدارة المركزية',
      nav_ins_active_status: 'حساب المحاضر: نشط ومعتمد',
      ins_version_text: 'Edu Nursing LMS  —  لوحة المحاضر v2.5',
      topbar_instructor_panel: 'لوحة المحاضر',
      global_search_placeholder: 'بحث شامل في الكورسات، الطلاب، الدروس... (Ctrl+K)',
      quick_sections: 'الأقسام السريعة',
      search_overview: '📊 نظرة عامة والتحليلات',
      search_courses: '📚 كورساتي التدريبية',
      search_lessons: '🎬 إدارة الدروس والمحتوى',
      search_question_bank: '🧠 بنك الأسئلة',
      search_students: '👨‍🎓 سجل الطلاب والتواصل',
      search_reviews: '⭐ تقييمات وآراء الطلاب',
      search_meetings: '📡 محاضرات Zoom المباشرة',
      search_profile: '👨‍⚕️ الملف الشخصي والبيو',
      topbar_refresh: 'تحديث البيانات',
      instructor_title: 'د. المحاضر',
      logout_btn: 'تسجيل الخروج',
      tab_overview: 'نظرة عامة',
      tab_courses: 'الكورسات',
      tab_lessons: 'الدروس والمنهج',
      tab_question_bank: 'بنك الأسئلة',
      question_bank_badge: 'بنك الأسئلة الطبية', question_bank_title: 'بنك الأسئلة الطبية', question_bank_subtitle: 'أنشئ أسئلتك، صنّفها، وراجعها ثم أضفها إلى اختباراتك.', question_new: '+ سؤال جديد',
      question_search: 'ابحث في نص السؤال أو التخصص أو الوسوم...', all_question_types: 'كل الأنواع', all_difficulties: 'كل المستويات', difficulty_easy: 'سهل', difficulty_medium: 'متوسط', difficulty_hard: 'صعب', all_question_courses: 'كل الكورسات', all_question_statuses: 'كل الحالات', question_draft: 'مسودة', question_approved: 'معتمد', refresh_btn: 'تحديث', question_export: 'تصدير JSON', question_import: 'استيراد JSON',
      assessment_ai_title: 'مساعد توليد الأسئلة بالذكاء الاصطناعي', assessment_ai_formats: 'PDF · DOCX · TXT · نص المحاضرة', assessment_ai_source: 'محتوى المحاضرة (اختياري)', assessment_ai_source_placeholder: 'الصق جزءًا من محتوى المحاضرة…', assessment_ai_file: 'ملف المحاضرة', assessment_ai_course: 'الكورس', assessment_ai_general: 'عام', assessment_ai_count: 'عدد الأسئلة', assessment_ai_level: 'المستوى', assessment_ai_mixed: 'متنوع', assessment_ai_generate: 'توليد أسئلة للمراجعة', assessment_ai_note: 'تُرسل المادة إلى مزود الذكاء الاصطناعي بعد إعداد المفتاح على الخادم. راجع كل سؤال قبل اعتماده.',
      tab_quizzes: 'الاختبارات',
      tab_students: 'الطلاب',
      tab_reviews: 'التقييمات',
      tab_meetings: 'جدول المحاضرات',
      tab_announcements: 'الإعلانات',
      tab_profile: 'الملف الشخصي',
      welcome_badge: 'EDU NURSING  —  مركز تدريب وإشراف المحاضر',
      welcome_title: 'أهلاً بك يا دكتور 🩺',
      welcome_subtitle: 'مؤشرات الأداء الأكاديمي لكورساتك، تفاعل الطلاب، والنشاط اللحظي من قاعدة البيانات.',
      quick_new_course: '+ كورس جديد',
      quick_new_lesson: '+ إضافة درس',
      quick_new_meeting: '+ جدولة زوم',
      quick_new_announcement: '+ إرسال إعلان',
      apply_filters: 'تطبيق الفلتر',
      reset_filters: 'إعادة ضبط'
    },
    en: {
      doc_title: 'Instructor Portal | Edu Nursing',
      ins_portal_subtitle: 'Academic Instructor Portal',
      nav_main_ctrl: 'Main & Controls',
      nav_overview_analytics: 'Overview & Analytics',
      nav_edu_curriculum: 'Education & Curriculum',
      nav_my_courses: 'My Courses',
      nav_lessons_curriculum: 'Lessons & Curriculum',
      nav_question_bank: 'Question Bank',
      nav_quizzes_eval: 'Quizzes & Evaluations',
      nav_students_follow: 'Students & Follow-up',
      nav_students_comm: 'Student Records & Chat',
      nav_reviews: 'Student Reviews',
      nav_interaction_live: 'Live Stream & Sessions',
      nav_meetings_zoom: 'Lectures Schedule & Zoom',
      nav_announcements: 'Announcements & Alerts',
      nav_account_settings: 'Account Settings',
      nav_profile_bio: 'Profile & Bio',
      nav_quick_links: 'Quick Links',
      nav_student_portal: 'Visit Student Portal',
      nav_central_admin: 'Admin Central Portal',
      nav_ins_active_status: 'Instructor: Active & Verified',
      ins_version_text: 'Edu Nursing LMS  —  Instructor v2.5',
      topbar_instructor_panel: 'Instructor Portal',
      global_search_placeholder: 'Search courses, students, lessons... (Ctrl+K)',
      quick_sections: 'Quick Sections',
      search_overview: '📊 Overview & Analytics',
      search_courses: '📚 My Courses',
      search_lessons: '🎬 Lessons Management',
      search_question_bank: '🧠 Question Bank',
      search_students: '👨‍🎓 Student Records & Chat',
      search_reviews: '⭐ Reviews & Ratings',
      search_meetings: '📡 Zoom Live Sessions',
      search_profile: '👨‍⚕️ Profile & Bio',
      topbar_refresh: 'Refresh Data',
      instructor_title: 'Dr. Instructor',
      logout_btn: 'Logout',
      tab_overview: 'Overview',
      tab_courses: 'Courses',
      tab_lessons: 'Lessons',
      tab_question_bank: 'Question Bank',
      question_bank_badge: 'MEDICAL QUESTION BANK', question_bank_title: 'Medical Question Bank', question_bank_subtitle: 'Create, classify, review, and add questions to your exams.', question_new: '+ New Question',
      question_search: 'Search question text, specialty, or tags...', all_question_types: 'All Types', all_difficulties: 'All Levels', difficulty_easy: 'Easy', difficulty_medium: 'Medium', difficulty_hard: 'Hard', all_question_courses: 'All Courses', all_question_statuses: 'All Statuses', question_draft: 'Draft', question_approved: 'Approved', refresh_btn: 'Refresh', question_export: 'Export JSON', question_import: 'Import JSON',
      assessment_ai_title: 'AI Question Generation Assistant', assessment_ai_formats: 'PDF · DOCX · TXT · Lecture text', assessment_ai_source: 'Lecture content (optional)', assessment_ai_source_placeholder: 'Paste part of the lecture content…', assessment_ai_file: 'Lecture file', assessment_ai_course: 'Course', assessment_ai_general: 'General', assessment_ai_count: 'Number of questions', assessment_ai_level: 'Difficulty', assessment_ai_mixed: 'Mixed', assessment_ai_generate: 'Generate questions for review', assessment_ai_note: 'Source material is sent to the AI provider after a server key is configured. Review each question before approval.',
      tab_quizzes: 'Quizzes',
      tab_students: 'Students',
      tab_reviews: 'Reviews',
      tab_meetings: 'Schedule',
      tab_announcements: 'Announcements',
      tab_profile: 'Profile',
      welcome_badge: 'EDU NURSING  —  Instructor Training & Supervision Hub',
      welcome_title: 'Welcome Doctor 🩺',
      welcome_subtitle: 'Live academic performance metrics, student engagement, and database insights.',
      quick_new_course: '+ New Course',
      quick_new_lesson: '+ Add Lesson',
      quick_new_meeting: '+ Schedule Zoom',
      quick_new_announcement: '+ Post Announcement',
      apply_filters: 'Apply Filters',
      reset_filters: 'Reset'
    }
  };

  // Comprehensive Direct Text Translation Dictionary for Instructor
  const INSTRUCTOR_TEXT_MAP = {
    // Navigation & General
    'لوحة المحاضر': 'Instructor Portal',
    'لوحة المحاضر الأكاديمية': 'Academic Instructor Portal',
    'الرئيسية والتحكم': 'Main & Dashboard',
    'نظرة عامة والتحليلات': 'Overview & Analytics',
    'التعليم والمنهج': 'Teaching & Curriculum',
    'كورساتي التدريبية': 'My Courses',
    'إدارة الدروس والمنهج': 'Lessons & Curriculum',
    'الاختبارات والتقييم': 'Quizzes & Assessments',
    'الطلاب والمتابعة': 'Students & Tracking',
    'سجل الطلاب والتواصل': 'Student Records & Chat',
    'تقييمات وآراء الطلاب': 'Student Reviews',
    'التفاعل والبث المباشر': 'Live Interaction',
    'جدول المحاضرات والزوم': 'Zoom & Lectures Schedule',
    'الإعلانات والتنبيهات': 'Announcements & Alerts',
    'إعدادات الحساب': 'Account Settings',
    'الملف المهني والبيو': 'Professional Bio & Profile',
    'روابط سريعة': 'Quick Links',
    'زيارة منصة الطلاب': 'Student Portal',
    'بوابة الإدارة المركزية': 'Admin Central Portal',
    'حساب المحاضر: نشط ومعتمد': 'Instructor: Active & Verified',
    'Edu Nursing LMS  —  لوحة المحاضر v2.5': 'Edu Nursing LMS  —  Instructor v2.5',
    'تحديث البيانات': 'Refresh Data',
    'تسجيل الخروج': 'Logout',
    'د. المحاضر': 'Dr. Instructor',
    'الوضع الليلي': 'Dark Mode',
    'الوضع النهاري': 'Light Mode',

    // Login View
    'بوابة المحاضرين المعتمدة': 'Certified Faculty Portal',
    'منصة Edu Nursing — الدخول مخصص لهيئة التدريس والمدربين المعتمدين لمتابعة الكورسات والطلاب.': 'Edu Nursing — Dedicated portal for certified instructors to manage courses and students.',
    'البريد الإلكتروني للمحاضر': 'Instructor Email Address',
    'كلمة المرور': 'Password',
    'تسجيل الدخول الآمن ←': 'Secure Login ←',
    'العودة إلى المنصة التعليمية للطلاب': 'Back to Student Platform',

    // Overview & Stats
    'أهلاً بك يا دكتور 🩺': 'Welcome Doctor 🩺',
    'مؤشرات الأداء الأكاديمي لكورساتك، تفاعل الطلاب، والنشاط اللحظي من قاعدة البيانات.': 'Academic performance, student engagement, and live database insights.',
    '+ كورس جديد': '+ New Course',
    '+ إضافة درس': '+ Add Lesson',
    '+ جدولة زوم': '+ Schedule Zoom',
    '+ إرسال إعلان': '+ Post Announcement',
    'الكورسات المعتمدة': 'Approved Courses',
    'منشورة ومتاحة في المنصة': 'Published and available',
    'الدروس والمحاضرات': 'Lessons & Lectures',
    'شرح وفيديو سريري مرفوع': 'Clinical video & lessons',
    'الطلاب المشتركون الفعليون': 'Active Subscribers',
    'اشتراكات مفعلة ونشطة': 'Active paid enrollments',
    'الزوار والمهتمون (Leads)': 'Visitors & Leads',
    'تصفحوا وتفاعلوا مع المحتوى': 'Interacted with content',

    // Section Headers
    'نشاط المشاهدات والزيارات اليومية 📈': 'Daily Views & Visits Activity 📈',
    'إحصائيات تفاعل وتصفح الطلاب لكورساتك خلال آخر أسبوعين.': 'Student browsing and engagement statistics over the past 2 weeks.',
    'نسب إكمال الطلاب للمسارات 🎯': 'Student Track Completion Rates 🎯',
    'متوسط تقدم وإنجاز الطلاب لكل كورس تدريبي مسند إليك.': 'Average student progress for each training course.',
    'أحدث الطلاب المنضمين لكورساتك 👨‍🎓': 'Recent Students Enrolled in Your Courses 👨‍🎓',
    'الطلاب المسجلون حديثاً لمتابعة تدريبهم والتواصل معهم.': 'Recently enrolled students for follow-up and engagement.',
    'أحدث تقييمات وآراء الطلاب 🌟': 'Recent Student Reviews & Ratings 🌟',
    'مراجعات الطلاب وتقييماتهم للشروحات والمحتوى التدريبي.': 'Student feedback and ratings on lectures and training.',
    'محاضرات البث المباشر وزوم القادمة 📡': 'Upcoming Live Stream & Zoom Lectures 📡',
    'الجلسات السريرية التفاعلية المجدولة قريباً لطلابك.': 'Interactive clinical sessions scheduled soon for your students.',

    // Filters & Selects
    'من تاريخ': 'From Date',
    'إلى تاريخ': 'To Date',
    'الكورس': 'Course',
    'كل كورساتي': 'All My Courses',
    'جميع الكورسات': 'All Courses',
    'جميع الكورسات التدريبية': 'All Training Courses',
    'كل الكورسات': 'All Courses',
    'كل الحالات': 'All Statuses',
    'كل المستويات': 'All Levels',
    'بحث شامل': 'Global Search',
    'تطبيق الفلتر': 'Apply Filter',
    'إعادة ضبط': 'Reset',
    'تصفية': 'Filter',
    'تحديث': 'Refresh',
    '🔄 تحديث': '🔄 Refresh',

    // Courses Panel
    'قائمة كورساتك التدريبية 📚': 'My Training Courses 📚',
    'إدارة وتعديل الكورسات وتفاصيلها والأسعار وحالة النشر والأهداف السريرية.': 'Manage courses, pricing, publishing status, and clinical objectives.',
    '+ كورس تدريبي جديد': '+ New Training Course',
    'منشور متاح للطلاب': 'Published & Available',
    'مسودة قيد الإعداد': 'Draft in Preparation',
    'مؤرشف': 'Archived',
    'مبتدئ': 'Beginner',
    'متوسط': 'Intermediate',
    'متقدم': 'Advanced',

    // Lessons Panel
    'إدارة المناهج والدروس السريرية 🎬': 'Clinical Curriculum & Lessons 🎬',
    'تنظيم الوحدات والمناهج التعليمية، رفع الشروحات السريرية، وإدارة المحتوى والمدة.': 'Organize learning modules, upload clinical lectures, and manage durations.',
    '+ إضافة وحدة / منهج': '+ Add Unit / Module',
    '+ إضافة درس جديد': '+ Add New Lesson',
    'المنهج والوحدات': 'Curriculum & Units',
    'جدول الدروس': 'Lessons Table',
    'الترتيب': 'Order',
    'عنوان الدرس': 'Lesson Title',
    'الوحدة / المنهج': 'Unit / Module',
    'مدة الدرس': 'Duration',
    'نوع المعاينة': 'Preview Type',
    'رابط الشرح': 'Video Link',

    // Students Panel
    'سجل الطلاب ومتابعة الأداء والتواصل 👥': 'Students Directory & Tracking 👥',
    'قائمة المشتركين الفعليين والمهتمين الذين استعرضوا كورساتك لمتابعة إنجازهم والتواصل المباشر.': 'Enrolled students and prospective leads to track progress and direct chat.',
    'المشتركون الفعليون': 'Active Subscribers',
    'المهتمون والزوار (Leads)': 'Visitors & Leads',
    'تصدير CSV': 'Export CSV',
    'الطالب': 'Student',
    'البريد الإلكتروني': 'Email',
    'الكورس المستهدف': 'Target Course',
    'نسبة الإنجاز الأكاديمي': 'Progress Rate',
    'تاريخ التفاعل': 'Interaction Date',
    'رقم الهاتف': 'Phone Number',
    'تواصل مباشر': 'Direct Chat',
    'تواصل': 'Contact',

    // Reviews Panel
    'تقييمات وآراء الطلاب 🌟': 'Student Reviews & Ratings 🌟',
    'مراجعات الطلاب وتقييماتهم للشروحات والمحتوى التدريبي وملاحظاتهم السريرية.': 'Student feedback and clinical review notes.',
    'التقييم': 'Rating',
    'التعليق والملاحظات': 'Comment & Feedback',
    'تاريخ التقييم': 'Review Date',

    // Timetable / Meetings Panel
    'جدول المحاضرات والتدريب السريري المباشر 📅': 'Live Clinical Schedule & Timetable 📅',
    'إدارة المواعيد الأسبوعية، جلسات Zoom والتدريب العملي، وإشعار الطلاب بالمواعيد فوراً.': 'Manage weekly schedule, Zoom sessions, and notify students instantly.',
    '+ إضافة موعد في الجدول': '+ Add to Schedule',
    'أجندة المواعيد': 'Schedule Agenda',
    'الجدول': 'Timetable',
    'عنوان المحاضرة': 'Session Title',
    'الكورس المرتبط': 'Linked Course',
    'موعد البدء': 'Start Time',
    'المدة': 'Duration',
    'رابط الانضمام': 'Join URL',

    // Announcements Panel
    'لوحة الإعلانات والتنبيهات 📢': 'Announcements & Alerts Board 📢',
    'نشر التحديثات والتوجيهات والواجبات وإرسال إشعارات فورية لطلابك في المنصة.': 'Publish instructions, assignments, and send instant alerts to students.',
    '+ إرسال تنبيه جديد للطلاب': '+ Post New Alert',
    'عنوان التنبيه': 'Alert Title',
    'نص التنبيه': 'Alert Body',
    'تاريخ النشر': 'Published Date',

    // Quizzes Panel
    'إدارة الاختبارات والتقييم الأكاديمي 📝': 'Quizzes & Academic Assessments 📝',
    'إنشاء اختبارات الكورسات، تحديد درجات النجاح، ومتابعة إجابات ونتائج الطلاب.': 'Create course quizzes, set passing scores, and review student grades.',
    '+ إنشاء اختبار جديد': '+ Create New Quiz',
    'عنوان الاختبار': 'Quiz Title',
    'الكورس التدريبي': 'Course',
    'مدة الاختبار': 'Duration',
    'نسبة النجاح': 'Passing Score',
    'عدد الأسئلة': 'Questions',
    'محاولات الطلاب': 'Student Attempts',
    'متوسط الدرجات': 'Average Grade',

    // Actions & Common
    'الإجراءات': 'Actions',
    'تعديل': 'Edit',
    'حذف': 'Delete',
    'عرض': 'View',
    'إلغاء': 'Cancel',
    'إغلاق': 'Close',
    'حفظ': 'Save',
    'حفظ التغييرات': 'Save Changes',
    'مباشر': 'Live',
    'آخر المسجلين': 'Recent',
    'آخر التقييمات': 'Latest',
    'مجدولة': 'Scheduled'
  };

    const INSTRUCTOR_ALL_TRANSLATIONS = Object.assign({}, INSTRUCTOR_TEXT_MAP, Object.fromEntries(Object.keys(INSTRUCTOR_I18N.ar).filter(key => typeof INSTRUCTOR_I18N.ar[key] === 'string' && typeof INSTRUCTOR_I18N.en[key] === 'string').map(key => [INSTRUCTOR_I18N.ar[key], INSTRUCTOR_I18N.en[key]])));
    let instructorLang = localStorage.getItem('nbc_lang') || localStorage.getItem('nbc_instructor_lang') || 'ar';

  function translateDom(root, lang) {
    if (!root) return;

    if (lang === 'en') {
      // 1. data-i18n
      root.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.dataset.i18n;
        if (INSTRUCTOR_I18N.en[key]) {
          if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
            el.placeholder = INSTRUCTOR_I18N.en[key];
          } else {
            el.textContent = INSTRUCTOR_I18N.en[key];
          }
        }
      });

      // 2. data-i18n-placeholder
      root.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
        const key = el.dataset.i18nPlaceholder;
        if (INSTRUCTOR_I18N.en[key]) {
          el.placeholder = INSTRUCTOR_I18N.en[key];
        }
      });

      // 3. Fallback scan for plain text in common elements
      const selector = 'button, h1, h2, h3, h4, th, .label, .sub, .pill, .badge, .status, .eyebrow, small, legend, a.btn, label > span';
      root.querySelectorAll(selector).forEach(el => {
        if (el.children.length === 0 || (el.children.length === 1 && el.querySelector('svg, span.status-dot'))) {
          const directText = Array.from(el.childNodes)
            .filter(n => n.nodeType === Node.TEXT_NODE)
            .map(n => n.textContent.trim())
            .filter(Boolean)
            .join(' ');

          if (directText && INSTRUCTOR_TEXT_MAP[directText]) {
            if (!el.dataset.origText) el.dataset.origText = directText;
            Array.from(el.childNodes).forEach(n => {
              if (n.nodeType === Node.TEXT_NODE && n.textContent.trim()) {
                n.textContent = n.textContent.replace(directText, INSTRUCTOR_TEXT_MAP[directText]);
              }
            });
          }
        } else if (el.children.length === 0) {
          const txt = el.textContent.trim();
          if (INSTRUCTOR_TEXT_MAP[txt]) {
            if (!el.dataset.origText) el.dataset.origText = txt;
            el.textContent = INSTRUCTOR_TEXT_MAP[txt];
          }
        }
      });
    } else {
      // Restore Arabic
      root.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.dataset.i18n;
        if (INSTRUCTOR_I18N.ar[key]) {
          if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
            el.placeholder = INSTRUCTOR_I18N.ar[key];
          } else {
            el.textContent = INSTRUCTOR_I18N.ar[key];
          }
        }
      });

      root.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
        const key = el.dataset.i18nPlaceholder;
        if (INSTRUCTOR_I18N.ar[key]) {
          el.placeholder = INSTRUCTOR_I18N.ar[key];
        }
      });

      root.querySelectorAll('[data-orig-text]').forEach(el => {
        const orig = el.dataset.origText;
        if (orig) {
          let replaced = false;
          Array.from(el.childNodes).forEach(n => {
            if (n.nodeType === Node.TEXT_NODE && n.textContent.trim()) {
              n.textContent = ' ' + orig + ' ';
              replaced = true;
            }
          });
          if (!replaced) el.textContent = orig;
          delete el.dataset.origText;
        }
      });
    }
    window.PlatformLanguage?.setDictionary(INSTRUCTOR_ALL_TRANSLATIONS);
    window.PlatformLanguage?.apply(root, lang, INSTRUCTOR_ALL_TRANSLATIONS);
  }

  function applyInstructorLang(lang) {
    instructorLang = lang === 'en' ? 'en' : 'ar';
    document.documentElement.setAttribute('lang', instructorLang);
    document.documentElement.setAttribute('dir', instructorLang === 'ar' ? 'rtl' : 'ltr');
    const lbl = document.getElementById('instructorLangLabel');
    if (lbl) lbl.textContent = instructorLang === 'ar' ? 'EN' : 'AR';
    const loginLbl = document.getElementById('loginLangLabel');
    if (loginLbl) loginLbl.textContent = instructorLang === 'ar' ? 'EN' : 'AR';
    localStorage.setItem('nbc_instructor_lang', instructorLang);
    if (localStorage.getItem('nbc_lang') !== instructorLang) localStorage.setItem('nbc_lang', instructorLang);

    const dict = INSTRUCTOR_I18N[instructorLang] || INSTRUCTOR_I18N.ar;
    document.title = dict.doc_title || 'Edu Nursing LMS';

    translateDom(document, instructorLang);

    // Update breadcrumb current
    const activeTab = document.querySelector('.side-link.tab.active')?.dataset?.tab || 'overview';
    const breadcrumbCurrent = document.getElementById('breadcrumbCurrent');
    if (breadcrumbCurrent) {
      const tabKeyMap = {
        'overview': 'nav_overview_analytics',
        'courses': 'nav_my_courses',
        'lessons': 'nav_lessons_curriculum',
        'quizzes': 'nav_quizzes_eval',
        'students': 'nav_students_comm',
        'reviews': 'nav_reviews',
        'meetings': 'nav_meetings_zoom',
        'announcements': 'nav_announcements',
        'profile': 'nav_profile_bio'
      };
      if (tabKeyMap[activeTab] && dict[tabKeyMap[activeTab]]) {
        breadcrumbCurrent.textContent = dict[tabKeyMap[activeTab]];
      }
    }
  }

  window.addEventListener('platform:languagechange', event => {
    const next = event.detail === 'en' ? 'en' : 'ar';
    if (instructorLang !== next) applyInstructorLang(next);
  });

  function setupInstructorLangToggle() {
    ['instructorLangToggle', 'loginLangToggle'].forEach(id => {
      const btn = document.getElementById(id);
      if (btn && !btn.dataset.bound) {
        btn.dataset.bound = 'true';
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          const nextLang = instructorLang === 'ar' ? 'en' : 'ar';
          applyInstructorLang(nextLang);
        });
      }
    });
  }

  setupInstructorLangToggle();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      setupInstructorLangToggle();
      applyInstructorLang(instructorLang);
    });
  } else {
    applyInstructorLang(instructorLang);
  }

  // MutationObserver to auto-translate newly loaded AJAX tables/content in English mode
  const observer = new MutationObserver((mutations) => {
    if (instructorLang === 'en') {
      for (const m of mutations) {
        for (const node of m.addedNodes) {
          if (node.nodeType === Node.ELEMENT_NODE) {
            translateDom(node, 'en');
          }
        }
      }
    }
  });
  observer.observe(document.body, { childList: true, subtree: true });
})();
