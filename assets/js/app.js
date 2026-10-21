document.addEventListener('DOMContentLoaded',()=>{
 const shell=document.getElementById('appShell'); const menu=document.getElementById('menuToggle');
 if(menu&&shell) menu.addEventListener('click',()=>shell.classList.toggle('sidebar-open'));
 document.addEventListener('click',e=>{if(shell?.classList.contains('sidebar-open')&&!e.target.closest('#sidebar')&&!e.target.closest('#menuToggle'))shell.classList.remove('sidebar-open');});
 document.querySelectorAll('[data-dismiss-alert]').forEach(b=>b.addEventListener('click',()=>b.closest('.alert')?.remove()));
 document.querySelectorAll('[data-toggle-password]').forEach(b=>b.addEventListener('click',()=>{const input=document.querySelector(b.dataset.togglePassword);if(!input)return;const show=input.type==='password';input.type=show?'text':'password';b.textContent=show?'Sembunyikan':'Lihat';}));
 document.querySelectorAll('[data-confirm]').forEach(el=>el.addEventListener('click',e=>{if(!window.confirm(el.dataset.confirm||'Lanjutkan operasi ini?'))e.preventDefault()}));
 document.querySelectorAll('[data-auto-submit]').forEach(el=>el.addEventListener('change',()=>el.form?.requestSubmit()));
 // Copy buttons (including the Midtrans QR image URL for the Sandbox simulator).
 // Use Clipboard API when available, then fall back to execCommand for older/local browsers.
 const copyToClipboard=async value=>{
  if(navigator.clipboard && window.isSecureContext){
   await navigator.clipboard.writeText(value);
   return;
  }
  const field=document.createElement('textarea');
  field.value=value;
  field.setAttribute('readonly','');
  field.setAttribute('aria-hidden','true');
  field.style.position='fixed';
  field.style.left='-9999px';
  field.style.top='0';
  field.style.opacity='0';
  document.body.appendChild(field);
  field.focus();
  field.select();
  field.setSelectionRange(0,field.value.length);
  let copied=false;
  try{copied=document.execCommand('copy')}finally{field.remove()}
  if(!copied)throw new Error('Browser menolak operasi salin.');
 };
 document.querySelectorAll('[data-copy]').forEach(button=>{
  button.addEventListener('click',async()=>{
   const value=button.getAttribute('data-copy')||'';
   if(!value){window.alert('URL QR tidak tersedia. Buat atau muat ulang QRIS terlebih dahulu.');return;}
   const originalText=button.dataset.copyLabel||button.textContent.trim()||'Salin URL QR';
   let feedback=button.parentElement?.querySelector('[data-copy-feedback]');
   if(!feedback){
    feedback=document.createElement('span');
    feedback.dataset.copyFeedback='1';
    feedback.setAttribute('role','status');
    feedback.setAttribute('aria-live','polite');
    feedback.className='muted';
    feedback.style.display='block';
    feedback.style.marginTop='6px';
    button.insertAdjacentElement('afterend',feedback);
   }
   button.disabled=true;
   try{
    await copyToClipboard(value);
    button.textContent='URL QR tersalin ✓';
    feedback.textContent='URL QR berhasil disalin. Tempelkan pada kolom QR Code Image Url di simulator Midtrans Sandbox.';
    feedback.style.color='var(--success, #14805e)';
   }catch(error){
    // A user-visible manual fallback ensures the URL can still be copied if clipboard access is blocked.
    window.prompt('Clipboard tidak tersedia. Salin URL QR di bawah ini secara manual (Ctrl+C):',value);
    button.textContent=originalText;
    feedback.textContent='Jika URL tidak tersalin otomatis, salin URL dari kotak dialog yang muncul.';
    feedback.style.color='var(--danger, #c24155)';
   }finally{
    button.disabled=false;
    window.setTimeout(()=>{if(button.isConnected)button.textContent=originalText;},2500);
   }
  });
 });
 const themeButton=document.getElementById('themeToggle');
 const applyTheme=theme=>{document.documentElement.dataset.theme=theme;try{localStorage.setItem('parkirstrd-theme',theme)}catch(e){};const icon=document.getElementById('themeIcon');if(icon)icon.textContent=theme==='dark'?'☀':'☾';if(themeButton)themeButton.setAttribute('aria-label',theme==='dark'?'Aktifkan mode terang':'Aktifkan mode gelap');};
 let theme='light';try{theme=localStorage.getItem('parkirstrd-theme')||'light'}catch(e){};applyTheme(theme);
 if(themeButton)themeButton.addEventListener('click',()=>applyTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
 if(window.JsBarcode){document.querySelectorAll('svg[data-barcode]').forEach(svg=>{try{JsBarcode(svg,svg.dataset.barcode,{format:'CODE128',displayValue:true,font:'DM Sans',fontSize:11,height:48,margin:6,lineColor:'#211638',background:'#ffffff'});}catch(e){svg.outerHTML='<span class="muted">Barcode tidak dapat dibuat</span>'}})}
 const parkingViewport=document.getElementById('parkingMapViewport');
 const parkingSpots=Array.from(document.querySelectorAll('.parking-spot[data-spot][data-status]'));
 if(parkingSpots.length){
  const freeCount=parkingSpots.filter(spot=>spot.dataset.status==='available').length;
  const occupiedCount=parkingSpots.length-freeCount;
  const updateText=(id,value)=>{const el=document.getElementById(id);if(el)el.textContent=String(value)};
  updateText('parkingFreeCount',freeCount);
  updateText('parkingLegendFree',freeCount);
  updateText('parkingLegendOccupied',occupiedCount);
  const selectedTitle=document.getElementById('selectedSpotTitle');
  const selectedDescription=document.getElementById('selectedSpotDescription');
  const selectedBadge=document.getElementById('selectedSpotBadge');
  const selectedMark=document.getElementById('selectedSpotMark');
  const detailPanel=document.getElementById('parkingSpotDetails');
  const selectSpot=spot=>{
   const occupied=spot.dataset.status==='occupied';
   parkingSpots.forEach(item=>{
    const active=item===spot;
    item.classList.toggle('is-selected',active);
    item.setAttribute('aria-pressed',active?'true':'false');
   });
   if(selectedTitle)selectedTitle.textContent=`${spot.dataset.spot} · Slot ${occupied?'terisi':'kosong'}`;
   if(selectedDescription)selectedDescription.textContent=occupied?'Slot ini ditampilkan terisi pada ilustrasi parkir.':'Slot ini ditampilkan kosong pada ilustrasi; pemilihan tidak membuat reservasi.';
   if(selectedBadge){selectedBadge.textContent=occupied?'TERISI':'KOSONG';selectedBadge.classList.toggle('is-occupied',occupied);selectedBadge.classList.toggle('is-available',!occupied);}
   if(selectedMark){selectedMark.classList.toggle('is-occupied',occupied);selectedMark.classList.toggle('is-available',!occupied);}
   if(detailPanel)detailPanel.dataset.status=occupied?'occupied':'available';
  };
  parkingSpots.forEach(spot=>spot.addEventListener('click',()=>selectSpot(spot)));
  selectSpot(parkingSpots.find(spot=>spot.dataset.status==='available')||parkingSpots[0]);
  document.querySelectorAll('[data-parking-scroll]').forEach(button=>button.addEventListener('click',()=>{
   if(!parkingViewport)return;
   const direction=Number(button.dataset.parkingScroll)||1;
   parkingViewport.scrollBy({left:direction*Math.max(260,Math.round(parkingViewport.clientWidth*.7)),behavior:'smooth'});
  }));
 }
 document.querySelectorAll('[data-chart]').forEach(canvas=>{if(typeof Chart==='undefined')return;let cfg;try{cfg=JSON.parse(canvas.dataset.chart)}catch(e){return}const dark=document.documentElement.dataset.theme==='dark';new Chart(canvas,{type:cfg.type||'bar',data:cfg.data,options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:cfg.legend??false,position:'bottom',labels:{boxWidth:8,usePointStyle:true,font:{size:10},color:dark?'#cfc6e8':'#77839a'}}},scales:cfg.type==='doughnut'?{}:{x:{grid:{display:false},ticks:{color:dark?'#b5a8d2':'#8a96a9',font:{size:10}}},y:{beginAtZero:true,grid:{color:dark?'#3b304e':'#eef1f6'},ticks:{color:dark?'#b5a8d2':'#8a96a9',font:{size:10}}}},...cfg.options}})});
});
