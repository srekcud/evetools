# Recoupement (outil, pas du code de production) d'un golden dont les jobs sont decoupes.
# Usage : python3 -I check-split-golden.py <sdeDir> <golden.json>
# Formule F1 appliquee a chaque job : max(runs, ceil(round(runs x base x (1 - ME/100) x modificateur, 2))).
import json, math, sys
from collections import defaultdict

sde_dir, golden_path = sys.argv[1], sys.argv[2]
rows = lambda f: [l.split('\t') for l in open(f'{sde_dir}/{f}').read().strip().split('\n')]
materials = defaultdict(list)
for bp, act, mat, qty in rows('mats.tsv'):
    materials[(int(bp), int(act))].append((int(mat), int(qty)))
producer = {}
for bp, act, prod, qty, _ in rows('prods.tsv'):
    if act in ('1', '11'):
        producer[(int(prod), int(act))] = int(bp)

golden = json.load(open(golden_path))
expected = golden['expected']
demand = defaultdict(int)
for job_group in expected['roots'] + expected['intermediates']:
    activity = 11 if job_group['activity'] == 'reaction' else 1
    blueprint = producer[(job_group['typeId'], activity)]
    factor = (1 - job_group['me'] / 100) * job_group['modifier']
    for runs in job_group['jobs']:
        for mat, base in materials[(blueprint, activity)]:
            demand[mat] += max(runs, math.ceil(round(runs * base * factor, 2)))

errors = []
for leaf in expected['leaves']:
    if demand[leaf['typeId']] != leaf['quantity']:
        errors.append(f"feuille {leaf['name']} : {demand[leaf['typeId']]} != {leaf['quantity']}")
for item in expected['intermediates']:
    runs = math.ceil(demand[item['typeId']] / item['outputPerRun'])
    if demand[item['typeId']] != item['quantityNeeded'] or runs != item['runs']:
        errors.append(f"{item['product']} : demande {demand[item['typeId']]} runs {runs}")
print('\n'.join(errors) or f"OK : {len(expected['leaves'])} feuilles, {len(expected['intermediates'])} intermediaires")
sys.exit(1 if errors else 0)
