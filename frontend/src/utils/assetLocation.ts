export type LocationIssue = 'unresolved' | 'missingName'

const UNRESOLVED_STRUCTURE_PATTERN = /^Structure #\d+$/
const UNRESOLVED_LOCATION_PATTERN = /^Location #\d+$/
const UNRESOLVED_IN_PATH_PATTERN = /(Structure|Location) #\d+/
const MISSING_NAME_PATTERN = /Unknown (structure|station)/
const ASSET_SAFETY_PREFIX = 'Asset Safety ('

export function locationIssue(locationName: string): LocationIssue | null {
  if (UNRESOLVED_IN_PATH_PATTERN.test(locationName)) {
    return 'unresolved'
  }
  if (MISSING_NAME_PATTERN.test(locationName) || locationName.startsWith(ASSET_SAFETY_PREFIX)) {
    return 'missingName'
  }
  return null
}

export function isUnresolvedStructureName(locationName: string): boolean {
  return UNRESOLVED_STRUCTURE_PATTERN.test(locationName)
}

export function isUnresolvedLocationName(locationName: string): boolean {
  return UNRESOLVED_LOCATION_PATTERN.test(locationName)
}
