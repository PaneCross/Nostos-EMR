// ─── PhoneInput Component ──────────────────────────────────────────────────────
// Formats phone numbers as (xxx) xxx-xxxx in real-time as the user types.
// Strips all non-digit characters internally, then re-applies formatting.
// The formatted string is what gets stored in form state and sent to the server.
// Validation rule on the backend: 'string', 'max:20' — the formatted value fits.
// ──────────────────────────────────────────────────────────────────────────────

import React from 'react'

// ─── Formatting utility ───────────────────────────────────────────────────────
// Takes any raw string, extracts up to 10 digits, returns formatted display value.
// Examples:
//   ''           → ''
//   '5'          → '(5'
//   '555'        → '(555'
//   '5551'       → '(555) 1'
//   '5551234'    → '(555) 123-4'
//   '5551234567' → '(555) 123-4567'
export function formatPhone(raw: string): string {
  const digits = raw.replace(/\D/g, '').slice(0, 10)
  if (digits.length === 0) return ''
  if (digits.length <= 3) return `(${digits}`
  if (digits.length <= 6) return `(${digits.slice(0, 3)}) ${digits.slice(3)}`
  return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6)}`
}

// ─── Component ────────────────────────────────────────────────────────────────
interface PhoneInputProps {
  value: string
  onChange: (formatted: string) => void
  placeholder?: string
  required?: boolean
  className?: string
  id?: string
  name?: string
}

export default function PhoneInput({
  value,
  onChange,
  placeholder = '(555) 123-4567',
  required,
  className,
  id,
  name,
}: PhoneInputProps) {
  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    onChange(formatPhone(e.target.value))
  }

  // On keydown, allow Backspace/Delete to work naturally —
  // the handleChange will strip and reformat the result.
  return (
    <input
      type="tel"
      id={id}
      name={name}
      value={value}
      onChange={handleChange}
      placeholder={placeholder}
      required={required}
      className={className}
      maxLength={14} // "(xxx) xxx-xxxx" = 14 chars
      inputMode="numeric"
    />
  )
}
