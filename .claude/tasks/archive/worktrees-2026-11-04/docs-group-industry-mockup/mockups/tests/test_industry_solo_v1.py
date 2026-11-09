import json
import pathlib
import re
import runpy

import pytest
from playwright.sync_api import sync_playwright

HERE = pathlib.Path(__file__).parent
PAGE = HERE.parent / 'industry-solo-v1.html'
URL = PAGE.as_uri()
DATA = runpy.run_path(str(HERE / 'industry_solo_v1_data.py'))['out']
TOUR_LENGTH = 21
PROPOSAL_COUNT = 10
STATES = ['missing_price', 'token_expired', 'skills_unknown', 'personal_use']


def fr_number(value, decimals=2):
    whole, frac = f'{abs(value):,.{decimals}f}'.split('.')
    return ('−' if value < 0 else '') + whole.replace(',', ' ') + ',' + frac


def millions(value):
    return fr_number(value / 1e6) + ' M'


def percent(value, decimals=1):
    return fr_number(value, decimals) + ' %'


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


def clipped_boxes(page):
    return page.evaluate('''() => {
      const out = [];
      if (document.documentElement.scrollWidth > innerWidth + 1) out.push('page');
      document.querySelectorAll('div').forEach(d => { const cs = getComputedStyle(d);
        if ((cs.overflowX === 'auto' || cs.overflowX === 'hidden') && d.clientWidth > 0 && d.scrollWidth > d.clientWidth + 1) out.push(d.className); });
      return out; }''')


def test_embedded_data_is_the_python_computation(page):
    open_hash(page, 'p=1')
    assert page.evaluate('DATA') == json.loads(json.dumps(DATA, ensure_ascii=False))


@pytest.mark.parametrize('fragment', [f'p={n}' for n in range(1, TOUR_LENGTH + 1)]
                         + [f'p={n}&real=1' for n in range(1, TOUR_LENGTH + 1)]
                         + [f'p={n}&long=1' for n in range(1, TOUR_LENGTH + 1)]
                         + [f'p=15&s={s}' for s in STATES] + ['p=12&s=skills_unknown', 'p=11&v=detail&tab=steps&s=token_expired'])
def test_every_screen_renders_without_error_or_clipped_column(page, fragment):
    page.errors.clear()
    open_hash(page, fragment)
    assert page.errors == []
    assert clipped_boxes(page) == []


def test_existing_profit_formula(page):
    text = open_hash(page, 'p=15&real=1')
    existing = DATA['existing']
    assert f"{millions(existing['profit'])} ({percent(existing['percent'])})" in text


def test_real_profit_with_sales_purchases_and_invention(page):
    text = open_hash(page, 'p=15')
    final = DATA['final']
    assert f"{millions(final['profit'])} ({percent(final['percent'])})" in text
    assert millions(DATA['realMaterial']) in text
    assert millions(DATA['invention']['computed']) in text


def test_partial_sales_show_provisional_and_projected_profit(page):
    text = open_hash(page, 'p=13')
    partial = DATA['partial']
    assert millions(partial['profit']) in text
    assert f"{millions(partial['projectedProfit'])} ({percent(partial['projectedPercent'])})" in text
    assert f"{partial['unsold']} Hammerhead II restent à vendre" in text


def test_contract_sale_pays_no_tax(page):
    open_hash(page, 'p=14')
    row = page.locator('tr', has_text='Contrat ESI').inner_text()
    assert re.search(r'\t0\t', row)
    assert millions(DATA['final']['taxes']) in page.inner_text('#root')


def test_missing_price_is_unknown_never_zero(page):
    open_hash(page, 'p=5&s=missing_price')
    row = page.locator('tr', has_text='Particle Accelerator Unit').first.inner_text()
    assert 'Inconnu' in row


def test_no_sale_yet_is_pending_not_a_loss(page):
    text = open_hash(page, 'p=6')
    assert 'en attente des ventes' in text


def test_existing_only_mode_hides_every_proposal(page):
    for n in range(1, TOUR_LENGTH + 1):
        open_hash(page, f'p={n}&real=1')
        assert page.locator('.prop').count() == 0
        assert page.locator('.ptag').count() == 0


def test_every_proposal_appears_on_a_screen(page):
    seen = set()
    for fragment in [f'p={n}' for n in range(1, TOUR_LENGTH + 1)] + [f'p=15&s={s}' for s in STATES]:
        open_hash(page, fragment)
        seen.update(int(n) for n in re.findall(r'(?i)Proposition (\d+)', page.inner_text('#root') + page.inner_text('#modal')))
    assert seen == set(range(1, PROPOSAL_COUNT + 1))
    open_hash(page, 'p=1&spec=1')
    assert page.locator('.spec-panel:not(.audit) tbody tr').count() == PROPOSAL_COUNT


@pytest.mark.parametrize('fragment, number, topic', [('p=13', 1, 'Onglet « Ventes »'), ('p=10', 6, 'Compagnon solo'), ('p=18', 9, 'Ouvrir à ma corpo')])
def test_screen_proposal_numbers_match_the_spec_panel(page, fragment, number, topic):
    open_hash(page, fragment)
    area = '#modal' if number == 9 else '#root'
    assert re.search(rf'(?i)Proposition {number}\b', page.inner_text(area))
    open_hash(page, 'p=1&spec=1')
    assert topic in page.locator('.spec-panel:not(.audit) tbody tr').nth(number - 1).inner_text()


def test_companion_counts_down_to_the_job_end(page):
    open_hash(page, 'p=11')
    page.wait_for_timeout(1200)
    assert re.search(r'dans 03:0[12]:[0-5][0-9]', page.inner_text('.cmpg'))


def test_alerts_are_visual_only():
    source = PAGE.read_text()
    for sound in ('<audio', 'new Audio', 'AudioContext', 'speechSynthesis', '.play('):
        assert sound not in source


def test_linking_purchases_updates_the_material_cost(page):
    open_hash(page, 'p=5')
    before = page.locator('.card', has_text='MATÉRIAUX').first.inner_text()
    assert '0 achats rattachés' in before
    page.click('[data-act=link-all]')
    page.wait_for_timeout(100)
    assert '4 achats rattachés' in page.inner_text('#root')


def test_linking_the_contract_completes_the_sales(page):
    open_hash(page, 'p=13')
    page.click('[data-act=link][data-arg=S2]')
    page.wait_for_timeout(100)
    text = page.inner_text('#root')
    assert f"{DATA['final']['revenue'] / 1e6:.2f}".replace('.', ',') + ' M' in text
    assert 'Contrats terminés à rattacher' not in text


def test_lifecycle_follows_the_project(page):
    assert '0 / 10 matériaux couverts' in open_hash(page, 'p=4')
    text = open_hash(page, 'p=14')
    assert '10 / 10 matériaux couverts' in text and '6 / 6 étapes livrées' in text and '50 / 50 vendus' in text


def test_steps_tree_view_lists_leaves(page):
    open_hash(page, 'p=4')
    page.click('[data-act=list-mode][data-arg=tree]')
    page.wait_for_timeout(100)
    assert 'Tritanium' in page.inner_text('#root') and 'Division 1/5' not in page.inner_text('#root')


def test_companion_move_task_can_be_checked_off(page):
    open_hash(page, 'p=10')
    page.click('[data-act=cmp-moved]')
    page.wait_for_timeout(100)
    assert 'déplacés dans' in page.inner_text('.cmpg')
