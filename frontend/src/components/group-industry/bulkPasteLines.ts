export const MAXIMUM_MATERIAL_EFFICIENCY = 10
export const MAXIMUM_TIME_EFFICIENCY = 20
export const MINIMUM_RUNS = 1
export const MAXIMUM_RUNS = 100000
export const MINIMUM_BLUEPRINT_COPIES = 1
export const MAXIMUM_BLUEPRINT_COPIES = 100

const BULK_LINE_PATTERN = /^(.+?)\s+(\d+)\s+(\d+)\s+x?(\d+)(?:\s+\*(\d+))?$/i
const LINE_BREAK_PATTERN = /\r?\n/

export type BulkLineRequest = {
  typeName: string
  meLevel: number
  teLevel: number
  runs: number
  copies: number
}

export type BulkLineRejection = 'format' | 'materialEfficiency' | 'timeEfficiency' | 'runs' | 'copies'

export type ParsedBulkLine =
  | { request: BulkLineRequest }
  | { rejection: BulkLineRejection }

export function splitBulkLines(text: string): string[] {
  return text
    .split(LINE_BREAK_PATTERN)
    .map(line => line.trim())
    .filter(line => line !== '')
}

export function parseBulkLine(line: string): ParsedBulkLine {
  const match = line.match(BULK_LINE_PATTERN)
  if (match === null) return { rejection: 'format' }

  const request: BulkLineRequest = {
    typeName: match[1].trim(),
    meLevel: parseInt(match[2], 10),
    teLevel: parseInt(match[3], 10),
    runs: parseInt(match[4], 10),
    copies: match[5] === undefined ? MINIMUM_BLUEPRINT_COPIES : parseInt(match[5], 10),
  }

  if (request.meLevel > MAXIMUM_MATERIAL_EFFICIENCY) return { rejection: 'materialEfficiency' }
  if (request.teLevel > MAXIMUM_TIME_EFFICIENCY) return { rejection: 'timeEfficiency' }
  if (request.runs < MINIMUM_RUNS || request.runs > MAXIMUM_RUNS) return { rejection: 'runs' }
  if (request.copies < MINIMUM_BLUEPRINT_COPIES || request.copies > MAXIMUM_BLUEPRINT_COPIES) return { rejection: 'copies' }

  return { request }
}
