# Live board example

A Symfony 7.4 app on [FrankenPHP](https://frankenphp.dev) where messages
appear in every open window as they are posted or deleted:

Doctrine `#[Broadcast]` → `PublishEvent` on Messenger's async transport →
worker → Hibira → `turbo_stream_listen()` → Turbo Streams.

No Mercure hub runs anywhere.

## Run it

1. Create an app on [hibira.io/portal](https://hibira.io/portal) and add
   `http://localhost:8000` to its **Allowed origins**.
2. Put the credentials in `example/.env.local`:

   ```ini
   HIBIRA_APP_ID=your-app-id
   HIBIRA_APP_KEY=your-app-key
   HIBIRA_APP_SECRET=your-app-secret
   ```

3. From the repository root:

   ```sh
   docker compose -f example/compose.yaml up --build
   ```

4. Open <http://localhost:8000> in two windows and post a message.

The `web` service is FrankenPHP serving the app, the `worker` service runs
`messenger:consume async`, and both share a SQLite database in a volume.

## Files to look at

- `src/Entity/Message.php`: the `#[Broadcast]` entity
- `templates/broadcast/Message.stream.html.twig`: the Turbo Streams it sends
- `templates/message/index.html.twig`: `turbo_stream_listen('App\\Entity\\Message')`
- `config/packages/messenger.yaml`: routes `PublishEvent` to the worker
- `config/routes/hibira.yaml`: the auth and webhook routes

## Browser test

With the app running on port 8000 and Playwright installed:

```sh
BASE_URL=http://127.0.0.1:8000 node e2e/live-board.mjs
```

It posts in one window, waits for the message in a second one, then deletes
it and waits for it to disappear.

## 中文摘要

跑在 FrankenPHP 上的 Symfony 即時留言板：Doctrine `#[Broadcast]` →
Messenger async worker → Hibira → Turbo Streams，完全不需要 Mercure hub。
在 `example/.env.local` 填入 Hibira 憑證、把 `http://localhost:8000` 加入
Allowed origins，然後在專案根目錄執行
`docker compose -f example/compose.yaml up --build`，用兩個視窗開
<http://localhost:8000> 測試。
