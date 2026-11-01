"""
Esempio di bot Discord che sincronizza il personale con il sito.
Il TUO bot puo' fare la stessa cosa: basta una POST a /api/bot_sync.php (vedi README).

pip install discord.py requests
"""
import os
import discord
import requests
from discord.ext import tasks

DISCORD_TOKEN = os.environ["DISCORD_TOKEN"]
GUILD_ID = int(os.environ["GUILD_ID"])
SITE_URL = os.environ.get("SITE_URL", "https://NOME-SERVIZIO.onrender.com")   # senza / finale
BOT_TOKEN = os.environ["SITE_BOT_TOKEN"]                                   # = BOT_TOKEN in config.php

intents = discord.Intents.default()
intents.members = True            # attiva "Server Members Intent" nel Developer Portal
client = discord.Client(intents=intents)


def split_name(member: discord.Member):
    """Usa il nickname del server ('Nome Cognome') se c'e', altrimenti il display name."""
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
            # "matricola": "AS-12",   # se la conosci, mandala qui
        })
    return {"users": users, "deactivate_missing": False}


def call_site(payload):
    r = requests.post(
        f"{SITE_URL}/api/bot_sync.php",
        json=payload,
        headers={"Authorization": f"Bearer {BOT_TOKEN}", "X-Bot-Token": BOT_TOKEN},
        timeout=60,
    )
    r.raise_for_status()
    return r.json()


async def send_credentials(guild, items):
    for it in items:
        member = guild.get_member(int(it["discord_id"]))
        if not member:
            continue
        try:
            await member.send(
                f"Accesso dashboard ARES 118\n"
                f"Sito: {SITE_URL}\nUsername: **{it['username']}**\nPassword temporanea: ||{it['password']}||\n"
                f"Al primo accesso ti verra' chiesto di cambiarla."
            )
        except discord.Forbidden:
            print("DM chiusi per", member)


@tasks.loop(minutes=30)
async def periodic_sync():
    guild = client.get_guild(GUILD_ID)
    if not guild:
        return
    res = call_site(build_payload(guild))          # sync bloccante: va bene per elenchi piccoli
    await send_credentials(guild, res.get("created", []))
    print("sync:", {k: res.get(k) for k in ("updated", "deactivated")}, "nuovi:", len(res.get("created", [])))


@client.event
async def on_ready():
    print("Bot pronto:", client.user)
    if not periodic_sync.is_running():
        periodic_sync.start()


@client.event
async def on_message(msg: discord.Message):
    # !resetpw @utente  -> solo chi ha il permesso di amministratore
    if msg.author.bot or not msg.guild or not msg.content.startswith("!resetpw"):
        return
    if not msg.author.guild_permissions.administrator or not msg.mentions:
        return
    target = msg.mentions[0]
    res = call_site(build_payload(msg.guild, reset_for={str(target.id)}))
    await send_credentials(msg.guild, res.get("passwords", []))
    await msg.reply("Nuova password inviata in DM.", mention_author=False)


client.run(DISCORD_TOKEN)
