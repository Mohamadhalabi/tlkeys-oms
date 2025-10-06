<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TLKeys OMS</title>
    <link rel="icon" href="{{ asset('images/favicon.ico') }}">
    <style>
        :root { 
            --bg:#FAFAFA; 
            --card:#FFFFFF; 
            --text:#333; 
            --muted:#666; 
            --brand:#d97706;      /* orange for Admin */
            --seller:#494D57;     /* custom gray for Seller */
        }
        *{box-sizing:border-box} html,body{height:100%}
        body{margin:0;background:var(--bg);color:var(--text);font:16px/1.4 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Helvetica Neue",Arial}
        .wrap{min-height:100%;display:grid;place-items:center;padding:24px}
        .card{width:100%;max-width:720px;background:var(--card);border-radius:18px;padding:36px;box-shadow:0 10px 40px rgba(0,0,0,.15);text-align:center}
        .brand img{height:80px;width:auto;margin:0 auto 28px auto;display:block}
        .grid{display:grid;grid-template-columns:1fr;gap:14px}
        @media (min-width:640px){ .grid{grid-template-columns:1fr 1fr} }
        .btn{display:flex;align-items:center;justify-content:center;gap:10px;text-decoration:none;
             padding:16px 20px;border-radius:12px;border:2px solid transparent;
             transition:.18s ease;font-weight:600;font-size:16px;color:#fff}
        .btn:hover{transform:translateY(-1px);opacity:0.9}
        .btn--admin{background:var(--brand);border-color:var(--brand)}
        .btn--seller{background:var(--seller);border-color:var(--seller)}
    </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="brand">
      <img src="https://www.tlkeys.com/images/logo/techno-lock-desktop-logo.webp" alt="Techno Lock Keys Trading">
    </div>
    <div class="grid">
      <a class="btn btn--admin" href="{{ url('admin/login') }}">Admin Login</a>
      <a class="btn btn--seller" href="{{ url('seller/login') }}">Seller Login</a>
    </div>
  </div>
</div>
</body>
</html>
