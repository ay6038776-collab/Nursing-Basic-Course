(function () {
  'use strict';

  const originals = new WeakMap();
  const attributeOriginals = new WeakMap();
  let dictionary = {};
  let supplementalDictionary = {};

  function locale(lang = document.documentElement.lang) {
    return lang === 'en' ? 'en-US' : 'ar-EG';
  }

  function preserveWhitespace(source, translated) {
    const leading = source.match(/^\s*/)?.[0] || '';
    const trailing = source.match(/\s*$/)?.[0] || '';
    return leading + translated + trailing;
  }

  function sourceFor(node) {
    const cached = originals.get(node);
    return cached && node.nodeValue === cached.output ? cached.source : node.nodeValue;
  }

  function replaceKnownPhrases(value, map) {
    const phrases = Object.keys(map).filter(key => key.length >= 4 && key !== '__reverse').sort((a, b) => b.length - a.length);
    for (const phrase of phrases) {
      if (!value.includes(phrase) || typeof map[phrase] !== 'string') continue;
      value = value.replaceAll(phrase, map[phrase]);
    }
    return value;
  }

  function localizeText(node, lang, map = dictionary) {
    if (!node || !node.nodeValue || !node.parentElement) return;
    const parent = node.parentElement;
    // Do not skip an entire English page just because its document direction is LTR.
    // Direction controls layout; it must not disable language replacement.
    if (parent.closest('script,style,noscript,code,pre,[data-no-localize],input,textarea')) return;

    const source = sourceFor(node);
    const trimmed = source.trim();
    let translated = source;
    if (lang === 'en') {
      const exact = map[trimmed];
      if (exact) translated = preserveWhitespace(source, exact);
      else translated = replaceKnownPhrases(source, map);
      translated = translated.replace(/ج\.\s*م/g, 'EGP').replace(/جنيه(?:\s+مصري)?/g, 'Egyptian pound').replace(/٪/g, '%');
      translated = translated.replace(/[٠-٩]/g, digit => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)));
    } else {
      const reverse = map.__reverse || (map.__reverse = Object.fromEntries(Object.entries(map).filter(([, value]) => typeof value === 'string').map(([key, value]) => [value, key])));
      if (reverse[trimmed]) translated = preserveWhitespace(source, reverse[trimmed]);
      else translated = replaceKnownPhrases(source, reverse);
      translated = translated.replace(/\bEGP\b/g, 'ج.م').replace(/Egyptian pounds?/gi, 'جنيه مصري').replace(/%/g, '٪');
      translated = translated.replace(/[0-9]/g, digit => '٠١٢٣٤٥٦٧٨٩'[Number(digit)]);
    }

    if (translated !== node.nodeValue) {
      originals.set(node, { source, output: translated });
      node.nodeValue = translated;
    } else if (!originals.has(node)) {
      originals.set(node, { source, output: translated });
    }
  }

  function localizeAttribute(element, name, lang, map = dictionary) {
    const current = element.getAttribute(name);
    if (current == null) return;
    let values = attributeOriginals.get(element);
    if (!values) { values = {}; attributeOriginals.set(element, values); }
    const cached = values[name];
    const source = cached && current === cached.output ? cached.source : current;
    const translated = lang === 'en' ? (map[source] || source) : (map.__reverse?.[source] || source);
    if (translated !== current) {
      values[name] = { source, output: translated };
      element.setAttribute(name, translated);
    }
  }

  function apply(root = document, lang = document.documentElement.lang, map = dictionary) {
    if (!root) return;
    const target = lang === 'en' ? 'en' : 'ar';
    if (root.nodeType === Node.TEXT_NODE) { localizeText(root, target, map); return; }
    if (root.nodeType === Node.ELEMENT_NODE) {
      ['placeholder', 'title', 'aria-label'].forEach(name => localizeAttribute(root, name, target, map));
    }
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let node;
    while ((node = walker.nextNode())) localizeText(node, target, map);
    if (root.querySelectorAll) root.querySelectorAll('[placeholder],[title],[aria-label]').forEach(element => {
      ['placeholder', 'title', 'aria-label'].forEach(name => localizeAttribute(element, name, target, map));
    });
  }

  function setLanguage(lang, persist = false) {
    const target = lang === 'en' ? 'en' : 'ar';
    document.documentElement.lang = target;
    document.documentElement.dir = target === 'ar' ? 'rtl' : 'ltr';
    if (persist) {
      try { if (localStorage.getItem('nbc_lang') !== target) localStorage.setItem('nbc_lang', target); } catch (_) {}
    }
    apply(document, target, dictionary);
    window.dispatchEvent(new CustomEvent('platform:languagechange', { detail: target }));
  }

  function setDictionary(map) {
    dictionary = { ...(map && typeof map === 'object' ? map : {}), ...supplementalDictionary };
    apply(document, document.documentElement.lang, dictionary);
  }

  window.PlatformLanguage = {
    locale,
    number(value, options) { return new Intl.NumberFormat(locale(), options).format(Number(value ?? 0)); },
    money(value, options = {}) { return new Intl.NumberFormat(locale(), { style: 'currency', currency: 'EGP', maximumFractionDigits: 2, ...options }).format(Number(value ?? 0)); },
    date(value, options) { return new Intl.DateTimeFormat(locale(), options).format(value instanceof Date ? value : new Date(value)); },
    apply,
    setDictionary,
    addDictionary(map) {
      if (map && typeof map === 'object') {
        supplementalDictionary = { ...supplementalDictionary, ...map };
        dictionary = { ...dictionary, ...map };
      }
      apply(document, document.documentElement.lang, dictionary);
    },
    setLanguage
  };

  window.addEventListener('storage', event => {
    if (event.key === 'nbc_lang' && (event.newValue === 'ar' || event.newValue === 'en')) setLanguage(event.newValue, false);
  });

  const observer = new MutationObserver(records => {
    const lang = document.documentElement.lang === 'en' ? 'en' : 'ar';
    for (const record of records) {
      if (record.type === 'characterData') localizeText(record.target, lang, dictionary);
      for (const node of record.addedNodes || []) {
        if (node.nodeType === Node.ELEMENT_NODE || node.nodeType === Node.TEXT_NODE) apply(node, lang, dictionary);
      }
    }
  });
  observer.observe(document.documentElement, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['lang'] });
  apply(document, document.documentElement.lang, dictionary);
  window.dispatchEvent(new Event('platform:languageruntime-ready'));
})();
