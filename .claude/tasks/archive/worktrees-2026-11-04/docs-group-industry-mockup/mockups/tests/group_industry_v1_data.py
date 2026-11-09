import json, math

STRUCT = 0.99
def qty(base, runs, me):
    exact = runs * base * (1 - me / 100) * STRUCT
    return max(runs, math.ceil(round(exact, 2)))

SDE = {
 'Hammerhead II': {'id': 2185, 'bp': 2186, 'max': 10, 'mats': {'Guidance Systems': 3, 'Hammerhead I': 1, 'Morphite': 3, 'Particle Accelerator Unit': 1, 'R.A.M.- Robotics': 1, 'Robotics': 3}},
 'Hobgoblin II': {'id': 2456, 'bp': 2457, 'max': 10, 'mats': {'Guidance Systems': 1, 'Hobgoblin I': 1, 'Morphite': 1, 'Particle Accelerator Unit': 1, 'R.A.M.- Robotics': 1, 'Robotics': 1}},
 'Hammerhead I': {'id': 2183, 'bp': 2184, 'max': 300, 'mats': {'Isogen': 11, 'Mexallon': 224, 'Pyerite': 2, 'Tritanium': 3413, 'Zydrine': 4}, 'inv': {'Datacore - Electronic Engineering': 2, 'Datacore - Graviton Physics': 2}},
 'Hobgoblin I': {'id': 2454, 'bp': 2455, 'max': 600, 'mats': {'Isogen': 4, 'Nocxium': 2, 'Pyerite': 7, 'Tritanium': 509}, 'inv': {'Datacore - Electronic Engineering': 1, 'Datacore - Graviton Physics': 1}},
}
T1_OF = {'Hammerhead II': 'Hammerhead I', 'Hobgoblin II': 'Hobgoblin I'}
INV_PROB, INV_RUNS = 0.34, 10
ITEMS = [('Hammerhead II', 2, 4, 50), ('Hobgoblin II', 2, 4, 30)]
BLACKLIST = ['Particle Accelerator Unit', 'R.A.M.- Robotics']

def split(runs, mx):
    n = math.ceil(runs / mx); base, extra = divmod(runs, n)
    return [base + 1 if i < extra else base for i in range(n)]

jobs, demand = [], {}
def add_jobs(product, runs, me, te, group):
    for r in split(runs, SDE[product]['max']):
        jobs.append({'product': product, 'activity': 'manufacturing', 'runs': r, 'me': me, 'te': te, 'group': group, 'required': r})
        for m, b in SDE[product]['mats'].items():
            demand[m] = demand.get(m, 0) + qty(b, r, me)
for p, me, te, runs in ITEMS:
    add_jobs(p, runs, me, te, 'final')
for inter in ['Hammerhead I', 'Hobgoblin I']:
    add_jobs(inter, demand[inter], 10, 20, 'component')
leaves = {m: q for m, q in demand.items() if m not in SDE or m in BLACKLIST}
copies = {}
for j in list(jobs):
    copies.setdefault(j['product'], 0); copies[j['product']] += j['runs']
for p, me, te, runs in ITEMS:
    del copies[p]
for p, r in copies.items():
    jobs.append({'product': p, 'activity': 'copy', 'runs': r, 'me': 0, 'te': 0, 'group': 'blueprint', 'required': r})
for p, me, te, runs in ITEMS:
    att = math.ceil(runs / (INV_RUNS * INV_PROB))
    jobs.append({'product': p, 'activity': 'invention', 'runs': att, 'me': 0, 'te': 0, 'group': 'blueprint', 'required': att})
    jobs.append({'product': T1_OF[p], 'activity': 'copy', 'runs': att, 'me': 0, 'te': 0, 'group': 'blueprint', 'required': att, 'forInvention': True})
    for d, q in SDE[T1_OF[p]]['inv'].items():
        leaves[d] = leaves.get(d, 0) + q * att

PRICES = {'Tritanium': 4.12, 'Pyerite': 9.85, 'Mexallon': 61.4, 'Isogen': 139.0, 'Nocxium': 645.0, 'Zydrine': 1580.0, 'Morphite': 14850.0,
          'Robotics': 91200.0, 'Guidance Systems': 34650.0, 'Particle Accelerator Unit': 30900.0, 'R.A.M.- Robotics': 1462.0,
          'Datacore - Electronic Engineering': 98400.0, 'Datacore - Graviton Physics': 111900.0}
ORDER = ['Tritanium', 'Pyerite', 'Mexallon', 'Isogen', 'Nocxium', 'Zydrine', 'Morphite', 'Robotics', 'Guidance Systems', 'Particle Accelerator Unit', 'R.A.M.- Robotics', 'Datacore - Electronic Engineering', 'Datacore - Graviton Physics']
assert set(ORDER) == set(leaves), (set(ORDER) ^ set(leaves))
mats = [{'name': m, 'required': leaves[m], 'price': PRICES[m]} for m in ORDER]
for i, j in enumerate(jobs):
    j['key'] = 'j%d' % (i + 1)
for i, m in enumerate(mats):
    m['key'] = 'm%d' % (i + 1)
M = {m['name']: m['key'] for m in mats}
def J(product, activity, idx=0, inv=False):
    c = [j for j in jobs if j['product'] == product and j['activity'] == activity and bool(j.get('forInvention')) == inv]
    return c[idx]['key']
bom_total = round(sum(m['required'] * m['price'] for m in mats), 2)

C = [
 ('A1', 'alpha', 'material', M['Tritanium'], 165656, None),
 ('A2', 'alpha', 'material', M['Mexallon'], 9980, None),
 ('A3', 'alpha', 'material', M['Isogen'], 598, None),
 ('A4', 'alpha', 'material', M['Pyerite'], 278, None),
 ('A5', 'alpha', 'material', M['Nocxium'], 54, None),
 ('A6', 'alpha', 'material', M['Zydrine'], 179, None),
 ('B1', 'bravo', 'material', M['Morphite'], 180, None),
 ('B2', 'bravo', 'material', M['Robotics'], 120, None),
 ('C1', 'charlie', 'material', M['Guidance Systems'], 180, None),
 ('C2', 'charlie', 'material', M['Particle Accelerator Unit'], 80, None),
 ('C3', 'charlie', 'material', M['R.A.M.- Robotics'], 80, None),
 ('C4', 'charlie', 'material', M['Robotics'], 60, None),
 ('D1', 'delta', 'material', M['Datacore - Electronic Engineering'], 39, None),
 ('D2', 'delta', 'material', M['Datacore - Graviton Physics'], 39, None),
 ('D7', 'delta', 'material', M['Morphite'], 30, None),
 ('A7', 'alpha', 'job_install', J('Hammerhead I', 'manufacturing'), 50, 96000),
 ('A8', 'alpha', 'job_install', J('Hobgoblin I', 'manufacturing'), 30, 41000),
 ('D3', 'delta', 'bpc', J('Hammerhead II', 'invention'), 15, 1245000),
 ('D4', 'delta', 'bpc', J('Hobgoblin II', 'invention'), 9, 747000),
 ('D5', 'delta', 'job_install', J('Hammerhead I', 'copy', inv=True), 15, 45000),
 ('D6', 'delta', 'job_install', J('Hobgoblin I', 'copy', inv=True), 9, 27000),
] + [('B%d' % (4 + i), 'bravo', 'job_install', J('Hammerhead II', 'manufacturing', i), 10, 214000) for i in range(5)] \
  + [('C%d' % (5 + i), 'charlie', 'job_install', J('Hobgoblin II', 'manufacturing', i), 10, 131000) for i in range(3)] \
  + [('C8', 'charlie', 'line_rental', J('Hobgoblin II', 'manufacturing', 0), 10, 1000000)]
REJECTED = {'D7'}
price_of = {m['key']: m['price'] for m in mats}
contribs = []
for cid, mem, typ, line, q, val in C:
    v = round(q * price_of[line], 2) if typ == 'material' else val
    contribs.append({'id': cid, 'member': mem, 'type': typ, 'line': line, 'qty': q, 'value': v, 'final': 'rejected' if cid in REJECTED else 'approved'})

SALES = [
 {'id': 'S1', 'item': 'Hammerhead II', 'qty': 30, 'unit': 760000, 'venue': 'Jita IV - Moon 4', 'seller': 'Personnage Alpha', 'wallet': True, 'date': '05/11', 'channel': 'market', 'contractFees': 0},
 {'id': 'S2', 'item': 'Hobgoblin II', 'qty': 30, 'unit': 455000, 'venue': 'Contrat item exchange · Corp D', 'seller': 'Personnage Bravo', 'wallet': False, 'date': '04/11', 'channel': 'contract', 'contractFees': 0},
 {'id': 'S3', 'item': 'Hammerhead II', 'qty': 20, 'unit': 748000, 'venue': 'Jita IV - Moon 4', 'seller': 'Personnage Bravo', 'wallet': True, 'date': '06/11', 'channel': 'market', 'contractFees': 0},
]
BROKER, TAX = 3.6, 3.6
def distribution(approved_ids, sale_ids, existing=False):
    sold = [s for s in SALES if s['id'] in sale_ids]
    rev = sum(s['qty'] * s['unit'] for s in sold)
    taxed = rev if existing else sum(s['qty'] * s['unit'] for s in sold if s['channel'] == 'market')
    contract_fees = 0 if existing else sum(s['contractFees'] for s in sold if s['channel'] == 'contract')
    broker = taxed * BROKER / 100; tax = taxed * TAX / 100; net = rev - broker - tax - contract_fees
    ap = [c for c in contribs if c['id'] in approved_ids]
    cost = sum(c['value'] for c in ap)
    ratio = (net - cost) / cost if cost > 0 else None
    members = {}
    for c in ap:
        members.setdefault(c['member'], {'costs': 0, 'byType': {}})
        members[c['member']]['costs'] += c['value']
        members[c['member']]['byType'][c['type']] = members[c['member']]['byType'].get(c['type'], 0) + c['value']
    for k, m in members.items():
        m['share'] = m['costs'] / cost * 100 if cost else None
        m['profit'] = m['costs'] * ratio if ratio is not None else None
        m['payout'] = m['costs'] + m['profit'] if ratio is not None else None
    return {'revenue': rev, 'taxed': taxed, 'contractFees': contract_fees, 'broker': broker, 'tax': tax, 'net': net, 'cost': cost, 'margin': None if ratio is None else ratio * 100, 'members': members}

all_ok = [c['id'] for c in contribs if c['final'] == 'approved']
final = distribution(all_ok, ['S1', 'S2', 'S3'])
partial = distribution(all_ok, ['S1', 'S2'])
final_real = distribution(all_ok, ['S1', 'S2', 'S3'], existing=True)
partial_real = distribution(all_ok, ['S1', 'S2'], existing=True)
out = {'mats': mats, 'jobs': jobs, 'contribs': contribs, 'sales': SALES, 'items': [{'name': n, 'me': me, 'te': te, 'runs': r} for n, me, te, r in ITEMS],
       'fees': {'broker': BROKER, 'tax': TAX}, 'bomTotal': bom_total}
expected = {'bomTotal': bom_total, 'final': final, 'partial': partial,
            'materialCount': len(mats), 'jobCount': len(jobs)}
SELLER_OF = {'Personnage Alpha': 'alpha', 'Personnage Bravo': 'bravo'}
held = {}
for s in SALES:
    g = s['qty'] * s['unit']
    net_of_sale = g * (1 - (BROKER + TAX) / 100) if s['channel'] == 'market' else g - s['contractFees']
    held[SELLER_OF[s['seller']]] = held.get(SELLER_OF[s['seller']], 0) + net_of_sale
bal = {k: round(held.get(k, 0) - v['payout'], 2) for k, v in final['members'].items()}
cred = sorted([[k, -v] for k, v in bal.items() if v < 0], key=lambda x: -x[1])
deb = sorted([[k, v] for k, v in bal.items() if v > 0], key=lambda x: -x[1])
transfers = []
i = j = 0
while i < len(deb) and j < len(cred):
    a = round(min(deb[i][1], cred[j][1]), 2)
    if a >= 0.01: transfers.append({'from': deb[i][0], 'to': cred[j][0], 'amount': a})
    deb[i][1] = round(deb[i][1] - a, 2); cred[j][1] = round(cred[j][1] - a, 2)
    if deb[i][1] < 0.01: i += 1
    if cred[j][1] < 0.01: j += 1
out['held'] = held; out['transfers'] = transfers
expected['held'] = held; expected['transfers'] = transfers

unsold_hh = 50 - 30
projected_net = partial['net'] + unsold_hh * 760000 * (1 - (BROKER + TAX) / 100)
projected_margin = (projected_net - partial['cost']) / partial['cost'] * 100
