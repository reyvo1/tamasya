(function(){
  'use strict';
  const VERSION='V137-i18nloopfix1';
  const STORAGE_KEY='tamasya.public.locale.v137';
  const SOURCE_LOCALE='id';
  const ENABLED=['id','en'];
  const D={
    'Tentang':'About','Kamar':'Rooms','Fasilitas':'Facilities','Menginap dengan tenang':'A Peaceful Stay','Pilihan unggulan':'Featured Choices','Promo':'Promotions','Galeri':'Gallery','Kontak':'Contact','Reservasi':'Reservation','Tentang kami':'About Us',
    'Pilihan kamar':'Room Choices','Ruang nyaman untuk setiap perjalanan':'Comfortable rooms for every journey','Pilih tipe kamar yang sesuai dengan kebutuhan menginap Anda.':'Choose the room type that best fits your stay.',
    'Detail kecil yang membuat istirahat lebih nyaman':'Thoughtful details for a more comfortable stay','Promo aktif':'Active Promotions','Penawaran yang membuat perjalanan lebih ringan':'Offers that make your trip easier',
    'Lihat suasana sebelum Anda tiba':'See the atmosphere before you arrive','Tanya jawab':'Questions & Answers','Informasi sebelum menginap':'Information before your stay','Permintaan reservasi':'Reservation Request',
    'Kirim rencana menginap Anda':'Send Your Stay Plan','Permintaan ini belum menjadi booking final. Resepsionis akan memverifikasi ketersediaan, harga, dan detail tamu.':'This request is not yet a final booking. Reception will verify availability, price, and guest details.',
    'Butuh bantuan langsung?':'Need Direct Assistance?','Istirahat nyaman, pelayanan hangat.':'Comfortable rest, warm hospitality.','Hotel nyaman untuk perjalanan keluarga, bisnis, dan singgah.':'A comfortable hotel for family trips, business travel, and stopovers.',
    'Hotel yang nyaman untuk perjalanan keluarga, bisnis, dan singgah.':'A comfortable hotel for family trips, business travel, and stopovers.','Selamat datang':'Welcome','Pesan Sekarang':'Book Now','Lihat Kamar':'View Rooms',
    'Waktu check-in mengikuti konfirmasi hotel.':'Check-in time follows hotel confirmation.','Hubungi hotel untuk kebijakan perubahan dan pembatalan.':'Contact the hotel for change and cancellation policies.',
    'Resepsionis 24 jam':'24-Hour Reception','Area parkir':'Parking Area','Layanan kamar':'Room Service','Bagaimana cara reservasi?':'How do I make a reservation?','Kirim permintaan melalui formulir. Resepsionis akan menghubungi Anda untuk verifikasi.':'Send a request through the form. Reception will contact you for verification.',
    'Website publik terhubung aman ke sistem TAMASYA.':'The public website is securely connected to TAMASYA.','Halo. Saya dapat membantu informasi kamar, tarif publik, fasilitas, kebijakan menginap, short time, dan proses reservasi.':'Hello. I can help with room information, public rates, facilities, stay policies, short stays, and the reservation process.',
    'Berapa tarif kamar?':'What are the room rates?','Apa saja fasilitas hotel?':'What facilities are available?','Apakah tersedia short time?':'Is short stay available?','Percakapan baru dimulai. Silakan tulis pertanyaan Anda.':'A new conversation has started. Please type your question.',
    'Layanan bantuan belum dapat menjawab.':'The help service could not answer.','Silakan gunakan Form Reservasi atau hubungi hotel.':'Please use the Reservation Form or contact the hotel.','Respons bantuan terlalu lama. Silakan coba lagi atau hubungi WhatsApp resmi hotel.':'The help response took too long. Please try again or contact the hotel\'s official WhatsApp.',
    'Layanan bantuan sedang tidak tersedia. Silakan coba lagi.':'The help service is currently unavailable. Please try again.','Chat bantuan hotel':'Hotel Help Chat','Chat Bantuan':'Help Chat','Resepsionis sedang menangani':'Reception is assisting',
    'Tutup chat bantuan':'Close Help Chat','Pertanyaan cepat':'Quick Questions','Sedang menyiapkan jawaban…':'Preparing an answer…','Mulai Chat Baru':'Start New Chat','Tulis pertanyaan':'Type a question','Harga kamar, fasilitas, atau reservasi…':'Room rates, facilities, or reservations…','Kirim':'Send',
    'Pesan tersinkron dengan AI, Telegram staf terverifikasi, dan kotak masuk aplikasi. Booking tetap harus diverifikasi resepsionis.':'Messages are synchronized with AI, verified staff Telegram, and the application inbox. Bookings must still be verified by reception.',
    'Buka chat bantuan':'Open Help Chat','Tutup':'Close','Tanggal check-out harus setelah check-in.':'Check-out date must be after check-in date.','Memeriksa kamar kosong dan harga total…':'Checking vacant rooms and total price…',
    'Ketersediaan kamar belum dapat diperiksa.':'Room availability could not be checked.','Ketersediaan dan harga sudah disinkronkan dengan aplikasi.':'Availability and prices are synchronized with the application.','Data ketersediaan kamar belum tersedia.':'Room availability data is not available yet.',
    'Tipe kamar ini tidak tersedia pada tanggal yang dipilih. Silakan pilih tipe lain.':'This room type is not available for the selected dates. Please choose another room type.','Mengirim permintaan…':'Sending request…','Sedang Mengirim…':'Sending…',
    'Respons server reservasi tidak valid. Gunakan tombol yang sama untuk mencoba kembali.':'The reservation server returned an invalid response. Use the same button to try again.','Permintaan gagal dikirim.':'The request could not be sent.','Koneksi belum mengonfirmasi hasil. Tekan kirim kembali; nomor operasi yang sama akan digunakan.':'The connection has not confirmed the result. Send again; the same operation number will be reused.',
    'Tidak dapat terhubung. Silakan hubungi hotel.':'Unable to connect. Please contact the hotel.','Informasi reservasi':'Reservation Information','Permintaan diverifikasi petugas':'Request Verified by Staff','Lanjut reservasi →':'Continue Reservation →','Pencarian kamar kosong':'Vacant Room Search',
    'Check-in':'Check-in','Check-out':'Check-out','Memuat kamar…':'Loading rooms…','Memeriksa ketersediaan':'Checking availability','Kamar nyaman':'Comfortable Room','Tempat tidur nyaman':'Comfortable Bed','Harga dasar':'Base Price','Total per malam · termasuk pajak':'Total per Night · Tax Included','Pilih →':'Select →',
    'Katalog kamar akan tampil setelah data diterbitkan.':'The room catalog will appear after data is published.','Hubungi resepsionis untuk penawaran terbaru.':'Contact reception for the latest offers.','Promo akan segera hadir':'Promotions Coming Soon','Tambahkan foto galeri melalui aplikasi TAMASYA.':'Add gallery photos through the TAMASYA application.',
    'Harga transparan':'Transparent Pricing','Harga yang tampil sudah termasuk PBJT sesuai aturan pajak aktif untuk tanggal check-in.':'Displayed prices already include PBJT according to the active tax rules for the check-in date.','Ketersediaan tersinkron':'Synchronized Availability','Website memeriksa booking reserved/active dan tidak menampilkan nomor kamar kepada publik.':'The website checks reserved/active bookings and does not expose room numbers to the public.',
    'Nama tamu':'Guest Name','Nomor WhatsApp':'WhatsApp Number','Email':'Email','Jumlah tamu':'Number of Guests','Tipe kamar':'Room Type','Pilih tipe kamar':'Select Room Type','Harga dasar per malam':'Base Price per Night','Ketersediaan final tetap dikonfirmasi resepsionis.':'Final availability is still confirmed by reception.','Permintaan tambahan':'Additional Request','Memeriksa Ketersediaan…':'Checking Availability…','Kirim Permintaan':'Send Request',
    'Alamat hotel':'Hotel Address','Alamat hotel dapat diatur melalui aplikasi.':'The hotel address can be configured in the application.','Hubungi WhatsApp':'Contact via WhatsApp','Buka di Google Maps':'Open in Google Maps','Petunjuk Arah':'Directions','Peta lokasi hotel':'Hotel Location Map','Peta interaktif belum disematkan. Gunakan tombol Google Maps untuk melihat lokasi dan rute.':'The interactive map is not embedded yet. Use the Google Maps button to view the location and route.','Isi alamat dan URL embed Google Maps melalui Website Content Manager.':'Enter the address and Google Maps embed URL through the Website Content Manager.','Buka Google Maps →':'Open Google Maps →','Peta berasal dari Google Maps. Ketersediaan rute dan posisi mengikuti layanan Google.':'The map is provided by Google Maps. Route and location availability follow Google services.',
    'Buka menu':'Open Menu','Lokasi strategis':'Strategic Location','Reservasi langsung':'Direct Reservation','WhatsApp hotel':'Hotel WhatsApp','Harga transparan':'Transparent Pricing','Ketersediaan tersinkron':'Synchronized Availability',
    'Pengamanan anti-spam chat publik belum tersedia pada database staging.':'Public chat anti-spam protection is not available on the staging database.','Terima kasih. Silakan gunakan kontak resmi hotel.':'Thank you. Please use the hotel\'s official contact channels.','Payload chat publik mengandung field internal yang dilarang.':'The public chat payload contains a forbidden internal field.','Operation ID chat publik tidak valid.':'The public chat operation ID is invalid.','Pesan harus berisi 2 sampai 800 karakter.':'The message must contain 2 to 800 characters.','Percakapan ini sudah ditutup. Mulai Chat Baru untuk mengirim pertanyaan berikutnya.':'This conversation has been closed. Start a New Chat to send another question.'
  };
  const PATTERNS=[
    [/^Maks\.\s*(\d+)\s*tamu$/i,(_,n)=>`Max. ${n} guests`],
    [/^(\d+)\s*tersedia$/i,(_,n)=>`${n} available`],
    [/^Estimasi total\s+(\d+)\s+malam$/i,(_,n)=>`Estimated total for ${n} nights`],
    [/^(\d+)\s+kamar masih tersedia untuk periode ini\.$/i,(_,n)=>`${n} rooms are still available for this period.`],
    [/^Harga dasar\s+(.+)$/i,(_,x)=>`Base price ${x}`],
    [/^(\d+)\s+malam$/i,(_,n)=>`${n} nights`],
    [/^(\d+)\s+tamu$/i,(_,n)=>`${n} guests`],
    [/^(\d+)\s+kamar tersedia$/i,(_,n)=>`${n} rooms available`],
    [/^\/malam\s*[—-]?\s*/i,()=>'/night — '],
    [/^Berhasil\. Nomor permintaan:\s*(.+)$/i,(_,id)=>`Success. Request number: ${id}`],
    [/^Lokasi\s+(.+)\s+di Google Maps$/i,(_,name)=>`${name} location on Google Maps`]
  ];
  let locale='id';
  let observer=null;
  let applying=false;
  let rafPending=0;
  let pendingRoots=null;
  const textState=new WeakMap();
  const attrState=new WeakMap();
  // AUDIT17 LOOP FIX:
  // 1) All translation WRITES now happen under the `applying` guard so our own
  //    mutations never re-trigger the observer (previously only applyAll was
  //    guarded — the observer callback wrote attributes unguarded, feeding an
  //    infinite observe->walk->write->observe cycle that pinned CPU at 100%).
  // 2) Observer work is coalesced into one requestAnimationFrame pass and
  //    de-duplicated by root element, instead of walking synchronously for
  //    every single mutation record.
  // 3) ensureSwitcher() only touches the DOM when something actually changed.
  const translate=(src,target=locale)=>{
    if(!src||target===SOURCE_LOCALE) return src;
    if(Object.prototype.hasOwnProperty.call(D,src)) return D[src];
    for(const [re,fn] of PATTERNS){re.lastIndex=0;if(re.test(src)){re.lastIndex=0;return src.replace(re,fn);}}
    return src;
  };
  const skip=(el)=>!el||el.nodeType!==1||!!el.closest('script,style,code,pre,textarea,[contenteditable="true"],[data-tamasya-no-i18n],.tamasya-public-language-switcher');
  function textNode(n){
    const p=n.parentElement;if(!p||skip(p))return;
    const raw=String(n.nodeValue||''),m=raw.match(/^(\s*)([\s\S]*?)(\s*)$/);if(!m||!m[2].trim())return;
    let st=textState.get(n);if(!st){st={source:m[2],last:m[2]};textState.set(n,st);}else if(m[2]!==st.last&&m[2]!==st.source){st.source=m[2];}
    const next=locale===SOURCE_LOCALE?st.source:translate(st.source,locale);
    if(m[2]!==next){st.last=next;const wasApplying=applying;applying=true;try{n.nodeValue=m[1]+next+m[3];}finally{applying=wasApplying;}}
    else st.last=next;
  }
  function attr(el,name){
    if(!el.hasAttribute(name))return;const cur=el.getAttribute(name)||'';let a=attrState.get(el);if(!a){a={};attrState.set(el,a);}let st=a[name];if(!st){st={source:cur,last:cur};a[name]=st;}else if(cur!==st.last&&cur!==st.source){st.source=cur;}
    const next=locale===SOURCE_LOCALE?st.source:translate(st.source,locale);
    if(cur!==next){st.last=next;const wasApplying=applying;applying=true;try{el.setAttribute(name,next);}finally{applying=wasApplying;}}
    else st.last=next;
  }
  function walk(root){
    if(!root)return;if(root.nodeType===3){textNode(root);return;}if(root.nodeType!==1||skip(root))return;
    const els=[root,...root.querySelectorAll('*')];for(const el of els){if(skip(el))continue;attr(el,'placeholder');attr(el,'title');attr(el,'aria-label');}
    const w=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);let n;while((n=w.nextNode()))textNode(n);
  }
  function meta(){
    document.documentElement.lang=locale;
    const desc=document.querySelector('meta[name="description"]');if(desc){if(!desc.dataset.idSource)desc.dataset.idSource=desc.content;desc.content=locale==='en'?'Room information and reservation requests for the hotel.':desc.dataset.idSource;}
  }
  function ensureSwitcher(){
    const host=document.querySelector('.nav-actions')||document.querySelector('header.nav');if(!host)return;
    let box=host.querySelector('.tamasya-public-language-switcher');
    if(!box){box=document.createElement('div');box.className='tamasya-public-language-switcher';box.dataset.tamasyaNoI18n='true';box.innerHTML='<span class="tamasya-language-label">Bahasa</span><select aria-label="Pilihan bahasa"><option value="id">ID</option><option value="en">EN</option></select>';const wasApplying=applying;applying=true;try{host.insertBefore(box,host.firstChild);}finally{applying=wasApplying;}box.querySelector('select').addEventListener('change',e=>setLocale(e.target.value));}
    const label=locale==='en'?'Language':'Bahasa';const lblEl=box.querySelector('.tamasya-language-label');
    if(lblEl&&lblEl.textContent!==label)lblEl.textContent=label;
    const s=box.querySelector('select');if(s.value!==locale)s.value=locale;const ariaLabel=locale==='en'?'Language selection':'Pilihan bahasa';if(s.getAttribute('aria-label')!==ariaLabel)s.setAttribute('aria-label',ariaLabel);
  }
  function scheduleFlush(){
    if(rafPending)return;rafPending=requestAnimationFrame(()=>{rafPending=0;const roots=pendingRoots||[];pendingRoots=null;if(!roots.length)return;
      if(applying)return;
      for(const r of roots){walk(r);}
      ensureSwitcher();
    });
  }
  function observe(){
    if(observer)return;observer=new MutationObserver(records=>{
      if(applying)return;
      // Relevance filter: ignore mutations our own translation writes and
      // pure attribute changes we cannot act on; collect unique root elements.
      if(!pendingRoots)pendingRoots=[];
      let relevant=false;
      for(const r of records){
        if(r.type==='characterData'&&r.target.parentElement){pendingRoots.push(r.target.parentElement);relevant=true;}
        else if(r.type==='childList'){
          for(const n of r.addedNodes){
            if(n.nodeType===1||n.nodeType===3){
              // skip nodes injected by this script itself
              if(n.nodeType===1&&n.classList&&n.classList.contains('tamasya-public-language-switcher'))continue;
              pendingRoots.push(n);relevant=true;
            }
          }
        }
      }
      if(relevant)scheduleFlush();
    });
    observer.observe(document.getElementById('root')||document.body,{subtree:true,childList:true,characterData:true});
  }
  function applyAll(){applying=true;try{if(observer)observer.disconnect();meta();walk(document.body);ensureSwitcher();}finally{applying=false;if(observer){observer.observe(document.getElementById('root')||document.body,{subtree:true,childList:true,characterData:true});}}}
  function setLocale(next,opt={}){
    if(!ENABLED.includes(next))next='id';if(next===locale){applyAll();return true;}
    locale=next;if(opt.persist!==false){try{localStorage.setItem(STORAGE_KEY,locale);}catch{}}
    // Chat answers are persisted in the language used when created. Start a clean local chat reference on language change.
    try{localStorage.removeItem('tamasya_public_support_session');sessionStorage.removeItem('tamasya_public_chat_pending_v1');}catch{}
    applyAll();window.dispatchEvent(new CustomEvent('tamasya:public-language-changed',{detail:{locale,version:VERSION}}));return true;
  }
  function preferred(){try{const v=localStorage.getItem(STORAGE_KEY)||'';if(ENABLED.includes(v))return v;}catch{}return'id';}
  locale=preferred();window.TamasyaPublicI18n=Object.freeze({version:VERSION,getLocale:()=>locale,setLocale,translate:(s,l)=>translate(String(s??''),l||locale)});
  function boot(){applyAll();observe();}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
