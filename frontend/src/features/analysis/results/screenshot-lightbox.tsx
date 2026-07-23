"use client";

import { useState } from "react";
import { Dialog, DialogContent, DialogTitle, DialogTrigger } from "@/components/ui/dialog";

export function ScreenshotLightbox({ src, alt, trigger }: { src: string; alt: string; trigger: React.ReactNode }) {
  const [open, setOpen] = useState(false);

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger render={<button type="button" className="block w-full cursor-zoom-in text-left" aria-label={`${alt}を拡大表示`} />}>
        {trigger}
      </DialogTrigger>
      <DialogContent className="max-w-3xl sm:max-w-3xl">
        <DialogTitle className="sr-only">{alt}</DialogTitle>
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={src} alt={alt} className="max-h-[80vh] w-full rounded-md object-contain" />
      </DialogContent>
    </Dialog>
  );
}
