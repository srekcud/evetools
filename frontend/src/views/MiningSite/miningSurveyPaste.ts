export type SurveyPasteIssueKind = 'unreadable' | 'unknown_ore' | 'volume_mismatch' | 'collapsed_group'

export interface SurveyPasteIssue {
  kind: SurveyPasteIssueKind
  line: number
  name?: string
}

export interface SurveyOreType {
  typeId: number
  typeName: string
  volume: number | null
}

export interface SurveyPasteLine {
  typeId: number
  units: number
}

export interface SurveyPasteResult {
  lines: SurveyPasteLine[]
  issues: SurveyPasteIssue[]
}

const MINIMUM_COLUMNS = 3
const UNITS_COLUMN = 1
const VOLUME_COLUMN = 2
const VOLUME_TOLERANCE_M3 = 1
const VOLUME_TOLERANCE_RATIO = 0.01
const LINE_BREAK = /\r?\n/
const COLUMN_SEPARATOR = /\s*\t\s*| {2,}/
const COLLAPSED_GROUP = /^(.+?)\s*\[\d+\]/
const GROUPED_UNITS = /^(?:\d{1,3}(?:[.,\s]\d{3})+|\d+)$/
const VOLUME_TEXT = /^(.+?)\s*m[³3]$/i
const DECIMAL_NUMBER = /^\d+(?:\.\d+)?$/

function unitsOf(text: string): number | null {
  if (!GROUPED_UNITS.test(text)) return null
  const units = Number(text.replace(/[.,\s]/g, ''))
  return Number.isSafeInteger(units) && units > 0 ? units : null
}

function volumeReadingsOf(text: string): number[] {
  const compact = text.replace(/\s/g, '')
  const candidates = [compact.replace(/\./g, '').replace(',', '.'), compact.replace(/,/g, '')]
  return candidates.filter(candidate => DECIMAL_NUMBER.test(candidate)).map(Number)
}

function volumeMatches(volumeText: string, expectedVolume: number): boolean {
  const tolerance = Math.max(VOLUME_TOLERANCE_M3, expectedVolume * VOLUME_TOLERANCE_RATIO)
  return volumeReadingsOf(volumeText).some(reading => Math.abs(reading - expectedVolume) <= tolerance)
}

export function parseSurveyPaste(text: string, oreTypes: SurveyOreType[]): SurveyPasteResult {
  const typesByName = new Map<string, SurveyOreType>()
  for (const oreType of oreTypes) {
    const key = oreType.typeName.trim().toLowerCase()
    if (!typesByName.has(key)) typesByName.set(key, oreType)
  }

  const unitsByTypeId = new Map<number, number>()
  const issues: SurveyPasteIssue[] = []

  text.split(LINE_BREAK).forEach((rawLine, index) => {
    const row = rawLine.trim()
    if (row === '') return
    const line = index + 1

    const collapsed = COLLAPSED_GROUP.exec(row)
    if (collapsed !== null) {
      issues.push({ kind: 'collapsed_group', line, name: (collapsed[1] ?? '').trim() })
      return
    }

    const columns = row.split(COLUMN_SEPARATOR).map(column => column.trim())
    const units = unitsOf(columns[UNITS_COLUMN] ?? '')
    const volumeText = VOLUME_TEXT.exec(columns[VOLUME_COLUMN] ?? '')?.[1]
    if (columns.length < MINIMUM_COLUMNS || units === null || volumeText === undefined || volumeReadingsOf(volumeText).length === 0) {
      issues.push({ kind: 'unreadable', line })
      return
    }

    const name = columns[0] ?? ''
    const oreType = typesByName.get(name.toLowerCase())
    if (oreType === undefined) {
      issues.push({ kind: 'unknown_ore', line, name })
      return
    }
    if (oreType.volume !== null && !volumeMatches(volumeText, units * oreType.volume)) {
      issues.push({ kind: 'volume_mismatch', line, name })
      return
    }

    unitsByTypeId.set(oreType.typeId, (unitsByTypeId.get(oreType.typeId) ?? 0) + units)
  })

  return { lines: [...unitsByTypeId].map(([typeId, units]) => ({ typeId, units })), issues }
}
