# Changelog

## Unreleased

- Fix: Approvals and Chat API requests are signed over the decoded path, as the server checks them, so IDs with a space or `/` no longer answer 401.
- Chat API: `$hibira->chat()` returns a `Chat` client for conversations, members, messages, read receipts, system messages and a user's conversations; errors throw `ChatException`.

## 0.3.0

- Approvals: `Hibira::requestApproval()`, `approval()`, `waitForApproval()` and `replyToApproval()` ask a person for a decision and read the answer; errors throw `ApprovalException`.
- Live Chat: `{{ hibira_live_chat() }}` puts the Hibira Live Chat widget on the page; with `hibira.live_chat.identity_secret` (`HIBIRA_LIVECHAT_IDENTITY_SECRET`) the signed-in user is a verified visitor (`data-user-hash`). The `LiveChat` service gives `identity()` and `userHash()` for SPAs.

## 0.2.0

- Waiting rooms: `#[WaitingRoom('presale')]` on controllers and
  `hibira.waiting_room.paths` send visitors without a pass to the Hibira
  queue and let them in with a signed pass kept in an HttpOnly cookie (the
  `hibira_pass` request attribute). `WaitingRooms::verifyPass()` checks a
  pass anywhere.

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
