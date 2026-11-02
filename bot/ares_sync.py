"""
ARES 118 - modulo da aggiungere al bot (discord.py 2.x).

Cosa fa
 - ogni 30 minuti manda al sito chi ha quali ruoli: il sito crea e aggiorna gli account (nessun messaggio alle persone);
 - ogni minuto chiede al sito se un amministratore ha premuto "Invia credenziali": in quel caso manda in DM a ciascuno
   username e password temporanea e riferisce al sito chi ha ricevuto il messaggio;
 - comando per gli amministratori Discord:  !resetpw @persona  -> nuova password in DM.

Installazione
1) Metti questo file accanto al tuo bot
2) Nel tuo bot, prima di avviarlo (es. in setup_hook):   await bot.load_extension("ares_sync")
3) Variabili d'ambiente:
       ARES_SITE_URL=https://ares118.onrender.com      (senza / finale)
       ARES_BOT_TOKEN=<lo stesso valore di BOT_TOKEN su Render>
       ARES_GUILD_ID=<ID del server>
4) Intents richiesti: members (anche nel Developer Portal -> Server Members Intent) e message_content.
Dipendenza: requests   (pip install requests)
"""
import asyncio
import os
import time

import discord
import requests
from discord.ext import commands, tasks

SITE = os.environ.get("ARES_SITE_URL", "").rstrip("/")
TOKEN = os.environ.get("ARES_BOT_TOKEN", "")
GUILD_ID = int(os.environ.get("ARES_GUILD_ID", "0") or 0)


def split_name(m: discord.Member):
    full = (m.nick or m.display_name or m.name).strip()
    p = full.split(" ", 1)
    return p[0], (p[1] if len(p) > 1 else "")


def build_payload(guild, reset_for=()):
    users = []
    for m in guild.members:
        if m.bot:
            continue
        nome, cognome = split_name(m)
        users.append({"discord_id": str(m.id), "nome": nome, "cognome": cognome,
                      "roles": [str(r.id) for r in m.roles], "reset_password": str(m.id) in reset_for})
    return {"users": users, "deactivate_missing": False}


def call_site(path, payload):
    last = None
    for i in range(3):  # il piano gratuito di Render dorme: il primo tentativo puo' essere lento
        try:
            r = requests.post(f"{SITE}/api/{path}", json=payload, timeout=120,
                              headers={"Authorization": f"Bearer {TOKEN}", "X-Bot-Token": TOKEN})
            if r.status_code == 401:
                raise RuntimeError("Token rifiutato: ARES_BOT_TOKEN deve essere uguale a BOT_TOKEN su Render")
            r.raise_for_status()
            return r.json()
        except requests.RequestException as ex:
            last = ex
            time.sleep(10 * (i + 1))
    raise last


def dm_text(it):
    return (
        f"Ciao {it['nome']}, il tuo accesso alla dashboard **ARES 118** è pronto.\n\n"
        f"**Sito:** {SITE}\n**Username:** {it['username']}\n**Password temporanea:** ||{it['password']}||\n\n"
        "La password è **momentanea**: al primo accesso il sito ti chiede di scegliere la tua. Non condividerla con nessuno.\n"
        "Dopo l'accesso trovi nome, cognome, matricola e grado assegnati dagli amministrativi. "
        "Se qualcosa non torna scrivi a un amministratore."
    )


class AresSync(commands.Cog):
    def __init__(self, bot):
        self.bot = bot
        self.busy = False
        self.loop_sync.start()
        self.loop_jobs.start()

    def cog_unload(self):
        self.loop_sync.cancel()
        self.loop_jobs.cancel()

    async def sync(self, reset_for=()):
        guild = self.bot.get_guild(GUILD_ID)
        if not guild:
            raise RuntimeError("Server non trovato: controlla ARES_GUILD_ID")
        res = await asyncio.to_thread(call_site, "bot_sync.php", build_payload(guild, reset_for))
        return guild, res

    async def send_credentials(self, guild, items):
        sent, failed = [], []
        for it in items or []:
            m = guild.get_member(int(it["discord_id"]))
            try:
                if not m:
                    raise RuntimeError("membro non trovato")
                await m.send(dm_text(it))
                sent.append(it["discord_id"])
            except Exception:
                failed.append(it["discord_id"])
            await asyncio.sleep(1.2)  # evita i limiti di velocita' di Discord
        return sent, failed

    @tasks.loop(minutes=30)
    async def loop_sync(self):
        try:
            _, r = await self.sync()
            print("[ARES] sync ok: aggiornati", r.get("updated"), "nuovi", len(r.get("created", [])),
                  "ruoli da confermare", r.get("da_confermare", 0))
        except Exception as ex:
            print("[ARES] sync fallita:", ex)

    @tasks.loop(seconds=60)
    async def loop_jobs(self):
        if self.busy:
            return
        self.busy = True
        try:
            p = await asyncio.to_thread(call_site, "bot_jobs.php", {"action": "peek"})
            if not p.get("pending"):
                return
            guild, _ = await self.sync()  # prima crea gli account di chi e' appena arrivato
            job = await asyncio.to_thread(call_site, "bot_jobs.php", {"action": "claim"})
            if not job.get("job_id"):
                return
            sent, failed = await self.send_credentials(guild, job.get("items"))
            await asyncio.to_thread(call_site, "bot_jobs.php",
                                    {"action": "report", "job_id": job["job_id"], "sent": sent, "failed": failed})
            print("[ARES] credenziali inviate:", len(sent), "non consegnate:", len(failed))
        except Exception as ex:
            print("[ARES] invio credenziali fallito:", ex)
        finally:
            self.busy = False

    @loop_sync.before_loop
    @loop_jobs.before_loop
    async def _wait(self):
        await self.bot.wait_until_ready()

    @commands.Cog.listener()
    async def on_message(self, msg: discord.Message):
        if msg.author.bot or not msg.guild or not msg.content.startswith("!resetpw"):
            return
        if not msg.author.guild_permissions.administrator or not msg.mentions:
            return
        try:
            guild, res = await self.sync({str(msg.mentions[0].id)})
            items = [dict(x, nome=x["username"]) for x in res.get("passwords", [])]
            sent, _ = await self.send_credentials(guild, items)
            await msg.reply("Nuova password inviata in DM." if sent else "Non riesco a scrivere in DM a questa persona.",
                            mention_author=False)
        except Exception as ex:
            await msg.reply(f"Errore: {ex}", mention_author=False)


async def setup(bot):
    if not (SITE and TOKEN and GUILD_ID):
        print("[ARES] Modulo non attivo: imposta ARES_SITE_URL, ARES_BOT_TOKEN e ARES_GUILD_ID")
        return
    await bot.add_cog(AresSync(bot))
