(function () {
  'use strict';
  const MIME_EXT = { 'image/jpeg':'jpg', 'image/png':'png', 'image/webp':'webp' };
  let active = false;

  function buildModal() {
    let modal = document.getElementById('imageCropperModal');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.id = 'imageCropperModal';
    modal.className = 'image-cropper-backdrop';
    modal.hidden = true;
    modal.innerHTML = `<section class="image-cropper-dialog" role="dialog" aria-modal="true" aria-labelledby="imageCropperTitle">
      <h2 id="imageCropperTitle">قص الصورة قبل الرفع</h2><p>اسحب إطار القص لتحديد الجزء المطلوب، واختر المقاس المناسب. يمكنك إبقاء الصورة كاملة.</p>
      <div class="image-cropper-canvas-wrap"><canvas class="image-cropper-canvas" aria-label="معاينة وإطار قص الصورة"></canvas></div>
      <div class="image-cropper-controls"><label>مقاس الصورة<select class="image-cropper-ratio"><option value="original">الصورة كاملة</option><option value="1">مربع 1:1 — شعار أو ملف شخصي</option><option value="1.333333">4:3 — غلاف كتاب</option><option value="1.5">3:2 — صورة كورس أو مهارة</option><option value="1.777778">16:9 — سلايدر وفيديو</option></select></label>
      <label class="image-cropper-zoom">حجم القص <input type="range" min="25" max="100" value="100"></label><span class="image-cropper-queue"></span></div>
      <div class="image-cropper-error" role="alert" hidden></div><div class="image-cropper-actions"><button class="image-cropper-confirm" type="button">استخدام الصورة</button><button class="image-cropper-cancel" type="button">إلغاء</button></div>
    </section>`;
    document.body.appendChild(modal);
    return modal;
  }

  function readImage(file) {
    return new Promise((resolve, reject) => {
      const image = new Image();
      const url = URL.createObjectURL(file);
      image.onload = () => { URL.revokeObjectURL(url); resolve(image); };
      image.onerror = () => { URL.revokeObjectURL(url); reject(new Error('تعذر فتح الصورة المحددة.')); };
      image.src = url;
    });
  }

  function cropOne(file, index, total) {
    return new Promise(async (resolve, reject) => {
      const modal = buildModal();
      const canvas = modal.querySelector('canvas');
      const ctx = canvas.getContext('2d');
      const ratioSelect = modal.querySelector('select');
      const zoom = modal.querySelector('input[type="range"]');
      const queue = modal.querySelector('.image-cropper-queue');
      const error = modal.querySelector('.image-cropper-error');
      const confirmButton = modal.querySelector('.image-cropper-confirm');
      const cancelButton = modal.querySelector('.image-cropper-cancel');
      let image;
      try { image = await readImage(file); } catch (e) { reject(e); return; }
      const W = 1200, H = 720;
      canvas.width = W; canvas.height = H;
      const scale = Math.min(W / image.naturalWidth, H / image.naturalHeight);
      const imageRect = { x:(W-image.naturalWidth*scale)/2, y:(H-image.naturalHeight*scale)/2, w:image.naturalWidth*scale, h:image.naturalHeight*scale };
      let frame = { ...imageRect };
      let dragging = false, dragOffset = {x:0,y:0}, settled = false;
      modal.hidden = false;
      queue.textContent = total > 1 ? `الصورة ${index + 1} من ${total}` : file.name;
      error.hidden = true;

      function setFrame(ratio, percent = 100) {
        const maximum = Math.min(imageRect.w, imageRect.h * ratio);
        const width = Math.min(imageRect.w, maximum) * percent / 100;
        const height = width / ratio;
        frame = { x:imageRect.x+(imageRect.w-width)/2, y:imageRect.y+(imageRect.h-height)/2, w:width, h:height };
        draw();
      }
      function draw() {
        ctx.clearRect(0,0,W,H);
        ctx.fillStyle = '#07111f'; ctx.fillRect(0,0,W,H);
        ctx.drawImage(image,imageRect.x,imageRect.y,imageRect.w,imageRect.h);
        ctx.fillStyle = 'rgba(2,8,18,.68)';
        ctx.fillRect(imageRect.x,imageRect.y,imageRect.w,Math.max(0,frame.y-imageRect.y));
        ctx.fillRect(imageRect.x,frame.y+frame.h,imageRect.w,Math.max(0,imageRect.y+imageRect.h-frame.y-frame.h));
        ctx.fillRect(imageRect.x,frame.y,Math.max(0,frame.x-imageRect.x),frame.h);
        ctx.fillRect(frame.x+frame.w,frame.y,Math.max(0,imageRect.x+imageRect.w-frame.x-frame.w),frame.h);
        ctx.strokeStyle='#2dd4bf'; ctx.lineWidth=3; ctx.strokeRect(frame.x,frame.y,frame.w,frame.h);
        ctx.strokeStyle='rgba(255,255,255,.35)';ctx.lineWidth=1;
        for(let i=1;i<3;i++){ctx.beginPath();ctx.moveTo(frame.x+frame.w*i/3,frame.y);ctx.lineTo(frame.x+frame.w*i/3,frame.y+frame.h);ctx.moveTo(frame.x,frame.y+frame.h*i/3);ctx.lineTo(frame.x+frame.w,frame.y+frame.h*i/3);ctx.stroke();}
        const handle=Math.min(18,Math.max(8,frame.w*.025));ctx.fillStyle='#2dd4bf';
        [[frame.x,frame.y],[frame.x+frame.w,frame.y],[frame.x,frame.y+frame.h],[frame.x+frame.w,frame.y+frame.h]].forEach(([x,y])=>ctx.fillRect(x-handle/2,y-handle/2,handle,handle));
      }
      function finish(ok, value) { if(settled)return; settled=true; modal.hidden=true; confirmButton.onclick=null;cancelButton.onclick=null;canvas.onpointerdown=null;canvas.onpointermove=null;canvas.onpointerup=null;ratioSelect.onchange=null;zoom.oninput=null;modal.onkeydown=null;ok?resolve(value):reject(value); }
      function pointerPosition(e){const r=canvas.getBoundingClientRect();return{x:(e.clientX-r.left)*W/r.width,y:(e.clientY-r.top)*H/r.height};}
      canvas.onpointerdown=e=>{const p=pointerPosition(e);if(p.x>=frame.x&&p.x<=frame.x+frame.w&&p.y>=frame.y&&p.y<=frame.y+frame.h){dragging=true;dragOffset={x:p.x-frame.x,y:p.y-frame.y};canvas.setPointerCapture(e.pointerId);}};
      canvas.onpointermove=e=>{if(!dragging)return;const p=pointerPosition(e);frame.x=Math.max(imageRect.x,Math.min(imageRect.x+imageRect.w-frame.w,p.x-dragOffset.x));frame.y=Math.max(imageRect.y,Math.min(imageRect.y+imageRect.h-frame.h,p.y-dragOffset.y));draw();};
      canvas.onpointerup=()=>{dragging=false;};
      ratioSelect.value='original';zoom.value='100';
      ratioSelect.onchange=()=>{const value=ratioSelect.value;const ratio=value==='original'?image.naturalWidth/image.naturalHeight:Number(value);setFrame(ratio,Number(zoom.value));};
      zoom.oninput=()=>{const value=ratioSelect.value;const ratio=value==='original'?image.naturalWidth/image.naturalHeight:Number(value);setFrame(ratio,Number(zoom.value));};
      confirmButton.onclick=()=>{
        try {
          const sx=Math.max(0,(frame.x-imageRect.x)/scale),sy=Math.max(0,(frame.y-imageRect.y)/scale);
          const sw=Math.min(image.naturalWidth-sx,frame.w/scale),sh=Math.min(image.naturalHeight-sy,frame.h/scale);
          const out=document.createElement('canvas');out.width=Math.max(1,Math.round(sw));out.height=Math.max(1,Math.round(sh));
          out.getContext('2d').drawImage(image,sx,sy,sw,sh,0,0,out.width,out.height);
          const type=MIME_EXT[file.type]?file.type:'image/jpeg';
          out.toBlob(blob=>{if(!blob){error.textContent='تعذر تجهيز الصورة. جرّب صورة أخرى.';error.hidden=false;return;}finish(true,new File([blob],file.name.replace(/\.[^.]+$/,'')+`-cropped.${MIME_EXT[type]}`,{type,lastModified:Date.now()}));},type,type==='image/png'?undefined:.92);
        } catch(e){error.textContent=e.message||'تعذر تجهيز الصورة.';error.hidden=false;}
      };
      cancelButton.onclick=()=>finish(false,new Error('تم إلغاء اختيار الصورة.'));
      modal.onkeydown=e=>{if(e.key==='Escape'){e.preventDefault();finish(false,new Error('تم إلغاء اختيار الصورة.'));}if(e.key==='Enter'&&e.target===modal.querySelector('.image-cropper-confirm'))confirmButton.click();};
      setFrame(image.naturalWidth/image.naturalHeight,100);
      confirmButton.focus();
    });
  }

  async function processInput(input, files) {
    if(active)return;
    active=true;input.disabled=true;
    try {
      const output=[];
      for(let i=0;i<files.length;i++) output.push(await cropOne(files[i],i,files.length));
      const transfer=new DataTransfer();output.forEach(file=>transfer.items.add(file));input.files=transfer.files;
      input.disabled=false;
      const event=new Event('change',{bubbles:true});event.croppedReady=true;input.dispatchEvent(event);
    } catch(e) {
      if(e.message!=='تم إلغاء اختيار الصورة.') window.alert(e.message||'تعذر قص الصورة.');
      input.value='';
    } finally {input.disabled=false;active=false;}
  }

  document.addEventListener('change', event => {
    const input=event.target;
    if(event.croppedReady||!(input instanceof HTMLInputElement)||input.type!=='file')return;
    const files=Array.from(input.files||[]);
    if(!files.length||!files.some(file=>file.type.startsWith('image/')))return;
    if(files.some(file=>!MIME_EXT[file.type])){event.stopImmediatePropagation();event.preventDefault();input.value='';window.alert('صيغة الصورة غير مدعومة. استخدم JPG أو PNG أو WEBP.');return;}
    event.stopImmediatePropagation();event.preventDefault();
    processInput(input,files);
  },true);
})();
