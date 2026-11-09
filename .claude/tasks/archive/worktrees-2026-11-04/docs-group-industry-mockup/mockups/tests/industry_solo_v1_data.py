import math

STRUCT = 0.99
BROKER, TAX = 3.6, 3.6


def qty(base, runs, me):
    return max(runs, math.ceil(round(runs * base * (1 - me / 100) * STRUCT, 2)))


FINAL = {'Guidance Systems': 3, 'Hammerhead I': 1, 'Morphite': 3, 'Particle Accelerator Unit': 1, 'R.A.M.- Robotics': 1, 'Robotics': 3}
T1 = {'Isogen': 11, 'Mexallon': 224, 'Pyerite': 2, 'Tritanium': 3413, 'Zydrine': 4}
RUNS, BPC_MAX_RUNS, ME, TE = 50, 10, 2, 4
DIVISIONS = [BPC_MAX_RUNS] * (RUNS // BPC_MAX_RUNS)

need = {}
for runs in DIVISIONS:
    for m, b in FINAL.items():
        need[m] = need.get(m, 0) + qty(b, runs, ME)
t1_runs = need.pop('Hammerhead I')
for m, b in T1.items():
    need[m] = need.get(m, 0) + qty(b, t1_runs, 10)

PRICES = {'Tritanium': 4.12, 'Pyerite': 9.85, 'Mexallon': 61.4, 'Isogen': 139.0, 'Zydrine': 1580.0, 'Morphite': 14850.0,
          'Robotics': 91200.0, 'Guidance Systems': 34650.0, 'Particle Accelerator Unit': 30900.0, 'R.A.M.- Robotics': 1462.0}
ORDER = ['Tritanium', 'Pyerite', 'Mexallon', 'Isogen', 'Zydrine', 'Morphite', 'Robotics', 'Guidance Systems', 'Particle Accelerator Unit', 'R.A.M.- Robotics']
assert set(ORDER) == set(need)
STOCK = {'Tritanium': 152050, 'Pyerite': 90, 'Mexallon': 9980, 'Isogen': 491, 'Zydrine': 179}
PURCHASES = [
    {'id': 'P1', 'item': 'Robotics', 'qty': 150, 'unit': 90500.0, 'date': '02/11 19:12', 'char': 'Personnage Alpha'},
    {'id': 'P2', 'item': 'Morphite', 'qty': 150, 'unit': 14600.0, 'date': '02/11 19:14', 'char': 'Personnage Alpha'},
    {'id': 'P3', 'item': 'Guidance Systems', 'qty': 150, 'unit': 34900.0, 'date': '02/11 19:20', 'char': 'Personnage Alpha'},
    {'id': 'P4', 'item': 'R.A.M.- Robotics', 'qty': 50, 'unit': 1450.0, 'date': '02/11 19:21', 'char': 'Personnage Alpha'},
    {'id': 'P5', 'item': 'Particle Accelerator Unit', 'qty': 50, 'unit': 31200.0, 'date': '03/11 18:30', 'char': 'Personnage Alpha'},
]
mats = []
for name in ORDER:
    bought = sum(p['qty'] for p in PURCHASES if p['item'] == name)
    mats.append({'name': name, 'required': need[name], 'price': PRICES[name], 'stock': STOCK.get(name, 0), 'bought': bought})

est_material = round(sum(m['required'] * m['price'] for m in mats), 2)
material_cost_applied = math.ceil(est_material / 1e6) * 1e6
est_sell = RUNS * 760000
est_tax = est_sell * (BROKER + TAX) / 100
est_job = 1250000.0
JOB_COSTS = {'final': 214000.0, 't1': 96000.0}
jobs_cost = len(DIVISIONS) * JOB_COSTS['final'] + JOB_COSTS['t1']
datacores = 30 * 98400.0 + 30 * 111900.0
invention_jobs = 1245000.0 + 45000.0
invention_computed = datacores + invention_jobs
invention_entered = 7600000.0

existing_total = invention_entered + material_cost_applied + jobs_cost + est_tax
existing_profit = est_sell - existing_total

real_material = 0.0
for m in mats:
    bought = [p for p in PURCHASES if p['item'] == m['name']]
    real_material += sum(p['qty'] * p['unit'] for p in bought) + (m['required'] - sum(p['qty'] for p in bought)) * m['price']
real_material = round(real_material, 2)

SALES = [
    {'id': 'S1', 'qty': 30, 'unit': 760000, 'channel': 'market', 'date': '05/11 20:41', 'venue': 'Jita IV - Moon 4', 'wallet': True, 'fees': None},
    {'id': 'S2', 'qty': 20, 'unit': 748000, 'channel': 'contract', 'date': '05/11 22:10', 'venue': 'Contrat item exchange · Corp D', 'wallet': False, 'fees': 0},
]


def outcome(sale_ids):
    sold = [s for s in SALES if s['id'] in sale_ids]
    revenue = sum(s['qty'] * s['unit'] for s in sold)
    market = sum(s['qty'] * s['unit'] for s in sold if s['channel'] == 'market')
    taxes = market * (BROKER + TAX) / 100 + sum(s['fees'] for s in sold if s['channel'] == 'contract')
    cost = invention_computed + real_material + jobs_cost + taxes
    profit = revenue - cost
    unsold = RUNS - sum(s['qty'] for s in sold)
    projected_revenue = revenue + unsold * 760000
    projected_taxes = taxes + unsold * 760000 * (BROKER + TAX) / 100
    projected_profit = projected_revenue - (invention_computed + real_material + jobs_cost + projected_taxes)
    return {'revenue': revenue, 'market': market, 'taxes': taxes, 'cost': cost, 'profit': profit,
            'percent': profit / cost * 100, 'unsold': unsold, 'projectedProfit': projected_profit,
            'projectedPercent': projected_profit / (invention_computed + real_material + jobs_cost + projected_taxes) * 100}


partial = outcome(['S1'])
final = outcome(['S1', 'S2'])
out = {'mats': mats, 'purchases': PURCHASES, 'sales': SALES, 'divisions': DIVISIONS, 't1Runs': t1_runs, 'jobCosts': JOB_COSTS,
       'fees': {'broker': BROKER, 'tax': TAX}, 'est': {'material': est_material, 'materialApplied': material_cost_applied, 'sell': est_sell, 'tax': est_tax, 'job': est_job},
       'jobsCost': jobs_cost, 'invention': {'datacores': datacores, 'jobs': invention_jobs, 'computed': invention_computed, 'entered': invention_entered},
       'existing': {'total': existing_total, 'profit': existing_profit, 'percent': existing_profit / existing_total * 100},
       'realMaterial': real_material, 'partial': partial, 'final': final}
