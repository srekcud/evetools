const ACTIVITIES_WITHOUT_BLUEPRINT_EFFICIENCY: ReadonlySet<string> = new Set(['reaction', 'copy'])

export function showsBlueprintEfficiency(activity: string | null): boolean {
  return activity === null || !ACTIVITIES_WITHOUT_BLUEPRINT_EFFICIENCY.has(activity)
}
