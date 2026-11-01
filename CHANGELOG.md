# Changelog

## 0.1.0

- First release.
- UX Turbo: `#[Broadcast]` entities go to Hibira through a `hibira`
  transport, without a Mercure hub, and `Hibira::turboStream()` sends any
  Turbo Stream to a topic.
  `turbo_stream_listen()` (UX Turbo 2 and 3) and `turbo_stream_from()` /
  `<twig:Turbo:Stream:From>` (UX Turbo 3.1+) subscribe the browser. Each
  topic is a signed private channel. A stream over the plan's event size
  limit becomes a Turbo page refresh.
- Messenger: `Hibira::publish()` dispatches `PublishEvent`, so routing it to
  an async transport sends events from a worker.
- `POST /hibira/auth` for private and presence channels
  (`ChannelAuthorizationEvent`), `POST /hibira/webhook` with signature checks
  (`WebhookReceivedEvent`), `GET /hibira/push/token` for push user tokens.
- `bin/console hibira:check`, `{{ hibira_meta_tags() }}` and a `hibira()`
  pusher-js client for your own channels.
- Symfony 6.4, 7.x and 8.x, PHP 8.1+.
