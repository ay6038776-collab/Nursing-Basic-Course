(() => {
  'use strict';

  const $ = (s, root = document) => root.querySelector(s);
  const $$ = (s, root = document) => [...root.querySelectorAll(s)];

  let csrfToken = '';
  let currentUser = null;
  let studentsCurrentView = localStorage.getItem('nbc_admin_students_view') || 'cards';
  let studentsCurrentData = [];
  let adminReviews = [];

  const escapeHtml = value =>
    String(value ?? '').replace(/[&<>"']/g, c => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[c]));

  async function request(url, options = {}) {
    const headers = {
      Accept: 'application/json',
      ...(options.headers || {})
    };

    if (options.body && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
    }

    const method = (options.method || 'GET').toUpperCase();

    if (
      ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method) &&
      csrfToken
    ) {
      headers['X-CSRF-Token'] = csrfToken;
    }

    const response = await fetch(url, {
      ...options,
      headers,
      credentials: 'same-origin'
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok || data.success === false) {
      throw new Error(
        (data.message || `خطأ ${response.status}`) +
        (data.debug ? ` [${data.debug}]` : '')
      );
    }

    if (data.csrf_token) {
      csrfToken = data.csrf_token;
      try { sessionStorage.setItem('nbc_admin_csrf', csrfToken); } catch (_) {}
    }

    return data;
  }

  function notice(target, message, error = false) {
    const box = $(target);
    if (!box) return;

    box.textContent = message;
    box.className = 'notice' + (error ? ' error' : '');
    box.hidden = false;
  }

  const LS_KEY = 'nbc_admin_last_student_id';

  const lsGet = () => {
    try {
      return Number(localStorage.getItem(LS_KEY) || 0);
    } catch (_) {
      return 0;
    }
  };

  const lsSet = value => {
    try {
      localStorage.setItem(LS_KEY, String(value));
    } catch (_) {}
  };

  const currentLocale = () => document.documentElement.lang === 'en' ? 'en-US' : 'ar-EG';
  const money = value =>
    Number(value ?? 0).toLocaleString(currentLocale(), {
      maximumFractionDigits: 2
    });

  const num = value =>
    Number(value ?? 0).toLocaleString(currentLocale());

  function filterParams() {
    const p = new URLSearchParams();

    const set = (key, value) => {
      if (value) p.set(key, value);
    };

    set('from', $('#fFrom').value);
    set('to', $('#fTo').value);
    set('course_id', $('#fCourse').value);
    set('payment_status', $('#fPay').value);
    set('q', $('#fSearch').value.trim());

    const since = lsGet();

    if (since > 0) {
      p.set('since_id', String(since));
    }

    return p;
  }

  // ==========================================
  // الرسوم البيانية
  // ==========================================

  function drawChart(target, series, isMoney) {
    const box = $(target);
    if (!box) return;

    const values = series?.values || [];
    const labels = series?.labels || [];

    if (!values.length || !values.some(v => v > 0)) {
      box.innerHTML =
        '<div class="chart-empty">لا توجد بيانات مسجلة في هذه الفترة.</div>';
      return;
    }

    const W = 600;
    const H = 200;
    const padL = 12;
    const padR = 12;
    const padT = 18;
    const padB = 26;

    const max = Math.max(...values);
    const innerW = W - padL - padR;
    const innerH = H - padT - padB;

    const x = i =>
      padL + (
        values.length === 1
          ? innerW / 2
          : (i * innerW) / (values.length - 1)
      );

    const y = value =>
      padT + innerH - (max > 0 ? (value / max) * innerH : 0);

    const gradId = 'ch_' + Math.random().toString(36).substring(2, 9);

    let gridLines = '';
    [0.33, 0.66, 1].forEach(ratio => {
      const gy = padT + innerH * (1 - ratio);
      gridLines += `<line class="grid" x1="${padL}" x2="${W - padR}" y1="${gy.toFixed(1)}" y2="${gy.toFixed(1)}" stroke="#f1f5f9" stroke-dasharray="3 3"/>`;
    });

    let body = `
      <defs>
        <linearGradient id="${gradId}_bar" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#14b8a6"/>
          <stop offset="100%" stop-color="#0d9488"/>
        </linearGradient>
        <linearGradient id="${gradId}_area" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#0d9488" stop-opacity="0.25"/>
          <stop offset="100%" stop-color="#0d9488" stop-opacity="0.01"/>
        </linearGradient>
      </defs>
      ${gridLines}
      <line
        class="axis"
        x1="${padL}"
        x2="${W - padR}"
        y1="${padT + innerH}"
        y2="${padT + innerH}"
        stroke="#e2e8f0"
        stroke-width="1.2"
      />
    `;

    if (values.length <= 45) {
      const slot = innerW / values.length;
      const bw = Math.max(3, slot * 0.62);

      values.forEach((value, i) => {
        const h = max > 0 ? (value / max) * innerH : 0;
        const bx = padL + i * slot + (slot - bw) / 2;

        body += `
          <rect
            class="bar"
            fill="url(#${gradId}_bar)"
            x="${bx.toFixed(1)}"
            y="${(padT + innerH - h).toFixed(1)}"
            width="${bw.toFixed(1)}"
            height="${Math.max(h, value > 0 ? 3 : 0).toFixed(1)}"
            rx="3"
            ry="3"
          >
            <title>
              ${escapeHtml(labels[i])}:
              ${escapeHtml(
                isMoney ? money(value) + ' ج.م' : num(value)
              )}
            </title>
          </rect>
        `;
      });
    } else {
      const points = values.map(
        (value, i) =>
          `${x(i).toFixed(1)},${y(value).toFixed(1)}`
      );

      body += `
        <polygon
          class="area"
          fill="url(#${gradId}_area)"
          points="${padL},${padT + innerH} ${points.join(' ')}
          ${W - padR},${padT + innerH}"
        />
        <polyline
          class="line"
          stroke="#0d9488"
          stroke-width="2.5"
          points="${points.join(' ')}"
        />
      `;
    }

    const indices = [
      0,
      Math.floor((values.length - 1) / 2),
      values.length - 1
    ];

    [...new Set(indices)].forEach((i, k, arr) => {
      const anchor =
        k === 0 ? 'start' :
        k === arr.length - 1 ? 'end' :
        'middle';

      body += `
        <text
          class="tick"
          x="${x(i).toFixed(1)}"
          y="${H - 6}"
          text-anchor="${anchor}"
          fill="#94a3b8"
          font-size="10.5"
        >${escapeHtml(labels[i])}</text>
      `;
    });

    body += `
      <text
        class="tick"
        x="${padL}"
        y="13"
        text-anchor="start"
        fill="#0d9488"
        font-weight="700"
        font-size="11"
      >${escapeHtml(isMoney ? money(max) + ' ج.م' : num(max))}</text>
    `;

    box.innerHTML = `
      <svg viewBox="0 0 ${W} ${H}" role="img" aria-label="رسم بياني">
        ${body}
      </svg>
    `;
  }

  const sum = arr =>
    (arr || []).reduce((a, b) => a + Number(b || 0), 0);

  const formatDateTime = value =>
    value
      ? new Date(
          String(value).replace(' ', 'T') + 'Z'
        ).toLocaleString(currentLocale(), {
          dateStyle: 'short',
          timeStyle: 'short'
        })
      : '—';

  const payMethod = method => ({
    manual_transfer: 'تحويل يدوي',
    instapay: 'إنستاباي',
    wallet: 'محفظة',
    card: 'بطاقة',
    bank_transfer: 'تحويل بنكي',
    other: 'أخرى'
  })[method] || method || '—';

  const roleLabel = role => ({
    admin: 'مدير',
    instructor: 'محاضر',
    student: 'طالب',
    support: 'دعم'
  })[role] || role || '—';

  const badge = value => `
    <span class="badge ${escapeHtml(value)}">
      ${escapeHtml(({
        active: 'نشط',
        inactive: 'غير نشط',
        suspended: 'موقوف',
        published: 'منشور',
        draft: 'مسودة',
        archived: 'مؤرشف',
        pending: 'معلّق',
        under_review: 'قيد المراجعة',
        confirmed: 'مؤكد',
        rejected: 'مرفوض',
        refunded: 'مسترد',
        failed: 'فاشل',
        completed: 'مكتمل',
        cancelled: 'ملغي'
      })[value] || value || '—')}
    </span>
  `;

  const formatDate = value =>
    value
      ? new Date(
          String(value).replace(' ', 'T') + 'Z'
        ).toLocaleDateString(currentLocale())
      : '—';

  const emptyTable = text => `
    <div style="padding:20px;color:#718096">
      ${escapeHtml(text)}
    </div>
  `;

  const activityText = activity => {
    const who = escapeHtml(activity.who || '—');
    const subject = escapeHtml(activity.subject || '');

    switch (activity.kind) {
      case 'registered':
        return [
          '✚',
          `تسجيل حساب جديد (${escapeHtml(roleLabel(activity.label))}):
          <strong>${who}</strong>`
        ];

      case 'enrolled':
        return [
          '▤',
          `<strong>${who}</strong> اشترك في
          <strong>${subject}</strong>`
        ];

      case 'payment':
        return [
          'ج.م',
          `معاملة دفع (
            ${escapeHtml(({
              pending: 'معلّقة',
              under_review: 'قيد المراجعة',
              confirmed: 'مؤكدة',
              rejected: 'مرفوضة',
              refunded: 'مستردة',
              failed: 'فاشلة'
            })[activity.label] || activity.label)}
          ) من <strong>${who}</strong> — ${subject}`
        ];

      case 'certificate':
        return [
          '✧',
          `إصدار شهادة لـ <strong>${who}</strong> — ${subject}`
        ];

      default:
        return [
          '⚙',
          `إجراء إداري <strong>${escapeHtml(activity.label)}</strong>
          بواسطة ${who}`
        ];
    }
  };

  // ==========================================
  // لوحة المعلومات الرئيسية
  // ==========================================

  async function loadDashboard() {
    const data = await request(
      'api/admin/dashboard.php?' + filterParams()
    );

    csrfToken = data.csrf_token || csrfToken;

    const counts = data.counts || {};
    const filters = data.filters || {};

    if (!$('#fFrom').value) {
      $('#fFrom').value = filters.from || '';
    }

    if (!$('#fTo').value) {
      $('#fTo').value = filters.to || '';
    }

    const courseFilter = $('#fCourse');
    const keepCourse = courseFilter.value;

    courseFilter.innerHTML =
      '<option value="">كل الكورسات</option>' +
      (data.course_options || []).map(option => `
        <option value="${escapeHtml(option.id)}">
          ${escapeHtml(option.title)}
        </option>
      `).join('');

    courseFilter.value = keepCourse;

    const svgIcons = {
      wallet: '<svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><circle cx="16" cy="15" r="1.5"/></svg>',
      users: '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
      instructor: '<svg viewBox="0 0 24 24"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>',
      book: '<svg viewBox="0 0 24 24"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
      check: '<svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
      coins: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><line x1="12" y1="6" x2="12" y2="8"/><line x1="12" y1="16" x2="12" y2="18"/></svg>',
      calendar: '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
      clock: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
      award: '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>',
      percent: '<svg viewBox="0 0 24 24"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>',
      layers: '<svg viewBox="0 0 24 24"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>'
    };

    const cards = [
      [
        'إجمالي المدفوعات المؤكدة (ج.م)',
        money(counts.confirmed_payments_total_egp) + ' ج.م',
        svgIcons.wallet,
        'إجمالي الإيرادات المؤكدة المحصلة',
        'stat-featured'
      ],
      [
        'إجمالي الطلاب المسجلين',
        num(counts.students),
        svgIcons.users,
        `جدد اليوم: ${num(counts.new_students_today)}  —  7 أيام: ${num(counts.new_students_7d)}  —  30 يومًا: ${num(counts.new_students_30d)}`,
        'stat-teal'
      ],
      [
        'المحاضرون والمدربون',
        num(counts.instructors),
        svgIcons.instructor,
        counts.pending_instructors
          ? `بانتظار الموافقة: ${num(counts.pending_instructors)}`
          : 'هيئة التدريس المعتمدة في المنصة',
        'stat-navy'
      ],
      [
        'الكورسات المنشورة',
        num(counts.published_courses),
        svgIcons.book,
        `من إجمالي ${num(counts.total_courses)} مسار تدريبي`,
        'stat-teal'
      ],
      ['التسجيلات السارية النشطة', num(counts.active_enrollments), svgIcons.check, 'طلاب يدرسون حالياً بالكورسات', 'stat-emerald'],
      ['إيرادات اليوم (ج.م)', money(counts.revenue_today_egp) + ' ج.م', svgIcons.coins, 'إجمالي تحصيلات اليوم', 'stat-gold'],
      ['إيرادات الشهر الحالي (ج.م)', money(counts.revenue_month_egp) + ' ج.م', svgIcons.calendar, 'مبيعات واشتراكات هذا الشهر', 'stat-gold'],
      ['المدفوعات المعلّقة', num(counts.pending_payments), svgIcons.clock, 'تحتاج مراجعة وتأكيد الإدارة', 'stat-amber'],
      ['الشهادات الصادرة', num(counts.certificates), svgIcons.award, 'شهادات تخرج واجتياز معتمدة', 'stat-gold'],
      [
        'نسبة إكمال الكورسات',
        `${Number(counts.completion_rate ?? 0).toLocaleString(currentLocale(), {
          maximumFractionDigits: 1
        })}٪`,
        svgIcons.percent,
        `${num(counts.completed_enrollments)} طالب أتم الكورسات بنجاح`,
        'stat-emerald'
      ],
      ['كل الكورسات المسجلة', num(counts.total_courses), svgIcons.layers, 'تشمل المنشور والمسودات', 'stat-navy']
    ];

    $('#stats').innerHTML = cards.map(
      ([label, value, icon, sub, styleClass]) => `
        <article class="stat ${styleClass || ''}">
          <div class="stat-head">
            <div class="label">${escapeHtml(label)}</div>
            <span class="icon">${icon}</span>
          </div>
          <div class="value">${escapeHtml(value)}</div>
          ${sub ? `<div class="sub">${escapeHtml(sub)}</div>` : ''}
        </article>
      `
    ).join('');

    // التنبيهات
    const alerts = [...(data.alerts || [])];
    const lastSeen = lsGet();

    if (lastSeen === 0) {
      lsSet(counts.max_student_id || 0);
    } else if ((counts.new_students_since || 0) > 0) {
      alerts.unshift({
        type: 'info',
        text: `طلاب جدد منذ آخر زيارة: ${counts.new_students_since}`,
        dismissId: counts.max_student_id
      });
    }

    const alertsBox = $('#alerts');

    alertsBox.hidden = alerts.length === 0;

    alertsBox.innerHTML = alerts.map(alert => `
      <div class="alert ${escapeHtml(alert.type)}">
        <span>${escapeHtml(alert.text)}</span>
        ${alert.dismissId ? `
          <button
            type="button"
            aria-label="إخفاء"
            data-dismiss-id="${escapeHtml(alert.dismissId)}"
          >×</button>
        ` : ''}
      </div>
    `).join('');

    // الرسوم البيانية
    const charts = data.charts || {};

    drawChart('#revenueChart', charts.revenue, true);
    drawChart('#enrollmentsChart', charts.enrollments, false);
    drawChart('#studentsChart', charts.new_students, false);

    $('#revenueTotal').textContent =
      `${money(sum(charts.revenue?.values))} ج.م`;

    $('#enrollmentsTotal').textContent =
      `${num(sum(charts.enrollments?.values))} تسجيل`;

    $('#studentsTotal').textContent =
      `${num(sum(charts.new_students?.values))} طالب`;

    // نسب إكمال الكورسات
    const completion = data.completion_by_course || [];

    $('#completionList').innerHTML = completion.length
      ? completion.map(row => `
          <div>
            <div class="row-head">
              <span>${escapeHtml(row.title)}</span>
              <span>
                ${escapeHtml(Number(row.rate).toLocaleString(currentLocale()))}${document.documentElement.lang === 'en' ? '%' : '٪'}
                (${escapeHtml(num(row.completed))}/${escapeHtml(num(row.enrolled))})
              </span>
            </div>
            <div class="track">
              <div
                class="fill"
                style="width:${Math.min(100, Math.max(0, Number(row.rate)))}%"
              ></div>
            </div>
          </div>
        `).join('')
      : emptyTable('لا توجد تسجيلات كافية لحساب نسب الإكمال.');

    // أحدث التسجيلات
    const enrollments = data.latest_enrollments || [];

    $('#latestEnrollments').innerHTML = enrollments.length
      ? `
        <table>
          <thead>
            <tr>
              <th>الطالب / الكورس</th>
              <th>الحالة</th>
              <th>التاريخ</th>
            </tr>
          </thead>
          <tbody>
            ${enrollments.map(item => `
              <tr>
                <td>
                  ${escapeHtml(item.student_name)}
                  <br>
                  <small>${escapeHtml(item.course_title)}</small>
                </td>
                <td>${badge(item.status)}</td>
                <td>${escapeHtml(formatDateTime(item.enrolled_at))}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `
      : emptyTable('لا توجد تسجيلات مطابقة.');

    // أحدث المعاملات
    const transactions = data.latest_transactions || [];

    $('#latestTransactions').innerHTML = transactions.length
      ? `
        <table>
          <thead>
            <tr>
              <th>الطالب / الكورس</th>
              <th>المبلغ</th>
              <th>الطريقة</th>
              <th>الحالة</th>
              <th>التاريخ</th>
            </tr>
          </thead>
          <tbody>
            ${transactions.map(item => `
              <tr>
                <td>
                  ${escapeHtml(item.student_name)}
                  <br>
                  <small>${escapeHtml(item.course_title)}</small>
                </td>
                <td>
                  ${escapeHtml(money(item.amount))}
                  ${escapeHtml(item.currency)}
                </td>
                <td>${escapeHtml(payMethod(item.payment_method))}</td>
                <td>${badge(item.status)}</td>
                <td>${escapeHtml(formatDateTime(item.created_at))}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `
      : emptyTable('لا توجد معاملات مطابقة.');

    // أحدث الأنشطة
    const activities = data.latest_activities || [];

    $('#latestActivities').innerHTML = activities.length
      ? activities.map(activity => {
          const [icon, text] = activityText(activity);

          return `
            <div class="activity">
              <span class="dot">${escapeHtml(icon)}</span>
              <div>
                ${text}
                <small>${escapeHtml(formatDateTime(activity.at))}</small>
              </div>
            </div>
          `;
        }).join('')
      : emptyTable('لا توجد أنشطة مطابقة.');

    // أحدث المستخدمين
    const users = data.latest_users || [];

    $('#latestUsers').innerHTML = users.length
      ? `
        <table>
          <thead>
            <tr>
              <th>الاسم</th>
              <th>الدور</th>
              <th>الحالة</th>
              <th>تاريخ التسجيل</th>
            </tr>
          </thead>
          <tbody>
            ${users.map(user => `
              <tr>
                <td>
                  ${escapeHtml(user.full_name)}
                  <br>
                  <small>${escapeHtml(user.email)}</small>
                </td>
                <td>${escapeHtml(roleLabel(user.role))}</td>
                <td>${badge(user.status)}</td>
                <td>${escapeHtml(formatDate(user.created_at))}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `
      : emptyTable('لا توجد حسابات بعد.');

    // المدفوعات المعلقة
    const payments = data.pending_payments || [];

    $('#pendingPayments').innerHTML = payments.length
      ? `
        <table>
          <thead>
            <tr>
              <th>الطالب / الكورس</th>
              <th>المبلغ</th>
              <th>الحالة</th>
              <th>التاريخ</th>
            </tr>
          </thead>
          <tbody>
            ${payments.map(item => `
              <tr>
                <td>
                  ${escapeHtml(item.student_name)}
                  <br>
                  <small>${escapeHtml(item.course_title)}</small>
                </td>
                <td>
                  ${escapeHtml(item.amount)}
                  ${escapeHtml(item.currency)}
                </td>
                <td>${badge(item.status)}</td>
                <td>${escapeHtml(formatDate(item.created_at))}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `
      : emptyTable('لا توجد مدفوعات معلّقة.');
  }

  // ==========================================
  // الطلاب
  // ==========================================

  async function loadStudents() {
    const params = new URLSearchParams({
      q: $('#studentSearch').value.trim(),
      status: $('#studentStatus').value,
      page: '1',
      per_page: '50'
    });

    const data = await request(
      'api/admin/students.php?' + params
    );

    const rows = data.data || [];

    $('#studentsTable').innerHTML = rows.length
      ? `
        <table>
          <thead>
            <tr>
              <th>الطالب</th>
              <th>السن / النوع</th>
              <th>الهاتف</th>
              <th>كود التفعيل</th>
              <th>الحالة</th>
              <th>التسجيل</th>
              <th>إجراء</th>
            </tr>
          </thead>
          <tbody>
            ${rows.map(user => `
              <tr>
                <td>
                  <a
                    href="#"
                    class="student-name-link"
                    data-view-student="${escapeHtml(user.id)}"
                    style="font-weight:700;text-decoration:none;color:var(--primary,#0f766e)"
                    aria-label="عرض تفاصيل ${escapeHtml(user.full_name)}"
                  >${escapeHtml(user.full_name)}</a>
                  <br>
                  <small>${escapeHtml(user.email)}</small>
                </td>

                <td>
                  <span>${user.age ? escapeHtml(user.age) + ' سنة' : '—'}</span> / 
                  <small>${user.gender === 'male' ? 'ذكر' : (user.gender === 'female' ? 'أنثى' : '—')}</small>
                </td>

                <td>${escapeHtml(user.phone || '—')}</td>
                <td>
                  ${user.is_verified ? '<span class="status-badge active" style="font-size:11px">مؤكد ✅</span>' : 
                    `<span class="status-badge" style="background:#fef3c7;color:#92400e;font-size:12px;font-family:monospace;font-weight:bold">${escapeHtml(user.verification_code || 'معلق')}</span>`}
                </td>
                <td>${badge(user.status)}</td>
                <td>${escapeHtml(formatDate(user.created_at))}</td>

                <td>
                  <button
                    type="button"
                    class="small-btn"
                    data-view-student="${escapeHtml(user.id)}"
                  >👁 عرض</button>

                  <button
                    type="button"
                    class="small-btn ${user.status === 'active' ? 'danger' : ''}"
                    data-student-status="${escapeHtml(user.id)}"
                    data-next-status="${user.status === 'active' ? 'suspended' : 'active'}"
                  >${user.status === 'active' ? 'إيقاف' : 'تفعيل'}</button>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `
      : emptyTable('لا توجد نتائج مطابقة.');

    // Fire event for cards view
    studentsCurrentData = rows;
    document.dispatchEvent(new CustomEvent('nbc:students:loaded', { detail: rows }));
    applyStudentsView();
  }

  // فتح التفاصيل داخل لوحة الإدارة دون iframe
  async function openStudentDetails(studentId) {
    const id = Number(studentId);

    if (!Number.isInteger(id) || id <= 0) {
      notice('#globalMessage', 'رقم الطالب غير صحيح.', true);
      return;
    }

    const listView = $('#studentsListView');
    const detailsView = $('#studentDetailsContainer');
    const addStudentForm = $('#studentForm');
    const message = $('#studentDetailsMessage');
    const content = $('#studentDetailsContent');

    if (!detailsView || !content) {
      notice(
        '#globalMessage',
        'تعذر العثور على واجهة تفاصيل الطالب. تأكد من تحديث admin.html.',
        true
      );
      return;
    }

    if (listView) listView.hidden = true;
    if (addStudentForm) addStudentForm.hidden = true;

    detailsView.hidden = false;

    if (message) {
      message.hidden = true;
      message.textContent = '';
    }

    content.innerHTML = '<div class="loading">جارٍ تحميل بيانات الطالب...</div>';

    detailsView.scrollIntoView({
      behavior: 'smooth',
      block: 'start'
    });

    try {
      if (
        !window.StudentDetails ||
        typeof window.StudentDetails.open !== 'function'
      ) {
        throw new Error(
          'ملف تفاصيل الطالب غير جاهز. تأكد من تحميل asset/js/student-details.js.'
        );
      }

      await window.StudentDetails.open(id);
    } catch (error) {
      content.innerHTML = `
        <div class="detail-empty error-text">
          ${escapeHtml(error.message || 'تعذر تحميل تفاصيل الطالب.')}
        </div>
      `;
    }
  }

  function closeStudentDetails() {
    const listView = $('#studentsListView');
    const detailsView = $('#studentDetailsContainer');

    if (detailsView) detailsView.hidden = true;
    if (listView) listView.hidden = false;

    const editCard = $('#studentDetailsEditCard');
    const editForm = $('#studentDetailsEditForm');

    if (editCard) editCard.hidden = true;
    if (editForm) editForm.reset();

    if (
      window.StudentDetails &&
      typeof window.StudentDetails.close === 'function'
    ) {
      window.StudentDetails.close();
    }

    listView?.scrollIntoView({
      behavior: 'smooth',
      block: 'start'
    });
  }

  // ==========================================
  // الكورسات والمحاضرون
  // ==========================================

  async function loadCourses() {
    const params = new URLSearchParams({
      q: $('#courseSearch').value.trim(),
      status: $('#courseStatus').value
    });

    const data = await request(
      'api/admin/courses.php?' + params
    );

    const rows = data.data || [];

    $('#coursesTable').innerHTML = rows.length
      ? `
        <table>
          <thead>
            <tr>
              <th>الكورس</th>
              <th>المحاضر</th>
              <th>السعر</th>
              <th>الطلاب النشطون</th>
              <th>الحالة</th>
              <th>إجراءات</th>
            </tr>
          </thead>
          <tbody>
            ${rows.map(course => `
              <tr>
                <td>
                  ${escapeHtml(course.title)}
                  <br>
                  <small dir="ltr">${escapeHtml(course.slug)}</small>
                </td>
                <td>${escapeHtml(course.instructor_name)}</td>
                <td>${escapeHtml(course.price)} ج.م</td>
                <td>${escapeHtml(course.active_students)}</td>
                <td>${badge(course.status)}</td>
                <td>
                  <button
                    class="small-btn"
                    data-edit-course="${escapeHtml(course.id)}"
                  >تعديل</button>

                  <button
                    class="small-btn"
                    data-course-status="${escapeHtml(course.id)}"
                    data-next-status="${course.status === 'published' ? 'draft' : 'published'}"
                  >${course.status === 'published' ? 'إلغاء النشر' : 'نشر'}</button>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `
      : emptyTable('لا توجد كورسات. يمكنك إنشاء أول كورس.');

    window.__coursesCache = rows;
  }

  async function loadInstructors() {
    const data = await request(
      'api/admin/courses.php?instructors=1'
    );

    $('#courseInstructor').innerHTML =
      '<option value="">اختر المحاضر</option>' +
      (data.data || []).map(instructor => `
        <option value="${escapeHtml(instructor.id)}">
          ${escapeHtml(instructor.full_name)}
        </option>
      `).join('');
  }

  // ==========================================
  // التنقل بين أقسام لوحة الإدارة
  // ==========================================

  const TAB_NAMES = {
    centers: 'السناتر والحجز الأوفلاين',
    overview: 'نظرة عامة',
    students: 'إدارة الطلاب',
    instructors: 'إدارة المحاضرين',
    courses: 'الكورسات والمنهج',
    settings: 'إعدادات المنصة والمظهر',
    'admin-profile': 'ملف حساب المدير',
    enrollments: 'إدارة التسجيلات',
    payments: 'المدفوعات والطلبات المالية',
    quizzes: 'الاختبارات وبنك الأسئلة',
    certificates: 'الشهادات الإلكترونية',
    skills: 'مكتبة المهارات التمريضية',
    notifications: 'مركز الإشعارات',
    support: 'الدعم الفني',
    reports: 'التقارير والإحصائيات',
      progress: 'تقدم الطلاب',
      reviews: 'تقييمات وآراء الطلاب'
  };

  function switchTab(name) {
    const changedTab = document.querySelector('.side-link.tab.active')?.dataset.tab !== name;
    if (changedTab) window.DashboardEffects?.show();
    $$('.tab').forEach(button => {
      button.classList.toggle('active', button.dataset.tab === name);
    });

    const breadcrumb = $('#breadcrumbCurrent');
    if (breadcrumb && TAB_NAMES[name]) {
      breadcrumb.textContent = TAB_NAMES[name];
    }

    // إغلاق الشريط الجانبي للشاشات الصغيرة عند الضغط على أي قسم
    $('#adminSidebar')?.classList.remove('mobile-open');
    $('#sidebarBackdrop')?.classList.remove('mobile-open');

    const panelIdMap = {
      centers: 'centersPanel',
      overview: 'overviewPanel',
      students: 'studentsPanel',
      instructors: 'instructorsPanel',
      courses: 'coursesPanel',
      settings: 'settingsPanel',
      'admin-profile': 'adminProfilePanel',
      enrollments: 'enrollmentsPanel',
      payments: 'paymentsPanel',
      quizzes: 'quizzesPanel',
      certificates: 'certificatesPanel',
      skills: 'skillsPanel',
      notifications: 'notificationsPanel',
      support: 'supportPanel',
      reports: 'reportsPanel',
      progress: 'progressPanel',
      reviews: 'reviewsPanel'
    };

    Object.keys(panelIdMap).forEach(tab => {
      const panel = $('#' + panelIdMap[tab]);
      if (panel) panel.hidden = tab !== name;
    });
    if (changedTab) window.DashboardEffects?.enter($('#' + (panelIdMap[name] || `${name}Panel`)));

    if (name === 'centers') {
      window.OfflineCentersAdmin?.load();
    }
    if (name === 'reviews') loadAdminReviews();

    if (name === 'settings') {
      if (typeof loadSettings === 'function') loadSettings();
      window.HomeSliderAdmin?.load();
      window.FounderPhotosAdmin?.load();
    }

    if (name === 'admin-profile') {
      if (typeof loadAdminProfile === 'function') loadAdminProfile();
    }

    if (name === 'students') {
      // عند الرجوع إلى قسم الطلاب نعرض القائمة افتراضيًا.
      closeStudentDetails();

      loadStudents().catch(error => {
        notice('#globalMessage', error.message, true);
      });
    }

    if (name === 'instructors') {
      window.InstructorsAdmin?.load();
    }

    if (name === 'courses') {
      Promise.all([
        loadCourses(),
        loadInstructors()
      ]).catch(error => {
        notice('#globalMessage', error.message, true);
      });
    }

    // Call feature panel loaders if available
    if (name === 'enrollments' && typeof window.loadEnrollments === 'function') window.loadEnrollments();
    if (name === 'payments' && typeof window.loadPayments === 'function') window.loadPayments();
    if (name === 'quizzes' && typeof window.initQuizzes === 'function') window.initQuizzes();
    if (name === 'certificates' && typeof window.loadCertificates === 'function') window.loadCertificates();
    if (name === 'skills' && typeof window.loadSkills === 'function') window.loadSkills();
    if (name === 'notifications' && typeof window.loadNotifications === 'function') window.loadNotifications();
    if (name === 'support' && typeof window.loadSupport === 'function') window.loadSupport();
    if (name === 'reports' && typeof window.loadReports === 'function') window.loadReports();
    if (name === 'progress' && typeof window.loadProgressOverview === 'function') window.loadProgressOverview();
  }

  function reviewText(ar, en) { return adminLang === 'en' ? en : ar; }

  async function loadAdminReviews() {
    const list = $('#reviewsList');
    if (list) list.innerHTML = `<div class="admin-review-empty">${reviewText('جارٍ تحميل التقييمات...', 'Loading reviews...')}</div>`;
    try {
      const result = await request('api/admin/reviews.php?action=list');
      adminReviews = Array.isArray(result.reviews) ? result.reviews : [];
      renderAdminReviews();
    } catch (error) {
      if (list) list.innerHTML = `<div class="admin-review-empty is-error">${escapeHtml(error.message || reviewText('تعذر تحميل التقييمات.', 'Could not load reviews.'))}</div>`;
    }
  }

  function renderAdminReviews() {
    const stats = $('#reviewsStats');
    const list = $('#reviewsList');
    if (!list) return;
    const pending = adminReviews.filter(review => review.status === 'pending').length;
    const approved = adminReviews.filter(review => review.status === 'approved').length;
    const rejected = adminReviews.filter(review => review.status === 'rejected').length;
    const approvedReviews = adminReviews.filter(review => review.status === 'approved');
    const average = approvedReviews.length
      ? (approvedReviews.reduce((sum, review) => sum + Number(review.rating || 0), 0) / approvedReviews.length).toFixed(1)
      : '—';

    if (stats) {
      const statCard = (label, value, modifier, icon) => `<div class="admin-review-stat ${modifier}"><span class="admin-review-stat-icon" aria-hidden="true">${icon}</span><div><span>${label}</span><strong>${value}</strong></div></div>`;
      stats.innerHTML = [
        statCard(reviewText('كل التقييمات', 'All reviews'), adminReviews.length, 'total', '▤'),
        statCard(reviewText('بانتظار المراجعة', 'Pending review'), pending, 'pending', '◷'),
        statCard(reviewText('معتمد', 'Approved'), approved, 'approved', '✓'),
        statCard(reviewText('متوسط المعتمد', 'Approved average'), average, 'average', '★')
      ].join('');
    }

    const statusFilter = $('#reviewsStatusFilter')?.value || '';
    const typeFilter = $('#reviewsTypeFilter')?.value || '';
    const query = ($('#reviewsSearch')?.value || '').trim().toLocaleLowerCase();
    const visible = adminReviews.filter(review => {
      if (statusFilter && review.status !== statusFilter) return false;
      if (typeFilter && review.item_type !== typeFilter) return false;
      if (!query) return true;
      return [review.student_name, review.item_title, review.comment, review.item_type]
        .some(value => String(value || '').toLocaleLowerCase().includes(query));
    });

    if (!visible.length) {
      list.innerHTML = `<div class="admin-review-empty"><span aria-hidden="true">☷</span><strong>${reviewText('لا توجد تقييمات مطابقة', 'No matching reviews')}</strong><small>${reviewText('جرّب تغيير الفلاتر أو البحث.', 'Try changing the filters or search terms.')}</small></div>`;
      return;
    }

    const statusInfo = {
      pending: { ar: 'بانتظار المراجعة', en: 'Pending', css: 'pending' },
      approved: { ar: 'معتمد', en: 'Approved', css: 'approved' },
      rejected: { ar: 'مرفوض', en: 'Rejected', css: 'rejected' }
    };
    list.innerHTML = visible.map(review => {
      const status = statusInfo[review.status] || statusInfo.pending;
      const rating = Math.max(0, Math.min(5, Number(review.rating) || 0));
      const stars = `${'★'.repeat(rating)}${'☆'.repeat(5 - rating)}`;
      const itemType = review.item_type === 'book'
        ? reviewText('كتاب', 'Book')
        : reviewText('كورس', 'Course');
      const title = escapeHtml(review.item_title || reviewText('محتوى غير متاح', 'Content unavailable'));
      const student = escapeHtml(review.student_name || reviewText('طالب', 'Student'));
      const avatarPath = String(review.student_avatar || '');
      const safeAvatar = /^(https?:\/\/|\/?uploads\/)/i.test(avatarPath)
        ? `<img class="admin-review-avatar" src="${escapeHtml(avatarPath)}" alt="" loading="lazy">`
        : `<span class="admin-review-avatar admin-review-avatar-fallback" aria-hidden="true">${escapeHtml((review.student_name || 'ط').trim().charAt(0))}</span>`;
      const dateValue = review.created_at ? new Date(String(review.created_at).replace(' ', 'T')) : null;
      const date = dateValue && !Number.isNaN(dateValue.getTime())
        ? new Intl.DateTimeFormat(adminLang === 'en' ? 'en-US' : 'ar-EG', { dateStyle: 'medium', timeStyle: 'short' }).format(dateValue)
        : '—';
      const approveButton = review.status !== 'approved'
        ? `<button type="button" class="btn btn-primary btn-sm" data-review-action="approved" data-review-id="${Number(review.id)}">${reviewText('اعتماد', 'Approve')}</button>`
        : '';
      const rejectButton = review.status !== 'rejected'
        ? `<button type="button" class="btn btn-outline btn-sm review-reject-btn" data-review-action="rejected" data-review-id="${Number(review.id)}">${reviewText('رفض', 'Reject')}</button>`
        : '';
      return `<article class="admin-review-card">
        <div class="admin-review-card-top"><span class="admin-review-type">${itemType}</span><span class="admin-review-status ${status.css}">${adminLang === 'en' ? status.en : status.ar}</span></div>
        <div class="admin-review-product"><strong>${title}</strong><time datetime="${escapeHtml(review.created_at || '')}">${escapeHtml(date)}</time></div>
        <div class="admin-review-student">${safeAvatar}<strong>${student}</strong><span class="admin-review-rating" aria-label="${rating} / 5">${stars}<b>${rating}.0</b></span></div>
        <blockquote class="admin-review-comment">${escapeHtml(review.comment || reviewText('بدون تعليق نصي', 'No written comment'))}</blockquote>
        <div class="admin-review-actions">${approveButton}${rejectButton}<button type="button" class="btn btn-ghost btn-sm review-delete-btn" data-review-action="delete" data-review-id="${Number(review.id)}">${reviewText('حذف', 'Delete')}</button></div>
      </article>`;
    }).join('');
  }

  async function updateAdminReview(id, action) {
    if (action === 'delete' && !window.confirm(reviewText('هل تريد حذف هذا التقييم نهائيًا؟', 'Permanently delete this review?'))) return;
    const message = $('#reviewsMessage');
    if (message) message.hidden = true;
    try {
      const result = action === 'delete'
        ? await request('api/admin/reviews.php?action=delete', { method: 'POST', body: JSON.stringify({ id }) })
        : await request('api/admin/reviews.php?action=update_status', { method: 'POST', body: JSON.stringify({ id, status: action }) });
      if (message) notice('#reviewsMessage', result.message || reviewText('تم تحديث التقييم.', 'Review updated.'));
      await loadAdminReviews();
      if (message) message.hidden = false;
    } catch (error) {
      if (message) notice('#reviewsMessage', error.message || reviewText('تعذر تحديث التقييم.', 'Could not update the review.'), true);
    }
  }

  async function enterApp(user) {
    currentUser = user;

    $('#loginView').hidden = true;
    $('#appView').hidden = false;
    $('#logout').hidden = false;

    $('#who').textContent = user?.full_name
      ? `مرحبًا، ${user.full_name}`
      : 'الإدارة';

    const greeting = $('#welcomeGreeting');
    if (greeting && user?.full_name) {
      greeting.textContent = `مرحباً بك، د. ${user.full_name} 🩺`;
    }

    const todayMeta = $('#welcomeMeta');
    if (todayMeta) {
      const now = new Date();
      const dateStr = now.toLocaleDateString(currentLocale(), {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
      });
      todayMeta.textContent = `${dateStr}  —  بيانات حية ومحدثة من قاعدة البيانات الأكاديمية.`;
    }

    try {
      await loadDashboard();
    } catch (error) {
      notice(
        '#globalMessage',
        error.message || 'تعذر تحميل لوحة الإدارة.',
        true
      );
    }
  }

  // ==========================================
  // تسجيل الدخول والخروج
  // ==========================================

  $('#loginForm').addEventListener('submit', async event => {
    event.preventDefault();

    const button = $('button[type="submit"]', event.currentTarget);
    button.disabled = true;

    try {
      const form = new FormData(event.currentTarget);

      const result = await request('api/admin/login.php', {
        method: 'POST',
        body: JSON.stringify({
          email: form.get('email'),
          password: form.get('password')
        })
      });

      if (result.user?.role !== 'admin') {
        await request('api/admin/logout.php', {
          method: 'POST',
          body: JSON.stringify({})
        }).catch(() => {});

        throw new Error(
          'الحساب ده مش حساب إدارة. استخدم حساب Admin معتمد.'
        );
      }

      notice('#loginMessage', 'تم تسجيل الدخول بنجاح.');
      await enterApp(result.user);
    } catch (error) {
      notice('#loginMessage', error.message, true);
    } finally {
      button.disabled = false;
    }
  });

  $('#logout').addEventListener('click', async () => {
    try {
      await request('api/admin/logout.php', {
        method: 'POST',
        body: JSON.stringify({})
      });
    } catch (_) {}

    window.location.href = 'auth/login.php';
  });

  // ==========================================
  // أحداث عامة
  // ==========================================

  $('#refresh').addEventListener('click', () => {
    loadDashboard()
      .then(() => {
        notice('#globalMessage', 'تم تحديث بيانات قاعدة البيانات.');
      })
      .catch(error => {
        notice('#globalMessage', error.message, true);
      });
  });

  $$('.tab').forEach(button => {
    button.addEventListener('click', () => {
      switchTab(button.dataset.tab);
    });
  });

  $('#searchStudents').addEventListener('click', () => {
    loadStudents().catch(error => {
      notice('#globalMessage', error.message, true);
    });
  });

  $('#searchCourses').addEventListener('click', () => {
    loadCourses().catch(error => {
      notice('#globalMessage', error.message, true);
    });
  });

  $('#studentSearch').addEventListener('keydown', event => {
    if (event.key === 'Enter') {
      event.preventDefault();

      loadStudents().catch(error => {
        notice('#globalMessage', error.message, true);
      });
    }
  });

  $('#courseSearch').addEventListener('keydown', event => {
    if (event.key === 'Enter') {
      event.preventDefault();

      loadCourses().catch(error => {
        notice('#globalMessage', error.message, true);
      });
    }
  });

  // ==========================================
  // الضغط على اسم الطالب أو زر العرض
  // ==========================================

  $('#studentsListView')?.addEventListener('click', async event => {
    const viewButton = event.target.closest('[data-view-student]');
    const statusButton = event.target.closest('[data-student-status]');

    if (viewButton) {
      event.preventDefault();

      await openStudentDetails(
        viewButton.dataset.viewStudent
      );

      return;
    }

    if (!statusButton) return;

    statusButton.disabled = true;

    try {
      await request('api/admin/students.php', {
        method: 'POST',
        body: JSON.stringify({
          action: 'status',
          id: Number(statusButton.dataset.studentStatus),
          status: statusButton.dataset.nextStatus
        })
      });

      await loadStudents();

      notice(
        '#globalMessage',
        'تم تحديث حالة الطالب دون حذف بياناته.'
      );
    } catch (error) {
      notice('#globalMessage', error.message, true);
    } finally {
      statusButton.disabled = false;
    }
  });

  // زر الرجوع لقائمة الطلاب
  $('#backToStudents')?.addEventListener('click', () => {
    closeStudentDetails();
  });

  // ==========================================
  // إضافة طالب
  // ==========================================

  $('#addStudentBtn').addEventListener('click', () => {
    const form = $('#studentForm');

    form.hidden = false;
    form.scrollIntoView({
      behavior: 'smooth',
      block: 'center'
    });
  });

  $('#cancelStudent').addEventListener('click', () => {
    $('#studentForm').hidden = true;
  });

  $('#studentForm').addEventListener('submit', async event => {
    event.preventDefault();

    const form = event.currentTarget;
    const formData = new FormData(form);

    try {
      await request('api/admin/students.php', {
        method: 'POST',
        body: JSON.stringify({
          action: 'create',
          full_name: formData.get('full_name'),
          email: formData.get('email'),
          phone: formData.get('phone'),
          password: formData.get('password')
        })
      });

      form.reset();
      form.hidden = true;

      await loadStudents();

      notice('#globalMessage', 'تم إنشاء حساب الطالب.');
    } catch (error) {
      notice('#globalMessage', error.message, true);
    }
  });

  // ==========================================
  // إضافة وتعديل الكورسات
  // ==========================================

  $('#addCourseBtn').addEventListener('click', () => {
    const form = $('#courseForm');

    form.reset();
    $('input[name="id"]', form).value = '';
    $('#courseFormTitle').textContent = 'إنشاء كورس';

    form.hidden = false;
    form.scrollIntoView({
      behavior: 'smooth',
      block: 'center'
    });
  });

  $('#cancelCourse').addEventListener('click', () => {
    $('#courseForm').hidden = true;
  });

  $('#courseForm').addEventListener('submit', async event => {
    event.preventDefault();

    const form = event.currentTarget;
    const formData = new FormData(form);
    const id = formData.get('id');

    const payload = {
      action: id ? 'update' : 'create',
      id: id ? Number(id) : undefined,
      title: formData.get('title'),
      slug: formData.get('slug'),
      instructor_id: Number(formData.get('instructor_id')),
      category: formData.get('category'),
      level: formData.get('level'),
      price: Number(formData.get('price')),
      status: formData.get('status'),
      description: formData.get('description')
    };

    try {
      await request('api/admin/courses.php', {
        method: 'POST',
        body: JSON.stringify(payload)
      });

      form.hidden = true;

      await loadCourses();

      notice('#globalMessage', 'تم حفظ بيانات الكورس.');
    } catch (error) {
      notice('#globalMessage', error.message, true);
    }
  });

  $('#coursesTable').addEventListener('click', async event => {
    const editButton = event.target.closest('[data-edit-course]');

    if (editButton) {
      const course = (window.__coursesCache || []).find(
        item => Number(item.id) === Number(editButton.dataset.editCourse)
      );

      if (!course) return;

      const form = $('#courseForm');

      form.reset();
      $('input[name="id"]', form).value = course.id;

      for (const key of [
        'title',
        'slug',
        'category',
        'level',
        'price',
        'status',
        'description',
        'instructor_id'
      ]) {
        const input = $(`[name="${key}"]`, form);

        if (input) {
          input.value = course[key] ?? '';
        }
      }

      $('#courseFormTitle').textContent = 'تعديل الكورس';

      form.hidden = false;
      form.scrollIntoView({
        behavior: 'smooth',
        block: 'center'
      });

      return;
    }

    const statusButton = event.target.closest('[data-course-status]');

    if (!statusButton) return;

    try {
      await request('api/admin/courses.php', {
        method: 'POST',
        body: JSON.stringify({
          action: 'status',
          id: Number(statusButton.dataset.courseStatus),
          status: statusButton.dataset.nextStatus
        })
      });

      await loadCourses();

      notice('#globalMessage', 'تم تحديث حالة الكورس.');
    } catch (error) {
      notice('#globalMessage', error.message, true);
    }
  });

  // ==========================================
  // فلاتر لوحة المعلومات والتنبيهات
  // ==========================================

  const reloadDash = () => {
    loadDashboard().catch(error => {
      notice('#globalMessage', error.message, true);
    });
  };

  $('#applyFilters').addEventListener('click', reloadDash);

  $('#fSearch').addEventListener('keydown', event => {
    if (event.key === 'Enter') {
      event.preventDefault();
      reloadDash();
    }
  });

  ['#fCourse', '#fPay'].forEach(selector => {
    $(selector).addEventListener('change', reloadDash);
  });

  $('#resetFilters').addEventListener('click', () => {
    ['#fFrom', '#fTo', '#fCourse', '#fPay', '#fSearch'].forEach(selector => {
      $(selector).value = '';
    });

    reloadDash();
  });

  $('#alerts').addEventListener('click', event => {
    const button = event.target.closest('[data-dismiss-id]');

    if (!button) return;

    lsSet(Number(button.dataset.dismissId) || 0);
    button.closest('.alert').remove();

    if (!$('#alerts').children.length) {
      $('#alerts').hidden = true;
    }
  });

  // الاختصارات السريعة
  $$('[data-quick]').forEach(button => {
    button.addEventListener('click', () => {
      const key = button.dataset.quick;

      if (key === 'instructor') {
        switchTab('instructors');

        setTimeout(() => {
          $('#insAddBtn').click();
        }, 60);

        return;
      }

      switchTab(key === 'course' ? 'courses' : 'students');

      setTimeout(() => {
        $(key === 'course' ? '#addCourseBtn' : '#addStudentBtn').click();
      }, 60);
    });
  });

  // ==========================================
  // إضافة محاضر
  // ==========================================

  $('#cancelInstructor').addEventListener('click', () => {
    $('#instructorForm').hidden = true;
  });

  $('#instructorForm').addEventListener('submit', async event => {
    event.preventDefault();

    const formData = new FormData(event.currentTarget);
    const form = event.currentTarget;

    try {
      await request('api/admin/instructors.php', {
        method: 'POST',
        body: JSON.stringify({
          action: 'create',
          full_name: formData.get('full_name'),
          email: formData.get('email'),
          phone: formData.get('phone'),
          password: formData.get('password')
        })
      });

      form.reset();
      form.hidden = true;

      await loadDashboard();

      notice('#globalMessage', 'تم إنشاء حساب المحاضر.');
    } catch (error) {
      notice('#globalMessage', error.message, true);
    }
  });

  // ==========================================
  // القائمة الجانبية للشاشات الصغيرة وروابط الهاش
  // ==========================================

  const sidebarToggle = $('#sidebarToggle');
  const adminSidebar = $('#adminSidebar');
  const sidebarBackdrop = $('#sidebarBackdrop');

  if (sidebarToggle && adminSidebar) {
    sidebarToggle.addEventListener('click', () => {
      adminSidebar.classList.toggle('mobile-open');
      sidebarBackdrop?.classList.toggle('mobile-open');
    });
  }

  if (sidebarBackdrop && adminSidebar) {
    sidebarBackdrop.addEventListener('click', () => {
      adminSidebar.classList.remove('mobile-open');
      sidebarBackdrop.classList.remove('mobile-open');
    });
  }

  if (location.hash === '#students') {
    const openStudents = () => switchTab('students');
    if (currentUser) openStudents();
    else window.addEventListener('load', () => setTimeout(() => {
      if (!$('#appView').hidden) openStudents();
    }, 150));
  }

  if (location.hash === '#instructors') {
    const openInstructors = () => switchTab('instructors');
    if (currentUser) openInstructors();
    else window.addEventListener('load', () => setTimeout(() => {
      if (!$('#appView').hidden) openInstructors();
    }, 150));
  }

  // ==========================================
  // الوضع الليلي (Dark Mode)
  // ==========================================

  function initTheme() {
    const THEME_KEY = 'nbc_admin_theme';
    const root = document.documentElement;
    const toggleButtons = document.querySelectorAll('#themeToggle, #loginThemeToggle, .theme-toggle-btn');

    const applyTheme = (theme) => {
      const isDark = theme === 'dark';
      root.setAttribute('data-theme', theme);
      root.style.removeProperty('background-color');
      if (document.body) document.body.style.removeProperty('background-color');

      try {
        localStorage.setItem(THEME_KEY, theme);
        localStorage.setItem('nbc_theme', theme);
      } catch (_) {}

      toggleButtons.forEach(btn => {
        const moonIcon = btn.querySelector('.theme-icon-moon');
        const sunIcon = btn.querySelector('.theme-icon-sun');
        const label = btn.querySelector('.theme-btn-label');
        if (moonIcon) moonIcon.hidden = isDark;
        if (sunIcon) sunIcon.hidden = !isDark;
        if (label) label.textContent = isDark ? 'الوضع النهاري' : 'الوضع الليلي';
        btn.setAttribute('title', isDark ? 'التبديل إلى الوضع النهاري' : 'التبديل إلى الوضع الليلي');
        btn.setAttribute('aria-label', isDark ? 'التبديل إلى الوضع النهاري' : 'التبديل إلى الوضع الليلي');
      });
    };

    const saved = localStorage.getItem('nbc_theme') || localStorage.getItem(THEME_KEY);
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const initialTheme = saved || (root.getAttribute('data-theme') || (prefersDark ? 'dark' : 'light'));
    applyTheme(initialTheme);

    toggleButtons.forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const active = root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        const next = active === 'dark' ? 'light' : 'dark';
        applyTheme(next);
      });
    });

    window.addEventListener('storage', event => {
      if (event.key === 'nbc_theme' && (event.newValue === 'dark' || event.newValue === 'light')) applyTheme(event.newValue);
    });

    if (window.matchMedia) {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
        if (!localStorage.getItem(THEME_KEY)) {
          applyTheme(e.matches ? 'dark' : 'light');
        }
      });
    }
  }

  initTheme();

  // ==========================================
  // شريط البحث الشامل (Global Search)
  // ==========================================

  function initGlobalSearch() {
    const searchInput = $('#globalSearchInput');
    const dropdown = $('#searchDropdown');
    const container = $('#topbarSearch');
    if (!searchInput) return;

    const showDropdown = () => {
      if (dropdown) dropdown.hidden = false;
    };

    const hideDropdown = () => {
      if (dropdown) dropdown.hidden = true;
    };

    // اختصار لوحة المفاتيح Ctrl+K أو Cmd+K
    window.addEventListener('keydown', event => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        searchInput.focus();
        searchInput.select();
        showDropdown();
      }

      if (event.key === 'Escape') {
        hideDropdown();
      }
    });

    searchInput.addEventListener('focus', showDropdown);
    searchInput.addEventListener('input', () => {
      showDropdown();
    });

    // التنفيذ الفوري عند الضغط على Enter
    searchInput.addEventListener('keydown', event => {
      if (event.key === 'Enter') {
        event.preventDefault();
        const query = searchInput.value.trim();
        hideDropdown();

        const activeTab = $('.tab.active')?.dataset.tab || 'overview';

        if (activeTab === 'overview') {
          const fSearch = $('#fSearch');
          if (fSearch) fSearch.value = query;
          reloadDash();
        } else if (activeTab === 'students') {
          const sSearch = $('#studentSearch');
          if (sSearch) sSearch.value = query;
          loadStudents().catch(err => notice('#globalMessage', err.message, true));
        } else if (activeTab === 'courses') {
          const cSearch = $('#courseSearch');
          if (cSearch) cSearch.value = query;
          loadCourses().catch(err => notice('#globalMessage', err.message, true));
        } else if (activeTab === 'instructors') {
          const iSearch = $('#insSearch');
          if (iSearch) iSearch.value = query;
          window.InstructorsAdmin?.load();
        }
      }
    });

    // الضغط على أحد خيارات البحث السريع
    dropdown?.addEventListener('click', event => {
      const btn = event.target.closest('[data-search-tab]');
      if (!btn) return;

      const tab = btn.dataset.searchTab;
      const query = searchInput.value.trim();
      hideDropdown();

      switchTab(tab);

      if (query) {
        if (tab === 'overview') {
          const fSearch = $('#fSearch');
          if (fSearch) fSearch.value = query;
          reloadDash();
        } else if (tab === 'students') {
          const sSearch = $('#studentSearch');
          if (sSearch) sSearch.value = query;
          setTimeout(() => {
            loadStudents().catch(err => notice('#globalMessage', err.message, true));
          }, 80);
        } else if (tab === 'courses') {
          const cSearch = $('#courseSearch');
          if (cSearch) cSearch.value = query;
          setTimeout(() => {
            loadCourses().catch(err => notice('#globalMessage', err.message, true));
          }, 80);
        } else if (tab === 'instructors') {
          const iSearch = $('#insSearch');
          if (iSearch) iSearch.value = query;
          setTimeout(() => {
            window.InstructorsAdmin?.load();
          }, 80);
        }
      }
    });

    // إغلاق القائمة عند النقر في أي مكان خارج الصندوق
    document.addEventListener('click', event => {
      if (!container?.contains(event.target)) {
        hideDropdown();
      }
    });
  }

  initGlobalSearch();

  // واجهة مشتركة للملفات الإضافية (المحاضرون ...)
  window.AdminCore = {
    request,
    notice,
    escapeHtml,
    badge,
    formatDate,
    formatDateTime,
    num,
    csrf: () => csrfToken || sessionStorage.getItem('nbc_admin_csrf') || ''
  };

  // ==========================================
  // استعادة جلسة الإدارة
  // ==========================================

  (async () => {
    try {
      const me = await request('api/admin/me.php');

      if (me.user?.role === 'admin') {
        await enterApp({
          id: me.user.id,
          full_name: me.user.name,
          email: me.user.email,
          role: me.user.role
        });
      }
    } catch (_) {
      // لا توجد جلسة إدارة مسجلة.
    }
  })();

  // ==========================================
  // Student Cards View Toggle
  // ==========================================

  function applyStudentsView() {
    const tableEl = $('#studentsTable');
    const cardsEl = $('#studentsCardsGrid');
    if (!tableEl || !cardsEl) return;
    if (studentsCurrentView === 'cards') {
      tableEl.hidden = true;
      cardsEl.hidden = false;
      $('#viewTableBtn')?.classList.remove('active');
      $('#viewCardsBtn')?.classList.add('active');
      renderStudentCards(studentsCurrentData);
    } else {
      tableEl.hidden = false;
      cardsEl.hidden = true;
      $('#viewTableBtn')?.classList.add('active');
      $('#viewCardsBtn')?.classList.remove('active');
    }
  }

  function renderStudentCards(rows) {
    const grid = $('#studentsCardsGrid');
    if (!grid) return;
    if (!rows.length) { grid.innerHTML = '<p style="padding:24px;text-align:center;color:var(--muted)">لا توجد نتائج مطابقة.</p>'; return; }
    grid.innerHTML = rows.map(u => {
      const initials = (u.full_name || '').split(' ').slice(0,2).map(w=>w[0]||'').join('').toUpperCase() || '?';
      const avatarHtml = u.avatar_url
        ? `<img src="${escapeHtml(u.avatar_url)}" alt="${escapeHtml(u.full_name)}" onerror="this.style.display='none';this.nextSibling.style.display='flex'">`
        : '';
      const statusMap = { active:'نشط', inactive:'غير نشط', suspended:'موقوف' };
      const statusClass = { active:'success', inactive:'muted', suspended:'danger' };
      return `
        <div class="student-card">
          <div class="student-card-head">
            <div class="student-card-avatar">
              ${avatarHtml}
              <span style="${u.avatar_url?'display:none;':''}align-items:center;justify-content:center;width:100%;height:100%;">${escapeHtml(initials)}</span>
            </div>
            <div class="student-card-userinfo">
              <div class="student-card-name" title="${escapeHtml(u.full_name)}">${escapeHtml(u.full_name)}</div>
              <div class="student-card-email" title="${escapeHtml(u.email)}">${escapeHtml(u.email)}</div>
              <div style="font-size:11px;color:var(--slate-500);margin-top:2px;">📱 ${escapeHtml(u.phone || 'بدون هاتف')}</div>
            </div>
          </div>
          <div class="student-card-meta">
            <span class="badge badge-${statusClass[u.status]||'muted'}">${statusMap[u.status]||u.status}</span>
            <span class="student-card-enroll">📚 ${u.enrolled_count||0} كورسات</span>
          </div>
          <div class="student-card-actions">
            <button type="button" class="small-btn btn-view" data-view-student="${escapeHtml(u.id)}">👁 التفاصيل</button>
            <button type="button" class="small-btn btn-qr" data-show-qr="${escapeHtml(u.id)}" data-qr-name="${escapeHtml(u.full_name)}" data-qr-email="${escapeHtml(u.email)}" data-qr-token="${escapeHtml(u.qr_token||'EDU-STU-'+u.id)}">⬛ QR</button>
            ${u.phone ? `<a href="https://wa.me/${u.phone.replace(/\D/g,'')}" target="_blank" rel="noopener" class="small-btn btn-wa" style="text-decoration:none;display:flex;align-items:center;justify-content:center;gap:4px">💬 واتساب</a>` : ''}
          </div>
        </div>
      `;
    }).join('');
  }

  // Intercept loadStudents result for cards view
  const _origLoadStudents = window._nbcLoadStudents;
  document.addEventListener('nbc:students:loaded', e => {
    studentsCurrentData = e.detail || [];
    if (studentsCurrentView === 'cards') renderStudentCards(studentsCurrentData);
  });

  document.addEventListener('click', e => {
    // View toggle
    if (e.target.closest('#viewTableBtn')) {
      studentsCurrentView = 'table'; localStorage.setItem('nbc_admin_students_view','table'); applyStudentsView();
    }
    if (e.target.closest('#viewCardsBtn')) {
      studentsCurrentView = 'cards'; localStorage.setItem('nbc_admin_students_view','cards'); applyStudentsView();
    }

    // Cards: view student details
    const viewBtn = e.target.closest('[data-view-student]');
    if (viewBtn && e.target.closest('#studentsCardsGrid')) {
      e.preventDefault();
      openStudentDetails(viewBtn.dataset.viewStudent);
    }

    // QR Code button
    const qrBtn = e.target.closest('[data-show-qr]');
    if (qrBtn) {
      const id = qrBtn.dataset.showQr;
      const name = qrBtn.dataset.qrName;
      const email = qrBtn.dataset.qrEmail;
      const token = qrBtn.dataset.qrToken;
      showQrModal(id, name, email, token);
    }

    // Close QR modal
    if (e.target.closest('#closeQrModal') || e.target.id === 'studentQrModal') {
      document.getElementById('studentQrModal').hidden = true;
    }
  });

  // After students load, also apply card view if active
  const origLS = $('#studentsTable');
  if (origLS) {
    const mo = new MutationObserver(() => {
      if (studentsCurrentView === 'cards') {
        // parse rows from table to re-render cards
        const trs = origLS.querySelectorAll('tbody tr');
        if (trs.length > 0 && studentsCurrentData.length === 0) {
          // fallback: show table (data not captured via event)
          studentsCurrentView = 'table'; applyStudentsView();
        } else {
          applyStudentsView();
        }
      }
    });
    mo.observe(origLS, { childList: true, subtree: true });
  }

  // ==========================================
  // QR Code Modal (canvas-based, no external lib)
  // ==========================================
  function showQrModal(id, name, email, token) {
    const modal = document.getElementById('studentQrModal');
    if (!modal) return;
    document.getElementById('qrStudentName').textContent = name;
    document.getElementById('qrStudentEmail').textContent = email;
    document.getElementById('qrTokenDisplay').textContent = token;
    modal.hidden = false;

    // Draw QR placeholder using canvas (simple matrix)
    const canvas = document.getElementById('qrCanvas');
    if (!canvas) return;
    drawSimpleQR(canvas, token);
  }

  function drawSimpleQR(canvas, text) {
    // Simple visual QR-like grid (decorative — for production use qrcode.js library)
    const size = 160;
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, size, size);
    ctx.fillStyle = '#0d9488';

    // Generate hash-based grid
    let hash = 0;
    for (let i = 0; i < text.length; i++) { hash = (hash * 31 + text.charCodeAt(i)) | 0; }
    const grid = 21;
    const cell = size / grid;

    // Finder patterns (top-left, top-right, bottom-left)
    const drawFinder = (ox, oy) => {
      ctx.fillStyle = '#0a1f3d';
      ctx.fillRect(ox*cell, oy*cell, 7*cell, 7*cell);
      ctx.fillStyle = '#ffffff';
      ctx.fillRect((ox+1)*cell, (oy+1)*cell, 5*cell, 5*cell);
      ctx.fillStyle = '#0a1f3d';
      ctx.fillRect((ox+2)*cell, (oy+2)*cell, 3*cell, 3*cell);
    };
    drawFinder(0, 0); drawFinder(14, 0); drawFinder(0, 14);

    // Data modules
    ctx.fillStyle = '#0d9488';
    let seed = Math.abs(hash);
    for (let y = 0; y < grid; y++) {
      for (let x = 0; x < grid; x++) {
        // Skip finder regions
        if ((x < 8 && y < 8) || (x > 13 && y < 8) || (x < 8 && y > 13)) continue;
        seed = (seed * 1103515245 + 12345) & 0x7fffffff;
        if (seed % 3 === 0) ctx.fillRect(x*cell, y*cell, cell-0.5, cell-0.5);
      }
    }

    // Store for download
    canvas.dataset.studentName = document.getElementById('qrStudentName').textContent;
  }

  // Print QR
  document.getElementById('printQrBtn')?.addEventListener('click', () => {
    const canvas = document.getElementById('qrCanvas');
    const name = document.getElementById('qrStudentName').textContent;
    const token = document.getElementById('qrTokenDisplay').textContent;
    const w = window.open('', '_blank', 'width=400,height=500');
    w.document.write(`<!DOCTYPE html><html lang="ar" dir="rtl"><head><title>QR - ${name}</title>
    <style>body{font-family:Cairo,sans-serif;text-align:center;padding:32px;background:#fff}
    h2{color:#0a1f3d;margin-bottom:8px}p{color:#64748b;font-size:12px}canvas{border:1px solid #e2e8f0;border-radius:8px}</style></head>
    <body><h2>${name}</h2><br><img src="${canvas.toDataURL()}" width="200"><br><br><p>${token}</p></body></html>`);
    w.document.close(); w.print();
  });

  // Download QR
  document.getElementById('downloadQrBtn')?.addEventListener('click', () => {
    const canvas = document.getElementById('qrCanvas');
    const name = canvas.dataset.studentName || 'student';
    const a = document.createElement('a');
    a.href = canvas.toDataURL('image/png');
    a.download = `QR_${name.replace(/\s+/g,'_')}.png`;
    a.click();
  });

  // ==========================================
  // Settings Panel
  // ==========================================
  const medicalThemeNames = {
    'royal-red':'الأحمر الملكي','royal-gold':'الذهبي الملكي','european-luxury':'الفخامة الأوروبية','modern-dark':'الداكن العصري'
  };
  const normalizeAdminPreset = value => ({'royal-teal':'royal-red','royal-sapphire':'royal-gold','royal-jade':'royal-gold','royal-amethyst':'modern-dark','royal-cyan':'european-luxury','clinical-blue':'royal-gold','teal-care':'royal-red','emerald-clinic':'royal-gold','violet-academy':'modern-dark','arctic-care':'european-luxury','custom':'royal-red'}[value] || (medicalThemeNames[value] ? value : 'royal-red'));
  const medicalGoogleFonts = new Set(['Cairo','Tajawal','Almarai','IBM Plex Sans Arabic','Alexandria','Readex Pro','Noto Sans Arabic']);

  function applyAdminPlatformLogo(path) {
    const safePath = /^uploads\/platform\/logo-[a-f0-9]{40}\.(jpg|png|webp)$/i.test(path || '') ? path : '';
    document.querySelectorAll('.sidebar-header .brand-mark, .login-brand-mark').forEach(mark => {
      if (safePath) {
        const image = document.createElement('img');
        image.src = safePath; image.alt = 'شعار المنصة';
        image.style.cssText = 'width:100%;height:100%;object-fit:contain;border-radius:inherit';
        mark.replaceChildren(image);
      } else if (mark.classList.contains('login-brand-mark')) mark.textContent = '✚';
      else mark.textContent = '✚';
    });
  }

  function applyMedicalIdentity() {
    const form = document.getElementById('settingsForm');
    if (!form) return;
    const root = document.documentElement;
    const preset = normalizeAdminPreset(form.elements.theme_preset.value);
    form.elements.theme_preset.value = preset;
    root.dataset.medicalTheme = preset;
    ['theme_button_style','theme_card_style','theme_header_style','theme_sidebar_style','theme_footer_style','theme_layout'].forEach(key=>{const el=form.elements[key];if(el?.value)root.setAttribute(`data-${key.replace('theme_','').replaceAll('_','-')}`,el.value)});
    if(form.elements.theme_motion?.value)root.setAttribute('data-motion',form.elements.theme_motion.value);
    const font = medicalGoogleFonts.has(form.elements.theme_font.value) ? form.elements.theme_font.value : 'Cairo';
    root.style.setProperty('--font-ar', `"${font}", "IBM Plex Sans Arabic", "Segoe UI", Tahoma, sans-serif`);
    const english=form.elements.theme_font_en?.value||'Inter';root.style.setProperty('--font-en',`"${english}", Arial, sans-serif`);
    if(form.elements.theme_mode_default?.value)root.dataset.theme=form.elements.theme_mode_default.value;
    if(form.elements.theme_custom_enabled?.value==='1'){
      root.dataset.customColors='enabled';
      root.style.setProperty('--brand-primary',form.elements.theme_custom_primary.value);
      root.style.setProperty('--brand-secondary',form.elements.theme_custom_secondary.value);
      root.style.setProperty('--theme-custom-surface',form.elements.theme_custom_surface.value);
      root.style.setProperty('--theme-custom-background',form.elements.theme_custom_background.value);
    }else{delete root.dataset.customColors;root.style.removeProperty('--brand-primary');root.style.removeProperty('--brand-secondary');root.style.removeProperty('--theme-custom-surface');root.style.removeProperty('--theme-custom-background')}
    document.fonts?.load(`700 16px "${font}"`).catch(() => {});
    document.querySelectorAll('[data-medical-preset]').forEach(card => {
      const selected = card.dataset.medicalPreset === preset;
      card.setAttribute('aria-checked', String(selected));
      card.classList.toggle('selected', selected);
    });
    updatePreview();
  }

  async function loadSettings() {
    try {
      const res = await request('api/admin/settings.php');
      const s = res.settings || {};
      applyAdminPlatformLogo(s.platform_logo);
      const logoPreview = document.getElementById('platformLogoPreview');
      if (logoPreview) {
        logoPreview.innerHTML = s.platform_logo ? `<img src="${escapeHtml(s.platform_logo)}?v=${Date.now()}" alt="شعار المنصة" style="width:100%;height:100%;object-fit:contain">` : '✚';
      }
      const introPreview = document.getElementById('introVideoAdminPreview');
      if (introPreview && /^uploads\/platform\/intro-video-[a-f0-9]+\.mp4$/i.test(s.hero_video_url || '')) {
        introPreview.src = s.hero_video_url; introPreview.style.display = 'block';
      }
      const form = document.getElementById('settingsForm');
      if (!form) return;
      const fields = ['academy_name','academy_name_ar','academy_slogan_ar','academy_slogan_en',
        'founder_name','founder_title','founder_bio_ar','contact_phone','whatsapp_number',
        'contact_email','clinic_address_ar','payment_vodafone','payment_instapay',
        'currency','payment_instructions_ar','theme_font','theme_font_en','theme_mode_default','theme_user_choice_enabled','theme_motion','theme_button_style','theme_card_style','theme_header_style','theme_sidebar_style','theme_footer_style','theme_layout','theme_custom_enabled','theme_custom_primary','theme_custom_secondary','theme_custom_surface','theme_custom_background'];
      fields.forEach(k => {
        const el = form.querySelector(`[name="${k}"]`);
        if (el && s[k] !== undefined && !(el.type === 'color' && !s[k])) el.value = s[k];
      });
      form.elements.theme_preset.value = normalizeAdminPreset(s.theme_preset);
      applyMedicalIdentity();
    } catch (err) {
      notice('#settingsMsg', 'تعذر تحميل الإعدادات: ' + err.message, true);
    }
  }

  function updatePreview() {
    const form = document.getElementById('settingsForm');
    if (!form) return;
    const name = document.getElementById('themePalettePreviewName');
    if (name) name.textContent = (medicalThemeNames[form.elements.theme_preset.value] || medicalThemeNames['royal-red']) + (form.elements.theme_custom_enabled?.value === '1' ? ' • ألوان مخصصة' : '');
    const fontPreview = document.getElementById('themeFontPreview');
    if (fontPreview) fontPreview.style.fontFamily = `"${form.elements.theme_font.value}", sans-serif`;
  }

  document.getElementById('medicalPaletteGrid')?.addEventListener('click', async event => {
    const card = event.target.closest('[data-medical-preset]');
    if (!card) return;
    const form = document.getElementById('settingsForm');
    const preset = card.dataset.medicalPreset;
    if (!medicalThemeNames[preset]) return;
    form.elements.theme_preset.value = preset;
    if (form.elements.theme_custom_enabled) form.elements.theme_custom_enabled.value = '0';
    applyMedicalIdentity();
    const msg = document.getElementById('settingsMsg');
    if (msg) { msg.className='notice notice-success';msg.textContent='تم تطبيق ألوان الهوية المختارة للمعاينة. اضغط «حفظ جميع الإعدادات» لنشرها.';msg.hidden=false; }
  });

  document.getElementById('settingsForm')?.addEventListener('change', event => {
    if (event.target.name.startsWith('theme_')) {
      applyMedicalIdentity();
    }
  });

  document.getElementById('settingsForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.target;
    const msg = document.getElementById('settingsMsg');
    msg.hidden = true;
    const fd = new FormData(form);
    const body = {};
    fd.forEach((v, k) => { if (!k.endsWith('_hex')) body[k] = v; });
    try {
      const res = await request('api/admin/settings.php', { method:'POST', body: JSON.stringify(body) });
      msg.className = 'notice'; msg.textContent = res.message || 'تم الحفظ بنجاح.'; msg.hidden = false;
      try { localStorage.removeItem('nbc_user_medical_theme'); } catch (_) {}
      applyMedicalIdentity();
      window.applyMedicalThemeSettings?.({...body,theme_user_choice_enabled:body.theme_user_choice_enabled==='1'});
    } catch (err) {
      msg.className = 'notice error'; msg.textContent = err.message; msg.hidden = false;
    }
  });

  document.getElementById('uploadPlatformLogoBtn')?.addEventListener('click', async () => {
    const input = document.getElementById('platformLogoFile');
    const button = document.getElementById('uploadPlatformLogoBtn');
    const message = document.getElementById('platformLogoMessage');
    if (!input?.files?.[0]) {
      message.textContent = 'اختر صورة الشعار وقصّها أولًا.'; message.className = 'notice notice-error'; message.hidden = false; return;
    }
    const body = new FormData(); body.set('action', 'upload_logo'); body.set('logo_file', input.files[0]);
    button.disabled = true; message.hidden = true;
    try {
      const result = await request('api/admin/settings.php', { method:'POST', body });
      const preview = document.getElementById('platformLogoPreview');
      preview.innerHTML = `<img src="${escapeHtml(result.platform_logo)}?v=${Date.now()}" alt="شعار المنصة" style="width:100%;height:100%;object-fit:contain">`;
      applyAdminPlatformLogo(result.platform_logo);
      input.value = '';
      message.className = 'notice notice-success'; message.textContent = result.message || 'تم تحديث الشعار.'; message.hidden = false;
    } catch (err) {
      message.className = 'notice notice-error'; message.textContent = err.message || 'تعذر رفع الشعار.'; message.hidden = false;
    } finally { button.disabled = false; }
  });

  document.getElementById('resetSettingsBtn')?.addEventListener('click', () => {
    const form = document.getElementById('settingsForm');
    if (!form) return;
    const defaults={theme_preset:'royal-red',theme_font:'Cairo',theme_font_en:'Inter',theme_mode_default:'light',theme_user_choice_enabled:'1',theme_motion:'smooth',theme_button_style:'soft',theme_card_style:'glass',theme_header_style:'solid',theme_sidebar_style:'solid',theme_footer_style:'dark',theme_layout:'wide',theme_custom_enabled:'0',theme_custom_primary:'#c9152b',theme_custom_secondary:'#e83b4e',theme_custom_surface:'#ffffff',theme_custom_background:'#f5f6f8'};
    Object.entries(defaults).forEach(([key,value])=>{const field=form.elements[key];if(field)field.value=value});
    applyMedicalIdentity();
  });

  // ==========================================
  // Admin Profile Panel
  // ==========================================
  async function loadAdminProfile() {
    try {
      const res = await request('api/admin/me.php');
      const u = res.user || {};
      const fn = document.getElementById('adminFullName');
      const em = document.getElementById('adminEmail');
      const ph = document.getElementById('adminPhone');
      const av = document.getElementById('adminAvatarFile');
      const avPrev = document.getElementById('adminAvatarPreview');
      if (fn) fn.value = u.full_name || '';
      if (em) em.value = u.email || '';
      if (ph) ph.value = u.phone || '';
      if (av) av.value = '';
      if (avPrev) {
        if (u.avatar_url) {
          avPrev.style.backgroundImage = `url(${u.avatar_url})`;
          avPrev.style.backgroundSize = 'cover';
          avPrev.textContent = '';
        } else {
          avPrev.textContent = (u.full_name||'Admin')[0].toUpperCase();
        }
      }
    } catch (_) {}
  }

  document.getElementById('adminAvatarFile')?.addEventListener('change', function() {
    const prev = document.getElementById('adminAvatarPreview');
    if (!prev) return;
    const file = this.files?.[0];
    if (file && file.type.startsWith('image/')) {
      prev.style.backgroundImage = `url(${URL.createObjectURL(file)})`;
      prev.style.backgroundSize = 'cover';
      prev.textContent = '';
    } else {
      prev.style.backgroundImage = '';
      prev.textContent = document.getElementById('adminFullName')?.value?.[0]?.toUpperCase() || '✚';
    }
  });

  document.getElementById('adminProfileForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const msg = document.getElementById('adminProfileMsg');
    msg.hidden = true;
    const fd = new FormData(e.target);
    const body = { action:'update_profile', full_name: fd.get('full_name'), email: fd.get('email'), phone: fd.get('phone') };
    try {
      const res = await request('api/admin/me.php', { method:'POST', body: JSON.stringify(body) });
      msg.className = 'notice'; msg.textContent = res.message; msg.hidden = false;
      if (res.user?.full_name) { const who = document.getElementById('who'); if (who) who.textContent = res.user.full_name; }
    } catch (err) {
      msg.className = 'notice error'; msg.textContent = err.message; msg.hidden = false;
    }
  });

  document.getElementById('adminAvatarFile')?.addEventListener('change', async function() {
    const file = this.files?.[0];
    if (!file) return;
    const msg = document.getElementById('adminProfileMsg');
    try {
      const data = new FormData(); data.append('action', 'upload_avatar'); data.append('file', file);
      const res = await request('api/admin/me.php', { method: 'POST', body: data, headers: csrfToken ? {'X-CSRF-Token': csrfToken} : {} });
      const prev = document.getElementById('adminAvatarPreview');
      if (prev) { prev.style.backgroundImage = `url(${res.avatar_url})`; prev.style.backgroundSize = 'cover'; prev.textContent = ''; }
      notice('#adminProfileMsg', res.message);
    } catch (err) { notice('#adminProfileMsg', err.message, true); }
  });

  document.getElementById('adminPasswordForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const msg = document.getElementById('adminProfileMsg');
    msg.hidden = true;
    const fd = new FormData(e.target);
    if (fd.get('new_password') !== fd.get('confirm_password')) {
      msg.className = 'notice error'; msg.textContent = 'كلمة المرور الجديدة وتأكيدها غير متطابقتين.'; msg.hidden = false; return;
    }
    try {
      const res = await request('api/admin/me.php', { method:'POST', body: JSON.stringify({ action:'change_password', current_password: fd.get('current_password'), new_password: fd.get('new_password') }) });
      msg.className = 'notice'; msg.textContent = res.message; msg.hidden = false;
      e.target.reset();
    } catch (err) {
      msg.className = 'notice error'; msg.textContent = err.message; msg.hidden = false;
    }
  });

  document.getElementById('introVideoAdminForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const msg = document.getElementById('introVideoAdminMsg');
    const form = e.currentTarget;
    try {
      const res = await request('api/admin/intro-video.php', {method:'POST', body:new FormData(form)});
      notice('#introVideoAdminMsg', res.message);
      const preview = document.getElementById('introVideoAdminPreview');
      if (preview) { preview.src = res.path; preview.style.display = 'block'; }
      form.reset();
    } catch (err) { notice('#introVideoAdminMsg', err.message, true); }
  });

  // ==========================================
  // Admin Language Toggle & I18N Engine (AR / EN)
  // ==========================================
  const ADMIN_I18N = {
    ar: {
      doc_title: 'لوحة الإدارة | Nursing Basic Course',
      admin_portal_subtitle: 'لوحة الإدارة الأكاديمية',
      nav_main_ctrl: 'الرئيسية والتحكم',
      nav_overview: 'نظرة عامة',
      nav_system_mgmt: 'إدارة المنظومة',
      nav_students: 'الطلاب',
      nav_instructors: 'المحاضرون',
      nav_courses: 'الكورسات والمنهج',
      nav_reviews: 'تقييمات وآراء الطلاب',
      nav_quick_links: 'روابط سريعة',
      nav_visit_site: 'زيارة موقع المنصة',
      nav_instructor_portal: 'بوابة المحاضرين',
      nav_settings_account: 'الإعدادات والحساب',
      nav_settings: 'إعدادات المنصة',
      nav_admin_profile: 'ملف حساب المدير',
      nav_db_status: 'قاعدة البيانات: متصلة ونشطة',
      admin_version_text: 'لوحة الإدارة الطبية  —  NBC v2.4',
      topbar_ctrl_panel: 'لوحة التحكم',
      global_search_placeholder: 'بحث شامل في الطلاب، الكورسات، المحاضرين... (Ctrl+K)',
      quick_sections: 'الأقسام السريعة',
      search_overview: '📊 نظرة عامة والإحصائيات',
      search_students: '👨‍🎓 بحث في سجلات الطلاب',
      search_instructors: '👨‍🏫 بحث في هيئة التدريس والمحاضرين',
      search_courses: '📚 بحث في الكورسات والمنهج',
      topbar_refresh: 'تحديث البيانات',
      admin_role: 'الإدارة',
      logout_btn: 'تسجيل الخروج',
      welcome_badge: 'NURSING BASIC COURSE  —  مركز التحكم الأكاديمي',
      welcome_title: 'نظرة عامة على المنظومة الطبية 🩺',
      welcome_subtitle: 'مؤشرات الأداء الأكاديمي، الإيرادات المالية، والنشاط اللحظي من قاعدة البيانات.',
      quick_new_course: '+ كورس جديد',
      quick_new_instructor: '+ محاضر جديد',
      quick_new_student: '+ طالب جديد',
      add_student_btn: '+ إضافة طالب جديد',
      add_instructor_btn: '+ إضافة محاضر جديد',
      add_course_btn: '+ إنشاء كورس جديد',
      students_title: 'إدارة الطلاب المسجلين',
      students_subtitle: 'بحث، متابعة الحالة الأكاديمية، والتحكم بحساب الطالب دون حذف بياناته.',
      instructors_title: 'إدارة المحاضرين والمدربين',
      instructors_subtitle: 'متابعة المحاضرين، إسناد الكورسات، والتحكم الدقيق في الصلاحيات الأكاديمية.',
      courses_title: 'إدارة الكورسات والمنهج الأكاديمي',
      courses_subtitle: 'إنشاء وتعديل المسارات الطبية وتحديد الأسعار والشهادات والوحدات التدريبية.',
      student_search_placeholder: 'ابحث بالاسم أو البريد أو الهاتف...',
      ins_search_placeholder: 'ابحث بالاسم أو البريد أو الهاتف أو التخصص...',
      course_search_placeholder: 'ابحث عن كورس أو تخصص طبي...',
      search_btn: 'بحث',
      apply_filters: 'تطبيق الفلتر',
      reset_filters: 'إعادة ضبط',
      view_cards: 'بطاقات',
      view_table: 'جدول',
      student_profile_title: 'ملف الطالب الأكاديمي',
      student_profile_subtitle: 'بيانات الحساب والتسجيلات ونسبة التقدم الدراسي.',
      export_csv: 'تصدير CSV',
      back_to_students: '← العودة إلى قائمة الطلاب',
      reviews_title: 'تقييمات وآراء الطلاب',
      reviews_subtitle: 'راجع التقييمات والتعليقات، واعتمد المناسب منها للظهور على المنصة.',
      reviews_refresh: 'تحديث التقييمات',
      reviews_filter_status: 'الحالة', reviews_all_statuses: 'كل الحالات',
      reviews_pending: 'بانتظار المراجعة', reviews_approved: 'معتمد', reviews_rejected: 'مرفوض',
      reviews_filter_type: 'نوع المحتوى', reviews_all_types: 'كل المحتوى', reviews_course: 'كورس', reviews_book: 'كتاب',
      reviews_search_label: 'بحث', reviews_search_placeholder: 'ابحث باسم الطالب أو الكورس أو التعليق'
    },
    en: {
      doc_title: 'Admin Dashboard | Nursing Basic Course',
      admin_portal_subtitle: 'Academic Administration Portal',
      nav_main_ctrl: 'Main & Controls',
      nav_overview: 'Overview',
      nav_system_mgmt: 'System Management',
      nav_students: 'Students',
      nav_instructors: 'Instructors',
      nav_courses: 'Courses & Curriculum',
      nav_reviews: 'Student Reviews',
      nav_quick_links: 'Quick Links',
      nav_visit_site: 'Visit Website',
      nav_instructor_portal: 'Instructor Portal',
      nav_settings_account: 'Settings & Account',
      nav_settings: 'Platform Settings',
      nav_admin_profile: 'Admin Profile',
      nav_db_status: 'Database: Connected & Active',
      admin_version_text: 'Clinical Admin Portal  —  NBC v2.4',
      topbar_ctrl_panel: 'Dashboard',
      global_search_placeholder: 'Search students, courses, instructors... (Ctrl+K)',
      quick_sections: 'Quick Sections',
      search_overview: '📊 Overview & Analytics',
      search_students: '👨‍🎓 Search Student Records',
      search_instructors: '👨‍🏫 Search Faculty & Instructors',
      search_courses: '📚 Search Courses & Curriculum',
      topbar_refresh: 'Refresh Data',
      admin_role: 'Administration',
      logout_btn: 'Logout',
      welcome_badge: 'NURSING BASIC COURSE  —  Academic Control Center',
      welcome_title: 'Clinical System Overview 🩺',
      welcome_subtitle: 'Live academic indicators, financial revenue, and database activity.',
      quick_new_course: '+ New Course',
      quick_new_instructor: '+ New Instructor',
      quick_new_student: '+ New Student',
      add_student_btn: '+ Add Student',
      add_instructor_btn: '+ Add Instructor',
      add_course_btn: '+ Create Course',
      students_title: 'Enrolled Students Management',
      students_subtitle: 'Search, monitor academic progress, and manage student accounts.',
      instructors_title: 'Faculty & Instructors Management',
      instructors_subtitle: 'Monitor instructors, assign courses, and manage academic privileges.',
      courses_title: 'Courses & Curriculum Management',
      courses_subtitle: 'Create and edit clinical tracks, pricing, certificates, and learning units.',
      student_search_placeholder: 'Search by name, email, or phone...',
      ins_search_placeholder: 'Search by name, email, phone, or specialty...',
      course_search_placeholder: 'Search courses or clinical specialties...',
      search_btn: 'Search',
      apply_filters: 'Apply Filters',
      reset_filters: 'Reset',
      view_cards: 'Cards',
      view_table: 'Table',
      student_profile_title: 'Student Academic Profile',
      student_profile_subtitle: 'Account info, enrolled courses, progress metrics and activity history.',
      export_csv: 'Export CSV',
      back_to_students: '← Back to Students List',
      reviews_title: 'Student Reviews & Ratings',
      reviews_subtitle: 'Review student ratings and comments, and approve suitable feedback for publication.',
      reviews_refresh: 'Refresh Reviews',
      reviews_filter_status: 'Status', reviews_all_statuses: 'All statuses',
      reviews_pending: 'Pending review', reviews_approved: 'Approved', reviews_rejected: 'Rejected',
      reviews_filter_type: 'Content type', reviews_all_types: 'All content', reviews_course: 'Course', reviews_book: 'Book',
      reviews_search_label: 'Search', reviews_search_placeholder: 'Search student, course, or comment'
    }
  };

  // Comprehensive Direct Text Translation Dictionary for Admin
  const ADMIN_TEXT_MAP = {
    // Navigation & General
    'لوحة التحكم': 'Control Panel',
    'لوحة الإدارة الأكاديمية': 'Academic Admin Portal',
    'الرئيسية والتحكم': 'Main & Dashboard',
    'نظرة عامة': 'Overview',
    'إدارة المنظومة': 'System Management',
    'الطلاب': 'Students',
    'المحاضرون': 'Instructors',
    'الكورسات والمنهج': 'Courses & Curriculum',
    'الكورسات': 'Courses',
    'روابط سريعة': 'Quick Links',
    'زيارة موقع المنصة': 'Visit Platform',
    'بوابة المحاضرين': 'Instructors Portal',
    'الإعدادات والحساب': 'Settings & Account',
    'إعدادات المنصة': 'Platform Settings',
    'ملف حساب المدير': 'Admin Profile',
    'حسابي': 'My Profile',
    'قاعدة البيانات: متصلة ونشطة': 'Database: Connected & Active',
    'لوحة الإدارة الطبية  —  NBC v2.4': 'Clinical Admin  —  NBC v2.4',
    'تحديث البيانات': 'Refresh Data',
    'تسجيل الخروج': 'Logout',
    'الإدارة': 'Administration',
    'الوضع الليلي': 'Dark Mode',
    'الوضع النهاري': 'Light Mode',

    // Overview & Stats
    'نظرة عامة على المنظومة الطبية 🩺': 'Clinical Platform Overview 🩺',
    'مؤشرات الأداء الأكاديمي، الإيرادات المالية، والنشاط اللحظي من قاعدة البيانات.': 'Academic performance, revenue, and live database metrics.',
    '+ كورس جديد': '+ New Course',
    '+ محاضر جديد': '+ New Instructor',
    '+ طالب جديد': '+ New Student',
    'إجمالي الطلاب': 'Total Students',
    'الطلاب النشطون': 'Active Students',
    'المحاضرون المعتمدون': 'Approved Faculty',
    'الكورسات المتاحة': 'Published Courses',
    'إجمالي التسجيلات': 'Total Enrollments',
    'إجمالي الإيرادات': 'Total Revenue',
    'الإيرادات المالية المؤكدة': 'Confirmed Financial Revenue',
    'متابعة التدفق المالي بالجنيه المصري عبر الفترة المحددة.': 'Financial flow tracking in EGP over the selected period.',
    'التسجيلات في الكورسات': 'Course Enrollments',
    'حركة تسجيل الطلاب في المسارات التدريبية المختلفة.': 'Student enrollment activity across clinical tracks.',
    'الطلاب الجدد المنضمون': 'Newly Registered Students',
    'معدل إنشاء الحسابات الطلابية الجديدة.': 'Rate of new student accounts registration.',
    'نسب إكمال الكورسات': 'Course Completion Rates',
    'مدى تقدم وإنجاز الطلاب لكل مسار تدريبي.': 'Student progress and track completion rate.',
    'أحدث التسجيلات': 'Latest Enrollments',
    'آخر 10 طلاب التحقوا بالكورسات التدريبية.': 'Last 10 students enrolled in training courses.',
    'أحدث المعاملات المالية': 'Latest Financial Transactions',
    'سجل التحويلات والمدفوعات الإلكترونية.': 'Electronic payments and transfer records.',
    'أحدث الأنشطة التفاعلية': 'Latest Interactive Activity',
    'سجل النشاط اللحظي داخل منصة التمريض.': 'Live platform interaction and learning activity.',
    'مدفوعات تحتاج مراجعة الإدارة': 'Payments Requiring Admin Review',
    'معاملات تنتظر التأكيد وتفعيل الاشتراك.': 'Transactions awaiting approval and subscription activation.',
    'أحدث الحسابات المسجلة': 'Latest Registered Accounts',
    'آخر المنضمين إلى الأكاديمية من طلاب ومحاضرين.': 'Recent students and instructors who joined.',

    // Filters
    'من تاريخ': 'From Date',
    'إلى تاريخ': 'To Date',
    'الكورس': 'Course',
    'حالة الدفع': 'Payment Status',
    'بحث شامل': 'Global Search',
    'تطبيق الفلتر': 'Apply Filter',
    'إعادة ضبط': 'Reset',
    'كل الكورسات': 'All Courses',
    'كل الحالات': 'All Statuses',

    // Students
    'إدارة الطلاب المسجلين': 'Enrolled Students Management',
    'بحث، متابعة الحالة الأكاديمية، والتحكم بحساب الطالب دون حذف بياناته.': 'Search, monitor academic progress, and manage student accounts.',
    '+ إضافة طالب جديد': '+ Add New Student',
    'إضافة حساب طالب جديد': 'Add New Student Account',
    'بطاقات': 'Cards',
    'جدول': 'Table',
    'بحث': 'Search',

    // Instructors
    'إدارة المحاضرين والمدربين': 'Faculty & Instructors Management',
    'متابعة المحاضرين، إسناد الكورسات، والتحكم الدقيق في الصلاحيات الأكاديمية.': 'Oversee faculty, assign courses, and manage academic privileges.',
    '+ إضافة محاضر جديد': '+ Add New Instructor',
    'إضافة محاضر جديد': 'Add New Instructor',
    'كروت': 'Cards',
    'ملف المحاضر': 'Instructor Profile',
    'البيانات المهنية والسيرة الذاتية': 'Professional Profile & Bio',
    'الصورة الشخصية، بيانات التواصل، والتخصص الطبي.': 'Profile photo, contact info, and medical specialty.',
    'تغيير الصورة': 'Change Photo',
    'تعطيل الحساب': 'Disable Account',
    '← العودة إلى المحاضرين': '← Back to Instructors',

    // Courses
    'إدارة الكورسات والمنهج الأكاديمي': 'Courses & Curriculum Management',
    'إنشاء وتعديل المسارات الطبية وتحديد الأسعار والشهادات والوحدات التدريبية.': 'Create and manage courses, prices, certificates, and curriculum.',
    '+ إنشاء كورس جديد': '+ Create New Course',
    'إنشاء كورس جديد': 'Create New Course',
    '1) البيانات الأساسية للكورس': '1) Basic Course Information',
    'اسم الكورس (بالعربية)': 'Course Title (Arabic)',
    'Course Name (English)': 'Course Title (English)',
    'Slug بالإنجليزية (رابط الكورس)': 'Course URL Slug',
    'التخصص الطبي': 'Medical Specialty',
    'المستوى التعليمي': 'Education Level',
    'حالة النشر': 'Publishing Status',
    'الوصف التفصيلي للكورس': 'Detailed Course Description',
    '2) صورة الغلاف': '2) Cover Image',
    'اختيار صورة': 'Choose Image',
    'إزالة الصورة': 'Remove Image',
    '3) هيئة التدريس والمحاضرون': '3) Faculty & Instructors',
    'المحاضر الرئيسي': 'Primary Instructor',
    'محاضرون مشاركون (اختياري)': 'Co-Instructors (Optional)',
    '4) التسعير والمقاعد والمدة': '4) Pricing, Seats & Duration',
    'السعر الأساسي (جنيه مصري)': 'Base Price (EGP)',
    'السعر بعد الخصم (إن وجد)': 'Discounted Price (if applicable)',
    'المدة التقديرية (بالدقائق)': 'Estimated Duration (Minutes)',
    'الحد الأقصى للمقاعد': 'Maximum Seats Limit',
    'تاريخ البدء': 'Start Date',
    'تاريخ الانتهاء': 'End Date',
    '5) الشروط ومخرجات التعلم': '5) Prerequisites & Learning Outcomes',
    'متطلبات الالتحاق بالكورس': 'Prerequisites & Prior Knowledge',
    'مخرجات التعلم المتوقعة': 'Expected Learning Outcomes',
    'أقل نسبة حضور/تقدم لإتمام الكورس (٪)': 'Min. Attendance/Progress for Completion (%)',
    'شروط استحقاق الشهادة': 'Certificate Eligibility Criteria',
    'حفظ كمسودة': 'Save as Draft',
    'حفظ الكورس': 'Save Course',
    'المنهج الدراسي: الوحدات والمحاضرات': 'Curriculum: Units & Lessons',
    'هيكلة الكورس إلى وحدات ودروس تفاعلية مع إمكانية إتاحة معاينات مجانية للزوار.': 'Structure lessons and units with optional free previews.',
    '+ وحدة دراسية جديدة': '+ New Unit',
    'حفظ المنهج': 'Save Curriculum',
    'كوبونات وقسائم الخصم': 'Coupons & Discount Vouchers',
    'أكواد ترويجية مخصصة للطلاب بنسبة مئوية أو قيمة ثابتة.': 'Promotional discount codes for enrolled students.',
    'الكود': 'Promo Code',
    'نوع الخصم': 'Discount Type',
    'نسبة ٪': 'Percentage %',
    'مبلغ ثابت (ج.م)': 'Fixed Amount (EGP)',
    'قيمة الخصم': 'Discount Value',
    'أقصى عدد استخدام': 'Maximum Uses',
    'إضافة الكوبون': 'Add Coupon',

    // Settings
    'إعدادات المنصة التعليمية ⚙️': 'Platform Settings ⚙️',
    'تحكم كامل في هوية المنصة والألوان والبيانات الأساسية للأكاديمية.': 'Complete control over academy identity, colors, and configuration.',
    '🏫 بيانات الأكاديمية': '🏫 Academy Details',
    'الاسم الرسمي ومعلومات المؤسسة التعليمية.': 'Official name and educational institution information.',
    'اسم المنصة (عربي)': 'Academy Name (Arabic)',
    'الشعار (عربي)': 'Academy Slogan (Arabic)',
    '👨‍⚕️ بيانات المؤسس والمحاضر الرئيسي': '👨‍⚕️ Founder & Lead Instructor Profile',
    'معلومات د. خالد عبد الناصر المرسي.': 'Dr. Khaled Abdel-Nasser El-Morsy details.',
    'اسم المؤسس': 'Founder Name',
    'اللقب المهني': 'Professional Title',
    'نبذة قصيرة (عربي)': 'Short Bio (Arabic)',
    '📞 بيانات التواصل': '📞 Contact & Payment Details',
    'أرقام الهاتف والبريد وطرق الدفع.': 'Phone numbers, emails, and payment methods.',
    'رقم التواصل العام': 'General Contact Phone',
    'رقم واتساب': 'WhatsApp Number',
    'العنوان (عربي)': 'Address (Arabic)',
    'رقم فودافون كاش': 'Vodafone Cash Number',
    'رقم إنستاباي': 'InstaPay Account',
    'تعليمات الدفع': 'Payment Instructions',
    '🎨 الثيم والهوية البصرية': '🎨 Theme & Visual Identity',
    'اختر هوية طبية جاهزة أو خصص ألوان المنصة، وبدّل بين خطوط Google العربية.': 'Choose a royal medical palette and switch between Google Arabic fonts.',
    'الثيم الطبي': 'Medical Theme',
    'رعاية تركوازية — الهوية الحالية': 'Teal Care — Current Identity',
    'الأزرق السريري': 'Clinical Blue',
    'الأخضر الإكلينيكي': 'Emerald Clinic',
    'البنفسجي الأكاديمي': 'Academic Violet',
    'الأزرق القطبي': 'Arctic Care',
    'ألوان مخصصة': 'Custom Colors',
    'اللون الرئيسي (Primary Teal)': 'Primary Color (Teal)',
    'اللون الداكن (Navy)': 'Dark Navy Color',
    'لون الأكسنت (Emerald)': 'Accent Color (Emerald)',
    'لون الإبراز': 'Highlight Color',
    'Alexandria': 'Alexandria',
    'Readex Pro': 'Readex Pro',
    'Noto Sans Arabic': 'Noto Sans Arabic',
    'معاينة للهوية والخط — Nursing Care': 'Theme and font preview — Nursing Care',
    'الخط الرئيسي': 'Primary Font',
    'حفظ جميع الإعدادات': 'Save All Settings',

    // Admin Profile
    'ملف حساب المدير الأكاديمي 👤': 'Admin Account Profile 👤',
    'بيانات الاعتماد والأمان الخاصة بمسؤول النظام والتحكم بصلاحيات الدخول.': 'Security credentials and access privileges for system administrator.',
    '👤 بيانات الحساب الأساسية': '👤 Primary Account Details',
    'الاسم والبريد الإلكتروني المسجل في النظام.': 'Registered name and system administrative email.',
    'الاسم الكامل': 'Full Name',
    'البريد الإلكتروني': 'Email Address',
    'حفظ البيانات': 'Save Details',
    '🔒 تغيير كلمة المرور': '🔒 Change Password',
    'تحديث كلمة مرور الدخول للوحة الإدارة.': 'Update admin login password securely.',
    'كلمة المرور الحالية': 'Current Password',
    'كلمة المرور الجديدة': 'New Password',
    'تأكيد كلمة المرور الجديدة': 'Confirm New Password',
    'تحديث كلمة المرور': 'Update Password',

    // Table Headers & Badges
    'الطالب': 'Student',
    'رقم الهاتف': 'Phone',
    'الكورسات المسجلة': 'Courses',
    'الحالة': 'Status',
    'تاريخ الانضمام': 'Joined Date',
    'الإجراءات': 'Actions',
    'التخصص': 'Specialty',
    'سنوات الخبرة': 'Experience',
    'التقييم': 'Rating',
    'السعر': 'Price',
    'عرض التفاصيل': 'View Details',
    'تعديل': 'Edit',
    'إيقاف': 'Suspend',
    'تفعيل': 'Activate',
    'حذف': 'Delete',
    'نشط': 'Active',
    'غير نشط': 'Inactive',
    'موقوف': 'Suspended',
    'معلّق': 'Pending',
    'قيد المراجعة': 'Under Review',
    'مؤكد': 'Confirmed',
    'مرفوض': 'Rejected',
    'مسترد': 'Refunded',
    'فاشل': 'Failed',
    'مسودة': 'Draft',
    'منشور': 'Published',
    'مؤرشف': 'Archived',
    'مكتمل': 'Completed',
    'ناجح': 'Passed',
    'راسب': 'Failed',
    'صادرة': 'Issued',
    'ملغاة': 'Revoked',
    'مبتدئ': 'Beginner',
    'متوسط': 'Intermediate',
    'متقدم': 'Advanced',
    'إنشاء الحساب': 'Create Account',
    'حفظ التعديلات': 'Save Changes',
    'إلغاء': 'Cancel',
    'تصدير CSV': 'Export CSV',
    '← العودة إلى قائمة الطلاب': '← Back to Students',
    'تعديل البيانات': 'Edit Details',
    'إيقاف الحساب': 'Suspend Account',
    'تفعيل الحساب': 'Activate Account'
  };

  const ADMIN_ALL_TRANSLATIONS = Object.assign({}, ADMIN_TEXT_MAP, Object.fromEntries(Object.keys(ADMIN_I18N.ar).filter(key => typeof ADMIN_I18N.ar[key] === 'string' && typeof ADMIN_I18N.en[key] === 'string').map(key => [ADMIN_I18N.ar[key], ADMIN_I18N.en[key]])));
  let adminLang = localStorage.getItem('nbc_lang') || localStorage.getItem('nbc_admin_lang') || 'ar';

  function translateDom(root, lang) {
    if (!root) return;

    if (lang === 'en') {
      // 1. data-i18n
      root.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.dataset.i18n;
        if (ADMIN_I18N.en[key]) {
          if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
            el.placeholder = ADMIN_I18N.en[key];
          } else {
            el.textContent = ADMIN_I18N.en[key];
          }
        }
      });

      // 2. data-i18n-placeholder
      root.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
        const key = el.dataset.i18nPlaceholder;
        if (ADMIN_I18N.en[key]) {
          el.placeholder = ADMIN_I18N.en[key];
        }
      });

      // 3. Fallback scan for plain text in common elements
      const selector = 'button, h1, h2, h3, h4, th, .label, .sub, .pill, .badge, .status, .eyebrow, small, legend, a.btn, label > span';
      root.querySelectorAll(selector).forEach(el => {
        if (el.children.length === 0 || (el.children.length === 1 && el.querySelector('svg, span.status-dot'))) {
          // If only SVG icon or status dot, translate remaining text
          const directText = Array.from(el.childNodes)
            .filter(n => n.nodeType === Node.TEXT_NODE)
            .map(n => n.textContent.trim())
            .filter(Boolean)
            .join(' ');

          if (directText && ADMIN_TEXT_MAP[directText]) {
            if (!el.dataset.origText) el.dataset.origText = directText;
            Array.from(el.childNodes).forEach(n => {
              if (n.nodeType === Node.TEXT_NODE && n.textContent.trim()) {
                n.textContent = n.textContent.replace(directText, ADMIN_TEXT_MAP[directText]);
              }
            });
          }
        } else if (el.children.length === 0) {
          const txt = el.textContent.trim();
          if (ADMIN_TEXT_MAP[txt]) {
            if (!el.dataset.origText) el.dataset.origText = txt;
            el.textContent = ADMIN_TEXT_MAP[txt];
          }
        }
      });
    } else {
      // Restore Arabic
      root.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.dataset.i18n;
        if (ADMIN_I18N.ar[key]) {
          if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
            el.placeholder = ADMIN_I18N.ar[key];
          } else {
            el.textContent = ADMIN_I18N.ar[key];
          }
        }
      });

      root.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
        const key = el.dataset.i18nPlaceholder;
        if (ADMIN_I18N.ar[key]) {
          el.placeholder = ADMIN_I18N.ar[key];
        }
      });

      root.querySelectorAll('[data-orig-text]').forEach(el => {
        const orig = el.dataset.origText;
        if (orig) {
          // If element has text nodes, replace text
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
    window.PlatformLanguage?.setDictionary(ADMIN_ALL_TRANSLATIONS);
    window.PlatformLanguage?.apply(root, lang, ADMIN_ALL_TRANSLATIONS);
  }

  function applyAdminLang(lang) {
    adminLang = lang === 'en' ? 'en' : 'ar';
    document.documentElement.setAttribute('lang', adminLang);
    document.documentElement.setAttribute('dir', adminLang === 'ar' ? 'rtl' : 'ltr');
    const lbl = document.getElementById('adminLangLabel');
    if (lbl) lbl.textContent = adminLang === 'ar' ? 'EN' : 'AR';
    const loginLbl = document.getElementById('loginLangLabel');
    if (loginLbl) loginLbl.textContent = adminLang === 'ar' ? 'EN' : 'AR';
    localStorage.setItem('nbc_admin_lang', adminLang);
    if (localStorage.getItem('nbc_lang') !== adminLang) localStorage.setItem('nbc_lang', adminLang);

    const dict = ADMIN_I18N[adminLang] || ADMIN_I18N.ar;
    document.title = dict.doc_title || 'Nursing Basic Course';

    translateDom(document, adminLang);
    if (document.querySelector('.side-link.tab.active')?.dataset.tab === 'reviews') renderAdminReviews();

    // Update breadcrumb current
    const activeTab = document.querySelector('.side-link.tab.active')?.dataset?.tab || 'overview';
    const breadcrumbCurrent = document.getElementById('breadcrumbCurrent');
    if (breadcrumbCurrent) {
      const tabKeyMap = {
        'overview': 'nav_overview',
        'students': 'nav_students',
        'instructors': 'nav_instructors',
        'courses': 'nav_courses',
        'reviews': 'nav_reviews',
        'settings': 'nav_settings',
        'admin-profile': 'nav_admin_profile'
      };
      if (tabKeyMap[activeTab] && dict[tabKeyMap[activeTab]]) {
        breadcrumbCurrent.textContent = dict[tabKeyMap[activeTab]];
      }
    }

    // If student details view is active, rerender it with active language
    if (window.StudentDetails && typeof window.StudentDetails.rerender === 'function') {
      window.StudentDetails.rerender();
    }
  }

  window.addEventListener('platform:languagechange', event => {
    const next = event.detail === 'en' ? 'en' : 'ar';
    if (adminLang !== next) applyAdminLang(next);
  });

  // Bind toggle click directly
  function setupLangToggle() {
    ['adminLangToggle', 'loginLangToggle'].forEach(id => {
      const btn = document.getElementById(id);
      if (btn && !btn.dataset.bound) {
        btn.dataset.bound = 'true';
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          const nextLang = adminLang === 'ar' ? 'en' : 'ar';
          applyAdminLang(nextLang);
        });
      }
    });
  }

  setupLangToggle();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      setupLangToggle();
      applyAdminLang(adminLang);
    });
  } else {
    applyAdminLang(adminLang);
  }

  // MutationObserver to auto-translate newly loaded AJAX tables/content in English mode
  const observer = new MutationObserver((mutations) => {
    if (adminLang === 'en') {
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

  // Load Settings & Profile & Instructors when their panels become active
  document.addEventListener('click', e => {
    const reviewAction = e.target.closest('[data-review-action]');
    if (reviewAction) {
      const id = Number(reviewAction.dataset.reviewId);
      const action = reviewAction.dataset.reviewAction;
      if (id > 0) updateAdminReview(id, action);
      return;
    }
    if (e.target.closest('#btnRefreshReviews')) {
      loadAdminReviews();
      return;
    }
    const tab = e.target.closest('[data-tab]');
    if (!tab) return;
    const t = tab.dataset.tab;
    if (t === 'settings') setTimeout(loadSettings, 50);
    if (t === 'admin-profile') setTimeout(loadAdminProfile, 50);
    if (t === 'instructors') {
      setTimeout(() => {
        if (window.InstructorsAdmin && typeof window.InstructorsAdmin.load === 'function') {
          window.InstructorsAdmin.load();
        }
      }, 50);
    }
    applyStudentsView(); // re-apply view on students tab open
  });

  document.addEventListener('input', event => {
    if (event.target.matches('#reviewsSearch')) renderAdminReviews();
  });
  document.addEventListener('change', event => {
    if (event.target.matches('#reviewsStatusFilter, #reviewsTypeFilter')) renderAdminReviews();
  });

})();
