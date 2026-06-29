"use client";

import { useEffect, useState } from "react";

/** Conmuta tema claro/oscuro y guarda la preferencia (localStorage `theme`). */
export function ThemeToggle() {
  const [dark, setDark] = useState<boolean | null>(null);

  useEffect(() => {
    const stored = localStorage.getItem("theme");
    if (stored === "dark" || stored === "light") setDark(stored === "dark");
    else setDark(window.matchMedia("(prefers-color-scheme: dark)").matches);
  }, []);

  function toggle() {
    const next = !dark;
    document.documentElement.dataset.theme = next ? "dark" : "light";
    localStorage.setItem("theme", next ? "dark" : "light");
    setDark(next);
  }

  return (
    <button
      onClick={toggle}
      aria-label="Cambiar tema claro/oscuro"
      title="Tema claro/oscuro"
      className="rounded-full px-2.5 py-1.5 text-sm text-muted transition hover:bg-brand-soft hover:text-foreground"
    >
      {dark === null ? "🌗" : dark ? "☀️" : "🌙"}
    </button>
  );
}
