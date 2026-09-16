/** AD&D 2nd Edition mechanical helpers (mirrors App\\Support\\Adnd2e). */

export const RACES = ['Human', 'Dwarf', 'Elf', 'Gnome', 'Half-Elf', 'Halfling', 'Half-Orc', 'Other'] as const

export const CLASSES = ['Fighter', 'Paladin', 'Ranger', 'Mage', 'Cleric', 'Druid', 'Thief', 'Bard', 'Psionicist'] as const

/** Compact class labels. Mage is mixed-case "Wiz" to match the sheet request. */
export const CLASS_ABBREVIATIONS: Record<string, string> = {
  Fighter: 'FR',
  Paladin: 'PAL',
  Ranger: 'RAN',
  Mage: 'Wiz',
  Cleric: 'CLR',
  Druid: 'DRU',
  Thief: 'TH',
  Bard: 'BRD',
  Psionicist: 'PSI',
}

/** Discipline name labels for the typed-power datalist only. Not kits. */
export const PSIONIC_DISCIPLINES = [
  'Clairsentience',
  'Psychokinesis',
  'Psychometabolism',
  'Psychoportation',
  'Telepathy',
  'Metapsionics',
] as const

export const SPECIALIST_SCHOOLS = [
  'Abjurer', 'Conjurer', 'Diviner', 'Enchanter', 'Illusionist', 'Invoker', 'Necromancer', 'Transmuter',
] as const

/** Racial-handbook kit names + thin eligibility. No benefit tables or handbook prose. */
export type RacialKit = {
  name: string
  races: readonly string[]
  classes: readonly string[]
  match?: 'all' | 'any'
}

export const HUMANOID_RACES = ['Half-Orc', 'Other'] as const

export const RACIAL_KITS: readonly RacialKit[] = [
  { name: 'Herbalist', races: ['Elf'], classes: ['Cleric'], match: 'any' },
  { name: 'Archer', races: ['Elf'], classes: ['Fighter', 'Ranger'], match: 'any' },
  { name: 'Wilderness Runner', races: ['Elf'], classes: ['Fighter', 'Ranger'], match: 'any' },
  { name: 'Windrider', races: ['Elf'], classes: ['Fighter', 'Ranger'], match: 'any' },
  { name: 'Elven Minstrel', races: ['Elf'], classes: ['Mage', 'Thief'], match: 'all' },
  { name: 'Spellfilcher', races: ['Elf'], classes: ['Mage', 'Thief'], match: 'all' },
  { name: 'Bladesinger', races: ['Elf'], classes: ['Fighter', 'Mage'], match: 'all' },
  { name: 'War Wizard', races: ['Elf'], classes: ['Fighter', 'Mage'], match: 'all' },
  { name: 'Huntsman', races: ['Elf'], classes: ['Fighter', 'Thief'], match: 'all' },
  { name: 'Collector', races: ['Elf'], classes: ['Fighter', 'Mage', 'Thief'], match: 'all' },
  { name: 'Infiltrator', races: ['Elf'], classes: ['Fighter', 'Mage', 'Thief'], match: 'all' },
  { name: 'Undead Slayer', races: ['Elf'], classes: [], match: 'any' },

  { name: 'Animal Master', races: ['Dwarf'], classes: ['Fighter'], match: 'any' },
  { name: 'Axe for Hire', races: ['Dwarf'], classes: ['Fighter'], match: 'any' },
  { name: 'Battlerager', races: ['Dwarf'], classes: ['Fighter'], match: 'any' },
  { name: 'Clansdwarf', races: ['Dwarf'], classes: ['Fighter'], match: 'any' },
  { name: 'Hearth Guard', races: ['Dwarf'], classes: ['Fighter'], match: 'any' },
  { name: 'Highborn', races: ['Dwarf'], classes: ['Fighter'], match: 'any' },
  { name: 'Outcast', races: ['Dwarf'], classes: ['Fighter'], match: 'any' },
  { name: 'Rapid Response Rider', races: ['Dwarf'], classes: ['Fighter'], match: 'any' },
  { name: 'Sharpshooter', races: ['Dwarf'], classes: ['Fighter'], match: 'any' },
  { name: 'Crafts Priest', races: ['Dwarf'], classes: ['Cleric'], match: 'any' },
  { name: 'Pariah', races: ['Dwarf'], classes: ['Cleric'], match: 'any' },
  { name: 'Patrician', races: ['Dwarf'], classes: ['Cleric'], match: 'any' },
  { name: 'Ritual Priest', races: ['Dwarf'], classes: ['Cleric'], match: 'any' },
  { name: 'Diplomat', races: ['Dwarf'], classes: ['Thief'], match: 'any' },
  { name: 'Entertainer', races: ['Dwarf'], classes: ['Thief'], match: 'any' },
  { name: 'Locksmith', races: ['Dwarf'], classes: ['Thief'], match: 'any' },
  { name: 'Pest Controller', races: ['Dwarf'], classes: ['Thief'], match: 'any' },
  { name: 'Champion', races: ['Dwarf'], classes: ['Fighter', 'Cleric'], match: 'all' },
  { name: 'Temple Guard', races: ['Dwarf'], classes: ['Fighter', 'Cleric'], match: 'all' },
  { name: 'Vindicator', races: ['Dwarf'], classes: ['Fighter', 'Cleric'], match: 'all' },
  { name: 'Ghetto Fighter', races: ['Dwarf'], classes: ['Fighter', 'Thief'], match: 'all' },
  { name: 'Trader', races: ['Dwarf'], classes: ['Fighter', 'Thief'], match: 'all' },
  { name: 'Vermin Slayer', races: ['Dwarf'], classes: ['Fighter', 'Thief'], match: 'all' },
  { name: 'Wayfinder', races: ['Dwarf'], classes: ['Fighter', 'Thief'], match: 'all' },

  { name: 'Breachgnome', races: ['Gnome'], classes: ['Fighter'], match: 'any' },
  { name: 'Goblinsticker', races: ['Gnome'], classes: ['Fighter'], match: 'any' },
  { name: 'Mouseburglar', races: ['Gnome'], classes: ['Thief'], match: 'any' },
  { name: 'Tumbler', races: ['Gnome'], classes: ['Thief'], match: 'any' },
  { name: 'Imagemaker', races: ['Gnome'], classes: ['Mage'], match: 'any' },
  { name: 'Vanisher', races: ['Gnome'], classes: ['Mage'], match: 'any' },
  { name: 'Buffoon', races: ['Gnome'], classes: ['Mage', 'Thief'], match: 'all' },
  { name: 'Stalker', races: ['Gnome'], classes: ['Fighter', 'Thief'], match: 'all' },
  { name: 'Rocktender', races: ['Gnome'], classes: ['Cleric'], match: 'any' },
  { name: 'Treetender', races: ['Gnome'], classes: ['Cleric'], match: 'any' },

  { name: 'Archer', races: ['Halfling'], classes: ['Fighter'], match: 'any' },
  { name: 'Forestwalker', races: ['Halfling'], classes: ['Fighter'], match: 'any' },
  { name: 'Homesteader', races: ['Halfling'], classes: ['Fighter'], match: 'any' },
  { name: 'Mercenary', races: ['Halfling'], classes: ['Fighter'], match: 'any' },
  { name: 'Sheriff', races: ['Halfling'], classes: ['Fighter'], match: 'any' },
  { name: 'Squire', races: ['Halfling'], classes: ['Fighter'], match: 'any' },
  { name: 'Tunnelrat', races: ['Halfling'], classes: ['Fighter'], match: 'any' },
  { name: 'Bandit', races: ['Halfling'], classes: ['Thief'], match: 'any' },
  { name: 'Bilker', races: ['Halfling'], classes: ['Thief'], match: 'any' },
  { name: 'Burglar', races: ['Halfling'], classes: ['Thief'], match: 'any' },
  { name: 'Smuggler', races: ['Halfling'], classes: ['Thief'], match: 'any' },
  { name: 'Urchin', races: ['Halfling'], classes: ['Thief'], match: 'any' },
  { name: 'Healer', races: ['Halfling'], classes: ['Cleric'], match: 'any' },
  { name: 'Leaftender', races: ['Halfling'], classes: ['Cleric'], match: 'any' },
  { name: 'Oracle', races: ['Halfling'], classes: ['Cleric'], match: 'any' },
  { name: 'Cartographer', races: ['Halfling'], classes: ['Fighter', 'Thief'], match: 'all' },
  { name: 'Trader', races: ['Halfling'], classes: ['Fighter', 'Thief'], match: 'all' },
  { name: 'Traveler', races: ['Halfling'], classes: ['Fighter', 'Thief'], match: 'all' },

  { name: 'Tribal Defender', races: HUMANOID_RACES, classes: ['Fighter'], match: 'any' },
  { name: 'Mine Rowdy', races: HUMANOID_RACES, classes: ['Fighter'], match: 'any' },
  { name: 'Pit Fighter', races: HUMANOID_RACES, classes: ['Fighter'], match: 'any' },
  { name: 'Saurial Paladin', races: HUMANOID_RACES, classes: ['Paladin'], match: 'any' },
  { name: 'Sellsword', races: HUMANOID_RACES, classes: ['Fighter'], match: 'any' },
  { name: 'Wilderness Protector', races: HUMANOID_RACES, classes: ['Fighter'], match: 'any' },
  { name: 'Hedge Wizard', races: HUMANOID_RACES, classes: ['Mage'], match: 'any' },
  { name: 'Humanoid Scholar', races: HUMANOID_RACES, classes: ['Mage'], match: 'any' },
  { name: 'Outlaw Mage', races: HUMANOID_RACES, classes: ['Mage'], match: 'any' },
  { name: 'Shaman', races: HUMANOID_RACES, classes: ['Cleric'], match: 'any' },
  { name: 'Witch Doctor', races: HUMANOID_RACES, classes: ['Cleric'], match: 'any' },
  { name: 'Oracle', races: HUMANOID_RACES, classes: ['Cleric'], match: 'any' },
  { name: 'War Priest', races: HUMANOID_RACES, classes: ['Cleric'], match: 'any' },
  { name: 'Wandering Mystic', races: HUMANOID_RACES, classes: ['Cleric'], match: 'any' },
  { name: 'Scavenger', races: HUMANOID_RACES, classes: ['Thief'], match: 'any' },
  { name: 'Tramp', races: HUMANOID_RACES, classes: ['Thief'], match: 'any' },
  { name: 'Tunnel Rat', races: HUMANOID_RACES, classes: ['Thief'], match: 'any' },
  { name: 'Shadow', races: HUMANOID_RACES, classes: ['Thief'], match: 'any' },
  { name: 'Humanoid Bard', races: HUMANOID_RACES, classes: ['Bard'], match: 'any' },
]

export function kitClassNames(entries: Array<{ class?: string } | string>): string[] {
  const have: string[] = []
  for (const entry of entries) {
    const raw = (typeof entry === 'string' ? entry : entry.class ?? '').trim()
    if (!raw) continue
    for (const part of raw.split(/\s*\/\s*/)) {
      const name = rewriteLegacyClass(part)
      if (name) have.push(name)
    }
  }
  return Array.from(new Set(have))
}

function kitRaceMatches(race: string, races: readonly string[]): boolean {
  return races.some(allowed => allowed.toLowerCase() === race.trim().toLowerCase())
}

function kitClassMatches(have: string[], needed: readonly string[], match: 'all' | 'any' = 'all'): boolean {
  if (needed.length === 0) return have.length > 0
  if (match === 'any') return needed.some(c => have.includes(c))
  return needed.every(c => have.includes(c))
}

export function suggestedRacialKits(race: string, entries: Array<{ class?: string } | string>): string[] {
  const have = kitClassNames(entries)
  const names: string[] = []
  for (const kit of RACIAL_KITS) {
    if (!kitRaceMatches(race, kit.races)) continue
    if (!kitClassMatches(have, kit.classes, kit.match ?? 'all')) continue
    names.push(kit.name)
  }
  return Array.from(new Set(names))
}

export function hasPsionicist(entries: Array<{ class?: string } | string>): boolean {
  return kitClassNames(entries).includes('Psionicist')
}

export function suggestedSubclassOptions(race: string, entries: Array<{ class?: string } | string>): string[] {
  const kits = suggestedRacialKits(race, entries)
  const names = kitClassNames(entries)
  const schools = names.includes('Mage') ? [...SPECIALIST_SCHOOLS] : []
  return Array.from(new Set([...schools, ...kits]))
}

export const ALIGNMENTS = [
  'Lawful Good', 'Neutral Good', 'Chaotic Good',
  'Lawful Neutral', 'True Neutral', 'Chaotic Neutral',
  'Lawful Evil', 'Neutral Evil', 'Chaotic Evil',
] as const

export const SAVE_CATEGORIES: Array<{ key: SaveKey; label: string }> = [
  { key: 'paralyzation', label: 'Paralyzation / Poison / Death' },
  { key: 'rod', label: 'Rod / Staff / Wand' },
  { key: 'petrification', label: 'Petrification / Polymorph' },
  { key: 'breath', label: 'Breath Weapon' },
  { key: 'spell', label: 'Spell' },
]

export type SaveKey = 'paralyzation' | 'rod' | 'petrification' | 'breath' | 'spell'

export const PRIEST_SPHERES = [
  'All', 'Animal', 'Astral', 'Charm', 'Combat', 'Creation', 'Divination', 'Elemental',
  'Guardian', 'Healing', 'Necromantic', 'Plant', 'Protection', 'Summoning', 'Sun', 'Weather',
]

export const WEAPON_PROFICIENCY_SUGGESTIONS = [
  'Long sword', 'Short sword', 'Bastard sword', 'Two-handed sword', 'Battle axe', 'Hand axe',
  'Dagger', 'Spear', 'Halberd', 'Morning star', 'Mace', 'Warhammer', 'Club', 'Quarterstaff',
  'Long bow', 'Short bow', 'Crossbow, light', 'Crossbow, heavy', 'Sling', 'Dart', 'Javelin',
  'Lance', 'Flail',
]

export const NONWEAPON_PROFICIENCY_SUGGESTIONS = [
  'Agriculture', 'Animal Handling', 'Animal Lore', 'Animal Training', 'Artistic Ability',
  'Astrology', 'Blacksmithing', 'Blind-fighting', 'Brewing', 'Carpentry', 'Cooking', 'Dancing',
  'Direction Sense', 'Endurance', 'Engineering', 'Etiquette', 'Fire-building', 'Fishing',
  'Healing', 'Heraldry', 'Herbalism', 'Hunting', 'Jumping', 'Languages, Ancient',
  'Languages, Modern', 'Leatherworking', 'Local History', 'Mining', 'Mountaineering',
  'Musical Instrument', 'Navigation', 'Pottery', 'Reading/Writing', 'Religion',
  'Riding, Land-based', 'Rope Use', 'Running', 'Seamanship', 'Set Snares', 'Singing',
  'Spellcraft', 'Stonemasonry', 'Survival', 'Swimming', 'Tracking', 'Weather Sense', 'Weaving',
]

export const CONDITIONS_2E = [
  'Blinded', 'Charmed', 'Confused', 'Cursed', 'Diseased', 'Feebleminded', 'Held',
  'Invisible', 'Paralyzed', 'Petrified', 'Poisoned', 'Silenced', 'Sleeping', 'Slowed',
  'Hasted', 'Unconscious', 'Dying', 'Dead', 'Fear', 'Berserk',
]

export const DEATH_THRESHOLD = -10

/** Copies of one known spell that may be marked memorized (2E Vancian). */
export const MAX_TIMES_MEMORIZED = 12

/** House dual-class: original class must be this level before a new class may begin. */
export const HOUSE_DUAL_MIN_ORIGINAL_LEVEL = 6

/** This table switches at 6th; resume is 6 − 1 = 5th in the new class. */
export const HOUSE_DUAL_RESUME_NEW_LEVEL = 5

export type ClassGroup = 'warrior' | 'priest' | 'rogue' | 'wizard'

export function normalizeClass(characterClass: string): string {
  if ((SPECIALIST_SCHOOLS as readonly string[]).includes(characterClass) || characterClass === 'Wizard' || characterClass === 'Illusionist') {
    return 'Mage'
  }
  if (characterClass === 'Rogue') return 'Thief'
  if (characterClass === 'Priest') return 'Cleric'
  if (characterClass === 'Psion' || characterClass === 'Psionic' || characterClass === 'Psionics') return 'Psionicist'
  return characterClass
}

/**
 * Psionicist uses the rogue combat group in this engine: d6 HD and rogue
 * THAC0/saves. CPHB treats it as its own group; we do not reprint that table.
 */
export function classGroup(characterClass: string): ClassGroup {
  const c = normalizeClass(characterClass)
  if (c === 'Fighter' || c === 'Paladin' || c === 'Ranger') return 'warrior'
  if (c === 'Cleric' || c === 'Druid') return 'priest'
  if (c === 'Thief' || c === 'Bard' || c === 'Psionicist') return 'rogue'
  return 'wizard'
}

export function isSpecialist(characterClass: string, subclass?: string | null): boolean {
  if ((SPECIALIST_SCHOOLS as readonly string[]).includes(characterClass)) return true
  return !!subclass && (SPECIALIST_SCHOOLS as readonly string[]).includes(subclass)
}

export function hitDie(characterClass: string): string {
  switch (classGroup(characterClass)) {
    case 'warrior': return 'd10'
    case 'priest': return 'd8'
    case 'rogue': return 'd6'
    default: return 'd4'
  }
}

export function movementRate(race: string): number {
  return race === 'Dwarf' || race === 'Gnome' || race === 'Halfling' ? 6 : 12
}

export function thac0(characterClass: string, level: number): number {
  const lv = Math.max(1, Math.min(20, level))
  switch (classGroup(characterClass)) {
    case 'warrior':
      return 21 - lv
    case 'priest':
      if (lv <= 3) return 20
      if (lv <= 6) return 18
      if (lv <= 9) return 16
      if (lv <= 12) return 14
      if (lv <= 15) return 12
      if (lv <= 18) return 10
      return 8
    case 'rogue':
      if (lv <= 4) return 20
      if (lv <= 8) return 19
      if (lv <= 12) return 16
      if (lv <= 16) return 14
      return 12
    default:
      if (lv <= 5) return 20
      if (lv <= 10) return 19
      if (lv <= 15) return 16
      return 14
  }
}

export type SavingThrows = Record<SaveKey, number>

export function savingThrows(characterClass: string, level: number): SavingThrows {
  const lv = Math.max(1, Math.min(20, level))
  const group = classGroup(characterClass)
  let row: [number, number, number, number, number]
  if (group === 'warrior') {
    if (lv <= 2) row = [14, 16, 15, 17, 17]
    else if (lv <= 4) row = [13, 15, 14, 16, 16]
    else if (lv <= 6) row = [11, 13, 12, 13, 14]
    else if (lv <= 8) row = [10, 12, 11, 12, 13]
    else if (lv <= 10) row = [8, 10, 9, 9, 11]
    else if (lv <= 12) row = [7, 9, 8, 8, 10]
    else if (lv <= 14) row = [5, 7, 6, 5, 8]
    else if (lv <= 16) row = [4, 6, 5, 4, 7]
    else row = [3, 5, 4, 4, 6]
  } else if (group === 'priest') {
    if (lv <= 3) row = [10, 14, 13, 16, 15]
    else if (lv <= 6) row = [9, 13, 12, 15, 14]
    else if (lv <= 9) row = [7, 11, 10, 13, 12]
    else if (lv <= 12) row = [6, 10, 9, 12, 11]
    else if (lv <= 15) row = [5, 9, 8, 11, 10]
    else if (lv <= 18) row = [4, 8, 7, 10, 9]
    else row = [2, 6, 5, 8, 7]
  } else if (group === 'rogue') {
    if (lv <= 4) row = [13, 14, 12, 16, 15]
    else if (lv <= 8) row = [12, 12, 11, 15, 13]
    else if (lv <= 12) row = [11, 10, 10, 14, 11]
    else if (lv <= 16) row = [10, 8, 9, 13, 9]
    else row = [9, 6, 8, 12, 7]
  } else {
    if (lv <= 5) row = [14, 11, 13, 15, 12]
    else if (lv <= 10) row = [13, 9, 11, 13, 10]
    else if (lv <= 15) row = [11, 7, 9, 11, 8]
    else row = [10, 5, 7, 9, 6]
  }
  return { paralyzation: row[0], rod: row[1], petrification: row[2], breath: row[3], spell: row[4] }
}

export function strengthAdjustments(score: number, exceptional?: string | null): {
  hit: number
  damage: number
  weight_allow: number
  max_press: number
  open_doors: string
  bend_bars: number
} {
  if (score <= 1) return { hit: -5, damage: -4, weight_allow: 1, max_press: 3, open_doors: '1', bend_bars: 0 }
  if (score === 2) return { hit: -3, damage: -2, weight_allow: 1, max_press: 5, open_doors: '1', bend_bars: 0 }
  if (score === 3) return { hit: -3, damage: -1, weight_allow: 5, max_press: 10, open_doors: '2', bend_bars: 0 }
  if (score <= 5) return { hit: -2, damage: -1, weight_allow: 10, max_press: 25, open_doors: '3', bend_bars: 0 }
  if (score <= 7) return { hit: -1, damage: 0, weight_allow: 20, max_press: 55, open_doors: '4', bend_bars: 0 }
  if (score <= 9) return { hit: 0, damage: 0, weight_allow: 35, max_press: 90, open_doors: '5', bend_bars: 1 }
  if (score <= 11) return { hit: 0, damage: 0, weight_allow: 40, max_press: 115, open_doors: '6', bend_bars: 2 }
  if (score <= 13) return { hit: 0, damage: 0, weight_allow: 45, max_press: 140, open_doors: '7', bend_bars: 4 }
  if (score <= 15) return { hit: 0, damage: 0, weight_allow: 55, max_press: 170, open_doors: '8', bend_bars: 7 }
  if (score === 16) return { hit: 0, damage: 1, weight_allow: 70, max_press: 195, open_doors: '9', bend_bars: 10 }
  if (score === 17) return { hit: 1, damage: 1, weight_allow: 85, max_press: 220, open_doors: '10', bend_bars: 13 }
  if (score === 18) {
    const exc = parseExceptional(exceptional)
    if (exc === null) return { hit: 1, damage: 2, weight_allow: 110, max_press: 255, open_doors: '11', bend_bars: 16 }
    if (exc <= 50) return { hit: 1, damage: 3, weight_allow: 135, max_press: 280, open_doors: '12', bend_bars: 20 }
    if (exc <= 75) return { hit: 2, damage: 3, weight_allow: 160, max_press: 305, open_doors: '13', bend_bars: 25 }
    if (exc <= 90) return { hit: 2, damage: 4, weight_allow: 185, max_press: 330, open_doors: '14', bend_bars: 30 }
    if (exc <= 99) return { hit: 3, damage: 5, weight_allow: 235, max_press: 380, open_doors: '15 (3)', bend_bars: 35 }
    return { hit: 3, damage: 6, weight_allow: 335, max_press: 480, open_doors: '16 (6)', bend_bars: 40 }
  }
  if (score === 19) return { hit: 3, damage: 7, weight_allow: 485, max_press: 640, open_doors: '16 (8)', bend_bars: 50 }
  if (score === 20) return { hit: 3, damage: 8, weight_allow: 535, max_press: 700, open_doors: '17 (10)', bend_bars: 60 }
  return { hit: 4, damage: 9, weight_allow: 635, max_press: 810, open_doors: '17 (12)', bend_bars: 70 }
}

export function dexterityAdjustments(score: number): { reaction: number; missile: number; defensive: number } {
  if (score <= 1) return { reaction: -6, missile: -6, defensive: 5 }
  if (score === 2) return { reaction: -4, missile: -4, defensive: 5 }
  if (score === 3) return { reaction: -3, missile: -3, defensive: 4 }
  if (score <= 5) return { reaction: -2, missile: -2, defensive: 3 }
  if (score === 6) return { reaction: -1, missile: -1, defensive: 2 }
  if (score <= 14) return { reaction: 0, missile: 0, defensive: 0 }
  if (score === 15) return { reaction: 0, missile: 0, defensive: -1 }
  if (score === 16) return { reaction: 1, missile: 1, defensive: -2 }
  if (score === 17) return { reaction: 2, missile: 2, defensive: -3 }
  if (score === 18) return { reaction: 2, missile: 2, defensive: -4 }
  if (score === 19) return { reaction: 3, missile: 3, defensive: -4 }
  return { reaction: 3, missile: 3, defensive: -5 }
}

export function constitutionHpAdjustment(score: number, characterClass = 'Fighter'): number {
  const warrior = classGroup(characterClass) === 'warrior'
  if (score <= 1) return -3
  if (score <= 3) return -2
  if (score <= 6) return -1
  if (score <= 14) return 0
  if (score === 15) return 1
  if (score === 16) return 2
  if (score === 17) return warrior ? 3 : 2
  if (score === 18) return warrior ? 4 : 2
  return warrior ? 5 : 2
}

export function constitutionAdjustments(score: number, characterClass = 'Fighter'): {
  hp: number
  system_shock: number
  resurrection: number
  poison_save: number
  regeneration: string | null
} {
  let shock = 70
  let resurrection = 75
  let poison = 0
  let regen: string | null = null
  if (score <= 1) { shock = 25; resurrection = 30 }
  else if (score === 2) { shock = 30; resurrection = 35 }
  else if (score === 3) { shock = 35; resurrection = 40 }
  else if (score === 4) { shock = 40; resurrection = 45 }
  else if (score === 5) { shock = 45; resurrection = 50 }
  else if (score === 6) { shock = 50; resurrection = 55 }
  else if (score === 7) { shock = 55; resurrection = 60 }
  else if (score === 8) { shock = 60; resurrection = 65 }
  else if (score === 9) { shock = 65; resurrection = 70 }
  else if (score === 10) { shock = 70; resurrection = 75 }
  else if (score === 11) { shock = 75; resurrection = 80 }
  else if (score === 12) { shock = 80; resurrection = 85 }
  else if (score === 13) { shock = 85; resurrection = 90 }
  else if (score === 14) { shock = 88; resurrection = 92 }
  else if (score === 15) { shock = 90; resurrection = 94 }
  else if (score === 16) { shock = 95; resurrection = 96 }
  else if (score === 17) { shock = 97; resurrection = 98 }
  else if (score === 18) { shock = 99; resurrection = 100 }
  else if (score === 19) { shock = 99; resurrection = 100; poison = 1 }
  else if (score === 20) { shock = 99; resurrection = 100; poison = 1; regen = '1/6 turns' }
  else if (score === 21) { shock = 99; resurrection = 100; poison = 2; regen = '1/5 turns' }
  else if (score === 22) { shock = 99; resurrection = 100; poison = 2; regen = '1/4 turns' }
  else if (score === 23) { shock = 99; resurrection = 100; poison = 3; regen = '1/3 turns' }
  else if (score === 24) { shock = 99; resurrection = 100; poison = 3; regen = '1/2 turns' }
  else { shock = 100; resurrection = 100; poison = 4; regen = '1/1 turn' }

  return {
    hp: constitutionHpAdjustment(score, characterClass),
    system_shock: shock,
    resurrection,
    poison_save: poison,
    regeneration: regen,
  }
}

export function wisdomMagicalDefense(score: number): number {
  if (score <= 1) return -6
  if (score === 2) return -4
  if (score === 3) return -3
  if (score <= 5) return -2
  if (score <= 7) return -1
  if (score <= 14) return 0
  if (score === 15) return 1
  if (score === 16) return 2
  if (score === 17) return 3
  return 4
}

export function wisdomBonusSpells(score: number): Record<number, number> {
  if (score <= 12) return {}
  if (score === 13) return { 1: 1 }
  if (score === 14) return { 1: 2 }
  if (score === 15) return { 1: 2, 2: 1 }
  if (score === 16) return { 1: 2, 2: 2 }
  if (score === 17) return { 1: 2, 2: 2, 3: 1 }
  return { 1: 2, 2: 2, 3: 1, 4: 1 }
}

export function wisdomSpellFailure(score: number): number {
  if (score <= 1) return 80
  if (score === 2) return 60
  if (score === 3) return 50
  if (score === 4) return 45
  if (score === 5) return 40
  if (score === 6) return 35
  if (score === 7) return 30
  if (score === 8) return 25
  if (score === 9) return 20
  if (score === 10) return 15
  if (score === 11) return 10
  if (score === 12) return 5
  return 0
}

export function charismaAdjustments(score: number): { max_henchmen: number; loyalty: number; reaction: number } {
  if (score <= 2) return { max_henchmen: 1, loyalty: -8, reaction: -7 }
  if (score === 3) return { max_henchmen: 1, loyalty: -6, reaction: -5 }
  if (score <= 5) return { max_henchmen: 2, loyalty: -4, reaction: -3 }
  if (score <= 7) return { max_henchmen: 3, loyalty: -2, reaction: -1 }
  if (score <= 11) return { max_henchmen: 4, loyalty: 0, reaction: 0 }
  if (score === 12) return { max_henchmen: 5, loyalty: 0, reaction: 0 }
  if (score === 13) return { max_henchmen: 5, loyalty: 0, reaction: 1 }
  if (score === 14) return { max_henchmen: 6, loyalty: 1, reaction: 2 }
  if (score === 15) return { max_henchmen: 7, loyalty: 3, reaction: 3 }
  if (score === 16) return { max_henchmen: 8, loyalty: 4, reaction: 5 }
  if (score === 17) return { max_henchmen: 10, loyalty: 6, reaction: 6 }
  return { max_henchmen: 15, loyalty: 8, reaction: 7 }
}

export function intelligenceLimits(score: number): {
  languages: number
  max_spell_level: number | null
  chance_to_learn: number | null
  max_spells_per_level: number | null
} {
  if (score <= 8) return { languages: 1, max_spell_level: null, chance_to_learn: null, max_spells_per_level: null }
  if (score === 9) return { languages: 2, max_spell_level: 4, chance_to_learn: 35, max_spells_per_level: 6 }
  if (score === 10) return { languages: 2, max_spell_level: 5, chance_to_learn: 40, max_spells_per_level: 7 }
  if (score === 11) return { languages: 2, max_spell_level: 5, chance_to_learn: 45, max_spells_per_level: 7 }
  if (score === 12) return { languages: 3, max_spell_level: 6, chance_to_learn: 50, max_spells_per_level: 7 }
  if (score === 13) return { languages: 3, max_spell_level: 6, chance_to_learn: 55, max_spells_per_level: 9 }
  if (score === 14) return { languages: 4, max_spell_level: 7, chance_to_learn: 60, max_spells_per_level: 9 }
  if (score === 15) return { languages: 4, max_spell_level: 7, chance_to_learn: 65, max_spells_per_level: 11 }
  if (score === 16) return { languages: 5, max_spell_level: 8, chance_to_learn: 70, max_spells_per_level: 11 }
  if (score === 17) return { languages: 5, max_spell_level: 8, chance_to_learn: 75, max_spells_per_level: 14 }
  return { languages: 7, max_spell_level: 9, chance_to_learn: 85, max_spells_per_level: 18 }
}

export function primaryAdjustment(ability: string, score: number, exceptional?: string | null, characterClass = 'Fighter'): number {
  switch (ability) {
    case 'strength': return strengthAdjustments(score, exceptional).hit
    case 'dexterity': return dexterityAdjustments(score).missile
    case 'constitution': return constitutionHpAdjustment(score, characterClass)
    case 'intelligence': return intelligenceLimits(score).languages - 2
    case 'wisdom': return wisdomMagicalDefense(score)
    case 'charisma': return charismaAdjustments(score).reaction
    default: return 0
  }
}

export function primaryAdjustmentLabel(ability: string): string {
  switch (ability) {
    case 'strength': return 'hit'
    case 'dexterity': return 'missile'
    case 'constitution': return 'HP'
    case 'intelligence': return 'lang'
    case 'wisdom': return 'MD'
    case 'charisma': return 'react'
    default: return 'mod'
  }
}

export function formatAbilityScore(ability: string, score: number, exceptional?: string | null): string {
  if (ability !== 'strength' || score !== 18) return String(score)
  const raw = (exceptional ?? '').toUpperCase().trim()
  if (!raw) return '18'
  if (raw === '00' || raw === '100') return '18/00'
  if (!/^\d{1,3}$/.test(raw)) return '18'
  return `18/${raw.padStart(2, '0')}`
}

export function formatWisdomBonusSpells(bonus: Record<number, number>): string {
  const levels = Object.keys(bonus).map(Number).sort((a, b) => a - b)
  if (levels.length === 0) return '—'
  const ordinal: Record<number, string> = { 1: '1st', 2: '2nd', 3: '3rd', 4: '4th', 5: '5th', 6: '6th', 7: '7th' }
  return levels.map(level => `${ordinal[level] ?? `${level}th`}×${bonus[level]}`).join(', ')
}

export type AbilityAdjustmentLine = { label: string; value: string }

export const ABILITY_ORDER = [
  { key: 'strength', label: 'STR' },
  { key: 'dexterity', label: 'DEX' },
  { key: 'constitution', label: 'CON' },
  { key: 'intelligence', label: 'INT' },
  { key: 'wisdom', label: 'WIS' },
  { key: 'charisma', label: 'CHA' },
] as const

export type AbilityKey = typeof ABILITY_ORDER[number]['key']

export function abilityAdjustmentLines(
  ability: string,
  score: number,
  exceptional?: string | null,
  characterClass = 'Fighter',
): AbilityAdjustmentLine[] {
  if (ability === 'strength') {
    const row = strengthAdjustments(score, exceptional)
    return [
      { label: 'hit', value: formatSigned(row.hit) },
      { label: 'dmg', value: formatSigned(row.damage) },
      { label: 'wt', value: String(row.weight_allow) },
      { label: 'press', value: String(row.max_press) },
      { label: 'open', value: row.open_doors },
      { label: 'BB', value: `${row.bend_bars}%` },
    ]
  }
  if (ability === 'dexterity') {
    const row = dexterityAdjustments(score)
    return [
      { label: 'react', value: formatSigned(row.reaction) },
      { label: 'missile', value: formatSigned(row.missile) },
      { label: 'def', value: formatSigned(row.defensive) },
    ]
  }
  if (ability === 'constitution') {
    const row = constitutionAdjustments(score, characterClass)
    const lines: AbilityAdjustmentLine[] = [
      { label: 'HP', value: formatSigned(row.hp) },
      { label: 'shock', value: `${row.system_shock}%` },
      { label: 'resurrect', value: `${row.resurrection}%` },
      { label: 'poison', value: formatSigned(row.poison_save) },
    ]
    if (row.regeneration) lines.push({ label: 'regen', value: row.regeneration })
    return lines
  }
  if (ability === 'intelligence') {
    const row = intelligenceLimits(score)
    const lines: AbilityAdjustmentLine[] = [{ label: 'langs', value: String(row.languages) }]
    if (row.max_spell_level !== null) lines.push({ label: 'spell lvl', value: String(row.max_spell_level) })
    if (row.chance_to_learn !== null) lines.push({ label: 'learn', value: `${row.chance_to_learn}%` })
    if (row.max_spells_per_level !== null) lines.push({ label: 'max/lvl', value: String(row.max_spells_per_level) })
    return lines
  }
  if (ability === 'wisdom') {
    return [
      { label: 'MD', value: formatSigned(wisdomMagicalDefense(score)) },
      { label: 'bonus', value: formatWisdomBonusSpells(wisdomBonusSpells(score)) },
      { label: 'fail', value: `${wisdomSpellFailure(score)}%` },
    ]
  }
  if (ability === 'charisma') {
    const row = charismaAdjustments(score)
    return [
      { label: 'hench', value: String(row.max_henchmen) },
      { label: 'loyalty', value: formatSigned(row.loyalty) },
      { label: 'react', value: formatSigned(row.reaction) },
    ]
  }
  return []
}

export function formatSigned(n: number): string {
  return n >= 0 ? `+${n}` : `${n}`
}

export function numberNeededToHit(characterThac0: number, armorClass: number): number {
  return characterThac0 - armorClass
}

export function resolveAttack(characterThac0: number, armorClass: number, roll: number) {
  const needed = numberNeededToHit(characterThac0, armorClass)
  const automatic = roll === 1 || roll === 20
  const hit = roll === 20 || (roll !== 1 && roll >= needed)
  return { hit, needed, roll, automatic }
}

export function resolveInitiative(d10: number, dexterity: number, otherModifiers = 0) {
  const reaction = dexterityAdjustments(dexterity).reaction
  return { roll: d10, modifier: -reaction + otherModifiers, total: d10 - reaction + otherModifiers }
}

export function vitalityState(currentHp: number): 'ok' | 'unconscious' | 'dying' | 'dead' {
  if (currentHp <= DEATH_THRESHOLD) return 'dead'
  if (currentHp < 0) return 'dying'
  if (currentHp === 0) return 'unconscious'
  return 'ok'
}

export type ClassEntry = { class: string; level: number; xp?: number | null }
export type ClassPath = 'single' | 'multi' | 'dual'

export function rewriteLegacyClass(name: string): string {
  const key = name.trim().toLowerCase()
  if (['warlock', 'sorcerer', 'wizard', 'artificer'].includes(key)) return 'Mage'
  if (key === 'rogue') return 'Thief'
  if (['barbarian', 'monk', 'blood hunter'].includes(key)) return 'Fighter'
  if (key === 'priest') return 'Cleric'
  if (['psion', 'psionic', 'psionics'].includes(key)) return 'Psionicist'
  return normalizeClass(name)
}

export function normalizeClassLevels(
  classLevels: ClassEntry[] | null | undefined,
  characterClass: string,
  level: number,
  path: ClassPath = 'single',
): ClassEntry[] {
  const fromJson = (classLevels ?? [])
    .filter(e => e && e.class)
    .map(e => {
      const entry: ClassEntry = {
        class: rewriteLegacyClass(e.class),
        level: Math.max(1, Math.min(20, Number(e.level) || level)),
      }
      if (e.xp !== undefined && e.xp !== null && e.xp !== ('' as unknown as number)) {
        const xp = Number(e.xp)
        if (Number.isFinite(xp) && xp >= 0) entry.xp = xp
      }
      return entry
    })
  let entries = fromJson
  if (entries.length === 0) {
    if (characterClass.includes('/')) {
      entries = characterClass.split(/\s*\/\s*/).map(part => ({ class: rewriteLegacyClass(part), level }))
    } else {
      entries = [{ class: rewriteLegacyClass(characterClass), level }]
    }
  }
  if (path === 'single') return entries.slice(0, 1)
  return entries.slice(0, 3)
}

export function displayClassName(entries: ClassEntry[], path: ClassPath = 'single'): string {
  const names = entries.map(e => e.class)
  if (path === 'dual' && names.length >= 2) return `${names[0]} → ${names[1]}`
  if (names.length > 1) return names.join('/')
  return names[0] ?? 'Fighter'
}

export function displayLevel(entries: ClassEntry[], path: ClassPath = 'single'): number {
  if (entries.length === 0) return 1
  if (path === 'dual') return entries[entries.length - 1].level
  return Math.max(...entries.map(e => e.level))
}

/** Prefer stored path; if it is missing/single, infer multi/dual from class_levels or class label. */
export function resolveClassPath(
  classPath: ClassPath | string | null | undefined,
  classLevels: ClassEntry[] | null | undefined,
  characterClass = '',
): ClassPath {
  if (classPath === 'multi' || classPath === 'dual') return classPath
  const label = characterClass || ''
  if (label.includes('→') || label.includes('->')) return 'dual'
  const n = (classLevels ?? []).filter(e => e && e.class).length
  if (n >= 2 || label.includes('/')) return 'multi'
  return 'single'
}

export function classAbbreviation(className: string): string {
  const name = className.trim()
  if (!name) return '?'
  if (CLASS_ABBREVIATIONS[name]) return CLASS_ABBREVIATIONS[name]
  const normalized = rewriteLegacyClass(name)
  if (CLASS_ABBREVIATIONS[normalized]) return CLASS_ABBREVIATIONS[normalized]
  if ((SPECIALIST_SCHOOLS as readonly string[]).includes(name) || (SPECIALIST_SCHOOLS as readonly string[]).includes(normalized)) {
    return 'Wiz'
  }
  const clean = name.replace(/[^A-Za-z]/g, '')
  return clean ? clean.slice(0, 3).toUpperCase() : '?'
}

/** Compact class/level line: "FR 11 / Wiz 12", "PSI 9 → FR 10", "CLR 10". */
export function formatClassLevelsLine(entries: ClassEntry[], path: ClassPath = 'single'): string {
  const parts = entries
    .filter(e => e.class)
    .map(e => `${classAbbreviation(e.class)} ${e.level}`)
  if (parts.length === 0) return ''
  if (path === 'dual' && parts.length >= 2) return parts.join(' → ')
  return parts.join(' / ')
}

export function formatXpAmount(xp: number, compact = false): string {
  if (compact && xp >= 10000 && xp % 1000 === 0) return `${xp / 1000}k`
  return xp.toLocaleString()
}

export function formatClassXpLine(
  entries: ClassEntry[],
  _path: ClassPath = 'single',
  compact = true,
  omitMissing = false,
): string {
  const parts: string[] = []
  for (const entry of entries) {
    if (!entry.class) continue
    const abbr = classAbbreviation(entry.class)
    if (entry.xp !== undefined && entry.xp !== null) {
      parts.push(`${abbr} ${formatXpAmount(entry.xp, compact)}`)
    } else if (!omitMissing) {
      parts.push(`${abbr} —`)
    }
  }
  return parts.join(' · ')
}

export function derivedExperiencePoints(entries: ClassEntry[], legacyXp = 0): number {
  let sum = 0
  let any = false
  for (const entry of entries) {
    if (entry.xp !== undefined && entry.xp !== null) {
      sum += Math.max(0, entry.xp)
      any = true
    }
  }
  return any ? sum : Math.max(0, legacyXp)
}

/**
 * Copy a legacy experience_points total into class_levels when per-class xp
 * is missing. Does not invent splits: single → only entry; dual → last class;
 * multi → leave empty.
 */
export function backfillClassLevelsXp(
  entries: ClassEntry[],
  path: ClassPath | string | null | undefined,
  legacyXp: number,
): ClassEntry[] {
  const hasXp = entries.some(e => e.xp !== undefined && e.xp !== null)
  const legacy = Math.max(0, Number(legacyXp) || 0)
  if (hasXp || legacy <= 0 || entries.length === 0) return entries
  const resolved: ClassPath = path === 'multi' || path === 'dual' ? path : 'single'
  if (resolved === 'multi') return entries
  const next = entries.map(e => ({ ...e }))
  const index = resolved === 'dual' ? next.length - 1 : 0
  next[index] = { ...next[index], xp: legacy }
  return next
}

/** House dual-class: begin a new class only after the original is 6th. */
export function canBeginNewClass(originalLevel: number): boolean {
  return originalLevel >= HOUSE_DUAL_MIN_ORIGINAL_LEVEL
}

/**
 * Resume the original class when the new class is 5th.
 * originalLevelAtSwitch is 6 on this table (6 − 1 = 5). Do not pass current original level.
 */
export function canResumeOriginalClass(newLevel: number): boolean {
  return newLevel >= HOUSE_DUAL_RESUME_NEW_LEVEL
}

export function dualResumeAllowed(entries: ClassEntry[]): boolean {
  if (entries.length < 2) return false
  return canResumeOriginalClass(entries[1].level)
}

export function combinedThac0(entries: ClassEntry[]): number {
  if (entries.length === 0) return 20
  return Math.min(...entries.map(e => thac0(e.class, e.level)))
}

export function combinedSavingThrows(entries: ClassEntry[]): SavingThrows {
  const empty: SavingThrows = { paralyzation: 20, rod: 20, petrification: 20, breath: 20, spell: 20 }
  return entries.reduce((acc, e) => {
    const row = savingThrows(e.class, e.level)
    return {
      paralyzation: Math.min(acc.paralyzation, row.paralyzation),
      rod: Math.min(acc.rod, row.rod),
      petrification: Math.min(acc.petrification, row.petrification),
      breath: Math.min(acc.breath, row.breath),
      spell: Math.min(acc.spell, row.spell),
    }
  }, empty)
}

export function combinedHitDie(entries: ClassEntry[]): string {
  const dice = Array.from(new Set(entries.map(e => hitDie(e.class))))
  return dice.join('/') || 'd10'
}

export function anyCaster(entries: ClassEntry[]): boolean {
  return entries.some(e => isCaster(e.class, e.level))
}

export function weaponSpeed(weapon?: string | null): number | null {
  if (!weapon) return null
  const key = weapon.toLowerCase().replace(/^(a|an|the)\s+/, '')
  if (key.includes('dagger') || key.includes('dart')) return 2
  if (key.includes('short sword')) return 3
  if (key.includes('hand axe') || key.includes('club') || key.includes('staff') || key.includes('warhammer') || key.includes('javelin')) return 4
  if (key.includes('long sword') || key.includes('spear') || key.includes('mace') || key.includes('sling')) return 5
  if (key.includes('bastard') || key.includes('flail') || key.includes('morning')) return 6
  if (key.includes('battle axe') || key.includes('short bow') || key.includes('light crossbow')) return 7
  if (key.includes('long bow') || key.includes('lance')) return 8
  if (key.includes('halberd')) return 9
  if (key.includes('two-handed') || key.includes('two handed') || key.includes('heavy crossbow')) return 10
  return null
}

export function isCaster(characterClass: string, level: number): boolean {
  const c = normalizeClass(characterClass)
  if (c === 'Mage' || c === 'Cleric' || c === 'Druid' || c === 'Bard') return true
  if (c === 'Paladin') return level >= 9
  if (c === 'Ranger') return level >= 8
  return false
}

export function timesMemorizedOf(spell: { times_memorized?: number | null; is_prepared?: boolean }): number {
  const n = spell.times_memorized ?? 0
  if (n > 0) return n
  return spell.is_prepared ? 1 : 0
}

export function remainingMemorizedOf(spell: {
  times_memorized?: number | null
  times_cast?: number | null
  is_prepared?: boolean
}): number {
  return Math.max(0, timesMemorizedOf(spell) - (spell.times_cast ?? 0))
}

/** Case-insensitive name, then contains either direction (mirrors PHP matching). */
export function inventoryHasMaterial(
  items: Array<{ name: string; quantity?: number }> | undefined,
  name: string,
  quantity = 1,
): boolean {
  if (!items || items.length === 0) return false
  const needle = name.trim().toLowerCase()
  if (needle === '') return false
  return items.some(item => {
    const hay = (item.name ?? '').toLowerCase().trim()
    const have = item.quantity ?? 1
    if (have < quantity) return false
    if (hay === needle) return true
    if (needle.length < 3) return false
    return hay.includes(needle) || (hay.length >= 3 && needle.includes(hay))
  })
}

export function missingSpellMaterials(
  spell: { material_requirements?: Array<{ name: string; quantity: number; consumed: boolean; focus: boolean }> },
  items: Array<{ name: string; quantity?: number }> | undefined,
): string[] {
  return (spell.material_requirements ?? [])
    .filter(req => !inventoryHasMaterial(items, req.name, req.quantity))
    .map(req => req.name)
}

export function materialRequirementQuantity(quantity?: number | null): number {
  const n = Number(quantity)
  return Number.isFinite(n) && n > 0 ? Math.floor(n) : 1
}

/** Always includes the count, including 1×. */
export function formatMaterialAmount(name: string, quantity?: number | null): string {
  return `${materialRequirementQuantity(quantity)}× ${name}`
}

export function formatLinkedMaterial(req: {
  name: string
  quantity?: number | null
  focus?: boolean
}): string {
  const kind = req.focus ? 'focus' : 'spend'
  return `${kind} ${formatMaterialAmount(req.name, req.quantity)}`
}

export function isComponentCategory(category?: string | null): boolean {
  const c = (category ?? '').trim().toLowerCase()
  return c === 'component' || /\bcomponents?\b/.test(c)
}

/** Visible quantity badge. Components always show an amount, including ×1. */
export function inventoryQuantityLabel(item: {
  quantity?: number | null
  category?: string | null
}): string | null {
  const qty = item.quantity ?? 1
  if (qty !== 1 || isComponentCategory(item.category)) {
    return `×${qty}`
  }
  return null
}

export function memorizedCopyTotal(spells: Array<{ times_memorized?: number | null; is_prepared?: boolean }>): number {
  return spells.reduce((sum, spell) => sum + timesMemorizedOf(spell), 0)
}

export function remainingCopyTotal(spells: Array<{
  times_memorized?: number | null
  times_cast?: number | null
  is_prepared?: boolean
}>): number {
  return spells.reduce((sum, spell) => sum + remainingMemorizedOf(spell), 0)
}

export function slotCapacityAtLevel(
  memorization: Record<string, number> | null | undefined,
  level: number,
): number {
  if (!memorization) return 0
  const raw = memorization[String(level)] ?? (memorization as Record<number, number>)[level]
  return Number(raw ?? 0) || 0
}

function parseExceptional(exceptional?: string | null): number | null {
  if (!exceptional) return null
  const v = exceptional.toUpperCase().trim()
  if (v === '00' || v === '100') return 100
  if (!/^\d{1,3}$/.test(v)) return null
  return parseInt(v, 10)
}
