export function containerNamesFromText(text: string): string[] {
  return text
    .split(/\r?\n/)
    .map(line => line.trim())
    .filter(line => line !== '')
}

export function containerNamesToText(containerNames: string[]): string {
  return containerNames.join('\n')
}
