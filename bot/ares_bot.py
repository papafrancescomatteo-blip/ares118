"""
Bot Discord ARES 118: legge i ruoli del server e li comunica al sito (Render).

Cosa fa
  - ogni 30 minuti manda al sito l'elenco delle persone con i loro ruoli;
  - quando una persona e' nuova, le invia in DM username e password temporanea;
  - il comando  !resetpw @persona  (solo amministratori) genera e invia una nuova password.
Il sito decide il ruolo dai suoi ID in config.php (ROLE_MAP). Nome, matricola e grado li gestiscono
gli amministrativi dal sito: il bot non li sovrascrive.

Variabili d'ambiente da impostare sull'hosting del bot (vedi .env.esempio):
  DISCORD_TOKEN   token del bot (Discord Developer Portal -> Bot -> Reset Token)
  GUILD_ID        ID del server Discord
  SITE_URL        indirizzo del sito, senza / finale (es. https://ares118.onrender.com)
  SITE_BOT_TOKEN  deve essere IDENTICO a BOT_TOKEN impostato su Render
"""
import asyncio
import os
import time

import discord
import requests
from discord.ext import tasks

DISCORD_TOKEN = os.environ["DISCORD_TOKEN"]
GUILD_ID = int(os.environ["GUILD_ID"])
SITE_URL = os.environ["SITE_URL"].rstrip("/")
SITE_BOT_TOKEN = os.environ["SITE_BOT_TOKEN"]

intents = discord.Intents.default()
intents.members = True            # attiva "Server Members Intent" nel Developer Portal
intents.message_content = True    # serve solo per il comando !resetpw
client = discord.Client(intents=intents)


def split_name(member: discord.Member):
    """Usa il nickname del server ('Nome Cognome') se c'e', altrimenti il nome visualizzato."""
    full = (member.nick or member.display_name or member.name).strip()
    parts = full.split(" ", 1)
    return parts[0], (parts[1] if len(parts) > 1 else "")


def build_payload(guild: discord.Guild, reset_for=()):
    users = []
    for m in guild.members:
        if m.bot:
            continue
        nome, cognome = split_name(m)
        users.append({
            "discord_id": str(m.id),
            "nome": nome,
            "cognome": cognome,
            "roles": [str(r.id) for r in m.roles],
            "reset_password": str(m.id) in reset_for,
        })
    return {"users": users, "deactivate_missing": False}


def call_site(payload):
    """POST al sito. Il piano gratuito di Render si addormenta: la prima richiesta puo' metterci ~1 minuto, quindi 3 tentativi."""
    last = None
    for attempt in range(3):
        try:
            r = requests.post(
                f"{SITE_URL}/api/bot_sync.php",
                json=payload,
                headers={"Authorization": f"Bearer {SITE_BOT_TOKEN}", "X-Bot-Token": SITE_BOT_TOKEN},
                timeout=120,
            )
            if r.status_code == 401:
                raise RuntimeError("Il sito ha rifiutato il token: SITE_BOT_TOKEN deve essere uguale a BOT_TOKEN su Render")
            r.raise_for_status()
            return r.json()
        except requests.RequestException as ex:
            last = ex
            time.sleep(10 * (attempt + 1))
    raise last


async def send_credentials(guild, items):
    for it in items:
        member = guild.get_member(int(it["discord_id"]))
        if not member:
            continue
        try:
            await member.send(
                "Accesso dashboard ARES 118\n"
                f"Sito: {SITE_URL}\nUsername: **{it['username']}**\nPassword temporanea: ||{it['password']}||\n"
                "Al primo accesso ti verrà chiesto di cambiarla."
            )
        except discord.Forbidden:
            print("DM chiusi per", member)


@tasks.loop(minutes=30)
async def periodic_sync():
    guild = client.get_guild(GUILD_ID)
    if not guild:
        print("Server non trovato: controlla GUILD_ID e che il bot sia nel server")
        return
    try:
        res = await asyncio.to_thread(call_site, build_payload(guild))
    except Exception as ex:
        print("Sincronizzazione fallita:", ex)
        return
    await send_credentials(guild, res.get("created", []))
    print("sync ok:", {k: res.get(k) for k in ("updated", "deactivated")}, "nuovi:", len(res.get("created", [])))


@client.event
async def on_ready():
    print("Bot pronto:", client.user)
    if not periodic_sync.is_running():
        periodic_sync.start()


@client.event
async def on_message(msg: discord.Message):
    if msg.author.bot or not msg.guild or not msg.content.startswith("!resetpw"):
        return
    if not msg.author.guild_permissions.administrator or not msg.mentions:
        return
    target = msg.mentions[0]
    try:
        res = await asyncio.to_thread(call_site, build_payload(msg.guild, reset_for={str(target.id)}))
    except Exception as ex:
        await msg.reply(f"Errore: {ex}", mention_author=False)
        return
    await send_credentials(msg.guild, res.get("passwords", []))
    await msg.reply("Nuova password inviata in DM.", mention_author=False)


client.run(DISCORD_TOKEN)
