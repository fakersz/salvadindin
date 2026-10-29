const assert = require('node:assert/strict')
const { randomBytes, createHmac } = require('node:crypto')
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright')
const base = process.env.AUTH_E2E_URL || 'https://localhost:15443'
assert(['localhost', '127.0.0.1'].includes(new URL(base).hostname), 'Disposable loopback target only')
const secret = process.env.WHATSAPP_E2E_APP_SECRET
assert(secret, 'Set a synthetic Meta secret for the disposable backend')
const root = '/api/integrations/whatsapp'
let step = 'launch'
;(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_PATH })
  try {
    const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 900 } })
    const csrf = await (await context.request.get(base + '/api/auth/csrf')).json()
    const password = randomBytes(24).toString('base64url')
    const registered = await context.request.post(base + '/api/auth/register', {
      headers: { Origin: base, 'X-CSRF-Token': csrf.csrf_token },
      data: { username: 'whatsapp.' + randomBytes(5).toString('hex'), password, password_confirmation: password },
    })
    assert.equal(registered.status(), 201)
    async function incoming(code) {
      const raw = JSON.stringify({object: 'whatsapp_business_account', entry: [{changes: [{field: 'messages', value: {
        messaging_product: 'whatsapp', metadata: {phone_number_id: '00000000000'}, messages: [{
          from: '00000000003', id: 'synthetic.' + randomBytes(12).toString('hex'), timestamp: String(Math.floor(Date.now()/1000)), type: 'text', text: {body: 'VINCULAR ' + code},
        }],
      }}]}]})
      const response = await context.request.post(base + root + '/webhook', {data: raw, headers: {
        'Content-Type': 'application/json', 'X-Hub-Signature-256': 'sha256=' + createHmac('sha256', secret).update(raw).digest('hex'),
      }})
      assert.equal(response.status(), 200)
    }
    const page = await context.newPage()
    const errors = []
    page.on('pageerror', e => errors.push(e.name))
    step = 'settings navigation and code revocation'
    await page.goto(base + '/app/security')
    await page.getByRole('link', {name: 'Integrações · WhatsApp →'}).click()
    await page.getByRole('heading', {name: 'Integrações', exact: true}).waitFor()
    await page.getByText('Desconectado', {exact: true}).waitFor()
    await page.getByRole('button', {name: 'Gerar código', exact: true}).click()
    await page.locator('.link-code code').waitFor()
    const revoked = (await page.locator('.link-code code').innerText()).replace('VINCULAR ', '')
    await page.getByRole('button', {name: 'Revogar código', exact: true}).click()
    await page.locator('.link-code').waitFor({state: 'hidden'})
    await incoming(revoked)
    assert.equal((await (await context.request.get(base + root + '/status')).json()).connected, false)
    step = 'mobile linking through signed official webhook'
    await page.setViewportSize({width: 390, height: 844})
    await page.getByRole('button', {name: 'Gerar código', exact: true}).click()
    await page.locator('.link-code code').waitFor()
    const code = (await page.locator('.link-code code').innerText()).replace('VINCULAR ', '')
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
    await incoming(code)
    await page.getByText('Conectado', {exact: true}).waitFor({timeout: 12000})
    assert.equal(await page.locator('.link-code').count(), 0)
    assert(!(await page.locator('body').innerText()).includes('00000000003'))
    step = 'unlink confirmation'
    await page.getByRole('button', {name: 'Desvincular WhatsApp', exact: true}).click()
    await page.getByRole('button', {name: 'Cancelar', exact: true}).click()
    assert.equal((await (await context.request.get(base + root + '/status')).json()).connected, true)
    await page.getByRole('button', {name: 'Desvincular WhatsApp', exact: true}).click()
    await page.getByRole('button', {name: 'Confirmar desvinculação', exact: true}).click()
    await page.getByText('Desconectado', {exact: true}).waitFor()
    step = 'credentials unavailable screen'
    await page.route('**/api/integrations/whatsapp/status', route => route.fulfill({contentType:'application/json', body:JSON.stringify({available:false,connected:false,linked_at:null,code_expires_at:null})}))
    await page.reload()
    await page.getByText('Indisponível', {exact: true}).waitFor()
    assert.equal(await page.getByRole('button', {name: /Gerar.*código/}).count(), 0)
    assert.deepEqual(errors, [])
    console.log('PASS: isolated desktop/mobile integration UI, signed synthetic linking, revocation, polling, unlink confirmation, privacy and unavailable state. No production data or real Meta connection.')
  } finally { await browser.close() }
})().catch(e => { console.error('FAIL isolated WhatsApp browser at ' + step + ': ' + e.name); console.error((e.stack || '').split('\n').filter(x => x.trim().startsWith('at ')).join('\n')); process.exitCode = 1 })
