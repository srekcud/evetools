const NO_SLOTS = { used: 0, max: 0, percent: 0 }

export function slotTrackerWithoutCharacters<Timeline>(timeline: Timeline[] = []) {
  return {
    globalKpis: { manufacturing: NO_SLOTS, reaction: NO_SLOTS, science: NO_SLOTS },
    characters: [],
    timeline,
    skillsMayBeStale: false,
    unknownSkillsCharacterCount: 0,
  }
}
