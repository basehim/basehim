<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<div class="mb-5 flex items-start justify-between gap-4 flex-wrap">
    <div>
        <h2 class="text-xl font-semibold text-slate-900">Desktop Agents</h2>
        <p class="text-sm text-slate-500">Circuits-DIY Engine desktop apps connected to this site. Send commands and manage desktop modules.</p>
    </div>
    <button type="button" onclick="document.getElementById('pairing-panel').classList.toggle('hidden')"
            class="px-3 py-2 text-sm bg-slate-700 hover:bg-slate-800 text-white rounded-lg font-medium inline-flex items-center gap-2">
        <?= icon('key', 'w-4 h-4') ?> Onboarding
    </button>
</div>

<div id="pairing-panel" class="hidden mb-5 bg-white border border-slate-200 rounded-xl p-5">
    <h3 class="font-semibold text-slate-800 mb-1">Pairing code</h3>
    <p class="text-sm text-slate-500 mb-3">When set, a new agent must present this code on first registration. Existing agents are unaffected. Leave empty for open onboarding on a trusted network.</p>
    <div class="flex items-center gap-3 flex-wrap">
        <code id="pairing-code" class="px-3 py-1.5 bg-slate-100 rounded text-sm font-mono"><?= htmlspecialchars($pairingCode ?: '— none —') ?></code>
        <button onclick="genPairing()" class="px-3 py-1.5 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Generate</button>
        <button onclick="clearPairing()" class="px-3 py-1.5 text-sm border border-slate-300 hover:bg-slate-50 rounded-lg">Clear</button>
    </div>
</div>

<div id="agents-list" class="space-y-3">
    <div class="text-sm text-slate-400">Loading agents…</div>
</div>

<!-- Commands log: shows every command sent from this page and its live status -->
<div id="commands-panel" class="mt-6 bg-white border border-slate-200 rounded-xl p-5 hidden">
    <div class="flex items-center justify-between mb-3">
        <h3 class="font-semibold text-slate-800">Commands</h3>
        <button onclick="clearCommands()" class="text-xs text-slate-400 hover:text-slate-600">Clear</button>
    </div>
    <div id="commands-list" class="space-y-2"></div>
</div>

<script>
const BASEHIM_BASE = <?= json_encode($base) ?>;
const CSRF = <?= json_encode($csrf) ?>;

async function jget(u){ const r = await fetch(BASEHIM_BASE+u,{credentials:'same-origin',headers:{'Accept':'application/json'}}); return r.json(); }
async function jpost(u,body){ const r = await fetch(BASEHIM_BASE+u,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF,'Accept':'application/json'},body:JSON.stringify(Object.assign({_csrf:CSRF},body||{}))}); return r.json(); }

let MODULES = [];

function cmdButton(agentId, cmd, label, cls){
  return `<button onclick="sendCmd(${agentId},'${cmd}')" class="px-2.5 py-1 text-xs rounded-md ${cls||'border border-slate-300 hover:bg-slate-50'}">${label}</button>`;
}

function fmtBytes(b){
  b = Number(b);
  if(!b || isNaN(b)) return '—';
  const u=['B','KB','MB','GB','TB']; let i=0;
  while(b>=1024 && i<u.length-1){ b/=1024; i++; }
  return `${b.toFixed(b>=10||i===0?0:1)} ${u[i]}`;
}

function pct1(v){ return (v==null||isNaN(v)) ? null : Math.round(Number(v)*10)/10; }

function fmtMetrics(m){
  if(!m || typeof m !== 'object') return '';
  // Accept flat fields (cpu_pct, mem_pct…) or nested ({cpu:{pct},mem:{total,used}}).
  const cpuPct = pct1((m.cpu_pct != null) ? m.cpu_pct : (m.cpu && m.cpu.pct));
  let memPct = pct1((m.mem_pct != null) ? m.mem_pct : null);
  if(memPct == null && m.mem && m.mem.total){
    const used = m.mem.used != null ? m.mem.used : (m.mem.total - (m.mem.available||0));
    memPct = Math.round(used / m.mem.total * 1000)/10;
  }
  const cores = m.cpu_cores != null ? m.cpu_cores : (m.cpu && m.cpu.cores);
  const memTotal = m.mem_total != null ? m.mem_total : (m.mem && m.mem.total);
  const parts = [];
  if(cpuPct != null) parts.push(`CPU ${cpuPct}%${cores?` · ${cores} cores`:''}`);
  else if(cores != null) parts.push(`${cores} cores`);
  if(memPct != null) parts.push(`MEM ${memPct}%${memTotal?` of ${fmtBytes(memTotal)}`:''}`);
  else if(memTotal != null) parts.push(`MEM ${fmtBytes(memTotal)}`);
  if(m.load_1 != null) parts.push(`load ${Number(m.load_1).toFixed(2)}`);
  return parts.length ? `<span class="text-xs text-slate-500">${parts.join(' · ')}</span>` : '';
}

function agentCard(a){
  const dot = a.online ? '<span class="inline-block w-2 h-2 rounded-full bg-green-500"></span>' : '<span class="inline-block w-2 h-2 rounded-full bg-slate-300"></span>';
  const seen = a.last_seen_at ? new Date(a.last_seen_at.replace(' ','T')).toLocaleString() : 'never';
  const metrics = fmtMetrics(a.metrics || {});
  const modBtns = MODULES.map(mod =>
    `<button onclick="installMod(${mod.id},${a.id})" class="px-2 py-1 text-xs rounded border border-slate-200 hover:bg-slate-50" title="Install ${mod.name}">↳ ${mod.name} v${mod.version}</button>`
  ).join(' ');
  return `<div class="bg-white border border-slate-200 rounded-xl p-4" data-agent-id="${a.id}" data-agent-name="${escapeHtml(a.name)}">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div class="flex items-center gap-2">
        ${dot}
        <span class="font-semibold text-slate-800">${escapeHtml(a.name)}</span>
        <span class="text-xs text-slate-400">${escapeHtml(a.platform||'')} ${escapeHtml(a.hostname||'')}</span>
      </div>
      <div class="flex items-center gap-3">${metrics}<span class="text-xs text-slate-400">seen ${seen}</span></div>
    </div>
    <div class="mt-3 flex items-center gap-2 flex-wrap">
      ${cmdButton(a.id,'restart','Restart')}
      ${cmdButton(a.id,'shutdown','Shutdown','border border-red-200 text-red-600 hover:bg-red-50')}
      ${cmdButton(a.id,'lock','Lock')}
      ${cmdButton(a.id,'sleep','Sleep')}
      ${cmdButton(a.id,'sync-now','Sync now')}
      ${cmdButton(a.id,'metrics-snapshot','Metrics')}
    </div>
    ${MODULES.length ? `<div class="mt-3 pt-3 border-t border-slate-100"><div class="text-xs text-slate-400 mb-1">Push module:</div><div class="flex items-center gap-2 flex-wrap">${modBtns}</div></div>` : ''}
  </div>`;
}

function escapeHtml(s){ return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

async function refresh(){
  const d = await jget('/admin/agents/api/list.json');
  if(!d.ok){ document.getElementById('agents-list').innerHTML = '<div class="text-sm text-red-500">'+escapeHtml(d.error||'Failed')+'</div>'; return; }
  MODULES = d.modules || [];
  const host = document.getElementById('agents-list');
  host.innerHTML = d.agents.length ? d.agents.map(agentCard).join('') :
    '<div class="bg-white border border-slate-200 rounded-xl p-6 text-center text-sm text-slate-500">No agents yet. Install Circuits-DIY Engine on a PC and pair it with this site.</div>';
}

// --- Commands log ---------------------------------------------------------
const CMD_LOG = [];   // {id, agentId, agentName, cmd, status, detail, at}

function agentName(agentId){
  const el = document.querySelector('[data-agent-id="'+agentId+'"]');
  return el ? el.getAttribute('data-agent-name') : ('agent #'+agentId);
}

function renderCommands(){
  const panel = document.getElementById('commands-panel');
  const list = document.getElementById('commands-list');
  if(!CMD_LOG.length){ panel.classList.add('hidden'); return; }
  panel.classList.remove('hidden');
  list.innerHTML = CMD_LOG.slice().reverse().map(function(c){
    const badge = statusBadge(c.status);
    const when = c.at ? new Date(c.at).toLocaleTimeString() : '';
    return `<div class="flex items-center justify-between gap-3 text-sm border border-slate-100 rounded-lg px-3 py-2">
      <div class="flex items-center gap-2 min-w-0">
        <span class="font-mono text-xs text-slate-400">#${c.id||'—'}</span>
        <span class="font-medium text-slate-700">${escapeHtml(c.cmd)}</span>
        <span class="text-slate-400">→ ${escapeHtml(c.agentName)}</span>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        ${c.detail?`<span class="text-xs text-slate-500">${escapeHtml(c.detail)}</span>`:''}
        ${badge}
        <span class="text-xs text-slate-300">${when}</span>
      </div>
    </div>`;
  }).join('');
}

function statusBadge(s){
  const map = {
    queued: ['Queued','bg-slate-100 text-slate-600'],
    sent:   ['Sent','bg-blue-100 text-blue-700'],
    done:   ['Done','bg-green-100 text-green-700'],
    failed: ['Failed','bg-red-100 text-red-700'],
    timeout:['No response','bg-amber-100 text-amber-700'],
  };
  const m = map[s] || map.queued;
  return `<span class="text-xs px-2 py-0.5 rounded-full ${m[1]}">${m[0]}</span>`;
}

function clearCommands(){ CMD_LOG.length = 0; renderCommands(); }

function upsertCmd(entry){
  const i = CMD_LOG.findIndex(c => c.id===entry.id && entry.id!=null);
  if(i>=0){ Object.assign(CMD_LOG[i], entry); } else { CMD_LOG.push(entry); }
  renderCommands();
}

async function sendCmd(agentId, cmd){
  if((cmd==='shutdown'||cmd==='restart') && !confirm('Send "'+cmd+'" to this agent?')) return;
  const name = agentName(agentId);
  // Optimistic row while we wait for the command id.
  const pending = { id:null, agentId, agentName:name, cmd, status:'queued', detail:'', at:Date.now() };
  CMD_LOG.push(pending); renderCommands();
  const d = await jpost('/admin/agents/api/command',{agent_id:agentId,command:cmd});
  if(!d.ok){ pending.status='failed'; pending.detail=d.error||'Could not queue'; renderCommands(); return; }
  pending.id = d.command_id; pending.status='sent'; renderCommands();
  pollCommand(d.command_id, cmd);
}

// Poll a queued command until the agent picks it up and reports a result.
async function pollCommand(id, label, tries){
  tries = tries||0;
  if(tries > 20){ upsertCmd({id, status:'timeout', detail:'No response from agent'}); return; }
  await new Promise(r=>setTimeout(r, 1500));
  const d = await jget('/admin/agents/api/command/'+id+'.json');
  if(!d.ok || !d.command){ return pollCommand(id, label, tries+1); }
  const st = d.command.status;
  if(st==='done'){
    let extra='';
    const r = d.command.result;
    if(label==='metrics-snapshot' && r){
      extra = `CPU ${pct1(r.cpu_pct)??'—'}% · MEM ${pct1(r.mem_pct)??'—'}%`;
    }
    upsertCmd({id, status:'done', detail:extra});
    refresh();
  } else if(st==='failed'){
    upsertCmd({id, status:'failed', detail:d.command.error||'unknown'});
  } else {
    upsertCmd({id, status:st});       // queued/sent → reflect + keep waiting
    pollCommand(id, label, tries+1);
  }
}
async function installMod(moduleId, agentId){
  const name = agentName(agentId);
  CMD_LOG.push({ id:null, agentId, agentName:name, cmd:'module-install', status:'queued', detail:'', at:Date.now() });
  renderCommands();
  const d = await jpost('/admin/agents/api/module/install',{module_id:moduleId,agent_id:agentId});
  const last = CMD_LOG[CMD_LOG.length-1];
  if(d.ok){ last.id=d.command_id; last.status='sent'; renderCommands(); pollCommand(d.command_id, 'module-install'); }
  else { last.status='failed'; last.detail=d.error||'Failed'; renderCommands(); }
}
async function genPairing(){
  const d = await jpost('/admin/agents/api/pairing',{action:'generate'});
  if(d.ok){ document.getElementById('pairing-code').textContent = d.pairing_code; toast('Pairing code set'); }
}
async function clearPairing(){
  const d = await jpost('/admin/agents/api/pairing',{action:'clear'});
  if(d.ok){ document.getElementById('pairing-code').textContent = '— none —'; toast('Onboarding is now open'); }
}

function toast(msg, bad){
  const t = document.createElement('div');
  t.className = 'fixed bottom-4 right-4 px-4 py-2 rounded-lg text-sm text-white shadow-lg z-50 '+(bad?'bg-red-600':'bg-slate-800');
  t.textContent = msg; document.body.appendChild(t);
  setTimeout(()=>t.remove(), 2600);
}

refresh();
setInterval(refresh, 8000); // live-ish status
</script>

<?php $this->endSection(); ?>
