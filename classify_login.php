<?php
// ================== DATABASE CONNECTION ==================
$host = "localhost";
$user = "root";
$pass = "";
$db   = "classify_db";

$conn = mysqli_connect($host, $user, $pass, $db);
if (!$conn) { die("Database connection failed: " . mysqli_connect_error()); }

session_start();

function log_action($conn, $action) {
    $user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'unknown';
    $user_name = isset($_SESSION['fullname']) ? $_SESSION['fullname'] : 'unknown';
    $sql = "INSERT INTO activity_logs (user_role, user_name, action, timestamp) VALUES (?, ?, ?, NOW())";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("sss", $user_role, $user_name, $action);
        $stmt->execute();
        $stmt->close();
    }
}

/* --------- Login handling --------- */
$login_success = false;
$login_redirect = '';
$login_message  = '';

if (isset($_POST['login'])) {
    $email     = trim($_POST['email']);
    $passInput = (string)$_POST['password'];

    $sql  = "SELECT id, fullname, role, password FROM users WHERE email = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res  = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $stored = (string)$row['password'];
        // Flexible match (bcrypt, md5, plaintext)
        $ok = false;
        $looks_md5 = (bool)preg_match('/^[a-f0-9]{32}$/i', $stored);
        if ($looks_md5) {
            $ok = (md5($passInput) === strtolower($stored));
        } elseif (strpos($stored, '$2y$') === 0) {
            $ok = password_verify($passInput, $stored);
        } else {
            $ok = ($passInput === $stored);
        }

        if ($ok) {
            $_SESSION['user_id']  = (int)$row['id'];
            $_SESSION['fullname'] = $row['fullname'];
            $_SESSION['role']     = $row['role'];

            log_action($conn, "Logged in successfully");
            $login_success  = true;
            $login_redirect = $row['role'] . "_dashboard.php";
            $login_message  = "Welcome, " . $row['fullname'] . "!";
        }
    }

    if (!$login_success) {
        log_action($conn, "Failed login attempt for email: ".$email);
        // Keep a simple, non-blocking message; user remains on page
        echo "<script>setTimeout(()=>alert('Invalid email or password.'), 20);</script>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CLASSIFY | Login</title>
  <style>
    /* ====== GLOBAL ====== */
    :root{
      --accent1:#007BFF;
      --accent2:#00B4DB;
      --ink:#ffffff;
      --muted:rgba(255,255,255,0.85);
      --glass: rgba(255,255,255,0.15);
      --glass-strong: rgba(255,255,255,0.25);
      --stroke: rgba(255,255,255,0.3);
      --shadow: 0 8px 40px rgba(0,0,0,0.3);
      --r: 25px;
    }

    *{margin:0;padding:0;box-sizing:border-box;font-family:"Poppins",system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,sans-serif}

    body{
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      background: linear-gradient(135deg, #0047AB, #00B4DB);
      color: var(--ink);
      overflow:hidden;
    }

    /* ====== BACKGROUND GRAPHICS ====== */
    .background-decor{ position: absolute; inset: 0; overflow:hidden; z-index: 0; }
    .circle{ position:absolute; border-radius:50%; filter: blur(60px); opacity:.4; animation:float 12s ease-in-out infinite alternate;}
    .circle:nth-child(1){ width:300px; height:300px; background:#00B4DB; top:-80px; left:-80px;}
    .circle:nth-child(2){ width:400px; height:400px; background:#0074D9; bottom:-100px; right:-100px;}
    .circle:nth-child(3){ width:200px; height:200px; background:#4FC3F7; top:50%; left:60%;}
    @keyframes float{0%{transform:translateY(0)}100%{transform:translateY(30px)}}

    /* ====== LOGIN CARD ====== */
    .login-container{
      position:relative; z-index:2;
      width: clamp(300px, 92vw, 420px);
      padding: clamp(28px, 5vw, 45px) clamp(22px, 5vw, 35px);
      border-radius: var(--r);
      background: var(--glass);
      backdrop-filter: blur(20px);
      border: 1px solid var(--stroke);
      text-align: center;
      box-shadow: var(--shadow);
      transition: transform .3s ease, box-shadow .3s ease;
      opacity:0; transform: translateY(10px);
    }
    .login-container.show{ opacity:1; transform:none; }
    .login-container:hover{ transform: translateY(-2px) scale(1.01); box-shadow: 0 10px 46px rgba(0,0,0,.35); }

    .login-container h1{
      font-size: clamp(1.8rem, 4vw, 2.3rem);
      letter-spacing: 2px;
      margin-bottom: 5px;
      background: linear-gradient(45deg, #ffffff, #B3E5FC);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    .login-container h3{
      font-size: .95rem;
      font-weight:400;
      color: var(--muted);
      margin-bottom: clamp(16px, 3vw, 25px);
    }

    .input-box{ margin-bottom: 16px; text-align:left; }
    .input-box label{ display:block; margin-bottom:6px; font-size:.9rem; opacity:.9 }
    .input-box input{
      width:100%; padding:12px 14px;
      border:none; outline:none;
      border-radius: 12px;
      background: var(--glass-strong);
      color:#fff; font-size:1rem;
      transition: .25s ease;
      border:1px solid rgba(255,255,255,0.25);
    }
    .input-box input::placeholder{ color: rgba(255,255,255,0.85); }
    .input-box input:focus{ background: rgba(255,255,255,0.35); box-shadow:0 0 0 3px rgba(0,180,219,.25); }

    .login-btn{
      width:100%; padding:12px;
      border:none; border-radius:12px;
      background: linear-gradient(90deg, var(--accent1), var(--accent2));
      color:white; font-size:1.05rem; cursor:pointer; transition:.25s ease;
      font-weight:600; letter-spacing:.3px;
    }
    .login-btn:hover{ transform: translateY(-1px); box-shadow:0 10px 26px rgba(0,0,0,.25); }
    .subtext{ font-size:.8rem; opacity:.7; margin-top:8px; }

    footer{ position: absolute; bottom:12px; font-size: .8rem; opacity:.7; text-align:center; width:100%; }

    /* ====== TOAST (for welcome) ====== */
    .toast{
      position: fixed; top: 16px; left: 50%; transform: translateX(-50%) translateY(-20px);
      background: rgba(0,0,0,.6); border:1px solid rgba(255,255,255,.35);
      color:#fff; padding:10px 14px; border-radius:999px; z-index:50;
      opacity:0; transition: .3s ease; backdrop-filter: blur(8px);
    }
    .toast.show{ opacity:1; transform: translateX(-50%) translateY(0); }

    /* ====== CONFETTI ====== */
    .confetti{
      position: fixed; top:-10px; width:10px; height:14px; z-index: 60;
      opacity: .95; will-change: transform, top, left;
      animation: confettiFall linear forwards, confettiSpin ease-in-out infinite;
    }
    @keyframes confettiFall {
      0%   { transform: translateY(-10px) translateX(0) rotate(0deg); }
      100% { transform: translateY(calc(100vh + 20px)) translateX(var(--drift)) rotate(var(--rot)); }
    }
    @keyframes confettiSpin {
      0%{ transform: rotate(0deg) }
      100%{ transform: rotate(360deg) }
    }

    /* ====== Mobile tweaks ====== */
    @media (max-width:420px){
      .login-container h3{ font-size:.9rem }
      .input-box input{ font-size:.95rem }
    }
    @media (prefers-reduced-motion: reduce){
      .confetti{ animation: none !important }
      .login-container, .login-btn{ transition:none !important }
    }
  </style>
</head>
<body>

  <!-- Background blobs -->
  <div class="background-decor" aria-hidden="true">
    <div class="circle"></div>
    <div class="circle"></div>
    <div class="circle"></div>
  </div>

  <!-- Glassmorphism Login Card -->
  <div class="login-container" id="loginBox" role="dialog" aria-label="Login form">
    <h1>𝐂𝐋𝐀𝐒𝐒𝐈𝐅𝐘</h1>
    <h3>Your All-in-One Solution for Class Cancellations, Grades, Quizzes, and Schedules</h3>

    <form method="POST" novalidate>
      <div class="input-box">
        <label for="email">Email</label>
        <input id="email" type="email" name="email" placeholder="Enter your email" required autocomplete="username">
      </div>
      <div class="input-box">
        <label for="password">Password</label>
        <input id="password" type="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
      </div>
      <button type="submit" name="login" class="login-btn">Login</button>
      <div class="subtext">Tip: your account routes you to the correct dashboard automatically.</div>
    </form>
  </div>

  <footer>© 2025 CLASSIFY — A University‑Class Management System by Jaymar</footer>

  <!-- Success sound (fixed filename) -->
  <audio id="successSound" src="beep.mp3" preload="auto"></audio>

  <!-- Toast -->
  <div id="toast" class="toast" role="status" aria-live="polite"></div>

  <script>
    // Fade-in effect
    window.addEventListener('load', () => {
      const box = document.getElementById('loginBox');
      requestAnimationFrame(() => box.classList.add('show'));
    });

    function showToast(msg){
      const t = document.getElementById('toast');
      t.textContent = msg || 'Success!';
      t.classList.add('show');
      setTimeout(()=> t.classList.remove('show'), 1600);
    }

    function beep(){
      const a = document.getElementById('successSound');
      if (!a) return;
      a.currentTime = 0;
      const p = a.play();
      if (p && p.catch) p.catch(()=>{}); // ignore autoplay blocks
    }

    // Lightweight confetti (CSS + JS, no CDN)
    function launchConfetti(durationMs = 1500, count = 150){
      const colors = ['#ff5252','#ffd166','#06d6a0','#118ab2','#9b5de5','#f15bb5','#00f5d4','#ffe66d'];
      const frag = document.createDocumentFragment();
      const now = Date.now();

      for (let i=0; i<count; i++){
        const piece = document.createElement('div');
        piece.className = 'confetti';
        const left = Math.random() * 100; // vw
        const sizeW = 6 + Math.random()*8;
        const sizeH = 8 + Math.random()*12;
        const color = colors[Math.floor(Math.random()*colors.length)];
        const delay = Math.random() * 0.2;
        const dur   = 1 + Math.random()*1.1;
        const drift = (Math.random()*160 - 80) + 'px';
        const rot   = (360 + Math.random()*720) + 'deg';

        piece.style.left = left + 'vw';
        piece.style.width = sizeW + 'px';
        piece.style.height= sizeH + 'px';
        piece.style.background = color;
        piece.style.opacity = 0.9;
        piece.style.setProperty('--drift', drift);
        piece.style.setProperty('--rot', rot);
        piece.style.animationDelay = delay + 's';
        piece.style.animationDuration = dur + 's';

        // tiny skew to mimic metallic paper
        piece.style.transform = 'rotate(' + (Math.random()*360) + 'deg) skew('+(Math.random()*20-10)+'deg)';
        frag.appendChild(piece);
      }
      document.body.appendChild(frag);

      // Cleanup after animation
      setTimeout(()=>{
        document.querySelectorAll('.confetti').forEach(el=>el.remove());
      }, durationMs + 800);
    }

    // Unified success handler (beep + confetti + toast + redirect)
    function playSuccess(message, redirectUrl){
      try { beep(); } catch(e){}
      launchConfetti(1600, 160);
      showToast(message || 'Welcome!');
      setTimeout(()=>{ window.location.href = redirectUrl; }, 1800);
    }
  </script>

  <?php if ($login_success): ?>
  <script>
    // Trigger success celebration then redirect
    playSuccess("<?= addslashes($login_message) ?>", "<?= addslashes($login_redirect) ?>");
  </script>
  <?php endif; ?>
</body>
</html>
