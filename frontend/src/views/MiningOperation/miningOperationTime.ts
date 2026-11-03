const EVE_INPUT_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/

function twoDigits(value: number): string {
  return String(value).padStart(2, '0')
}

export function formatEveDate(isoDateTime: string): string {
  const moment = new Date(isoDateTime)
  return `${moment.getUTCFullYear()}-${twoDigits(moment.getUTCMonth() + 1)}-${twoDigits(moment.getUTCDate())}`
}

export function formatEveDateTime(isoDateTime: string): string {
  const moment = new Date(isoDateTime)
  return `${formatEveDate(isoDateTime)} ${twoDigits(moment.getUTCHours())}:${twoDigits(moment.getUTCMinutes())}`
}

export function eveInputToIso(inputValue: string): string {
  if (!EVE_INPUT_PATTERN.test(inputValue)) {
    throw new Error(`Not an EVE time input value: ${inputValue}`)
  }
  return `${inputValue}:00+00:00`
}

export function nextFullHourAsEveInput(now: Date): string {
  const nextHour = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate(), now.getUTCHours() + 1))
  return `${nextHour.getUTCFullYear()}-${twoDigits(nextHour.getUTCMonth() + 1)}-${twoDigits(nextHour.getUTCDate())}T${twoDigits(nextHour.getUTCHours())}:00`
}

const MILLISECONDS_PER_SECOND = 1000
const SECONDS_PER_MINUTE = 60
const SECONDS_PER_HOUR = 3600

export function formatEveClock(isoDateTime: string): string {
  const moment = new Date(isoDateTime)
  return `${twoDigits(moment.getUTCHours())}:${twoDigits(moment.getUTCMinutes())}`
}

export function formatCountdown(remainingMilliseconds: number): string {
  const remainingSeconds = Math.max(0, Math.ceil(remainingMilliseconds / MILLISECONDS_PER_SECOND))
  return `${twoDigits(Math.floor(remainingSeconds / SECONDS_PER_MINUTE))}:${twoDigits(remainingSeconds % SECONDS_PER_MINUTE)}`
}

export function formatDuration(totalSeconds: number): string {
  const hours = Math.floor(totalSeconds / SECONDS_PER_HOUR)
  const minutes = Math.floor((totalSeconds % SECONDS_PER_HOUR) / SECONDS_PER_MINUTE)
  return hours > 0 ? `${hours} h ${twoDigits(minutes)}` : `${minutes} min`
}
