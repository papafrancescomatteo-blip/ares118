/**
 * ARES 118 - modulo da aggiungere al bot (discord.js v14, Node 18+).
 *
 * Cosa fa
 *  - ogni 30 minuti manda al sito chi ha quali ruoli: il sito crea e aggiorna gli account (nessun messaggio alle persone);
 *  - ogni minuto chiede al sito se un amministratore ha premuto "Invia credenziali": in quel caso manda in DM
 *    a ciascuno username e password temporanea e riferisce al sito chi ha ricevuto il messaggio;
 *  - comando per gli amministratori Discord:  !resetpw @persona  -> nuova password in DM.
 *
 * Installazione
 * 1) Metti questo file accanto al tuo index.js
 * 2) Nel tuo index.js, dopo la creazione del client, aggiungi UNA riga:   require('./ares_sync')(client);
 * 3) Variabili d'ambiente (nel .env):
 *        ARES_SITE_URL=https://ares118.onrender.com      (senza / finale)
 *        ARES_BOT_TOKEN=<lo stesso valore di BOT_TOKEN su Render>
 *        ARES_GUILD_ID=<ID del server>
 * 4) Intent del client: Guilds, GuildMembers, GuildMessages, MessageContent
 *    (GuildMembers va attivato anche nel Developer Portal -> Bot -> Server Members Intent).
 */
// Se il tuo index.js non carica il file .env, lo legge il modulo (cerca .env accanto a questo file e nella cartella di avvio)
(function loadEnv() {
  if (process.env.ARES_SITE_URL && process.env.ARES_BOT_TOKEN && process.env.ARES_GUILD_ID) return;
  const fs = require('fs'), path = require('path');
  for (const dir of [__dirname, process.cwd()]) {
    try {
      const txt = fs.readFileSync(path.join(dir, '.env'), 'utf8');
      for (const line of txt.split(/\r?\n/)) {
        const m = line.match(/^\s*(ARES_[A-Z_]+)\s*=\s*(.*?)\s*$/);
        if (m && !process.env[m[1]]) process.env[m[1]] = m[2].replace(/^["']|["']$/g, '');
      }
    } catch (e) { /* nessun .env qui */ }
  }
})();

const SITE = (process.env.ARES_SITE_URL || '').replace(/\/$/, '');
const TOKEN = process.env.ARES_BOT_TOKEN || '';
const GUILD_ID = process.env.ARES_GUILD_ID || '';
const SYNC_EVERY_MIN = 30;
const JOBS_EVERY_SEC = 60;

function splitName(member) {
  const full = (member.nickname || member.displayName || member.user.username).trim();
  const i = full.indexOf(' ');
  return i < 0 ? [full, ''] : [full.slice(0, i), full.slice(i + 1)];
}

function buildPayload(members, resetFor = new Set()) {
  const users = [];
  for (const m of members.values()) {
    if (m.user.bot) continue;
    const [nome, cognome] = splitName(m);
    users.push({ discord_id: m.id, nome, cognome, roles: [...m.roles.cache.keys()], reset_password: resetFor.has(m.id) });
  }
  return { users, deactivate_missing: false };
}

async function callSite(path, payload) {
  let last;
  for (let i = 0; i < 3; i++) {       // il piano gratuito di Render dorme: il primo tentativo puo' essere lento
    try {
      const r = await fetch(`${SITE}/api/${path}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${TOKEN}`, 'X-Bot-Token': TOKEN },
        body: JSON.stringify(payload),
        signal: AbortSignal.timeout(120000),
      });
      if (r.status === 401) throw new Error('Token rifiutato: ARES_BOT_TOKEN deve essere uguale a BOT_TOKEN su Render');
      if (!r.ok) throw new Error('Sito: HTTP ' + r.status);
      return await r.json();
    } catch (e) {
      last = e;
      if (/Token rifiutato/.test(e.message)) break;
      await new Promise(res => setTimeout(res, 10000 * (i + 1)));
    }
  }
  throw last;
}

function dmText(it) {
  return (
    `Ciao ${it.nome}, il tuo accesso alla dashboard **ARES 118** è pronto.\n\n` +
    `**Sito:** ${SITE}\n**Username:** ${it.username}\n**Password temporanea:** ||${it.password}||\n\n` +
    'La password è **momentanea**: al primo accesso il sito ti chiede di scegliere la tua. Non condividerla con nessuno.\n' +
    'Dopo l\'accesso trovi nome, cognome, matricola e grado assegnati dagli amministrativi. ' +
    'Se qualcosa non torna scrivi a un amministratore.'
  );
}

function reason(e) {
  if (e && e.code === 50007) return 'DM chiusi o bloccati';
  if (e && /membro non trovato/.test(e.message)) return 'non è nel server';
  return String((e && e.message) || e).slice(0, 60);
}

/** Manda i DM; ritorna { sent: [ids], failed: [ids], errors: {id: motivo} } */
async function sendCredentials(guild, items) {
  const sent = [], failed = [], errors = {};
  for (const it of items || []) {
    const member = await guild.members.fetch(it.discord_id).catch(() => null);
    try {
      if (!member) throw new Error('membro non trovato');
      await member.send(dmText(it));
      sent.push(it.discord_id);
    } catch (e) {
      failed.push(it.discord_id);
      errors[it.discord_id] = reason(e);
      console.log('[ARES] DM non inviato a', it.username, '-', errors[it.discord_id]);
    }
    await new Promise(res => setTimeout(res, 1200));   // evita i limiti di velocita' di Discord
  }
  return { sent, failed, errors };
}

module.exports = function init(client) {
  if (!SITE || !TOKEN || !GUILD_ID) {
    console.log('[ARES] Modulo non attivo: imposta ARES_SITE_URL, ARES_BOT_TOKEN e ARES_GUILD_ID');
    return;
  }

  async function sync(resetFor) {
    const guild = client.guilds.cache.get(GUILD_ID);
    if (!guild) throw new Error('Server non trovato: controlla ARES_GUILD_ID');
    const members = await guild.members.fetch({ time: 60000 });
    const res = await callSite('bot_sync.php', buildPayload(members, resetFor));
    return { guild, res };
  }

  const runSync = () => sync().then(
    ({ res }) => console.log('[ARES] sync ok: aggiornati', res.updated, 'nuovi', (res.created || []).length, 'ruoli da confermare', res.da_confermare || 0),
    e => console.log('[ARES] sync fallita:', e.message)
  );

  let busy = false;
  async function runJobs(manual) {
    if (busy) return 'occupato';
    busy = true;
    try {
      const p = await callSite('bot_jobs.php', { action: 'peek' });
      if (!p.pending) return 'nessun invio richiesto dal sito';
      const { guild } = await sync();                       // prima crea gli account di chi e' appena arrivato
      const job = await callSite('bot_jobs.php', { action: 'claim' });
      if (!job.job_id) return 'nessun destinatario';
      const r = await sendCredentials(guild, job.items);
      await callSite('bot_jobs.php', { action: 'report', job_id: job.job_id, sent: r.sent, failed: r.failed, errors: r.errors });
      console.log('[ARES] credenziali inviate:', r.sent.length, 'non consegnate:', r.failed.length);
      return `inviati ${r.sent.length}, non consegnati ${r.failed.length}`;
    } catch (e) {
      console.log('[ARES] invio credenziali fallito:', e.message);
      return 'errore: ' + e.message;
    } finally {
      busy = false;
    }
  }

  // parte una sola volta, con qualunque versione di discord.js ('ready' nelle vecchie, 'clientReady' nelle nuove)
  const start = () => {
    if (client.__aresStarted) return;
    client.__aresStarted = true;
    runSync();
    setInterval(runSync, SYNC_EVERY_MIN * 60 * 1000);
    setInterval(runJobs, JOBS_EVERY_SEC * 1000);
  };
  console.log('[ARES] Modulo attivo, sito:', SITE);
  client.once('clientReady', start);
  client.once('ready', start);
  if (client.isReady && client.isReady()) start();

  client.on('messageCreate', async msg => {
    if (msg.author.bot || !msg.guild || !msg.member || !msg.member.permissions.has('Administrator')) return;
    // !ares-stato : controlla il collegamento con il sito
    if (msg.content.startsWith('!ares-stato')) {
      try { const p = await callSite('bot_jobs.php', { action: 'peek' }); await msg.reply(`Collegato al sito. Invii in attesa: ${p.pending ? 'sì' : 'no'}.`); }
      catch (e) { await msg.reply('Non riesco a parlare col sito: ' + e.message); }
      return;
    }
    // !ares-invia : esegue subito l'invio credenziali richiesto dal sito, senza aspettare il minuto
    if (msg.content.startsWith('!ares-invia')) {
      await msg.reply('Controllo le richieste del sito…');
      await msg.reply('Esito: ' + await runJobs(true));
      return;
    }
    if (!msg.content.startsWith('!resetpw') || !msg.mentions.users.size) return;
    try {
      const { guild, res } = await sync(new Set([msg.mentions.users.first().id]));
      const r = await sendCredentials(guild, (res.passwords || []).map(x => ({ ...x, nome: x.username })));
      await msg.reply(r.sent.length ? 'Nuova password inviata in DM.' : 'Non riesco a scrivere in DM a questa persona.');
    } catch (e) {
      await msg.reply('Errore: ' + e.message);
    }
  });
};
