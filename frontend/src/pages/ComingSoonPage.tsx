import { t } from '../i18n'
import type { SoonScreen } from '../router'

/** Tasarımda olup henüz yapılmamış ekranlar için boş durum. */
export function ComingSoonPage({ screen }: { screen: SoonScreen }) {
  return (
    <main className="flex flex-1 items-center justify-center overflow-y-auto p-8">
      <div className="flex max-w-md flex-col items-center gap-3 text-center">
        <span className="rounded-full bg-raised px-2.5 py-1 text-[11px] font-medium text-muted">{t('nav.soon')}</span>
        <h1 className="m-0 font-serif text-[30px] font-normal tracking-tight">{t(`nav.${screen}`)}</h1>
        <p className="m-0 text-[15px] leading-relaxed text-ink-2">{t(`soon.${screen}`)}</p>
        <p className="m-0 text-sm text-muted">{t('soon.note')}</p>
      </div>
    </main>
  )
}
