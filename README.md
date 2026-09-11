# Patchnotes

**Patch notes for a country.** Patchnotes tracks every change in German legislation — federal laws and
regulations, the laws of all 16 federal states, and bills in the Bundestag — stores the law texts as
Markdown in a public git repository (every amendment is a Pull Request), explains what changed in plain
language with the help of AI, translates it into **Russian, Ukrainian, English and Turkish**, and notifies
each person about the changes that actually concern them — based on their residence status, federal state,
work, family and other profile tags.

It is built for people who live in Germany and do not read German.

> Information, not legal advice. Only the German text is legally binding.

- **Requirements:** `docs/SPEC.md` (the single source of truth)
- **Progress:** `docs/PROGRESS.md`
- **Decisions:** `docs/adr/`
- **Русская версия этого файла:** `README.ru.md`

## Status

Under active development, milestone by milestone (SPEC.md § 20). See `docs/PROGRESS.md`.

## Quick start (development)

Requires only Docker (Desktop or Engine) with Compose v2:

```bash
git clone <this repository> patchnotes && cd patchnotes
cp .env .env.local          # optional: put your real secrets into .env.local
make up                     # start the stack
make install                # dependencies, secrets, database, assets
open https://localhost/      # self-signed certificate in development
```

Useful entry points: `https://localhost/healthz` (liveness), `https://localhost/readyz` (readiness),
`http://localhost:8025` (Mailpit, captured e-mail).

Without any API keys the system still runs: sources are synchronised and the `laws` repository is
maintained; AI-dependent work (cards, translations) waits in the queue until a provider is configured.
`make demo` shows a fully working site on fixtures, without network access or keys.

## How it works

```
sources (gesetze-im-internet, BGBl, DIP, 16 state portals)
   → normalisation to Markdown (deterministic, no AI)
   → git repository "laws" (one amending act = one Pull Request)
   → AI: analysis, facts, audience tags, plain-language card, translations, verification
   → git repository "content" (facts.yml + cards in 4 languages)
   → website, search, personal feed
   → notifications: e-mail, Telegram, Web Push, iCal, RSS
```

Three repositories are involved: this one (application code, AGPL-3.0), `laws` (German law texts, CC0)
and `content` (cards and translations, CC BY 4.0). Git is the source of truth for content; MySQL is a
cache and holds user data, which never enters git.

## Documentation

| Document | Content |
|---|---|
| `docs/SPEC.md` | Full specification |
| `docs/PROGRESS.md` | Implementation progress |
| `docs/adr/` | Architecture decision records |
| `docs/sources/` | One file per data source: format, terms of use, parsing notes |
| `CLAUDE.md` | Conventions for contributors and AI sessions |

## License

AGPL-3.0 for the application code. The `laws` repository is CC0-1.0 (the law texts themselves are
*amtliche Werke*, § 5 UrhG, not protected by copyright); `content` is CC BY 4.0.
