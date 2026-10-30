<style>
.pill-wait{background:rgba(251,191,36,.12);color:#fcd34d;border-color:rgba(251,191,36,.35)}
.tk-steps{list-style:none;margin:22px 0 0;padding:0;display:grid;grid-template-columns:repeat(4,minmax(0,1fr))}
.tk-steps li{position:relative;text-align:center;padding:0 4px}
.tk-steps li:before{content:"";position:absolute;top:15px;left:-50%;width:100%;height:3px;background:rgba(148,163,184,.25)}
.tk-steps li:first-child:before{display:none}
.tk-steps li.done:before,.tk-steps li.cur:before{background:var(--green)}
.tk-dot{position:relative;z-index:1;width:32px;height:32px;margin:0 auto;border-radius:50%;display:flex;align-items:center;justify-content:center;font:700 13px Inter,sans-serif;background:#1b2740;border:2px solid rgba(148,163,184,.4);color:#9fb0cc}
.tk-steps li.done .tk-dot{background:#16a34a;border-color:#16a34a;color:#fff}
.tk-steps li.cur .tk-dot{background:var(--blue);border-color:#93c5fd;color:#fff;box-shadow:0 0 0 5px rgba(59,130,246,.22)}
.tk-name{display:block;margin-top:8px;font-size:13px;font-weight:600}
.tk-steps li.todo .tk-name{opacity:.5}
.tk-time{display:block;margin-top:2px;font-size:11.5px;line-height:1.35;color:var(--muted)}
.pay{border-color:rgba(59,130,246,.45);background:linear-gradient(180deg,rgba(59,130,246,.12),rgba(59,130,246,.03)),var(--card)}
.kv{font-size:15px}
.kv td:first-child{color:var(--muted)}
.kv td:last-child{font-weight:600}
.kv tr.total td{font-size:18px;padding-top:12px}
.copy{display:inline-flex;align-items:baseline;gap:8px;font:600 15px Inter,sans-serif;color:#fff;background:rgba(255,255,255,.06);border:1px dashed rgba(147,197,253,.6);border-radius:8px;padding:4px 10px;cursor:pointer}
.copy.big{font-size:18px;letter-spacing:.04em}
.copy small{font-size:11px;font-weight:500;color:var(--blue-l);letter-spacing:0}
.copy.ok small{font-size:0}.copy.ok small:after{content:"copied ✓";font-size:11px}
.hint{margin:14px 0 0;color:var(--muted);font-size:14px}
.hint a{color:var(--blue-l);font-weight:600}
.paid-note{padding:14px 20px;color:#a7f3d0;font-weight:600;display:flex;align-items:center;gap:10px}
.tick{display:inline-flex;width:24px;height:24px;border-radius:50%;align-items:center;justify-content:center;background:rgba(52,211,153,.18);color:var(--green);font-size:14px}
.tk-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
.tk-photo{display:block;text-decoration:none;border-radius:12px;overflow:hidden;background:#0e1626;border:1px solid var(--line)}
.tk-photo img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover}
.tk-photo span{display:block;padding:7px 10px;font-size:12.5px;color:#cbd5e1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tk-docs{list-style:none;margin:12px 0 0;padding:0;display:grid;gap:8px}
.tk-docs a{display:flex;gap:12px;align-items:center;text-decoration:none;padding:10px 12px;border-radius:12px;background:rgba(255,255,255,.04);border:1px solid var(--line)}
.tk-docs a:hover{border-color:rgba(147,197,253,.5)}
.tk-ext{flex:none;width:42px;height:42px;border-radius:9px;display:grid;place-items:center;background:rgba(59,130,246,.18);color:#bfdbfe;font:700 11px Inter,sans-serif}
.tk-docs strong{display:block;font-weight:600;word-break:break-word}
.tk-docs small{display:block;color:var(--muted);font-size:12.5px}
.tk-log{list-style:none;margin:0;padding:0 0 0 18px;border-left:2px solid rgba(148,163,184,.2)}
.tk-log li{position:relative;padding:0 0 16px 14px}
.tk-log li:last-child{padding-bottom:0}
.tk-log li:before{content:"";position:absolute;left:-25px;top:5px;width:12px;height:12px;border-radius:50%;background:#1b2740;border:2px solid rgba(148,163,184,.5)}
.tk-log li.latest:before{background:var(--blue);border-color:#93c5fd;box-shadow:0 0 0 4px rgba(59,130,246,.2)}
.tk-log-head{display:flex;flex-wrap:wrap;justify-content:space-between;gap:2px 12px}
.tk-log-head time{color:var(--muted);font-size:13px}
.tk-log p{margin:3px 0 0;color:#cbd5e1;font-size:14.5px}
.tk-log small{display:block;margin-top:2px;color:var(--muted);font-size:12.5px}
@media (max-width:520px){.tk-name{font-size:11.5px}.tk-time{font-size:10.5px}.tk-dot{width:28px;height:28px}.tk-steps li:before{top:13px}.kv td{display:block;border:0;padding:2px 0;text-align:left!important}.kv tr{display:block;padding:8px 0;border-bottom:1px solid var(--line)}}
</style>
