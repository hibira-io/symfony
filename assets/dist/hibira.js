// The shared Hibira client for the browser.
//
// The turbo-stream controller uses it, and so can your own code:
//
//   import { hibira } from "@hibira-io/symfony-bundle/hibira.js"
//   hibira().subscribe("private-orders.42").bind("shipped", (data) => { ... })
//
// Settings come from the first turbo_stream_listen() element, or from
// {{ hibira_meta_tags() }} in your layout.

import Pusher from "pusher-js"

const META = {
  key: "hibira-key",
  host: "hibira-host",
  port: "hibira-port",
  tls: "hibira-tls",
  authEndpoint: "hibira-auth-endpoint",
}

let client = null
// Channel name → extra fields the auth endpoint needs (Turbo stream signatures).
const authParams = new Map()

function meta(name) {
  return document.querySelector(`meta[name="${META[name]}"]`)?.content
}

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.content
}

/** Adds fields to the auth request for one channel. */
export function setAuthParams(channelName, params) {
  authParams.set(channelName, params)
}

export function hibira(settings = {}) {
  if (client) return client

  const key = settings.key || meta("key")
  if (!key) {
    throw new Error("Hibira: no app key. Set HIBIRA_APP_KEY, or add {{ hibira_meta_tags() }} to your layout.")
  }

  const tlsSetting = settings.tls ?? meta("tls")
  const tls = tlsSetting !== false && tlsSetting !== "false"
  const port = Number(settings.port || meta("port") || (tls ? 443 : 80))
  const endpoint = settings.authEndpoint || meta("authEndpoint") || "/hibira/auth"

  const authorize = async ({ socketId, channelName }, callback) => {
    const body = new URLSearchParams({ socket_id: socketId, channel_name: channelName })
    for (const [name, value] of Object.entries(authParams.get(channelName) || {})) body.set(name, value)

    const headers = { Accept: "application/json" }
    const token = csrfToken()
    if (token) headers["X-CSRF-Token"] = token

    try {
      const response = await fetch(endpoint, { method: "POST", headers, body, credentials: "same-origin" })
      if (!response.ok) throw new Error(`Hibira auth for ${channelName} failed with ${response.status}`)
      callback(null, await response.json())
    } catch (error) {
      callback(error, null)
    }
  }

  client = new Pusher(key, {
    cluster: "hkg", // required by pusher-js, unused once wsHost is set
    wsHost: settings.host || meta("host") || "ws.hibira.io",
    wsPort: port,
    wssPort: port,
    forceTLS: tls,
    enabledTransports: ["ws", "wss"],
    channelAuthorization: { customHandler: authorize },
  })
  return client
}
