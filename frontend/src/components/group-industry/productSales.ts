import type { BomItem, GroupSale } from '@/stores/group-industry/types'

export interface ProductSalesRow {
  typeId: number
  typeName: string
  sold: number
  produced: number | null
  remaining: number | null
}

const FINAL_JOB_GROUP = 'final'
const UNTYPED_SALE_TYPE_ID = 0

export function buildProductSales(
  projectItems: { typeId: number; typeName: string }[],
  bomItems: BomItem[],
  sales: GroupSale[],
): ProductSalesRow[] {
  return projectItems.map(({ typeId, typeName }) => {
    const finalLines = bomItems.filter(line => line.jobGroup === FINAL_JOB_GROUP && line.typeId === typeId)
    const produced = finalLines.length === 0 ? null : finalLines.reduce((sum, line) => sum + line.requiredQuantity, 0)
    const sold = sales
      .filter(sale => sale.typeId === typeId || (sale.typeId === UNTYPED_SALE_TYPE_ID && sale.typeName === typeName))
      .reduce((sum, sale) => sum + sale.quantity, 0)

    return { typeId, typeName, sold, produced, remaining: produced === null ? null : Math.max(0, produced - sold) }
  })
}
