/* Shared booking management UI. Authentication and API operations are unchanged. */
(function(global){'use strict';



    class EventEmitter{constructor(){this.events={};} on(e,l){(this.events[e]??=[]).push(l);} off(e,l){if(!this.events[e])return; this.events[e]=this.events[e].filter(x=>x!==l);} emit(e,d){(this.events[e]||[]).forEach(l=>l(d));}}

    class GlobalState{
      constructor(){ this.instances=new Map(); this.sharedState={availability:{}, bookings:[], customerBookings:new Map()};}
      registerInstance(id,inst){this.instances.set(id,inst);} unregisterInstance(id){this.instances.delete(id);}
      updateAvailability(boxId,dateString,availability,source='admin'){
        const key=`${boxId}_${dateString}`; this.sharedState.availability[key]=availability;
        if(source==='customer'&&availability.available===0){ this.sharedState.customerBookings.set(key,true); }
        else if(source==='admin'&&availability.available>0){ this.sharedState.customerBookings.delete(key); }
        MMBCore.events.emit('availability:changed',{boxId,dateString,availability,source});
      }
      getAvailability(boxId,dateString){ return this.sharedState.availability[`${boxId}_${dateString}`]; }
      isCustomerBooked(boxId,dateString){ return this.sharedState.customerBookings.has(`${boxId}_${dateString}`); }
    }

    const Utils={
      MONTH_NAMES:['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'],
      DAY_NAMES:['Mo','Di','Mi','Do','Fr','Sa','So'],
      formatDate(d){const y=d.getFullYear(),m=String(d.getMonth()+1).padStart(2,'0'),da=String(d.getDate()).padStart(2,'0'); return `${y}-${m}-${da}`;},
      formatMonthYear(d){return `${this.MONTH_NAMES[d.getMonth()]} ${d.getFullYear()}`;},
      formatDateRange(s,e){return `${s.toLocaleDateString('de-DE')} - ${e.toLocaleDateString('de-DE')}`;},
      generateCalendarDays(cur){const y=cur.getFullYear(),m=cur.getMonth(),first=new Date(y,m,1),last=new Date(y,m+1,0); const start=(first.getDay()===0?6:first.getDay()-1); const days=[]; const prevLast=new Date(y,m,0);
        for(let i=start-1;i>=0;i--){days.push({date:new Date(y,m-1,prevLast.getDate()-i),otherMonth:true});}
        for(let d=1; d<=last.getDate(); d++){days.push({date:new Date(y,m,d),otherMonth:false});}
        const total=Math.ceil(days.length/7)*7, trailing=total-days.length; for(let d=1; d<=trailing; d++){days.push({date:new Date(y,m+1,d),otherMonth:true});}
        return days;},
      showNotification(msg,type='info'){const ex=document.querySelector(`.mmb-${type}-notification`); if(ex) ex.remove(); const el=document.createElement('div'); el.className=`mmb-${type}-notification`; const bg=type==='error'?'var(--mmb-busy)':'var(--mmb-ok)';
        el.style.cssText=`position:fixed;top:20px;right:20px;background:${bg};color:#fff;padding:1rem;border-radius:4px;box-shadow:0 4px 6px rgba(0,0,0,.1);z-index:1000;max-width:300px;`; el.textContent=msg; document.body.appendChild(el); setTimeout(()=>el.remove(),5000);}
    };

     const events=new EventEmitter(), globalState=new GlobalState();
    global.MMBCore={events,globalState,Utils,version:'1.0.0'};
  })(window);
  

    const MMB_API_BASE = MMBAdminAuth.getApiBase();

    async function mmbApiGet(path){
      const res=await MMBAdminAuth.fetch(MMB_API_BASE+path,{credentials:'include'});
      const text=await res.text(); let data;
      try{ data=JSON.parse(text);}catch{ data={ok:false,error:'Keine JSON-Antwort',raw:text};}
      if(!res.ok||!data.ok) throw new Error(data.error||('HTTP '+res.status+'\n'+(data.raw||'')));
      return data;
    }
    async function mmbApiPost(path,body){
      const res=await MMBAdminAuth.fetch(MMB_API_BASE+path,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify(body||{})});
      const text=await res.text(); let data;
      try{ data=JSON.parse(text);}catch{ data={ok:false,error:'Keine JSON-Antwort',raw:text};}
      if(!res.ok||!data.ok) throw new Error(data.error||('HTTP '+res.status+'\n'+(data.raw||'')));
      return data;
    }

    const MMBApi={
      async listBookings(params = {}) {
        const q=new URLSearchParams();
        if(params.box_id) q.set('box_id', params.box_id);
        if(params.status) q.set('status', params.status);
        if(params.from) q.set('from', params.from);
        if(params.to) q.set('to', params.to);
        return mmbApiGet('list_bookings.php'+(q.toString()?`?${q}`:''));
      },
      async updateBooking(payload){
        return mmbApiPost('update_booking.php', payload);
      },
      // FIX: richtiger Endpoint + richtige Keys
      async updateBookingStatus(payload){
        return mmbApiPost('update_booking_status.php', payload);
      },
      async deleteBooking(payload){
        return mmbApiPost('delete_booking.php', payload);
      },
    };
  

  (function(global){'use strict';

    const BOX_MAP = { '1':'Marshall Bromley 750', '2':'Teufel Rockster Neo', '3':'Soundboks 4' };
    const EURO_FORMATTER = new Intl.NumberFormat('de-DE', {minimumFractionDigits:2,maximumFractionDigits:2});
    const FULFILLMENT_CHOICES = [
      { id:'pickup',   label:'Abholung', full:'Abholung in Wesel', note:'', priceDelta:0, addsToTotal:true },
      { id:'shipping', label:'Versand',  full:'Versand (deutschlandweit)', note:'inkl. Express-Hin- & Rückversand sowie Vorbereitungspauschale', priceDelta:80, addsToTotal:true },
      { id:'delivery_setup', label:'Lieferung und Montage', full:'Lieferung und Montage', note:'Auf- und Abbau 160 € enthalten; Lieferfahrt zusätzlich auf Angebot', priceDelta:0, addsToTotal:false },
      { id:'delivery', label:'Lieferung', full:'Lieferung (NRW-weit)', note:'zzgl. individueller Lieferpauschale – wir melden uns mit einem Angebot', priceDelta:0, addsToTotal:false },
      { id:'unknown', label:'Nicht erfasst', full:'Nicht erfasst', note:'Bei älteren Anfragen wurde die Abwicklung nicht gespeichert. Bitte mit der Kundschaft klären.', priceDelta:0, addsToTotal:false }
    ];
    const FULFILLMENT_LOOKUP = Object.fromEntries(FULFILLMENT_CHOICES.map(opt=>[opt.id,opt]));

    // Anzeige-ID (nur Ziffern), Prefix 00 + laufend ab 100
    const ID_CATEGORY = '00';
    function displayId(rawId){ const num = 100 + (Number(rawId)||0); return ID_CATEGORY + String(num).padStart(3,'0'); }



    class AdminList{
      constructor(options={}){
        this.container = typeof options.container==='string'?document.querySelector(options.container):options.container;
        if(!this.container) throw new Error('Container not found: '+options.container);
        this.options={ locale:'de-DE', timezone:'Europe/Berlin', ...options };
        this.state={
          bookings:[], originalBookings:[], boxes:[],
          loading:false,
          filters:{ status:'', box:'', id:'', name:'' }
        };
        this.activeDetailOverlayClose = null;
        this.activeDetailOverlayBookingId = null;
        this.instanceId=Math.random().toString(36).substr(2,9); MMBCore.globalState.registerInstance(this.instanceId,this);
        this.init();
      }
      destroy(){ MMBCore.globalState.unregisterInstance(this.instanceId); }

      async init(){ try{ this.render(); await this.loadInitialData(); this.bindUI(); this.applyFilters(); }catch(e){ console.error('AdminList init error:',e); MMBCore.Utils.showNotification('Fehler beim Initialisieren der Admin-Liste','error'); } }

      async loadInitialData(){
        this.setState({loading:true});
        await Promise.all([this.loadBookings(), this.loadBoxes()]);
        this.setState({loading:false});
      }

      async loadBookings(){ try{
        const res=await MMBApi.listBookings();
        const items=(res.items||[]).map(r=>{
          const boxId = r.box_id==null?'':String(r.box_id);
          const order=this.safeParseJson(r.order_json);
          const visualStatus=r.alternative_proposed_at&&r.status!=='confirmed'&&r.status!=='cancelled'?'alternative':r.status;
          const totalAmount = Number(r.total_amount ?? 0);
          const deposit = Number(r.deposit_eur ?? 0);
          const pricingRaw = r.pricing_details_json ?? r.pricing_details ?? null;
          const parsedPricing = this.safeParseJson(pricingRaw);
          const normalizedPricing = this.normalizePricingDetails(parsedPricing);
          const pricingCanonical = this.stableStringify(normalizedPricing || {});
          const fulfillmentDetailsRaw = r.fulfillment_details_json ?? r.fulfillment_details ?? null;
          const parsedFulfillmentDetails = this.safeParseJson(fulfillmentDetailsRaw);
          const normalizedFulfillmentDetails = this.normalizeFulfillmentDetails(parsedFulfillmentDetails, (r.fulfillment_method || '').toString());
          const fulfillmentMeta = this.computeFulfillmentMeta(r, normalizedFulfillmentDetails);
          const fulfillmentCanonical = this.stableStringify(normalizedFulfillmentDetails || {});
          return {
            id:String(r.id),
            order,
            visual_status:visualStatus,
            box_id:boxId,
            box_name: order?order.package_name+' · '+order.summary:(BOX_MAP[boxId] || r.box_name || ('Box #'+boxId)),
            start_date:r.start_date,
            end_date:r.end_date,
            status:(r.status||'').toString(),
            customer_name:r.customer_name||'',
            customer_email:r.customer_email||'',
            customer_phone:r.customer_phone||'',
            billing_address_line1:r.billing_address_line1||'',
            billing_postal_code:r.billing_postal_code||'',
            billing_city:r.billing_city||'',
            customer_birthdate:r.customer_birthdate||'',
            customer_type:r.customer_type||'private',company_name:r.company_name||'',
            email_verified_at:r.email_verified_at||'',
            customer_mail_sent_at:r.customer_mail_sent_at||'',
            internal_mail_sent_at:r.internal_mail_sent_at||'',
            note:r.note ?? '',
            price_total:Number.isFinite(totalAmount)?totalAmount:0,
            deposit_eur:Number.isFinite(deposit)?deposit:0,
            fulfillment_method:fulfillmentMeta.method,
            fulfillment_label:fulfillmentMeta.label,
            fulfillment_note:fulfillmentMeta.note,
            fulfillment_price_delta:fulfillmentMeta.priceDelta,
            fulfillment_adds_to_total:fulfillmentMeta.addsToTotal,
            created_at:r.created_at,
            pricing_details: normalizedPricing,
            pricing_details_raw: pricingRaw,
            pricing_details_canonical: pricingCanonical,
            fulfillment_details: normalizedFulfillmentDetails,
            fulfillment_details_raw: fulfillmentDetailsRaw,
            fulfillment_details_canonical: fulfillmentCanonical
          };
        });
        this.state.originalBookings = items;
        this.applyFilters(); // hält Filter aktiv nach jedem Reload/Statuswechsel
      }catch(err){ console.error('loadBookings error:',err); MMBCore.Utils.showNotification('Buchungen konnten nicht geladen werden','error'); } }

      async loadBoxes(){
        this.state.boxes = [
          {id:'1',name:BOX_MAP['1']},
          {id:'2',name:BOX_MAP['2']},
          {id:'3',name:BOX_MAP['3']}
        ];
      }

      setState(ns){ this.state={...this.state,...ns}; this.render(); }

      // ---------- Focus Snapshot ----------
      snapshotFocus(){
        const ae = document.activeElement;
        if(!ae || !this.container.contains(ae)) return null;
        const id = ae.id || '';
        let start=null,end=null;
        try{ start=ae.selectionStart; end=ae.selectionEnd; }catch(e){}
        return {id,start,end};
      }
      restoreFocus(snap){
        if(!snap||!snap.id) return;
        const el = document.getElementById(snap.id);
        if(!el) return;
        el.focus({preventScroll:true});
        try{
          const pos = (typeof snap.end==='number') ? snap.end : (el.value?el.value.length:0);
          el.setSelectionRange(pos,pos);
        }catch(e){}
      }

      // ---------- Rendering ----------
      render(){
        if(this.state.loading){
          this.container.innerHTML=`<div class="mmb-widget"><div class="mmb-loading">Lade Buchungen...</div></div>`;
          return;
        }
        const f = this.state.filters;
        this.container.innerHTML=`
          <div class="mmb-widget">
            <div class="mmb-admin-heading"><h1>Buchungen</h1><p>In der Liste wischen, für alle Angaben und Aktionen die Details öffnen.</p></div>
            ${this.renderFilters(f)}
            <div class="mmb-admin-list-wrap" role="region" aria-label="Buchungen, scrollbar" tabindex="0">${this.renderTable()}</div>
            ${this.renderFooter()}
          </div>`;
        this.bindTableActions();
        this.container.querySelector('[data-jump-calendar]')?.addEventListener('click',()=>MMBAdminAuth.focusSection('mmb-admin-cal'));
        const btn = document.getElementById(`btn-reset-${this.instanceId}`);
        if(btn && !btn._bound){ btn.addEventListener('click', ()=> this.resetFilters()); btn._bound=true; }
      }

      renderFilters(f){
        return `
        <div class="mmb-admin-filters">
          <label class="mmb-filter-field"><span>Status</span><select aria-label="Buchungsstatus filtern" id="filter-status-${this.instanceId}">
            <option value="">Alle</option>
            ${[['alternative','Alternative vorgeschlagen'],['pending','Ausstehend'],['confirmed','Bestätigt'],['rejected','Abgelehnt'],['cancelled','Storniert']].map(([id,label])=>`<option value="${id}" ${f.status===id?'selected':''}>${label}</option>`).join('')}
          </select></label>
          <label class="mmb-filter-field"><span>Ausstattung</span><select aria-label="Produkt oder Paket filtern" id="filter-box-${this.instanceId}">
            <option value="">Alle</option>
            ${[['photo','Fotobox'],['tent','Eventzelt'],['controller','DJ-Mischpult'],['dj','DJ-Set'],['party','Party-Set'],['event','Event-Set']].map(([id,name])=>`<option value="${id}" ${f.box===id?'selected':''}>${name}</option>`).join('')}
            ${this.state.boxes.map(b=>`<option value="${b.id}" ${f.box===b.id?'selected':''}>${this.escape(b.name)}</option>`).join('')}
          </select></label>
          <label class="mmb-filter-field"><span>Buchungsnummer</span><input id="filter-id-${this.instanceId}" aria-label="Buchungs-ID suchen" inputmode="numeric" pattern="[0-9]*" placeholder="z. B. 00123" value="${this.escape(f.id)}"/></label>
          <label class="mmb-filter-field mmb-filter-customer"><span>Kunde suchen</span><input id="filter-name-${this.instanceId}" aria-label="Kunde suchen" placeholder="Name oder E-Mail" value="${this.escape(f.name)}"/></label>
          <button class="mmb-btn mmb-reset" id="btn-reset-${this.instanceId}"><span>Zurücksetzen</span></button>
        </div>`;
      }

      renderTable(){
        const rows = (this.state.bookings||[]);
        if(!rows.length){
          return `
            <table class="mmb-bookings-table">
              <thead>
                <tr><th class="cell-head-id">ID</th><th class="cell-head-customer">Kunde</th><th class="cell-head-box">Ausstattung</th><th class="cell-head-date">Zeitraum</th><th class="cell-head-fulfillment">Abwicklung</th><th class="cell-head-price">Preis</th><th class="cell-head-status">Status</th><th class="cell-head-actions">Aktionen</th></tr>
              </thead>
              <tbody><tr class="mmb-empty"><td colspan="8" style="padding:1rem;color:var(--mmb-text-light);">Keine Buchungen vorhanden</td></tr></tbody>
            </table>`;
        }
        return `
        <table class="mmb-bookings-table">
          <thead>
            <tr><th class="cell-head-id">ID</th><th class="cell-head-customer">Kunde</th><th class="cell-head-box">Ausstattung</th><th class="cell-head-date">Zeitraum</th><th class="cell-head-fulfillment">Abwicklung</th><th class="cell-head-price">Preis</th><th class="cell-head-status">Status</th><th class="cell-head-actions">Aktionen</th></tr>
          </thead>
          <tbody>
            ${rows.map(b=>this.renderRow(b)).join('')}
          </tbody>
        </table>`;
      }

      renderRow(b){
        const isConfirmed = b.status==='confirmed';
        const isRejected  = b.status==='rejected';
        const isCancelled = b.status==='cancelled';
        const fulfillmentInfo = this.getFulfillmentInfo(b);
        const fulfillmentNoteAttr = fulfillmentInfo.note ? ` title="${this.escape(fulfillmentInfo.note)}"` : '';

        const emailLine = b.customer_email ? `<span class="cell--email">${this.escape(b.customer_email)}</span>` : '';
        return `
        <tr>
          <td class="cell--id"><span class="badge-id">${displayId(b.id)}</span></td>
          <td class="cell--customer"><span class="cell--name">${this.escape(b.customer_name)}</span>${emailLine}</td>
          <td class="cell--box">${this.renderEquipment(b)}</td>
          <td class="cell--date">${this.renderDateCell(b)}</td>
          <td class="cell--fulfillment"${fulfillmentNoteAttr}>${this.escape(this.formatFulfillmentCell(b))}</td>
          <td class="cell--total">${this.formatEuro(b.price_total)}</td>
          <td class="cell--status"><span class="mmb-status-badge mmb-status-${b.visual_status||b.status}">${this.translateStatus(b.visual_status||b.status)}</span></td>
          <td class="cell--actions">
            <div class="cell--actions-row">
              <button class="mmb-btn mmb-btn-small mmb-btn-secondary"
                      data-action="viewDetails" data-id="${b.id}"
                      title="Details anzeigen" aria-label="Buchung ${displayId(b.id)} – Details und Aktionen">${MMBIcons.icon('info')}<span class="mmb-action-desktop">Details</span><span class="mmb-action-label">Details &amp; Aktionen</span></button>
              <button class="mmb-btn mmb-btn-small mmb-btn-success"
                      data-action="confirmBooking" data-id="${b.id}"
                      title="${isConfirmed?'Bereits bestätigt':'Buchung bestätigen'}"
                      ${isConfirmed ? 'disabled aria-disabled="true"' : ''}>✓</button>

              <button class="mmb-btn mmb-btn-small mmb-btn-danger"
                      data-action="declineBooking" data-id="${b.id}"
                      title="${isRejected?'Bereits abgelehnt':'Buchung ablehnen'}"
                      ${isRejected ? 'disabled aria-disabled="true"' : ''}>×</button>

              <button class="mmb-btn mmb-btn-small mmb-btn-warning"
                      data-action="offerAlternative" data-id="${b.id}"
                      title="Alternative Box vorschlagen">Alt</button>
            </div>
          </td>
        </tr>`;
      }

      renderEquipment(b){
        if(!b.order)return `<span class="mmb-legacy-product">${MMBIcons.icon('speaker')}${this.escape(b.box_name)}</span>`;
        const lines=b.order.items.map(x=>`<span>${MMBIcons.icon({music:'speaker',photo:'camera',tent:'tent',accessory:'package',service:'truck'}[x.category]||'package')}<span>${x.quantity}× ${this.escape(x.label)}</span></span>`);
        const rest=lines.length>2?`<details class="mmb-equipment-more"><summary>${lines.length-2} weitere ${lines.length===3?'Position':'Positionen'}</summary><div class="mmb-order-mini">${lines.slice(2).join('')}</div></details>`:'';
        return `<strong class="mmb-equipment-name">${this.escape(b.order.package_name)}</strong><div class="mmb-order-mini">${lines.slice(0,2).join('')}</div>${rest}`;
      }

      renderDateCell(b){
        const parse = (value)=>{
          if(!value) return null;
          const d=new Date(value);
          return Number.isNaN(d.getTime())?null:d;
        };
        const start=parse(b.start_date);
        const end=parse(b.end_date);
        const fmt=(d)=>d?d.toLocaleDateString('de-DE'):'–';
        const wrap=(content)=>`<span class="date-range">${content}</span>`;
        if(start && end){
          const same=start.getFullYear()===end.getFullYear() && start.getMonth()===end.getMonth() && start.getDate()===end.getDate();
          if(same){
            return `<div class="date-range date-range--single"><span>${fmt(start)}</span></div>`;
          }
          return `<div class="date-range"><span>${fmt(start)}</span><span>${fmt(end)}</span></div>`;
        }
        if(start){ return `<div class="date-range date-range--single"><span>${fmt(start)}</span></div>`; }
        if(end){ return `<div class="date-range date-range--single"><span>${fmt(end)}</span></div>`; }
        return `<div class="date-range date-range--single"><span>–</span></div>`;
      }

      renderFooter(){
        const shown = (this.state.bookings||[]).filter(r=>r && r.id).length;
        const total = (this.state.originalBookings||[]).length;
        return `<div class="mmb-footer"><button type="button" class="mmb-jump" data-jump-calendar>Zum Kalender ↓</button><span>${shown} von ${total} Buchungen</span></div>`;
      }

      // ---------- Events ----------
      bindUI(){
        // Live-Filtern + Fokus halten
        this.container.addEventListener('input', (e)=>{
          const t = e.target;
          if(!t.id) return;
          const snap = this.snapshotFocus();
          if(t.id===`filter-id-${this.instanceId}`){
            this.state.filters.id = (t.value||'').replace(/[^\d]/g,''); // nur Ziffern
            this.applyFilters(snap);
          }
          if(t.id===`filter-name-${this.instanceId}`){
            this.state.filters.name = (t.value||'');
            this.applyFilters(snap);
          }
        });
        this.container.addEventListener('change', (e)=>{
          const t = e.target;
          if(!t.id) return;
          const snap = this.snapshotFocus();
          if(t.id===`filter-status-${this.instanceId}`){
            this.state.filters.status = t.value||'';
            this.applyFilters(snap);
          }
          if(t.id===`filter-box-${this.instanceId}`){
            this.state.filters.box = t.value||'';
            this.applyFilters(snap);
          }
          if(t.id===`btn-reset-${this.instanceId}`){
            this.resetFilters();
          }
        });

        const btn = document.getElementById(`btn-reset-${this.instanceId}`);
        if(btn){ btn.addEventListener('click', ()=> this.resetFilters()); }
      }

      bindTableActions(){
        this.container.querySelector('.mmb-widget')?.addEventListener('click', async (e)=>{
          const btn = e.target.closest('button[data-action]');
          if(!btn) return;
          if (btn.disabled || btn.getAttribute('aria-disabled') === 'true') return;
          e.preventDefault();
          const id = btn.dataset.id;
          if(btn.dataset.action==='viewDetails') { await this.viewDetails(id); return; }
          if(btn.dataset.action==='confirmBooking') await this.confirmBooking(id);
          if(btn.dataset.action==='declineBooking') await this.declineBooking(id);
          if(btn.dataset.action==='offerAlternative') await this.offerAlternative(id);
          if(btn.dataset.action==='editBooking') await this.editBooking(id);
        });
      }

      resetFilters(){
        const snap = this.snapshotFocus();
        this.state.filters = { status:'', box:'', id:'', name:'' };
        this.applyFilters(snap);
      }

      // ---------- Filterlogik ----------
      applyFilters(focusSnapshot=null){
        const f = this.state.filters;
        const rows = this.state.originalBookings.filter(x=>{
          if (f.status && (x.visual_status||x.status)!==f.status) return false;
          if(f.box){const c=x.order?.cart;const matches=['dj','party','event'].includes(f.box)?x.order?.package===f.box:f.box==='photo'?!!c?.photo:f.box==='tent'?!!c?.tent:f.box==='controller'?!!c?.controller:x.box_id===f.box||String(c?.speaker)===f.box;if(!matches)return false;}

          if (f.id){
            const disp = displayId(x.id);
            if (!disp.startsWith(f.id)) return false;
          }

          if (f.name){
            const q = f.name.toLowerCase();
            const inName = (x.customer_name||'').toLowerCase().includes(q);
            const inMail = (x.customer_email||'').toLowerCase().includes(q);
            if (!inName && !inMail) return false;
          }
          return true;
        });

        this.state.bookings = rows;

        this.render();
        this.restoreFocus(focusSnapshot);

        const fid = document.getElementById(`filter-id-${this.instanceId}`);
        const fnm = document.getElementById(`filter-name-${this.instanceId}`);
        const fs  = document.getElementById(`filter-status-${this.instanceId}`);
        const fb  = document.getElementById(`filter-box-${this.instanceId}`);
        if(fid && fid.value !== this.state.filters.id) fid.value = this.state.filters.id;
        if(fnm && fnm.value !== this.state.filters.name) fnm.value = this.state.filters.name;
        if(fs && fs.value !== this.state.filters.status) fs.value = this.state.filters.status;
        if(fb && fb.value !== this.state.filters.box) fb.value = this.state.filters.box;

        const btn = document.getElementById(`btn-reset-${this.instanceId}`);
        if(btn && !btn._bound){ btn.addEventListener('click', ()=> this.resetFilters()); btn._bound=true; }
      }

      // ---------- Aktionen ----------
      async confirmBooking(id){
        const b = this.state.originalBookings.find(x=>x.id===id); if(!b) return;
        const subject = `Deine Buchung wurde bestätigt ✅ – Buchungs-ID: ${displayId(id)}`;
        const body = this.tplConfirm(b);
        const html = `
          <div class="mmb-field"><strong>Buchung:</strong> ${displayId(id)} – ${this.escape(b.customer_name)} (${this.escape(b.box_name)})</div>
          <div class="mmb-field"><label>Betreff</label><input name="subject" value="${this.escape(subject)}"/></div>
          <div class="mmb-field"><label>Nachricht an Kund*in</label><textarea name="message">${this.escape(body)}</textarea></div>
        `;
        const values = await this.openModal({ title:'Buchung bestätigen', html, confirmText:'Status auf „Bestätigt“ setzen' });
        if(!values) return;
        await MMBApi.updateBookingStatus({ booking_id: Number(id), status: 'confirmed', send_email: 1, subject: values.subject, message: values.message });
        await this.loadBookings();
        MMBCore.Utils.showNotification('Buchung wurde bestätigt');
      }

      async declineBooking(id){
        const b = this.state.originalBookings.find(x=>x.id===id); if(!b) return;
        const subject = `Deine Buchung wurde abgelehnt ❌ – Buchungs-ID: ${displayId(id)}`;
        const body = this.tplReject(b);
        const html = `
          <div class="mmb-field"><strong>Buchung:</strong> ${displayId(id)} – ${this.escape(b.customer_name)} (${this.escape(b.box_name)})</div>
          <div class="mmb-field"><label>Grund (intern)</label><input name="reason" placeholder="z.B. Terminkonflikt"/></div>
          <div class="mmb-field"><label>Betreff</label><input name="subject" value="${this.escape(subject)}"/></div>
          <div class="mmb-field"><label>Nachricht an Kund*in</label><textarea name="message">${this.escape(body)}</textarea></div>
        `;
        const values = await this.openModal({ title:'Buchung ablehnen', html, confirmText:'Als „Abgelehnt“ markieren' });
        if(!values) return;
        await MMBApi.updateBookingStatus({ booking_id: Number(id), status: 'rejected', send_email: 1, subject: values.subject, message: values.message });
        await this.loadBookings();
        MMBCore.Utils.showNotification('Buchung wurde abgelehnt');
      }

      async offerAlternative(id){
        const b = this.state.originalBookings.find(x=>x.id===id); if(!b) return;
        const alternatives = [{id:'2',name:BOX_MAP['2']},{id:'3',name:BOX_MAP['3']},{id:'1',name:BOX_MAP['1']}]
          .filter(x => x.id !== b.box_id);
        const altMap = Object.fromEntries(alternatives.map(a=>[a.id,a]));
        const subject = `Konflikt bei deiner Buchung ⚠️ – Buchungs-ID: ${displayId(id)}`;
        const initialSelection = alternatives.length ? [alternatives[0]] : [];
        const html = `
          <div class="mmb-field"><strong>Buchung:</strong> ${displayId(id)} – ${this.escape(b.customer_name)}</div>
          <div class="mmb-field"><label>Alternative Box(en)</label>
            <div class="mmb-alt-options">
              ${alternatives.map((x,idx)=>`
                <label><input type="checkbox" name="alternative_box_ids" value="${x.id}" ${idx===0?'checked':''}/> ${this.escape(x.name)}</label>
              `).join('')}
            </div>
          </div>
          <div class="mmb-field"><label>Betreff</label><input name="subject" value="${this.escape(subject)}"/></div>
          <div class="mmb-field"><label>Nachricht an Kund*in</label><textarea name="message">${this.escape(this.tplAlternative(b, initialSelection))}</textarea></div>
        `;
        const values = await this.openModal({
          title:'Alternative Box vorschlagen',
          html,
          confirmText:'Alternative senden',
          onMount: (form)=>{
            const boxes = Array.from(form.querySelectorAll('input[name="alternative_box_ids"]'));
            const ta  = form.querySelector('textarea[name="message"]');
            const sync = ()=>{
              const checked = boxes.filter(cb=>cb.checked);
              if(!checked.length && boxes.length){
                boxes[0].checked = true;
                return sync();
              }
              const selected = checked.map(cb=>altMap[cb.value]).filter(Boolean);
              const fallback = selected.length ? selected : initialSelection;
              ta.value = this.tplAlternative(b, fallback);
            };
            boxes.forEach(cb=>cb.addEventListener('change', sync));
            sync();
          }
        });
        if(!values) return;

        const rawAlt = values.alternative_box_ids;
        const selectedIds = Array.isArray(rawAlt) ? rawAlt : (rawAlt ? [rawAlt] : []);
        const selected = selectedIds.map(v=>altMap[v]).filter(Boolean);
        if(!selected.length){
          MMBCore.Utils.showNotification('Bitte wähle mindestens eine Alternative aus.', 'error');
          return;
        }

        const payload = {
          booking_id: Number(id),
          status: 'rejected',
          alternative_box_ids: selected.map(alt=>Number(alt.id)),
          send_email: 1,
          subject: values.subject,
          message: values.message
        };

        const response = await MMBApi.updateBookingStatus(payload);
        await this.loadBookings();

        const mailInfo = response?.mail || null;
        if(mailInfo && mailInfo.sent === false){
          const err = mailInfo.error ? ` – ${mailInfo.error}` : '';
          MMBCore.Utils.showNotification('Alternative versendet, aber E-Mail fehlgeschlagen'+err, 'error');
        }else{
          MMBCore.Utils.showNotification('Alternative vorgeschlagen (Status: Abgelehnt)');
        }
      }

      async deleteBooking(id, booking=null, closeOverlay=null){
        const current = booking || this.state.originalBookings.find(x=>String(x.id)===String(id));
        if(!current){
          MMBCore.Utils.showNotification('Buchung nicht gefunden','error');
          return;
        }
        const display = displayId(current.id);
        const html = `
          <p>Diese Buchung wird dauerhaft entfernt und Kund*innen werden <strong>nicht</strong> informiert.</p>
          <p><strong>Buchung:</strong> ${display} – ${this.escape(current.customer_name || 'Unbekannt')}</p>
          <p>Möchtest du wirklich fortfahren?</p>
        `;
        const values = await this.openModal({
          title:'Buchung endgültig löschen',
          html,
          confirmText:'Endgültig löschen',
          cancelText:'Abbrechen'
        });
        if(!values) return;
        try{
          const response = await MMBApi.deleteBooking({ id: Number(id) });
          const deleted = !response || response.deleted !== false;
          if(typeof closeOverlay === 'function') closeOverlay();
          else if(this.activeDetailOverlayClose) this.activeDetailOverlayClose();
          const msg = deleted ? `Buchung ${display} wurde gelöscht` : `Buchung ${display} wurde nicht gefunden`;
          const type = deleted ? 'info' : 'error';
          MMBCore.Utils.showNotification(msg, type);
          await this.loadBookings();
        }catch(err){
          console.error('deleteBooking error', err);
          MMBCore.Utils.showNotification('Buchung konnte nicht gelöscht werden', 'error');
        }
      }

      async editBooking(id){
        const b = this.state.originalBookings.find(x=>x.id===id); if(!b) return;
        const boxOptions = [{id:'1',name:BOX_MAP['1']},{id:'2',name:BOX_MAP['2']},{id:'3',name:BOX_MAP['3']}]
          .map(x => `<option value="${x.id}" ${x.id===b.box_id?'selected':''}>${this.escape(x.name)}</option>`).join('');
        const currentFulfillment = (b.fulfillment_method || 'pickup').toString();
        const fulfillmentOptions = FULFILLMENT_CHOICES
          .map(opt => `<option value="${opt.id}" ${opt.id===currentFulfillment?'selected':''}>${this.escape(opt.label)}</option>`)
          .join('');
        const fulfillmentInfo = this.getFulfillmentInfo(b);
        const fulfillmentHint = fulfillmentInfo.note ? this.escape(fulfillmentInfo.note) : 'Keine zusätzlichen Hinweise';
        const baseSubject = `Deine Buchung wurde aktualisiert 👷🏽 – Buchungs-ID: ${displayId(id)}`;
        const parseAmount = (val)=>{
          if(val===null || val===undefined) return NaN;
          if(typeof val==='number') return val;
          let str = String(val).trim();
          if(!str) return NaN;
          str = str.replace(/€/g,'').replace(/\s+/g,'');
          if(str.includes(',')){
            str = str.replace(/\./g,'').replace(',','.');
          }
          const num = Number(str);
          if(Number.isNaN(num) && str.includes('.')){
            const fallback = Number(str.replace(/\./g,''));
            return Number.isNaN(fallback) ? NaN : fallback;
          }
          return num;
        };
        const body = this.tplEditInfo(b);
        const html = `
          <div class="mmb-grid-2">
            <div class="mmb-field"><label>Kundename</label><input name="customer_name" value="${this.escape(b.customer_name)}"/></div>
            <div class="mmb-field"><label>E-Mail</label><input name="customer_email" value="${this.escape(b.customer_email)}"/></div>
          </div>
          <div class="mmb-grid-2">
            <div class="mmb-field"><label>Telefon</label><input name="customer_phone" value="${this.escape(b.customer_phone)}"/></div>
            <div class="mmb-field"><label>Box</label><select name="box_id">${boxOptions}</select></div>
          </div>
          <div class="mmb-grid-2">
            <div class="mmb-field"><label>Start (YYYY-MM-DD)</label><input name="start_date" value="${this.escape(b.start_date)}"/></div>
            <div class="mmb-field"><label>Ende (YYYY-MM-DD)</label><input name="end_date" value="${this.escape(b.end_date)}"/></div>
          </div>
          <div class="mmb-grid-2">
            <div class="mmb-field"><label>Status</label>
              <select name="status">
                <option value="pending" ${b.status==='pending'?'selected':''}>Ausstehend</option>
                <option value="confirmed" ${b.status==='confirmed'?'selected':''}>Bestätigt</option>
                <option value="rejected" ${b.status==='rejected'?'selected':''}>Abgelehnt</option>
                <option value="cancelled" ${b.status==='cancelled'?'selected':''}>Storniert</option>
              </select>
            </div>
            <div class="mmb-field"><label>Preis (EUR)</label><input name="total_amount" value="${this.escape(b.price_total)}"/></div>
          </div>
          <div class="mmb-grid-2">
            <div class="mmb-field"><label>Abwicklung</label><select name="fulfillment_method">${fulfillmentOptions}</select><small class="mmb-text-muted">Steuert Versand-/Lieferhinweise und Preisaufschläge.</small></div>
            <div class="mmb-field"><label>Hinweis zur Abwicklung</label><div data-fulfillment-hint class="mmb-text-muted">${fulfillmentHint}</div></div>
          </div>
          <hr style="border:none;border-top:1px solid var(--mmb-border);margin:.75rem 0"/>
          <div class="mmb-field mmb-field-switch">
            <label><input type="checkbox" name="send_email" value="1" checked/> <span>Kund*in per E-Mail informieren</span></label>
            <small>Wenn aktiviert, wird die aktualisierte Buchung an ${this.escape(b.customer_email)} gesendet.</small>
          </div>
          <div class="mmb-field" data-email-field><label>Betreff (E-Mail an Kund*in)</label><input name="subject" value="${this.escape(baseSubject)}"/></div>
          <div class="mmb-field" data-email-field><label>Nachricht (E-Mail an Kund*in)</label><textarea name="message">${this.escape(body)}</textarea></div>
        `;
        const values = await this.openModal({
          title:`Buchung bearbeiten – ${displayId(id)}`,
          html,
          confirmText:'Änderungen speichern',
          onMount:(form)=>{
            const subjectInput = form.querySelector('input[name="subject"]');
            const messageInput = form.querySelector('textarea[name="message"]');
            const sendSwitch = form.querySelector('input[name="send_email"]');
            const companyField=form.querySelector('[name=company_name]'),typeField=form.querySelector('[name=customer_type]');
            if(companyField&&typeField){const syncCompany=()=>{companyField.required=typeField.value==='business';companyField.closest('.mmb-detail-field').hidden=typeField.value!=='business';};typeField.addEventListener('change',syncCompany);syncCompany();}
            const watchFields = ['customer_name','customer_email','customer_phone','box_id','start_date','end_date','status','total_amount','fulfillment_method'];
            let subjectDirty = false;
            let messageDirty = false;

            const collectCurrentValues = ()=>{
              const data = {};
              watchFields.forEach((name)=>{
                const field = form.querySelector(`[name="${name}"]`);
                if(!field) return;
                data[name] = field.value;
              });
              return data;
            };

            const buildBookingForEmail = (vals)=>{
              const boxId = String(vals.box_id ?? b.box_id ?? '');
              const fulfillmentChoice = this.getFulfillmentChoice((vals.fulfillment_method ?? b.fulfillment_method ?? 'pickup').toString());
              const parsedAmount = parseAmount(vals.total_amount ?? b.price_total);
              return {
                ...b,
                customer_name: vals.customer_name ?? b.customer_name,
                customer_email: vals.customer_email ?? b.customer_email,
                customer_phone: vals.customer_phone ?? b.customer_phone,
                box_id: boxId,
                box_name: BOX_MAP[boxId] || BOX_MAP[String(b.box_id)] || b.box_name,
                start_date: vals.start_date ?? b.start_date,
                end_date: vals.end_date ?? b.end_date,
                status: vals.status ?? b.status,
                price_total: Number.isFinite(parsedAmount) ? parsedAmount : b.price_total,
                fulfillment_method: fulfillmentChoice.id,
                fulfillment_label: fulfillmentChoice.label,
                fulfillment_note: fulfillmentChoice.note,
                fulfillment_price_delta: fulfillmentChoice.priceDelta
              };
            };

            const syncEmailFields = (force=false)=>{
              if(!subjectInput || !messageInput) return;
              const current = buildBookingForEmail(collectCurrentValues());
              if(force || !subjectDirty){
                subjectInput.value = baseSubject;
              }
              if(force || !messageDirty){
                messageInput.value = this.tplEditInfo(current);
              }
            };

            if(subjectInput){
              subjectInput.addEventListener('input', ()=>{ subjectDirty = subjectInput.value !== baseSubject; });
            }
            if(messageInput){
              messageInput.addEventListener('input', ()=>{ messageDirty = true; });
            }

            watchFields.forEach((name)=>{
              const field = form.querySelector(`[name="${name}"]`);
              if(!field) return;
              const evt = field.tagName === 'SELECT' ? 'change' : 'input';
              field.addEventListener(evt, ()=> syncEmailFields());
            });

            const fulfillmentSelect = form.querySelector('select[name="fulfillment_method"]');
            const fulfillmentHintEl = form.querySelector('[data-fulfillment-hint]');
            const updateFulfillmentHint = ()=>{
              if(!fulfillmentSelect || !fulfillmentHintEl) return;
              const choice = this.getFulfillmentChoice(fulfillmentSelect.value);
              fulfillmentHintEl.textContent = choice.note || 'Keine zusätzlichen Hinweise';
            };
            if(fulfillmentSelect){
              fulfillmentSelect.addEventListener('change', updateFulfillmentHint);
              updateFulfillmentHint();
            }

            const applyEmailToggleState = ()=>{
              if(!subjectInput || !messageInput || !sendSwitch) return;
              const enabled = sendSwitch.checked;
              subjectInput.disabled = !enabled;
              messageInput.disabled = !enabled;
              form.classList.toggle('mmb-email-disabled', !enabled);
            };

            if(sendSwitch){
              applyEmailToggleState();
              sendSwitch.addEventListener('change', ()=>{
                applyEmailToggleState();
                if(sendSwitch.checked){
                  syncEmailFields();
                }
              });
            }

            syncEmailFields(true);
          }
        });
        if(!values) return;

        const nextBoxIdRaw = values.box_id ?? b.box_id;
        const nextBoxIdNum = Number(nextBoxIdRaw);
        const finalBoxId = Number.isFinite(nextBoxIdNum) && nextBoxIdNum > 0 ? nextBoxIdNum : Number(b.box_id);

        const originalTotalRaw = parseAmount(b.price_total ?? b.total_amount);
        const fallbackDbTotal = Number(b.price_total ?? b.total_amount);
        const originalTotal = Number.isFinite(originalTotalRaw)
          ? Math.round(originalTotalRaw * 100) / 100
          : (Number.isFinite(fallbackDbTotal) ? Math.round(fallbackDbTotal * 100) / 100 : 0);
        const normalizedTotal = (()=>{
          const parsed = parseAmount(values.total_amount);
          if(Number.isFinite(parsed)) return Math.round(parsed * 100) / 100;
          return originalTotal;
        })();

        const startDate = ((values.start_date||'').trim()) || b.start_date;
        const endDate = ((values.end_date||'').trim()) || b.end_date;

        const nextFulfillmentChoice = this.getFulfillmentChoice((values.fulfillment_method ?? b.fulfillment_method ?? 'pickup').toString());
        const sanitized = {
          customer_name: (values.customer_name||'').trim(),
          customer_email: (values.customer_email||'').trim(),
          customer_phone: (values.customer_phone||'').trim(),
          box_id: booking.order&&!orderState?.cart?.speaker?null:finalBoxId,
          start_date: startDate,
          end_date: endDate,
          status: (values.status || b.status || '').trim(),
          total_amount: normalizedTotal,
          fulfillment_method: nextFulfillmentChoice.id,
          fulfillment_label: nextFulfillmentChoice.label,
          fulfillment_note: nextFulfillmentChoice.note || '',
          fulfillment_price_delta: Number.isFinite(nextFulfillmentChoice.priceDelta) ? nextFulfillmentChoice.priceDelta : 0
        };

        const originalFulfillment = this.getFulfillmentInfo(b);
        const originalNormalized = {
          customer_name: (b.customer_name || '').trim(),
          customer_email: (b.customer_email || '').trim(),
          customer_phone: (b.customer_phone || '').trim(),
          box_id: Number(b.box_id),
          start_date: (b.start_date || '').trim(),
          end_date: (b.end_date || '').trim(),
          status: (b.status || '').trim(),
          total_amount: originalTotal,
          fulfillment_method: originalFulfillment.method,
          fulfillment_label: originalFulfillment.label,
          fulfillment_note: originalFulfillment.note || '',
          fulfillment_price_delta: originalFulfillment.priceDelta
        };

        const updatesToSend = {};
        const markIfChanged = (key, nextValue, prevValue)=>{
          if(key === 'total_amount'){
            const nextFinite = Number.isFinite(nextValue);
            const prevFinite = Number.isFinite(prevValue);
            if(nextFinite && prevFinite && Math.abs(nextValue - prevValue) < 0.01) return;
            if(!nextFinite && !prevFinite) return;
          }else if(key === 'box_id'){
            if(Number(nextValue) === Number(prevValue)) return;
          }else{
            if(nextValue === prevValue) return;
          }
          updatesToSend[key] = nextValue;
        };

        markIfChanged('customer_name', sanitized.customer_name, originalNormalized.customer_name);
        markIfChanged('customer_email', sanitized.customer_email, originalNormalized.customer_email);
        markIfChanged('customer_phone', sanitized.customer_phone, originalNormalized.customer_phone);
        markIfChanged('box_id', sanitized.box_id, originalNormalized.box_id);
        markIfChanged('start_date', sanitized.start_date, originalNormalized.start_date);
        markIfChanged('end_date', sanitized.end_date, originalNormalized.end_date);
        markIfChanged('status', sanitized.status, originalNormalized.status);
        markIfChanged('total_amount', sanitized.total_amount, originalNormalized.total_amount);

        const fulfillmentChanged = (
          sanitized.fulfillment_method !== originalNormalized.fulfillment_method ||
          sanitized.fulfillment_label !== originalNormalized.fulfillment_label ||
          sanitized.fulfillment_note !== originalNormalized.fulfillment_note ||
          Math.abs(sanitized.fulfillment_price_delta - originalNormalized.fulfillment_price_delta) > 0.01
        );
        if(fulfillmentChanged){
          updatesToSend.fulfillment_method = sanitized.fulfillment_method;
          updatesToSend.fulfillment_label = sanitized.fulfillment_label;
          updatesToSend.fulfillment_note = sanitized.fulfillment_note;
          updatesToSend.fulfillment_price_delta = sanitized.fulfillment_price_delta;
        }

        const hasUpdates = Object.keys(updatesToSend).length > 0;
        const shouldSendEmail = values.send_email === '1' || values.send_email === 'on' || values.send_email === 'true';
        const normalizedSubject = (values.subject || baseSubject).trim();
        const normalizedMessage = (values.message || '').trim();

        if(!hasUpdates && !shouldSendEmail){
          MMBCore.Utils.showNotification('Keine Änderungen gespeichert');
          return;
        }

        const bookingId = Number(id);
        let updatesApplied = false;
        if(hasUpdates){
          const updatePayload = { id: bookingId };
          Object.entries(updatesToSend).forEach(([key, value])=>{
            if(key === 'box_id'){
              updatePayload[key] = Number(value);
            }else if(key === 'total_amount'){
              const numeric = Number(value);
              updatePayload[key] = Number.isFinite(numeric) ? Math.round(numeric * 100) / 100 : value;
            }else if(key === 'fulfillment_price_delta'){
              const numeric = Number(value);
              updatePayload[key] = Number.isFinite(numeric) ? Math.round(numeric * 100) / 100 : 0;
            }else{
              updatePayload[key] = value;
            }
          });

          try {
            await MMBApi.updateBooking(updatePayload);
            updatesApplied = true;
          } catch (err) {
            console.error('updateBooking failed:', err);
            const errorMessage = err && err.message ? err.message : 'Aktualisierung fehlgeschlagen';
            MMBCore.Utils.showNotification(errorMessage, 'error');
            return;
          }
        }

        let mailInfo = null;
        let emailFailed = false;
        let emailError = '';

        if(shouldSendEmail){
          const emailPayload = {
            booking_id: bookingId,
            send_email: 1,
            subject: normalizedSubject,
            message: normalizedMessage,
          };
          if(sanitized.status){
            emailPayload.status = sanitized.status;
          }

          try {
            const response = await MMBApi.updateBookingStatus(emailPayload);
            mailInfo = response && response.mail ? response.mail : null;
            if(mailInfo && mailInfo.sent === false){
              emailFailed = true;
              emailError = mailInfo.error ? ` (Fehler: ${mailInfo.error})` : '';
            }
          } catch (err) {
            console.error('updateBookingStatus failed:', err);
            emailFailed = true;
            emailError = err && err.message ? ` (${err.message})` : '';
          }
        }

        if(updatesApplied || shouldSendEmail){
          await this.loadBookings();
        }

        if(shouldSendEmail){
          if(emailFailed){
            const base = updatesApplied ? 'Buchung aktualisiert (E-Mail konnte nicht gesendet werden)' : 'E-Mail konnte nicht gesendet werden';
            MMBCore.Utils.showNotification(base + emailError, 'error');
          }else{
            const message = updatesApplied
              ? 'Buchung aktualisiert & Kund*in informiert'
              : 'E-Mail an Kund*in gesendet';
            MMBCore.Utils.showNotification(message);
          }
        }else if(updatesApplied){
          MMBCore.Utils.showNotification('Buchung aktualisiert');
        }
      }

      // ---------- Modal & Utils ----------
      openModal({ title, html, confirmText='OK', cancelText='Abbrechen', onMount=null }){
        return new Promise((resolve)=>{
          const modal = document.createElement('div');
          modal.className = 'mmb-modal';
          const cancelBtnHtml = cancelText ? `<button type="button" class="mmb-modal-btn mmb-modal-btn-secondary" data-cancel>${cancelText}</button>` : '';
          const confirmBtnHtml = confirmText ? `<button type="submit" class="mmb-modal-btn mmb-modal-btn-primary">${confirmText}</button>` : '';
          modal.innerHTML = `
            <div class="mmb-modal-content" role="dialog" aria-modal="true">
              <h3>${title}</h3>
              <form id="mmb-modal-form">
                ${html}
                <div class="mmb-modal-actions">
                  ${cancelBtnHtml}
                  ${confirmBtnHtml}
                </div>
              </form>
            </div>`;
          document.body.appendChild(modal);

          const form = modal.querySelector('#mmb-modal-form');
          const cancelBtn = modal.querySelector('[data-cancel]');

          if(typeof onMount==='function'){ try{ onMount(form, modal); }catch(e){} }

          if(cancelBtn){ cancelBtn.addEventListener('click', ()=>{ modal.remove(); resolve(null); }); }
          modal.addEventListener('click', (ev)=>{ if(ev.target===modal) { modal.remove(); resolve(null); }});
          form.addEventListener('submit', (ev)=>{
            ev.preventDefault();
            const data = new FormData(form);
            const values = {};
            data.forEach((v,k)=>{
              if(Object.prototype.hasOwnProperty.call(values,k)){
                if(Array.isArray(values[k])) values[k].push(v);
                else values[k] = [values[k], v];
              }else{
                values[k]=v;
              }
            });
            modal.remove();
            resolve(values);
          });
        });
      }

      escape(s){ return (s??'').toString().replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
      translateStatus(s){ return ({pending:'Ausstehend',confirmed:'Bestätigt',rejected:'Abgelehnt',cancelled:'Storniert',alternative:'Alternative vorgeschlagen'})[s]||s; }

      getFulfillmentChoice(id){ return FULFILLMENT_LOOKUP[id] || FULFILLMENT_CHOICES[0]; }
      computeFulfillmentMeta(record, normalizedDetails){
        const details = normalizedDetails && typeof normalizedDetails === 'object' ? normalizedDetails : {};
        const fallbackMethod = (record.fulfillment_method ?? '').toString().toLowerCase();
        const methodSource = details.method ?? fallbackMethod;
        const method = (methodSource ?? '').toString().toLowerCase();
        const choice = this.getFulfillmentChoice(method || fallbackMethod || 'pickup');
        const label = details.label ?? record.fulfillment_label ?? choice.label;
        const note = details.note ?? record.fulfillment_note ?? choice.note ?? '';
        const priceCandidates = [details.price_delta, details.priceDelta, details.price, record.fulfillment_price_delta, choice.priceDelta];
        const defaultPrice = Number(choice.priceDelta);
        let priceDelta = Number.isFinite(defaultPrice) ? defaultPrice : 0;
        for(const candidate of priceCandidates){
          if(candidate === undefined || candidate === null) continue;
          const num = Number(candidate);
          if(Number.isFinite(num)){ priceDelta = num; break; }
        }
        const addsToTotalCandidate = details.adds_to_total ?? details.addsToTotal ?? record.fulfillment_adds_to_total;
        const addsToTotal = typeof addsToTotalCandidate === 'boolean' ? addsToTotalCandidate : (choice.addsToTotal ?? true);
        return {
          method: method || choice.id,
          label: label || choice.label,
          note,
          priceDelta,
          addsToTotal
        };
      }
      getFulfillmentInfo(b){
        const details = b.fulfillment_details && typeof b.fulfillment_details === 'object' ? b.fulfillment_details : {};
        const rawMethod = details.method ?? b.fulfillment_method;
        const raw = (rawMethod || '').toString().toLowerCase();
        const choice = this.getFulfillmentChoice(raw);
        const label = details.label ?? b.fulfillment_label ?? choice.label;
        const note = details.note ?? b.fulfillment_note ?? choice.note ?? '';
        const priceCandidates = [details.price_delta, details.priceDelta, details.price, b.fulfillment_price_delta, choice.priceDelta];
        const defaultPrice = Number(choice.priceDelta);
        let price = Number.isFinite(defaultPrice) ? defaultPrice : 0;
        for(const candidate of priceCandidates){
          if(candidate === undefined || candidate === null) continue;
          const num = Number(candidate);
          if(Number.isFinite(num)){ price = num; break; }
        }
        const addsToTotalCandidate = details.adds_to_total ?? details.addsToTotal ?? b.fulfillment_adds_to_total;
        const addsToTotal = typeof addsToTotalCandidate === 'boolean' ? addsToTotalCandidate : (choice.addsToTotal ?? true);
        const full = details.full ?? choice.full;
        return {
          method: raw || choice.id,
          label: label || choice.label,
          full,
          note,
          priceDelta: Number.isFinite(price) ? price : choice.priceDelta,
          addsToTotal
        };
      }
      formatFulfillmentCell(b){
        const info = this.getFulfillmentInfo(b);
        return info.label || info.full || '–';
      }
      formatEuro(amount){
        const num = Number(amount||0);
        const safe = Number.isFinite(num) ? num : 0;
        return safe.toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';
      }
      fulfillmentEmailLine(b){
        if(b.order){const details=b.fulfillment_details||{};return b.order.fulfillment_label+' — '+b.order.fulfillment_note+(b.fulfillment_method!=='shipping'&&details.preferred_time?' · Wunschzeit: '+details.preferred_time+' Uhr (wird abgestimmt)':'')+(b.fulfillment_method!=='shipping'&&details.meeting_point?' · Hinweis: '+details.meeting_point:'');}
        const info = this.getFulfillmentInfo(b);
        if(info.method==='pickup'){
          return info.priceDelta>0 ? `${info.full} +${this.formatEuro(info.priceDelta)}` : `${info.full} (gratis)`;
        }
        if(info.method==='shipping'){
          return `${info.full} +${this.formatEuro(info.priceDelta)}`;
        }
        return info.note ? `${info.full} (${info.note})` : info.full;
      }

      async viewDetails(id){
        const booking = this.state.originalBookings.find(x=>String(x.id)===String(id));
        if(!booking) return;
        this.openDetailOverlay(booking);
      }

      async editBooking(id){
        await this.viewDetails(id);
      }

      openDetailOverlay(booking){
        const overlay = document.createElement('div');
        overlay.className = 'mmb-detail-overlay';
        overlay.innerHTML = `<div class="mmb-detail-panel"><div class="mmb-detail-header"><div><h2 class="mmb-detail-title">Buchung ${this.escape(displayId(booking.id))}</h2></div><button type="button" class="mmb-detail-close" data-close>×</button></div><div class="mmb-detail-body"><p>Detailansicht wird geladen…</p></div></div>`;
        document.body.appendChild(overlay);
        document.body.classList.add('mmb-detail-open');
        const close = ()=>{
          if(overlay.parentNode){ overlay.remove(); }
          document.body.classList.remove('mmb-detail-open');
          document.removeEventListener('keydown', escHandler);
        };
        const escHandler = (event)=>{ if(event.key==='Escape'){ close(); } };
        document.addEventListener('keydown', escHandler);
        overlay.addEventListener('click',(event)=>{ if(event.target===overlay) close(); });
        overlay.querySelectorAll('[data-close]').forEach(btn=>btn.addEventListener('click', close));
        // Vollständige Detailansicht wird im weiteren Verlauf aufgebaut
        this.renderDetailOverlay(booking, overlay, close);
        MMBAdminAuth.focusPanel?.();
      }

      renderDetailOverlay(booking, overlay, closeOverlay){
        const statusLabel = this.translateStatus(booking.visual_status || booking.status || 'pending');
        const createdLabel = this.formatDateTimeLabel(booking.created_at);
        const rangeLabel = this.formatBookingRangeLabel(booking.start_date, booking.end_date);
        const boxOptions = this.getBoxOptionsForDetail(booking);
        const boxOptionsHtml = boxOptions.map(box=>`<option value="${this.escape(box.id)}" ${box.id===String(booking.box_id)?'selected':''}>${this.escape(box.name)}</option>`).join('');
        const currentFulfillmentMethod = (booking.fulfillment_method || 'pickup').toString();
        const fulfillmentChoice = this.getFulfillmentChoice(currentFulfillmentMethod);
        const fulfillmentOptionsHtml = FULFILLMENT_CHOICES.map(opt=>`<option value="${opt.id}" ${opt.id===currentFulfillmentMethod?'selected':''}>${this.escape(opt.label)}</option>`).join('');
        const pricing = this.normalizePricingDetails(booking.pricing_details);
        const fulfillmentDetails = this.normalizeFulfillmentDetails(booking.fulfillment_details, currentFulfillmentMethod) || {};
        const depositValue = Number.isFinite(booking.deposit_eur) ? booking.deposit_eur : (Number(pricing?.deposit) || 0);
        const totalValue = Number.isFinite(booking.price_total) ? booking.price_total : (Number(pricing?.total) || 0);
        const baseTotalValue = Number.isFinite(pricing?.base_total) ? pricing.base_total : totalValue;
        const originalValue = Number.isFinite(pricing?.original) ? pricing.original : baseTotalValue;
        const shippingDeltaValue = Number.isFinite(pricing?.shipping_delta) ? pricing.shipping_delta : (Number.isFinite(fulfillmentChoice.priceDelta) ? fulfillmentChoice.priceDelta : 0);
        const discountAmountValue = Number.isFinite(pricing?.discount_amount) ? pricing.discount_amount : 0;
        const discountPercentExactValue = Number.isFinite(pricing?.discount_percent_exact) ? pricing.discount_percent_exact : 0;
        const discountPercentDisplayValue = pricing?.discount_percent_display || (discountPercentExactValue ? String(discountPercentExactValue) : '');
        const avgPerDayValue = Number.isFinite(pricing?.avg_per_day) ? pricing.avg_per_day : null;
        const daysValue = Number.isFinite(pricing?.days) ? pricing.days : null;
        const baseSubject = `Deine Buchung wurde aktualisiert 👷🏽 – Buchungs-ID: ${displayId(booking.id)}`;
        const baseMessage = this.tplEditInfo(booking);
        const shippingAllowPhone = fulfillmentDetails.allow_contact_phone !== false;
        const shippingAllowWhatsApp = fulfillmentDetails.allow_contact_whatsapp === true || (fulfillmentDetails.contact_preferences && fulfillmentDetails.contact_preferences.whatsapp === true);
        const preferredTimes = ['', '11:00','11:30','12:00','12:30','13:00','13:30','14:00','14:30','15:00','15:30','16:00'];
        const existingPreferred = (fulfillmentDetails.preferred_time || '').trim();
        if(existingPreferred && !preferredTimes.includes(existingPreferred)){
          preferredTimes.push(existingPreferred);
        }
        const preferredOptionsHtml = preferredTimes.map(time=>{
          const label = time ? `${time} Uhr` : 'Bitte auswählen';
          const isSelected = time === (fulfillmentDetails.preferred_time || '');
          return `<option value="${time}" ${isSelected?'selected':''}>${this.escape(label)}</option>`;
        }).join('');
        const showExtended = currentFulfillmentMethod === 'shipping' || currentFulfillmentMethod.startsWith('delivery');

        overlay.innerHTML = `
          <div class="mmb-detail-panel" role="dialog" aria-modal="true">
            <div class="mmb-detail-header">
              <div class="mmb-detail-summary">
                <div class="mmb-detail-title-row"><h2 class="mmb-detail-title">Buchung ${this.escape(displayId(booking.id))} <span data-summary="customer_name">${this.escape(booking.customer_name)}</span></h2><span class="mmb-status-badge mmb-status-${this.escape(booking.visual_status||booking.status)}" data-summary="status">${this.escape(statusLabel)}</span></div>
                <div class="mmb-detail-meta">
                  <span>${MMBIcons.icon('calendar')}<span data-summary="range">${this.escape(rangeLabel)}</span></span>
                  <span>${MMBIcons.icon('truck')}<span data-summary="fulfillment_method">${this.escape(fulfillmentChoice.label)}</span></span>
                  <strong>${MMBIcons.icon('euro')}<span data-summary="total_amount">${this.formatEuro(totalValue)}</span></strong>
                  <a data-summary="customer_email" href="mailto:${this.escape(booking.customer_email)}">${MMBIcons.icon('mail')}<span>${this.escape(booking.customer_email||'Keine E-Mail')}</span></a>
                  <a data-summary="customer_phone" href="tel:${this.escape(booking.customer_phone)}">${MMBIcons.icon('phone')}<span>${this.escape(booking.customer_phone||'Keine Telefonnummer')}</span></a>
                </div>
              </div>
              <button type="button" class="mmb-detail-close" data-close aria-label="Detailansicht schließen">×</button>
            </div>
            <form class="mmb-detail-form" autocomplete="off"><div class="mmb-detail-body">
              <div class="mmb-detail-section">
                <h3>Allgemein</h3>
                <div class="mmb-detail-grid">
                  <div class="mmb-detail-field">
                    <label>Status</label>
                    <select name="status">
                      ${['pending','confirmed','rejected','cancelled'].map(st=>`<option value="${st}" ${st===(booking.status||'')?'selected':''}>${this.escape(this.translateStatus(st))}</option>`).join('')}
                    </select>
                  </div>
                  <div class="mmb-detail-field">
                    <label>Box</label>
                    <select name="box_id" ${booking.order?'disabled':''}>${boxOptionsHtml}</select>${booking.order?'<span class="mmb-detail-note">Produkte im Ausstattungsbereich bearbeiten.</span>':''}
                  </div>
                  <div class="mmb-detail-field">
                    <label>Startdatum</label>
                    <input name="start_date" type="date" value="${this.escape(booking.start_date||'')}">
                  </div>
                  <div class="mmb-detail-field">
                    <label>Enddatum</label>
                    <input name="end_date" type="date" value="${this.escape(booking.end_date||'')}">
                  </div>
                  <p class="mmb-created-note">Erstellt am ${this.escape(createdLabel)}</p>
                </div>
              </div>

              <div class="mmb-detail-section">
                <h3>Kontakt</h3>
                <div class="mmb-detail-grid">
                  <div class="mmb-detail-field">
                    <label>Name</label>
                    <input name="customer_name" value="${this.escape(booking.customer_name||'')}">
                  </div>
                  <div class="mmb-detail-field">
                    <label>E-Mail</label>
                    <input name="customer_email" type="email" value="${this.escape(booking.customer_email||'')}">
                  </div>
                  <div class="mmb-detail-field">
                    <label>Telefon</label>
                    <input name="customer_phone" value="${this.escape(booking.customer_phone||'')}">
                  </div>
                </div>
              </div>

              <div class="mmb-detail-section">
                <h3>Rechnungsadresse</h3><div class="mmb-detail-grid"><div class="mmb-detail-field"><label>Kundentyp</label><select name="customer_type"><option value="private" ${booking.customer_type!=='business'?'selected':''}>Privat</option><option value="business" ${booking.customer_type==='business'?'selected':''}>Gewerblich</option></select></div><div class="mmb-detail-field"><label>Vollständiger Unternehmensname</label><input name="company_name" maxlength="200" value="${this.escape(booking.company_name||'')}"></div></div>
                <div class="mmb-detail-grid">
                  <div class="mmb-detail-field"><label>Straße &amp; Hausnummer</label><input name="billing_address_line1" maxlength="200" value="${this.escape(booking.billing_address_line1||'')}"></div>
                  <div class="mmb-detail-field"><label>PLZ</label><input name="billing_postal_code" maxlength="20" value="${this.escape(booking.billing_postal_code||'')}"></div>
                  <div class="mmb-detail-field"><label>Ort</label><input name="billing_city" maxlength="120" value="${this.escape(booking.billing_city||'')}"></div>
                  <div class="mmb-detail-field"><label>Geburtsdatum (freiwillig)</label><input type="date" name="customer_birthdate" value="${this.escape(booking.customer_birthdate||'')}"></div>
                </div>
                <p class="mmb-detail-note">E-Mail-Adresse: ${booking.email_verified_at?'Bestätigt am '+this.escape(this.formatDateTimeLabel(booking.email_verified_at)):'Noch nicht bestätigt / bei Altanfragen nicht erfasst'}. Dies ist keine Identitätsprüfung.</p>
                <p class="mmb-detail-note">Eingangsmail: ${booking.customer_mail_sent_at?'vom Mailserver angenommen':'nicht als versendet erfasst'} · Interne Benachrichtigung: ${booking.internal_mail_sent_at?'vom Mailserver angenommen':'nicht als versendet erfasst'}. Der tatsächliche Posteingang ist damit nicht garantiert.</p>
              </div>

              <div class="mmb-detail-section">
                <h3>Abwicklung</h3>
                <div class="mmb-detail-grid">
                  <div class="mmb-detail-field">
                    <label>Art</label>
                    <select name="fulfillment_method">${fulfillmentOptionsHtml}</select>
                    <span class="mmb-detail-note" data-fulfillment-hint>${this.escape(fulfillmentChoice.note || 'Keine zusätzlichen Hinweise')}</span>
                  </div>
                  <div class="mmb-detail-field">
                    <label>Abwicklungshinweis (kundenrelevant)</label>
                    <textarea name="fulfillment_note">${this.escape(booking.fulfillment_note || '')}</textarea>
                  </div>
                </div>
              </div>

              <div class="mmb-detail-section" data-fulfillment-section="extended" ${showExtended?'':'hidden'}>
                <h3>Kontakt für Abwicklung</h3>
                <div class="mmb-detail-grid">
                  <div class="mmb-detail-field">
                    <label>Ansprechpartner*in</label>
                    <input name="fulfillment_contact_name" value="${this.escape(fulfillmentDetails.contact_name || booking.customer_name || '')}">
                  </div>
                  <div class="mmb-detail-field">
                    <label>E-Mail</label>
                    <input name="fulfillment_contact_email" type="email" value="${this.escape(fulfillmentDetails.contact_email || booking.customer_email || '')}">
                  </div>
                  <div class="mmb-detail-field">
                    <label>Telefon</label>
                    <input name="fulfillment_contact_phone" value="${this.escape(fulfillmentDetails.contact_phone || booking.customer_phone || '')}">
                  </div>
                </div>
                <div class="mmb-detail-grid">
                  <div class="mmb-detail-field">
                    <label>Kontaktoptionen</label>
                    <label class="mmb-detail-checkbox"><input type="checkbox" name="fulfillment_pref_phone" value="1" ${shippingAllowPhone?'checked':''}> Telefon erlaubt</label>
                    <label class="mmb-detail-checkbox"><input type="checkbox" name="fulfillment_pref_whatsapp" value="1" ${shippingAllowWhatsApp?'checked':''}> WhatsApp erlaubt</label>
                  </div>
                </div>
              </div>

              <div class="mmb-detail-section" data-fulfillment-section="address" ${showExtended?'':'hidden'}>
                <h3 data-destination-title>${currentFulfillmentMethod==='shipping'?'Versandadresse':'Lieferadresse'}</h3><div class="mmb-detail-grid"><div class="mmb-detail-field"><label>Unternehmen (bei Gewerbe)</label><input name="shipping_company_name" maxlength="200" value="${this.escape(fulfillmentDetails.company_name||'')}"></div></div>
                <div class="mmb-detail-grid">
                  <div class="mmb-detail-field">
                    <label>Straße &amp; Hausnummer</label>
                    <input name="shipping_address_line1" value="${this.escape(fulfillmentDetails.address_line1 || '')}">
                  </div>
                  <div class="mmb-detail-field">
                    <label>PLZ</label>
                    <input name="shipping_postal_code" value="${this.escape(fulfillmentDetails.postal_code || '')}">
                  </div>
                  <div class="mmb-detail-field">
                    <label>Ort</label>
                    <input name="shipping_city" value="${this.escape(fulfillmentDetails.city || '')}">
                  </div>
                  <div class="mmb-detail-field">
                    <label>Adresszusatz</label>
                    <input name="shipping_address_extra" value="${this.escape(fulfillmentDetails.address_extra || '')}">
                  </div>
                </div>
              </div>

              <div class="mmb-detail-section" data-fulfillment-section="delivery" ${currentFulfillmentMethod!=='shipping'?'':'hidden'}>
                <h3 data-timing-title>${currentFulfillmentMethod==='pickup'?'Abholdetails':'Lieferdetails'}</h3>
                <div class="mmb-detail-grid">
                  <div class="mmb-detail-field">
                    <label data-timing-hint>${currentFulfillmentMethod==='pickup'?'Abholhinweis':'Lieferhinweis'}</label>
                    <textarea name="delivery_meeting_point">${this.escape(fulfillmentDetails.meeting_point || '')}</textarea>
                  </div>
                  <div class="mmb-detail-field">
                    <label data-timing-label>Wunschzeit für ${currentFulfillmentMethod==='pickup'?'Abholung':'Lieferung'} am ${this.escape(booking.start_date)}</label>
                    <select name="delivery_preferred_time">${preferredOptionsHtml}</select>
                  </div>
                </div>
              </div>

              <div class="mmb-detail-section">
                <h3>Rechnungsübersicht</h3>
                <div class="mmb-invoice-rows">
                  ${[['pricing_original','Miete vor Rabatt',originalValue],['pricing_discount_amount','Rabatt',discountAmountValue],['pricing_base_total','Zwischensumme nach Rabatt',baseTotalValue],['pricing_shipping_delta','Versand / Lieferung',shippingDeltaValue],['total_amount','Gesamtpreis',totalValue],['deposit_eur','Kaution (zusätzlich)',depositValue]].map(([name,label,value])=>`<div class="mmb-detail-field mmb-invoice-row ${name==='total_amount'?'mmb-invoice-total':''}"><label>${label}</label><div class="mmb-money-input"><input inputmode="decimal" name="${name}" value="${this.escape(this.formatNumberInput(value))}"><span aria-hidden="true">€</span></div></div>`).join('')}
                </div>
                <details class="mmb-price-calculation"><summary>Berechnungsdetails bearbeiten</summary><div class="mmb-detail-grid">
                  <div class="mmb-detail-field"><label>Rabatt (%)</label><input inputmode="decimal" name="pricing_discount_percent_exact" value="${this.escape(this.formatNumberInput(discountPercentExactValue))}"></div>
                  <div class="mmb-detail-field"><label>Rabatt (Anzeige)</label><input name="pricing_discount_percent_display" value="${this.escape(discountPercentDisplayValue)}"></div>
                  <div class="mmb-detail-field"><label>Ø Preis pro Tag</label><input inputmode="decimal" name="pricing_avg_per_day" value="${this.escape(this.formatNumberInput(avgPerDayValue))}"></div>
                  <div class="mmb-detail-field"><label>Anzahl Tage</label><input inputmode="numeric" name="pricing_days" value="${this.escape(this.formatIntegerInput(daysValue))}"></div>
                </div></details>
                <p class="mmb-detail-note">Beträge in Euro. Änderungen an Einzelwerten sind manuelle Preisangaben; bitte Gesamtpreis und Rabatt gemeinsam prüfen.</p>
              </div>

              <div class="mmb-detail-section">
                <h3>Anmerkungen</h3>
                <div class="mmb-detail-field">
                  <label>Interne Notizen</label>
                  <textarea name="note">${this.escape(booking.note || '')}</textarea>
                </div>
              </div>

              <div class="mmb-detail-section">
                <h3>E-Mail an Kund*in</h3>
                <label class="mmb-detail-checkbox"><input type="checkbox" name="send_email" value="1" checked> Kund*in nach dem Speichern informieren</label>
                <div class="mmb-detail-grid" data-email-field>
                  <div class="mmb-detail-field">
                    <label>Betreff</label>
                    <input name="subject" value="${this.escape(baseSubject)}">
                  </div>
                  <div class="mmb-detail-field">
                    <label>Nachricht</label>
                    <textarea name="message">${this.escape(baseMessage)}</textarea>
                  </div>
                </div>
                <div class="mmb-detail-note">Nur kundenrelevante Felder werden in der E-Mail angezeigt.</div>
              </div>

              </div><div class="mmb-detail-footer">
                <button type="button" class="mmb-btn mmb-btn-secondary" data-close>Schließen</button>
                <div class="mmb-detail-actions">
                  <button type="button" class="mmb-btn mmb-btn-secondary mmb-toggle-actions" data-toggle-actions aria-expanded="false">Weitere Aktionen</button>
                  <div class="mmb-secondary-actions">
                  <button type="button" class="mmb-btn mmb-btn-warning" data-action="offerAlternative">Alternative</button>
                  <button type="button" class="mmb-btn mmb-btn-danger" data-action="declineBooking">Ablehnen</button>
                  <button type="button" class="mmb-btn mmb-btn-success" data-action="confirmBooking">Bestätigen</button>
                  <button type="button" class="mmb-btn mmb-btn-danger-light" data-action="deleteBooking">Löschen</button>
                  </div><button type="submit" class="mmb-btn">Speichern</button>
                </div>
              </div>
            </form>
          </div>
        `;

        const form = overlay.querySelector('form');
        if(!form) return;
        const fieldIcons={customer_type:'user',company_name:'house',shipping_company_name:'house',status:'circle-check',box_id:'speaker',start_date:'calendar',end_date:'calendar',customer_name:'user',customer_email:'mail',customer_phone:'phone',billing_address_line1:'house',billing_postal_code:'map-pin',billing_city:'map-pin',customer_birthdate:'cake',fulfillment_method:'truck',fulfillment_note:'info'};
        form.querySelectorAll('.mmb-detail-field').forEach((field,index)=>{
          const input=field.querySelector('input,select,textarea'),label=field.querySelector('label');
          if(!input||!label||label.querySelector('input'))return;
          input.id='mmb-detail-'+booking.id+'-'+index;label.htmlFor=input.id;
          label.insertAdjacentHTML('afterbegin',MMBIcons.icon(fieldIcons[input.name]||(input.name.startsWith('pricing_')||input.name==='total_amount'?'euro':'info')));
        });
        const refreshSummary=()=>{
          ['customer_name','customer_email','customer_phone','total_amount','status','fulfillment_method'].forEach(name=>{
            const input=form.elements.namedItem(name),target=overlay.querySelector('[data-summary="'+name+'"]');if(!input||!target)return;
            const value=input.value;
            if(name==='status'){const visual=value===booking.status?(booking.visual_status||value):value;target.className='mmb-status-badge mmb-status-'+visual;target.textContent=this.translateStatus(visual);}
            else if(name==='fulfillment_method')target.textContent=this.getFulfillmentChoice(value).label;
            else if(name==='total_amount')target.textContent=this.formatEuro(Number(String(value).replace(',','.'))||0);
            else if(name==='customer_email'||name==='customer_phone'){target.href=(name==='customer_email'?'mailto:':'tel:')+value;target.querySelector('span').textContent=value||'Nicht angegeben';}
            else target.textContent=value;
          });
          overlay.querySelector('[data-summary="range"]').textContent=this.formatBookingRangeLabel(form.elements.start_date.value,form.elements.end_date.value);
        };
        form.addEventListener('input',refreshSummary);form.addEventListener('change',refreshSummary);
        overlay.querySelector('[data-toggle-actions]')?.addEventListener('click',event=>{
          const actions=event.currentTarget.closest('.mmb-detail-actions');
          const expanded=actions.classList.toggle('is-expanded');
          event.currentTarget.setAttribute('aria-expanded',String(expanded));
        });

        overlay.querySelectorAll('[data-close]').forEach(btn=>btn.addEventListener('click', closeOverlay));

        const idString = String(booking.id);
        ['confirmBooking','declineBooking','offerAlternative'].forEach(action=>{
          const btn = overlay.querySelector(`[data-action="${action}"]`);
          if(!btn) return;
          btn.addEventListener('click', async ()=>{
            closeOverlay();
            if(action==='confirmBooking') await this.confirmBooking(idString);
            if(action==='declineBooking') await this.declineBooking(idString);
            if(action==='offerAlternative') await this.offerAlternative(idString);
          });
        });

        const deleteBtn = overlay.querySelector('[data-action="deleteBooking"]');
        if(deleteBtn){
          deleteBtn.addEventListener('click', async ()=>{
            const confirmation = await this.openModal({
              title:'Buchung löschen',
              html:'<p>Möchtest du diese Buchung dauerhaft entfernen? Diese Aktion kann nicht rückgängig gemacht werden.</p>',
              confirmText:'Ja, löschen',
              cancelText:'Abbrechen'
            });
            if(confirmation === null) return;
            const originalLabel = deleteBtn.textContent;
            deleteBtn.textContent = 'Löschen…';
            deleteBtn.disabled = true;
            try{
              await MMBApi.deleteBooking({ id: idString });
              closeOverlay();
              await this.loadBookings();
              MMBCore.Utils.showNotification('Buchung gelöscht');
            }catch(err){
              console.error('deleteBooking failed:', err);
              const msg = err && err.message ? ` (${err.message})` : '';
              MMBCore.Utils.showNotification('Buchung konnte nicht gelöscht werden'+msg, 'error');
            }finally{
              if(deleteBtn.isConnected){
                deleteBtn.disabled = false;
                deleteBtn.textContent = originalLabel;
              }
            }
          });
        }

        const fulfillmentSelect = form.querySelector('select[name="fulfillment_method"]');
        const hintEl = form.querySelector('[data-fulfillment-hint]');
        const detailSections = form.querySelectorAll('[data-fulfillment-section]');
        const updateFulfillmentUI = ()=>{
          if(!fulfillmentSelect) return;
          const method = fulfillmentSelect.value;
          const destinationTitle=form.querySelector('[data-destination-title]');if(destinationTitle)destinationTitle.textContent=method==='shipping'?'Versandadresse':'Lieferadresse';
          const timingTitle=form.querySelector('[data-timing-title]'),timingHint=form.querySelector('[data-timing-hint]'),timingLabel=form.querySelector('[data-timing-label]');
          if(timingTitle)timingTitle.textContent=method==='pickup'?'Abholdetails':'Lieferdetails';if(timingHint)timingHint.textContent=method==='pickup'?'Abholhinweis':'Lieferhinweis';if(timingLabel)timingLabel.textContent='Wunschzeit für '+(method==='pickup'?'Abholung':'Lieferung')+' am '+(form.elements.start_date?.value||booking.start_date);
          detailSections.forEach(section=>{
            const target = section.dataset.fulfillmentSection;
            if(target==='extended' || target==='address'){
              section.hidden = !(method==='shipping' || method.startsWith('delivery'));
            }else{
              section.hidden = target==='delivery'?method==='shipping':target!==method;
            }
          });
          if(hintEl){
            const choice = this.getFulfillmentChoice(method);
            hintEl.textContent = choice.note || 'Keine zusätzlichen Hinweise';
          }
        };
        updateFulfillmentUI();
        if(fulfillmentSelect){ fulfillmentSelect.addEventListener('change', updateFulfillmentUI); }

        const subjectInput = form.querySelector('input[name="subject"]');
        const messageInput = form.querySelector('textarea[name="message"]');
        const sendSwitch = form.querySelector('input[name="send_email"]');
        const companyField=form.querySelector('[name=company_name]'),typeField=form.querySelector('[name=customer_type]');
            if(companyField&&typeField){const syncCompany=()=>{companyField.required=typeField.value==='business';companyField.closest('.mmb-detail-field').hidden=typeField.value!=='business';};typeField.addEventListener('change',syncCompany);syncCompany();}
            const watchFields = ['customer_name','customer_email','customer_phone','box_id','start_date','end_date','status','total_amount','fulfillment_method'];
        let subjectDirty = false;
        let messageDirty = false;

        const collectCurrentValues = ()=>{
          const data={};
          watchFields.forEach(name=>{
            const field=form.querySelector(`[name="${name}"]`);
            if(!field) return;
            data[name] = field.value;
          });
          return data;
        };

        const buildBookingForEmail = (vals)=>{
          const boxId = String(vals.box_id ?? booking.box_id ?? '');
          const fulfillmentChoiceLocal = this.getFulfillmentChoice((vals.fulfillment_method ?? booking.fulfillment_method ?? 'pickup').toString());
          const parsedAmount = this.parseAmount(vals.total_amount ?? booking.price_total);
          return {
            ...booking,
            customer_name: vals.customer_name ?? booking.customer_name,
            customer_email: vals.customer_email ?? booking.customer_email,
            customer_phone: vals.customer_phone ?? booking.customer_phone,
            box_id: boxId,
            order: form._mmbOrder?.quote||booking.order,
            box_name: booking.order?(form._mmbOrder?.quote?.summary||booking.order.summary):(BOX_MAP[boxId] || BOX_MAP[String(booking.box_id)] || booking.box_name),
            start_date: vals.start_date ?? booking.start_date,
            end_date: vals.end_date ?? booking.end_date,
            status: vals.status ?? booking.status,
            price_total: Number.isFinite(parsedAmount) ? parsedAmount : booking.price_total,
            fulfillment_method: fulfillmentChoiceLocal.id,
            fulfillment_label: fulfillmentChoiceLocal.label,
            fulfillment_note: fulfillmentChoiceLocal.note,
            fulfillment_price_delta: fulfillmentChoiceLocal.priceDelta
          };
        };

        const syncEmailFields = (force=false)=>{
          if(!subjectInput || !messageInput) return;
          const current = buildBookingForEmail(collectCurrentValues());
          if(force || !subjectDirty){
            subjectInput.value = baseSubject;
          }
          if(force || !messageDirty){
            messageInput.value = this.tplEditInfo(current);
          }
        };

        if(subjectInput){ subjectInput.addEventListener('input', ()=>{ subjectDirty = subjectInput.value !== baseSubject; }); }
        if(messageInput){ messageInput.addEventListener('input', ()=>{ messageDirty = true; }); }

        watchFields.forEach(name=>{
          const field=form.querySelector(`[name="${name}"]`);
          if(!field) return;
          const evt = field.tagName === 'SELECT' ? 'change' : 'input';
          field.addEventListener(evt, ()=> syncEmailFields());
        });

        const applyEmailToggleState = ()=>{
          if(!subjectInput || !messageInput || !sendSwitch) return;
          const enabled = !!sendSwitch.checked;
          subjectInput.disabled = !enabled;
          messageInput.disabled = !enabled;
          form.classList.toggle('mmb-email-disabled', !enabled);
        };

        if(sendSwitch){
          applyEmailToggleState();
          sendSwitch.addEventListener('change', ()=>{
            applyEmailToggleState();
            if(sendSwitch.checked){ syncEmailFields(); }
          });
        }

        form._mmbOrder=MMBOrderAdmin.mount(form,booking,mmbApiPost);
        syncEmailFields(true);
        form.addEventListener('mmb-order-quoted',()=>syncEmailFields());
        form.addEventListener('submit', async (event)=>{
          event.preventDefault();
          await this.handleDetailSubmit(form, booking, closeOverlay, { baseSubject });
        });
      }

      async handleDetailSubmit(form, booking, closeOverlay, meta={}){
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalLabel = submitBtn ? submitBtn.textContent : '';
        if(submitBtn){ submitBtn.disabled = true; submitBtn.textContent = 'Speichern…'; }
        try{
          const orderState=form._mmbOrder?await form._mmbOrder.flush():null;
          const getField = (name)=>form.querySelector(`[name="${name}"]`);
          const getValue = (name)=>{
            const el = getField(name);
            if(!el) return '';
            if(el.type === 'checkbox') return el.checked;
            return el.value ?? '';
          };

          const nextBoxIdRaw = getValue('box_id') || booking.box_id;
          const nextBoxIdNum = Number(nextBoxIdRaw);
          const finalBoxId = Number.isFinite(nextBoxIdNum) && nextBoxIdNum > 0 ? nextBoxIdNum : Number(booking.box_id);

          const totalParsed = this.parseAmount(getValue('total_amount'));
          const normalizedTotal = Number.isFinite(totalParsed)
            ? this.roundCurrency(totalParsed)
            : (Number.isFinite(booking.price_total) ? this.roundCurrency(booking.price_total) : 0);

          const depositParsed = this.parseAmount(getValue('deposit_eur'));
          const normalizedDeposit = Number.isFinite(depositParsed)
            ? this.roundCurrency(depositParsed)
            : (Number.isFinite(booking.deposit_eur) ? this.roundCurrency(booking.deposit_eur) : 0);

          const startDate = (getValue('start_date') || booking.start_date || '').trim();
          const endDate = (getValue('end_date') || booking.end_date || '').trim();
          const statusValue = (getValue('status') || booking.status || '').trim();

          const nextFulfillmentMethod = (getValue('fulfillment_method') || booking.fulfillment_method || 'pickup').toString();
          const nextFulfillmentChoice = this.getFulfillmentChoice(nextFulfillmentMethod);
          const fulfillmentNoteValue = (getValue('fulfillment_note') || booking.fulfillment_note || '').trim();
          const shippingDeltaParsed = this.parseAmount(getValue('pricing_shipping_delta'));
          const fulfillmentPriceDelta = Number.isFinite(shippingDeltaParsed)
            ? this.roundCurrency(shippingDeltaParsed)
            : (Number.isFinite(nextFulfillmentChoice.priceDelta) ? this.roundCurrency(nextFulfillmentChoice.priceDelta) : 0);

          const sanitized = {
            customer_name: (getValue('customer_name') || booking.customer_name || '').trim(),
            customer_email: (getValue('customer_email') || booking.customer_email || '').trim(),
            customer_phone: (getValue('customer_phone') || booking.customer_phone || '').trim(),
            customer_birthdate: getValue('customer_birthdate').trim(),
            customer_type:getValue('customer_type'),company_name:getValue('company_name').trim(),
            billing_city: getValue('billing_city').trim(),
            billing_postal_code: getValue('billing_postal_code').trim(),
            billing_address_line1: getValue('billing_address_line1').trim(),
            box_id: booking.order&&!orderState?.cart?.speaker?null:finalBoxId,
            start_date: startDate,
            end_date: endDate,
            status: statusValue,
            total_amount: normalizedTotal,
            fulfillment_method: nextFulfillmentChoice.id,
            fulfillment_label: nextFulfillmentChoice.label,
            fulfillment_note: fulfillmentNoteValue,
            fulfillment_price_delta: fulfillmentPriceDelta,
            note: (getValue('note') || booking.note || '').trim(),
            deposit_eur: normalizedDeposit
          };

          const originalFulfillment = this.getFulfillmentInfo(booking);
          const originalNormalized = {
            customer_name: (booking.customer_name || '').trim(),
            customer_email: (booking.customer_email || '').trim(),
            customer_phone: (booking.customer_phone || '').trim(),
            customer_birthdate: (booking.customer_birthdate || '').trim(),
            customer_type:booking.customer_type||'private',company_name:booking.company_name||'',
            billing_city: (booking.billing_city || '').trim(),
            billing_postal_code: (booking.billing_postal_code || '').trim(),
            billing_address_line1: (booking.billing_address_line1 || '').trim(),
            box_id: Number(booking.box_id),
            start_date: (booking.start_date || '').trim(),
            end_date: (booking.end_date || '').trim(),
            status: (booking.status || '').trim(),
            total_amount: Number.isFinite(booking.price_total) ? this.roundCurrency(booking.price_total) : 0,
            fulfillment_method: originalFulfillment.method,
            fulfillment_label: originalFulfillment.label,
            fulfillment_note: originalFulfillment.note || '',
            fulfillment_price_delta: this.roundCurrency(originalFulfillment.priceDelta || 0),
            note: (booking.note || '').trim(),
            deposit_eur: Number.isFinite(booking.deposit_eur) ? this.roundCurrency(booking.deposit_eur) : 0
          };

          const updatesToSend = {};
          const markIfChanged = (key, nextValue, prevValue)=>{
            if(key==='total_amount' || key==='deposit_eur' || key==='fulfillment_price_delta'){
              const nextFinite = Number.isFinite(nextValue);
              const prevFinite = Number.isFinite(prevValue);
              if(nextFinite && prevFinite && Math.abs(nextValue - prevValue) < 0.01) return;
              if(!nextFinite && !prevFinite) return;
            }else if(key==='box_id'){
              if(Number(nextValue) === Number(prevValue)) return;
            }else{
              if(nextValue === prevValue) return;
            }
            updatesToSend[key] = nextValue;
          };

          markIfChanged('customer_name', sanitized.customer_name, originalNormalized.customer_name);
          markIfChanged('customer_email', sanitized.customer_email, originalNormalized.customer_email);
          markIfChanged('customer_phone', sanitized.customer_phone, originalNormalized.customer_phone);
          markIfChanged('customer_birthdate', sanitized.customer_birthdate, originalNormalized.customer_birthdate);
          markIfChanged('customer_type',sanitized.customer_type,originalNormalized.customer_type);
          markIfChanged('company_name',sanitized.company_name,originalNormalized.company_name);
          markIfChanged('billing_city', sanitized.billing_city, originalNormalized.billing_city);
          markIfChanged('billing_postal_code', sanitized.billing_postal_code, originalNormalized.billing_postal_code);
          markIfChanged('billing_address_line1', sanitized.billing_address_line1, originalNormalized.billing_address_line1);
          markIfChanged('box_id', sanitized.box_id, originalNormalized.box_id);
          markIfChanged('start_date', sanitized.start_date, originalNormalized.start_date);
          markIfChanged('end_date', sanitized.end_date, originalNormalized.end_date);
          markIfChanged('status', sanitized.status, originalNormalized.status);
          markIfChanged('total_amount', sanitized.total_amount, originalNormalized.total_amount);
          markIfChanged('note', sanitized.note, originalNormalized.note);
          markIfChanged('deposit_eur', sanitized.deposit_eur, originalNormalized.deposit_eur);

          const fulfillmentChanged = (
            sanitized.fulfillment_method !== originalNormalized.fulfillment_method ||
            sanitized.fulfillment_label !== originalNormalized.fulfillment_label ||
            sanitized.fulfillment_note !== originalNormalized.fulfillment_note ||
            Math.abs(sanitized.fulfillment_price_delta - originalNormalized.fulfillment_price_delta) > 0.01
          );
          if(fulfillmentChanged){
            updatesToSend.fulfillment_method = sanitized.fulfillment_method;
            updatesToSend.fulfillment_label = sanitized.fulfillment_label;
            updatesToSend.fulfillment_note = sanitized.fulfillment_note;
            updatesToSend.fulfillment_price_delta = sanitized.fulfillment_price_delta;
          }

          const pricingPayload = this.buildPricingDetailsPayload(form, booking, sanitized, nextFulfillmentChoice);
          const pricingCanonical = this.stableStringify(pricingPayload || {});
          const originalPricingCanonical = booking.pricing_details_canonical || this.stableStringify({});
          if(pricingCanonical !== originalPricingCanonical){
            updatesToSend.pricing_details_json = pricingPayload && Object.keys(pricingPayload).length ? pricingPayload : null;
          }

          const fulfillmentDetailsPayload = this.buildFulfillmentDetailsPayload(form, nextFulfillmentChoice.id, booking.fulfillment_details);
          const nextFulfillmentCanonical = this.stableStringify(fulfillmentDetailsPayload || {});
          const originalFulfillmentCanonical = booking.fulfillment_details_canonical || this.stableStringify({});
          if(nextFulfillmentCanonical !== originalFulfillmentCanonical){
            updatesToSend.fulfillment_details_json = fulfillmentDetailsPayload && Object.keys(fulfillmentDetailsPayload).length ? fulfillmentDetailsPayload : null;
          }

          if(orderState?.dirty){updatesToSend.cart=orderState.cart;updatesToSend.manual_price_override=orderState.manual;updatesToSend.start_date=sanitized.start_date;updatesToSend.end_date=sanitized.end_date;updatesToSend.fulfillment_method=sanitized.fulfillment_method;if(orderState.manual)updatesToSend.total_amount=sanitized.total_amount;}
          const hasUpdates = Object.keys(updatesToSend).length > 0;
          const sendSwitch = getField('send_email');
          const shouldSendEmail = !!(sendSwitch && sendSwitch.checked);
          const subjectValue = (getValue('subject') || '').trim();
          const messageValue = (getValue('message') || '').trim();

          if(!hasUpdates && !shouldSendEmail){
            MMBCore.Utils.showNotification('Keine Änderungen gespeichert');
            return;
          }

          const bookingId = Number(booking.id);
          let updatesApplied = false;
          if(hasUpdates){
            const payload = { id: bookingId };
            Object.entries(updatesToSend).forEach(([key,value])=>{
              if(value === undefined) return;
              if(key === 'box_id'){
                payload[key] = Number(value);
                return;
              }
              if(key === 'total_amount' || key === 'deposit_eur' || key === 'fulfillment_price_delta'){
                const numeric = Number(value);
                payload[key] = Number.isFinite(numeric) ? this.roundCurrency(numeric) : value;
                return;
              }
              if((key === 'pricing_details_json' || key === 'fulfillment_details_json') && value === null){
                payload[key] = null;
                return;
              }
              payload[key] = value;
            });
            try{
              await MMBApi.updateBooking(payload);
              updatesApplied = true;
            }catch(err){
              console.error('updateBooking failed:', err);
              const message = err && err.message ? err.message : 'Aktualisierung fehlgeschlagen';
              MMBCore.Utils.showNotification(message, 'error');
              return;
            }
          }

          let emailFailed = false;
          let emailError = '';
          if(shouldSendEmail){
            try{
              const emailPayload = {
                booking_id: bookingId,
                send_email: 1,
                subject: subjectValue || meta.baseSubject || '',
                message: messageValue || ''
              };
              if(sanitized.status){ emailPayload.status = sanitized.status; }
              const response = await MMBApi.updateBookingStatus(emailPayload);
              const mailInfo = response && response.mail ? response.mail : null;
              if(mailInfo && mailInfo.sent === false){
                emailFailed = true;
                emailError = mailInfo.error ? ` (Fehler: ${mailInfo.error})` : '';
              }
            }catch(err){
              console.error('updateBookingStatus failed:', err);
              emailFailed = true;
              emailError = err && err.message ? ` (${err.message})` : '';
            }
          }

          closeOverlay();
          await this.loadBookings();

          if(shouldSendEmail){
            if(emailFailed){
              const base = updatesApplied ? 'Buchung aktualisiert (E-Mail konnte nicht gesendet werden)' : 'E-Mail konnte nicht gesendet werden';
              MMBCore.Utils.showNotification(base + emailError, 'error');
            }else{
              const msg = updatesApplied ? 'Buchung aktualisiert & Kund*in informiert' : 'E-Mail an Kund*in gesendet';
              MMBCore.Utils.showNotification(msg);
            }
          }else if(updatesApplied){
            MMBCore.Utils.showNotification('Buchung aktualisiert');
          }
        }catch(err){
          MMBCore.Utils.showNotification('Speichern fehlgeschlagen: '+(err.message||'Bitte Angaben prüfen.'),'error');
        }finally{
          if(submitBtn){ submitBtn.disabled = false; submitBtn.textContent = originalLabel; }
        }
      }

      getBoxOptionsForDetail(booking){
        const seen = new Set();
        const options = [];
        (this.state.boxes||[]).forEach(box=>{
          const id = String(box.id);
          if(seen.has(id)) return;
          seen.add(id);
          options.push({id,name:box.name});
        });
        const currentId = String(booking.box_id);
        if(booking.order&&!booking.box_id)options.unshift({id:'',name:'Ohne Musikbox'});
        if(currentId && !seen.has(currentId)){
          options.push({id:currentId,name:booking.box_name || BOX_MAP[currentId] || `Box #${currentId}`});
        }
        if(!options.length){
          Object.entries(BOX_MAP).forEach(([id,name])=>options.push({id,name}));
        }
        return options;
      }

      formatDateTimeLabel(value){
        if(!value) return '–';
        const date = new Date(value);
        if(Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleString('de-DE', { dateStyle:'long', timeStyle:'short' });
      }

      formatBookingRangeLabel(start,end){
        const parse=(val)=>{ if(!val) return null; const d=new Date(val); return Number.isNaN(d.getTime())?null:d; };
        const s=parse(start); const e=parse(end);
        if(s && e){
          const sLabel=s.toLocaleDateString('de-DE');
          const eLabel=e.toLocaleDateString('de-DE');
          return sLabel===eLabel ? sLabel : `${sLabel} – ${eLabel}`;
        }
        if(s) return s.toLocaleDateString('de-DE');
        if(e) return e.toLocaleDateString('de-DE');
        return '–';
      }

      formatNumberInput(value, decimals=2){
        const num = Number(value);
        if(!Number.isFinite(num)) return '';
        return (Math.round(num * Math.pow(10,decimals))/Math.pow(10,decimals)).toFixed(decimals).replace('.', ',');
      }

      formatIntegerInput(value){
        const num = Number(value);
        if(!Number.isFinite(num)) return '';
        return String(Math.round(num));
      }

      roundCurrency(value, decimals=2){
        const num = Number(value);
        if(!Number.isFinite(num)) return 0;
        const factor = Math.pow(10,decimals);
        return Math.round(num*factor)/factor;
      }

      parseAmount(value){
        if(value === null || value === undefined) return NaN;
        if(typeof value === 'number') return value;
        let str = String(value).trim();
        if(!str) return NaN;
        str = str.replace(/€/g,'').replace(/\s+/g,'');
        str = str.replace(',', '.');
        const num = Number(str);
        if(Number.isNaN(num) && str.includes('.')){
          const fallback = Number(str.replace(/\./g,''));
          return Number.isNaN(fallback)?NaN:fallback;
        }
        return num;
      }

      safeParseJson(value){
        if(!value) return null;
        if(typeof value === 'object') return value;
        try{ return JSON.parse(value); }catch(e){ return null; }
      }

      normalizePricingDetails(data){
        if(!data || typeof data !== 'object') return null;
        const result = {};
        const numericFields = ['total','deposit','original','base_total','shipping_delta','discount_amount','discount_percent_exact','avg_per_day','fulfillment_price_delta'];
        numericFields.forEach(field=>{
          if(data[field] === undefined || data[field] === null) return;
          const num = Number(data[field]);
          if(Number.isFinite(num)) result[field] = num;
        });
        if(data.discount_percent_display !== undefined) result.discount_percent_display = String(data.discount_percent_display);
        if(data.days !== undefined){
          const daysNum = Number(data.days);
          if(Number.isFinite(daysNum)) result.days = daysNum;
        }
        if(data.pricing_mode) result.pricing_mode = data.pricing_mode;
        if(data.fulfillment_id) result.fulfillment_id = data.fulfillment_id;
        if(data.fulfillment_label) result.fulfillment_label = data.fulfillment_label;
        if(data.fulfillment_note) result.fulfillment_note = data.fulfillment_note;
        if(data.fulfillment_adds_to_total !== undefined) result.fulfillment_adds_to_total = !!data.fulfillment_adds_to_total;
        return Object.keys(result).length ? result : null;
      }

      normalizeFulfillmentDetails(details, fallbackMethod){
        if(!details || typeof details !== 'object') return null;
        const clone = JSON.parse(JSON.stringify(details));
        const fallback = fallbackMethod ? fallbackMethod.toString().toLowerCase() : '';
        if(clone.method){
          clone.method = clone.method.toString().toLowerCase();
        }else if(fallback){
          clone.method = fallback;
        }
        if(clone.label !== undefined && clone.label !== null) clone.label = clone.label.toString();
        if(clone.note !== undefined && clone.note !== null) clone.note = clone.note.toString();
        if(clone.full !== undefined && clone.full !== null) clone.full = clone.full.toString();
        const priceKey = clone.price_delta !== undefined ? 'price_delta' : (clone.priceDelta !== undefined ? 'priceDelta' : (clone.price !== undefined ? 'price' : null));
        if(priceKey){
          const num = Number(clone[priceKey]);
          if(Number.isFinite(num)) clone.price_delta = num;
          else delete clone[priceKey];
        }
        if(clone.price_delta === undefined && clone.priceDelta !== undefined){
          const num = Number(clone.priceDelta);
          if(Number.isFinite(num)) clone.price_delta = num;
          delete clone.priceDelta;
        }
        if(clone.price !== undefined) delete clone.price;
        if(clone.adds_to_total !== undefined){ clone.adds_to_total = !!clone.adds_to_total; }
        if(clone.addsToTotal !== undefined){ clone.adds_to_total = !!clone.addsToTotal; delete clone.addsToTotal; }
        if(clone.contact_preferences && typeof clone.contact_preferences === 'object'){
          clone.contact_preferences = {
            phone: !!clone.contact_preferences.phone,
            whatsapp: !!clone.contact_preferences.whatsapp
          };
        }
        if(clone.allow_contact_phone === undefined){
          clone.allow_contact_phone = clone.contact_preferences ? !!clone.contact_preferences.phone : true;
        }else{
          clone.allow_contact_phone = clone.allow_contact_phone !== false;
        }
        if(clone.allow_contact_whatsapp === undefined){
          clone.allow_contact_whatsapp = clone.contact_preferences ? !!clone.contact_preferences.whatsapp : false;
        }else{
          clone.allow_contact_whatsapp = !!clone.allow_contact_whatsapp;
        }
        return clone;
      }

      stableStringify(value){
        const normalize=(input)=>{
          if(input === null || typeof input !== 'object') return input;
          if(Array.isArray(input)) return input.map(item=>normalize(item));
          const sortedKeys = Object.keys(input).sort();
          const out={};
          sortedKeys.forEach(key=>{ out[key] = normalize(input[key]); });
          return out;
        };
        try{ return JSON.stringify(normalize(value)); }catch(e){ return ''; }
      }

      buildPricingDetailsPayload(form, booking, sanitized, fulfillmentChoice){
        const getValue = (name)=>{
          const el = form.querySelector(`[name="${name}"]`);
          return el ? (el.value ?? '') : '';
        };
        const parseAmountField = (name)=>{
          const val = getValue(name);
          const parsed = this.parseAmount(val);
          return Number.isFinite(parsed) ? this.roundCurrency(parsed) : NaN;
        };

        const payload = {};
        const total = parseAmountField('total_amount');
        if(Number.isFinite(total)) payload.total = total; else payload.total = sanitized.total_amount;

        const baseTotal = parseAmountField('pricing_base_total');
        if(Number.isFinite(baseTotal)) payload.base_total = baseTotal;

        const original = parseAmountField('pricing_original');
        if(Number.isFinite(original)) payload.original = original;

        const shippingDelta = parseAmountField('pricing_shipping_delta');
        if(Number.isFinite(shippingDelta)) payload.shipping_delta = shippingDelta; else payload.shipping_delta = sanitized.fulfillment_price_delta;

        const discountExact = parseAmountField('pricing_discount_percent_exact');
        if(Number.isFinite(discountExact)) payload.discount_percent_exact = discountExact;

        const discountAmount = parseAmountField('pricing_discount_amount');
        if(Number.isFinite(discountAmount)) payload.discount_amount = discountAmount;

        const avgPerDay = parseAmountField('pricing_avg_per_day');
        if(Number.isFinite(avgPerDay)) payload.avg_per_day = avgPerDay;

        const discountDisplay = getValue('pricing_discount_percent_display').trim();
        if(discountDisplay !== '') payload.discount_percent_display = discountDisplay;

        const daysRaw = getValue('pricing_days').trim();
        if(daysRaw !== ''){
          const daysNum = Number(daysRaw);
          if(Number.isFinite(daysNum)) payload.days = Math.round(daysNum);
        }

        payload.deposit = sanitized.deposit_eur;
        payload.fulfillment_id = sanitized.fulfillment_method;
        payload.fulfillment_label = sanitized.fulfillment_label;
        payload.fulfillment_note = sanitized.fulfillment_note;
        payload.fulfillment_price_delta = sanitized.fulfillment_price_delta;
        payload.fulfillment_adds_to_total = fulfillmentChoice.addsToTotal !== false;

        if(payload.total === undefined) payload.total = sanitized.total_amount;
        if(payload.shipping_delta === undefined) payload.shipping_delta = sanitized.fulfillment_price_delta;
        if(payload.discount_percent_display === undefined && payload.discount_percent_exact !== undefined){
          payload.discount_percent_display = String(payload.discount_percent_exact);
        }
        if(!payload.pricing_mode){
          if(Number.isFinite(payload.days) && payload.days > 1){
            payload.pricing_mode = 'range';
          }else if(Number.isFinite(payload.days) && payload.days === 1){
            payload.pricing_mode = 'single';
          }else if(booking.pricing_details && booking.pricing_details.pricing_mode){
            payload.pricing_mode = booking.pricing_details.pricing_mode;
          }
        }

        return payload;
      }

      buildFulfillmentDetailsPayload(form, method, existing){
        if(method !== 'shipping' && !method.startsWith('delivery')){
          return null;
        }
        const getValue = (name)=>{
          const el = form.querySelector(`[name="${name}"]`);
          return el ? (el.value ?? '').toString().trim() : '';
        };
        const getChecked = (name)=>{
          const el = form.querySelector(`[name="${name}"]`);
          return el ? !!el.checked : false;
        };

        const payload = {
          ...(existing && typeof existing==='object'?existing:{}),
          method,
          submitted_via: (existing && typeof existing === 'object' && existing.submitted_via)
            ? existing.submitted_via
            : 'admin_panel'
        };

        const contactName = getValue('fulfillment_contact_name');
        const contactEmail = getValue('fulfillment_contact_email');
        const contactPhone = getValue('fulfillment_contact_phone');
        if(contactName) payload.contact_name = contactName;
        if(contactEmail) payload.contact_email = contactEmail;
        if(contactPhone) payload.contact_phone = contactPhone;

        const allowPhone = getChecked('fulfillment_pref_phone');
        const allowWhatsApp = getChecked('fulfillment_pref_whatsapp');
        payload.allow_contact_phone = allowPhone;
        payload.allow_contact_whatsapp = allowWhatsApp;
        payload.contact_preferences = { phone: allowPhone, whatsapp: allowWhatsApp };

        if(method === 'shipping' || method.startsWith('delivery')){
          payload.company_name=getValue('shipping_company_name');
          payload.customer_type=getValue('customer_type');
          const line1 = getValue('shipping_address_line1');
          const postal = getValue('shipping_postal_code');
          const city = getValue('shipping_city');
          const extra = getValue('shipping_address_extra');
          if(line1) payload.address_line1 = line1;
          if(postal) payload.postal_code = postal;
          if(city) payload.city = city;
          if(extra) payload.address_extra = extra;
        }

        if(method!=='shipping'){
          const meeting = getValue('delivery_meeting_point');
          const preferred = getValue('delivery_preferred_time');
          if(meeting) payload.meeting_point = meeting;
          if(preferred) payload.preferred_time = preferred;
        }

        Object.keys(payload).forEach(key=>{
          const value = payload[key];
          if(value === '' || value === null || (typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length === 0)){
            delete payload[key];
          }
        });

        const meaningfulKeys = Object.keys(payload).filter(key=>!['method','submitted_via'].includes(key));
        return meaningfulKeys.length ? payload : null;
      }

      tplConfirm(b){
        const range = `${new Date(b.start_date).toLocaleDateString('de-DE')} – ${new Date(b.end_date).toLocaleDateString('de-DE')}`;
        return `Hallo ${b.customer_name},

hier sind hervorragende Neuigkeiten für dich 🎉
Wir haben deine Buchung mit der Buchungs-ID: ${displayId(b.id)} bestätigt.

Deine Buchung im Überblick:
👤 Name: ${b.customer_name}
📧 E-Mail: ${b.customer_email}
🎵 Musikbox: ${b.box_name}
🕰️ Zeitraum: ${range}
🚚 Abwicklung: ${this.fulfillmentEmailLine(b)}
💶 Gesamtkosten: ${this.formatEuro(b.price_total)}

Bitte überprüfe einmal, ob wir deine Buchung korrekt hinterlegt haben.

Falls du noch offene Fragen hast, kannst du uns jederzeit kontaktieren.

Viele Grüße
Dein MietMichBox Team
www.mietmichbox.de

E-Mail: info@mietmichbox.de
Telefon: 01742015500`;
      }

      tplReject(b){
        const range = `${new Date(b.start_date).toLocaleDateString('de-DE')} – ${new Date(b.end_date).toLocaleDateString('de-DE')}`;
        return `Hallo ${b.customer_name},

vielen Dank für deine Buchung (${displayId(b.id)}) für den Zeitraum ${range}.
Leider können wir diese Buchung nicht annehmen, da zu deinem gewählten Zeitraum bereits alle unsere Musikboxen vermietet sind.

Du kannst gerne direkt in unserem Buchungskalender (https://www.mietmichbox.de/musikbox-online-buchen) eine neue Buchung zu einem anderen Zeitraum anfragen oder uns als Antwort auf diese E-Mail alternative Zeiträume nennen, sodass wir gemeinsam eine Lösung finden können. Wir helfen dir gerne schnellstmöglich weiter.

Falls du noch offene Fragen hast, kannst du uns jederzeit kontaktieren.

Viele Grüße
Dein MietMichBox Team
www.mietmichbox.de

E-Mail: info@mietmichbox.de
Telefon: 01742015500`;
      }

      tplAlternative(b, alternatives){
        const range = `${new Date(b.start_date).toLocaleDateString('de-DE')} – ${new Date(b.end_date).toLocaleDateString('de-DE')}`;
        const names = Array.isArray(alternatives)
          ? alternatives.map(x=>x?.name).filter(Boolean)
          : (alternatives && alternatives.name ? [alternatives.name] : []);
        let altSentence;
        if(names.length === 0){
          altSentence = 'Wir schlagen dir als Alternative aktuell keine weitere Box vor.';
        }else if(names.length === 1){
          altSentence = `Wir schlagen dir als Alternative die ${names[0]} vor.`;
        }else if(names.length === 2){
          altSentence = `Wir schlagen dir als Alternative die ${names[0]} oder die ${names[1]} vor.`;
        }else{
          const list = names.slice();
          const last = list.pop();
          altSentence = `Wir schlagen dir als Alternative die ${list.join(', die ')} oder die ${last} vor.`;
        }
        return `Hallo ${b.customer_name},

tut uns leid, aber unsere Musikboxen sind sehr gefragt 🔥

Für deine Buchung (${displayId(b.id)}) ist die Musikbox ${b.box_name} für den Zeitraum ${range} leider nicht mehr verfügbar. ${altSentence}

Du kannst gerne direkt in unserem Buchungskalender (https://www.mietmichbox.de/musikbox-online-buchen) eine neue Buchung mit einer anderen Musikbox oder zu einem anderen Zeitraum anfragen. Alternativ kannst du uns auch als Antwort auf diese E-Mail die alternative Musikbox oder den alternativen Zeitraum nennen, sodass wir deine Buchung aktualisieren können. Wir helfen dir gerne schnellstmöglich weiter.

Falls du noch offene Fragen hast, kannst du uns jederzeit kontaktieren.

Viele Grüße
Dein MietMichBox Team
www.mietmichbox.de

E-Mail: info@mietmichbox.de
Telefon: 01742015500`;
      }

      tplEditInfo(b){
        const start = new Date(b.start_date);
        const end = new Date(b.end_date);
        const validStart = !Number.isNaN(start.getTime());
        const validEnd = !Number.isNaN(end.getTime());
        const range = (validStart && validEnd)
          ? `${start.toLocaleDateString('de-DE')} – ${end.toLocaleDateString('de-DE')}`
          : `${b.start_date} – ${b.end_date}`;
        const price = Number(b.price_total ?? b.total_amount ?? 0);
        const priceLabel = Number.isFinite(price)
          ? price.toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2})
          : (b.price_total ?? b.total_amount ?? '0');
        const statusLabel = this.translateStatus(b.status || 'pending');
        const name = b.customer_name || 'Kund*in';
        const email = b.customer_email || '–';
        const box = b.box_name || BOX_MAP[String(b.box_id)] || '—';
        const fulfillment = this.fulfillmentEmailLine(b);
        return `Hallo ${name},

wir haben Details in deiner Buchung (${displayId(b.id)}) aktualisiert.

Deine aktualisierte Buchung im Überblick:
👤 Name: ${name}
📧 E-Mail: ${email}
Ausstattung: ${b.order?b.order.items.map(x=>`${x.quantity}× ${x.label} — ${this.formatEuro(x.total)}`).join('\n'):box}
🕰️ Zeitraum: ${range}
🚚 Abwicklung: ${fulfillment}
💶 Preis: ${priceLabel}€

Bitte überprüfe einmal, ob wir deine Buchung korrekt hinterlegt haben.

Falls du noch offene Fragen hast, kannst du uns jederzeit kontaktieren.

Viele Grüße
Dein MietMichBox Team
www.mietmichbox.de

E-Mail: info@mietmichbox.de
Telefon: 01742015500`;
      }
    }

    
    global.MietMichBoxAdminList={ init:(opts)=>new AdminList(opts) };

  })(window);
  

    document.addEventListener('DOMContentLoaded', async function(){
      try {
        await MMBAdminAuth.ensureSession();
        MietMichBoxAdminList.init({ container:'#mmb-admin-list', locale:'de-DE', timezone:'Europe/Berlin' });
      } catch (error) {
        console.error('Admin-Login konnte nicht initialisiert werden', error);
        const container = document.querySelector('#mmb-admin-list');
        if (container) {
          container.innerHTML = '<p class="mmb-error">Der Admin-Login konnte nicht initialisiert werden. Bitte prüfen Sie die HTTPS-Verbindung oder wenden Sie sich an den Support.</p>';
        }
      }
    });
