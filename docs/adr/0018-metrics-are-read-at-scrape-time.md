# 18. Metrics are read at scrape time, and request rate is not among them

**Status:** accepted

## Context

The queue is the part of this system that fails quietly. A worker that has died
or wedged does not raise anything — the API keeps answering, jobs keep being
enqueued, and the only symptom is a table getting longer. The same is true of the
dead letter: jobs failing repeatedly is a state nobody discovers by using the API.

The usual instrumentation for a PHP application is a counter incremented in
process and exposed on a scrape endpoint.

## Decision

`GET /metrics` in the **Prometheus text exposition format**
(`MetricsController` + `MetricsInterface` / `MetricsService`), and every value is
**read from the database when the scrape arrives** rather than accumulated in the
process.

Five gauges, chosen as "what would page somebody at night" rather than "what is
easy to count":

| Metric | What a rise means |
| --- | --- |
| `queue_jobs_pending` | the worker is gone or wedged |
| `queue_jobs_reserved` | jobs are being claimed but not finishing |
| `queue_jobs_failed_total` | jobs are exhausting their attempts and nobody has looked |
| `users_total` | growth, and a sanity check on a destructive `seeder/clear` |
| `one_time_tokens_live` | unspent reset and verification tokens outstanding |

**Read at scrape time, because PHP shares no memory between requests.** An
in-process counter is reset by every worker recycle and is per-container besides,
so it reports one replica's slice of the truth with no way to tell which. A
query answers for the whole system, and these are all cheap counting queries.

**Request rate and latency are deliberately absent.** They belong to the web
server or a sidecar, which sees every request — including the ones PHP never got
to serve. A metric that can only be emitted by a healthy application is blind
exactly when it matters.

## Consequences

- **Public and unauthenticated**, for the same reason `/health` is: a scraper is
  infrastructure and has no account. That is only acceptable because of what is
  *not* exposed — no per-user data, no identifiers, nothing an outsider could not
  infer from using the API. **A metric that leaks something means moving this
  endpoint behind the network boundary**, and that trade has to be made
  deliberately when the metric is added, not discovered later.
- **Not the JSON envelope.** Plain `yii\web\Controller` and `FORMAT_RAW`, because
  Prometheus parses a specific line format and would reject anything else — the
  same reasoning as `DocsController`.
- **The method is restricted by the route table, not `verbs()`.** A plain
  `yii\web\Controller` attaches no verb filter, so a `verbs()` declaration here
  would never be consulted; `GET metrics` in `config/url_rules.php` is what makes
  anything else a 404. Worth knowing before adding a second action.
- **Every gauge costs a query per scrape.** At Prometheus' default interval that
  is nothing, and it is the reason the selection stays short: a metric nobody
  alerts on is a query nobody needed.
- Adding a counter that must survive a request — retries attempted, say — is the
  point at which this decision runs out, and the answer is a column somewhere
  rather than process memory.
