import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, beforeEach } from 'vitest'
import { setLocale } from '../i18n'

// Mevcut testler Türkçe metinleri doğrular; İngilizce (varsayılan dil) testleri setLocale('en') ile.
beforeEach(() => setLocale('tr'))
afterEach(() => cleanup())

// jsdom <dialog> modal API'sini desteklemiyor.
if (typeof HTMLDialogElement !== 'undefined' && !HTMLDialogElement.prototype.showModal) {
  HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement) {
    this.setAttribute('open', '')
  }
  HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement) {
    this.removeAttribute('open')
  }
}
