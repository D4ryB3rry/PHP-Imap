# Benchmarks

Reproducible head-to-head benchmarks against two other PHP IMAP libraries — [`webklex/php-imap`](https://github.com/Webklex/php-imap) and [`ddeboer/imap`](https://github.com/ddeboer/imap) — live in a separate repo so you can audit the adapters and rerun them yourself: **[D4ryB3rry/imap-client-benchmarks](https://github.com/D4ryB3rry/imap-client-benchmarks)**.

## Methodology

All scenarios run against a local Dovecot in Docker, 1 warmup + 10 measured runs each in fresh PHP subprocesses, outliers >2σ dropped. Time = median ms, memory = per-scenario delta in MB. Bold marks the row winner.

**Run:** 2026-04-08 · AMD Ryzen 7 PRO 4750U · PHP 8.4.19 · Dovecot in Docker.

## Full Results

| Scenario | d4ry (ms / MB) | webklex (ms / MB) | ddeboer (ms / MB) |
|---|---:|---:|---:|
| 01 — List 10k subjects                           | **1,232.7** / 38.62 | 36,840.9 / 25.22    | 1,412.4 / **0.68** |
| 02 — Fetch text body of large-attachment message | **40.8** / 0.50     | 9,123.8 / 374.64    | 45.2 / **0.42**    |
| 03 — Save attachments of 10 messages             | **3,891.1 / 0.53**  | 69,506.1 / 1,885.71 | 5,504.9 / 128.96   |
| 04 — Search UNSEEN FROM x SINCE y                | **107.6** / 0.59    | 165.8 / 5.26        | 114.5 / **0.40**   |
| 05 — Count unseen in 10k mailbox                 | 18.8 / 0.32         | 23.7 / 0.92         | **17.5 / 0.09**    |
| 06 — Move 100 messages between folders           | 155.1 / **0.80**    | 26,761.3 / 7.18     | **153.9** / 0.93   |
| 07 — Cold open + read first 10                   | **53.8** / 0.76     | 211.5 / 6.21        | 90.9 / **0.68**    |

## How to Read These Numbers

Each library is called through a thin adapter that uses its documented defaults. Adapter code shapes results significantly — different fetch strategies, eager vs lazy modes, and connection reuse can all move numbers around. The adapters are auditable in the benchmark repo, and PRs that improve any of them are welcome.

The benchmarks exist to verify that `d4ry/imap-client` performs competitively on the workloads it was designed for.

## How to Reproduce

```bash
git clone https://github.com/D4ryB3rry/imap-client-benchmarks
cd imap-client-benchmarks
# Follow the README in that repo to spin up Dovecot and run the suite.
```

The benchmark repo contains the raw JSON history, standard deviations, and full methodology documentation.
