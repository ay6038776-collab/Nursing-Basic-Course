(() => {
  'use strict';

  const scriptOptions = document.currentScript?.dataset || {};
  const generateEndpoint = scriptOptions.endpoint || 'api/admin/ai-image-generate.php';
  const csrfStorageKey = scriptOptions.csrfStorage || 'nbc_admin_csrf';
  const csrfGlobal = scriptOptions.csrfGlobal || '';
  const modal = document.getElementById('nanoBananaModal');
  if (!modal) return;
  const promptInput = document.getElementById('nanoBananaPrompt');
  const message = document.getElementById('nanoBananaMessage');
  const previewWrap = document.getElementById('nanoBananaPreviewWrap');
  const preview = document.getElementById('nanoBananaPreview');
  const generateButton = document.getElementById('nanoBananaGenerate');
  let targetInput = null;
  let generatedBlob = null;
  let generatedMime = 'image/png';

  function showMessage(text, isError = false) {
    message.textContent = text;
    message.hidden = !text;
    message.classList.toggle('notice-error', isError);
    message.classList.toggle('notice-success', !isError && !!text);
  }

  function closeModal() {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
  }

  function openModal(input) {
    targetInput = input;
    generatedBlob = null;
    previewWrap.hidden = true;
    showMessage('');
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    promptInput.focus();
  }

  function addButton(input) {
    if (input.dataset.nanoBananaBound || !input.accept || !/image\//i.test(input.accept)) return;
    input.dataset.nanoBananaBound = '1';
    const host = input.closest('label') || input.parentElement;
    if (!host) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'nano-banana-trigger';
    button.textContent = '🍌 توليد بـ Nano Banana 2';
    button.setAttribute('aria-label', 'توليد صورة باستخدام Nano Banana 2');
    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      openModal(input);
    });
    input.insertAdjacentElement('afterend', button);
  }

  document.querySelectorAll('input[type="file"]').forEach(addButton);
  new MutationObserver((records) => records.forEach((record) => record.addedNodes.forEach((node) => {
    if (!(node instanceof Element)) return;
    if (node.matches?.('input[type="file"]')) addButton(node);
    node.querySelectorAll?.('input[type="file"]').forEach(addButton);
  }))).observe(document.body, { childList: true, subtree: true });

  document.getElementById('nanoBananaClose').addEventListener('click', closeModal);
  modal.addEventListener('click', (event) => { if (event.target === modal) closeModal(); });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !modal.hidden) closeModal(); });

  generateButton.addEventListener('click', async () => {
    const prompt = promptInput.value.trim();
    if (prompt.length < 8) {
      showMessage('اكتب وصفًا واضحًا للصورة قبل التوليد.', true);
      promptInput.focus();
      return;
    }
    generateButton.disabled = true;
    showMessage('جارٍ توليد الصورة الاحترافية… قد يستغرق ذلك بعض الوقت.');
    previewWrap.hidden = true;
    try {
      const csrf = (csrfGlobal && typeof window[csrfGlobal] === 'function' ? window[csrfGlobal]() : '') || sessionStorage.getItem(csrfStorageKey) || '';
      const headers = { 'Content-Type': 'application/json' };
      if (csrf) headers['X-CSRF-Token'] = csrf;
      const response = await fetch(generateEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers,
        body: JSON.stringify({ prompt, aspect_ratio: document.getElementById('nanoBananaRatio').value }),
      });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'تعذر توليد الصورة.');
      generatedMime = result.mime_type || 'image/png';
      const binary = atob(result.image_base64);
      const bytes = new Uint8Array(binary.length);
      for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
      generatedBlob = new Blob([bytes], { type: generatedMime });
      const previewUrl = URL.createObjectURL(generatedBlob);
      preview.onload = () => URL.revokeObjectURL(previewUrl);
      preview.src = previewUrl;
      const download = document.getElementById('nanoBananaDownload');
      download.href = previewUrl;
      download.download = `nano-banana-2.${generatedMime === 'image/jpeg' ? 'jpg' : generatedMime === 'image/webp' ? 'webp' : 'png'}`;
      previewWrap.hidden = false;
      showMessage('تم توليد الصورة. راجعها ثم استخدمها في الحقل المحدد.');
    } catch (error) {
      showMessage(error.message || 'حدث خطأ أثناء توليد الصورة.', true);
    } finally {
      generateButton.disabled = false;
    }
  });

  document.getElementById('nanoBananaUse').addEventListener('click', () => {
    if (!targetInput || !generatedBlob) return;
    const extension = generatedMime === 'image/jpeg' ? 'jpg' : generatedMime === 'image/webp' ? 'webp' : 'png';
    const file = new File([generatedBlob], `nano-banana-2-${Date.now()}.${extension}`, { type: generatedMime });
    const transfer = new DataTransfer();
    if (targetInput.multiple) Array.from(targetInput.files || []).forEach((existing) => transfer.items.add(existing));
    transfer.items.add(file);
    targetInput.files = transfer.files;
    targetInput.dispatchEvent(new Event('change', { bubbles: true }));
    targetInput.dispatchEvent(new Event('input', { bubbles: true }));
    closeModal();
  });
})();
