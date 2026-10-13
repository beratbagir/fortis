import logo from '../assets/logo.svg'

/** Fortis'in dört köşeli yıldızı. */
export function Logo({ size = 20 }: { size?: number }) {
  return <img src={logo} width={size} height={size} alt="" aria-hidden className="shrink-0" />
}

const AVATAR_BG: Record<string, string> = {
  crimson: 'bg-[var(--avatar-crimson)]',
  blue: 'bg-[var(--avatar-blue)]',
  green: 'bg-[var(--avatar-green)]',
  amber: 'bg-[var(--avatar-amber)]',
  violet: 'bg-[var(--avatar-violet)]',
  slate: 'bg-[var(--avatar-slate)]',
}

/** Baş harfli yuvarlak avatar; renk kullanıcıya kayıtlı tema token'ı. */
export function Avatar({ name, color, size = 28 }: { name: string; color: string | null; size?: number }) {
  return (
    <span
      aria-hidden
      className={`flex shrink-0 items-center justify-center rounded-full text-xs font-semibold text-on-brand ${AVATAR_BG[color ?? 'crimson'] ?? AVATAR_BG.crimson}`}
      style={{ width: size, height: size }}
    >
      {name.trim().charAt(0).toLocaleUpperCase('tr') || '?'}
    </span>
  )
}
