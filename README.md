# MS-NCB v4

MS-NCB v4 is a modular, regression-driven conversational AI framework written in PHP and vanilla web technologies. The engine is organised around horizontally and vertically stackable regressors that exchange signals through weighted inter-stack connections.

## Project Layout

```
api/
  core/
    Regression.php     # Incremental linear regressor
    Stack.php          # Manages regression matrices and temporal state
    MultiModal.php     # Orchestrates propagation and training
  respond.php          # REST endpoint generating responses
  train.php            # Endpoint for incremental learning
js/
  app.js               # Minimalistic chat UI logic
index.html             # Front-end entry point
/data
  config.json          # Stack definitions, learning rates, decay factors
  interconnect.json    # Weighted graph describing stack communication
  training_*.json      # Sample datasets per stack
/docs
  MS_NCB_v4_WhitePaper_RicktoriousLimited.pdf
```

## Running the Project

1. Start the built-in PHP server from the repository root:
   ```bash
   php -S localhost:8080
   ```
2. Open [http://localhost:8080](http://localhost:8080) in a browser to interact with the chat interface.
3. Visit [`/training.html`](http://localhost:8080/training.html) for the incremental training console. It exposes configuration metadata, sample datasets, JSON validation, and a direct client for the `/api/train.php` endpoint.

## API

### `POST /api/respond.php`

Accepts:
```json
{
  "message": "hello world",
  "entryStack": "language",       // optional, defaults to "language"
  "conversationId": "thread-42",  // optional, persists regression state per thread
  "history": [                     // optional, seed with prior dialogue turns
    { "role": "user", "message": "hi there" },
    { "role": "assistant", "message": "hello!" }
  ]
}
```

Returns a reply plus all stack activations.

Providing a `conversationId` lets the engine recall the decayed state of previous turns. When omitted the
request is treated as stateless, unless a `history` seed is provided—in that case a new identifier will be generated
and echoed back in the response so the conversation can be continued.

### `POST /api/train.php`

Use to apply incremental regression updates.

```json
{
  "stack": "language",
  "samples": [
    { "input": [..], "target": [..] }
  ]
}
```

On success the updated weights are saved back to `data/config.json`.

## Training Data

Sample datasets inside `data/` illustrate how to structure incremental updates per stack. They can be replayed by posting to `/api/train.php`.

- `training_*.json` files: Raw regression pairs for the built-in stacks (language, context, emotion, audio).
- `sample_payloads.json`: Ready-to-send payloads that show how to call `/api/respond.php` with a conversational input (including history seeding) and `/api/train.php` with a miniature training batch.

To try them out, issue:

```bash
curl -X POST http://localhost:8080/api/respond.php \
  -H 'Content-Type: application/json' \
  -d @<(jq '.respondRequest.body' data/sample_payloads.json)

curl -X POST http://localhost:8080/api/train.php \
  -H 'Content-Type: application/json' \
  -d @<(jq '.trainRequest.body' data/sample_payloads.json)
```

The example requests are sized to remain compatible with the default `language` stack definition in `data/config.json`.

## Conversation Memory

- Conversations are persisted under `data/conversations/` using the `conversationId` you supply.
- Seed legacy transcripts by passing a `history` array; the request will blend those turns into the current encoding and
  return the generated `conversationId` for future calls.
- Use the "Reset" button in the chat UI to clear the browser's conversation key and begin a fresh session without
  deleting the archived transcripts on disk.

## Extending the System

- Edit `data/config.json` to add new stacks or tune learning rates and decay factors.
- Configure new inter-stack edges in `data/interconnect.json`.
- Create new `training_*.json` files to seed regression updates for custom stacks.

## FFT n-gram interpolation (language stack)

- Each latin letter is now assigned a unique binary-spaced base frequency. Sliding trigrams sum these bases and are converted to
  spectra via an FFT, forming the language stack's interpolation lattice.
- The chat endpoint (`api/respond.php`) performs the FFT-based encoding for every request and uses an inverse transform to decode
  stack responses back into symbolic text snippets.
- The training console auto-loads the refreshed `data/training_language.json` sample, which demonstrates how the phrase "language
  sample" maps to the response target "fft resonance" using the new encoding.

## Documentation

See `docs/MS_NCB_v4_WhitePaper_RicktoriousLimited.pdf` for a white-paper style overview with diagrams and equations.
