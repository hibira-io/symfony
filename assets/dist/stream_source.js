// Turbo Streams over Hibira, shared by the turbo-stream Stimulus controller
// (turbo_stream_listen) and the <hibira-turbo-stream-source> element
// (turbo_stream_from and <twig:Turbo:Stream:From>, UX Turbo 3.1+).

import { connectStreamSource, disconnectStreamSource } from "@hotwired/turbo"
import { hibira, setAuthParams } from "./hibira.js"

const EVENT = "turbo-stream"
// Keeps a channel across a Turbo visit to a page that listens to it too.
const UNSUBSCRIBE_DELAY = 1000

// Channel name → { channel, users, timer }
const channels = new Map()

/**
 * Subscribes to each stream's channel and passes every Turbo Stream to
 * Turbo. Returns a function that undoes it.
 *
 * @param {{channel: string, signature: string}[]} streams
 * @param {object} settings  key, host, port, tls, authEndpoint
 * @param {(connected: boolean) => void} onState
 */
export function listen(streams, settings, onState = () => {}) {
  const client = hibira(settings)
  const source = new EventTarget()
  connectStreamSource(source)

  const receive = (data) => source.dispatchEvent(new MessageEvent("message", { data }))
  const subscribed = () => onState(true)
  const names = []

  for (const { channel: name, signature } of streams) {
    setAuthParams(name, { signature })

    let entry = channels.get(name)
    if (!entry) {
      entry = { users: 0, timer: null, channel: null }
      channels.set(name, entry)
    }
    clearTimeout(entry.timer)
    entry.channel ||= client.subscribe(name)
    entry.users += 1

    entry.channel.bind(EVENT, receive)
    entry.channel.bind("pusher:subscription_succeeded", subscribed)
    if (entry.channel.subscribed) subscribed()
    names.push(name)
  }

  return () => {
    disconnectStreamSource(source)
    onState(false)

    for (const name of names) {
      const entry = channels.get(name)
      if (!entry) continue

      entry.channel.unbind(EVENT, receive)
      entry.channel.unbind("pusher:subscription_succeeded", subscribed)
      entry.users -= 1
      if (entry.users > 0) continue

      clearTimeout(entry.timer)
      entry.timer = setTimeout(() => {
        if (entry.users > 0) return
        hibira().unsubscribe(name)
        channels.delete(name)
      }, UNSUBSCRIBE_DELAY)
    }
  }
}

class HibiraTurboStreamSourceElement extends HTMLElement {
  static observedAttributes = ["streams"]

  connectedCallback() {
    const streams = JSON.parse(this.getAttribute("streams") || "[]")
    const data = this.dataset
    this.stop = listen(
      streams,
      { key: data.hibiraKey, host: data.hibiraHost, port: data.hibiraPort, tls: data.hibiraTls, authEndpoint: data.hibiraAuthEndpoint },
      (connected) => this.toggleAttribute("connected", connected),
    )
  }

  disconnectedCallback() {
    this.stop?.()
    this.stop = null
  }

  attributeChangedCallback() {
    if (this.stop) {
      this.disconnectedCallback()
      this.connectedCallback()
    }
  }
}

if (!customElements.get("hibira-turbo-stream-source")) {
  customElements.define("hibira-turbo-stream-source", HibiraTurboStreamSourceElement)
}
