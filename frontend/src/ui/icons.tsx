// Tasarımdaki çizgi ikonlar (stroke). Dekoratif: aria-hidden.
import type { SVGProps } from 'react'

const base = (props: SVGProps<SVGSVGElement>) => ({
  width: 18,
  height: 18,
  viewBox: '0 0 24 24',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 2,
  strokeLinecap: 'round' as const,
  strokeLinejoin: 'round' as const,
  'aria-hidden': true,
  ...props,
})

export const ChatIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base(p)}><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z" /></svg>
)
export const FlowsIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base(p)}><rect x="3" y="3" width="7" height="7" rx="2" /><rect x="14" y="14" width="7" height="7" rx="2" /><path d="M6.5 10v4a3 3 0 0 0 3 3H14" /></svg>
)
export const RunsIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base(p)}><path d="M5 3l14 9-14 9V3z" /></svg>
)
export const UsageIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base(p)}><path d="M4 20V10" /><path d="M10 20V4" /><path d="M16 20v-7" /><path d="M22 20H2" /></svg>
)
export const CloseIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 16, height: 16, strokeWidth: 2.2, ...p })}><path d="M6 6l12 12" /><path d="M18 6L6 18" /></svg>
)
export const SearchIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 16, height: 16, ...p })}><circle cx="11" cy="11" r="7" /><path d="M20 20l-4-4" /></svg>
)
export const ChevronIcon = ({ open, ...p }: SVGProps<SVGSVGElement> & { open?: boolean }) => (
  <svg {...base({ width: 16, height: 16, strokeWidth: 2.2, ...p })}>{open ? <path d="M6 15l6-6 6 6" /> : <path d="M6 9l6 6 6-6" />}</svg>
)
export const SendIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 16, height: 16, strokeWidth: 2.4, ...p })}><path d="M12 19V5" /><path d="M5 12l7-7 7 7" /></svg>
)
export const PlayIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg width={14} height={14} viewBox="0 0 24 24" fill="currentColor" aria-hidden {...p}><path d="M6 4l14 8-14 8V4z" /></svg>
)
export const WarnIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 14, height: 14, strokeWidth: 2.4, ...p })}><path d="M12 9v4" /><path d="M12 17h.01" /><path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" /></svg>
)
export const MicIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 16, height: 16, strokeWidth: 2.2, ...p })}><rect x="9" y="3" width="6" height="11" rx="3" /><path d="M5 11a7 7 0 0 0 14 0" /><path d="M12 18v3" /></svg>
)
export const AgentIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base(p)}><rect x="4" y="8" width="16" height="12" rx="4" /><path d="M12 8V4" /><circle cx="12" cy="3.5" r="1" /><path d="M9 14h.01" /><path d="M15 14h.01" /></svg>
)

// Kenar çubuğu (Fortis Aero tasarımı)
export const PlusIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 14, height: 14, strokeWidth: 2.6, ...p })}><path d="M12 5v14" /><path d="M5 12h14" /></svg>
)
export const InboxIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 16, height: 16, ...p })}><path d="M22 12h-6l-2 3h-4l-2-3H2" /><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1z" /></svg>
)
export const CheckSquareIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 16, height: 16, ...p })}><rect x="3" y="3" width="18" height="18" rx="3" /><path d="m8 12 3 3 5-6" /></svg>
)
export const GridIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 16, height: 16, ...p })}><rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" /></svg>
)
export const HammerIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 16, height: 16, ...p })}><path d="m15 12-8.5 8.5a2.1 2.1 0 0 1-3-3L12 9" /><path d="M17.6 15 22 10.6" /><path d="m20.9 11.7-1.3-1.3a2 2 0 0 1 0-2.8l.6-.6-2.3-2.3a5 5 0 0 0-7-.1l-.8.8 1.7.6a2 2 0 0 1 1.2 1.9V9l2 2h1.5a2 2 0 0 1 1.4.6l1.2 1.2" /></svg>
)
export const PlugIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 16, height: 16, ...p })}><path d="M12 22v-5" /><path d="M9 8V2" /><path d="M15 8V2" /><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8z" /></svg>
)
export const LayersIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 16, height: 16, ...p })}><path d="m12 2 10 5-10 5L2 7z" /><path d="m2 17 10 5 10-5" /><path d="m2 12 10 5 10-5" /></svg>
)
export const UsersIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 16, height: 16, ...p })}><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.9" /><path d="M16 3.1a4 4 0 0 1 0 7.8" /></svg>
)
export const ClockIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 16, height: 16, ...p })}><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
)
export const KnowledgeIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 16, height: 16, ...p })}><path d="M3 4h18l-7 9v6l-4 2v-8z" /></svg>
)
export const StarIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 16, height: 16, ...p })}><path d="m12 3 2.8 5.6 6.2.9-4.5 4.4 1 6.1L12 17.1 6.5 20l1-6.1L3 9.5l6.2-.9z" /></svg>
)
export const SidebarIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 16, height: 16, ...p })}><rect x="3" y="4" width="18" height="16" rx="2" /><path d="M9 4v16" /></svg>
)
export const MenuIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base(p)}><path d="M4 6h16" /><path d="M4 12h16" /><path d="M4 18h16" /></svg>
)
export const SunIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 14, height: 14, ...p })}><circle cx="12" cy="12" r="4" /><path d="M12 2v2" /><path d="M12 20v2" /><path d="m4.9 4.9 1.4 1.4" /><path d="m17.7 17.7 1.4 1.4" /><path d="M2 12h2" /><path d="M20 12h2" /><path d="m4.9 19.1 1.4-1.4" /><path d="m17.7 6.3 1.4-1.4" /></svg>
)
export const MoonIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 14, height: 14, ...p })}><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" /></svg>
)
export const LogoutIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 16, height: 16, ...p })}><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="m16 17 5-5-5-5" /><path d="M21 12H9" /></svg>
)
export const UserIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 16, height: 16, ...p })}><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></svg>
)
export const SettingsIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 16, height: 16, ...p })}><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" /></svg>
)

// Konuşma ekranları
export const SparkIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 14, height: 14, ...p })}><path d="M12 3c.6 4.3 4.7 8.4 9 9-4.3.6-8.4 4.7-9 9-.6-4.3-4.7-8.4-9-9 4.3-.6 8.4-4.7 9-9Z" /></svg>
)
export const CheckIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 14, height: 14, strokeWidth: 2.4, ...p })}><path d="m5 12 5 5L20 7" /></svg>
)
export const LockIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 12, height: 12, ...p })}><rect x="4" y="11" width="16" height="10" rx="2" /><path d="M8 11V7a4 4 0 0 1 8 0v4" /></svg>
)
export const CopyIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 14, height: 14, ...p })}><rect x="9" y="9" width="12" height="12" rx="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></svg>
)
export const DownloadIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 14, height: 14, ...p })}><path d="M12 3v12" /><path d="m7 10 5 5 5-5" /><path d="M5 21h14" /></svg>
)
export const ExpandIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 14, height: 14, ...p })}><path d="M15 3h6v6" /><path d="M9 21H3v-6" /><path d="m21 3-7 7" /><path d="m3 21 7-7" /></svg>
)
export const ThumbUpIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 14, height: 14, ...p })}><path d="M7 10v11" /><path d="M15 5.9 14 10h5.8a2 2 0 0 1 2 2.3l-1.4 7A2 2 0 0 1 18.4 21H7V10l4-8a3 3 0 0 1 4 3.9Z" /></svg>
)
export const ThumbDownIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 14, height: 14, ...p })}><path d="M17 14V3" /><path d="m9 18.1 1-4.1H4.2a2 2 0 0 1-2-2.3l1.4-7A2 2 0 0 1 5.6 3H17v11l-4 8a3 3 0 0 1-4-3.9Z" /></svg>
)
export const RetryIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 14, height: 14, ...p })}><path d="M3 12a9 9 0 0 1 15.5-6.2L21 8" /><path d="M21 3v5h-5" /><path d="M21 12a9 9 0 0 1-15.5 6.2L3 16" /><path d="M3 21v-5h5" /></svg>
)
export const SlidersIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 14, height: 14, ...p })}><path d="M4 7h10" /><path d="M18 7h2" /><circle cx="16" cy="7" r="2" /><path d="M4 17h2" /><path d="M10 17h10" /><circle cx="8" cy="17" r="2" /></svg>
)
export const ChartIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 14, height: 14, ...p })}><path d="M3 3v18h18" /><path d="M7 15l4-4 3 3 5-6" /></svg>
)
export const WrenchIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 14, height: 14, ...p })}><path d="M14.7 6.3a4 4 0 0 0 5 5L22 13.6 13.6 22l-2.3-2.3a4 4 0 0 0-5-5L4 12.4 12.4 4l2.3 2.3Z" /></svg>
)
export const DocumentIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 14, height: 14, ...p })}><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9Z" /><path d="M14 3v6h6" /><path d="M8 13h8" /><path d="M8 17h5" /></svg>
)
export const ScreenIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 14, height: 14, ...p })}><rect x="2" y="4" width="20" height="13" rx="2" /><path d="M8 21h8" /><path d="M12 17v4" /></svg>
)
export const ArrowUpIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base({ width: 14, height: 14, strokeWidth: 2.6, ...p })}><path d="M12 19V5" /><path d="m5 12 7-7 7 7" /></svg>
)
