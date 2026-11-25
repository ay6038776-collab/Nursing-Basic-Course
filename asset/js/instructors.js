(() => {
  'use strict';

  const getCore = () => window.AdminCore || {};
  const request = (url, opts) => (getCore().request ? getCore().request(url, opts) : fetch(url, { credentials: 'same-origin', ...opts }).then(r => r.json()));
  const notice = (sel, msg, err) => (getCore().notice ? getCore().notice(sel, msg, err) : alert(msg));
  const esc = str => (getCore().escapeHtml ? getCore().escapeHtml(str) : String(str ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])));
  const badge = st => (getCore().badge ? getCore().badge(st) : `<span class="badge">${esc(st)}</span>`);
  const formatDate = d => (getCore().formatDate ? getCore().formatDate(d) : String(d || ''));
  const formatDateTime = d => (getCore().formatDateTime ? getCore().formatDateTime(d) : String(d || ''));
  const num = n => (getCore().num ? getCore().num(n) : Number(n || 0).toLocaleString('ar-EG'));

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];

  // الصلاحيات القابلة للتحديد
  const PERMISSIONS = [
    ['courses_view',   'عرض الكورسات المسندة', 'الاطلاع على الكورسات المسندة إليه فقط.'],
    ['lessons_manage', 'إضافة وتعديل الدروس',  'إنشاء الدروس وتعديلها داخل كورساته.'],
    ['students_view',  'عرض طلاب كورساته',     'الاسم وحالة التسجيل فقط، دون بيانات الدفع.'],
    ['progress_view',  'متابعة تقدم الطلاب',   'نسب الإكمال ونتائج الاختبارات لطلاب كورساته.'],
    ['quizzes_manage', 'إدارة الاختبارات',     'إنشاء الاختبارات وتصحيحها.'],
    ['announcements',  'نشر إعلانات للطلاب',   'إرسال إعلانات داخل كورساته فقط.']
  ];

  const API = 'api/admin/instructors.php';
  let currentId = 0;
  let current = null;
  let instructorsCurrentView = localStorage.getItem('nbc_admin_instructors_view') || 'cards';
  let instructorsCurrentData = [];

  const initials = name =>
    String(name || '؟').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('') || '؟';

  const avatarHtml = (name, url) =>
    url ? `<img src="${esc(url)}" alt="${esc(name)}">` : esc(initials(name));

  const empty = text =>
    `<div style="padding:20px;color:#718096">${esc(text)}</div>`;

  const fail = error => notice('#globalMessage', error.message || 'حدث خطأ في تحميل البيانات.', true);

  // ---------- القائمة: التبديل بين الكروت والجدول ----------

  function applyInstructorsView() {
    const tableEl = $('#insTable');
    const cardsEl = $('#insCardsGrid');
    if (!tableEl || !cardsEl) return;

    if (instructorsCurrentView === 'cards') {
      tableEl.hidden = true;
      cardsEl.hidden = false;
      $('#insViewTableBtn')?.classList.remove('active');
      $('#insViewCardsBtn')?.classList.add('active');
      renderInstructorCards(instructorsCurrentData);
    } else {
      tableEl.hidden = false;
      cardsEl.hidden = true;
      $('#insViewTableBtn')?.classList.add('active');
      $('#insViewCardsBtn')?.classList.remove('active');
    }
  }

  function renderInstructorCards(rows) {
    const grid = $('#insCardsGrid');
    if (!grid) return;
    if (!rows || !rows.length) {
      grid.innerHTML = '<div style="padding:36px;text-align:center;color:var(--slate-400);grid-column:1/-1;">لا يوجد محاضرون مسجلون حالياً أو مطابقون للبحث.</div>';
      return;
    }

    grid.innerHTML = rows.map(r => {
      const statusMap = { active: 'نشط', inactive: 'غير نشط', suspended: 'موقوف' };
      const statusClass = { active: 'success', inactive: 'muted', suspended: 'danger' };
      const avatar = r.avatar_url
        ? `<img src="${esc(r.avatar_url)}" alt="${esc(r.full_name)}" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">`
        : '';
      const inits = initials(r.full_name);

      return `
        <div class="instructor-card" data-card-id="${esc(r.id)}">
          <div class="instructor-card-head">
            <div class="instructor-card-avatar">
              ${avatar}
              <span style="${r.avatar_url ? 'display:none;' : ''}display:flex;align-items:center;justify-content:center;width:100%;height:100%;font-weight:800;font-size:18px;">${esc(inits)}</span>
            </div>
            <div class="instructor-card-userinfo">
              <div class="instructor-card-name" title="${esc(r.full_name)}">${esc(r.full_name)}</div>
              <div class="instructor-card-email" title="${esc(r.email)}">${esc(r.email)}</div>
              ${r.specialization ? `<span class="instructor-card-spec" title="${esc(r.specialization)}">🩺 ${esc(r.specialization)}</span>` : '<span class="instructor-card-spec" style="opacity:0.7">👨‍🏫 هيئة التدريس</span>'}
            </div>
          </div>

          <div class="instructor-card-stats">
            <div class="instructor-stat-item">
              <div class="instructor-stat-value">${num(r.courses_count)}</div>
              <div class="instructor-stat-label">كورس</div>
            </div>
            <div class="instructor-stat-item">
              <div class="instructor-stat-value">${num(r.lessons_count)}</div>
              <div class="instructor-stat-label">درس</div>
            </div>
            <div class="instructor-stat-item">
              <div class="instructor-stat-value">${num(r.students_count)}</div>
              <div class="instructor-stat-label">طالب</div>
            </div>
          </div>

          <div class="instructor-card-meta">
            <span class="badge badge-${statusClass[r.status] || 'muted'}">${statusMap[r.status] || r.status}</span>
            <span style="color:var(--slate-500);font-size:11.5px;font-weight:600;">${r.phone ? '📱 ' + esc(r.phone) : 'بدون هاتف'}</span>
          </div>

          <div class="instructor-card-actions">
            <button type="button" class="small-btn" data-ins-view="${esc(r.id)}" style="background:var(--teal-600,#0d9488);color:#fff;border-color:var(--teal-600,#0d9488);font-weight:700;">👁 عرض الملف</button>
            <button type="button"
                    class="small-btn ${r.status === 'active' ? 'danger' : ''}"
                    data-ins-status="${esc(r.id)}"
                    data-next="${r.status === 'active' ? 'suspended' : 'active'}"
            >${r.status === 'active' ? 'تعطيل' : 'تفعيل'}</button>
            ${r.phone ? `<a href="https://wa.me/${r.phone.replace(/\D/g, '')}" target="_blank" rel="noopener" class="small-btn btn-whatsapp" style="text-decoration:none;display:flex;align-items:center;justify-content:center;gap:4px">💬 واتساب</a>` : ''}
          </div>
        </div>
      `;
    }).join('');
  }

  async function load() {
    showList();

    const searchInput = $('#insSearch');
    const statusSelect = $('#insStatus');

    const params = new URLSearchParams({
      q: searchInput ? searchInput.value.trim() : '',
      status: statusSelect ? statusSelect.value : '',
      page: '1',
      per_page: '100'
    });

    try {
      const data = await request(`${API}?${params}`);
      const rows = data.data || [];
      instructorsCurrentData = rows;

      const tableEl = $('#insTable');
      if (tableEl) {
        tableEl.innerHTML = rows.length ? `
          <table>
            <thead>
              <tr>
                <th>المحاضر</th>
                <th>التخصص</th>
                <th>الكورسات</th>
                <th>الدروس</th>
                <th>الطلاب</th>
                <th>الحالة</th>
                <th>إجراء</th>
              </tr>
            </thead>
            <tbody>
              ${rows.map(r => `
                <tr>
                  <td>
                    <div class="ins-person">
                      <span class="ins-avatar">${avatarHtml(r.full_name, r.avatar_url)}</span>
                      <span>
                        <a href="#" data-ins-view="${esc(r.id)}"
                           style="font-weight:700;text-decoration:none;color:var(--teal,#0d9488)">${esc(r.full_name)}</a>
                        <br><small>${esc(r.email)}</small>
                      </span>
                    </div>
                  </td>
                  <td>${esc(r.specialization || '—')}</td>
                  <td>${esc(num(r.courses_count))}</td>
                  <td>${esc(num(r.lessons_count))}</td>
                  <td>${esc(num(r.students_count))}</td>
                  <td>${badge(r.status)}</td>
                  <td>
                    <button type="button" class="small-btn" data-ins-view="${esc(r.id)}">👁 عرض الملف</button>
                    <button type="button"
                            class="small-btn ${r.status === 'active' ? 'danger' : ''}"
                            data-ins-status="${esc(r.id)}"
                            data-next="${r.status === 'active' ? 'suspended' : 'active'}"
                    >${r.status === 'active' ? 'تعطيل' : 'تفعيل'}</button>
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>` : empty('لا يوجد محاضرون مطابقون لخيارات البحث.');
      }

      applyInstructorsView();
    } catch (e) {
      fail(e);
    }
  }

  function showList() {
    const list = $('#insListView');
    const details = $('#insDetailsView');
    if (list) list.hidden = false;
    if (details) details.hidden = true;
    currentId = 0;
    current = null;
  }

  // ---------- الملف الشخصي للمحاضر ----------

  async function openDetails(id) {
    currentId = Number(id);
    if (!currentId) return;

    const list = $('#insListView');
    const form = $('#insForm');
    const details = $('#insDetailsView');
    const msgBox = $('#insDetailsMsg');

    if (list) list.hidden = true;
    if (form) form.hidden = true;
    if (details) details.hidden = false;
    if (msgBox) msgBox.hidden = true;
    details?.scrollIntoView({ behavior: 'smooth', block: 'start' });

    try {
      const res = await request(`${API}?id=${currentId}`);
      current = res.data || {};
      renderDetails();

      if ((res.warnings || []).length) {
        msg('تعذر تحميل بعض الأقسام (' + res.warnings.join('، ') + ').', true);
      }
    } catch (e) {
      msg(e.message, true);
    }
  }

  const msg = (text, error = false) => notice('#insDetailsMsg', text, error);

  function renderDetails() {
    const p = current.profile || {};
    const stats = current.stats || {};

    const nameEl = $('#insName');
    if (nameEl) nameEl.textContent = p.full_name || 'ملف المحاضر';

    const avEl = $('#insAvatar');
    if (avEl) avEl.innerHTML = avatarHtml(p.full_name, p.avatar_url);

    const prevEl = $('#insPhotoPreview');
    if (prevEl) prevEl.innerHTML = avatarHtml(p.full_name, p.avatar_url);

    const subEl = $('#insSub');
    if (subEl) {
      subEl.innerHTML =
        `${badge(p.status)} <span>${esc(p.email || '')}</span>` +
        (p.created_at ? ` <span>• منذ ${esc(formatDate(p.created_at))}</span>` : '');
    }

    const toggle = $('#insToggleStatus');
    if (toggle) {
      const isActive = p.status === 'active';
      toggle.textContent = isActive ? 'تعطيل الحساب' : 'تفعيل الحساب';
      toggle.className = 'btn ' + (isActive ? 'btn-danger' : 'btn-primary');
      toggle.dataset.next = isActive ? 'suspended' : 'active';
    }

    // ملخص الإحصائيات
    const statCards = [
      ['الكورسات المسندة', num(stats.courses), '▤', ''],
      ['الدروس المضافة', num(stats.lessons), '✎', ''],
      ['إجمالي الطلاب', num(stats.students), '♙', 'في الكورسات المسندة'],
      ['آخر نشاط', stats.last_activity_at ? formatDateTime(stats.last_activity_at) : '—', '◔', '']
    ];

    const statsEl = $('#insStats');
    if (statsEl) {
      statsEl.innerHTML = statCards.map(([l, v, i, s]) => `
        <article class="stat">
          <span class="icon">${esc(i)}</span>
          <div class="label">${esc(l)}</div>
          <div class="value" style="${l === 'آخر نشاط' ? 'font-size:15px;margin-top:10px' : ''}">${esc(v)}</div>
          ${s ? `<div class="sub">${esc(s)}</div>` : ''}
        </article>`).join('');
    }

    // نموذج البيانات المهنية
    const f = $('#insEditForm');
    if (f) {
      ['full_name', 'email', 'phone', 'age', 'gender', 'specialization', 'cv_url', 'experience_years', 'workplace', 'experience', 'bio']
        .forEach(k => { const el = f.elements[k]; if (el) el.value = p[k] ?? ''; });
    }

    if (p.workplace && subEl) {
      subEl.innerHTML += ` • <span>🏥 ${esc(p.workplace)}</span>`;
    }
    if (p.experience_years && subEl) {
      subEl.innerHTML += ` • <span>⏳ خبرة ${esc(p.experience_years)} سنوات</span>`;
    }
    if (p.cv_url && subEl) {
      subEl.innerHTML += ` • <a href="${esc(p.cv_url)}" target="_blank" rel="noopener" style="color:var(--teal,#0d9488);font-weight:700">📄 السيرة الذاتية (CV)</a>`;
    }
    if (p.gender && subEl) {
      subEl.innerHTML += ` • <span>${p.gender === 'male' ? 'ذكر' : 'أنثى'}</span>`;
    }
    if (p.age && subEl) {
      subEl.innerHTML += ` • <span>${esc(p.age)} سنة</span>`;
    }

    // الكورسات
    const courses = current.courses || [];
    const crsPill = $('#insCoursesPill');
    if (crsPill) crsPill.textContent = `${num(courses.length)} كورس`;

    const crsWrap = $('#insCourses');
    if (crsWrap) {
      crsWrap.innerHTML = courses.length ? `
        <table>
          <thead><tr><th>الكورس</th><th>الحالة</th><th>الطلاب النشطون</th><th>إجمالي المسجلين</th></tr></thead>
          <tbody>${courses.map(c => `
            <tr>
              <td><strong>${esc(c.title)}</strong></td>
              <td>${badge(c.status)}</td>
              <td>${esc(num(c.active_students))} طالب</td>
              <td>${esc(num(c.total_students ?? c.active_students))} مسجل</td>
            </tr>`).join('')}</tbody>
        </table>` : empty('لا توجد كورسات مسندة لهذا المحاضر حتى الآن.');
    }

    // الدروس
    const lessons = current.lessons || [];
    const lesPill = $('#insLessonsPill');
    if (lesPill) lesPill.textContent = `${num(stats.lessons ?? lessons.length)} درس`;

    const lesWrap = $('#insLessons');
    if (lesWrap) {
      lesWrap.innerHTML = lessons.length ? `
        <table>
          <thead><tr><th>الدرس</th><th>الكورس</th><th>الحالة</th><th>تاريخ الإضافة</th></tr></thead>
          <tbody>${lessons.map(l => `
            <tr>
              <td><strong>${esc(l.title)}</strong></td>
              <td>${esc(l.course_title || '—')}</td>
              <td>${badge(l.status)}</td>
              <td>${esc(formatDate(l.created_at))}</td>
            </tr>`).join('')}</tbody>
        </table>` : empty('لم يضف المحاضر أي دروس بعد.');
    }

    // الأنشطة
    const acts = current.activity || [];
    const actWrap = $('#insActivity');
    if (actWrap) {
      actWrap.innerHTML = acts.length ? acts.map(a => `
        <div class="activity">
          <span class="dot">${esc(a.icon || '✎')}</span>
          <div>
            ${esc(a.text || a.label || '')}${a.subject ? ` — <strong>${esc(a.subject)}</strong>` : ''}
            <small>${esc(formatDateTime(a.at))}</small>
          </div>
        </div>`).join('') : empty('لا توجد أنشطة مسجلة.');
    }

    // الصلاحيات
    const granted = new Set(current.permissions || []);
    const permsWrap = $('#insPerms');
    if (permsWrap) {
      permsWrap.innerHTML = PERMISSIONS.map(([key, title, hint]) => `
        <label class="perm-item">
          <input type="checkbox" name="perm" value="${esc(key)}" ${granted.has(key) ? 'checked' : ''}>
          <span><strong>${esc(title)}</strong><small>${esc(hint)}</small></span>
        </label>`).join('');
    }
  }

  // ---------- تسجيل الأحداث ----------

  $('#insSearchBtn')?.addEventListener('click', load);
  $('#insSearch')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); load(); }
  });
  $('#insStatus')?.addEventListener('change', load);

  $('#insAddBtn')?.addEventListener('click', () => {
    const form = $('#insForm');
    if (form) {
      form.hidden = false;
      form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });

  $('#insCancelAdd')?.addEventListener('click', () => {
    const form = $('#insForm');
    if (form) form.hidden = true;
  });

  $('#insForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.currentTarget;
    const d = new FormData(form);

    try {
      await request(API, {
        method: 'POST',
        body: JSON.stringify({
          action: 'create',
          full_name: d.get('full_name'),
          email: d.get('email'),
          phone: d.get('phone'),
          password: d.get('password'),
          specialization: d.get('specialization'),
          experience_years: d.get('experience_years') === '' ? null : Number(d.get('experience_years'))
        })
      });

      form.reset();
      form.hidden = true;
      await load();
      notice('#globalMessage', 'تم إنشاء حساب المحاضر بنجاح.');
    } catch (err) {
      fail(err);
    }
  });

  // رفع وتغيير الصورة
  $('#insPhotoInput')?.addEventListener('change', e => {
    const file = e.target.files?.[0];
    if (!file || !currentId) return;

    if (file.size > 2 * 1024 * 1024) {
      alert('حجم الصورة يجب ألا يتجاوز 2 ميجابايت.');
      return;
    }

    const reader = new FileReader();
    reader.onload = async () => {
      try {
        await request(API, {
          method: 'POST',
          body: JSON.stringify({ action: 'avatar', id: currentId, image_data: reader.result })
        });
        msg('تم تحديث صورة المحاضر بنجاح.');
        await openDetails(currentId);
        load();
      } catch (err) {
        msg(err.message, true);
      }
    };
    reader.readAsDataURL(file);
  });

  // أزرار التبديل بين الكروت والجدول
  document.addEventListener('click', e => {
    if (e.target.closest('#insViewCardsBtn')) {
      instructorsCurrentView = 'cards';
      localStorage.setItem('nbc_admin_instructors_view', 'cards');
      applyInstructorsView();
      if (!instructorsCurrentData.length) load();
    }
    if (e.target.closest('#insViewTableBtn')) {
      instructorsCurrentView = 'table';
      localStorage.setItem('nbc_admin_instructors_view', 'table');
      applyInstructorsView();
      if (!instructorsCurrentData.length) load();
    }
  });

  // الضغط على الإجراءات داخل الكروت والجدول
  $('#insListView')?.addEventListener('click', async e => {
    const view = e.target.closest('[data-ins-view]');
    if (view) {
      e.preventDefault();
      openDetails(view.dataset.insView);
      return;
    }

    const st = e.target.closest('[data-ins-status]');
    if (!st) return;

    if (st.dataset.next === 'suspended' &&
        !confirm('تعطيل حساب هذا المحاضر؟ لن يتمكن من الدخول، ولن تُحذف بياناته.')) return;

    st.disabled = true;
    try {
      await request(API, { method: 'POST', body: JSON.stringify({ action: 'status', id: Number(st.dataset.insStatus), status: st.dataset.next }) });
      await load();
      notice('#globalMessage', 'تم تحديث حالة المحاضر بنجاح.');
    } catch (err) {
      fail(err);
    } finally {
      st.disabled = false;
    }
  });

  $('#insBack')?.addEventListener('click', load);

  $('#insToggleStatus')?.addEventListener('click', async e => {
    const btn = e.currentTarget;
    const next = btn.dataset.next;

    if (next === 'suspended' &&
        !confirm('تعطيل حساب هذا المحاضر؟ لن يتمكن من الدخول، ولن تُحذف بياناته.')) return;

    btn.disabled = true;
    try {
      await request(API, { method: 'POST', body: JSON.stringify({ action: 'status', id: currentId, status: next }) });
      await openDetails(currentId);
      msg(next === 'active' ? 'تم تفعيل الحساب.' : 'تم تعطيل الحساب.');
    } catch (err) {
      msg(err.message, true);
    } finally {
      btn.disabled = false;
    }
  });

  $('#insEditForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const d = new FormData(e.currentTarget);
    const years = d.get('experience_years');

    try {
      await request(API, {
        method: 'POST',
        body: JSON.stringify({
          action: 'update',
          id: currentId,
          full_name: d.get('full_name'),
          email: d.get('email'),
          phone: d.get('phone'),
          specialization: d.get('specialization'),
          experience_years: years === '' ? null : Number(years),
          workplace: d.get('workplace'),
          experience: d.get('experience'),
          bio: d.get('bio')
        })
      });
      await openDetails(currentId);
      msg('تم حفظ بيانات المحاضر بنجاح.');
    } catch (err) {
      msg(err.message, true);
    }
  });

  $('#insPermForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const perms = [...e.currentTarget.querySelectorAll('input[name="perm"]:checked')].map(i => i.value);

    try {
      await request(API, {
        method: 'POST',
        body: JSON.stringify({ action: 'permissions', id: currentId, permissions: perms })
      });
      msg('تم حفظ الصلاحيات بنجاح.');
    } catch (err) {
      msg(err.message, true);
    }
  });

  window.InstructorsAdmin = { load, openDetails, applyInstructorsView };

  // تحميل تلقائي مباشر
  load();

})();
