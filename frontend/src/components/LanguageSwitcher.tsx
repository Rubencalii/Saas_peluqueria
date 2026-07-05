"use client";

// Selector de idioma de la web pública: guarda la cookie `lang` y recarga el
// árbol de servidor (router.refresh) para que el SSR re-renderice traducido.
import { useRouter } from "next/navigation";
import { LOCALES, persistLocale, type Locale } from "@/lib/i18n";

export function LanguageSwitcher({ current }: { current: Locale }) {
  const router = useRouter();

  function change(locale: Locale) {
    persistLocale(locale);
    router.refresh();
  }

  return (
    <div className="flex items-center rounded-full border border-border bg-card p-0.5 text-[11px] font-semibold uppercase">
      {LOCALES.map((l) => (
        <button
          key={l}
          onClick={() => change(l)}
          aria-pressed={current === l}
          className={
            "rounded-full px-2 py-1 transition " +
            (current === l ? "bg-brand-soft text-foreground" : "text-muted hover:text-foreground")
          }
        >
          {l}
        </button>
      ))}
    </div>
  );
}
