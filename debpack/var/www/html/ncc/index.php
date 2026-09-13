<?php
// Load tv_24h setting directly from nems.conf
$tv_24h = 3;
$conf_file = '/usr/local/share/nems/nems.conf';
if (file_exists($conf_file)) {
    $conf_content = file_get_contents($conf_file);
    if (preg_match('/tv_24h\s*=\s*"?([1-3])"?/', $conf_content, $matches)) {
        $tv_24h = (int)$matches[1];
    }
}

// Load Phonetic Dictionary from phonetics.conf
$phonetics_file = __DIR__ . '/phonetics.conf';
$phonetics_map = [];
if (file_exists($phonetics_file)) {
    $lines = file($phonetics_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === '#') continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $phonetics_map[trim($parts[0])] = trim($parts[1]);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>NEMS Central Command</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    :root {
      --bg: #050811;
      --panel-bg: rgba(10, 16, 28, 0.65);
      --border: rgba(0, 240, 255, 0.25);
      --cyan: #00f0ff;
      --green: #00ff88;
      --warn: #ffaa00;
      --crit: #ff0055;
      --unknown: #a855f7;
      --font: 'Segoe UI', Roboto, sans-serif;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    
    html, body {
      width: 100vw;
      height: 100vh;
      overflow: hidden;
    }

    body {
      background-color: var(--bg);
      color: #e0f0ff;
      font-family: var(--font);
      display: flex;
      flex-direction: column;
      transition: cursor 0.2s ease;
    }

    /* BACKGROUND CANVAS FOR SUBTLE ELECTRIFIED GRID */
    #bg-canvas {
      position: fixed;
      top: 0; left: 0;
      width: 100vw; height: 100vh;
      z-index: -10;
      pointer-events: none;
    }

    /* CONNECTION OVERLAY */
    #connection-lost-overlay {
      display: none;
      position: fixed;
      top: 0; left: 0; width: 100vw; height: 100vh;
      background: rgba(5, 8, 17, 0.94);
      z-index: 9999;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      backdrop-filter: blur(8px);
    }
    #connection-lost-overlay.active { display: flex; }
    .lost-title { font-size: 2.2rem; font-weight: bold; color: var(--crit); letter-spacing: 4px; text-shadow: 0 0 20px var(--crit); margin-bottom: 8px; }
    .lost-sub { font-size: 0.85rem; color: #8a9bb0; letter-spacing: 2px; }

    /* CELEBRATION CANVAS */
    #fireworks-canvas {
      position: fixed;
      top: 0; left: 0; width: 100vw; height: 100vh;
      pointer-events: none;
      z-index: 9998;
    }

    header {
      height: 48px;
      flex-shrink: 0;
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0 20px;
      border-bottom: 1px solid var(--border);
      background: linear-gradient(180deg, rgba(0,240,255,0.12) 0%, transparent 100%);
    }
    .title-box h1 { font-size: 1.35rem; letter-spacing: 3px; color: var(--cyan); text-shadow: 0 0 12px var(--cyan); }
    .title-box span { font-size: 0.65rem; color: #8a9bb0; letter-spacing: 1.5px; }

    .grid {
      flex: 1;
      min-height: 0;
      display: grid;
      grid-template-columns: 320px 1fr 420px;
      grid-template-rows: 100%;
      gap: 10px;
      padding: 10px;
      overflow: hidden;
    }

    .panel {
      background: var(--panel-bg);
      border: 1px solid var(--border);
      border-radius: 4px;
      padding: 12px;
      display: flex;
      flex-direction: column;
      clip-path: polygon(0 0, calc(100% - 12px) 0, 100% 12px, 100% 100%, 12px 100%, 0 calc(100% - 12px));
      box-shadow: inset 0 0 15px rgba(0,240,255,0.03);
      min-height: 0;
      backdrop-filter: blur(4px);
    }

    .left-module { margin-bottom: 10px; flex-shrink: 0; }
    .left-module.fill-module {
      margin-bottom: 0;
      flex: 1;
      display: flex;
      flex-direction: column;
      min-height: 0;
    }

    .panel h2 {
      font-size: 0.78rem;
      letter-spacing: 2px;
      color: var(--cyan);
      text-transform: uppercase;
      margin-bottom: 6px;
      border-bottom: 1px dashed var(--border);
      padding-bottom: 4px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .center-layout {
      display: grid;
      grid-template-rows: auto 1fr 165px;
      gap: 10px;
      height: 100%;
      min-height: 0;
    }

    .hud-gauges {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 10px;
      background: rgba(0, 240, 255, 0.03);
      border: 1px solid var(--border);
      padding: 8px;
      border-radius: 4px;
      backdrop-filter: blur(4px);
    }
    .gauge-box { text-align: center; }
    .gauge-val { font-size: 1.6rem; font-weight: bold; color: var(--cyan); text-shadow: 0 0 10px var(--cyan); }
    .gauge-lbl { font-size: 0.62rem; color: #8a9bb0; letter-spacing: 1px; margin-top: 2px; }

    .matrix-container {
      background: rgba(0, 0, 0, 0.35);
      border: 1px solid var(--border);
      padding: 12px;
      overflow: hidden;
      border-radius: 4px;
      display: flex;
      flex-direction: column;
      min-height: 0;
      position: relative;
      backdrop-filter: blur(4px);
    }

    #page-indicator {
      position: absolute;
      top: 12px;
      right: 14px;
      color: var(--cyan);
      font-size: 0.72rem;
      font-weight: bold;
      letter-spacing: 1px;
      background: rgba(0, 240, 255, 0.1);
      padding: 2px 8px;
      border-radius: 3px;
      border: 1px solid var(--border);
    }

    .node-grid-wrapper {
      flex: 1;
      min-height: 0;
      overflow: hidden;
      position: relative;
      margin-top: 4px;
    }

    .node-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 10px;
      height: 100%;
      align-content: start;
      transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.35s ease;
    }

    .node-grid.sliding-out {
      transform: translateX(-30px);
      opacity: 0;
    }

    .node-card {
      background: rgba(15, 23, 42, 0.60);
      border: 1px solid var(--border);
      padding: 10px 12px;
      border-radius: 6px;
      min-height: 100px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: border-color 0.3s ease, background 0.3s ease;
      box-shadow: 0 4px 14px rgba(0,0,0,0.35);
      backdrop-filter: blur(4px);
    }
    .node-card.ok { border-color: rgba(0,255,136,0.4); box-shadow: inset 0 0 10px rgba(0,255,136,0.05); }
    .node-card.warn { border-color: var(--warn); background: rgba(255,170,0,0.15); }
    .node-card.crit { border-color: var(--crit); background: rgba(255,0,85,0.20); box-shadow: 0 0 15px rgba(255,0,85,0.3); }
    .node-card.unk { border-color: var(--unknown); background: rgba(168,85,247,0.15); }

    .node-header { margin-bottom: 6px; }
    .node-name {
      font-size: 0.88rem;
      font-weight: 700;
      color: #ffffff;
      line-height: 1.3;
      margin-bottom: 4px;
      word-break: break-word;
    }
    .node-status-badge {
      display: inline-block;
      font-size: 0.62rem;
      font-weight: bold;
      padding: 2px 6px;
      border-radius: 3px;
      letter-spacing: 0.5px;
      text-transform: uppercase;
    }
    .node-card.ok .node-status-badge { background: rgba(0,255,136,0.15); color: var(--green); border: 1px solid rgba(0,255,136,0.3); }
    .node-card.warn .node-status-badge { background: rgba(255,170,0,0.2); color: var(--warn); border: 1px solid rgba(255,170,0,0.4); }
    .node-card.crit .node-status-badge { background: rgba(255,0,85,0.25); color: var(--crit); border: 1px solid rgba(255,0,85,0.5); }
    .node-card.unk .node-status-badge { background: rgba(168,85,247,0.2); color: var(--unknown); border: 1px solid rgba(168,85,247,0.4); }

    .node-card-bottom {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 0.68rem;
      color: #8a9bb0;
      border-top: 1px dashed rgba(0, 240, 255, 0.15);
      padding-top: 4px;
      margin-top: 4px;
    }

    .chart-box {
      background: rgba(0, 0, 0, 0.30);
      border: 1px solid var(--border);
      padding: 8px 10px 4px 10px;
      border-radius: 4px;
      display: flex;
      flex-direction: column;
      height: 100%;
      min-height: 0;
      backdrop-filter: blur(4px);
    }

    .stat-row { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
    .card { background: rgba(255,255,255,0.03); border-left: 3px solid var(--cyan); padding: 6px; }
    .card.ok { border-color: var(--green); }
    .card.warn { border-color: var(--warn); }
    .card.crit { border-color: var(--crit); }
    .card .num { font-size: 1.2rem; font-weight: bold; }
    .card .label { font-size: 0.6rem; color: #8a9bb0; letter-spacing: 1px; }

    .chat-container {
      flex: 1;
      background: rgba(0, 0, 0, 0.30);
      border: 1px solid var(--border);
      border-radius: 4px;
      padding: 8px;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 6px;
      min-height: 0;
    }
    .chat-item {
      background: rgba(0, 240, 255, 0.05);
      border-left: 3px solid var(--cyan);
      padding: 6px 8px;
      border-radius: 3px;
      font-size: 0.72rem;
      line-height: 1.35;
      animation: fadeIn 0.3s ease-in;
    }
    .chat-item.ai { border-left-color: var(--green); background: rgba(0, 255, 136, 0.05); }
    .chat-item.alert, .chat-item.crit { border-left-color: var(--crit); background: rgba(255, 0, 85, 0.08); }
    .chat-item.warn { border-left-color: var(--warn); background: rgba(255, 170, 0, 0.08); }
    .chat-item.unk { border-left-color: var(--unknown); background: rgba(168, 85, 247, 0.08); }
    .chat-item.ok { border-left-color: var(--cyan); background: rgba(0, 240, 255, 0.05); }

    .chat-meta {
      display: flex;
      justify-content: space-between;
      font-size: 0.6rem;
      color: #8a9bb0;
      margin-bottom: 2px;
      font-weight: bold;
    }
    .chat-text { color: #d0e8ff; word-break: break-word; }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-4px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* TELEMETRY METERS */
    .perf-widget {
      background: rgba(0,240,255,0.03);
      border: 1px solid var(--border);
      padding: 6px;
      margin-top: 4px;
      border-radius: 3px;
    }
    .perf-title { font-size: 0.6rem; color: #8a9bb0; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 4px; }

    .meter-bar-track {
      height: 8px;
      background: rgba(255,255,255,0.08);
      border-radius: 4px;
      overflow: hidden;
      margin-top: 3px;
    }
    .meter-bar-fill {
      height: 100%;
      background: linear-gradient(90deg, var(--cyan), var(--green));
      border-radius: 4px;
      transition: width 0.5s ease;
    }

    .dual-meter-row {
      display: flex;
      gap: 8px;
      font-size: 0.7rem;
      margin-top: 2px;
    }
    .meter-sub-box { flex: 1; }

    .incidents { flex: 1; overflow-y: auto; min-height: 0; }
    .incident-item {
      background: rgba(255,0,85,0.08);
      border: 1px solid rgba(255,0,85,0.3);
      padding: 8px 10px;
      margin-bottom: 6px;
      border-radius: 4px;
    }
    .incident-item.warn { background: rgba(255,170,0,0.08); border-color: rgba(255,170,0,0.3); }
    .incident-item.unk { background: rgba(168,85,247,0.08); border-color: rgba(168,85,247,0.3); }
    
    .incident-top-line {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px;
      margin-bottom: 4px;
    }
    .incident-check {
      font-size: 0.82rem;
      font-weight: 700;
      color: #ffffff;
      line-height: 1.3;
      word-break: break-word;
    }
    .incident-timer {
      font-size: 0.62rem;
      color: #ff88a5;
      background: rgba(255,0,85,0.25);
      padding: 2px 5px;
      border-radius: 3px;
      white-space: nowrap;
      font-weight: bold;
    }
    .incident-item.warn .incident-timer { color: #ffe088; background: rgba(255,170,0,0.25); }
    .incident-item.unk .incident-timer { color: #d8b4fe; background: rgba(168,85,247,0.25); }

    .incident-host {
      font-size: 0.75rem;
      font-weight: 600;
      color: var(--cyan);
      margin-bottom: 4px;
      word-break: break-word;
    }

    .incident-msg {
      font-size: 0.7rem;
      color: #b0c4de;
      line-height: 1.35;
      word-break: break-word;
    }
  </style>
</head>
<body>

  <!-- BACKGROUND CANVAS FOR SUBTLE ELECTRIFIED GRID -->
  <canvas id="bg-canvas"></canvas>

  <!-- CONNECTION OVERLAY -->
  <div id="connection-lost-overlay">
    <div class="lost-title">⚡ LOST CONNECTION</div>
    <div class="lost-sub">RECONNECTING TO NEMS SERVER...</div>
  </div>

  <!-- CELEBRATION FIREWORKS CANVAS -->
  <canvas id="fireworks-canvas"></canvas>

  <header>
    <div class="title-box">
      <h1>NEMS CENTRAL COMMAND</h1>
      <span>REAL-TIME ENTERPRISE INFRASTRUCTURE HEALTH</span>
    </div>
    <div id="clock" style="font-size: 1rem; letter-spacing: 2px; color: var(--cyan);">--:--:--</div>
  </header>

  <div class="grid">
    <!-- Left Panel -->
    <div class="panel">
      <div class="left-module">
        <h2>Host Summary</h2>
        <div class="stat-row">
          <div class="card ok"><div class="num" id="h-up">0</div><div class="label">HOSTS UP</div></div>
          <div class="card crit"><div class="num" id="h-down">0</div><div class="label">HOSTS DOWN</div></div>
        </div>
      </div>

      <div class="left-module">
        <h2>Service Summary</h2>
        <div class="stat-row">
          <div class="card ok"><div class="num" id="s-ok">0</div><div class="label">SERVICES OK</div></div>
          <div class="card warn"><div class="num" id="s-warn">0</div><div class="label">WARNING</div></div>
          <div class="card crit"><div class="num" id="s-crit">0</div><div class="label">CRITICAL</div></div>
          <div class="card"><div class="num" id="s-unknown">0</div><div class="label">UNKNOWN</div></div>
        </div>
      </div>

      <div class="left-module">
        <h2>Smart Telemetry</h2>
        <div id="perf-widgets"></div>
      </div>

      <div class="left-module fill-module">
        <h2>System Notifications</h2>
        <div class="chat-container" id="chat-log">
          <div style="font-size:0.7rem; color:#8a9bb0; text-align:center; padding:10px;">Initializing Comm Feed...</div>
        </div>
      </div>
    </div>

    <!-- Center Stage -->
    <div class="center-layout">
      <div class="hud-gauges">
        <div class="gauge-box">
          <div class="gauge-val" id="sla-val">100%</div>
          <div class="gauge-lbl">OVERALL HEALTH</div>
        </div>
        <div class="gauge-box">
          <div class="gauge-val" id="host-health" style="color: var(--green);">100%</div>
          <div class="gauge-lbl">HOST HEALTH</div>
        </div>
        <div class="gauge-box">
          <div class="gauge-val" id="svc-health" style="color: var(--warn);">100%</div>
          <div class="gauge-lbl">SERVICE HEALTH</div>
        </div>
      </div>

      <div class="matrix-container">
        <h2><span>MONITORED HOST NODES</span></h2>
        <span id="page-indicator">PAGE 1/1</span>
        <div class="node-grid-wrapper" id="node-grid-wrapper">
          <div class="node-grid" id="node-grid"></div>
        </div>
      </div>

      <div class="chart-box">
        <h2 style="font-size:0.7rem; color:var(--cyan); margin-bottom:2px;">INFRASTRUCTURE HEALTH TIMELINE</h2>
        <div style="flex:1; position:relative; min-height:0; width:100%;">
          <canvas id="slaChart"></canvas>
        </div>
      </div>
    </div>

    <!-- Right Panel -->
    <div class="panel">
      <h2>Active Tactical Incidents <span id="inc-count" style="color:var(--crit);">0</span></h2>
      <div class="incidents" id="incident-list"></div>
    </div>
  </div>

  <script>
    // --- SUBTLE ELECTRIFIED GRID BACKGROUND ENGINE ---
    const bgCanvas = document.getElementById('bg-canvas');
    const bgCtx = bgCanvas.getContext('2d');
    let sparks = [];
    const GRID_SIZE = 45;

    let currentGridLineColor = 'rgba(0, 240, 255, 0.09)';
    let currentSparkRgb = '0, 240, 255';

    function resizeBgCanvas() {
      bgCanvas.width = window.innerWidth;
      bgCanvas.height = window.innerHeight;
    }
    window.addEventListener('resize', resizeBgCanvas);
    resizeBgCanvas();

    function updateElectrifiedTheme(state) {
      if (state === 'crit') {
        currentGridLineColor = 'rgba(255, 0, 85, 0.12)';
        currentSparkRgb = '255, 0, 85';
      } else if (state === 'warn') {
        currentGridLineColor = 'rgba(255, 170, 0, 0.12)';
        currentSparkRgb = '255, 170, 0';
      } else {
        currentGridLineColor = 'rgba(0, 240, 255, 0.09)';
        currentSparkRgb = '0, 240, 255';
      }
    }

    function spawnElectricSpark() {
      const isHorizontal = Math.random() > 0.5;
      if (isHorizontal) {
        const row = Math.floor(Math.random() * (bgCanvas.height / GRID_SIZE));
        sparks.push({
          x: -40,
          y: row * GRID_SIZE,
          length: 30 + Math.random() * 20,
          speed: 5 + Math.random() * 4,
          dir: 'h'
        });
      } else {
        const col = Math.floor(Math.random() * (bgCanvas.width / GRID_SIZE));
        sparks.push({
          x: col * GRID_SIZE,
          y: -40,
          length: 30 + Math.random() * 20,
          speed: 5 + Math.random() * 4,
          dir: 'v'
        });
      }
    }

    function animateElectrifiedGrid() {
      bgCtx.clearRect(0, 0, bgCanvas.width, bgCanvas.height);

      // 1. Static Clean Grid Lines
      bgCtx.strokeStyle = currentGridLineColor;
      bgCtx.lineWidth = 1;

      for (let x = 0; x <= bgCanvas.width; x += GRID_SIZE) {
        bgCtx.beginPath();
        bgCtx.moveTo(x, 0);
        bgCtx.lineTo(x, bgCanvas.height);
        bgCtx.stroke();
      }

      for (let y = 0; y <= bgCanvas.height; y += GRID_SIZE) {
        bgCtx.beginPath();
        bgCtx.moveTo(0, y);
        bgCtx.lineTo(bgCanvas.width, y);
        bgCtx.stroke();
      }

      // 2. Spawn Short Subtle Energy Pulses (Max 3 Active Sparks)
      if (Math.random() < 0.03 && sparks.length < 3) {
        spawnElectricSpark();
      }

      // 3. Render Micro-Pulse Gradient Sparks
      sparks.forEach((s, idx) => {
        bgCtx.lineWidth = 1.5;
        bgCtx.shadowColor = `rgba(${currentSparkRgb}, 0.8)`;
        bgCtx.shadowBlur = 6;

        let grad;
        if (s.dir === 'h') {
          grad = bgCtx.createLinearGradient(s.x, s.y, s.x + s.length, s.y);
          grad.addColorStop(0, `rgba(${currentSparkRgb}, 0)`);
          grad.addColorStop(1, `rgba(${currentSparkRgb}, 0.75)`);
          bgCtx.strokeStyle = grad;

          bgCtx.beginPath();
          bgCtx.moveTo(s.x, s.y);
          bgCtx.lineTo(s.x + s.length, s.y);
          bgCtx.stroke();
          s.x += s.speed;
        } else {
          grad = bgCtx.createLinearGradient(s.x, s.y, s.x, s.y + s.length);
          grad.addColorStop(0, `rgba(${currentSparkRgb}, 0)`);
          grad.addColorStop(1, `rgba(${currentSparkRgb}, 0.75)`);
          bgCtx.strokeStyle = grad;

          bgCtx.beginPath();
          bgCtx.moveTo(s.x, s.y);
          bgCtx.lineTo(s.x, s.y + s.length);
          bgCtx.stroke();
          s.y += s.speed;
        }

        if (s.x > bgCanvas.width + 50 || s.y > bgCanvas.height + 50) {
          sparks.splice(idx, 1);
        }
      });

      bgCtx.shadowBlur = 0;
      requestAnimationFrame(animateElectrifiedGrid);
    }
    animateElectrifiedGrid();

    // --- TV_24H SYSTEM-WIDE TIME FORMATTER ---
    const tv24hSetting = <?php echo $tv_24h; ?>;

    function getFormattedTime(date = new Date(), includeSeconds = true) {
      let hours = date.getHours();
      const minutes = String(date.getMinutes()).padStart(2, '0');
      const seconds = String(date.getSeconds()).padStart(2, '0');
      const secStr = includeSeconds ? `:${seconds}` : '';

      if (tv24hSetting === 1) {
        return `${String(hours).padStart(2, '0')}:${minutes}${secStr}`;
      } else if (tv24hSetting === 2) {
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12 || 12;
        return `${hours}:${minutes}${secStr} ${ampm}`;
      } else {
        hours = hours % 12 || 12;
        return `${hours}:${minutes}${secStr}`;
      }
    }

    function updateClock() {
      const clockEl = document.getElementById('clock');
      if (clockEl) clockEl.innerText = getFormattedTime(new Date(), true);
    }

    updateClock();
    setInterval(updateClock, 1000);

    // --- MOUSE CURSOR IDLE HIDE ---
    let cursorTimer;
    function resetCursorTimer() {
      document.body.style.cursor = 'default';
      clearTimeout(cursorTimer);
      cursorTimer = setTimeout(() => {
        document.body.style.cursor = 'none';
      }, 5000);
    }
    window.addEventListener('mousemove', resetCursorTimer);
    resetCursorTimer();

    // --- DYNAMIC PHONETIC DICTIONARY ENGINE ---
    const phoneticsMap = <?php echo json_encode($phonetics_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    function sanitizePhonetics(phrase) {
      if (!phrase) return '';
      let text = phrase;

      // Convert raw IPv4 addresses for TTS ONLY (e.g., 10.0.0.10 -> 10 dot 0 dot 0 dot 10)
      text = text.replace(/\b(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\b/g, '$1 dot $2 dot $3 dot $4');

      // Apply dynamic dictionary rules from phonetics.conf
      for (const [key, val] of Object.entries(phoneticsMap)) {
        const escapedKey = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const isPureWord = /^\w+$/.test(key);
        const pattern = isPureWord
          ? new RegExp(`\\b${escapedKey}\\b`, 'gi')
          : new RegExp(escapedKey, 'gi');

        text = text.replace(pattern, val);
      }

      // Cleanup formatting, slashes, and excess whitespace
      return text
        .replace(/([a-zA-Z0-9]+)\s*\/\s*([a-zA-Z0-9]+)/g, '$1 and $2')
        .replace(/[*_#`"'\r\n]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    }

    function getStateClass(code) {
      if (code === 1) return 'warn';
      if (code === 2) return 'crit';
      if (code === 3) return 'unk';
      return 'ok';
    }

    function appendChatMessage(sender, text, isAi = false, stateType = '') {
      const log = document.getElementById('chat-log');
      if (!log) return;

      if (log.children.length === 1 && log.children[0].innerText.includes('Initializing')) {
        log.innerHTML = '';
      }

      let stateClass = '';
      if (typeof stateType === 'string') {
        stateClass = stateType;
      } else if (stateType === true) {
        stateClass = 'crit';
      }

      const timeStr = getFormattedTime(new Date(), true);
      const msgDiv = document.createElement('div');
      msgDiv.className = `chat-item ${isAi ? 'ai' : ''} ${stateClass}`;
      msgDiv.innerHTML = `
        <div class="chat-meta">
          <span>${sender}</span>
          <span>${timeStr}</span>
        </div>
        <div class="chat-text">${text}</div>
      `;

      log.insertBefore(msgDiv, log.firstChild);
      log.scrollTop = 0;

      while (log.children.length > 20) {
        log.removeChild(log.lastChild);
      }
    }

    // --- SPEECH QUEUE ENGINE ---
    window.speechQueue = [];
    window.isSpeaking = false;
    window.currentUtterance = null;

    if ('speechSynthesis' in window) {
      window.speechSynthesis.getVoices();
      window.speechSynthesis.onvoiceschanged = () => {
        if ('speechSynthesis' in window) window.speechSynthesis.getVoices();
      };
    }

    function enqueueSpeech(displayPhrase, speechPhrase = null, isAi = false, stateType = '', sender = '[NEMS COMMAND]') {
      const textToSpeak = sanitizePhonetics(speechPhrase || displayPhrase);
      appendChatMessage(sender, displayPhrase, isAi, stateType);

      if (!textToSpeak) return;
      window.speechQueue.push(textToSpeak);
      processSpeechQueue();
    }

    function processSpeechQueue() {
      if (window.isSpeaking || window.speechQueue.length === 0) return;
      if (!('speechSynthesis' in window)) return;

      window.isSpeaking = true;
      const phrase = window.speechQueue.shift();

      try {
        window.speechSynthesis.cancel();
        if (window.speechSynthesis.paused) window.speechSynthesis.resume();

        window.currentUtterance = new SpeechSynthesisUtterance(phrase);
        window.currentUtterance.rate = 0.95;
        window.currentUtterance.pitch = 1.0;

        const voices = window.speechSynthesis.getVoices();
        if (voices && voices.length > 0) {
          const usVoice = voices.find(v => v.lang && (v.lang.includes('en-US') || v.lang.includes('en')));
          if (usVoice) window.currentUtterance.voice = usVoice;
        }

        window.currentUtterance.onend = function() {
          window.currentUtterance = null;
          window.isSpeaking = false;
          setTimeout(processSpeechQueue, 250);
        };

        window.currentUtterance.onerror = function(e) {
          window.currentUtterance = null;
          window.isSpeaking = false;
          setTimeout(processSpeechQueue, 250);
        };

        window.speechSynthesis.speak(window.currentUtterance);

      } catch(e) {
        window.currentUtterance = null;
        window.isSpeaking = false;
        setTimeout(processSpeechQueue, 250);
      }
    }

    async function dispatchSpeechEvent(eventType, baselineText, checkData = {}) {
      let speechText = baselineText;
      let displayText = baselineText;
      let isAiEngine = false;
      const stateClass = eventType === 'recovery' ? 'ok' : getStateClass(checkData.state);
      const senderTag = eventType === 'incident' ? '[ALERT TRANSMISSION]' : '[RECOVERY TRANSMISSION]';

      try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 45000);

        const res = await fetch('/nems-api/nems-ai', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          signal: controller.signal,
          body: JSON.stringify({
            event_type: eventType,
            baseline_text: baselineText,
            timestamp: Math.floor(Date.now() / 1000),
            check_data: checkData
          })
        }).then(r => r.json());

        clearTimeout(timeoutId);

        if (res && res.success && res.ai_active && res.speech_text) {
          speechText = res.speech_text;
          displayText = res.display_text || res.speech_text;
          isAiEngine = true;
        }
      } catch (e) {}

      enqueueSpeech(displayText, speechText, isAiEngine, stateClass, senderTag);
    }

    async function dispatchBatchIncidents(newIncidents) {
      if (!newIncidents || newIncidents.length === 0) return;

      let primaryStateClass = 'unk';
      if (newIncidents.some(i => i.stateCode === 2)) primaryStateClass = 'crit';
      else if (newIncidents.some(i => i.stateCode === 1)) primaryStateClass = 'warn';

      if (newIncidents.length === 1) {
        const inc = newIncidents[0];
        const hostAlias = inc.alias || inc.host;
        const baselineText = inc.checkName === 'HOST DOWN'
          ? `Server ${hostAlias} is offline.`
          : `Service ${inc.checkName} on ${hostAlias} is reporting ${inc.stateText}.`;

        dispatchSpeechEvent('incident', baselineText, {
          host_name: inc.host,
          host_alias: hostAlias,
          service_description: inc.checkName,
          state: inc.stateCode,
          plugin_output: inc.msg
        });
        return;
      }

      const baselineText = `${newIncidents.length} tactical incidents detected across monitored nodes.`;

      try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 45000);

        const res = await fetch('/nems-api/nems-ai', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          signal: controller.signal,
          body: JSON.stringify({
            event_type: 'batch_incidents',
            baseline_text: baselineText,
            incidents: newIncidents
          })
        }).then(r => r.json());

        clearTimeout(timeoutId);

        if (res && res.success && res.ai_active && res.speech_text) {
          enqueueSpeech(res.display_text, res.speech_text, true, primaryStateClass, '[ALERT TRANSMISSION]');
          return;
        }
      } catch (e) {}

      newIncidents.forEach(inc => {
        const hostAlias = inc.alias || inc.host;
        const fallbackText = inc.checkName === 'HOST DOWN'
          ? `Server ${hostAlias} is offline.`
          : `Service ${inc.checkName} on ${hostAlias} is reporting ${inc.stateText}.`;
        enqueueSpeech(fallbackText, fallbackText, false, getStateClass(inc.stateCode), '[ALERT TRANSMISSION]');
      });
    }

    async function dispatchBatchRecoveries(newRecoveries) {
      if (!newRecoveries || newRecoveries.length === 0) return;

      if (newRecoveries.length === 1) {
        const item = newRecoveries[0];
        const hostAlias = item.alias || item.host;
        const baselineText = item.checkName === 'HOST DOWN'
          ? `Server ${hostAlias} is back online.`
          : (item.msg 
              ? `Service ${item.checkName} on ${hostAlias} has recovered: ${item.msg}`
              : `Service ${item.checkName} on ${hostAlias} has returned to normal operational status.`);

        dispatchSpeechEvent('recovery', baselineText, {
          host_name: item.host,
          host_alias: item.hostAlias,
          service_description: item.checkName,
          state: 0,
          plugin_output: item.msg
        });
        return;
      }

      const baselineText = `${newRecoveries.length} services have returned to normal status.`;

      try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 45000);

        const res = await fetch('/nems-api/nems-ai', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          signal: controller.signal,
          body: JSON.stringify({
            event_type: 'batch_recoveries',
            baseline_text: baselineText,
            recoveries: newRecoveries
          })
        }).then(r => r.json());

        clearTimeout(timeoutId);

        if (res && res.success && res.ai_active && res.speech_text) {
          enqueueSpeech(res.display_text, res.speech_text, true, 'ok', '[RECOVERY TRANSMISSION]');
          return;
        }
      } catch (e) {}

      newRecoveries.forEach(item => {
        const hostAlias = item.alias || item.host;
        const fallbackText = item.checkName === 'HOST DOWN'
          ? `Server ${hostAlias} is back online.`
          : `Service ${item.checkName} on ${hostAlias} has returned to normal.`;
        enqueueSpeech(fallbackText, fallbackText, false, 'ok', '[RECOVERY TRANSMISSION]');
      });
    }

    function announceWelcomeOverview(hosts, services, incidents, overallSla) {
      const hostCount = hosts.length;
      const hostStr = hostCount === 1 ? '1 host' : `${hostCount} hosts`;
      let baselineText = '';

      if (incidents.length === 0) {
        const verb = hostCount === 1 ? 'is' : 'are';
        baselineText = `NEMS Central Command is online. All ${hostStr} ${verb} operational with overall health at ${overallSla} percent.`;
      } else {
        const incCount = incidents.length;
        const verb = incCount === 1 ? 'is' : 'are';
        const incStr = incCount === 1 ? '1 active incident' : `${incCount} active incidents`;
        baselineText = `NEMS Central Command is online. Monitoring ${hostStr} with overall health at ${overallSla} percent. There ${verb} ${incStr} requiring attention.`;
      }

      enqueueSpeech(baselineText, baselineText, false, 'ok', '[SYSTEM INIT]');
    }

    const spokenIncidents = new Map();
    const previousStateMap = new Map();
    let hasAnnouncedOnline = false;
    let trackedHostMap = null;
    let previousOverallHealth = null;

    function formatElapsed(epochSec) {
      if (!epochSec || epochSec <= 0) return 'JUST NOW';
      const diff = Math.floor((Date.now() / 1000) - epochSec);
      if (diff < 60) return `${diff}s AGO`;
      if (diff < 3600) return `${Math.floor(diff/60)}m ${diff%60}s AGO`;
      if (diff < 86400) return `${Math.floor(diff/3600)}h ${Math.floor((diff%3600)/60)}h AGO`;
      return `${Math.floor(diff/86400)}d ${Math.floor((diff%86400)/3600)}h AGO`;
    }

    // --- CELEBRATION FIREWORKS ENGINE ---
    function launchFireworks() {
      const canvas = document.getElementById('fireworks-canvas');
      if (!canvas) return;
      const ctx = canvas.getContext('2d');
      canvas.width = window.innerWidth;
      canvas.height = window.innerHeight;

      const particles = [];
      const colors = ['#00f0ff', '#00ff88', '#ffaa00', '#ffffff', '#a855f7'];

      for (let i = 0; i < 120; i++) {
        particles.push({
          x: canvas.width / 2,
          y: canvas.height / 2,
          vx: (Math.random() - 0.5) * 14,
          vy: (Math.random() - 0.5) * 14 - 2,
          size: Math.random() * 3 + 2,
          color: colors[Math.floor(Math.random() * colors.length)],
          alpha: 1,
          decay: Math.random() * 0.02 + 0.008
        });
      }

      function render() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        let active = false;

        particles.forEach(p => {
          if (p.alpha > 0) {
            active = true;
            p.x += p.vx;
            p.y += p.vy;
            p.vy += 0.1;
            p.alpha -= p.decay;

            ctx.globalAlpha = Math.max(0, p.alpha);
            ctx.fillStyle = p.color;
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
            ctx.fill();
          }
        });

        if (active) requestAnimationFrame(render);
        else ctx.clearRect(0, 0, canvas.width, canvas.height);
      }
      render();
    }

    // --- INFRASTRUCTURE HEALTH TIMELINE CHART ---
    const SLA_STORAGE_KEY = 'nems_noc_sla_timestamps_24h';
    const MAX_SLA_POINTS = 288;
    const FIVE_MIN_MS = 5 * 60 * 1000;

    function loadSlaHistory() {
      const stored = localStorage.getItem(SLA_STORAGE_KEY);
      const now = Date.now();
      const currentBucket = Math.floor(now / FIVE_MIN_MS) * FIVE_MIN_MS;

      if (stored) {
        try {
          const parsed = JSON.parse(stored);
          if (parsed && Array.isArray(parsed.timestamps) && Array.isArray(parsed.data) && parsed.data.length > 0) {
            return parsed;
          }
        } catch(e) {}
      }
      
      return { timestamps: [currentBucket], data: [], lastBucket: currentBucket };
    }

    function updateSlaHistory(slaVal) {
      const hist = loadSlaHistory();
      const numericVal = Math.round(parseFloat(slaVal));
      const now = Date.now();
      const currentBucket = Math.floor(now / FIVE_MIN_MS) * FIVE_MIN_MS;

      if (hist.data.length === 0) {
        hist.data.push(numericVal);
        hist.lastBucket = currentBucket;
      } else {
        const bucketDiff = Math.floor((currentBucket - hist.lastBucket) / FIVE_MIN_MS);

        if (bucketDiff > 0) {
          for (let i = 1; i <= bucketDiff; i++) {
            const nextBucket = hist.lastBucket + (i * FIVE_MIN_MS);
            hist.timestamps.push(nextBucket);
            hist.data.push(numericVal);

            if (hist.data.length > MAX_SLA_POINTS) {
              hist.timestamps.shift();
              hist.data.shift();
            }
          }
          hist.lastBucket = currentBucket;
        } else {
          hist.data[hist.data.length - 1] = numericVal;
        }
      }

      localStorage.setItem(SLA_STORAGE_KEY, JSON.stringify(hist));
      const labels = hist.timestamps.map(ts => getFormattedTime(new Date(ts), false));
      return { labels, data: hist.data };
    }

    const initialHist = updateSlaHistory(100);
    const ctx = document.getElementById('slaChart').getContext('2d');
    const slaChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: initialHist.labels,
        datasets: [{
          label: 'Health %',
          data: initialHist.data,
          borderColor: '#00f0ff',
          backgroundColor: 'rgba(0, 240, 255, 0.12)',
          borderWidth: 2,
          fill: true,
          tension: 0.2,
          spanGaps: true,
          pointRadius: initialHist.data.length === 1 ? 3 : 0,
          pointHoverRadius: 3
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: { bottom: 2, top: 2 } },
        plugins: { legend: { display: false } },
        scales: {
          y: {
            min: 0, max: 100,
            grid: { color: 'rgba(0, 240, 255, 0.08)' },
            ticks: { color: '#8a9bb0', font: { size: 9 }, callback: v => Math.round(v) + '%' }
          },
          x: {
            grid: { display: false },
            ticks: { display: true, color: '#8a9bb0', font: { size: 9 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 7 }
          }
        }
      }
    });

    // --- DYNAMIC HOST PAGINATION & SLIDE MATRIX ---
    let cachedMappedHosts = [];
    let currentHostPage = 0;

    function calculateDynamicPageSize() {
      const wrapper = document.getElementById('node-grid-wrapper');
      if (!wrapper) return 6;
      const rect = wrapper.getBoundingClientRect();
      const colWidth = 280;
      const rowHeight = 100;
      const gap = 10;

      const cols = Math.max(1, Math.floor((rect.width + gap) / (colWidth + gap)));
      const rows = Math.max(1, Math.floor((rect.height + gap) / (rowHeight + gap)));
      return Math.max(1, cols * rows);
    }

    function renderPagedHostMatrix(forceImmediate = false) {
      const grid = document.getElementById('node-grid');
      const indicator = document.getElementById('page-indicator');
      if (!grid || !indicator) return;

      if (cachedMappedHosts.length === 0) {
        grid.innerHTML = `<div style="color:#8a9bb0; font-size:0.75rem;">No hosts registered</div>`;
        indicator.innerText = `PAGE 1/1`;
        return;
      }

      const pageSize = calculateDynamicPageSize();
      const totalPages = Math.ceil(cachedMappedHosts.length / pageSize);
      if (currentHostPage >= totalPages) currentHostPage = 0;

      indicator.innerText = `PAGE ${currentHostPage + 1}/${totalPages}`;

      const startIndex = currentHostPage * pageSize;
      const pageHosts = cachedMappedHosts.slice(startIndex, startIndex + pageSize);

      const renderCards = () => {
        grid.innerHTML = pageHosts.map(h => `
          <div class="node-card ${h.compositeState}">
            <div class="node-header">
              <div class="node-name" title="${h.alias || h.name}">${h.alias || h.name}</div>
              <span class="node-status-badge">${h.statusText}</span>
            </div>
            <div class="node-card-bottom">
              <span>${h.svcs.length} SERVICES MONITORED</span>
              <span>${h.address || 'LOCAL'}</span>
            </div>
          </div>
        `).join('');
      };

      if (forceImmediate || totalPages === 1) {
        renderCards();
      } else {
        grid.classList.add('sliding-out');
        setTimeout(() => {
          renderCards();
          grid.classList.remove('sliding-out');
        }, 350);
      }
    }

    setInterval(() => {
      if (cachedMappedHosts.length === 0) return;
      const pageSize = calculateDynamicPageSize();
      const totalPages = Math.ceil(cachedMappedHosts.length / pageSize);
      if (totalPages > 1) {
        currentHostPage = (currentHostPage + 1) % totalPages;
        renderPagedHostMatrix(false);
      }
    }, 8000);

    window.addEventListener('resize', () => renderPagedHostMatrix(true));

    // --- MAIN API FETCH & STATE LOOP ---
    let consecutiveFailures = 0;

    async function fetchNemsData() {
      try {
        const [hostsRes, svcsRes] = await Promise.all([
          fetch('/nems-api/hosts?Columns=name,alias,state,address,plugin_output,last_state_change').then(r => r.json()),
          fetch('/nems-api/services?Columns=host_name,description,state,plugin_output,perf_data,last_state_change').then(r => r.json())
        ]);

        if (!hostsRes.success || !svcsRes.success) throw new Error("API Failure");

        // Clear Lost Connection Overlay
        consecutiveFailures = 0;
        document.getElementById('connection-lost-overlay').classList.remove('active');

        const hosts = hostsRes.content || [];
        const services = svcsRes.content || [];

        // 1. Detect Added or Removed Hosts
        if (trackedHostMap !== null) {
          const currentHostNames = new Set(hosts.map(h => h.name));
          
          hosts.forEach(h => {
            if (!trackedHostMap.has(h.name)) {
              enqueueSpeech(`New host ${h.alias || h.name} has been added to NEMS monitoring.`, null, false, 'ok', '[SYSTEM NOTICE]');
            }
          });

          trackedHostMap.forEach((alias, name) => {
            if (!currentHostNames.has(name)) {
              enqueueSpeech(`Host ${alias || name} was removed from NEMS monitoring.`, null, false, 'warn', '[SYSTEM NOTICE]');
            }
          });
        }
        trackedHostMap = new Map(hosts.map(h => [h.name, h.alias || h.name]));

        // 2. Map Composite States
        cachedMappedHosts = hosts.map(h => {
          const hostSvcs = services.filter(s => s.host_name === h.name);
          let compositeState = 'ok';
          let statusText = '● ONLINE';

          if (h.state !== 0) {
            compositeState = 'crit';
            statusText = '✖ HOST DOWN';
          } else if (hostSvcs.some(s => s.state === 2)) {
            compositeState = 'crit';
            statusText = '⚠ CRITICAL SERVICE';
          } else if (hostSvcs.some(s => s.state === 1)) {
            compositeState = 'warn';
            statusText = '▲ WARNING SERVICE';
          } else if (hostSvcs.some(s => s.state === 3)) {
            compositeState = 'unk';
            statusText = '? UNKNOWN SERVICE';
          }

          return { ...h, compositeState, statusText, svcs: hostSvcs };
        });

        renderPagedHostMatrix(true);

        const hUp = hosts.filter(h => h.state === 0).length;
        const hDown = hosts.filter(h => h.state !== 0).length;
        const sOk = services.filter(s => s.state === 0).length;
        const sWarn = services.filter(s => s.state === 1).length;
        const sCrit = services.filter(s => s.state === 2).length;
        const sUnknown = services.filter(s => s.state === 3).length;

        document.getElementById('h-up').innerText = hUp;
        document.getElementById('h-down').innerText = hDown;
        document.getElementById('s-ok').innerText = sOk;
        document.getElementById('s-warn').innerText = sWarn;
        document.getElementById('s-crit').innerText = sCrit;
        document.getElementById('s-unknown').innerText = sUnknown;

        const hostSla = hosts.length > 0 ? Math.round((hUp / hosts.length) * 100) : 100;
        const svcSla = services.length > 0 ? Math.round((sOk / services.length) * 100) : 100;
        const totalObj = hosts.length + services.length;
        const totalGood = hUp + sOk;
        const overallSla = totalObj > 0 ? Math.round((totalGood / totalObj) * 100) : 100;

        document.getElementById('sla-val').innerText = `${overallSla}%`;
        document.getElementById('host-health').innerText = `${hostSla}%`;
        document.getElementById('svc-health').innerText = `${svcSla}%`;

        // 3. Update Electrified Background Theme Based on Overall State
        if (hDown > 0 || sCrit > 0) updateElectrifiedTheme('crit');
        else if (sWarn > 0) updateElectrifiedTheme('warn');
        else updateElectrifiedTheme('ok');

        // 4. 100% Health Celebration Trigger
        if (previousOverallHealth !== null && previousOverallHealth < 100 && overallSla === 100) {
          launchFireworks();
          dispatchSpeechEvent('celebration', "Sensors report infrastructure health has reached 100 percent. Outstanding work team.", {});
        }
        previousOverallHealth = overallSla;

        // 5. Timeline Update
        const updatedHist = updateSlaHistory(overallSla);
        slaChart.data.labels = updatedHist.labels;
        slaChart.data.datasets[0].data = updatedHist.data;
        slaChart.data.datasets[0].pointRadius = updatedHist.data.length === 1 ? 3 : 0;
        slaChart.update('none');

        // 6. Smart Telemetry Meters
        const perfContainer = document.getElementById('perf-widgets');
        let perfHtml = '';

        let tempVal = null, humidVal = null, speedOutput = null;

        services.forEach(s => {
          const desc = s.description.toLowerCase();
          const output = s.plugin_output || '';

          if ((desc.includes('speed') || desc.includes('internet')) && s.state === 0) {
            speedOutput = output;
          } else if ((desc.includes('temp') || desc.includes('room')) && s.state === 0) {
            const m = output.match(/([0-9.]+)\s*°?[CF]/i) || output.match(/([0-9.]+)/);
            if (m) tempVal = parseFloat(m[1]);
          } else if (desc.includes('humid') && s.state === 0) {
            const m = output.match(/([0-9.]+)\s*%/i) || output.match(/([0-9.]+)/);
            if (m) humidVal = parseFloat(m[1]);
          }
        });

        // WAN Speedtest Widget
        if (speedOutput) {
          const dlMatch = speedOutput.match(/Download\s*=\s*([0-9.]+)/i);
          const ulMatch = speedOutput.match(/Upload\s*=\s*([0-9.]+)/i);
          const dl = dlMatch ? parseFloat(dlMatch[1]) : 0;
          const ul = ulMatch ? parseFloat(ulMatch[1]) : 0;

          perfHtml += `
            <div class="perf-widget">
              <div class="perf-title">⚡ WAN Speedtest</div>
              <div class="dual-meter-row">
                <div class="meter-sub-box">
                  <span style="color:var(--cyan); font-weight:bold;">Down: ${dl.toFixed(1)} Mbps</span>
                  <div class="meter-bar-track"><div class="meter-bar-fill" style="width: ${Math.min(100, (dl/1000)*100)}%;"></div></div>
                </div>
                <div class="meter-sub-box">
                  <span style="color:var(--green); font-weight:bold;">Up: ${ul.toFixed(1)} Mbps</span>
                  <div class="meter-bar-track"><div class="meter-bar-fill" style="width: ${Math.min(100, (ul/1000)*100)}%; background:var(--green);"></div></div>
                </div>
              </div>
            </div>`;
        }

        // Thermal & Environmental Meters
        if (tempVal !== null || humidVal !== null) {
          perfHtml += `<div class="perf-widget"><div class="perf-title">🌡 Ambient Environment</div><div class="dual-meter-row">`;
          
          if (tempVal !== null) {
            perfHtml += `
              <div class="meter-sub-box">
                <span style="color:var(--warn); font-weight:bold;">${tempVal.toFixed(1)}° C</span>
                <div class="meter-bar-track"><div class="meter-bar-fill" style="width: ${Math.min(100, (tempVal/50)*100)}%; background:var(--warn);"></div></div>
              </div>`;
          }
          if (humidVal !== null) {
            perfHtml += `
              <div class="meter-sub-box">
                <span style="color:var(--cyan); font-weight:bold;">${humidVal.toFixed(1)}% Humidity</span>
                <div class="meter-bar-track"><div class="meter-bar-fill" style="width: ${Math.min(100, humidVal)}%;"></div></div>
              </div>`;
          }
          perfHtml += `</div></div>`;
        }

        perfContainer.innerHTML = perfHtml;

        // 7. Active Incidents Processing
        const incidents = [
          ...hosts.filter(h => h.state !== 0).map(h => ({
            host: h.name, alias: h.alias, checkName: 'HOST DOWN', stateText: 'DOWN', stateCode: h.state, stateClass: 'crit', msg: h.plugin_output, ts: h.last_state_change
          })),
          ...services.filter(s => s.state !== 0).map(s => {
            const parentHost = hosts.find(h => h.name === s.host_name);
            let stateText = 'UNKNOWN', stateClass = 'unk';
            if (s.state === 1) { stateText = 'WARNING'; stateClass = 'warn'; }
            if (s.state === 2) { stateText = 'CRITICAL'; stateClass = 'crit'; }

            return {
              host: s.host_name, alias: parentHost ? parentHost.alias : s.host_name, checkName: s.description, stateText: stateText, stateCode: s.state, stateClass: stateClass, msg: s.plugin_output, ts: s.last_state_change
            };
          })
        ];

        if (!hasAnnouncedOnline) {
          hasAnnouncedOnline = true;
          announceWelcomeOverview(hosts, services, incidents, overallSla);
        }

        const newIncidentsToAnnounce = [];
        incidents.forEach(inc => {
          const key = `${inc.host}_${inc.checkName}`;
          const lastState = spokenIncidents.get(key);

          if (lastState === undefined || lastState !== inc.stateCode) {
            spokenIncidents.set(key, inc.stateCode);
            newIncidentsToAnnounce.push(inc);
          }
        });

        if (newIncidentsToAnnounce.length > 0) {
          dispatchBatchIncidents(newIncidentsToAnnounce);
        }

        const newRecoveriesToAnnounce = [];
        hosts.forEach(h => {
          const key = `HOST_${h.name}`;
          const prevState = previousStateMap.get(key);
          if (prevState !== undefined && prevState !== 0 && h.state === 0) {
            newRecoveriesToAnnounce.push({ host: h.name, alias: h.alias, checkName: 'HOST DOWN', msg: h.plugin_output });
            spokenIncidents.delete(`${h.name}_HOST DOWN`);
          }
          previousStateMap.set(key, h.state);
        });

        services.forEach(s => {
          const parentHost = hosts.find(h => h.name === s.host_name);
          const key = `SVC_${s.host_name}_${s.description}`;
          const prevState = previousStateMap.get(key);
          if (prevState !== undefined && prevState !== 0 && s.state === 0) {
            newRecoveriesToAnnounce.push({ host: s.host_name, alias: parentHost ? parentHost.alias : s.host_name, checkName: s.description, msg: s.plugin_output });
            spokenIncidents.delete(`${s.host_name}_${s.description}`);
          }
          previousStateMap.set(key, s.state);
        });

        if (newRecoveriesToAnnounce.length > 0) {
          dispatchBatchRecoveries(newRecoveriesToAnnounce);
        }

        document.getElementById('inc-count').innerText = incidents.length;
        const incidentContainer = document.getElementById('incident-list');

        if (incidents.length === 0) {
          incidentContainer.innerHTML = `<div style="text-align: center; color: var(--green); margin-top: 40px; font-size: 0.85rem;">✓ ALL SYSTEMS OPERATIONAL</div>`;
        } else {
          incidentContainer.innerHTML = incidents.map(i => `
            <div class="incident-item ${i.stateClass}">
              <div class="incident-top-line">
                <span class="incident-check">${i.checkName}</span>
                <span class="incident-timer">${formatElapsed(i.ts)}</span>
              </div>
              <div class="incident-host">${i.alias || i.host}</div>
              <div class="incident-msg">${i.msg || 'No plugin output available'}</div>
            </div>
          `).join('');
        }

      } catch (e) {
        consecutiveFailures++;
        if (consecutiveFailures >= 2) {
          document.getElementById('connection-lost-overlay').classList.add('active');
        }
      }
    }

    fetchNemsData();
    setInterval(fetchNemsData, 5000);
  </script>
</body>
</html>
