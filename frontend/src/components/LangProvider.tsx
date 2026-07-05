"use client";

// Contexto de idioma de la web pública: el layout (servidor) lee la cookie
// `lang` y lo inyecta aquí; los componentes cliente traducen con useLang().
import { createContext, useContext } from "react";
import { dateLocale, t as translate, type Locale } from "@/lib/i18n";

const LangContext = createContext<Locale>("es");

export function LangProvider({ locale, children }: { locale: Locale; children: React.ReactNode }) {
  return <LangContext.Provider value={locale}>{children}</LangContext.Provider>;
}

export function useLang() {
  const locale = useContext(LangContext);

  return {
    locale,
    /** Locale BCP-47 para fechas/horas/precios. */
    intl: dateLocale(locale),
    t: (key: string, vars?: Record<string, string>) => translate(locale, key, vars),
  };
}
