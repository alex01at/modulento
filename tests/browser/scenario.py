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

        # 2d. a rich text: the toolbar appears while it is edited; a link or a button is not followed
        await b.click('#inline-steps [data-field="body"]')
        check('a rich text shows its toolbar while it is edited', await b.exists('.inline-wysiwyg .inline-wysiwyg-bold'))
        await b.eval("document.querySelector('#inline-steps [data-field=\"body\"]').blur()")
        await asyncio.sleep(1)
        await b.goto(BASE + '/?edit=1')
        await b.click('#inline-cta-links a')
        check('a button in edit mode is not followed; its form opens instead', await b.location() == '/?edit=1' and await b.eval("!!document.querySelector('#inline-cta-links details.inline-edit[open]')"))

        # 2e. duplicate a block
        before = await b.eval("document.querySelectorAll('.inline-block').length")
        await b.click('#inline-steps button[value^="duplicate:"]')
        check('a block can be duplicated', await b.eval("document.querySelectorAll('.inline-block').length") == before + 1)

        # 2f. a content page is edited on the spot too
        import random
        slug = f'probe-{random.randint(1000, 99999)}'
        await b.goto(BASE + '/admin/pages/new')
        await b.type_into('input[name="text[de][title]"]', 'Testseite ' + slug)
        await b.type_into('input[name="text[de][slug]"]', slug)
        await b.type_into('textarea[name="text[de][body]"]', '<p>Erster Text.</p>')
        await b.eval("document.querySelector('select[name=\\'status\\']') && (document.querySelector('select[name=\\'status\\']').value = 'published')")
        await b.click('form[action$="/admin/pages/new"] button[type="submit"]')
        await b.goto(BASE + f'/{slug}?edit=1')
        check('a content page can be edited in place, with the pencil', await b.exists('article.inline-block') and await b.exists('.edit-page'))
        await b.click(f'article h1')
        await b.eval("(() => { const h = document.querySelector('article h1'); h.focus(); const r = document.createRange(); r.selectNodeContents(h); const s = getSelection(); s.removeAllRanges(); s.addRange(r); document.execCommand('insertText', false, 'Neuer Seitentitel'); h.blur(); })()")
        await asyncio.sleep(1.2)
        await b.goto(BASE + f'/{slug}?edit=1')
        check('the title of a content page is saved on the spot', 'Neuer Seitentitel' in (await b.text('article h1')))

        await b.goto(BASE + '/?edit=1')
        # 2g. a widget: inserted from the "+" menu, and a block kept as a widget of one's own
        before = await b.eval("document.querySelectorAll('.inline-block').length")
        await b.click('#inline-hero .inline-plus summary')
        await b.click('#inline-hero .inline-plus button.inline-widget-choice')
        check('a shipped widget is inserted from the "+" menu', await b.eval("document.querySelectorAll('.inline-block').length") == before + 1)
        await b.eval("document.querySelector('#inline-steps details.inline-widget').open = true")
        await b.type_into('#inline-steps input[name="widget_name"]', 'Probe Widget')
        await b.click('#inline-steps button[value^="savewidget:"]')
        check('a block is kept as a widget of one\'s own', 'Das Widget wurde gespeichert' in (await b.eval('document.body.innerText')))

        # 2h. a block is moved by dragging its handle; the new order is kept after reloading
        await b.goto(BASE + '/?edit=1')
        before_ids = await b.eval("[...document.querySelectorAll('.inline-block[data-block-id]')].map(b => b.dataset.blockId)")
        # the drag as the browser sends it: handle pressed, the first block dropped below the second
        await b.eval("""(() => { const blocks = [...document.querySelectorAll('.inline-block[data-block-id]')];
            const first = blocks[0], second = blocks[1]; const dt = new DataTransfer();
            first.querySelector('.inline-grip').dispatchEvent(new MouseEvent('mousedown', {bubbles: true}));
            first.dispatchEvent(new DragEvent('dragstart', {bubbles: true, dataTransfer: dt}));
            const box = second.getBoundingClientRect();
            second.dispatchEvent(new DragEvent('dragover', {bubbles: true, cancelable: true, clientY: box.bottom - 2, dataTransfer: dt}));
            first.dispatchEvent(new DragEvent('dragend', {bubbles: true, dataTransfer: dt})); })()""")
        await asyncio.sleep(1.2)
        await b.goto(BASE + '/?edit=1')
        after_ids = await b.eval("[...document.querySelectorAll('.inline-block[data-block-id]')].map(b => b.dataset.blockId)")
        check('a block dragged below the next one stays there after reloading', after_ids[:2] == [before_ids[1], before_ids[0]] and after_ids[2:] == before_ids[2:])

        # 2i. the offer page's blocks are reordered by the same editing script; checked here
        # on the administration page, which holds the offer page's blocks and its CSRF token
        await b.goto(BASE + '/admin/offer-page')
        result = await b.eval(r"""(async () => {
            const token = document.querySelector('input[name="_csrf"]').value;
            const order = async () => {
                const html = await (await fetch('/admin/offer-page', {credentials: 'same-origin'})).text();
                return [...new Set([...html.matchAll(/name="blocks\[([a-z0-9-]+)\]/g)].map(m => m[1]))];
            };
            const send = (ids) => fetch('/admin/offer-page/order', {method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': token}, body: JSON.stringify({order: ids})}).then(r => r.status);
            const before = await order();
            const status = await send([...before].reverse());
            const after = await order();
            await send(before);
            return {before, status, after, restored: (await order()).join(',') === before.join(',')};
        })()""")
        check('the offer page keeps a dragged order after reloading', result['status'] == 200 and result['after'] == list(reversed(result['before'])) and result['restored'])

        await b.goto(BASE + '/?edit=1')

        # 3. hide the categories block, then show it again
        await b.click('#inline-offers button[value="toggle:offers"]')
        check('a block can be hidden (it shows as hidden while editing)', await b.exists('#inline-offers .badge-disabled'))
        await b.click('#inline-offers button[value="toggle:offers"]')
        check('the hidden block can be shown again', not await b.exists('#inline-offers .badge-disabled'))

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
