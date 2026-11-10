import type { WorkshopProject } from '@/stores/industry/workshopProjects'

export type WorkshopKpiFigure = {
  amount: number | null
  unknownCount: number
}

export type WorkshopKpis = {
  capital: WorkshopKpiFigure
  profit: WorkshopKpiFigure
}

function sumOfKnown(values: (number | null)[]): WorkshopKpiFigure {
  const known = values.filter((value): value is number => value !== null)
  return {
    amount: known.length === 0 ? null : known.reduce((sum, value) => sum + value, 0),
    unknownCount: values.length - known.length,
  }
}

export function workshopKpis(rows: WorkshopProject[], activeProjectIds: ReadonlySet<string>): WorkshopKpis {
  const activeRows = rows.filter(row => activeProjectIds.has(row.projectId))
  return {
    capital: sumOfKnown(activeRows.map(row => row.engagedCapital)),
    profit: sumOfKnown(activeRows.map(row => row.profit.amount)),
  }
}
