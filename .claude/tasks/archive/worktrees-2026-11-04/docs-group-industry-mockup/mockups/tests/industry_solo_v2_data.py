import pathlib
import runpy

V1 = runpy.run_path(str(pathlib.Path(__file__).with_name('industry_solo_v1_data.py')))
out = dict(V1['out'])
FEES = (V1['BROKER'] + V1['TAX']) / 100
RUNS = V1['RUNS']

production_cost = out['invention']['computed'] + out['realMaterial'] + out['jobsCost']
break_even_unit = production_cost / (RUNS * (1 - FEES))
estimate = {
    'material': out['est']['material'], 'jobs': out['est']['job'], 'invention': out['invention']['entered'],
    'taxes': out['est']['tax'], 'revenue': out['est']['sell'],
}
estimate['profit'] = estimate['revenue'] - estimate['material'] - estimate['jobs'] - estimate['invention'] - estimate['taxes']
final = out['final']
waterfall = [
    ['Revenu', final['revenue']],
    ['Taxes marché', -final['taxes']],
    ['Matériaux', -out['realMaterial']],
    ['Invention', -out['invention']['computed']],
    ['Jobs', -out['jobsCost']],
]
profit_check = sum(v for _, v in waterfall)
assert abs(profit_check - final['profit']) < 0.01


def simulate(unit_price, channel='market'):
    partial = out['partial']
    extra = partial['unsold'] * unit_price
    revenue = partial['revenue'] + extra
    taxes = partial['taxes'] + (extra * FEES if channel == 'market' else 0)
    profit = revenue - taxes - production_cost
    return {'revenue': revenue, 'taxes': taxes, 'profit': profit, 'percent': profit / (production_cost + taxes) * 100}


SIMULATIONS = {f'{price}-{channel}': simulate(price, channel) for price in (700000, 748000, 760000, 800000) for channel in ('market', 'contract')}
assert abs(simulate(748000, 'contract')['profit'] - final['profit']) < 0.01
out.update({'productionCost': production_cost, 'breakEvenUnit': break_even_unit, 'estimate': estimate, 'waterfall': waterfall,
            'simulations': SIMULATIONS})

DATACORES = [('Datacore - Electronic Engineering', 30, 98400.0), ('Datacore - Graviton Physics', 30, 111900.0)]
conversion_lines = []
for m in out['mats']:
    if m['stock'] >= m['required']:
        conversion_lines.append({'kind': 'stock', 'item': m['name'], 'qty': m['required'], 'bom': m['price'], 'paid': None})
for p in out['purchases']:
    if p['id'] != 'P5':
        price = next(m['price'] for m in out['mats'] if m['name'] == p['item'])
        conversion_lines.append({'kind': 'purchase', 'item': p['item'], 'qty': p['qty'], 'bom': price, 'paid': p['unit']})
for name, q, price in DATACORES:
    conversion_lines.append({'kind': 'datacore', 'item': name, 'qty': q, 'bom': price, 'paid': None})
for line in conversion_lines:
    line['value'] = round(line['qty'] * line['bom'], 2)
    line['paidTotal'] = None if line['paid'] is None else round(line['qty'] * line['paid'], 2)
job_lines = [{'item': 'Hammerhead II · invention', 'type': 'bpc', 'qty': 15, 'value': 1245000.0}, {'item': 'Hammerhead I · copie pour l’invention', 'type': 'job_install', 'qty': 15, 'value': 45000.0}]
carried = round(sum(l['value'] for l in conversion_lines) + sum(j['value'] for j in job_lines), 2)
purchase_gap = round(sum(l['value'] - l['paidTotal'] for l in conversion_lines if l['paidTotal'] is not None), 2)
pau = next(m for m in out['mats'] if m['name'] == 'Particle Accelerator Unit')
remaining = {'item': pau['name'], 'qty': pau['required'], 'value': pau['required'] * pau['price']}
out['conversion'] = {'lines': conversion_lines, 'jobs': job_lines, 'carried': carried, 'purchaseGap': purchase_gap, 'remaining': remaining}
