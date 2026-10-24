import { useEffect, useState } from "react";
import { ClipboardList } from "lucide-react";

export default function SetupGuideNavButton({ showLabel }: { showLabel: boolean }) {
  const [progress, setProgress] = useState<{ completed: number; total: number } | null>(null);

  useEffect(() => {
    const update = (event: Event) => setProgress((event as CustomEvent<{ completed: number; total: number }>).detail);
    window.addEventListener("solespace:setup-progress", update);
    return () => window.removeEventListener("solespace:setup-progress", update);
  }, []);

  return <button type="button" onClick={() => window.dispatchEvent(new Event("solespace:open-setup-guide"))} className="flex min-h-10 w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:text-gray-200 dark:hover:bg-gray-800" title={showLabel ? undefined : "Setup Guide"}><ClipboardList aria-hidden="true" className="h-4 w-4 shrink-0" /><span className={showLabel ? "min-w-0 flex-1" : "sr-only"}>Setup Guide</span>{showLabel && progress && <span className="text-xs text-gray-500">{progress.completed}/{progress.total}</span>}</button>;
}
