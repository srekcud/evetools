import pathlib
import runpy

V2 = runpy.run_path(str(pathlib.Path(__file__).with_name('industry_solo_v2_data.py')))
out = dict(V2['out'])
FEES = V2['FEES']
mats = {m['name']: m for m in out['mats']}
paid = {p['item']: p for p in out['purchases']}

PAU_SPLIT = {'charlie': 30, 'bravo': 20}
RATES = [['Facteur location ligne', 1000000, 'ISK / jour'], ['Compo T1', 250, 'ISK / run'], ['Module T1', 10000, 'ISK / run'], ['Ship T1', 100000, 'ISK / run'],
         ['Module T2', 100000, 'ISK / run'], ['Ship T2', 2500000, 'ISK / run'], ['Cap T1', 25000, 'ISK / run'], ['Cap T2', 125000, 'ISK / run']]
DAY_FACTOR = RATES[0][1]
CATEGORY = 'Module T2'
PER_RUN = next(r[1] for r in RATES if r[0] == CATEGORY)
RENTAL_JOBS, RUNS_PER_JOB, MINUTES_PER_JOB = 5, 10, 277
rental_per_job = DAY_FACTOR * MINUTES_PER_JOB / 1440 + PER_RUN * RUNS_PER_JOB
RENTAL_TOTAL = round(RENTAL_JOBS * rental_per_job, 2)
MEMBERS = {
    'alpha': {'char': 'Personnage Alpha', 'player': 'Joueur Alpha', 'role': 'owner'},
    'bravo': {'char': 'Personnage Bravo', 'player': 'Joueur Bravo', 'role': 'admin'},
    'charlie': {'char': 'Personnage Charlie', 'player': 'Joueur Charlie', 'role': 'member'},
    'delta': {'char': 'Personnage Delta', 'player': 'Joueur Delta', 'role': 'member'},
}


def stock(name):
    m = mats[name]
    return round(m['required'] * m['price'], 2)


def bought(name):
    p = paid[name]
    return round(p['qty'] * p['unit'], 2)


CONTRIBS = [
    ('alpha', 'material', 'Minerais du stock (5 types)', 'stock · prix Jita du jour', sum(stock(n) for n in ['Tritanium', 'Pyerite', 'Mexallon', 'Isogen', 'Zydrine']), 1, 'declared'),
    ('alpha', 'material', 'Datacores ×60', 'stock · prix Jita du jour', out['invention']['datacores'], 0, 'declared'),
    ('alpha', 'bpc', 'Invention Hammerhead II · 15 tentatives', 'job ESI · coût réel', 1245000.0, 0, 'esi'),
    ('alpha', 'job_install', 'Copie Hammerhead I · 15 runs', 'job ESI · coût réel', 45000.0, 0, 'esi'),
    ('alpha', 'job_install', 'Hammerhead I ×50', 'job ESI · coût réel', out['jobCosts']['t1'], 2, 'esi'),
    ('bravo', 'material', 'Robotics ×150', 'achat wallet · prix payé', bought('Robotics'), 1, 'wallet'),
    ('bravo', 'material', 'Morphite ×150', 'achat wallet · prix payé', bought('Morphite'), 1, 'wallet'),
    ('bravo', 'job_install', 'Hammerhead II · 5 jobs × 10 runs', 'jobs ESI · coût réel', len(out['divisions']) * out['jobCosts']['final'], 4, 'esi'),
    ('charlie', 'material', 'Guidance Systems ×150', 'achat wallet · prix payé', bought('Guidance Systems'), 1, 'wallet'),
    ('charlie', 'material', 'R.A.M.- Robotics ×50', 'achat wallet · prix payé', bought('R.A.M.- Robotics'), 1, 'wallet'),
    ('charlie', 'material', 'Particle Accelerator Unit ×30', 'achat wallet · prix payé', PAU_SPLIT['charlie'] * paid['Particle Accelerator Unit']['unit'], 4, 'wallet'),
    ('bravo', 'material', 'Particle Accelerator Unit ×20', 'achat wallet · prix payé', PAU_SPLIT['bravo'] * paid['Particle Accelerator Unit']['unit'], 4, 'wallet'),
    ('delta', 'line_rental', '5 lignes · 5 jobs de 4 h 37 · 50 runs', 'facteur par jour × durée ESI + tarif par run', RENTAL_TOTAL, 4, 'declared'),
]
contribs = [{'id': f'C{i + 1}', 'member': m, 'type': t, 'label': l, 'method': meth, 'value': round(v, 2), 'from': t0, 'source': src}
            for i, (m, t, l, meth, v, t0, src) in enumerate(CONTRIBS)]

solo_cost = out['productionCost']
team_cost = round(sum(c['value'] for c in contribs), 2)
assert abs(team_cost - (solo_cost + RENTAL_TOTAL)) < 0.01

final = out['final']
net = final['revenue'] - final['taxes']
ratio = (net - team_cost) / team_cost
members = {}
for c in contribs:
    members.setdefault(c['member'], 0)
    members[c['member']] += c['value']
payouts = {k: {'costs': round(v, 2), 'share': v / team_cost * 100, 'payout': v * (1 + ratio)} for k, v in members.items()}
assert abs(sum(p['payout'] for p in payouts.values()) - net) < 0.01

SELLERS = {'S1': 'alpha', 'S2': 'bravo'}
held = {}
for s in out['sales']:
    gross = s['qty'] * s['unit']
    held[SELLERS[s['id']]] = held.get(SELLERS[s['id']], 0) + (gross * (1 - FEES) if s['channel'] == 'market' else gross - (s['fees'] or 0))
balance = {k: round(held.get(k, 0) - p['payout'], 2) for k, p in payouts.items()}
debtors = sorted([[k, v] for k, v in balance.items() if v > 0], key=lambda x: -x[1])
creditors = sorted([[k, -v] for k, v in balance.items() if v < 0], key=lambda x: -x[1])
transfers, i, j = [], 0, 0
while i < len(debtors) and j < len(creditors):
    amount = round(min(debtors[i][1], creditors[j][1]), 2)
    if amount >= 0.01:
        transfers.append({'from': debtors[i][0], 'to': creditors[j][0], 'amount': amount})
    debtors[i][1] = round(debtors[i][1] - amount, 2)
    creditors[j][1] = round(creditors[j][1] - amount, 2)
    if debtors[i][1] < 0.01:
        i += 1
    if creditors[j][1] < 0.01:
        j += 1

assert sum(PAU_SPLIT.values()) == paid['Particle Accelerator Unit']['qty']
out['team'] = {'pauSplit': PAU_SPLIT, 'members': MEMBERS, 'contribs': contribs, 'cost': team_cost, 'net': net, 'profit': net - team_cost, 'margin': ratio * 100,
               'payouts': payouts, 'held': held, 'transfers': transfers, 'rental': {'rates': RATES, 'category': CATEGORY, 'dayFactor': DAY_FACTOR, 'perRun': PER_RUN, 'jobs': RENTAL_JOBS, 'runsPerJob': RUNS_PER_JOB, 'minutesPerJob': MINUTES_PER_JOB, 'perJob': rental_per_job, 'total': RENTAL_TOTAL}}

solo_profit = final['revenue'] - final['taxes'] - solo_cost
out['solo'] = {'cost': solo_cost, 'profit': solo_profit, 'margin': solo_profit / solo_cost * 100}
assert abs(out['team']['profit'] - (solo_profit - RENTAL_TOTAL)) < 0.01

import math

V1 = V2['V1']
FINAL_MATS = V1['FINAL']
SCC = 0.04
JOB_COST_A = out['jobCosts']['final'] / 10
STRUCTURES = [
    {'key': 'A', 'name': 'Structure A', 'kind': 'Raitaru', 'system': 'Système A', 'jumps': 0, 'favorite': True, 'role': 1.0, 'rig': 0.0, 'rigLabel': 'aucun', 'index': 4.2, 'costRole': 3.0, 'tax': 1.0},
    {'key': 'B', 'name': 'Structure B', 'kind': 'Azbel', 'system': 'Système B', 'jumps': 1, 'favorite': False, 'role': 1.0, 'rig': 0.0, 'rigLabel': 'ME T1 navires', 'index': 2.9, 'costRole': 5.0, 'tax': 2.5},
    {'key': 'C', 'name': 'Structure C', 'kind': 'Sotiyo', 'system': 'Système C (nullsec)', 'jumps': 4, 'favorite': False, 'role': 1.0, 'rig': 2.0 * 2.1, 'rigLabel': 'ME T1 drones × 2,1 (nullsec)', 'index': 1.8, 'costRole': 5.0, 'tax': 0.5},
]
A = STRUCTURES[0]
EIV_PER_RUN = JOB_COST_A / (A['index'] / 100 * (1 - A['costRole'] / 100) + A['tax'] / 100 + SCC)
hh1_mats = sum(next(m for m in out['mats'] if m['name'] == n)['required'] * PRICE for n, PRICE in [(n, next(m for m in out['mats'] if m['name'] == n)['price']) for n in ['Tritanium', 'Pyerite', 'Mexallon', 'Isogen', 'Zydrine']])
HH1_UNIT = (hh1_mats + out['jobCosts']['t1']) / 50
UNIT_PRICE = {m['name']: m['price'] for m in out['mats']}
UNIT_PRICE['Hammerhead I'] = HH1_UNIT
VOLUME = {'Guidance Systems': 3.0, 'Hammerhead I': 10.0, 'Morphite': 0.01, 'Particle Accelerator Unit': 1.0, 'R.A.M.- Robotics': 0.04, 'Robotics': 3.0}
PRODUCT_VOLUME = 10.0
TRANSPORT_PER_M3_JUMP = 150.0


def qty_with(base, runs, me, multiplier):
    return max(runs, math.ceil(round(runs * base * (1 - me / 100) * multiplier, 2)))


compare = []
for s in STRUCTURES:
    mult = (1 - s['role'] / 100) * (1 - s['rig'] / 100)
    qty = {n: sum(qty_with(b, r, 2, mult) for r in out['divisions']) for n, b in FINAL_MATS.items()}
    materials = sum(q * UNIT_PRICE[n] for n, q in qty.items())
    install = len(out['divisions']) * 10 * EIV_PER_RUN * (s['index'] / 100 * (1 - s['costRole'] / 100) + s['tax'] / 100 + SCC)
    m3 = sum(q * VOLUME[n] for n, q in qty.items()) + 50 * PRODUCT_VOLUME
    transport = m3 * s['jumps'] * TRANSPORT_PER_M3_JUMP
    compare.append({'key': s['key'], 'materials': round(materials, 2), 'install': round(install, 2), 'm3': round(m3, 2), 'transport': round(transport, 2),
                    'total': round(materials + install + transport, 2), 'qty': qty})
base_total = compare[0]['total']
for c in compare:
    c['delta'] = round(c['total'] - base_total, 2)
best = min(compare, key=lambda c: c['total'])
assert abs(compare[0]['install'] - len(out['divisions']) * out['jobCosts']['final']) < 0.01
out['structures'] = {'list': STRUCTURES, 'compare': compare, 'best': best['key'], 'eivPerRun': EIV_PER_RUN, 'scc': SCC, 'transportPerM3Jump': TRANSPORT_PER_M3_JUMP, 'minGain': 500000}

# ---------- V3.2 : frais réels du vendeur (l'owner vend) ----------
SKILLS = {'accounting': 5, 'brokerRelations': 5, 'factionStanding': 2.0, 'corpStanding': 3.0}
SALES_TAX = 7.5 * (1 - 0.11 * SKILLS['accounting'])
BROKER_JITA = 3.0 - 0.3 * SKILLS['brokerRelations'] - 0.03 * SKILLS['factionStanding'] - 0.02 * SKILLS['corpStanding']
FEES2 = (SALES_TAX + BROKER_JITA) / 100
SELLERS = {'S1': 'alpha', 'S2': 'alpha'}
sales = out['sales']
production = out['productionCost']


def outcome(ids, rental=0.0):
    sold = [s for s in sales if s['id'] in ids]
    revenue = sum(s['qty'] * s['unit'] for s in sold)
    market = sum(s['qty'] * s['unit'] for s in sold if s['channel'] == 'market')
    taxes = market * FEES2
    cost = production + rental
    profit = revenue - taxes - cost
    return {'revenue': revenue, 'market': market, 'taxes': taxes, 'cost': cost, 'profit': profit, 'margin': profit / cost * 100, 'unsold': V1['RUNS'] - sum(s['qty'] for s in sold)}


solo_final, solo_partial = outcome(['S1', 'S2']), outcome(['S1'])
team_final = outcome(['S1', 'S2'], RENTAL_TOTAL)
net2 = team_final['revenue'] - team_final['taxes']
ratio2 = (net2 - team_cost) / team_cost
payouts2 = {k: {'costs': round(v, 2), 'share': v / team_cost * 100, 'payout': v * (1 + ratio2)} for k, v in members.items()}
held2 = {'alpha': net2}
bal = {k: round(held2.get(k, 0) - p['payout'], 2) for k, p in payouts2.items()}
cred = sorted([[k, -v] for k, v in bal.items() if v < 0], key=lambda x: -x[1])
transfers2 = [{'from': 'alpha', 'to': k, 'amount': round(a, 2)} for k, a in cred]
assert abs(sum(t['amount'] for t in transfers2) - bal['alpha']) < 0.05
break_even = lambda cost: cost / (V1['RUNS'] * (1 - FEES2))
out['fees'] = {'broker': round(BROKER_JITA, 4), 'tax': round(SALES_TAX, 4), 'skills': SKILLS}
out['final'], out['partial'] = solo_final, solo_partial
out['estimate'] = dict(out['estimate'], taxes=out['estimate']['revenue'] * FEES2)
out['estimate']['profit'] = out['estimate']['revenue'] - out['estimate']['taxes'] - out['estimate']['material'] - out['estimate']['jobs'] - out['estimate']['invention']
out['breakEvenUnit'] = break_even(production)
out['solo'] = {'cost': production, 'profit': solo_final['profit'], 'margin': solo_final['margin'], 'breakEven': break_even(production)}
out['team'].update({'net': net2, 'profit': net2 - team_cost, 'margin': ratio2 * 100, 'payouts': payouts2, 'held': held2, 'transfers': transfers2, 'breakEven': break_even(team_cost)})


def simulate2(price, channel):
    p = solo_partial
    extra = p['unsold'] * price
    taxes = p['taxes'] + (extra * FEES2 if channel == 'market' else 0)
    profit = p['revenue'] + extra - taxes - production
    return {'revenue': p['revenue'] + extra, 'taxes': taxes, 'profit': profit, 'percent': profit / production * 100}


out['simulations'] = {f'{pr}-{ch}': simulate2(pr, ch) for pr in (700000, 748000, 760000, 800000) for ch in ('market', 'contract')}
assert abs(out['simulations']['748000-contract']['profit'] - solo_final['profit']) < 0.01

# ---------- Décrypteurs (SDE : probabilité, runs, ME, TE) ----------
DECRYPTORS = [('Sans décrypteur', 1.0, 0, 0, 0, 0.0), ('Accelerant Decryptor', 1.2, 1, 2, 10, 1050000.0), ('Attainment Decryptor', 1.8, 4, -1, 4, 1480000.0),
              ('Augmentation Decryptor', 0.6, 9, -2, 2, 520000.0), ('Optimized Attainment Decryptor', 1.9, 2, 1, -2, 1250000.0),
              ('Optimized Augmentation Decryptor', 0.9, 7, 2, 0, 940000.0), ('Parity Decryptor', 1.5, 3, 1, -2, 1390000.0),
              ('Process Decryptor', 1.1, 0, 3, 6, 980000.0), ('Symmetry Decryptor', 1.0, 2, 1, 8, 860000.0)]
DATACORES_PER_ATTEMPT = 2 * 98400.0 + 2 * 111900.0
INVENTION_JOB = 1245000.0 / 15
COPY_RUN = 45000.0 / 15
decryptors = []
for name, prob, runs_mod, me_mod, te_mod, price in DECRYPTORS:
    bpc_runs = 10 + runs_mod
    p = 0.34 * prob
    attempts = math.ceil(V1['RUNS'] / (bpc_runs * p))
    invention = attempts * (DATACORES_PER_ATTEMPT + price + INVENTION_JOB + COPY_RUN)
    me = 2 + me_mod
    n = math.ceil(V1['RUNS'] / bpc_runs)
    base_runs, extra = divmod(V1['RUNS'], n)
    job_runs = [base_runs + 1 if i < extra else base_runs for i in range(n)]
    mats = sum(qty_with(b, r, me, 0.99) * UNIT_PRICE[m] for r in job_runs for m, b in FINAL_MATS.items())
    total = invention + mats
    decryptors.append({'name': name, 'prob': round(p * 100, 1), 'bpcRuns': bpc_runs, 'me': me, 'te': 4 + te_mod, 'attempts': attempts, 'jobs': n,
                       'invention': round(invention, 2), 'materials': round(mats, 2), 'total': round(total, 2), 'perUnit': round(total / V1['RUNS'], 2), 'price': price})
base_dec = decryptors[0]['total']
for d in decryptors:
    d['delta'] = round(d['total'] - base_dec, 2)
out['decryptors'] = decryptors
out['decryptorBest'] = min(decryptors, key=lambda d: d['total'])['name']

# ---------- Profondeur de marché (données de marché fictives) ----------
BUY_BOOK = [(745000, 12), (738000, 18), (725000, 25), (700000, 40)]
def dump(qty):
    left, value = qty, 0
    for price, q in BUY_BOOK:
        take = min(left, q); value += take * price; left -= take
        if not left: break
    return value / qty
MARKETS = [
    {'key': 'jita', 'name': 'Jita IV - Moon 4', 'sell': 760000, 'volume': 180, 'broker': BROKER_JITA, 'tax': SALES_TAX},
    {'key': 'capitale', 'name': 'Marché de la capitale (structure préférée)', 'sell': 845000, 'volume': 14, 'broker': 1.0, 'tax': SALES_TAX},
]
SHARE = 0.10
for m in MARKETS:
    m['net'] = m['sell'] * (1 - (m['broker'] + m['tax']) / 100)
    m['days'] = V1['RUNS'] / (m['volume'] * SHARE)
out['market'] = {'markets': MARKETS, 'share': SHARE, 'buyBook': BUY_BOOK, 'dumpAvg': dump(V1['RUNS']), 'dumpNet': dump(V1['RUNS']) * (1 - SALES_TAX / 100)}

# ---------- Quoi produire (données de marché et coûts fictifs, sauf Hammerhead II) ----------
OPP = [
    ('Hammerhead II', production / V1['RUNS'], 760000, 180, 845000, 14, 'drone moyen T2'),
    ('Hobgoblin II', 412000, 455000, 420, 498000, 30, 'drone léger T2'),
    ('Warrior II', 268000, 318000, 610, 345000, 41, 'drone léger T2'),
    ('Damage Control II', 515000, 598000, 950, 640000, 75, 'module T2'),
    ('Drone Damage Amplifier II', 1610000, 1735000, 260, 1890000, 18, 'module T2'),
    ('Multispectrum Energized Membrane II', 1180000, 1325000, 140, 1495000, 9, 'module T2'),
    ('Medium Shield Extender II', 1980000, 2050000, 70, 2390000, 6, 'module T2'),
    ('Nitrogen Fuel Block', 14200, 15800, 52000, 17600, 4200, 'carburant'),
]
opps = []
for name, cost, jita, vj, cap, vc, kind in OPP:
    for mk, price, vol, broker in (('jita', jita, vj, BROKER_JITA), ('capitale', cap, vc, 1.0)):
        net = price * (1 - (broker + SALES_TAX) / 100)
        week_qty = math.floor(vol * 7 * SHARE)
        opps.append({'name': name, 'kind': kind, 'market': mk, 'cost': round(cost, 2), 'price': price, 'volume': vol, 'net': round(net, 2), 'unit': round(net - cost, 2),
                     'margin': (net - cost) / cost * 100, 'weekQty': week_qty, 'weekProfit': round((net - cost) * week_qty, 2), 'capital': round(cost * week_qty, 2)})
out['opportunities'] = {'list': opps, 'share': SHARE, 'capitalMax': 50000000}

# ---------- Surplus, référence, transport ----------
out['surplus'] = {'bpc': 1, 'bpcRuns': 10, 'value': round(out['invention']['computed'] / 5, 2)}
out['reference'] = [{'name': 'Hammerhead II · doctrine (octobre)', 'ageDays': 12, 'unitCost': 671200, 'margin': 6.8, 'delay': '+9 h 10', 'used': True},
                    {'name': 'Hammerhead II · campagne d’été', 'ageDays': 47, 'unitCost': 702500, 'margin': 3.1, 'delay': '+1 j 4 h', 'used': False}]
VOLUMES = {'Robotics': 3.0, 'Guidance Systems': 3.0, 'Morphite': 0.01, 'Particle Accelerator Unit': 1.0, 'R.A.M.- Robotics': 0.04}
haul_m3 = sum(p['qty'] * VOLUMES[p['item']] for p in out['purchases'])
haul_value = sum(p['qty'] * p['unit'] for p in out['purchases'])
JUMPS_JITA = 9
out['hauling'] = {'m3': round(haul_m3, 2), 'collateral': round(haul_value, 2), 'jumps': JUMPS_JITA, 'reward': round(haul_m3 * JUMPS_JITA * TRANSPORT_PER_M3_JUMP, 2)}
for o in out['opportunities']['list']:
    o['qtyFit'] = min(o['weekQty'], math.floor(out['opportunities']['capitalMax'] / o['cost']))
    o['profitFit'] = round(o['unit'] * o['qtyFit'], 2)
    o['days'] = o['qtyFit'] / (o['volume'] * SHARE) if o['volume'] else None

# ---------- Acheter ou fabriquer (import depuis Jita compris) ----------
IMPORT_PER_M3 = JUMPS_JITA * TRANSPORT_PER_M3_JUMP
bvb = []
for item, qty, build, jita, volume in [('Hammerhead I', 50, HH1_UNIT, 31900.0, 10.0), ('Particle Accelerator Unit', 50, None, 30900.0, 1.0)]:
    imp = volume * IMPORT_PER_M3
    buy = jita + imp
    choice = 'acheter' if build is None or buy < build else 'fabriquer'
    bvb.append({'item': item, 'qty': qty, 'build': None if build is None else round(build, 2), 'jita': jita, 'volume': volume, 'import': imp, 'buy': buy,
                'choice': choice, 'withoutImport': 'acheter' if build is None or jita < build else 'fabriquer',
                'saving': None if build is None else round(abs(buy - build) * qty, 2)})
out['buyVsBuild'] = {'list': bvb, 'importPerM3': IMPORT_PER_M3, 'jumps': JUMPS_JITA}
out['team']['chars'] = {'alpha': ['Personnage Alpha', 'Personnage Alpha II'], 'bravo': ['Personnage Bravo'] + [f'alt {i}' for i in range(2, 31)], 'charlie': ['Personnage Charlie', 'alt 2', 'alt 3'], 'delta': ['Personnage Delta', 'alt 2']}

# ---------- V3.4 · ISK par heure de slot ----------
BASE_TIME = {'Hammerhead II': (3000, 1), 'Hobgoblin II': (1500, 1), 'Warrior II': (1500, 1), 'Damage Control II': (2340, 1), 'Drone Damage Amplifier II': (2340, 1),
             'Multispectrum Energized Membrane II': (2340, 1), 'Medium Shield Extender II': (2340, 1), 'Nitrogen Fuel Block': (900, 40)}
T2_FACTOR = 1662 / 3000
T1_FACTOR = T2_FACTOR * 0.80 / 0.96
for o in out['opportunities']['list']:
    base, per_run = BASE_TIME[o['name']]
    factor = T1_FACTOR if o['name'] == 'Nitrogen Fuel Block' else T2_FACTOR
    o['secondsPerUnit'] = base * factor / per_run
    o['iskPerHour'] = round(o['unit'] * 3600 / o['secondsPerUnit'], 2)
    o['slotHours'] = round(o['qtyFit'] * o['secondsPerUnit'] / 3600, 2)
out['opportunities']['timeAssumption'] = 'TE 4 pour un T2, TE 20 pour un T1, structure −15 %, Industry V, Advanced Industry V'

# ---------- V3.4 · bibliothèque de blueprints ----------
HOB1_ME = {'me8': None, 'me10': None}
hob1 = {'Isogen': 4, 'Nocxium': 2, 'Pyerite': 7, 'Tritanium': 509}
HOB_PRICE = {'Isogen': 139.0, 'Nocxium': 645.0, 'Pyerite': 9.85, 'Tritanium': 4.12}
cost_at = lambda me: sum(qty_with(b, 30, me, 0.99) * HOB_PRICE[n] for n, b in hob1.items())
out['blueprints'] = {
    'list': [
        {'name': 'Hammerhead I Blueprint', 'kind': 'BPO', 'me': 10, 'te': 20, 'runs': None, 'owner': 'perso', 'where': 'Personnage Alpha · « Prod HH »', 'status': 'utilisé par Hammerhead II · doctrine'},
        {'name': 'Hammerhead II Blueprint', 'kind': 'BPC', 'me': 2, 'te': 4, 'runs': 10, 'count': 5, 'owner': 'perso', 'where': 'Personnage Alpha · « Prod HH »', 'status': 'inventés pour le projet'},
        {'name': 'Hammerhead II Blueprint', 'kind': 'BPC', 'me': 2, 'te': 4, 'runs': 10, 'count': 1, 'owner': 'perso', 'where': 'Personnage Alpha II · hangar', 'status': 'surplus · Stockpile'},
        {'name': 'Hobgoblin I Blueprint', 'kind': 'BPO', 'me': 8, 'te': 16, 'runs': None, 'owner': 'perso', 'where': 'Personnage Alpha · hangar', 'status': 'libre'},
        {'name': 'Drake Blueprint', 'kind': 'BPO', 'me': 10, 'te': 20, 'runs': None, 'owner': 'corpo', 'where': 'Corp A · Division 4 « Doctrine »', 'status': 'en job · Personnage Alpha II'},
        {'name': 'Vexor Blueprint', 'kind': 'BPC', 'me': 10, 'te': 20, 'runs': 3, 'count': 2, 'owner': 'corpo', 'where': 'Corp A · Division 2 « Projets de groupe »', 'status': 'libre'},
        {'name': 'Damage Control II Blueprint', 'kind': 'BPC', 'me': 2, 'te': 4, 'runs': 1, 'count': 4, 'owner': 'corpo', 'where': 'Corp A · Division 5 « Direction »', 'status': 'masqué pour vous'},
    ],
    'research': {'name': 'Hobgoblin I Blueprint', 'from': 8, 'to': 10, 'runs': 30, 'costMe8': round(cost_at(8), 2), 'costMe10': round(cost_at(10), 2)},
}
r = out['blueprints']['research']
r['saving'] = round(r['costMe8'] - r['costMe10'], 2)

# ---------- V3.4 · mes ordres de vente ----------
out['orders'] = [
    {'item': 'Hammerhead II', 'market': 'Jita IV - Moon 4', 'qty': 20, 'mine': 759900, 'best': 759800, 'rank': 2, 'since': '14 min', 'char': 'Personnage Alpha'},
    {'item': 'Hobgoblin II', 'market': 'Marché de la capitale (structure préférée)', 'qty': 12, 'mine': 497500, 'best': 497500, 'rank': 1, 'since': None, 'char': 'Personnage Alpha II'},
]

# ---------- V3.5 · chaîne de réactions du Particle Accelerator Unit (SDE, prix fictifs) ----------
REACT_PRICE = {'Carbon Polymers': 9800.0, 'Crystallite Alloy': 11200.0, 'Platinum Technite': 21500.0, 'Dysporite': 32000.0, 'Solerium': 28000.0,
               'Vanadium Hafnite': 41000.0, 'Helium Fuel Block': 17000.0, 'Nitrogen Fuel Block': 17000.0, 'Oxygen Fuel Block': 17000.0}
REACTIONS = [
    ('Crystalline Carbonide', 10000, {'Carbon Polymers': 100, 'Crystallite Alloy': 100, 'Helium Fuel Block': 5}, 31),
    ('Fullerides', 3000, {'Carbon Polymers': 100, 'Platinum Technite': 100, 'Nitrogen Fuel Block': 5}, 11),
    ('Hypersynaptic Fibers', 750, {'Dysporite': 100, 'Solerium': 100, 'Vanadium Hafnite': 100, 'Oxygen Fuel Block': 5}, 1),
]
REACTION_HOURS, REACTION_STRUCT_FACTOR = 3.0, 0.75
REACTION_INSTALL = 38000.0
PAU_COUNT, PAU_JOB = 50, 30000.0
reactions = []
pau_mats = 0.0
for name, out_qty, inputs, per_pau in REACTIONS:
    need = qty_with(per_pau, PAU_COUNT, 10, 0.99)
    runs = math.ceil(need / out_qty)
    run_cost = sum(q * REACT_PRICE[i] for i, q in inputs.items()) + REACTION_INSTALL
    unit = run_cost / out_qty
    used = need * unit
    surplus = runs * out_qty - need
    pau_mats += used
    reactions.append({'name': name, 'need': need, 'runs': runs, 'out': out_qty * runs, 'inputs': inputs, 'runCost': round(run_cost, 2), 'unit': round(unit, 2),
                      'used': round(used, 2), 'surplus': surplus, 'surplusValue': round(surplus * unit, 2), 'hours': REACTION_HOURS * REACTION_STRUCT_FACTOR})
pau_build_unit = (pau_mats + PAU_JOB) / PAU_COUNT
pau = next(x for x in out['buyVsBuild']['list'] if x['item'] == 'Particle Accelerator Unit')
full_run_unit = (sum(r['runs'] * r['runCost'] for r in reactions) + PAU_JOB) / PAU_COUNT
pau.update({'build': round(full_run_unit, 2), 'buildWithSurplus': round(pau_build_unit, 2), 'choice': 'acheter', 'withoutImport': 'acheter',
            'saving': round((full_run_unit - pau['buy']) * PAU_COUNT, 2), 'savingIfReused': round((pau['buy'] - pau_build_unit) * PAU_COUNT, 2)})
out['reactions'] = {'list': reactions, 'prices': REACT_PRICE, 'pauBuildUnit': pau_build_unit, 'install': REACTION_INSTALL, 'pauJob': PAU_JOB,
                    'structure': 'Structure D · Tatara (réactions) · Système D, 2 sauts', 'surplusValue': round(sum(r['surplusValue'] for r in reactions), 2)}

# ---------- V3.5 · compétences les plus rentables ----------
MONTH_PROJECTS = 3
skills = []
inv_now = 0.34 * (1 + (4 + 4) / 30 + 3 / 40)
inv_next = 0.34 * (1 + (5 + 4) / 30 + 3 / 40)
att_now, att_next = math.ceil(50 / (10 * inv_now)), math.ceil(50 / (10 * inv_next))
per_attempt = DATACORES_PER_ATTEMPT + INVENTION_JOB + COPY_RUN
expected_gain = (50 / (10 * inv_now) - 50 / (10 * inv_next)) * per_attempt * MONTH_PROJECTS
skills.append({'name': 'Mechanical Engineering', 'from': 4, 'to': 5, 'days': 9.2, 'effect': f'invention {inv_now * 100:.1f} % → {inv_next * 100:.1f} %'.replace('.', ','), 'gain': round(expected_gain, 2)})
slot_hours_month = 3 * (len(out['divisions']) * 10 * 1662 / 3600 + 50 * 600 * 0.55 / 3600)
skills.append({'name': 'Advanced Industry', 'from': 4, 'to': 5, 'days': 12.6, 'effect': 'temps de fabrication −3 %', 'gain': round(slot_hours_month * 0.03 * 321136 / 1, 2)})
skills.append({'name': 'Reactions', 'from': 3, 'to': 4, 'days': 4.1, 'effect': 'temps de réaction −4 %', 'gain': round(3 * 3 * REACTION_HOURS * 0.75 * 0.04 * 300000, 2)})
skills.append({'name': 'Drones', 'from': 5, 'to': 5, 'days': 0, 'effect': 'déjà au maximum', 'gain': 0.0})
skills.append({'name': 'Broker Relations', 'from': 5, 'to': 5, 'days': 0, 'effect': 'déjà au maximum', 'gain': 0.0})
for s in skills:
    s['perDay'] = round(s['gain'] / s['days'], 2) if s['days'] else None
skills.sort(key=lambda x: -(x['perDay'] or -1))
out['skills'] = {'list': skills, 'months': 1, 'projects': MONTH_PROJECTS, 'inventionNow': inv_now, 'inventionNext': inv_next, 'attemptsNow': att_now, 'attemptsNext': att_next}

# ---------- V3.5 · bilan mensuel (projets terminés, fictifs sauf novembre) ----------
MONTHS = [('Août', 4, 61200000, 2.9, 38, 210000000), ('Septembre', 5, 88400000, 3.4, 41, 245000000), ('Octobre', 6, 97100000, 3.1, 47, 260000000)]
report = [{'month': m, 'projects': n, 'profit': p, 'margin': mg, 'slotHours': sh * 10, 'capital': c, 'iskPerHour': round(p / (sh * 10), 2)} for m, n, p, mg, sh, c in MONTHS]
nov_hours = len(out['divisions']) * 10 * 1662 / 3600 + 50 * 600 * 0.55 / 3600
report.append({'month': 'Novembre', 'projects': 1, 'profit': round(solo_final['profit'], 2), 'margin': round(solo_final['margin'], 2), 'slotHours': round(nov_hours, 2), 'capital': round(production, 2),
               'iskPerHour': round(solo_final['profit'] / nov_hours, 2)})
by_product = [('Hammerhead II', 4, round(solo_final['profit'] + 21400000, 2)), ('Drake', 3, 58200000.0), ('Nitrogen Fuel Block', 6, 22800000.0), ('Damage Control II', 2, 9100000.0), ('Hobgoblin II', 2, -1800000.0)]
out['report'] = {'months': report, 'byProduct': [{'name': n, 'projects': k, 'profit': p} for n, k, p in by_product]}

# ---------- V3.5 · santé des structures, courrier, modèles, stockpile ----------
out['structureHealth'] = [{'name': 'Structure A', 'fuelDays': 2.5, 'services': 'Fabrication en ligne', 'state': 'warning'},
                          {'name': 'Structure B', 'fuelDays': 41, 'services': 'Invention hors ligne', 'state': 'danger'},
                          {'name': 'Structure C', 'fuelDays': None, 'services': None, 'state': 'unknown'}]
out['courier'] = [{'t': 1, 'status': 'outstanding', 'label': 'en attente d’un transporteur'}, {'t': 2, 'status': 'in_progress', 'label': 'accepté par Personnage Echo'},
                  {'t': 3, 'status': 'finished', 'label': 'livré le 03/11 06:40'}]
out['templates'] = [{'name': 'Doctrine drones · Vexor', 'items': 8, 'by': 'Personnage Bravo', 'shared': 'Corp A', 'uses': 6, 'last': 'il y a 9 j'},
                    {'name': 'Hammerhead II ×50', 'items': 1, 'by': 'Personnage Alpha', 'shared': 'personnel', 'uses': 3, 'last': 'aujourd’hui'},
                    {'name': 'Carburant hebdomadaire', 'items': 4, 'by': 'Personnage Bravo', 'shared': 'Corp A', 'uses': 11, 'last': 'il y a 2 j'}]
out['stockpile'] = [{'item': 'Hobgoblin II', 'have': 20, 'target': 100, 'missing': 80, 'runs': 80},
                    {'item': 'Nitrogen Fuel Block', 'have': 1800, 'target': 2000, 'missing': 200, 'runs': 5}]

# ---------- V3.6 · capacité personnelle dans « Quoi produire » ----------
REQUIRED = {'Hammerhead II': ['Electronic Engineering', 'Graviton Physics', 'Industry'], 'Hobgoblin II': ['Electronic Engineering', 'Graviton Physics', 'Industry'],
            'Warrior II': ['Electronic Engineering', 'Graviton Physics', 'Industry'], 'Drone Damage Amplifier II': ['Electronic Engineering', 'Graviton Physics', 'Industry'],
            'Damage Control II': ['Industry', 'Molecular Engineering', 'Nanite Engineering'], 'Multispectrum Energized Membrane II': ['Hydromagnetic Physics', 'Industry', 'Nanite Engineering'],
            'Medium Shield Extender II': ['Hydromagnetic Physics', 'Industry', 'Quantum Physics'], 'Nitrogen Fuel Block': ['Industry']}
MY_SKILLS = {'Industry': 5, 'Electronic Engineering': 5, 'Graviton Physics': 4, 'Molecular Engineering': 4, 'Nanite Engineering': 3, 'Hydromagnetic Physics': 0, 'Quantum Physics': 2}
WALLET = 182400000.0
CAPITAL_CAP = 60000000.0
FREE_SLOTS = 14
WEEK_HOURS = 168
for o in out['opportunities']['list']:
    missing = [s for s in REQUIRED[o['name']] if MY_SKILLS.get(s, 0) < (5 if s == 'Industry' and o['name'] != 'Nitrogen Fuel Block' else 1)]
    o['missingSkills'] = missing
    limits = {'marché': o['weekQty'], 'capital': math.floor(min(WALLET, CAPITAL_CAP) / o['cost']), 'slots': math.floor(FREE_SLOTS * WEEK_HOURS * 3600 / o['secondsPerUnit'])}
    o['limits'] = limits
    o['myQty'] = 0 if missing or o['unit'] <= 0 else min(limits.values())
    o['limitedBy'] = None if not o['myQty'] else min(limits, key=limits.get)
    o['myProfit'] = round(o['unit'] * o['myQty'], 2)
    o['groupHint'] = bool(missing and o['unit'] > 0) or (o['limitedBy'] in ('capital', 'slots') and o['weekQty'] > o['myQty'])
out['opportunities']['me'] = {'wallet': WALLET, 'cap': CAPITAL_CAP, 'freeSlots': FREE_SLOTS, 'skills': MY_SKILLS}
