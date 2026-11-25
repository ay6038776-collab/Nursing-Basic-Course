/* responsive-nav.js
   ينقل أزرار الدخول/التسجيل (.auth-actions) من الهيدر إلى قايمة البرجر (.mobile-nav-panel)
   لما الشاشة تبقى 960px أو أقل، ويرجّعها للهيدر لما الشاشة تكبر.
   بينقل نفس العناصر (مش نسخة) فالروابط والأحداث تفضل شغالة.
   التركيب: <script src="asset/responsive-nav.js" defer></script> قبل </body> */
(function () {
  'use strict';

  var mq = window.matchMedia('(max-width: 960px)');

  function ensureSlot(panel) {
    var slot = panel.querySelector('.mobile-auth');
    if (!slot) {
      slot = document.createElement('div');
      slot.className = 'mobile-auth';
      panel.appendChild(slot);
    }
    return slot;
  }

  function sync() {
    var panel = document.querySelector('.mobile-nav-panel');
    var header = document.querySelector('.header-actions');

    if (mq.matches) {
      // موبايل/تابلت: الأزرار تدخل قايمة البرجر
      if (!panel) return;
      var live = document.querySelector('.header-actions .auth-actions');
      if (live) {
        var slot = ensureSlot(panel);
        slot.textContent = '';            // يشيل أي نسخة قديمة لو الهيدر اتعاد رسمه
        slot.appendChild(live);
      }
    } else {
      // شاشة كبيرة: الأزرار ترجع للهيدر
      var s = document.querySelector('.mobile-nav-panel .mobile-auth');
      var el = s && s.querySelector('.auth-actions');
      if (!el || !header) return;
      if (header.querySelector('.auth-actions')) {
        el.remove();                       // الهيدر فيه نسخة جديدة بالفعل
      } else {
        var toggle = header.querySelector('.menu-toggle');
        header.insertBefore(el, toggle && toggle.parentNode === header ? toggle : null);
      }
    }
  }

  var queued = false;
  function schedule() {
    if (queued) return;
    queued = true;
    (window.requestAnimationFrame || setTimeout)(function () { queued = false; sync(); });
  }

  if (mq.addEventListener) mq.addEventListener('change', sync);
  else if (mq.addListener) mq.addListener(sync);

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', sync);
  else sync();

  // الهيدر/القايمة ممكن تتبني أو تتحدّث بالـ JS
  new MutationObserver(schedule).observe(document.documentElement, { childList: true, subtree: true });

  // الضغط على أي زر داخل القايمة يقفلها
  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('.mobile-auth a, .mobile-auth button');
    if (!t) return;
    var nav = t.closest('.mobile-nav');
    if (nav) nav.classList.remove('open');
  });
})();