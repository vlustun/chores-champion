import { Controller } from '@hotwired/stimulus';

/*
 * - Detects the device timezone (IANA) and reports it to the server when it
 *   differs from the stored one (unless the user chose one manually).
 * - Re-renders <time data-timezone-target="time" datetime="ISO-UTC"> in the
 *   device timezone/locale (server-rendered text stays as no-JS fallback).
 */
export default class extends Controller {
    static values = { stored: String, url: String, token: String, manual: Boolean };
    static targets = ['time'];

    connect() {
        this.detected = this.detect();
        document.cookie = `tz=${encodeURIComponent(this.detected || '')}; path=/; max-age=31536000; SameSite=Lax`;
        this.formatTimes();
        this.report();
    }

    timeTargetConnected() {
        if (this.detected !== undefined) this.formatTimes();
    }

    detect() {
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || null;
        } catch (e) {
            return null; // very old browsers: server falls back to stored/default timezone
        }
    }

    async report() {
        if (!this.detected || !this.urlValue || this.manualValue) return;
        if (this.detected === this.storedValue) return;
        // do not hammer the server if a previous attempt for this value already ran this session
        const key = `tz-reported:${this.detected}`;
        if (sessionStorage.getItem(key)) return;
        sessionStorage.setItem(key, '1');
        try {
            const res = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ timezone: this.detected, _token: this.tokenValue }),
            });
            if (!res.ok) sessionStorage.removeItem(key);
        } catch (e) {
            sessionStorage.removeItem(key); // offline: retry on next page load
        }
    }

    formatTimes() {
        const tz = this.storedValue || this.detected || undefined;
        this.timeTargets.forEach((el) => {
            const d = new Date(el.getAttribute('datetime'));
            if (Number.isNaN(d.getTime())) return;
            try {
                el.textContent = new Intl.DateTimeFormat(undefined, {
                    weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
                    timeZone: tz,
                }).format(d);
            } catch (e) { /* invalid tz: keep server-rendered text */ }
        });
    }
}
