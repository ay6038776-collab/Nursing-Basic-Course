/* responsive-tables.js
   يحوّل جداول الصفحة لكروت على الموبايل (مع responsive.css):
   - ينسخ عنوان كل عمود (<th>) إلى data-label على كل <td>
   - يضيف class="rt-cards" على الجدول
   - يشتغل تلقائيًا على الجداول اللي بتتبني/تتحدث بالـ JS
   لاستثناء جدول:  <table data-no-cards>
   التركيب: <script src="asset/responsive-tables.js" defer></script> قبل </body> */
(function () {
  'use strict';

  function clean(t) { return (t || '').replace(/\s+/g, ' ').trim(); }

  function prepare(table) {
    if (table.hasAttribute('data-no-cards')) return;

    var ths = table.querySelectorAll('thead th');
    if (!ths.length && table.rows.length) {
      ths = table.rows[0].querySelectorAll('th');
    }
    if (!ths.length) return;

    // خريطة الأعمدة مع مراعاة colspan في الرأس
    var labels = [];
    Array.prototype.forEach.call(ths, function (th) {
      var span = th.colSpan || 1;
      for (var i = 0; i < span; i++) labels.push(clean(th.textContent));
    });

    Array.prototype.forEach.call(table.querySelectorAll('tr'), function (tr) {
      if (tr.closest('thead')) return;
      var col = 0;
      Array.prototype.forEach.call(tr.children, function (cell) {
        if (cell.tagName !== 'TD' && cell.tagName !== 'TH') return;
        var span = cell.colSpan || 1;
        var label = span > 1 ? '' : (labels[col] || '');
        if (cell.getAttribute('data-label') !== label) cell.setAttribute('data-label', label);

        // خلية إجراءات = فيها أزرار/روابط وملهاش نص تاني
        var ctl = cell.querySelectorAll('button, a, .btn, .small-btn, input[type=checkbox]');
        var isActions = false;
        if (ctl.length) {
          var probe = cell.cloneNode(true);
          Array.prototype.forEach.call(probe.querySelectorAll('button, a, .btn, .small-btn, svg, i, input, form'), function (n) { n.remove(); });
          isActions = clean(probe.textContent) === '';
        }
        if (isActions) { if (!cell.hasAttribute('data-actions')) cell.setAttribute('data-actions', '1'); }
        else if (cell.hasAttribute('data-actions')) cell.removeAttribute('data-actions');

        col += span;
      });
    });

    table.classList.add('rt-cards');
  }

  function run() {
    Array.prototype.forEach.call(document.querySelectorAll('table'), prepare);
  }

  var scheduled = false;
  function schedule() {
    if (scheduled) return;
    scheduled = true;
    (window.requestAnimationFrame || setTimeout)(function () {
      scheduled = false;
      run();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }

  // الجداول المتولّدة/المحدّثة ديناميكيًا (الكود بيعدّل data-label فقط → لا يوجد loop)
  new MutationObserver(schedule).observe(document.documentElement, { childList: true, subtree: true });
})();