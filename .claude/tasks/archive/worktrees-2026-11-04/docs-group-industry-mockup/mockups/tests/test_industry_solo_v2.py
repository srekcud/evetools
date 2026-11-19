import json
import pathlib
import re
import runpy

import pytest
from playwright.sync_api import sync_playwright

HERE = pathlib.Path(__file__).parent
PAGE = HERE.parent / 'industry-solo-v2.html'
URL = PAGE.as_uri()
DATA = runpy.run_path(str(HERE / 'industry_solo_v2_data.py'))['out']
TOUR_LENGTH = 22
STATES = ['missing_price', 'token_expired', 'personal_use']
TABS = ['chaine', 'planning', 'materiaux', 'finances', 'ventes', 'journal']


def fr(value, decimals=2):
    whole, frac = f'{abs(value):,.{decimals}f}'.split('.')
    return ('−' if value < 0 else '') + whole.replace(',', ' ') + ',' + frac


def millions(value):
    return fr(value / 1e6) + ' M'


def percent(value, decimals=1):
    return fr(value, decimals) + ' %'


@pytest.fixture(scope='module')
def page():
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='/usr/bin/google-chrome')
        pg = browser.new_page(viewport={'width': 1440, 'height': 900})
        pg.errors = []
        pg.on('console', lambda m: pg.errors.append(m.text) if m.type in ('error', 'warning') else None)
        pg.on('pageerror', lambda e: pg.errors.append(str(e)))
        yield pg
        browser.close()


def open_hash(page, fragment):
    page.goto(URL + '#' + fragment)
    page.wait_for_timeout(150)
    return page.inner_text('body')


def clipped(page):
    return page.evaluate('''() => { const out = [];
      if (document.documentElement.scrollWidth > innerWidth + 1) out.push('page');
      document.querySelectorAll('div, table, dl').forEach(d => { const cs = getComputedStyle(d);
        if ((cs.overflowX === 'auto' || cs.overflowX === 'hidden') && d.clientWidth > 0 && d.scrollWidth > d.clientWidth + 1) out.push(d.className); });
      return out; }''')


def test_embedded_data_is_the_python_computation(page):
    open_hash(page, 'p=1')
    assert page.evaluate('DATA') == json.loads(json.dumps(DATA, ensure_ascii=False))


@pytest.mark.parametrize('fragment', [f'p={n}' for n in range(1, TOUR_LENGTH + 1)]
                         + [f'p={n}&long=1' for n in range(1, TOUR_LENGTH + 1)]
                         + [f'v=projet&tab={tab}&t={t}' for tab in TABS for t in range(8)]
                         + [f'v=projet&tab={tab}&t=5&s={s}' for tab in TABS for s in STATES]
                         + [f'v=atelier&t={t}' for t in range(8)] + [f'v=compagnon&t={t}' for t in range(3, 8)])
def test_every_screen_renders_without_error_or_clipping(page, fragment):
    page.errors.clear()
    open_hash(page, fragment)
    assert page.errors == []
    assert clipped(page) == []


def test_final_profit(page):
    text = open_hash(page, 'p=12')
    final = DATA['final']
    assert f"{millions(final['profit'])} · {percent(final['percent'])}" in text
    for _, value in DATA['waterfall']:
        assert millions(abs(value)) in text


def test_provisional_profit_before_the_contract(page):
    text = open_hash(page, 'p=11')
    partial = DATA['partial']
    assert millions(partial['profit']) in text


def test_break_even_price(page):
    text = open_hash(page, 'p=12')
    assert f"{round(DATA['breakEvenUnit'] / 1000)} k" in text


def test_simulator_at_contract_price_matches_the_final_profit(page):
    open_hash(page, 'p=11')
    sim = page.locator('.sim').inner_text()
    expected = DATA['simulations']['748000-contract']
    assert millions(expected['profit']) in sim and millions(DATA['final']['profit']) in sim
    page.click('[data-act=sim-ch][data-arg=market]')
    page.wait_for_timeout(100)
    assert millions(DATA['simulations']['748000-market']['profit']) in page.locator('.sim').inner_text()


def test_estimate_against_actual(page):
    text = open_hash(page, 'p=12')
    assert millions(DATA['estimate']['profit']) in text


def test_linking_the_contract_closes_the_sale(page):
    open_hash(page, 'p=10')
    page.click('.banner [data-act=link-s2]')
    page.wait_for_timeout(100)
    assert '50 / 50 vendus' in page.inner_text('#root')


def test_missing_price_is_unknown_never_zero(page):
    open_hash(page, 'v=projet&tab=materiaux&t=1&s=missing_price')
    row = page.locator('tr', has_text='Particle Accelerator Unit').inner_text()
    assert 'inconnu' in row.lower()
    assert 'inconnu' in page.locator('tfoot').inner_text().lower()


def test_token_expired_makes_jobs_unknown(page):
    text = open_hash(page, 'p=17')
    assert 'statut inconnu' in text and 'Jeton ESI' in text


def test_companion_counts_down(page):
    open_hash(page, 'v=compagnon&t=4')
    page.wait_for_timeout(1200)
    assert re.search(r'dans 0[23]:[0-5]\d:[0-5]\d', page.inner_text('.cmp'))


def test_alerts_are_visual_only():
    source = PAGE.read_text()
    for sound in ('<audio', 'new Audio', 'AudioContext', 'speechSynthesis', '.play('):
        assert sound not in source


def test_placeholders_only():
    source = PAGE.read_text()
    for name in re.findall(r"'Personnage [^']+'", source):
        assert re.fullmatch(r"'Personnage Alpha( II)?'", name)
