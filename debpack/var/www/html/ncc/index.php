<?php
// INTERNAL API INTERCEPTOR FOR REAL-TIME NEMS SERVER CPU LOAD
if (isset($_GET['getload'])) {
    header('Content-Type: application/json');
    $cores = (int)shell_exec('nproc');
    if ($cores < 1) $cores = 1;
    echo json_encode(['load' => sys_getloadavg(), 'cores' => $cores]);
    exit;
}

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
      --panel-bg: rgba(10, 16, 28, 0.45);
      --static-cyan: #00f0ff;
      --green: #00ff88;
      --warn: #ffaa00;
      --crit: #ff0055;
      --unknown: #a855f7;
      --font: 'Segoe UI', Roboto, sans-serif;

      /* DYNAMIC THEME VARIABLES */
      --theme-color: #00f0ff;
      --theme-border: rgba(0, 240, 255, 0.25);
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

    /* THEME OVERRIDES TRIGGERED BY JS */
    body.is-warn {
      --theme-color: #ffaa00;
      --theme-border: rgba(255, 170, 0, 0.35);
    }
    body.is-crit {
      --theme-color: #ff0055;
      --theme-border: rgba(255, 0, 85, 0.45);
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
      border-bottom: 1px solid var(--theme-border);
      background: linear-gradient(180deg, rgba(0,240,255,0.12) 0%, transparent 100%);
      transition: border-color 0.8s ease;
    }
    .title-box h1 { 
      font-size: 1.35rem; 
      letter-spacing: 3px; 
      color: var(--theme-color); 
      text-shadow: 0 0 12px var(--theme-color); 
      transition: color 0.8s ease, text-shadow 0.8s ease; 
    }
    .title-box span { font-size: 0.65rem; color: #8a9bb0; letter-spacing: 1.5px; }
    #clock { transition: color 0.8s ease; }

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
      border: 1px solid var(--theme-border);
      border-radius: 4px;
      padding: 12px;
      display: flex;
      flex-direction: column;
      clip-path: polygon(0 0, calc(100% - 12px) 0, 100% 12px, 100% 100%, 12px 100%, 0 calc(100% - 12px));
      box-shadow: inset 0 0 15px rgba(0,240,255,0.03);
      min-height: 0;
      backdrop-filter: blur(2px);
      transition: border-color 0.8s ease;
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
      color: var(--theme-color);
      text-transform: uppercase;
      margin-bottom: 6px;
      border-bottom: 1px dashed var(--theme-border);
      padding-bottom: 4px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      transition: color 0.8s ease, border-color 0.8s ease;
    }

    .center-layout {
      display: grid;
      grid-template-rows: auto 1fr 165px;
      gap: 10px;
      height: 100%;
      min-height: 0;
    }

    /* SCI-FI HUD RADIAL GAUGES CONTAINER */
    .hud-gauges {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr 1fr;
      gap: 10px;
      background: rgba(0, 240, 255, 0.02);
      border: 1px solid var(--theme-border);
      padding: 6px 10px;
      border-radius: 4px;
      backdrop-filter: blur(2px);
      transition: border-color 0.8s ease;
    }
    .gauge-box {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      position: relative;
    }
    .gauge-canvas {
      width: 78px;
      height: 78px;
    }
    .gauge-lbl {
      font-size: 0.58rem;
      color: #8a9bb0;
      letter-spacing: 1px;
      margin-top: 2px;
      text-transform: uppercase;
      font-weight: 600;
    }

    .matrix-container {
      background: rgba(0, 0, 0, 0.22);
      border: 1px solid var(--theme-border);
      padding: 12px;
      overflow: hidden;
      border-radius: 4px;
      display: flex;
      flex-direction: column;
      min-height: 0;
      position: relative;
      backdrop-filter: blur(2px);
      transition: border-color 0.8s ease;
    }

    #page-indicator {
      position: absolute;
      top: 12px;
      right: 14px;
      color: var(--theme-color);
      font-size: 0.72rem;
      font-weight: bold;
      letter-spacing: 1px;
      background: rgba(0, 240, 255, 0.1);
      padding: 2px 8px;
      border-radius: 3px;
      border: 1px solid var(--theme-border);
      transition: color 0.8s ease, border-color 0.8s ease;
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
      background: rgba(15, 23, 42, 0.38);
      border: 1px solid var(--theme-border);
      padding: 10px 12px;
      border-radius: 6px;
      min-height: 108px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: border-color 0.3s ease, background 0.3s ease;
      box-shadow: 0 4px 14px rgba(0,0,0,0.25);
      backdrop-filter: blur(2px);
    }
    .node-card.ok { border-color: rgba(0,255,136,0.4); box-shadow: inset 0 0 10px rgba(0,255,136,0.05); }
    .node-card.warn { border-color: var(--warn); background: rgba(255,170,0,0.15); }
    .node-card.crit { border-color: var(--crit); background: rgba(255,0,85,0.20); box-shadow: 0 0 15px rgba(255,0,85,0.3); }
    .node-card.unk { border-color: var(--unknown); background: rgba(168,85,247,0.15); }

    .node-header { margin-bottom: 4px; }
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
      flex-direction: column;
      gap: 4px;
      border-top: 1px dashed rgba(0, 240, 255, 0.15);
      padding-top: 6px;
      margin-top: 4px;
    }

    .node-card-meta-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 0.68rem;
      color: #8a9bb0;
    }

    /* HIGH-RESOLUTION 48-SEGMENT BINARY HISTORICAL STRIP (30-MIN BLOCKS) */
    .history-bar-container {
      display: flex;
      gap: 1px;
      height: 6px;
      width: 100%;
      background: rgba(255, 255, 255, 0.05);
      border-radius: 2px;
      padding: 1px;
    }
    .history-bar-segment {
      flex: 1;
      height: 100%;
      border-radius: 1px;
      transition: background-color 0.3s ease;
    }
    .history-bar-segment.seg-ok { background-color: var(--green); box-shadow: 0 0 2px rgba(0,255,136,0.3); }
    .history-bar-segment.seg-crit { background-color: var(--crit); box-shadow: 0 0 3px rgba(255,0,85,0.6); }

    .chart-box {
      background: rgba(0, 0, 0, 0.20);
      border: 1px solid var(--theme-border);
      padding: 8px 10px 4px 10px;
      border-radius: 4px;
      display: flex;
      flex-direction: column;
      height: 100%;
      min-height: 0;
      backdrop-filter: blur(2px);
      transition: border-color 0.8s ease;
    }

    .stat-row { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
    .card { background: rgba(255,255,255,0.03); border-left: 3px solid var(--theme-color); padding: 6px; transition: border-color 0.8s ease; }
    .card.ok { border-color: var(--green); }
    .card.warn { border-color: var(--warn); }
    .card.crit { border-color: var(--crit); }
    .card .num { font-size: 1.2rem; font-weight: bold; }
    .card .label { font-size: 0.6rem; color: #8a9bb0; letter-spacing: 1px; }

    .chat-container {
      flex: 1;
      background: rgba(0, 0, 0, 0.22);
      border: 1px solid var(--theme-border);
      border-radius: 4px;
      padding: 8px;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 6px;
      min-height: 0;
      backdrop-filter: blur(2px);
      transition: border-color 0.8s ease;
    }
    .chat-item {
      background: rgba(0, 240, 255, 0.05);
      border-left: 3px solid var(--static-cyan);
      padding: 6px 8px;
      border-radius: 3px;
      font-size: 0.72rem;
      line-height: 1.35;
      animation: fadeIn 0.3s ease-in;
    }
    .chat-item.ai { border-left-color: var(--green); background: rgba(0, 255, 136, 0.05); }
    .chat-item.alert, .chat-item.crit { border-left-color: var(--crit); background: rgba(255, 0, 85, 0.08); }
    .chat-item.warn { border-left-color: var(--warn); background: rgba(255, 170, 0, 0.08); }
    .chat-item.unk { border-left-color: var(--unknown); background: rgba(168,85,247,0.08); }
    .chat-item.ok { border-left-color: var(--static-cyan); background: rgba(0, 240, 255, 0.05); }

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
      border: 1px solid var(--theme-border);
      padding: 6px;
      margin-top: 4px;
      border-radius: 3px;
      transition: border-color 0.8s ease;
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
      background: linear-gradient(90deg, var(--static-cyan), var(--green));
      border-radius: 4px;
      transition: width 0.5s ease, background 0.5s ease;
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
      color: var(--static-cyan);
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
    <div class="lost-sub">NEMS SERVER IS NOT RESPONDING - AWAITING CONNECTION...</div>
  </div>

  <!-- CELEBRATION FIREWORKS CANVAS -->
  <canvas id="fireworks-canvas"></canvas>

  <header>
    <div class="title-box">
      <h1>NEMS CENTRAL COMMAND</h1>
      <span>REAL-TIME ENTERPRISE INFRASTRUCTURE HEALTH</span>
    </div>
    <div id="clock" style="font-size: 1rem; letter-spacing: 2px; color: var(--theme-color);">--:--:--</div>
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
      <!-- SCI-FI RADIAL HUD DIALS -->
      <div class="hud-gauges">
        <div class="gauge-box">
          <canvas id="gauge-overall" class="gauge-canvas" width="156" height="156"></canvas>
          <div class="gauge-lbl">OVERALL HEALTH</div>
        </div>
        <div class="gauge-box">
          <canvas id="gauge-avg" class="gauge-canvas" width="156" height="156"></canvas>
          <div class="gauge-lbl">24H AVERAGE HEALTH</div>
        </div>
        <div class="gauge-box">
          <canvas id="gauge-host" class="gauge-canvas" width="156" height="156"></canvas>
          <div class="gauge-lbl">HOST HEALTH</div>
        </div>
        <div class="gauge-box">
          <canvas id="gauge-svc" class="gauge-canvas" width="156" height="156"></canvas>
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
        <h2 style="font-size:0.7rem; color:var(--theme-color); margin-bottom:2px; transition: color 0.8s ease;">INFRASTRUCTURE HEALTH TIMELINE</h2>
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
    // --- GLOBAL STATE ---
    let currentOverallSla = 100;
    let isInitialLoad = true;

    // --- WEB AUDIO API SOUND ENGINE ---
    function playTacticalSound(type = 'alert') {
      try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        if (!window.audioCtx) window.audioCtx = new AudioCtx();
        if (window.audioCtx.state === 'suspended') window.audioCtx.resume();

        const osc = window.audioCtx.createOscillator();
        const gain = window.audioCtx.createGain();
        const now = window.audioCtx.currentTime;

        if (type === 'klaxon') {
          // Dual-Tone Square Wave for Critical Network Emergency (<75% Overall)
          osc.type = 'square';
          osc.frequency.setValueAtTime(600, now);
          osc.frequency.setValueAtTime(800, now + 0.25);
          osc.frequency.setValueAtTime(600, now + 0.5);
          osc.frequency.setValueAtTime(800, now + 0.75);
          gain.gain.setValueAtTime(0.08, now);
          gain.gain.linearRampToValueAtTime(0.0, now + 1.0);
          osc.connect(gain);
          gain.connect(window.audioCtx.destination);
          osc.start(now);
          osc.stop(now + 1.0);
        } else if (type === 'alert') {
          // "Pew" sound for normal service/host alerts
          osc.type = 'sine';
          osc.frequency.setValueAtTime(880, now);
          osc.frequency.exponentialRampToValueAtTime(220, now + 0.2);
          gain.gain.setValueAtTime(0.12, now);
          gain.gain.exponentialRampToValueAtTime(0.001, now + 0.2);
          osc.connect(gain);
          gain.connect(window.audioCtx.destination);
          osc.start(now);
          osc.stop(now + 0.2);
        } else if (type === 'recovery') {
          // Gentle rising recovery chime
          osc.type = 'sine';
          osc.frequency.setValueAtTime(329.63, now);
          osc.frequency.exponentialRampToValueAtTime(523.25, now + 0.3);
          gain.gain.setValueAtTime(0.08, now);
          gain.gain.exponentialRampToValueAtTime(0.001, now + 0.32);
          osc.connect(gain);
          gain.connect(window.audioCtx.destination);
          osc.start(now);
          osc.stop(now + 0.32);
        }
      } catch(e) {}
    }

    // --- INTERACTIVE AUDIO TEST HARNESS MODE TRIGGER (`?testsound`) ---
    if (window.location.search.includes('testsound')) {
      const overlay = document.createElement('div');
      overlay.innerHTML = '<h1 style="color:#00f0ff; font-family:sans-serif; text-align:center; padding:20px; background:rgba(0,0,0,0.8); border:1px solid #00f0ff; border-radius:8px;">CLICK TO START AUDIO TEST<br><span style="font-size:12px; color:#8a9bb0;">Allows browser to authorize Web Audio API</span></h1>';
      overlay.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.85);z-index:999999;display:flex;align-items:center;justify-content:center;cursor:pointer;';
      document.body.appendChild(overlay);

      overlay.onclick = () => {
        overlay.remove();
        if (window.audioCtx && window.audioCtx.state === 'suspended') window.audioCtx.resume();
        
        enqueueSpeech("Audio test sequence initiated. Triggering standard alert pew.", null, false, 'crit', '[TEST HARNESS]');
        setTimeout(() => { playTacticalSound('alert'); }, 3000);
        setTimeout(() => {
          enqueueSpeech("Triggering emergency klaxon.", null, false, 'crit', '[TEST HARNESS]');
          setTimeout(() => { playTacticalSound('klaxon'); }, 2000);
        }, 8000);
        setTimeout(() => {
          enqueueSpeech("Triggering recovery chime.", null, false, 'ok', '[TEST HARNESS]');
          setTimeout(() => { playTacticalSound('recovery'); }, 2000);
        }, 16000);
        setTimeout(() => {
          window.celebrationPending = true;
          enqueueSpeech("Triggering celebration sequence.", null, false, 'ok', '[TEST HARNESS]');
        }, 24000);
      };
    }

    // --- STANDARDIZED HEALTH THRESHOLD COLOR HELPER ---
    function getHealthThresholdColor(val) {
      if (val >= 90) return '#00f0ff'; // 90-100% = Aqua / Cyan
      if (val >= 75) return '#ffaa00'; // 75-89% = Amber / Warning
      return '#ff0055';                // <75% = Critical Red
    }

    // --- SCI-FI HUD RADIAL GAUGE RENDERER ---
    function renderSciFiGauge(canvasId, value) {
      const canvas = document.getElementById(canvasId);
      if (!canvas) return;
      const ctx = canvas.getContext('2d');
      const w = canvas.width;
      const h = canvas.height;
      const cx = w / 2;
      const cy = h / 2;
      const radius = cx - 14;

      ctx.clearRect(0, 0, w, h);

      const val = Math.max(0, Math.min(100, Math.round(value)));
      const activeColor = getHealthThresholdColor(val);

      const startAngle = 0.75 * Math.PI;
      const totalArc = 1.5 * Math.PI;
      const currentArc = startAngle + (val / 100) * totalArc;

      ctx.strokeStyle = 'rgba(0, 240, 255, 0.12)';
      ctx.lineWidth = 5;
      ctx.beginPath();
      ctx.arc(cx, cy, radius, startAngle, startAngle + totalArc);
      ctx.stroke();

      ctx.strokeStyle = 'rgba(255, 255, 255, 0.06)';
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.arc(cx, cy, radius - 8, 0, 2 * Math.PI);
      ctx.stroke();

      ctx.save();
      ctx.shadowColor = activeColor;
      ctx.shadowBlur = 10;
      ctx.strokeStyle = activeColor;
      ctx.lineWidth = 5;
      ctx.lineCap = 'round';
      ctx.beginPath();
      ctx.arc(cx, cy, radius, startAngle, currentArc);
      ctx.stroke();
      ctx.restore();

      const numTicks = 12;
      for (let i = 0; i <= numTicks; i++) {
        const tickAngle = startAngle + (i / numTicks) * totalArc;
        const x1 = cx + (radius - 12) * Math.cos(tickAngle);
        const y1 = cy + (radius - 12) * Math.sin(tickAngle);
        const x2 = cx + (radius - 15) * Math.cos(tickAngle);
        const y2 = cy + (radius - 15) * Math.sin(tickAngle);

        ctx.strokeStyle = (startAngle + (i / numTicks) * totalArc) <= currentArc 
          ? activeColor 
          : 'rgba(138, 155, 176, 0.3)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x1, y1);
        ctx.lineTo(x2, y2);
        ctx.stroke();
      }

      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.font = 'bold 22px "Segoe UI", Roboto, sans-serif';
      ctx.fillStyle = activeColor;
      ctx.shadowColor = activeColor;
      ctx.shadowBlur = 8;
      ctx.fillText(`${val}%`, cx, cy);
      ctx.shadowBlur = 0;
    }

    // --- MULTI-DIRECTIONAL ELECTRIFIED GRID & SMOOTH LERP ENGINE ---
    const bgCanvas = document.getElementById('bg-canvas');
    const bgCtx = bgCanvas.getContext('2d');
    let sparks = [];
    let bursts = [];
    const GRID_SIZE = 45;
    const MIN_SPEED = 3.8;
    const MAX_SPEED = 7.5;

    let currentSystemState = 'ok';
    let currentGridRgb = [0, 240, 255];
    let targetGridRgb = [0, 240, 255];
    let currentSparkRgb = [0, 240, 255];
    let targetSparkRgb = [0, 240, 255];
    let currentGridAlpha = 0.09;
    let targetGridAlpha = 0.09;

    function lerp(start, end, amt) { return start + (end - start) * amt; }

    function resizeBgCanvas() {
      bgCanvas.width = window.innerWidth;
      bgCanvas.height = window.innerHeight;
    }
    window.addEventListener('resize', resizeBgCanvas);
    resizeBgCanvas();

    function updateElectrifiedTheme(state) {
      currentSystemState = state;
      document.body.classList.remove('is-warn', 'is-crit');

      if (state === 'crit') {
        document.body.classList.add('is-crit');
        targetGridRgb = [255, 0, 85];
        targetSparkRgb = [255, 0, 85];
        targetGridAlpha = 0.18;
      } else if (state === 'warn') {
        document.body.classList.add('is-warn');
        targetGridRgb = [255, 170, 0];
        targetSparkRgb = [255, 170, 0];
        targetGridAlpha = 0.14;
      } else {
        targetGridRgb = [0, 240, 255];
        targetSparkRgb = [0, 240, 255];
        targetGridAlpha = 0.09;
      }
    }

    function spawnElectricSpark() {
      const isHorizontal = Math.random() > 0.5;
      const isForward = Math.random() > 0.5;

      if (isHorizontal) {
        const numRows = Math.floor(bgCanvas.height / GRID_SIZE);
        const activeRows = new Set(sparks.filter(s => s.dir.startsWith('h')).map(s => s.gridIndex));
        const availableRows = [];
        for (let r = 0; r < numRows; r++) if (!activeRows.has(r)) availableRows.push(r);
        if (availableRows.length === 0) return;

        const row = availableRows[Math.floor(Math.random() * availableRows.length)];
        const speed = MIN_SPEED + Math.random() * (MAX_SPEED - MIN_SPEED);

        if (isForward) sparks.push({ x: -40, y: row * GRID_SIZE, gridIndex: row, length: 30 + Math.random() * 25, speed: speed, dir: 'h+' });
        else sparks.push({ x: bgCanvas.width + 40, y: row * GRID_SIZE, gridIndex: row, length: 30 + Math.random() * 25, speed: -speed, dir: 'h-' });
      } else {
        const numCols = Math.floor(bgCanvas.width / GRID_SIZE);
        const activeCols = new Set(sparks.filter(s => s.dir.startsWith('v')).map(s => s.gridIndex));
        const availableCols = [];
        for (let c = 0; c < numCols; c++) if (!activeCols.has(c)) availableCols.push(c);
        if (availableCols.length === 0) return;

        const col = availableCols[Math.floor(Math.random() * availableCols.length)];
        const speed = MIN_SPEED + Math.random() * (MAX_SPEED - MIN_SPEED);

        if (isForward) sparks.push({ x: col * GRID_SIZE, y: -40, gridIndex: col, length: 30 + Math.random() * 25, speed: speed, dir: 'v+' });
        else sparks.push({ x: col * GRID_SIZE, y: bgCanvas.height + 40, gridIndex: col, length: 30 + Math.random() * 25, speed: -speed, dir: 'v-' });
      }
    }

    function animateElectrifiedGrid() {
      bgCtx.clearRect(0, 0, bgCanvas.width, bgCanvas.height);

      currentGridRgb[0] = lerp(currentGridRgb[0], targetGridRgb[0], 0.04);
      currentGridRgb[1] = lerp(currentGridRgb[1], targetGridRgb[1], 0.04);
      currentGridRgb[2] = lerp(currentGridRgb[2], targetGridRgb[2], 0.04);

      currentSparkRgb[0] = lerp(currentSparkRgb[0], targetSparkRgb[0], 0.04);
      currentSparkRgb[1] = lerp(currentSparkRgb[1], targetSparkRgb[1], 0.04);
      currentSparkRgb[2] = lerp(currentSparkRgb[2], targetSparkRgb[2], 0.04);

      currentGridAlpha = lerp(currentGridAlpha, targetGridAlpha, 0.04);

      let pulseAlpha = currentGridAlpha;
      if (currentSystemState === 'crit') pulseAlpha += Math.sin(Date.now() / 320) * 0.06;

      const gridColorStr = `rgba(${Math.round(currentGridRgb[0])}, ${Math.round(currentGridRgb[1])}, ${Math.round(currentGridRgb[2])}, ${Math.max(0.02, pulseAlpha).toFixed(3)})`;
      const sparkColorStr = `${Math.round(currentSparkRgb[0])}, ${Math.round(currentSparkRgb[1])}, ${Math.round(currentSparkRgb[2])}`;

      bgCtx.strokeStyle = gridColorStr;
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

      if (Math.random() < 0.035 && sparks.length < 3) spawnElectricSpark();

      const hSparks = sparks.filter(s => s.dir.startsWith('h'));
      const vSparks = sparks.filter(s => s.dir.startsWith('v'));

      hSparks.forEach(h => {
        vSparks.forEach(v => {
          const ix = v.x;
          const iy = h.y;

          const minHx = Math.min(h.x, h.x + (h.dir === 'h+' ? h.length : -h.length));
          const maxHx = Math.max(h.x, h.x + (h.dir === 'h+' ? h.length : -h.length));
          const minVy = Math.min(v.y, v.y + (v.dir === 'v+' ? v.length : -v.length));
          const maxVy = Math.max(v.y, v.y + (v.dir === 'v+' ? v.length : -v.length));

          if (minHx <= ix && ix <= maxHx && minVy <= iy && iy <= maxVy) {
            if (!bursts.some(b => Math.abs(b.x - ix) < 8 && Math.abs(b.y - iy) < 8)) {
              bursts.push({ x: ix, y: iy, radius: 3, alpha: 1.0 });
            }
          }
        });
      });

      bursts.forEach((b, bIdx) => {
        bgCtx.save();
        bgCtx.shadowColor = `rgba(${sparkColorStr}, 1.0)`;
        bgCtx.shadowBlur = 18;

        bgCtx.fillStyle = `#ffffff`;
        bgCtx.beginPath();
        bgCtx.arc(b.x, b.y, Math.max(1, b.radius * 0.4), 0, Math.PI * 2);
        bgCtx.fill();

        bgCtx.strokeStyle = `rgba(${sparkColorStr}, ${b.alpha})`;
        bgCtx.lineWidth = 2;
        bgCtx.beginPath();
        bgCtx.arc(b.x, b.y, b.radius, 0, Math.PI * 2);
        bgCtx.stroke();
        bgCtx.restore();

        b.radius += 0.65;
        b.alpha -= 0.06;
        if (b.alpha <= 0) bursts.splice(bIdx, 1);
      });

      sparks.forEach((s, idx) => {
        bgCtx.lineWidth = 1.5;
        bgCtx.shadowColor = `rgba(${sparkColorStr}, 0.75)`;
        bgCtx.shadowBlur = 14;

        let grad;
        if (s.dir.startsWith('h')) {
          grad = bgCtx.createLinearGradient(s.x, s.y, s.x + (s.dir === 'h+' ? s.length : -s.length), s.y);
          grad.addColorStop(0, `rgba(${sparkColorStr}, 0)`);
          grad.addColorStop(1, `rgba(${sparkColorStr}, 0.65)`);
          bgCtx.strokeStyle = grad;
          bgCtx.beginPath();
          bgCtx.moveTo(s.x, s.y);
          bgCtx.lineTo(s.x + (s.dir === 'h+' ? s.length : -s.length), s.y);
          bgCtx.stroke();
          s.x += s.speed;
        } else {
          grad = bgCtx.createLinearGradient(s.x, s.y, s.x, s.y + (s.dir === 'v+' ? s.length : -s.length));
          grad.addColorStop(0, `rgba(${sparkColorStr}, 0)`);
          grad.addColorStop(1, `rgba(${sparkColorStr}, 0.65)`);
          bgCtx.strokeStyle = grad;
          bgCtx.beginPath();
          bgCtx.moveTo(s.x, s.y);
          bgCtx.lineTo(s.x, s.y + (s.dir === 'v+' ? s.length : -s.length));
          bgCtx.stroke();
          s.y += s.speed;
        }

        if ((s.dir === 'h+' && s.x > bgCanvas.width + 60) || (s.dir === 'h-' && s.x < -60) || (s.dir === 'v+' && s.y > bgCanvas.height + 60) || (s.dir === 'v-' && s.y < -60)) {
          sparks.splice(idx, 1);
        }
      });

      bgCtx.shadowBlur = 0;
      requestAnimationFrame(animateElectrifiedGrid);
    }
    animateElectrifiedGrid();

    // --- SYSTEM TIME ---
    const tv24hSetting = <?php echo $tv_24h; ?>;
    function getFormattedTime(date = new Date(), includeSeconds = true) {
      let hours = date.getHours();
      const minutes = String(date.getMinutes()).padStart(2, '0');
      const seconds = String(date.getSeconds()).padStart(2, '0');
      const secStr = includeSeconds ? `:${seconds}` : '';
      if (tv24hSetting === 1) return `${String(hours).padStart(2, '0')}:${minutes}${secStr}`;
      if (tv24hSetting === 2) {
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12 || 12;
        return `${hours}:${minutes}${secStr} ${ampm}`;
      }
      hours = hours % 12 || 12;
      return `${hours}:${minutes}${secStr}`;
    }
    function updateClock() {
      const clockEl = document.getElementById('clock');
      if (clockEl) clockEl.innerText = getFormattedTime(new Date(), true);
    }
    updateClock();
    setInterval(updateClock, 1000);

    // --- CURSOR HIDE ---
    let cursorTimer;
    function resetCursorTimer() {
      document.body.style.cursor = 'default';
      clearTimeout(cursorTimer);
      cursorTimer = setTimeout(() => { document.body.style.cursor = 'none'; }, 5000);
    }
    window.addEventListener('mousemove', resetCursorTimer);
    resetCursorTimer();

    // --- PHONETICS ---
    const phoneticsMap = <?php echo json_encode($phonetics_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    function sanitizePhonetics(phrase) {
      if (!phrase) return '';
      let text = phrase;
      text = text.replace(/\b(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\b/g, '$1 dot $2 dot $3 dot $4');
      for (const [key, val] of Object.entries(phoneticsMap)) {
        const escapedKey = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const pattern = /^\w+$/.test(key) ? new RegExp(`\\b${escapedKey}\\b`, 'gi') : new RegExp(escapedKey, 'gi');
        text = text.replace(pattern, val);
      }
      return text.replace(/([a-zA-Z0-9]+)\s*\/\s*([a-zA-Z0-9]+)/g, '$1 and $2').replace(/[*_#`"'\r\n]/g, ' ').replace(/\s+/g, ' ').trim();
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
      if (log.children.length === 1 && log.children[0].innerText.includes('Initializing')) log.innerHTML = '';

      let stateClass = typeof stateType === 'string' ? stateType : (stateType === true ? 'crit' : '');
      const msgDiv = document.createElement('div');
      msgDiv.className = `chat-item ${isAi ? 'ai' : ''} ${stateClass}`;
      msgDiv.innerHTML = `<div class="chat-meta"><span>${sender}</span><span>${getFormattedTime(new Date(), true)}</span></div><div class="chat-text">${text}</div>`;

      log.insertBefore(msgDiv, log.firstChild);
      log.scrollTop = 0;
      while (log.children.length > 20) log.removeChild(log.lastChild);
    }

    // --- SPEECH QUEUE ENGINE & CELEBRATION TRIGGER ---
    window.speechQueue = [];
    window.isSpeaking = false;
    window.currentUtterance = null;
    window.celebrationPending = false;

    if ('speechSynthesis' in window) {
      window.speechSynthesis.getVoices();
      window.speechSynthesis.onvoiceschanged = () => { if ('speechSynthesis' in window) window.speechSynthesis.getVoices(); };
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

        if (window.celebrationPending) {
          launchFireworks();
          window.celebrationPending = false;
        }

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

      if (!isInitialLoad) {
        const soundType = (currentOverallSla < 75 && eventType === 'incident') ? 'klaxon' : (eventType === 'incident' ? 'alert' : 'recovery');
        playTacticalSound(soundType);
      }

      const payload = { event_type: eventType, baseline_text: baselineText, timestamp: Math.floor(Date.now() / 1000), check_data: checkData };
      console.log(`%c[NEMS AI] Outbound Request (${eventType})`, 'color: #00f0ff; font-weight: bold;', payload);

      try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 45000);
        const res = await fetch('/nems-api/nems-ai', { method: 'POST', headers: { 'Content-Type': 'application/json' }, signal: controller.signal, body: JSON.stringify(payload) }).then(r => r.json());
        clearTimeout(timeoutId);
        console.log(`%c[NEMS AI] Synthesis Response`, 'color: #00ff88; font-weight: bold;', res);

        if (res && res.success && res.ai_active && res.speech_text) {
          speechText = res.speech_text;
          displayText = res.display_text || res.speech_text;
          isAiEngine = true;
        }
      } catch (e) {
        console.log(`%c[NEMS AI] Request Timed Out / Failed`, 'color: #ff0055; font-weight: bold;', e);
      }

      enqueueSpeech(displayText, speechText, isAiEngine, stateClass, senderTag);
    }

    async function dispatchBatchIncidents(newIncidents, totalDownHosts) {
      if (!newIncidents || newIncidents.length === 0) return;

      if (!isInitialLoad) {
        const soundType = currentOverallSla < 75 ? 'klaxon' : 'alert';
        playTacticalSound(soundType);
      }

      let primaryStateClass = 'unk';
      if (newIncidents.some(i => i.stateCode === 2)) primaryStateClass = 'crit';
      else if (newIncidents.some(i => i.stateCode === 1)) primaryStateClass = 'warn';

      if (newIncidents.length === 1) {
        const inc = newIncidents[0];
        const hostAlias = inc.alias || inc.host;
        const baselineText = inc.checkName === 'HOST DOWN' ? `Server ${hostAlias} is offline.` : `Service ${inc.checkName} on ${hostAlias} is reporting ${inc.stateText}.`;
        dispatchSpeechEvent('incident', baselineText, { host_name: inc.host, host_alias: hostAlias, service_description: inc.checkName, state: inc.stateCode, plugin_output: inc.msg });
        return;
      }

      const downCount = newIncidents.filter(i => i.checkName === 'HOST DOWN').length;
      const newlyHostStr = downCount === 1 ? 'host' : 'hosts';
      const totalHostStr = totalDownHosts === 1 ? 'host is' : 'hosts are';
      
      const baselineText = downCount > 0 
        ? `Tactical alert: ${downCount} newly offline ${newlyHostStr} detected. A total of ${totalDownHosts} ${totalHostStr} currently offline.`
        : `${newIncidents.length} active incident${newIncidents.length === 1 ? '' : 's'} detected across monitored nodes.`;

      const payload = { event_type: 'batch_incidents', baseline_text: baselineText, incidents: newIncidents.slice(0, 5) };
      console.log(`%c[NEMS AI] Outbound Request (Batch Incidents)`, 'color: #ffaa00; font-weight: bold;', payload);

      try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 45000);
        const res = await fetch('/nems-api/nems-ai', { method: 'POST', headers: { 'Content-Type': 'application/json' }, signal: controller.signal, body: JSON.stringify(payload) }).then(r => r.json());
        clearTimeout(timeoutId);
        console.log(`%c[NEMS AI] Synthesis Response`, 'color: #00ff88; font-weight: bold;', res);

        if (res && res.success && res.ai_active && res.speech_text) {
          enqueueSpeech(res.display_text, res.speech_text, true, primaryStateClass, '[ALERT TRANSMISSION]');
          return;
        }
      } catch (e) {
        console.log(`%c[NEMS AI] Request Timed Out / Failed`, 'color: #ff0055; font-weight: bold;', e);
      }

      enqueueSpeech(baselineText, baselineText, false, primaryStateClass, '[ALERT TRANSMISSION]');
    }

    async function dispatchBatchRecoveries(newRecoveries) {
      if (!newRecoveries || newRecoveries.length === 0) return;

      if (!isInitialLoad) playTacticalSound('recovery');

      if (newRecoveries.length === 1) {
        const item = newRecoveries[0];
        const hostAlias = item.alias || item.host;
        const baselineText = item.checkName === 'HOST DOWN' ? `Server ${hostAlias} is back online.` : `Service ${item.checkName} on ${hostAlias} has returned to normal operational status.`;
        dispatchSpeechEvent('recovery', baselineText, { host_name: item.host, host_alias: item.alias || item.host, service_description: item.checkName, state: 0, plugin_output: item.msg });
        return;
      }

      const hostsAffected = new Set(newRecoveries.map(r => r.alias || r.host));
      const hostListStr = Array.from(hostsAffected).slice(0, 3).join(', ') + (hostsAffected.size > 3 ? ' and others' : '');
      const baselineText = `${newRecoveries.length} services have recovered on ${hostListStr}.`;

      const payload = { event_type: 'batch_recoveries', baseline_text: baselineText, recoveries: newRecoveries.slice(0, 5) };
      console.log(`%c[NEMS AI] Outbound Request (Batch Recoveries)`, 'color: #00f0ff; font-weight: bold;', payload);

      try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 45000);
        const res = await fetch('/nems-api/nems-ai', { method: 'POST', headers: { 'Content-Type': 'application/json' }, signal: controller.signal, body: JSON.stringify(payload) }).then(r => r.json());
        clearTimeout(timeoutId);
        console.log(`%c[NEMS AI] Synthesis Response`, 'color: #00ff88; font-weight: bold;', res);

        if (res && res.success && res.ai_active && res.speech_text) {
          enqueueSpeech(res.display_text, res.speech_text, true, 'ok', '[RECOVERY TRANSMISSION]');
          return;
        }
      } catch (e) {
        console.log(`%c[NEMS AI] Request Timed Out / Failed`, 'color: #ff0055; font-weight: bold;', e);
      }

      enqueueSpeech(baselineText, baselineText, false, 'ok', '[RECOVERY TRANSMISSION]');
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

    // --- HIGH-RESOLUTION 48-SEGMENT BINARY HISTORICAL STRIP ENGINE (30-MIN BLOCKS) ---
    const HOST_30MIN_KEY = 'nems_noc_host_48seg_history_v1';
    const SEGMENT_COUNT = 48; // 48 * 30 mins = 24 Hours
    const THIRTY_MIN_MS = 30 * 60 * 1000;

    function updateAndGetHost24hBarHtml(hostName, currentStateCode) {
      let historyMap = {};
      try {
        const stored = localStorage.getItem(HOST_30MIN_KEY);
        if (stored) historyMap = JSON.parse(stored);
      } catch(e) {}

      const currentSlot = Math.floor(Date.now() / THIRTY_MIN_MS);

      if (!historyMap[hostName] || typeof historyMap[hostName].lastSlot !== 'number' || !Array.isArray(historyMap[hostName].segments) || historyMap[hostName].segments.length !== SEGMENT_COUNT) {
        historyMap[hostName] = {
          lastSlot: currentSlot,
          segments: new Array(SEGMENT_COUNT).fill('seg-ok')
        };
      }

      let hostData = historyMap[hostName];

      // Shift array left as 30-min slots pass
      if (hostData.lastSlot < currentSlot) {
        const slotsPassed = Math.min(SEGMENT_COUNT, currentSlot - hostData.lastSlot);
        for (let i = 0; i < slotsPassed; i++) {
          hostData.segments.shift();
          hostData.segments.push('seg-ok');
        }
        hostData.lastSlot = currentSlot;
      }

      // Any non-zero state code (Warning, Critical, Unknown) marks the current slot (far right) as BAD (Red)
      if (currentStateCode !== 0) {
        hostData.segments[SEGMENT_COUNT - 1] = 'seg-crit';
      }

      try {
        localStorage.setItem(HOST_30MIN_KEY, JSON.stringify(historyMap));
      } catch(e) {}

      // Build 48 segment HTML strip
      const segmentsHtml = hostData.segments.map((cls, idx) => {
        const slotsAgo = (SEGMENT_COUNT - 1) - idx;
        const minsAgo = slotsAgo * 30;
        let timeLabel = 'Current 30m';
        if (minsAgo > 0) {
          const hrs = (minsAgo / 60).toFixed(minsAgo % 60 === 0 ? 0 : 1);
          timeLabel = `${hrs}h ago`;
        }
        return `<div class="history-bar-segment ${cls}" title="${timeLabel}: ${cls === 'seg-ok' ? 'OK' : 'PROBLEM DETECTED'}"></div>`;
      }).join('');

      return `<div class="history-bar-container" title="24-Hour Historical Uptime (48 x 30m Blocks)">${segmentsHtml}</div>`;
    }

    // --- INFRASTRUCTURE HEALTH TIMELINE CHART (CONTINUOUS OVERALL HEALTH SLA) ---
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
          if (parsed && Array.isArray(parsed.timestamps) && Array.isArray(parsed.data) && parsed.data.length > 0) return parsed;
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

      let avgHealth = 100;
      if (hist.data.length > 0) {
        const sum = hist.data.reduce((acc, v) => acc + v, 0);
        avgHealth = Math.round(sum / hist.data.length);
      }
      return { labels, data: hist.data, avgHealth };
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
      const rowHeight = 108;
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
        grid.innerHTML = pageHosts.map(h => {
          let svcText = '';
          if (h.svcs.length === 0) svcText = 'MONITORING UPTIME';
          else if (h.svcs.length === 1) svcText = '1 SERVICE MONITORED';
          else svcText = `${h.svcs.length} SERVICES MONITORED`;

          let stateCodeNum = 0;
          if (h.compositeState === 'warn' || h.compositeState === 'unk') stateCodeNum = 1;
          if (h.compositeState === 'crit') stateCodeNum = 2;

          const historyBarHtml = updateAndGetHost24hBarHtml(h.name, stateCodeNum);

          return `
            <div class="node-card ${h.compositeState}">
              <div class="node-header">
                <div class="node-name" title="${h.alias || h.name}">${h.alias || h.name}</div>
                <span class="node-status-badge">${h.statusText}</span>
              </div>
              <div class="node-card-bottom">
                ${historyBarHtml}
                <div class="node-card-meta-row">
                  <span>${svcText}</span>
                  <span>${h.address || 'LOCAL'}</span>
                </div>
              </div>
            </div>
          `;
        }).join('');
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
    let uiAborted = false;

    async function fetchNemsData() {
      try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 8000); // Fast UI Freeze Protection

        const [hostsRes, svcsRes, loadRes] = await Promise.all([
          fetch('/nems-api/hosts?Columns=name,alias,state,address,plugin_output,last_state_change', { signal: controller.signal }).then(r => r.json()),
          fetch('/nems-api/services?Columns=host_name,description,state,plugin_output,perf_data,last_state_change', { signal: controller.signal }).then(r => r.json()),
          fetch('?getload=1', { signal: controller.signal }).then(r => r.json()).catch(() => ({ load: [0,0,0], cores: 1 }))
        ]);

        clearTimeout(timeoutId);

        if (!hostsRes.success || !svcsRes.success) throw new Error("API Structure Failure");

        consecutiveFailures = 0;
        if (uiAborted) {
          uiAborted = false;
          document.getElementById('connection-lost-overlay').classList.remove('active');
        }

        const hosts = hostsRes.content || [];
        const services = svcsRes.content || [];

        // Detect Added or Removed Hosts
        if (trackedHostMap !== null) {
          const currentHostNames = new Set(hosts.map(h => h.name));
          hosts.forEach(h => {
            if (!trackedHostMap.has(h.name)) enqueueSpeech(`New host ${h.alias || h.name} has been added to NEMS monitoring.`, null, false, 'ok', '[SYSTEM NOTICE]');
          });
          trackedHostMap.forEach((alias, name) => {
            if (!currentHostNames.has(name)) enqueueSpeech(`Host ${alias || name} was removed from NEMS monitoring.`, null, false, 'warn', '[SYSTEM NOTICE]');
          });
        }
        trackedHostMap = new Map(hosts.map(h => [h.name, h.alias || h.name]));

        // Map Composite States
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
            statusText = '? SERVICE STATE UNKNOWN';
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

        currentOverallSla = overallSla;

        // Overall Health Threshold Theme Shift
        if (overallSla < 75) updateElectrifiedTheme('crit');
        else if (overallSla < 90) updateElectrifiedTheme('warn');
        else updateElectrifiedTheme('ok');

        // Timeline Update (Continuous Overall SLA Percentage Curve)
        const updatedHist = updateSlaHistory(overallSla);
        slaChart.data.labels = updatedHist.labels;
        slaChart.data.datasets[0].data = updatedHist.data;
        
        const slaColor = getHealthThresholdColor(overallSla);
        slaChart.data.datasets[0].borderColor = slaColor;
        
        let fillColor = 'rgba(0, 240, 255, 0.12)';
        if (slaColor === '#00f0ff') fillColor = 'rgba(0, 240, 255, 0.12)';
        else if (slaColor === '#ffaa00') fillColor = 'rgba(255, 170, 0, 0.12)';
        else if (slaColor === '#ff0055') fillColor = 'rgba(255, 0, 85, 0.12)';
        
        slaChart.data.datasets[0].backgroundColor = fillColor;
        slaChart.update('none');

        // Render Sci-Fi HUD Radial Gauges
        renderSciFiGauge('gauge-overall', overallSla);
        renderSciFiGauge('gauge-avg', updatedHist.avgHealth);
        renderSciFiGauge('gauge-host', hostSla);
        renderSciFiGauge('gauge-svc', svcSla);

        // Smart Telemetry Meters & Server CPU Load
        const perfContainer = document.getElementById('perf-widgets');
        let perfHtml = '';

        const loadArray = loadRes.load || [0,0,0];
        const cores = loadRes.cores || 1;
        const load1 = Array.isArray(loadArray) && loadArray.length >= 1 ? parseFloat(loadArray[0]) : 0;
        const load15 = Array.isArray(loadArray) && loadArray.length >= 3 ? parseFloat(loadArray[2]) : 0;
        
        const load1Pct = (load1 / cores) * 100;
        const load1Color = load1Pct > 90 ? 'var(--crit)' : (load1Pct > 70 ? 'var(--warn)' : 'var(--static-cyan)');
        const load1Fill = Math.min(100, load1Pct);
        
        const load15Pct = (load15 / cores) * 100;
        const load15Color = load15Pct > 90 ? 'var(--crit)' : (load15Pct > 70 ? 'var(--warn)' : 'var(--green)');
        const load15Fill = Math.min(100, load15Pct);

        perfHtml += `
          <div class="perf-widget">
            <div class="perf-title">🖥 NEMS Server CPU Load</div>
            <div class="dual-meter-row">
              <div class="meter-sub-box">
                <span style="color:${load1Color}; font-weight:bold;">Current: ${load1.toFixed(2)} (${load1Pct.toFixed(0)}%)</span>
                <div class="meter-bar-track"><div class="meter-bar-fill" style="width: ${load1Fill}%; background:${load1Color};"></div></div>
              </div>
              <div class="meter-sub-box">
                <span style="color:${load15Color}; font-weight:bold;">Average: ${load15.toFixed(2)} (${load15Pct.toFixed(0)}%)</span>
                <div class="meter-bar-track"><div class="meter-bar-fill" style="width: ${load15Fill}%; background:${load15Color};"></div></div>
              </div>
            </div>
          </div>`;

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
                  <span style="color:var(--static-cyan); font-weight:bold;">↓ ${dl.toFixed(1)} Mbps</span>
                  <div class="meter-bar-track"><div class="meter-bar-fill" style="width: ${Math.min(100, (dl/1000)*100)}%;"></div></div>
                </div>
                <div class="meter-sub-box">
                  <span style="color:var(--green); font-weight:bold;">↑ ${ul.toFixed(1)} Mbps</span>
                  <div class="meter-bar-track"><div class="meter-bar-fill" style="width: ${Math.min(100, (ul/1000)*100)}%; background:var(--green);"></div></div>
                </div>
              </div>
            </div>`;
        }

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
                <span style="color:var(--static-cyan); font-weight:bold;">${humidVal.toFixed(1)}% Humidity</span>
                <div class="meter-bar-track"><div class="meter-bar-fill" style="width: ${Math.min(100, humidVal)}%;"></div></div>
              </div>`;
          }
          perfHtml += `</div></div>`;
        }

        perfContainer.innerHTML = perfHtml;

        // Active Incidents Processing
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

        // STRICT CELEBRATION SEQUENCING
        if (previousOverallHealth !== null && previousOverallHealth < 100 && overallSla === 100 && incidents.length === 0) {
          window.celebrationPending = true;
          dispatchSpeechEvent('celebration', "Sensors report infrastructure health has reached 100 percent. Outstanding work team.", {});
        }
        previousOverallHealth = overallSla;

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
            if (!isInitialLoad) newIncidentsToAnnounce.push(inc);
          }
        });

        if (newIncidentsToAnnounce.length > 0) {
          dispatchBatchIncidents(newIncidentsToAnnounce, hDown);
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

        // Lift initial load lock so subsequent state changes trigger audio alerts
        isInitialLoad = false;

      } catch (e) {
        consecutiveFailures++;
        if (consecutiveFailures >= 2) {
          uiAborted = true;
          document.getElementById('connection-lost-overlay').classList.add('active');
        }
      }
    }

    fetchNemsData();
    setInterval(fetchNemsData, 5000);
  </script>
</body>
</html>
