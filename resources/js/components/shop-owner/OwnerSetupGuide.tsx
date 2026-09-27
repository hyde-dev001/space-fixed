import { useCallback, useEffect, useRef, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import axios from "axios";
import { setupTours } from "./setupTours";

type Task = { key: string; group: string; label: string; required: boolean; url: string; status: "complete" | "incomplete" | "needs_attention" };
type Tutorial = { status: "not_started" | "in_progress" | "completed" | "skipped"; step: number; route?: string | null; target?: string | null };
type Guide = { tasks: Task[]; completed: number; total: number; tutorials: Record<string, Tutorial>; welcome_seen: boolean };
type Active = { key: string; step: number };
type TargetRect = { top: number; left: number; right: number; bottom: number; width: number; height: number };

const API = "/shop-owner/setup-guide";
const groupNames: Record<string, string> = { profile: "Set up your shop profile", business: "Set up your business settings", team: "Set up your team", help: "Explore help" };
const storageKey = (ownerId: number) => `solespace:owner-tour:${ownerId}`;

export default function OwnerSetupGuide() {
  const page = usePage();
  const props = page.props as { auth?: { shop_owner?: { id?: number } } };
  const owner = props.auth?.shop_owner;
  const ownerId = owner?.id;
  const [guide, setGuide] = useState<Guide | null>(null);
  const [drawer, setDrawer] = useState(false);
  const [welcome, setWelcome] = useState(false);
  const [intro, setIntro] = useState<string | null>(null);
  const [active, setActive] = useState<Active | null>(null);
  const [rect, setRect] = useState<TargetRect | null>(null);
  const [missing, setMissing] = useState(false);
  const [error, setError] = useState("");
  const previousFocus = useRef<HTMLElement | null>(null);

  const refresh = useCallback(async () => {
    if (!ownerId) return;
    try {
      const response = await axios.get<Guide>(API);
      setGuide(response.data);
      window.dispatchEvent(new CustomEvent("solespace:setup-progress", { detail: { completed: response.data.completed, total: response.data.total } }));
      setError("");
    } catch {
      setError("The setup guide could not load. Please try again.");
    }
  }, [ownerId]);

  useEffect(() => {
    if (!ownerId) return;
    void refresh();
    try {
      const stored = window.sessionStorage.getItem(storageKey(ownerId));
      if (stored) {
        const parsed = JSON.parse(stored) as Active;
        if (setupTours[parsed.key]?.[parsed.step]) setActive(parsed);
      }
    } catch { /* Storage can be unavailable in private browsing. */ }
  }, [ownerId, refresh]);

  useEffect(() => {
    if (guide && guide.completed < guide.total && !guide.welcome_seen && !welcome && !intro && !active) {
      setWelcome(true);
    }
  }, [guide, welcome, intro, active]);

  useEffect(() => {
    const open = () => { previousFocus.current = document.activeElement as HTMLElement; setDrawer(true); setIntro(null); void refresh(); };
    window.addEventListener("solespace:open-setup-guide", open);
    return () => window.removeEventListener("solespace:open-setup-guide", open);
  }, [refresh]);

  useEffect(() => {
    if (!drawer && !active) return;
    const onFocus = () => void refresh();
    window.addEventListener("focus", onFocus);
    const interval = window.setInterval(() => void refresh(), 5000);
    return () => { window.removeEventListener("focus", onFocus); window.clearInterval(interval); };
  }, [drawer, active, refresh]);

  const persist = useCallback((key: string, status: Tutorial["status"], step: number) => {
    const definition = setupTours[key]?.[step];
    void axios.post(`${API}/progress`, { task_key: key, status, step, route: window.location.pathname, target: definition?.target ?? null }).then(() => void refresh()).catch(() => setError("Tutorial progress could not be saved. Please try again."));
  }, [refresh]);

  const setPosition = useCallback((next: Active | null) => {
    setActive(next);
    if (!ownerId) return;
    try {
      if (next) window.sessionStorage.setItem(storageKey(ownerId), JSON.stringify(next));
      else window.sessionStorage.removeItem(storageKey(ownerId));
    } catch { /* Server still stores the last saved step. */ }
  }, [ownerId]);

  const advance = useCallback(() => {
    if (!active) return;
    const nextStep = active.step + 1;
    if (nextStep >= setupTours[active.key].length) {
      persist(active.key, "completed", active.step);
      setPosition(null);
      setDrawer(true);
      return;
    }
    persist(active.key, "in_progress", nextStep);
    setPosition({ key: active.key, step: nextStep });
  }, [active, persist, setPosition]);

  useEffect(() => {
    const onEscape = (event: KeyboardEvent) => {
      if (event.key !== "Escape") return;
      if (active) { setPosition(null); setRect(null); previousFocus.current?.focus(); }
      else if (intro) setIntro(null);
      else if (drawer) { setDrawer(false); previousFocus.current?.focus(); }
    };
    window.addEventListener("keydown", onEscape);
    return () => window.removeEventListener("keydown", onEscape);
  }, [active, intro, drawer, setPosition]);

  useEffect(() => {
    if (guide && active && !guide.tasks.some((task) => task.key === active.key)) {
      setPosition(null);
      setDrawer(true);
      setError("This tutorial is no longer available for your shop.");
    }
  }, [guide, active, setPosition]);

  const current = active ? setupTours[active.key]?.[active.step] : null;
  useEffect(() => {
    if (!current) { setRect(null); return; }
    let timer = 0;
    const visible = (element: HTMLElement) => {
      const bounds = element.getBoundingClientRect();
      return bounds.width > 0 && bounds.height > 0 && bounds.bottom > 0 && bounds.top < window.innerHeight && bounds.right > 0 && bounds.left < window.innerWidth;
    };
    const locate = () => {
      const candidates = [...document.querySelectorAll<HTMLElement>(`[data-tour="${current.target}"]`)];
      let target = candidates.find(visible) ?? candidates[0];
      if (target && !visible(target)) target.scrollIntoView?.({ block: "center", behavior: "smooth" });
      if (!target || !visible(target)) {
        const alternate = current.target === "owner-account-menu" ? "owner-mobile-menu" : current.target === "sidebar-articles" ? "owner-sidebar-toggle" : null;
        target = alternate ? document.querySelector<HTMLElement>(`[data-tour="${alternate}"]`) : null;
      }
      if (target && visible(target)) {
        const bounds = target.getBoundingClientRect();
        setRect({ top: bounds.top, left: bounds.left, right: bounds.right, bottom: bounds.bottom, width: bounds.width, height: bounds.height });
        setMissing(false);
      } else { setRect(null); setMissing(true); }
    };
    locate();
    timer = window.setTimeout(locate, 250);
    const onClick = (event: MouseEvent) => {
      const element = event.target instanceof Element ? event.target : null;
      if (element?.closest('[role="status"]')) return;
      const clicked = element?.closest("[data-tour]");
      if (clicked?.getAttribute("data-tour") === current.target && current.action === "click") {
        window.setTimeout(advance, 0);
        return;
      }
      if (clicked?.matches('[data-tour="owner-mobile-menu"], [data-tour="owner-sidebar-toggle"]')) {
        window.setTimeout(locate, 50);
        return;
      }
      if (current.action === "click") {
        event.preventDefault();
        event.stopPropagation();
      }
    };
    document.addEventListener("click", onClick, true);
    window.addEventListener("resize", locate);
    window.addEventListener("scroll", locate, true);
    return () => { window.clearTimeout(timer); document.removeEventListener("click", onClick, true); window.removeEventListener("resize", locate); window.removeEventListener("scroll", locate, true); };
  }, [current, advance, page.url]);

  if (!ownerId) return null;
  const dismissWelcome = async (showGuide: boolean) => {
    try { await axios.post(`${API}/welcome`); setGuide((value) => value ? { ...value, welcome_seen: true } : value); }
    catch { setError("Your welcome preference could not be saved."); return; }
    setWelcome(false);
    setDrawer(showGuide);
  };
  const begin = (key: string, resume = false) => {
    const step = resume ? Math.min(guide?.tutorials[key]?.step ?? 0, setupTours[key].length - 1) : 0;
    setDrawer(false); setIntro(null); setError("");
    setPosition({ key, step });
    persist(key, "in_progress", step);
  };
  const exit = () => { setPosition(null); setRect(null); previousFocus.current?.focus(); };
  const grouped = guide ? Object.entries(groupNames).map(([key, name]) => ({ key, name, tasks: guide.tasks.filter((task) => task.group === key) })).filter((group) => group.tasks.length) : [];
  const tooltipTop = rect ? rect.bottom + 220 < window.innerHeight ? rect.bottom + 12 : Math.max(12, rect.top - 220) : 12;
  const tooltipLeft = rect ? Math.max(12, Math.min(window.innerWidth - 340, rect.left)) : 12;

  return (
    <>
      {welcome && !guide?.welcome_seen && (
        <div className="fixed inset-0 z-[100000] flex items-center justify-center bg-black/60 p-4 erp-modal-backdrop" role="presentation">
          <section role="dialog" aria-modal="true" aria-labelledby="setup-welcome-title" className="w-full max-w-md rounded-2xl bg-white p-6 text-gray-900 shadow-2xl dark:bg-gray-900 dark:text-white">
            <h2 id="setup-welcome-title" className="text-2xl font-bold">Welcome to SoleSpace</h2>
            <p className="mt-3 text-sm text-gray-600 dark:text-gray-300">Let’s get your shop ready. The setup guide points to the real settings in your workspace.</p>
            <div className="mt-6 flex flex-wrap gap-2"><button type="button" onClick={() => void dismissWelcome(true)} className="rounded-lg bg-gray-950 px-4 py-2 text-sm font-semibold text-white">Start setup</button><button type="button" onClick={() => void dismissWelcome(false)} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold">I’ll do this later</button></div>
          </section>
        </div>
      )}
      {drawer && (
        <div className="fixed inset-0 z-[100000] bg-black/40 erp-modal-backdrop" onMouseDown={(event) => { if (event.target === event.currentTarget) setDrawer(false); }}>
          <aside role="dialog" aria-modal="true" aria-label="Setup guide" className="ml-auto flex h-full w-full max-w-lg flex-col bg-white text-gray-900 shadow-2xl dark:bg-gray-900 dark:text-gray-100">
            <div className="flex items-start justify-between border-b border-gray-200 p-5 dark:border-gray-700"><div><h2 className="text-xl font-bold">Setup guide</h2><p className="mt-1 text-sm text-gray-600 dark:text-gray-300">Complete the settings that apply to your shop.</p></div><button type="button" onClick={() => { setDrawer(false); previousFocus.current?.focus(); }} aria-label="Close setup guide" autoFocus className="rounded-lg px-2 py-1 text-2xl focus-visible:ring-2 focus-visible:ring-black">×</button></div>
            <div className="overflow-y-auto p-5">
              {error && <p role="alert" className="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{error} <button type="button" onClick={() => void refresh()} className="underline">Retry</button></p>}
              {guide && <><p className="text-sm font-semibold">{guide.completed} of {guide.total} required tasks complete</p><div className="mt-2 h-2 rounded-full bg-gray-200" role="progressbar" aria-valuenow={guide.completed} aria-valuemin={0} aria-valuemax={guide.total}><div className="h-full rounded-full bg-gray-900 dark:bg-white" style={{ width: `${guide.total ? 100 * guide.completed / guide.total : 100}%` }} /></div>{guide.completed === guide.total && <p className="mt-3 text-sm font-medium text-green-700">Setup complete. You can still manage settings or replay a tutorial.</p>}</>}
              {grouped.map((group) => <section key={group.key} className="mt-6"><h3 className="mb-3 text-xs font-bold uppercase tracking-wide text-gray-500">{group.name}</h3><div className="space-y-2">{group.tasks.map((task) => { const tutorial = guide?.tutorials[task.key]; return <article key={task.key} className="rounded-xl border border-gray-200 p-4 dark:border-gray-700"><div className="flex items-start gap-3"><span aria-hidden="true" className={task.status === "complete" ? "text-green-700" : "text-gray-500"}>{task.status === "complete" ? "✓" : "○"}</span><div className="min-w-0 flex-1"><h4 className="text-sm font-semibold">{task.label}</h4><p className="mt-1 text-xs text-gray-500">{task.status === "complete" ? "Configured" : task.status === "needs_attention" ? "Needs attention" : task.required ? "Required setting" : "Optional or educational"}</p><div className="mt-3 flex flex-wrap gap-3 text-xs font-semibold"><button type="button" onClick={() => setIntro(task.key)} className="rounded-md bg-gray-950 px-3 py-2 text-white dark:bg-white dark:text-gray-900">{tutorial?.status === "in_progress" ? `Continue step ${tutorial.step + 1}` : task.status === "complete" || tutorial?.status === "completed" ? "Replay tutorial" : "Show me how"}</button><button type="button" onClick={() => { setDrawer(false); router.visit(task.url); }} className="rounded-md border border-gray-300 px-3 py-2">{task.status === "complete" ? "Manage" : "Open setting"}</button></div></div></div></article>; })}</div></section>)}
            </div>
          </aside>
        </div>
      )}
      {intro && <div className="fixed inset-0 z-[100001] flex items-center justify-center bg-black/60 p-4 erp-modal-backdrop"><section role="dialog" aria-modal="true" aria-label="Tutorial introduction" className="w-full max-w-sm rounded-2xl bg-white p-6 text-gray-900"><p className="text-xs font-bold uppercase text-gray-500">Step 1 of {setupTours[intro].length}</p><h2 className="mt-2 text-xl font-bold">{guide?.tasks.find((task) => task.key === intro)?.label}</h2><p className="mt-2 text-sm text-gray-600">We’ll highlight the real controls. Click each highlighted item yourself. You can exit and resume later.</p><div className="mt-5 flex gap-2"><button type="button" onClick={() => begin(intro, guide?.tutorials[intro]?.status === "in_progress")} autoFocus className="rounded-lg bg-gray-950 px-4 py-2 text-sm font-semibold text-white">Let’s go</button><button type="button" onClick={() => { persist(intro, "skipped", 0); setIntro(null); }} className="rounded-lg border border-gray-300 px-4 py-2 text-sm">Do this later</button></div></section></div>}
      {active && current && <>
        {rect && <><div className="pointer-events-none fixed left-0 top-0 z-[100000] bg-black/65" style={{ width: "100%", height: Math.max(0, rect.top - 5) }} /><div className="pointer-events-none fixed left-0 z-[100000] bg-black/65" style={{ top: rect.top - 5, width: Math.max(0, rect.left - 5), height: rect.height + 10 }} /><div className="pointer-events-none fixed right-0 z-[100000] bg-black/65" style={{ top: rect.top - 5, width: Math.max(0, window.innerWidth - rect.right - 5), height: rect.height + 10 }} /><div className="pointer-events-none fixed bottom-0 left-0 z-[100000] bg-black/65" style={{ top: rect.bottom + 5, width: "100%" }} /><div className="pointer-events-none fixed z-[100001] rounded-lg ring-4 ring-white shadow-[0_0_0_2px_black]" style={{ top: rect.top - 5, left: rect.left - 5, width: rect.width + 10, height: rect.height + 10 }} /></>}
        <div role="status" className="pointer-events-none fixed z-[100002] w-[min(320px,calc(100vw-24px))] rounded-xl border border-gray-200 bg-white p-4 text-gray-900 shadow-2xl" style={rect ? { top: tooltipTop, left: tooltipLeft } : { bottom: 16, right: 16 }}><p className="text-xs font-bold uppercase text-gray-500">{active.step + 1} of {setupTours[active.key].length}</p><h2 className="mt-1 text-base font-bold">{current.title}</h2><p className="mt-2 text-sm text-gray-600">{current.description}</p>{missing && <p className="mt-2 text-xs text-amber-700">This control is unavailable on this screen. Open the relevant page to continue.</p>}<div className="mt-4 flex flex-wrap gap-2">{current.action === "view" && !missing && <button type="button" onClick={advance} className="pointer-events-auto rounded-md bg-gray-950 px-3 py-2 text-xs font-semibold text-white">I found it</button>}{missing && <button type="button" onClick={() => router.visit(current.route ?? "/shop-owner/home") } className="pointer-events-auto rounded-md bg-gray-950 px-3 py-2 text-xs font-semibold text-white">{current.route ? "Open page" : "Open owner home"}</button>}<button type="button" onClick={exit} className="pointer-events-auto rounded-md border border-gray-300 px-3 py-2 text-xs font-semibold">Exit tutorial</button></div></div>
      </>}
    </>
  );
}
