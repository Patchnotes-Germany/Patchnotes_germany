# Running Patchnotes with your own model

Patchnotes does not need a cloud provider. Every AI task can run on a model on your own machine —
that is a configuration change, never a code change (SPEC.md § 8.1).

There are two ways to arrange it, and the difference is only *where the model is*:

| | `execution: direct` | `execution: remote_worker` |
|---|---|---|
| Model runs | on the same machine as Patchnotes | on your computer, server elsewhere |
| Who calls whom | the server calls the model | your computer pulls jobs over HTTPS |
| Needs an open port | no | no — the worker connects out |
| Set up | a base URL | a base URL, a token, one container |

The second one exists because the natural setup for this project is a small VPS plus a decent
computer at home: the server cannot reach your machine, so the work travels the other way round.

---

## 1. Start a model server

Both of these speak the OpenAI API, which is all Patchnotes needs.

**LM Studio** — download a model in the UI, then *Developer → Start Server* (default port 1234).
It enforces JSON schemas, which is the better setup for this project.

**Ollama**

```bash
ollama serve
ollama pull <model>
```

Its OpenAI-compatible endpoint is `http://localhost:11434/v1`. It does not enforce JSON schemas, so
set `LOCAL_LLM_SUPPORTS_JSON_SCHEMA=false`: Patchnotes then validates the answers itself and asks
the model to correct them, which costs a retry now and then.

Check that the server answers and see how the models are spelled:

```bash
curl -s http://localhost:1234/v1/models | jq '.data[].id'
```

The id you get back is what goes into the configuration, verbatim.

### Which model

Choose by memory, not by hope — a model that swaps is unusably slow:

| Your RAM/VRAM | Realistic choice |
|---|---|
| 8 GB | a 4B model, quantised; fine for `law_topics`, weak for cards |
| 16 GB | a 7–14B model, quantised; usable for translations |
| 32 GB | a 27–32B model; good for translations, acceptable for analysis |
| 64 GB+ | a 70B class model; can carry the writing tasks |

For the languages of this project (German → English, Russian, Ukrainian, Turkish) prefer a model
that names multilingual training explicitly. Quality drops in that order: German and English are
easy, Russian is usually fine, Ukrainian and Turkish are where small models get noticeably worse —
check a few translations by hand before trusting a small model with `card_translate`.

Which task runs where is configuration. A sensible mixed setup is analysis and card writing on a
cloud model, translations locally: `AI_MODEL_MEDIUM` and `AI_MODEL_LOCAL` do that.

---

## 2. Model on the same machine as Patchnotes

In `.env.local`:

```dotenv
LOCAL_LLM_BASE_URL=http://host.docker.internal:1234/v1
LOCAL_LLM_SUPPORTS_JSON_SCHEMA=true
AI_MODEL_LOCAL=local:<model-id>
```

`host.docker.internal` is how a container reaches a server running on the host; `compose.yaml`
already maps it. If you run Ollama through the compose profile instead
(`docker compose --profile local-llm up -d`), use `http://ollama:11434/v1`.

Then switch the provider to direct mode in `config/packages/patchnotes.yaml`:

```yaml
patchnotes:
    ai:
        providers:
            local:
                execution: direct
```

Check what would run where — this prints the model chain of every task, and with `SAY=1` it
actually calls the first model of each chain:

```bash
make ai-ping
make ai-ping SAY=1
```

---

## 3. Model on your computer, Patchnotes on a server

Leave the provider in its default `execution: remote_worker`. Tasks routed to it become rows in the
job table; nothing in the pipeline waits for them.

**On the server**, issue a token for the machine:

```bash
make ai-worker-token N=office-imac
```

It is printed once — only its hash is stored.

**On your computer**, next to `compose.ai-worker.yaml`, create `.env.worker`:

```dotenv
AI_WORKER_SERVER_URL=https://patchnotes.example
AI_WORKER_TOKEN=pnw_…
LOCAL_LLM_BASE_URL=http://host.docker.internal:1234/v1
AI_MODEL_LOCAL=local:<model-id>
```

and start it:

```bash
docker compose -f compose.ai-worker.yaml up -d
docker compose -f compose.ai-worker.yaml logs -f
```

Without Docker, the same thing from a checkout:

```bash
php bin/console patchnotes:ai-worker \
    --server=https://patchnotes.example \
    --token=pnw_… \
    --base-url=http://localhost:1234/v1 \
    --model=local:<model-id>
```

`--once` runs a single batch and exits, which is what you want from cron or when testing.

### What happens when your computer is off

Nothing breaks, by design:

- A job claimed but not finished is **leased**. When the lease expires the job returns to the queue,
  so a closed lid costs minutes, not a lost change.
- A job nobody picks up for `ai.local_worker_fallback_after_minutes` (default 60) is run by a cloud
  provider instead, if one is configured. If none is, it keeps waiting — an offline laptop is
  usually back within a day, and waiting is better than failing.
- The worker sends a heartbeat, so the admin can see whether it is online or merely idle.

---

## 4. Cost and privacy

A local model costs nothing, and `ai.pricing` has no entry for it — which is exactly how the budget
of § 8.2 treats it. When the monthly limit is reached with `on_exceed: degrade`, everything that can
run locally keeps running.

Nothing leaves your machine when a task runs locally. The inputs are public documents in any case
(laws, gazettes, parliamentary papers) — user data never reaches a model.

---

## 5. Troubleshooting

**`make ai-ping` says "no usable model"** — the alias is empty or points at a provider without
credentials. `AI_MODEL_*` must be written `provider:model-id`, where the provider is a key under
`patchnotes.ai.providers` (`openai`, `anthropic`, `local`).

**The model id is not found** — compare it with `curl …/v1/models`; LM Studio and Ollama spell the
same model differently.

**Answers keep failing validation** — the server cannot enforce schemas. Set
`LOCAL_LLM_SUPPORTS_JSON_SCHEMA=false` so the repair loop is used, and prefer a larger model:
small models are the ones that struggle to hold a schema.

**Everything times out** — a model that does not fit in memory is swapping. Use a smaller or more
strongly quantised one; the provider timeout is already 15 minutes.

**The worker cannot reach the server** — check the URL includes the scheme, and that the token was
not revoked (`make ai-worker-tokens`).
