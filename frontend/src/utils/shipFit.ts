import { SHIP_CATEGORY_ID } from '@/constants/assetsConstants'
import type { Asset } from '@/types'

export interface FitStack {
  typeId: number
  typeName: string | null
  quantity: number
}

export interface ShipFit {
  low: Asset[]
  mid: Asset[]
  high: Asset[]
  rig: Asset[]
  subsystem: Asset[]
  drones: FitStack[]
  cargo: FitStack[]
}

export interface ShipGroup {
  key: string
  representative: Asset
  ships: Asset[]
  fit: ShipFit
  badFit: boolean
}

type SlotKind = 'low' | 'mid' | 'high' | 'rig' | 'subsystem'

const CHARGE_CATEGORY_ID = 8
const SLOT_FLAG_PATTERN = /^(LoSlot|MedSlot|HiSlot|RigSlot|SubSystemSlot)(\d+)$/
const DRONE_FLAG_PATTERN = /^(DroneBay|FighterBay|FighterTube\d+)$/
const BAD_FIT_PATTERN = /bad fit/i
const SLOT_KIND_BY_PREFIX: Record<string, SlotKind> = {
  LoSlot: 'low',
  MedSlot: 'mid',
  HiSlot: 'high',
  RigSlot: 'rig',
  SubSystemSlot: 'subsystem',
}
const EFT_SLOT_KINDS: SlotKind[] = ['low', 'mid', 'high', 'rig', 'subsystem']

function parseSlot(flag: string | null): { kind: SlotKind; index: number } | null {
  const match = flag === null ? null : SLOT_FLAG_PATTERN.exec(flag)
  if (match === null) {
    return null
  }
  return { kind: SLOT_KIND_BY_PREFIX[match[1]], index: Number(match[2]) }
}

export function isFittedFlag(flag: string | null): boolean {
  return parseSlot(flag) !== null
}

export function isGroupableShip(asset: Asset): boolean {
  return asset.categoryId === SHIP_CATEGORY_ID
    && asset.isSingleton === true
    && !(asset.itemName === null && asset.canResolveItemName)
}

export function isBadFitName(name: string | null): boolean {
  return name !== null && BAD_FIT_PATTERN.test(name)
}

function addToStacks(stacks: FitStack[], asset: Asset): void {
  const known = stacks.find(stack => stack.typeId === asset.typeId)
  if (known) {
    known.quantity += asset.quantity
    return
  }
  stacks.push({ typeId: asset.typeId, typeName: asset.typeName, quantity: asset.quantity })
}

export function shipFitOf(contents: Asset[]): ShipFit {
  const fit: ShipFit = { low: [], mid: [], high: [], rig: [], subsystem: [], drones: [], cargo: [] }
  const slotIndexByItemId = new Map<number, number>()

  for (const content of contents) {
    const slot = parseSlot(content.locationFlag)
    if (slot !== null) {
      if (content.categoryId !== CHARGE_CATEGORY_ID) {
        fit[slot.kind].push(content)
        slotIndexByItemId.set(content.itemId, slot.index)
      }
    } else if (content.locationFlag !== null && DRONE_FLAG_PATTERN.test(content.locationFlag)) {
      addToStacks(fit.drones, content)
    } else {
      addToStacks(fit.cargo, content)
    }
  }

  for (const kind of EFT_SLOT_KINDS) {
    fit[kind].sort((a, b) => (slotIndexByItemId.get(a.itemId) ?? 0) - (slotIndexByItemId.get(b.itemId) ?? 0))
  }

  return fit
}

function fitSignature(fit: ShipFit): string {
  return EFT_SLOT_KINDS
    .flatMap(kind => fit[kind].map(module => `${kind}:${module.typeId}`))
    .sort()
    .join(',')
}

export function groupAssembledShips(ships: Asset[], contentsOf: (itemId: number) => Asset[]): ShipGroup[] {
  const groups = new Map<string, ShipGroup>()

  for (const ship of ships) {
    const fit = shipFitOf(contentsOf(ship.itemId))
    const key = [ship.typeId, ship.itemName ?? '', fitSignature(fit)].join('|')
    const known = groups.get(key)
    if (known) {
      known.ships.push(ship)
      continue
    }
    groups.set(key, { key, representative: ship, ships: [ship], fit, badFit: isBadFitName(ship.itemName) })
  }

  return Array.from(groups.values())
}

function allNamed(assets: { typeName: string | null }[]): string[] | null {
  const names: string[] = []
  for (const asset of assets) {
    if (asset.typeName === null) {
      return null
    }
    names.push(asset.typeName)
  }
  return names
}

function eftSection(lines: string[]): string | null {
  return lines.length > 0 ? lines.join('\n') : null
}

export function formatEft(group: ShipGroup): string | null {
  const { representative, fit } = group
  if (representative.typeName === null) {
    return null
  }

  const sections: (string | null)[] = []
  for (const kind of EFT_SLOT_KINDS) {
    const names = allNamed(fit[kind])
    if (names === null) {
      return null
    }
    sections.push(eftSection(names))
  }
  for (const stacks of [fit.drones, fit.cargo]) {
    const names = allNamed(stacks)
    if (names === null) {
      return null
    }
    sections.push(eftSection(stacks.map((stack, index) => `${names[index]} x${stack.quantity}`)))
  }

  const fitName = representative.itemName !== null && representative.itemName !== '' ? representative.itemName : representative.typeName
  const header = `[${representative.typeName}, ${fitName}]`
  const body = sections.filter((section): section is string => section !== null).join('\n\n')

  return body === '' ? header : `${header}\n${body}`
}
