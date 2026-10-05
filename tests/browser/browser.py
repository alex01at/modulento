"""Drives a headless Chrome over the DevTools protocol: navigate, click, type, read, screenshot."""
import asyncio, base64, itertools, json, urllib.request
import websockets

class Browser:
    def __init__(self, port=9333):
        self.port = port
        self.ids = itertools.count(1)

    async def connect(self):
        pages = json.load(urllib.request.urlopen(f'http://127.0.0.1:{self.port}/json/list'))
        page = next(p for p in pages if p['type'] == 'page')
        self.ws = await websockets.connect(page['webSocketDebuggerUrl'], max_size=None)
        await self.send('Page.enable')
        await self.send('Runtime.enable')
        return self

    async def close(self):
        await self.ws.close()

    async def send(self, method, params=None):
        mid = next(self.ids)
        await self.ws.send(json.dumps({'id': mid, 'method': method, 'params': params or {}}))
        while True:
            msg = json.loads(await self.ws.recv())
            if msg.get('id') == mid:
                if 'error' in msg:
                    raise RuntimeError(f"{method}: {msg['error']}")
                return msg.get('result', {})

    async def eval(self, js):
        r = await self.send('Runtime.evaluate', {'expression': js, 'awaitPromise': True, 'returnByValue': True})
        if 'exceptionDetails' in r:
            raise RuntimeError(f"JS error: {r['exceptionDetails'].get('text')} {js[:120]}")
        return r['result'].get('value')

    async def wait_idle(self, timeout=15):
        """After a click or a navigation: wait until the new page has loaded."""
        await asyncio.sleep(0.6)
        for _ in range(int(timeout / 0.25)):
            try:
                if await self.eval('document.readyState') == 'complete':
                    return
            except RuntimeError:
                pass
            await asyncio.sleep(0.25)
        raise TimeoutError('page did not load')

    async def goto(self, url):
        await self.send('Page.navigate', {'url': url})
        await self.wait_idle()

    async def exists(self, selector):
        return await self.eval(f"!!document.querySelector({json.dumps(selector)})")

    async def text(self, selector):
        return await self.eval(f"(document.querySelector({json.dumps(selector)}) || {{}}).textContent || null")

    async def click(self, selector):
        ok = await self.eval(f"(() => {{ const el = document.querySelector({json.dumps(selector)}); if (!el) return false; el.click(); return true; }})()")
        if not ok:
            raise RuntimeError(f'no element to click: {selector}')
        await self.wait_idle()

    async def type_into(self, selector, value):
        ok = await self.eval(f"""(() => {{ const el = document.querySelector({json.dumps(selector)}); if (!el) return false;
            el.value = {json.dumps(value)}; el.dispatchEvent(new Event('input', {{bubbles: true}})); el.dispatchEvent(new Event('change', {{bubbles: true}})); return true; }})()""")
        if not ok:
            raise RuntimeError(f'no field: {selector}')

    async def location(self):
        return await self.eval('location.pathname + location.search')

    async def screenshot(self, path, width=1200, height=900):
        await self.send('Emulation.setDeviceMetricsOverride', {'width': width, 'height': height, 'deviceScaleFactor': 1, 'mobile': width < 600})
        r = await self.send('Page.captureScreenshot', {'format': 'png'})
        open(path, 'wb').write(base64.b64decode(r['data']))
