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
      --panel-bg: rgba(10, 16, 28, 0.88);
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
      background-image: 
        radial-gradient(circle at 50% 50%, rgba(0,240,255,0.04) 0%, transparent 75%),
        linear-gradient(rgba(0, 240, 255, 0.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0, 240, 255, 0.03) 1px, transparent 1px);
      background-size: 100% 100%, 25px 25px, 25px 25px;
      transition: cursor 0.2s ease;
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
      box-shadow: inset 0 0 15px rgba(0,240,255,0.05);
      min-height: 0;
    }

    .left-module { margin-bottom: 12px; }
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
      background: rgba(0,240,255,0.02);
      border: 1px solid var(--border);
      padding: 8px;
      border-radius: 4px;
    }
    .gauge-box { text-align: center; }
    .gauge-val { font-size: 1.6rem; font-weight: bold; color: var(--cyan); text-shadow: 0 0 10px var(--cyan); }
    .gauge-lbl { font-size: 0.62rem; color: #8a9bb0; letter-spacing: 1px; margin-top: 2px; }

    .matrix-container {
      background: rgba(0,0,0,0.3);
      border: 1px solid var(--border);
      padding: 12px;
      overflow: hidden;
      border-radius: 4px;
      display: flex;
      flex-direction: column;
      min-height: 0;
    }
    .node-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 10px;
      transition: opacity 0.4s ease-in-out;
    }
    .node-grid.fade-out { opacity: 0; }
    
    .node-card {
      background: rgba(15, 23, 42, 0.75);
      border: 1px solid var(--border);
      padding: 10px 12px;
      border-radius: 6px;
      min-height: 100px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: all 0.3s ease;
      box-shadow: 0 4px 14px rgba(0,0,0,0.45);
    }
    .node-card.ok { border-color: rgba(0,255,136,0.4); box-shadow: inset 0 0 10px rgba(0,255,136,0.05); }
    .node-card.warn { border-color: var(--warn); background: rgba(255,170,0,0.08); }
    .node-card.crit { border-color: var(--crit); background: rgba(255,0,85,0.12); box-shadow: 0 0 15px rgba(255,0,85,0.3); animation: pulse-card 1.5s infinite alternate; }
    .node-card.unk { border-color: var(--unknown); background: rgba(168,85,247,0.08); }

    @keyframes pulse-card {
      0% { transform: scale(0.99); }
      100% { transform: scale(1.01); }
    }

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
      background: rgba(0,0,0,0.2);
      border: 1px solid var(--border);
      padding: 8px 10px 4px 10px;
      border-radius: 4px;
      display: flex;
      flex-direction: column;
      height: 100%;
      min-height: 0;
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
      background: rgba(0,0,0,0.35);
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

    .perf-widget {
      background: rgba(0,240,255,0.03);
      border: 1px solid var(--border);
      padding: 6px;
      margin-top: 4px;
      border-radius: 3px;
    }
    .perf-title { font-size: 0.6rem; color: #8a9bb0; letter-spacing: 1px; text-transform: uppercase; }
    .perf-val { font-size: 0.85rem; font-weight: bold; color: var(--cyan); margin-top: 2px; }

    .latency-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 0.68rem;
      padding: 3px 0;
      border-bottom: 1px dashed rgba(0,240,255,0.15);
    }
    .latency-row:last-child { border-bottom: none; }
    .latency-name { color: #b0c4de; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 200px; }
    .latency-ms { font-weight: bold; color: var(--warn); white-space: nowrap; margin-left: 8px; }

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

    body.has-crit { animation: ambient-alarm 2s infinite alternate; }
    @keyframes ambient-alarm {
      0% { background-color: #050811; }
      100% { background-color: #12040a; }
    }
  </style>
</head>
<body>

  <header>
    <div class="title-box">
      <h1>NEMS CENTRAL COMMAND</h1>
      <span>REAL-TIME NETWORK COMMAND CENTER</span>
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
          <div class="gauge-lbl">OVERALL SLA</div>
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
        <h2>
          <span>MONITORED HOST NODES</span>
          <span id="page-indicator" style="color:var(--cyan); font-size:0.7rem; font-weight:bold;">PAGE 1/1</span>
        </h2>
        <div class="node-grid" id="node-grid"></div>
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

    // --- PHONETIC OVERRIDES & SPOKEN UNIT/SLASH EXPANSIONS ---
    function sanitizePhonetics(phrase) {
      if (!phrase) return '';
      return phrase
        .replace(/\bNagios\b/gi, 'Noggy-ose')
        .replace(/\bSLA\b/gi, 'S L A')
        .replace(/\bNEMS\b/gi, 'Nems')
        .replace(/\bN\.E\.M\.S\.\b/gi, 'Nems')
        .replace(/\bCPU\b/gi, 'C P U')
        .replace(/\bWAN\b/gi, 'Wan')
        .replace(/\bMB\/s\b/gi, 'megabytes per second')
        .replace(/\bKB\/s\b/gi, 'kilobytes per second')
        .replace(/\bMbps\b/gi, 'megabits per second')
        .replace(/\bGbps\b/gi, 'gigabits per second')
        .replace(/\bms\b/gi, 'milliseconds')
        .replace(/\bGB\b/gi, 'gigabytes')
        .replace(/\bMB\b/gi, 'megabytes')
        .replace(/([a-zA-Z0-9]+)\s*\/\s*([a-zA-Z0-9]+)/g, '$1 and $2')
        .replace(/[*_#`"'\r\n]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    }

    // --- HELPER TO GET STATE CLASS FROM CODE ---
    function getStateClass(code) {
      if (code === 1) return 'warn';
      if (code === 2) return 'crit';
      if (code === 3) return 'unk';
      return 'ok';
    }

    // --- SYSTEM NOTIFICATIONS LOG STREAM ---
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

        if (window.speechSynthesis.paused) {
          window.speechSynthesis.resume();
        }

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

    // --- SINGLE SPEECH DISPATCHER ---
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

    // --- BATCH INCIDENT DISPATCHER ---
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

    // --- BATCH RECOVERY DISPATCHER ---
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
          host_alias: hostAlias,
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

    // --- STATIC WELCOME OVERVIEW (DYNAMIC SINGULAR/PLURAL GRAMMAR) ---
    function announceWelcomeOverview(hosts, services, incidents, overallSla) {
      const hostCount = hosts.length;
      const hostStr = hostCount === 1 ? '1 host' : `${hostCount} hosts`;
      let baselineText = '';

      if (incidents.length === 0) {
        const verb = hostCount === 1 ? 'is' : 'are';
        baselineText = `NEMS Central Command is online. All ${hostStr} ${verb} operational with an overall SLA of ${overallSla} percent.`;
      } else {
        const incCount = incidents.length;
        const verb = incCount === 1 ? 'is' : 'are';
        const incStr = incCount === 1 ? '1 active incident' : `${incCount} active incidents`;
        baselineText = `NEMS Central Command is online. Monitoring ${hostStr} with an overall SLA of ${overallSla} percent. There ${verb} ${incStr} requiring attention.`;
      }

      enqueueSpeech(baselineText, baselineText, false, 'ok', '[SYSTEM INIT]');
    }

    const spokenIncidents = new Map();
    const previousStateMap = new Map();
    let hasAnnouncedOnline = false;

    function formatElapsed(epochSec) {
      if (!epochSec || epochSec <= 0) return 'JUST NOW';
      const diff = Math.floor((Date.now() / 1000) - epochSec);
      if (diff < 60) return `${diff}s AGO`;
      if (diff < 3600) return `${Math.floor(diff/60)}m ${diff%60}s AGO`;
      if (diff < 86400) return `${Math.floor(diff/3600)}h ${Math.floor((diff%3600)/60)}h AGO`;
      return `${Math.floor(diff/86400)}d ${Math.floor((diff%86400)/3600)}h AGO`;
    }

    // --- DYNAMIC AUTO-SCALING INFRASTRUCTURE HEALTH TIMELINE ---
    const SLA_STORAGE_KEY = 'nems_noc_sla_timestamps_24h';
    const MAX_SLA_POINTS = 288; // 24 Hours capped (288 * 5-min intervals)
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
      
      return {
        timestamps: [currentBucket],
        data: [],
        lastBucket: currentBucket
      };
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

      // Dynamically reformat labels from raw timestamps to match current tv_24h setting
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
        layout: {
          padding: { bottom: 2, top: 2 }
        },
        plugins: { legend: { display: false } },
        scales: {
          y: {
            min: 0,
            max: 100,
            grid: { color: 'rgba(0, 240, 255, 0.08)' },
            ticks: { color: '#8a9bb0', font: { size: 9 }, callback: v => Math.round(v) + '%' }
          },
          x: {
            grid: { display: false },
            ticks: {
              display: true,
              color: '#8a9bb0',
              font: { size: 9 },
              maxRotation: 0,
              autoSkip: true,
              maxTicksLimit: 7
            }
          }
        }
      }
    });

    let cachedMappedHosts = [];
    let currentHostPage = 0;
    const PAGE_SIZE = 6;

    function renderPagedHostMatrix() {
      const grid = document.getElementById('node-grid');
      const indicator = document.getElementById('page-indicator');

      if (cachedMappedHosts.length === 0) {
        grid.innerHTML = `<div style="color:#8a9bb0; font-size:0.75rem;">No hosts registered</div>`;
        return;
      }

      const totalPages = Math.ceil(cachedMappedHosts.length / PAGE_SIZE);
      if (currentHostPage >= totalPages) currentHostPage = 0;

      indicator.innerText = `PAGE ${currentHostPage + 1}/${totalPages}`;

      const startIndex = currentHostPage * PAGE_SIZE;
      const pageHosts = cachedMappedHosts.slice(startIndex, startIndex + PAGE_SIZE);

      grid.classList.add('fade-out');
      setTimeout(() => {
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
        grid.classList.remove('fade-out');
      }, 200);
    }

    setInterval(() => {
      if (cachedMappedHosts.length > PAGE_SIZE) {
        currentHostPage = (currentHostPage + 1) % Math.ceil(cachedMappedHosts.length / PAGE_SIZE);
        renderPagedHostMatrix();
      }
    }, 8000);

    async function fetchNemsData() {
      try {
        const [hostsRes, svcsRes] = await Promise.all([
          fetch('/nems-api/hosts?Columns=name,alias,state,address,plugin_output,last_state_change').then(r => r.json()),
          fetch('/nems-api/services?Columns=host_name,description,state,plugin_output,perf_data,last_state_change').then(r => r.json())
        ]);

        if (!hostsRes.success || !svcsRes.success) return;

        const hosts = hostsRes.content || [];
        const services = svcsRes.content || [];

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

        renderPagedHostMatrix();

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

        // Real-Time SLA Timeline Chart Update
        const updatedHist = updateSlaHistory(overallSla);
        slaChart.data.labels = updatedHist.labels;
        slaChart.data.datasets[0].data = updatedHist.data;
        slaChart.data.datasets[0].pointRadius = updatedHist.data.length === 1 ? 3 : 0;
        slaChart.update('none');

        const perfContainer = document.getElementById('perf-widgets');
        let perfHtml = '';
        const latencyList = [];

        services.forEach(s => {
          const desc = s.description.toLowerCase();
          const output = s.plugin_output || '';
          const perf = s.perf_data || '';

          if (desc.includes('speed') || desc.includes('internet')) {
            perfHtml += `
              <div class="perf-widget">
                <div class="perf-title">⚡ WAN Speedtest</div>
                <div class="perf-val" style="font-size:0.82rem;">${output}</div>
              </div>`;
          } else if (desc.includes('temp') || desc.includes('room')) {
            perfHtml += `
              <div class="perf-widget">
                <div class="perf-title">🌡 Ambient Temperature</div>
                <div class="perf-val">${output}</div>
              </div>`;
          } else if (desc.includes('humid')) {
            perfHtml += `
              <div class="perf-widget">
                <div class="perf-title">💧 Room Humidity</div>
                <div class="perf-val" style="color:var(--green);">${output}</div>
              </div>`;
          }

          const rtaMatch = perf.match(/rta=([0-9.]+)/i) || output.match(/([0-9.]+)\s*ms/i);
          if (rtaMatch && rtaMatch[1]) {
            latencyList.push({
              checkName: s.description,
              rtt: parseFloat(rtaMatch[1])
            });
          }
        });

        if (latencyList.length > 0) {
          latencyList.sort((a, b) => b.rtt - a.rtt);
          const topLatency = latencyList.slice(0, 4);

          perfHtml += `
            <div class="perf-widget">
              <div class="perf-title" style="margin-bottom:4px;">📡 Network Latency (RTT)</div>
              ${topLatency.map(l => `
                <div class="latency-row">
                  <span class="latency-name">${l.checkName}</span>
                  <span class="latency-ms">${l.rtt.toFixed(1)} ms</span>
                </div>
              `).join('')}
            </div>`;
        }

        perfContainer.innerHTML = perfHtml;

        // Active Incidents
        const incidents = [
          ...hosts.filter(h => h.state !== 0).map(h => ({
            host: h.name,
            alias: h.alias,
            checkName: 'HOST DOWN',
            stateText: 'DOWN',
            stateCode: h.state,
            stateClass: 'crit',
            msg: h.plugin_output,
            ts: h.last_state_change
          })),
          ...services.filter(s => s.state !== 0).map(s => {
            const parentHost = hosts.find(h => h.name === s.host_name);
            let stateText = 'UNKNOWN';
            let stateClass = 'unk';
            if (s.state === 1) { stateText = 'WARNING'; stateClass = 'warn'; }
            if (s.state === 2) { stateText = 'CRITICAL'; stateClass = 'crit'; }

            return {
              host: s.host_name,
              alias: parentHost ? parentHost.alias : s.host_name,
              checkName: s.description,
              stateText: stateText,
              stateCode: s.state,
              stateClass: stateClass,
              msg: s.plugin_output,
              ts: s.last_state_change
            };
          })
        ];

        // 1. Static Welcome Overview
        if (!hasAnnouncedOnline) {
          hasAnnouncedOnline = true;
          announceWelcomeOverview(hosts, services, incidents, overallSla);
        }

        // 2. Batch Incidents Announcement (State-transition based, single notification per incident)
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

        // 3. Batch Recoveries Announcement (Clears incident state memory)
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
            newRecoveriesToAnnounce.push({
              host: s.host_name,
              alias: parentHost ? parentHost.alias : s.host_name,
              checkName: s.description,
              msg: s.plugin_output
            });
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
          document.body.classList.remove('has-crit');
          incidentContainer.innerHTML = `<div style="text-align: center; color: var(--green); margin-top: 40px; font-size: 0.85rem;">✓ ALL SYSTEMS OPERATIONAL</div>`;
        } else {
          if (incidents.some(i => i.stateClass === 'crit')) document.body.classList.add('has-crit');
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
        console.error("NEMS API fetch failure:", e);
      }
    }

    fetchNemsData();
    setInterval(fetchNemsData, 5000);
  </script>
</body>
</html>
