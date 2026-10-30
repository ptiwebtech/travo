<style>
:root{--bg:#0b1220;--card:#131c2e;--line:rgba(255,255,255,.08);--text:#e6edf8;--muted:#9fb0cc;--blue:#3b82f6;--blue-l:#93c5fd;--green:#34d399}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:radial-gradient(900px 500px at 10% -10%,rgba(59,130,246,.28),transparent 60%),var(--bg);color:var(--text);font:16px/1.55 Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
a{color:inherit}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;max-width:760px;margin:0 auto;padding:18px 16px}
.logo img{display:block;height:32px;width:auto}
.wrap{max-width:760px;margin:0 auto;padding:0 16px 24px;display:grid;gap:16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:24px;box-shadow:0 30px 60px -40px rgba(0,0,0,.7)}
h1,h2{font-family:"Space Grotesk",Inter,sans-serif;letter-spacing:-.02em;margin:0}
h1{font-size:clamp(24px,4.4vw,34px);line-height:1.15;color:#fff}
h2{font-size:18px;color:#fff;margin-bottom:10px}
.eyebrow{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;font-size:12.5px;text-transform:uppercase;letter-spacing:.08em;color:#bfdbfe;font-weight:600}
.pill{text-transform:none;letter-spacing:0;font-size:12.5px;padding:4px 10px;border-radius:999px;background:rgba(52,211,153,.14);color:#6ee7b7;border:1px solid rgba(52,211,153,.35)}
.pill-off{background:rgba(248,113,113,.12);color:#fca5a5;border-color:rgba(248,113,113,.35)}
.service{margin:6px 0 0;color:var(--muted)}
.price-row{margin:20px 0 6px}
.price{font:700 clamp(36px,8vw,52px)/1 "Space Grotesk",Inter,sans-serif;color:#fff;letter-spacing:-.02em}
.price-sm{font-size:24px}
.price-note{color:var(--muted);font-size:14px;margin-top:6px}
.notice{margin:12px 0 0;padding:10px 12px;border-radius:12px;background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:#fecaca;font-size:14px}
.route{list-style:none;margin:20px 0 0;padding:0;display:grid;gap:12px}
.route li{display:flex;gap:12px;align-items:center}
.route small{display:block;color:var(--muted);font-size:12.5px}
.route strong{font-weight:600}
.dot{flex:none;width:28px;height:28px;border-radius:50%;display:grid;place-items:center;font:700 12px Inter,sans-serif;color:#0e1626}
.dot-a{background:var(--green)}.dot-b{background:#60a5fa}
.facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin:20px 0 0;padding:16px 0 0;border-top:1px solid var(--line)}
.facts dt{color:var(--muted);font-size:12.5px}
.facts dd{margin:2px 0 0;font-weight:600;word-break:break-word}
.items,.breakdown{margin-top:20px;padding-top:16px;border-top:1px solid var(--line)}
.items ul{margin:0;padding-left:18px;color:#dbe6f7}
table{width:100%;border-collapse:collapse;font-size:15px}
td{padding:9px 0;border-bottom:1px solid var(--line)}
td:last-child{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
tr.total td{font-weight:700;color:#fff;border-bottom:0}
.map{padding:10px}
.map svg{display:block;width:100%;height:auto;border-radius:14px}
.map-note{margin:8px 6px 2px;color:var(--muted);font-size:12.5px}
.share-row,.cta-row,.stores{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;cursor:pointer;text-decoration:none;font:600 15px Inter,sans-serif;color:#fff;background:var(--blue);padding:11px 18px;border-radius:12px}
.btn:hover{filter:brightness(1.08)}
.btn-sm{padding:8px 14px;font-size:14px}
.btn-lg{padding:14px 22px;font-size:16px}
.btn-wa{background:#22c55e}
.btn-ghost{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14)}
.cta{background:radial-gradient(700px 300px at 100% 0%,rgba(59,130,246,.3),transparent 60%),#111a2b}
.eyebrow-l{margin:0 0 8px;text-transform:uppercase;letter-spacing:.08em;font-size:12.5px;font-weight:600;color:#bfdbfe}
.cta h2{font-size:clamp(24px,4.6vw,32px);line-height:1.15;margin-bottom:14px}
.cta h2 span{background:linear-gradient(92deg,#60a5fa,#bfdbfe);-webkit-background-clip:text;background-clip:text;color:transparent}
.ticks{list-style:none;margin:0 0 18px;padding:0;display:grid;gap:8px}
.ticks li{display:flex;gap:10px;align-items:center}
.ticks li:before{content:"";flex:none;width:20px;height:20px;border-radius:6px;background:rgba(52,211,153,.15) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%2334d399'%3E%3Cpath d='M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z'/%3E%3C/svg%3E") center/14px no-repeat}
.link{color:var(--blue-l);font-size:14px}
.stores{margin-top:16px}
.store{display:inline-flex;align-items:center;gap:10px;background:#000;color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:12px;padding:8px 16px 8px 12px;text-decoration:none;min-width:150px}
.store span{display:flex;flex-direction:column;font:600 15px/1.1 "Space Grotesk",Inter,sans-serif}
.store small{font:400 11px Inter,sans-serif;opacity:.85}
.foot{max-width:760px;margin:0 auto;padding:8px 16px 32px;color:#8fa3c4;font-size:13.5px;text-align:center}
.foot a{color:#c7d4e9;text-decoration:none}
.foot p{margin:8px 0 0;font-size:12.5px}
@media (max-width:520px){.card{padding:18px;border-radius:16px}.top .btn-sm{padding:7px 11px;font-size:13px}}
</style>
