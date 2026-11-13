import { buildProductSales } from '@/components/group-industry/productSales'
import type { BomItem, GroupSale } from '@/stores/group-industry/types'

const ISHTAR = { typeId: 12_005, typeName: 'Ishtar' }
const HECATE = { typeId: 35_683, typeName: 'Hecate' }

function finalLine(typeId: number, requiredQuantity: number): BomItem {
  return { typeId, requiredQuantity, jobGroup: 'final', isJob: true } as BomItem
}

function saleOf(typeId: number, typeName: string, quantity: number): GroupSale {
  return { typeId, typeName, quantity } as GroupSale
}

describe('buildProductSales', () => {
  it('counts what was sold against what the project produces, per item', () => {
    const rows = buildProductSales([ISHTAR, HECATE], [finalLine(12_005, 5), finalLine(35_683, 2)], [saleOf(12_005, 'Ishtar', 2), saleOf(12_005, 'Ishtar', 1), saleOf(35_683, 'Hecate', 2)])

    expect(rows).toEqual([
      { typeId: 12_005, typeName: 'Ishtar', sold: 3, produced: 5, remaining: 2 },
      { typeId: 35_683, typeName: 'Hecate', sold: 2, produced: 2, remaining: 0 },
    ])
  })

  it('adds the final lines of the same item together and ignores the components', () => {
    const component = { typeId: 12_005, requiredQuantity: 99, jobGroup: 'component', isJob: true } as BomItem

    expect(buildProductSales([ISHTAR], [finalLine(12_005, 2), finalLine(12_005, 3), component], [])).toEqual([
      { typeId: 12_005, typeName: 'Ishtar', sold: 0, produced: 5, remaining: 5 },
    ])
  })

  it('leaves the produced quantity unknown, never zero, while the project has no final line for the item', () => {
    expect(buildProductSales([ISHTAR], [], [saleOf(12_005, 'Ishtar', 2)])).toEqual([
      { typeId: 12_005, typeName: 'Ishtar', sold: 2, produced: null, remaining: null },
    ])
  })

  it('matches a sale typed by hand without type id on the item name', () => {
    expect(buildProductSales([ISHTAR], [finalLine(12_005, 4)], [saleOf(0, 'Ishtar', 1)])[0]).toEqual(
      { typeId: 12_005, typeName: 'Ishtar', sold: 1, produced: 4, remaining: 3 },
    )
  })

  it('gives a single row to an item listed once per blueprint copy and counts its sales once', () => {
    const rows = buildProductSales(
      [ISHTAR, ISHTAR, ISHTAR, ISHTAR, ISHTAR],
      [finalLine(12_005, 10), finalLine(12_005, 10), finalLine(12_005, 10), finalLine(12_005, 10), finalLine(12_005, 10)],
      [saleOf(12_005, 'Ishtar', 10)],
    )

    expect(rows).toEqual([{ typeId: 12_005, typeName: 'Ishtar', sold: 10, produced: 50, remaining: 40 }])
  })

  it('keeps distinct items as distinct rows, in the order of their first appearance', () => {
    const rows = buildProductSales([ISHTAR, HECATE, ISHTAR], [finalLine(12_005, 3), finalLine(35_683, 1)], [])

    expect(rows.map(row => row.typeId)).toEqual([12_005, 35_683])
  })

  it('never shows a negative remaining quantity when more was sold than produced', () => {
    expect(buildProductSales([ISHTAR], [finalLine(12_005, 2)], [saleOf(12_005, 'Ishtar', 3)])[0].remaining).toBe(0)
  })
})
