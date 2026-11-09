import type { JobLocation, JobLocations } from '@/stores/group-industry/types'

export type JobLocationCheck = 'ok' | 'blueprint-outside' | 'output-outside' | 'unknown'

export function jobLocationCheck(locations: JobLocations): JobLocationCheck {
  if (locations.output.status === 'outside') return 'output-outside'
  if (locations.blueprint.status === 'outside') return 'blueprint-outside'
  if (locations.blueprint.status === 'unknown' || locations.output.status === 'unknown') return 'unknown'
  return 'ok'
}

export function jobLocationUnknownReasons(locations: JobLocations): string[] {
  const reasons = [locations.blueprint, locations.output]
    .filter((side: JobLocation) => side.status === 'unknown' && side.unknownReason !== null)
    .map(side => side.unknownReason as string)
  return [...new Set(reasons)]
}
