import json
import pathlib
import re
import runpy

import pytest
from playwright.sync_api import sync_playwright

HERE = pathlib.Path(__file__).parent
PAGE = HERE.parent / 'group-industry-v1.html'
URL = PAGE.as_uri()
DATA = runpy.run_path(str(HERE / 'group_industry_v1_data.py'))
TOUR_LENGTH = 30
PROPOSAL_COUNT = 20
STATES = ['missing_price', 'container_unknown', 'corp_reader', 'reconnect', 'no_sales', 'no_cost']


def fr_number(value, decimals=2):
    whole, frac = f'{value:,.{decimals}f}'.split('.')
    return whole.replace(',', ' ') + ',' + frac


def millions(value):
    return fr_number(value / 1e6) + ' M'


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
    assert page.evaluate('DATA') == json.loads(json.dumps(DATA['out'], ensure_ascii=False))


@pytest.mark.parametrize('fragment', [f'p={n}' for n in range(1, TOUR_LENGTH + 1)]
                         + [f'm=in_progress&tab={t}&s={s}' for s in STATES for t in ('bom', 'contrib', 'dist', 'members')]
                         + [f'p={n}&real=1' for n in range(1, TOUR_LENGTH + 1)]
                         + [f'p={n}&long=1' for n in range(1, TOUR_LENGTH + 1)])
def test_every_screen_renders_without_error_or_clipped_column(page, fragment):
    page.errors.clear()
    open_hash(page, fragment)
    assert page.errors == []
    assert clipped_boxes(page) == []


def test_tour_counts_its_steps_and_moves_forward(page):
    open_hash(page, 'p=1')
    assert f'Étape 1/{TOUR_LENGTH}' in page.inner_text('#mockbar')
    page.click('[data-act=tour-next]')
    page.wait_for_timeout(100)
    assert f'Étape 2/{TOUR_LENGTH}' in page.inner_text('#mockbar')


def test_frozen_bom_total_matches_python(page):
    text = open_hash(page, 'p=4')
    assert fr_number(DATA['bom_total']) + ' ISK' in text
    assert f"{DATA['out']['mats'].__len__()} items · {len(DATA['out']['jobs'])} jobs" in text


def test_final_distribution_matches_python(page):
    text = open_hash(page, 'p=21')
    final = DATA['final']
    assert f"Σ payouts {fr_number(final['net'])} ISK = revenu net {fr_number(final['net'])} ISK ✓" in text
    assert fr_number(final['margin']) + ' %' in text
    for member in final['members'].values():
        assert millions(member['payout']) in text


def test_partial_sales_show_the_projected_margin(page):
    text = open_hash(page, 'p=20')
    assert fr_number(DATA['partial']['margin']).replace('-', '−') + ' %' in text
    assert fr_number(DATA['projected_margin']) + ' %' in text


def test_transfers_settle_every_payout(page):
    text = open_hash(page, 'p=22')
    for transfer in DATA['transfers']:
        assert fr_number(transfer['amount']) + ' ISK' in text
    received = {}
    for transfer in DATA['transfers']:
        received[transfer['to']] = received.get(transfer['to'], 0) + transfer['amount']
    for member, amount in received.items():
        assert abs(amount + DATA['held'].get(member, 0) - DATA['final']['members'][member]['payout']) < 0.02


def test_missing_price_is_unknown_never_zero(page):
    open_hash(page, 'm=in_progress&tab=bom&s=missing_price')
    robotics = page.locator('tr', has_text='Robotics').first.inner_text()
    assert 'inconnu' in robotics
    assert 'Coût total inconnu' in page.inner_text('body')


def test_no_sale_is_pending_and_existing_code_shows_minus_100(page):
    proposal = open_hash(page, 'm=in_progress&tab=dist&s=no_sales')
    assert 'en attente des ventes' in proposal
    assert '−100,00 %' not in proposal
    existing = open_hash(page, 'm=in_progress&tab=dist&s=no_sales&real=1')
    assert '−100,00 %' in existing


def test_existing_only_mode_hides_every_proposal(page):
    for n in range(1, TOUR_LENGTH + 1):
        open_hash(page, f'p={n}&real=1')
        assert page.locator('.prop').count() == 0
        assert page.locator('.ptag:not(.real)').count() == 0


def test_every_proposal_appears_on_a_screen(page):
    seen = set()
    fragments = [f'p={n}' for n in range(1, TOUR_LENGTH + 1)] + [f'm=in_progress&tab={t}&s={s}' for s in STATES for t in ('bom', 'contrib', 'dist', 'members')]
    for fragment in fragments:
        open_hash(page, fragment)
        seen.update(int(n) for n in re.findall(r'(?i)Proposition (\d+)', page.inner_text('#root') + page.inner_text('#modal')))
    assert seen == set(range(1, PROPOSAL_COUNT + 1))
    open_hash(page, 'p=1&spec=1')
    assert page.locator('.spec-panel:not(.audit) tbody tr').count() == PROPOSAL_COUNT


def test_alerts_are_visual_only():
    source = PAGE.read_text()
    for sound in ('<audio', 'new Audio', 'AudioContext', 'speechSynthesis', '.play('):
        assert sound not in source


def test_people_are_placeholders(page):
    open_hash(page, 'p=1')
    people = page.evaluate('NAME_ORIG.people')
    for person in people.values():
        assert re.fullmatch(r'Joueur [A-Z][a-z]+', person['name'])
        assert re.fullmatch(r'Personnage [A-Z][a-z]+', person['char'])
        assert re.fullmatch(r'Corp [A-C]', person['corp'])


def test_invitation_link_names_its_module(page):
    open_hash(page, 'p=6')
    assert page.input_value('input[readonly]') == 'evetools.app/group-industry/join/7f3a9c2e1b'
    open_hash(page, 'p=6&real=1')
    assert page.input_value('input[readonly]') == 'evetools.app/join/7f3a9c2e1b'


def test_job_containers_flag_what_leaves_the_project_container(page):
    open_hash(page, 'p=14')
    card = page.locator('.card.flush', has=page.locator('h3:has-text("Jobs ESI")'))
    rows = {r.split('\n')[0]: r for r in card.locator('tbody tr').all_inner_texts()}
    assert '✓ entrée et sortie dans le projet' in rows['Hammerhead II']
    assert 'entrée hors du container du projet' in rows['Hobgoblin II']
    assert 'sortie hors container' in rows['Hammerhead I']
    assert 'inconnu' in rows['Inventions et copies T1'] and 'contrôle impossible' in rows['Inventions et copies T1']


def test_unreadable_job_location_is_unknown_with_its_reason(page):
    open_hash(page, 'm=in_progress&tab=contrib&s=reconnect')
    card = page.locator('.card.flush', has=page.locator('h3:has-text("Jobs ESI")'))
    bravo = card.locator('tbody tr', has_text='Hammerhead II').inner_text()
    assert 'inconnu' in bravo and 'à reconnecter' in bravo


def test_running_jobs_show_real_cost_and_live_countdown(page):
    open_hash(page, 'p=16')
    page.wait_for_timeout(1200)
    running = page.locator('.lab:has-text("En cours maintenant")').locator('xpath=..').inner_text()
    hammerhead = sum(c['value'] for c in DATA['contribs'] if c['id'] in ('B4', 'B5', 'B6', 'B7', 'B8'))
    hobgoblin = sum(c['value'] for c in DATA['contribs'] if c['id'] in ('C5', 'C6', 'C7'))
    assert millions(hammerhead) in running and f'{hobgoblin / 1e3:.0f} k' in running
    assert re.search(r'dans 0[0-9]:[0-5][0-9]:[0-5][0-9]', running)


def test_launched_jobs_cost_is_the_sum_of_game_costs(page):
    text = open_hash(page, 'p=16&tab=bom')
    detected = sum(c['value'] for c in DATA['contribs'] if c['type'] in ('job_install', 'bpc'))
    assert millions(detected) in text
    assert '14 jobs lancés sur 16' in text


def test_companion_claims_a_free_line_without_leaving_the_game(page):
    open_hash(page, 'p=17')
    assert 'Fenêtre du jeu' in page.inner_text('#root')
    assert 'À ATTRIBUER · 2' in page.inner_text('.cmpg').upper()
    page.click('[data-act=claim][data-arg=j11]')
    page.wait_for_timeout(100)
    card = page.inner_text('.cmpg').upper()
    assert 'À ATTRIBUER · 1' in card and 'MES LIGNES À LIVRER · 1' in card
    page.click('[data-act=unclaim][data-arg=j11]')
    page.wait_for_timeout(100)
    assert 'À ATTRIBUER · 2' in page.inner_text('.cmpg').upper()


def test_companion_before_launch_lists_every_unclaimed_line(page):
    open_hash(page, f'p={TOUR_LENGTH}')
    lines = len(DATA['out']['mats']) + len(DATA['out']['jobs'])
    assert f'À ATTRIBUER · {lines - 2}' in page.inner_text('.cmpg').upper()


def test_contract_sale_pays_no_project_tax(page):
    open_hash(page, 'p=19')
    summary = page.locator('.card', has=page.locator('h3:has-text("Résumé financier")')).inner_text()
    contract = next(s for s in DATA['SALES'] if s['channel'] == 'contract')
    assert millions(contract['qty'] * contract['unit']) in summary
    assert re.search(r'Taxe de vente \(3,6 % du marché\)\s*0\b', summary)
    assert re.search(r'Revenu net\s*' + re.escape(millions(contract['qty'] * contract['unit'])), summary)


def test_existing_code_taxes_every_sale_including_contracts(page):
    text = open_hash(page, 'p=21&real=1')
    assert fr_number(DATA['final_real']['margin']) + ' %' in text
    assert fr_number(DATA['final']['margin']) + ' %' not in text


@pytest.mark.parametrize('fragment, area, number, topic', [
    ('p=6', '#root', 19, 'lien d’invitation'),
    ('p=29', '#modal', 20, 'Vente par contrat'),
    ('p=12', '#root', 18, 'Entrée et sortie'),
])
def test_screen_proposal_numbers_match_the_spec_panel(page, fragment, area, number, topic):
    open_hash(page, fragment)
    assert re.search(rf'(?i)Proposition {number}\b', page.inner_text(area))
    open_hash(page, 'p=1&spec=1')
    row = page.locator('.spec-panel:not(.audit) tbody tr').nth(number - 1).inner_text()
    assert topic in row
