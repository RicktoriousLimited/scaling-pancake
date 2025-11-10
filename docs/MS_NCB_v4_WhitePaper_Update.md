# MS-NCB v4 White Paper Addendum

This addendum complements the original PDF white paper by expanding on the practical aspects of
running, testing, and extending the MS-NCB v4 conversational engine. It focuses on reproducible
engineering steps, regression coverage, and human-readable training corpora for the language stack.

## 1. Engine Overview

MS-NCB v4 remains a stack-based regression engine where each stack is composed of lightweight linear
regressors that accumulate state over time through configurable decay factors. The
`MultiModal` orchestrator manages:

- **Stack instantiation** from `data/config.json`, wiring input/output dimensionality and decay.
- **Signal propagation** according to `data/interconnect.json`, allowing higher-level stacks to
  consume activations produced by upstream stacks.
- **Incremental learning** through the `/api/train.php` endpoint, which delegates to
  `MultiModal::trainStack()`.
- **Persistence** of updated weights via `MultiModal::save()`.

### 1.1 Inter-stack propagation update

The propagation bridge has been hardened so that weighted activations emitted by a source stack are
preserved even when the target stack has a larger input dimensionality. Incoming signals now slide
into the tail of the target input vector instead of being truncated, ensuring consistent behaviour
whether or not the target previously emitted state.

## 2. Testing Strategy

A lightweight but comprehensive regression suite lives under `tests/` and can be executed with:

```bash
php tests/run.php
```

The harness asserts the following:

1. **Regression convergence** – single neuron updates reduce absolute error on the training sample and
   constructor preconditions reject invalid dimensions.
2. **Stack decay dynamics** – deterministic regressors confirm that past activations decay as expected.
3. **Inter-stack propagation** – the orchestrator passes weighted activations across stacks without loss
   and applies incremental training updates.
4. **Persistence guarantees** – `MultiModal::save()` round-trips configuration payloads to disk.
5. **Conversation archival** – `ConversationStore` sanitises identifiers and faithfully reloads saved
   transcripts.

These tests are framework-agnostic and rely solely on stock PHP, which keeps continuous integration
simple and avoids introducing additional runtime dependencies.

## 3. Human-readable language training corpus

`data/training_language.json` now exposes the FFT lattice samples in a dual format:

- The `prompt` and `targetText` fields communicate the natural-language meaning of each sample.
- The accompanying `explanation` field clarifies how the encoding relates to the lattice.
- The training console now derives FFT vectors on the fly, so only the human-readable text needs to
  be stored alongside each sample.

Two illustrative samples ship by default:

1. **"language sample" → "fft resonance"** – the historical calibration pair that demonstrates the
   base encoding.
2. **"hello there" → "warm acknowledgement"** – a new greeting-to-reply pair encoded via the same
   FFT process, ideal for manual experimentation or demonstrations.

## 4. Workflow recommendations

- **Local iteration** – run `php -S localhost:8080` and use `training.html` to inspect stack metadata
  and replay sample training payloads. The updated language corpus is auto-loaded for the language
  stack to aid exploration.
- **Continuous validation** – add `php tests/run.php` to your CI jobs or Git hooks to guarantee that
  stack propagation, learning, and persistence semantics stay intact as you extend the codebase.
- **Extensibility** – when designing new stacks, pair each numeric sample with a `prompt` and
  `targetText` narrative. This keeps datasets self-describing and makes it easier for non-technical
  collaborators to review proposed training batches.

## 5. Future work

- Extend the training harness to replay real-world transcripts through the regression suite.
- Document best practices for deriving FFT encodings from arbitrary corpora, including recommended
  window sizes and normalisation pipelines.
- Introduce automated drift detection that flags when newly trained weights diverge significantly from
  the shipped baselines.

For high-level context, consult the original PDF in `docs/MS_NCB_v4_WhitePaper_RicktoriousLimited.pdf`.
This addendum should be treated as the living technical companion that evolves alongside the codebase.
