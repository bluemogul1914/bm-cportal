/* Blue Mogul Dealer Admin — Shared CSS (included inside <style> tag) */
*{margin:0;padding:0;box-sizing:border-box;}
:root{
  --navy:#0a1628;--navy2:#0d1f3c;--navy3:#112347;
  --blue:#1a56a0;--blue-lt:#4a9eff;
  --white:#ffffff;--bg:#f4f6f9;
  --border:#e2e8f0;--border-dark:#1e3a5f;
  --text:#1a202c;--text-m:#4a5568;--text-lt:#718096;
  --green:#15893e;--green-bg:#e6f4ec;--green-text:#14532d;
  --amber:#b45309;--amber-bg:#fef3e2;--amber-text:#7a4f0d;
  --red:#c53030;--red-bg:#fff5f5;--red-text:#742a2a;
  --blue-bg:#e6f1fb;--blue-text:#0c447c;
  --teal:#0d9488;--teal-bg:#e6f7f6;--teal-text:#0f4c48;
  --silver-bg:#f1f5f9;--silver-text:#475569;
  --gold-bg:#fef3c7;--gold-text:#92400e;
  --font:'DM Sans',system-ui,sans-serif;
  --radius:8px;--radius-lg:12px;
}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;display:flex;}

/* ── MAIN AREA ── */
.main{flex:1;display:flex;flex-direction:column;min-height:100vh;min-width:0;}
.topbar{background:var(--white);border-bottom:1px solid var(--border);padding:13px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10;}
.topbar-title{font-size:16px;font-weight:600;color:var(--text);}
.topbar-sub{font-size:12px;color:var(--text-lt);margin-top:1px;}
.topbar-right{display:flex;align-items:center;gap:10px;}
.page-body{padding:24px;flex:1;}

/* ── STAT CARDS ── */
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
.stat-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius-lg);padding:16px 18px;}
.stat-label{font-size:11px;color:var(--text-lt);font-weight:500;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;}
.stat-value{font-size:24px;font-weight:600;color:var(--text);line-height:1;}
.stat-sub{font-size:11px;color:var(--text-lt);margin-top:4px;}

/* ── CARDS ── */
.card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius-lg);padding:18px 20px;margin-bottom:16px;}
.card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
.card-title{font-size:13px;font-weight:600;color:var(--text);}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px;}

/* ── TABLES ── */
table{width:100%;border-collapse:collapse;font-size:12.5px;}
th{text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);padding:8px 12px;border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.04em;}
td{padding:10px 12px;border-bottom:1px solid var(--border);color:var(--text);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#fafbfd;}

/* ── BADGES ── */
.badge{font-size:10px;font-weight:600;padding:3px 9px;border-radius:20px;display:inline-block;}
.badge-green{background:var(--green-bg);color:var(--green-text);}
.badge-amber{background:var(--amber-bg);color:var(--amber-text);}
.badge-blue{background:var(--blue-bg);color:var(--blue-text);}
.badge-red{background:var(--red-bg);color:var(--red-text);}
.badge-gray{background:#f1f5f9;color:#475569;}
.badge-teal{background:var(--teal-bg);color:var(--teal-text);}
.badge-silver{background:var(--silver-bg);color:var(--silver-text);}
.badge-gold{background:var(--gold-bg);color:var(--gold-text);}

/* ── FORMS ── */
.form-group{margin-bottom:14px;}
.form-label{display:block;font-size:12px;font-weight:500;color:var(--text-m);margin-bottom:5px;}
.form-control{width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius);font-family:var(--font);font-size:13px;color:var(--text);background:var(--white);outline:none;transition:border-color .15s;}
.form-control:focus{border-color:var(--blue);}
.form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--radius);font-family:var(--font);font-size:13px;font-weight:500;cursor:pointer;border:none;transition:all .15s;text-decoration:none;}
.btn-primary{background:var(--blue);color:var(--white);}
.btn-primary:hover{background:#14478a;}
.btn-outline{background:transparent;border:1px solid var(--border);color:var(--text-m);}
.btn-outline:hover{border-color:var(--blue);color:var(--blue);}
.btn-sm{padding:5px 12px;font-size:12px;}

/* ── ALERTS ── */
.alert{padding:12px 16px;border-radius:var(--radius);font-size:13px;margin-bottom:16px;border-left:3px solid;}
.alert-info{background:var(--blue-bg);border-color:var(--blue);color:var(--blue-text);}
.alert-success{background:var(--green-bg);border-color:var(--green);color:var(--green-text);}
.alert-warning{background:var(--amber-bg);border-color:var(--amber);color:var(--amber-text);}

/* ── TABS ── */
.tab-nav{display:flex;gap:2px;border-bottom:1px solid var(--border);margin-bottom:20px;}
.tab-btn{padding:8px 16px;font-size:13px;font-weight:500;color:var(--text-lt);background:none;border:none;border-bottom:2px solid transparent;cursor:pointer;font-family:var(--font);transition:color .12s;}
.tab-btn.active{color:var(--blue);border-bottom-color:var(--blue);}
.tab-pane{display:none;} .tab-pane.active{display:block;}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 20px;}
.info-row{padding:8px 0;border-bottom:1px solid var(--border);}
.info-label{font-size:11px;color:var(--text-lt);margin-bottom:2px;}
.info-val{font-size:13px;color:var(--text);font-weight:500;}

@media(max-width:900px){
  .stat-grid{grid-template-columns:1fr 1fr;}
  .two-col{grid-template-columns:1fr;}
}
