import { ApiError } from '@/services/api'

const NOT_FOUND_STATUS = 404
const BAD_REQUEST_STATUS = 400
const NOT_OPEN_YET_API_MESSAGE = 'This project is not open to new members yet'
const CLOSED_API_MESSAGE = 'This project is no longer accepting new members'

export function joinRefusalMessageKey(error: unknown): string | null {
  if (!(error instanceof ApiError)) return null
  if (error.status === NOT_FOUND_STATUS) return 'groupIndustry.join.notAvailable'
  if (error.status !== BAD_REQUEST_STATUS) return null
  if (error.message === NOT_OPEN_YET_API_MESSAGE) return 'groupIndustry.join.notOpenYet'
  if (error.message === CLOSED_API_MESSAGE) return 'groupIndustry.join.closed'
  return null
}
