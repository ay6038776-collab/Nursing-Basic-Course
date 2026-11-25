(() => {
  'use strict';

  const STORAGE_KEY = 'nbc_home_design';
  const VALID = new Set(['royal-luxury', 'medical-premium', 'modern-academy', 'european-corporate']);
  const LABELS = {
    'royal-luxury': 'الفخامة الملكية',
    'medical-premium': 'الطبي المميز',
    'modern-academy': 'الأكاديمية العصرية',
    'european-corporate': 'المؤسسي الأوروبي'
  };
  const UI_TRANSLATIONS = {
    'مظهر الصفحة الرئيسية': 'Homepage Appearance',
    'عاين واختر هوية مستقلة للواجهة الرئيسية. الاختيار محفوظ على هذا المتصفح ولا يغير إعدادات المنصة أو بياناتها.': 'Preview and choose a distinct homepage identity. This selection is saved in this browser and does not change platform settings or data.',
    'التصميم النشط:': 'Active design:',
    'الفخامة الملكية': 'Royal Luxury',
    'كحلي ملكي ولمسات ذهبية، مع واجهة أكاديمية سينمائية.': 'Royal navy and gold accents with a cinematic academy layout.',
    'الطبي المميز': 'Medical Premium',
    'أخضر طبي وتركواز ومساحات بيضاء واضحة.': 'Medical green, teal, and clean white space.',
    'الأكاديمية العصرية': 'Modern Academy',
    'بنفسجي وإنديغو وتوزيع ديناميكي لمحتوى التعلم.': 'Purple, indigo, and a dynamic learning layout.',
    'المؤسسي الأوروبي': 'European Corporate',
    'فحمي وفضي وأزرق رمادي بهدوء مؤسسي.': 'Charcoal, silver, and muted blue-grey in a formal layout.',
    'معاينة': 'Preview', 'تطبيق': 'Apply', 'التصميم النشط': 'Active design',
    'المعاينة تفتح الصفحة الرئيسية في تبويب جديد من دون تفعيل التصميم.': 'Preview opens the homepage in a new tab without activating the design.',
    '↩ استعادة التصميم الأصلي': '↩ Restore original design'
  };
  let memoryTheme = 'medical-premium';

  function normalize(value) { return VALID.has(value) ? value : 'medical-premium'; }
  function installTranslations() { window.PlatformLanguage?.addDictionary(UI_TRANSLATIONS); }
  function readTheme() {
    try { return normalize(localStorage.getItem(STORAGE_KEY) || 'medical-premium'); }
    catch (_) { return memoryTheme; }
  }
  function saveTheme(theme) {
    memoryTheme = normalize(theme);
    try { localStorage.setItem(STORAGE_KEY, memoryTheme); } catch (_) {}
    return memoryTheme;
  }
  function applyTheme(theme) {
    theme = normalize(theme);
    memoryTheme = theme;
    const home = document.getElementById('page-home');
    if (home) home.dataset.homeDesign = theme;
    document.documentElement.dataset.homeDesign = theme;
    document.dispatchEvent(new CustomEvent('homepage:designchange', { detail: theme }));
  }
  function renderManager(active) {
    document.querySelectorAll('[data-home-design-card]').forEach(card => {
      const selected = card.dataset.homeDesignCard === active;
      card.classList.toggle('is-active', selected);
      card.setAttribute('aria-checked', String(selected));
      const badge = card.querySelector('.home-design-selected');
      if (badge) badge.hidden = !selected;
    });
    const summary = document.getElementById('homeDesignActiveSummary');
    if (summary) summary.textContent = `التصميم النشط: ${LABELS[active] || LABELS['medical-premium']}`;
  }
  function preview(theme) {
    const url = new URL('index.html', window.location.href);
    url.searchParams.set('homePreview', normalize(theme));
    url.hash = 'home';
    window.open(url.href, '_blank', 'noopener');
  }
  function initManager() {
    renderManager(readTheme());
    document.querySelectorAll('[data-home-design-preview]').forEach(button => {
      button.addEventListener('click', () => preview(button.dataset.homeDesignPreview));
    });
    document.querySelectorAll('[data-home-design-apply]').forEach(button => {
      button.addEventListener('click', () => {
        const active = saveTheme(button.dataset.homeDesignApply);
        renderManager(active);
        applyTheme(active);
      });
    });
    document.getElementById('homeDesignRestore')?.addEventListener('click', () => {
      const active = saveTheme('medical-premium');
      renderManager(active);
      applyTheme(active);
    });
  }

  const queryPreview = new URLSearchParams(window.location.search).get('homePreview');
  applyTheme(queryPreview && VALID.has(queryPreview) ? queryPreview : readTheme());

  if (window.PlatformLanguage?.addDictionary) installTranslations();
  else window.addEventListener('platform:languageruntime-ready', installTranslations, { once: true });

  if (document.getElementById('homeDesignManager')) initManager();
  window.addEventListener('storage', event => {
    if (event.key === STORAGE_KEY && event.newValue) {
      const active = normalize(event.newValue);
      applyTheme(active);
      renderManager(active);
    }
  });
  window.HomepageDesigns = { get: readTheme, apply: applyTheme, save: saveTheme, options: [...VALID] };
})();
