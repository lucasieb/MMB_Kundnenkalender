<?php
header('Content-Type: text/html; charset=utf-8');
?>

<meta charset="utf-8">
<title>Test Booking</title>
<style>
  body{font-family:system-ui,Arial,sans-serif;max-width:640px;margin:40px auto;padding:16px}
  label{display:block;margin:12px 0 4px}
  input,button{font-size:16px;padding:8px}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  pre{background:#f6f6f6;padding:12px;border:1px solid #ddd;white-space:pre-wrap}
</style>

<h1>Test: create_booking.php</h1>

<form id="f">
  <label>box_id
    <input type="number" id="box_id" value="1" required>
  </label>
  <div class="row">
    <div>
      <label>start_date (YYYY-MM-DD)
        <input type="date" id="start_date" required>
      </label>
    </div>
    <div>
      <label>end_date (YYYY-MM-DD)
        <input type="date" id="end_date" required>
      </label>
    </div>
  </div>
  <label>customer_name
    <input type="text" id="customer_name" value="Test Kunde" required>
  </label>
  <label>customer_email
    <input type="email" id="customer_email" value="test@example.com" required>
  </label>
  <button type="submit">Buchung testen</button>
</form>

<h3>Antwort</h3>
<pre id="out">–</pre>

<script>
// Standard-Daten setzen: morgen & übermorgen
(function setDefaultDates(){
  const pad = n=> String(n).padStart(2,'0');
  const d = new Date(); d.setHours(12,0,0,0);
  const d1 = new Date(d); d1.setDate(d1.getDate()+1);
  const d2 = new Date(d); d2.setDate(d2.getDate()+2);
  const fmt = x => x.getFullYear()+"-"+pad(x.getMonth()+1)+"-"+pad(x.getDate());
  document.getElementById('start_date').value = fmt(d1);
  document.getElementById('end_date').value   = fmt(d2);
})();

const f = document.getElementById('f'), out = document.getElementById('out');
f.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const body = {
    box_id: Number(document.getElementById('box_id').value),
    start_date: document.getElementById('start_date').value,
    end_date: document.getElementById('end_date').value,
    customer_name: document.getElementById('customer_name').value.trim(),
    customer_email: document.getElementById('customer_email').value.trim()
  };
  out.textContent = "Sende… " + JSON.stringify(body, null, 2);
  try{
    const res = await fetch('/create_booking.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(body),
      credentials:'include'
    });
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); } catch { data = {raw:text}; }
    out.textContent = "HTTP "+res.status+"\n"+JSON.stringify(data, null, 2);
  } catch(err){
    out.textContent = "Fetch/JSON Fehler: "+err.message;
  }
});
</script>
