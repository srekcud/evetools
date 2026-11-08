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

export function parseSurveyPaste(_text: string, _oreTypes: SurveyOreType[]): SurveyPasteResult {
  return { lines: [], issues: [] }
}
