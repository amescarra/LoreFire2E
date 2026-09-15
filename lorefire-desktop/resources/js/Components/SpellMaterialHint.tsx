import React from 'react'
import { CharacterSpell, InventoryItem } from '@/types'
import { inventoryHasMaterial } from '@/lib/adnd2e'

export function SpellMaterialHint({
  spell,
  items,
  compact = false,
}: {
  spell: CharacterSpell
  items?: InventoryItem[]
  compact?: boolean
}) {
  const reqs = spell.material_requirements ?? []
  if (reqs.length === 0) return null

  return (
    <span
      className={`flex items-center gap-1 min-w-0 ${compact ? '' : 'flex-wrap'}`}
      data-testid="spell-material-hint"
      title={reqs.map(req => {
        const ok = inventoryHasMaterial(items, req.name, req.quantity)
        const kind = req.focus ? 'focus' : 'spend'
        return `${req.name} (${kind}${ok ? '' : ', missing'})`
      }).join(' · ')}
    >
      {reqs.map(req => {
        const ok = inventoryHasMaterial(items, req.name, req.quantity)
        const label = req.quantity > 1 ? `${req.quantity}× ${req.name}` : req.name
        return (
          <span
            key={`${req.focus ? 'f' : 'm'}-${req.name}`}
            className="text-[8px] uppercase tracking-widest truncate max-w-[9rem]"
            style={{ color: ok ? 'var(--color-text-dim)' : 'var(--color-danger)' }}
          >
            {req.focus ? 'F' : 'M'} {label}{ok ? '' : ' ✕'}
          </span>
        )
      })}
    </span>
  )
}
