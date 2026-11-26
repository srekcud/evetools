import type { ColonyLayout } from '@/stores/planetaryLayouts'

export const COOLANT_LAYOUT: ColonyLayout = {
  id: 'layout-coolant',
  name: 'Coolant starter',
  summary: {
    planetTypeId: 2016,
    planetTypeName: 'Barren',
    commandCenterLevel: 5,
    buildings: { extractorControlUnits: 0, extractorHeads: 0, processors: 2, storages: 0, launchpads: 1 },
    links: 2,
    routes: 2,
    load: { cpu: 4600, powergrid: 2100 },
    products: [{ typeId: 9832, typeName: 'Coolant', tier: 'P2' }],
    extracted: [],
    skills: [{ name: 'Command Center Upgrades', level: 5 }],
  },
  gameTemplate: {
    CmdCtrLv: 5,
    Cmt: 'Comment written in the game',
    Diam: 0.5,
    L: [{ D: 2, Lv: 0, S: 1 }, { D: 3, Lv: 0, S: 1 }],
    P: [
      { H: 0, La: 1.25, Lo: -0.5, S: null, T: 2544 },
      { H: 0, La: 1.5, Lo: -0.25, S: 9832, T: 2474 },
      { H: 0, La: 1.75, Lo: 0.25, S: 9832, T: 2474 },
    ],
    Pln: 2016,
    R: [{ P: [2, 1], Q: 40, T: 3645 }, { P: [3, 1], Q: 40, T: 3645 }],
  },
  createdAt: '2026-11-24T10:00:00+00:00',
}

export const ROBOTICS_LAYOUT: ColonyLayout = {
  id: 'layout-robotics',
  name: 'Robotics line',
  summary: {
    planetTypeId: 2015,
    planetTypeName: 'Lava',
    commandCenterLevel: 3,
    buildings: { extractorControlUnits: 2, extractorHeads: 9, processors: 3, storages: 1, launchpads: 1 },
    links: 8,
    routes: 6,
    load: null,
    products: [{ typeId: 9848, typeName: 'Robotics', tier: 'P3' }, { typeId: 9830, typeName: 'Rocket Fuel', tier: 'P2' }],
    extracted: [{ typeId: 2073, typeName: 'Micro Organisms' }],
    skills: [{ name: 'Command Center Upgrades', level: 3 }],
  },
  gameTemplate: {
    CmdCtrLv: 3,
    Cmt: 'Lava robotics',
    Diam: 0.75,
    L: [{ D: 2, Lv: 1, S: 1 }],
    P: [
      { H: 0, La: 0.5, Lo: 0.25, S: null, T: 2555 },
      { H: 4, La: 0.75, Lo: 0.5, S: 2073, T: 2848 },
    ],
    Pln: 2015,
    R: [{ P: [2, 1], Q: 120, T: 2073 }],
  },
  createdAt: '2026-11-25T08:30:00+00:00',
}

export const VALID_GAME_TEXT = '{"CmdCtrLv":2,"Cmt":"Pasted colony","Diam":0.5,"L":[],"P":[],"Pln":2016,"R":[]}'
