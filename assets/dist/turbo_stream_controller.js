// What turbo_stream_listen(topic, 'hibira') renders: subscribes to each
// topic's private channel and hands every "turbo-stream" event to Turbo,
// like the Mercure controller does with an EventSource.
//
// Importing this module also defines <hibira-turbo-stream-source>, which
// turbo_stream_from() renders on UX Turbo 3.1+.

import { Controller } from "@hotwired/stimulus"
import { listen } from "./stream_source.js"

export default class extends Controller {
  static values = {
    key: String,
    host: String,
    port: Number,
    tls: { type: Boolean, default: true },
    authEndpoint: String,
    streams: Array,
  }

  connect() {
    this.stop = listen(
      this.streamsValue,
      { key: this.keyValue, host: this.hostValue, port: this.portValue, tls: this.tlsValue, authEndpoint: this.authEndpointValue },
      (connected) => this.element.toggleAttribute("data-hibira-connected", connected),
    )
  }

  disconnect() {
    this.stop?.()
    this.stop = null
  }
}
