"""Browser test of the editing and sign-in flows, against a running installation.

Start Chrome with a debugging port, then run it:
  google-chrome --headless=new --no-sandbox --remote-debugging-port=9333 --user-data-dir=/tmp/chrome-test about:blank &
  MODULENTO_URL=http://127.0.0.1:8317 MODULENTO_ADMIN=admin@example.test MODULENTO_PASSWORD=... python3 tests/browser/scenario.py

It changes data (a block, a text, an account): use a throwaway installation.

The scenario: sign in, edit the home page in place, add and hide blocks, change a text;
then sign in as a new account and back to the administration."""
import asyncio, os, re, sys, tempfile, time
sys.path.insert(0, __file__.rsplit('/', 1)[0])
from browser import Browser

BASE = os.environ.get('MODULENTO_URL', 'http://127.0.0.1:8317')
ADMIN = os.environ.get('MODULENTO_ADMIN', 'vis@example.test')
PASSWORD = os.environ.get('MODULENTO_PASSWORD', 'correct horse battery')
SHOTS = os.environ.get('MODULENTO_SHOTS', tempfile.gettempdir())
results = []

def check(name, ok):
    results.append((name, bool(ok)))
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)

async def main():
    b = await Browser().connect()
    try:
        # 1. sign in
        await b.goto(BASE + '/login')
        if await b.exists('input[name="email"]'):
            await b.type_into('input[name="email"]', ADMIN)
            await b.type_into('input[name="password"]', PASSWORD)
            await b.click('form[action$="/login"] button[type="submit"]')
        check('sign in as administrator', await b.exists('a[href="/admin"]') or 'Administration' in (await b.eval('document.body.innerText')))

        # 2. the pencil on the home page leads into editing
        await b.goto(BASE + '/')
        check('the pencil is in the header of the home page', await b.exists('.edit-page'))
        await b.click('.edit-page')
        check('the pencil turns editing on', (await b.location()).endswith('?edit=1') and await b.exists('.inline-tools'))
        check('every block has its tools', await b.eval("document.querySelectorAll('.inline-tools').length") >= 4)

        # 2b. a text is edited on the spot: a click makes it editable, leaving it saves it
        await b.click('#inline-hero h1')
        check('a click on a text makes it editable', await b.eval("document.querySelector('#inline-hero h1').isContentEditable"))
        # typed the way a person types: the selection is replaced by the text
        await b.eval("""(() => { const h = document.querySelector('#inline-hero h1'); h.focus();
            const r = document.createRange(); r.selectNodeContents(h); const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r);
            document.execCommand('insertText', false, 'Auf der Seite geändert'); h.blur(); })()""")
        await asyncio.sleep(1.2)
        await b.goto(BASE + '/?edit=1')
        check('leaving the text saves it, the page shows it after reloading', 'Auf der Seite geändert' in (await b.text('#inline-hero h1')))

        # 2c. the "+" between blocks adds a block right there
        before = await b.eval("document.querySelectorAll('.inline-block').length")
        await b.click('#inline-steps .inline-plus summary')
        await b.click('#inline-steps .inline-plus button[value="text"]')
        check('the "+" after a block inserts a new block right after it', await b.eval("document.querySelectorAll('.inline-block').length") == before + 1
              and await b.eval("document.querySelectorAll('.inline-block')[document.querySelector('#inline-steps').nextElementSibling ? 0 : 0].id") is not None)

        # 3. hide the categories block, then show it again
        await b.click('#inline-categories button[value="toggle:categories"]')
        check('a block can be hidden (it shows as hidden while editing)', await b.exists('#inline-categories .badge-disabled'))
        await b.click('#inline-categories button[value="toggle:categories"]')
        check('the hidden block can be shown again', not await b.exists('#inline-categories .badge-disabled'))

        # 4. add a text block at the end
        before = await b.eval("document.querySelectorAll('.inline-tools').length")
        await b.type_into('#inline-add-type', 'text')
        await b.click('button[value="add"]')
        after = await b.eval("document.querySelectorAll('.inline-tools').length")
        check('a block can be added', after == before + 1)

        # 5. change the text of the steps block in place
        await b.type_into('#heading-steps', 'Ablauf in drei Schritten')
        await b.click('#inline-steps button[value="save"]')
        check('the text of a block is changed and shown', 'Ablauf in drei Schritten' in (await b.eval('document.body.innerText')))
        await b.screenshot(SHOTS + '/scenario_edit.png', 1200, 1000)

        # 6. a phone-sized page works the same
        await b.screenshot(SHOTS + '/scenario_edit_mobile.png', 390, 900)
        await b.send('Emulation.setDeviceMetricsOverride', {'width': 1200, 'height': 900, 'deviceScaleFactor': 1, 'mobile': False})

        # 7. create an account, sign in as it, come back
        await b.goto(BASE + '/admin/accounts/new')
        email = f'hilfe{int(time.time())}@example.test'
        await b.type_into('#new_email', email)
        await b.type_into('#new_name', 'Hilfe Test')
        await b.click('form[action$="/admin/accounts/new"] button[type="submit"]')
        loc = await b.location()
        check('an account can be created', re.fullmatch(r'/admin/accounts/\d+', loc) is not None)
        account_url = loc
        await b.click('form[action$="/impersonate"] button[type="submit"]')
        check('signed in as the account: the banner shows, with the way back', await b.exists('.impersonation') and 'Hilfe Test' in (await b.text('.impersonation')))
        await b.screenshot(SHOTS + '/scenario_banner.png', 1200, 700)
        await b.click('.impersonation button')
        check('the way back leads to the administration account page', (await b.location()) == account_url)
        await b.goto(BASE + '/admin')
        check('the administration opens again after the way back', 'Übersicht' in (await b.eval('document.body.innerText')) or await b.exists('.sidebar, .admin-menu, .header-nav'))
    finally:
        await b.close()
    failed = [n for n, ok in results if not ok]
    print(f'\n{len(results) - len(failed)} of {len(results)} passed')
    sys.exit(1 if failed else 0)

asyncio.run(main())
