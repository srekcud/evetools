export interface NamedType {
  typeId: number
  typeName: string
}

export function missingVolumeTypeNames(items: NamedType[], missingVolumeTypeIds: number[]): string {
  const missingIds = new Set(missingVolumeTypeIds)
  const names = new Set(items.filter(item => missingIds.has(item.typeId)).map(item => item.typeName))
  return [...names].join(', ')
}
