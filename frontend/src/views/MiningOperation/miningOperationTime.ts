const EVE_INPUT_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/

function twoDigits(value: number): string {
  return String(value).padStart(2, '0')
}

export function formatEveDateTime(isoDateTime: string): string {
  const moment = new Date(isoDateTime)
  const date = `${moment.getUTCFullYear()}-${twoDigits(moment.getUTCMonth() + 1)}-${twoDigits(moment.getUTCDate())}`
  return `${date} ${twoDigits(moment.getUTCHours())}:${twoDigits(moment.getUTCMinutes())}`
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
