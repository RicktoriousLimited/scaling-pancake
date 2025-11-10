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

## API

### `POST /api/respond.php`

Accepts:
```json
{
  "message": "hello world",
  "entryStack": "language" // optional, defaults to "language"
}
```

Returns a reply plus all stack activations.

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

## Extending the System

- Edit `data/config.json` to add new stacks or tune learning rates and decay factors.  
- Configure new inter-stack edges in `data/interconnect.json`.  
- Create new `training_*.json` files to seed regression updates for custom stacks.

## Documentation

See `docs/MS_NCB_v4_WhitePaper_RicktoriousLimited.pdf` for a white-paper style overview with diagrams and equations.
