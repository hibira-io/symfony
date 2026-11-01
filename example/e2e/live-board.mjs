// End-to-end check: a message posted in one window shows up in another one
// through Hibira, and deleting it removes it from both.
//
//   BASE_URL=http://127.0.0.1:8000 node e2e/live-board.mjs
import { chromium } from "playwright"

const base = process.env.BASE_URL || "http://127.0.0.1:8000"
const shots = process.env.SCREENSHOT_DIR
const text = `hello from window A ${Date.now()}`

const browser = await chromium.launch()
const fail = async (message) => {
  console.error(`FAIL: ${message}`)
  await browser.close()
  process.exit(1)
}

try {
  const a = await browser.newPage()
  const b = await (await browser.newContext({ colorScheme: "dark", viewport: { width: 390, height: 700 } })).newPage()
  for (const page of [a, b]) {
    page.on("console", (m) => m.type() === "error" && console.error(`[console] ${m.text()}`))
    await page.goto(base)
    await page.waitForSelector("#messages[data-hibira-connected]", { timeout: 10000 })
  }
  console.log("both windows subscribed")

  await a.fill("input[name=author]", "Ada")
  await a.fill("input[name=body]", text)
  await a.click("button[type=submit]:has-text('Send')")

  const item = b.locator("#messages li", { hasText: text })
  await item.waitFor({ timeout: 10000 })
  console.log("window B received the message without reloading")
  if (shots) {
    await a.waitForTimeout(300)
    await a.screenshot({ path: `${shots}/live-board-desktop-light.png` })
    await b.screenshot({ path: `${shots}/live-board-mobile-dark.png` })
  }

  await a.locator("#messages li", { hasText: text }).locator("button").click()
  await item.waitFor({ state: "detached", timeout: 10000 })
  console.log("window B removed the deleted message")
} catch (error) {
  await fail(error.message)
}

await browser.close()
console.log("OK")
