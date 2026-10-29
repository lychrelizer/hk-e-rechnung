<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>E-Rechnung erstellen (ZUGFeRD)</title>
<style>
:root { --bg:#f5f6f8; --card:#fff; --line:#d9dce1; --text:#1c2128; --muted:#5f6772; --accent:#1f5fbf; --err:#b42318; --errbg:#fdecea; }
* { box-sizing:border-box; }
body { margin:0; font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--text); }
header { background:#fff; border-bottom:1px solid var(--line); padding:14px 24px; display:flex; align-items:center; gap:14px; }
header h1 { font-size:18px; margin:0; }
header small { color:var(--muted); }
main { max-width:1040px; margin:24px auto; padding:0 16px 48px; }
.card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:20px 22px; margin-bottom:18px; }
.card h2 { font-size:16px; margin:0 0 14px; }
.grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px 16px; }
label { display:block; font-size:13px; color:var(--muted); margin-bottom:4px; }
input,select,textarea { width:100%; padding:8px 10px; border:1px solid var(--line); border-radius:6px; font:inherit; background:#fff; }
input:focus,select:focus,textarea:focus { outline:2px solid var(--accent); outline-offset:-1px; border-color:var(--accent); }
.field.error input,.field.error select { border-color:var(--err); }
.field .msg { color:var(--err); font-size:12px; margin-top:3px; }
.wide { grid-column:1 / -1; }
.errors { background:var(--errbg); border:1px solid #f3b8b1; color:var(--err); border-radius:8px; padding:12px 16px; margin-bottom:18px; }
.errors ul { margin:6px 0 0; padding-left:20px; }
table { width:100%; border-collapse:collapse; }
th { text-align:left; font-size:12px; color:var(--muted); font-weight:600; padding:0 6px 6px; }
td { padding:4px 6px; vertical-align:top; }
td input,td select { padding:7px 8px; }
.btn { display:inline-block; padding:10px 18px; border-radius:7px; border:1px solid var(--accent); background:var(--accent); color:#fff; font:inherit; cursor:pointer; }
.btn.secondary { background:#fff; color:var(--accent); }
.btn.small { padding:6px 10px; font-size:13px; }
.actions { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
.hint { color:var(--muted); font-size:13px; }
.totals { text-align:right; font-variant-numeric:tabular-nums; margin-top:10px; }
.totals b { font-size:16px; }
code { background:#eef1f5; padding:1px 5px; border-radius:4px; }
details summary { cursor:pointer; color:var(--accent); }
</style>
</head>
<body>
<header>
  <h1>E-Rechnung erstellen</h1>
  <small>PDF-Rechnung → ZUGFeRD / Factur-X (EN 16931)</small>
</header>
<main>
<?= $content ?>
</main>
</body>
</html>
