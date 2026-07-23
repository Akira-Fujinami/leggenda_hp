"use client";

import { useEffect, useState } from "react";

/**
 * sectionIdsに対応するDOM要素をIntersectionObserverで監視し、現在画面上部に
 * 見えているセクションのidを返す(セクションナビのアクティブ表示用)。
 * rootMarginの上端はsticky header+セクションナビの高さ分だけ余裕を持たせ、
 * それらの下に来た瞬間にアクティブ切り替えが起きるようにする。
 */
export function useActiveSection(sectionIds: string[]): string | null {
  const [activeId, setActiveId] = useState<string | null>(sectionIds[0] ?? null);

  useEffect(() => {
    if (sectionIds.length === 0) return;

    const elements = sectionIds.map((id) => document.getElementById(id)).filter((el): el is HTMLElement => el !== null);
    if (elements.length === 0) return;

    const observer = new IntersectionObserver(
      (entries) => {
        const visible = entries
          .filter((entry) => entry.isIntersecting)
          .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
        if (visible.length > 0) {
          setActiveId(visible[0].target.id);
        }
      },
      { rootMargin: "-96px 0px -70% 0px", threshold: 0 },
    );

    elements.forEach((el) => observer.observe(el));
    return () => observer.disconnect();
  }, [sectionIds]);

  return activeId;
}
