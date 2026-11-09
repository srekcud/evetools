import json
import pathlib
import re
import runpy

import pytest
from playwright.sync_api import sync_playwright

HERE = pathlib.Path(__file__).parent
PAGE = HERE.parent / 'industry-project-v3.html'
URL = PAGE.as_uri()
DATA = runpy.run_path(str(HERE / 'industry_project_v3_data.py'))['out']
TEAM = DATA['team']
TOUR_LENGTH = 39
TABS = ['chaine', 'planning', 'materiaux', 'finances', 'ventes', 'journal', 'equipe']
STATES = ['missing_price', 'token_expired', 'personal_use']


def fr(value, decimals=2):
    whole, frac = f'{abs(value):,.{decimals}f}'.split('.')
    return ('−' if value < 0 else '') + whole.replace(',', ' ') + ',' + frac


def millions(value):
    return fr(value / 1e6) + ' M'


def isk(value):
    return millions(value) if abs(value) >= 1e6 else fr(value / 1e3, 1).rstrip('0').rstrip(',') + ' k'


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
                         + [f'v=projet&tab={tab}&t={t}&team={team}' for tab in TABS for t in range(8) for team in (1, 4)]
                         + [f'v=projet&tab={tab}&t=5&s={s}' for tab in TABS for s in STATES]
                         + [f'v=atelier&t={t}&team={team}' for t in range(8) for team in (1, 4)]
                         + [f'v=compagnon&t={t}&team={team}' for t in range(3, 8) for team in (1, 4)])
def test_every_screen_renders_without_error_or_clipping(page, fragment):
    page.errors.clear()
    open_hash(page, fragment)
    assert page.errors == []
    assert clipped(page) == []


def test_group_distribution_matches_python(page):
    text = open_hash(page, 'p=14')
    assert f"marge {percent(TEAM['margin'], 2)}".upper() in text.upper()
    for member in TEAM['payouts'].values():
        assert isk(member['payout']) in text and isk(member['costs']) in text
    for transfer in TEAM['transfers']:
        assert fr(transfer['amount']) + ' ISK' in text


def test_one_margin_definition_everywhere(page):
    text = open_hash(page, 'p=14')
    assert f"{millions(TEAM['profit'])} · {percent(TEAM['margin'])}" in text
    solo = open_hash(page, 'p=17')
    assert f"{millions(DATA['solo']['profit'])} · {percent(DATA['solo']['margin'])}" in solo


def test_group_costs_only_the_line_rental_more_than_solo(page):
    group = open_hash(page, 'v=projet&tab=finances&t=6&team=4')
    solo = open_hash(page, 'v=projet&tab=finances&t=6&team=1')
    assert 'Location de lignes' in group and 'Location de lignes' not in solo
    assert millions(TEAM['cost']) in group


def test_same_tabs_except_team(page):
    open_hash(page, 'v=projet&t=4&team=1')
    solo_tabs = page.locator('.tabs button').all_inner_texts()
    open_hash(page, 'v=projet&t=4&team=4')
    group_tabs = page.locator('.tabs button').all_inner_texts()
    assert [t for t in group_tabs if t.upper() != 'ÉQUIPE'] == solo_tabs
    assert len(group_tabs) == len(solo_tabs) + 1


def test_declared_contribution_waits_for_review(page):
    open_hash(page, 'p=11')
    row = page.locator('tr', has_text='5 lignes · 5 jobs').inner_text()
    assert 'APPROUVER' in row.upper()
    open_hash(page, 'v=projet&tab=equipe&t=5&team=4')
    assert 'APPROUVÉ' in page.locator('tr', has_text='5 lignes · 5 jobs').inner_text().upper()


def test_reserve_a_missing_material(page):
    open_hash(page, 'v=projet&tab=materiaux&t=1&team=4')
    page.click('[data-act=reserve][data-arg="Particle Accelerator Unit"]')
    page.wait_for_timeout(100)
    assert 'réservé' in page.locator('tr', has_text='Particle Accelerator Unit').inner_text()


def test_invite_keeps_the_same_project(page):
    text = open_hash(page, 'p=4')
    assert 'Le projet reste le même' in text
    assert page.input_value('#modal input[readonly]') == 'evetools.app/industry/join/4c81d2a07e'


def test_missing_price_is_unknown_never_zero(page):
    open_hash(page, 'v=projet&tab=materiaux&t=1&s=missing_price')
    assert 'inconnu' in page.locator('tr', has_text='Particle Accelerator Unit').inner_text().lower()


def test_alerts_are_visual_only():
    source = PAGE.read_text()
    for sound in ('<audio', 'new Audio', 'AudioContext', 'speechSynthesis', '.play('):
        assert sound not in source


def test_placeholders_only():
    source = PAGE.read_text()
    for name in set(re.findall(r"'Personnage [^']+'", source)):
        assert re.fullmatch(r"'Personnage (Alpha|Alpha II|Bravo|Charlie|Delta|Echo)'", name)


def test_a_material_can_be_split_between_participants(page):
    open_hash(page, 'v=projet&tab=materiaux&t=4&team=4')
    row = page.locator('tr', has_text='Particle Accelerator Unit').inner_text()
    for member, qty in TEAM['pauSplit'].items():
        assert f"{TEAM['members'][member]['char']}\n×{qty}" in row


def test_partial_reservation_leaves_the_rest_free(page):
    open_hash(page, 'v=projet&tab=materiaux&t=3&team=4')
    row = page.locator('tr', has_text='Particle Accelerator Unit')
    assert 'réservé ×30' in row.inner_text() and '20 libres' in row.inner_text()
    row.locator('[data-qty]').fill('15')
    row.locator('[data-act=reserve]').click()
    page.wait_for_timeout(100)
    text = page.locator('tr', has_text='Particle Accelerator Unit').inner_text()
    assert 'réservé ×15' in text and '5 libres' in text


def test_line_rental_is_day_factor_plus_per_run(page):
    rental = TEAM['rental']
    expected = rental['jobs'] * (rental['dayFactor'] * rental['minutesPerJob'] / 1440 + rental['perRun'] * rental['runsPerJob'])
    assert abs(expected - rental['total']) < 0.01
    text = open_hash(page, 'p=11')
    assert millions(rental['total']) in text
    assert 'Facteur location ligne' in text and 'Module T2' in text


@pytest.mark.parametrize('fragment', ['p=16', 'p=16&long=1', 'p=16&team=1', 'p=16&team=1&long=1'])
def test_discord_recap_fits_one_message(page, fragment):
    open_hash(page, fragment)
    recap = page.evaluate('discordRecap()')
    assert len(recap) <= 2000
    assert recap.startswith('## ') and recap.count('```') % 2 == 0


def test_discord_recap_carries_the_python_figures(page):
    open_hash(page, 'p=16')
    recap = page.evaluate('discordRecap()')
    assert millions(TEAM['profit']) in recap and percent(TEAM['margin'], 2) in recap
    for transfer in TEAM['transfers']:
        assert fr(transfer['amount']) + ' ISK' in recap
    page.click('[data-act=recap-format][data-arg=plain]')
    page.wait_for_timeout(100)
    assert '```' not in page.locator('.copyblock').inner_text()


def test_recommended_structure_per_job(page):
    open_hash(page, 'p=10')
    tree = page.locator('.card', has=page.locator('h2:has-text("Chaîne de production")'))
    assert 'Structure A' in tree.locator('tr', has_text='Division 1/5').inner_text()
    assert 'Structure B' in tree.locator('tr', has_text='Invention').first.inner_text()
    assert 'STRUCTURES · HAMMERHEAD II' in tree.inner_text().upper()


def test_structure_comparison_adds_install_and_transport(page):
    open_hash(page, 'p=10')
    card = page.locator('.card', has=page.locator('h3:has-text("Structures · Hammerhead")')).last
    text = card.inner_text()
    for c in DATA['structures']['compare']:
        assert abs(c['total'] - (c['materials'] + c['install'] + c['transport'])) < 0.01
        assert millions(c['total']) in text
    gain = DATA['structures']['compare'][0]['total'] - min(c['total'] for c in DATA['structures']['compare'])
    assert isk(gain) in text and 'Non retenue' in text


def test_allowing_outside_favorite_system_picks_the_cheaper_structure(page):
    open_hash(page, 'p=10')
    card = page.locator('.card', has=page.locator('h3:has-text("Structures · Hammerhead")')).last
    card.locator('[data-act=allow-outside]').click()
    page.wait_for_timeout(100)
    card = page.locator('.card', has=page.locator('h3:has-text("Structures · Hammerhead")')).last
    assert 'Structure C retenue' in card.inner_text()
    assert 'Structure C' in card.locator('tr[aria-selected=true]').inner_text()


def test_listing_tells_solo_from_group(page):
    open_hash(page, 'p=1')
    table = page.locator('.card', has=page.locator('h2:has-text("Projets")')).first
    assert 'SOLO' in table.inner_text().upper() and 'GROUPE · 4' in table.inner_text().upper()
    table.locator('[data-act=list-filter][data-arg=solo]').click()
    page.wait_for_timeout(100)
    rows = page.locator('.card', has=page.locator('h2:has-text("Projets")')).first.locator('tbody tr').all_inner_texts()
    assert len(rows) == 1 and 'Drake' in rows[0]


def test_opportunities_respect_volume_and_capital(page):
    open_hash(page, 'p=22')
    o = DATA['opportunities']
    for x in o['list']:
        assert x['qtyFit'] <= x['weekQty'] and x['cost'] * x['qtyFit'] <= o['capitalMax'] + 0.01
    best = max(o['list'], key=lambda x: x['myProfit'])
    first = page.locator('.card', has=page.locator('h2:has-text("Quoi produire")')).locator('tbody tr').first.inner_text()
    assert best['name'] in first and isk(best['myProfit']) in first


def test_decryptor_table_matches_python(page):
    text = open_hash(page, 'p=24')
    for d in DATA['decryptors']:
        assert d['name'] in text and isk(d['perUnit']) in text
    assert DATA['decryptorBest'] == 'Sans décrypteur'


def test_fees_come_from_seller_skills(page):
    fees = DATA['fees']
    assert abs(fees['tax'] - 7.5 * (1 - 0.11 * 5)) < 1e-9
    text = open_hash(page, 'v=projet&tab=finances&t=6&team=1')
    assert percent(fees['tax'], 3) in text and percent(fees['broker'], 2) in text
    assert f"{millions(DATA['solo']['profit'])} · {percent(DATA['solo']['margin'])}" in text


def test_price_alert_under_break_even(page):
    text = open_hash(page, 'p=26')
    assert 'sous votre seuil de rentabilité' in text
    calm = open_hash(page, 'v=projet&tab=chaine&t=4&team=1')
    assert 'sous votre seuil de rentabilité' not in calm


def test_reference_older_than_a_month_is_ignored(page):
    open_hash(page, 'p=23')
    modal = page.inner_text('#modal')
    assert 'ignoré : plus d’un mois' in modal and 'il y a 12 j' in modal


def test_doctrine_lists_items_to_buy(page):
    open_hash(page, 'p=23')
    row = page.locator('#modal tr', has_text='Navy Cap Booster 400').inner_text()
    assert '200' in row and 'acheter' in row.lower()


def test_hangar_visibility_needs_a_director(page):
    assert 'Personnage Alpha' in open_hash(page, 'p=27')
    text = open_hash(page, 'p=29')
    assert 'Aucun directeur connecté' in text and 'inconnu' in text.lower()


def test_buy_or_build_includes_jita_import(page):
    open_hash(page, 'v=projet&tab=chaine&t=1&team=1')
    card = page.locator('.card', has=page.locator('h3:has-text("Acheter ou fabriquer")')).last.inner_text()
    hh1 = next(x for x in DATA['buyVsBuild']['list'] if x['item'] == 'Hammerhead I')
    assert hh1['jita'] < hh1['build'] < hh1['buy'] and hh1['choice'] == 'fabriquer'
    assert isk(hh1['buy']) in card and isk(hh1['saving']) in card and 'sans l’import : acheter' in card


def test_other_players_show_their_main_only(page):
    open_hash(page, 'v=projet&tab=equipe&t=4&team=4')
    table = page.locator('.card', has=page.locator('h2:has-text("Participants")')).inner_text()
    assert 'Personnage Alpha II' in table
    assert 'alt 2' not in table and '30' not in table
    open_hash(page, 'v=projet&tab=chaine&t=4&team=4')
    page.click('[data-act=chain][data-arg=tree]')
    page.wait_for_timeout(100)
    tree = page.locator('table.tree').inner_text()
    assert 'Personnage Alpha II' in tree and 'MAIN' in tree.upper()


def test_side_panel_never_scrolls(page):
    open_hash(page, 'p=1')
    page.wait_for_timeout(2200)
    assert page.evaluate("[...document.querySelectorAll('.card.panel')].every(p => p.scrollHeight <= p.clientHeight + 1 && p.scrollWidth <= p.clientWidth + 1)")


def test_tables_have_as_many_headers_as_cells(page):
    for fragment in [f'p={n}' for n in range(1, TOUR_LENGTH + 1)]:
        open_hash(page, fragment)
        bad = page.evaluate('''() => [...document.querySelectorAll('table.data')].filter(t => {
          const head = [...t.querySelectorAll('thead th')].reduce((s, th) => s + (th.colSpan || 1), 0);
          const row = t.querySelector('tbody tr'); if (!head || !row) return false;
          const cells = [...row.children].reduce((s, td) => s + (td.colSpan || 1), 0);
          return cells !== head; }).length''')
        assert bad == 0, fragment


def test_isk_per_slot_hour(page):
    open_hash(page, 'p=31')
    o = DATA['opportunities']['list']
    best = max((x for x in o if x['unit'] > 0 and not x['missingSkills']), key=lambda x: x['iskPerHour'])
    first = page.locator('.card', has=page.locator('h2:has-text("Quoi produire")')).locator('tbody tr').first.inner_text()
    assert best['name'] in first and isk(best['iskPerHour']) in first


def test_blueprint_library(page):
    text = open_hash(page, 'p=30')
    assert 'Hammerhead I Blueprint' in text and 'BPO' in text and 'pas de copie' not in text
    assert 'Director' in text
    corp_unknown = open_hash(page, 'v=blueprints&t=4&s=no_director')
    assert 'Blueprints corpo inconnus' in corp_unknown


def test_undercut_order_alert(page):
    text = open_hash(page, 'p=32')
    assert 'SOUS-COTÉ' in text.upper() and 'COPIER 759 700' in text.upper().replace('\u202f', ' ')
    open_hash(page, 'v=atelier&t=5&team=1')
    assert 'Ordre sous-coté' in page.inner_text('#root')


def test_reaction_chain_counts_surplus_honestly(page):
    open_hash(page, 'p=33')
    r = DATA['reactions']
    pau = next(x for x in DATA['buyVsBuild']['list'] if 'Particle' in x['item'])
    assert pau['choice'] == 'acheter' and pau['buildWithSurplus'] < pau['buy'] < pau['build']
    card = page.locator('.card', has=page.locator('h3:has-text("Réactions")')).last.inner_text()
    for x in r['list']:
        assert x['name'] in card and fmtn(x['surplus']) in card


def fmtn(v):
    return f'{v:,}'.replace(',', ' ')


def test_skills_page_sorted_by_gain_per_training_day(page):
    open_hash(page, 'p=38')
    rows = page.locator('tbody tr').all_inner_texts()
    ranked = [s for s in DATA['skills']['list'] if s['perDay']]
    assert ranked == sorted(ranked, key=lambda s: -s['perDay'])
    assert ranked[0]['name'] in rows[0] and isk(ranked[0]['perDay']) in rows[0]


def test_monthly_report(page):
    text = open_hash(page, 'p=39')
    for m in DATA['report']['months']:
        assert m['month'] in text and isk(m['iskPerHour']) in text


def test_structure_health_and_courier(page):
    open_hash(page, 'p=34')
    health = page.locator('.card', has=page.locator('h3:has-text("Santé des structures")')).last.inner_text()
    assert 'Invention hors ligne' in health and 'carburant 2,5 j' in health and 'inconnus' in health
    assert 'accepté par' in open_hash(page, 'p=35')
    assert 'LIVRÉ · COMPTÉ' in open_hash(page, 'v=projet&tab=materiaux&t=4&team=1').upper()


def test_templates_and_stockpile(page):
    open_hash(page, 'p=36')
    assert 'Doctrine drones · Vexor' in page.inner_text('#modal')
    queue = open_hash(page, 'p=37')
    assert 'Stockpile · Hobgoblin II sous le seuil (20 / 100)' in queue


def test_industry_report_lives_in_revenue_module(page):
    open_hash(page, 'p=39')
    assert page.locator('.breadcrumb').inner_text().upper().startswith('ÉCONOMIE')
    assert page.locator('.nav a[aria-current=page]').inner_text() == 'Revenus'
    page.click('[data-act=rev-tab][data-arg=dashboard]')
    page.wait_for_timeout(100)
    text = page.inner_text('#root')
    assert 'PVE' in text and isk(DATA['report']['months'][-1]['profit']) in text


def test_my_capacity_limits_and_group_hint(page):
    open_hash(page, 'p=22')
    for x in DATA['opportunities']['list']:
        if x['myQty']:
            assert x['myQty'] == min(x['limits'].values())
    card = page.locator('.card', has=page.locator('h2:has-text("Quoi produire")'))
    blocked = card.locator('tr', has_text='Medium Shield Extender II').first.inner_text()
    assert 'Hydromagnetic Physics' in blocked
    warrior = card.locator('tbody tr').first.inner_text().upper()
    assert 'LIMITÉ PAR CAPITAL' in warrior and 'À FAIRE EN GROUPE' in warrior
