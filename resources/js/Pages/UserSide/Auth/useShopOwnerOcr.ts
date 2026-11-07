import { useCallback, useEffect, useRef, useState } from 'react';
import {
  assessShopOwnerDocument,
  classifyShopOwnerDocumentRelevance,
  type ShopOwnerOcrContext,
  type ShopOwnerOcrRelevance,
} from './shopOwnerDocumentRecognition';
import {
  scoreOwnerOcrEntry,
  serializeOwnerOcrEnvelope,
  type ShopOwnerOcrDocumentType,
  type ShopOwnerOcrEntry,
  type ShopOwnerOcrReasonCode,
  type ShopOwnerOcrSlot,
} from './shopOwnerScreening';
import type { ShopOwnerOcrPass, ShopOwnerOcrReaderOptions } from './registrationOcr';

const CLEANUP_CONFIRMATION_MS = 1_000;
const documentTypes: ShopOwnerOcrDocumentType[] = [
  'dti_registration', 'sec_registration', 'mayors_permit', 'bir_certificate', 'valid_id',
];
const reasonCodes: ShopOwnerOcrReasonCode[] = [
  'ocr_unavailable', 'ocr_failed', 'ocr_timeout', 'scan_unfinished',
];

export type ShopOwnerOcrAssets = Pick<ShopOwnerOcrReaderOptions, 'workerPath' | 'corePath' | 'langPath'>;
export type ShopOwnerOcrStatus = 'queued' | 'loading' | 'recognizing' | 'complete' | 'review' | 'unable' | 'replacement_required';
export type ShopOwnerOcrSlotState = {
  status: ShopOwnerOcrStatus;
  score?: 0 | 50 | 100 | null;
  entry?: ShopOwnerOcrEntry;
  reasonCode?: ShopOwnerOcrReasonCode;
  relevance?: ShopOwnerOcrRelevance;
};

export type UseShopOwnerOcrOptions = {
  assets: ShopOwnerOcrAssets | null;
  context: ShopOwnerOcrContext;
  selectedDocumentTypes: Partial<Record<ShopOwnerOcrSlot, ShopOwnerOcrDocumentType>>;
};

type Selection = { file: File; generation: number };
type Evidence = { generation: number; passes?: ShopOwnerOcrPass[]; entry?: ShopOwnerOcrEntry };
type ActiveScan = { slot: ShopOwnerOcrSlot; generation: number; controller: AbortController };
type ReaderError = Error & { reasonCode?: unknown; cleanup?: Promise<void> };

function unableEntry(reason: ShopOwnerOcrReasonCode, referenceDay: string | null): ShopOwnerOcrEntry {
  return {
    assessment_state: 'unable_to_assess',
    detected_family: null,
    ...(referenceDay ? { reference_day: referenceDay } : {}),
    checks: {},
    reason_codes: [reason],
  };
}

function validDocumentType(value: unknown): value is ShopOwnerOcrDocumentType {
  return typeof value === 'string' && documentTypes.includes(value as ShopOwnerOcrDocumentType);
}

function validTypeForSlot(slot: ShopOwnerOcrSlot, value: unknown): value is ShopOwnerOcrDocumentType {
  return validDocumentType(value) && (slot === 'business_registration'
    ? value === 'dti_registration' || value === 'sec_registration'
    : value === slot);
}

function reasonFrom(error: unknown): ShopOwnerOcrReasonCode {
  const code = (error as ReaderError | null)?.reasonCode;
  return typeof code === 'string' && reasonCodes.includes(code as ShopOwnerOcrReasonCode)
    ? code as ShopOwnerOcrReasonCode
    : 'ocr_unavailable';
}

async function cleanupConfirmed(cleanup: Promise<void>): Promise<boolean> {
  let timer: ReturnType<typeof setTimeout> | undefined;
  return new Promise((resolve) => {
    timer = setTimeout(() => resolve(false), CLEANUP_CONFIRMATION_MS);
    cleanup.then(() => resolve(true), () => resolve(false));
  }).finally(() => {
    if (timer !== undefined) clearTimeout(timer);
  });
}

function stateForEntry(
  entry: ShopOwnerOcrEntry,
  slot: ShopOwnerOcrSlot,
  type: ShopOwnerOcrDocumentType,
  relevance: ShopOwnerOcrRelevance,
): ShopOwnerOcrSlotState {
  const { score } = scoreOwnerOcrEntry(slot, type, entry);
  if (relevance === 'clearly_irrelevant' || relevance === 'incompatible_document_type') {
    return { status: 'replacement_required', score, entry, relevance };
  }
  if (entry.assessment_state === 'unable_to_assess') {
    return { status: 'unable', score: null, entry, reasonCode: entry.reason_codes?.[0], relevance };
  }
  return { status: score === 100 ? 'complete' : 'review', score, entry, relevance };
}

export function useShopOwnerOcr(options: UseShopOwnerOcrOptions) {
  const optionsRef = useRef(options);
  optionsRef.current = options;
  const selections = useRef(new Map<ShopOwnerOcrSlot, Selection>());
  const evidence = useRef(new Map<ShopOwnerOcrSlot, Evidence>());
  const generations = useRef(new Map<ShopOwnerOcrSlot, number>());
  const queue = useRef<ShopOwnerOcrSlot[]>([]);
  const queued = useRef(new Set<ShopOwnerOcrSlot>());
  const active = useRef<ActiveScan | null>(null);
  const blocked = useRef(false);
  const finished = useRef(false);
  const mounted = useRef(true);
  const slotStatuses = useRef(new Map<ShopOwnerOcrSlot, ShopOwnerOcrStatus>());
  const [storedStates, setStoredStates] = useState<Partial<Record<ShopOwnerOcrSlot, ShopOwnerOcrSlotState>>>({});
  const setSlotState = useCallback((slot: ShopOwnerOcrSlot, next: ShopOwnerOcrSlotState | null) => {
    if (!mounted.current || finished.current) return;
    if (next) slotStatuses.current.set(slot, next.status);
    else slotStatuses.current.delete(slot);
    setStoredStates((current) => {
      const updated = { ...current };
      if (next) updated[slot] = next;
      else delete updated[slot];
      return updated;
    });
  }, []);

  const currentEntry = useCallback((slot: ShopOwnerOcrSlot, item: Evidence): ShopOwnerOcrEntry | null => {
    const selection = selections.current.get(slot);
    const type = optionsRef.current.selectedDocumentTypes[slot];
    if (!selection || selection.generation !== item.generation || !validTypeForSlot(slot, type)) return null;
    if (item.passes) return assessShopOwnerDocument(slot, type, item.passes, optionsRef.current.context);
    return item.entry ?? null;
  }, []);

  const makeUnable = useCallback((slot: ShopOwnerOcrSlot, generation: number, reason: ShopOwnerOcrReasonCode) => {
    if (selections.current.get(slot)?.generation !== generation) return;
    const type = optionsRef.current.selectedDocumentTypes[slot];
    if (!validTypeForSlot(slot, type)) {
      setSlotState(slot, { status: 'unable', reasonCode: reason });
      return;
    }
    const entry = unableEntry(reason, optionsRef.current.context.referenceDay);
    evidence.current.set(slot, { generation, entry });
    setSlotState(slot, { status: 'unable', score: null, entry, reasonCode: reason, relevance: 'technical_failure' });
  }, [setSlotState]);

  const drain = useCallback(async () => {
    if (active.current || blocked.current || finished.current || !mounted.current) return;
    while (queue.current.length > 0 && !active.current && !blocked.current && !finished.current) {
      const slot = queue.current.shift() as ShopOwnerOcrSlot;
      queued.current.delete(slot);
      const selection = selections.current.get(slot);
      if (!selection) continue;
      const { generation, file } = selection;
      const type = optionsRef.current.selectedDocumentTypes[slot];
      if (!validTypeForSlot(slot, type)) {
        setSlotState(slot, { status: 'unable', reasonCode: 'ocr_unavailable' });
        continue;
      }
      const assets = optionsRef.current.assets;
      if (!assets || blocked.current) {
        makeUnable(slot, generation, 'ocr_unavailable');
        continue;
      }

      const controller = new AbortController();
      active.current = { slot, generation, controller };
      setSlotState(slot, { status: 'loading' });
      try {
        const reader = await import('./registrationOcr');
        const result = await reader.readShopOwnerDocument(file, {
          ...assets,
          layout: slot === 'valid_id' ? 'id' : 'certificate',
          signal: controller.signal,
          onStage: (stage) => {
            if (selections.current.get(slot)?.generation === generation) {
              setSlotState(slot, { status: stage });
            }
          },
        });
        const currentType = optionsRef.current.selectedDocumentTypes[slot];
        if (selections.current.get(slot)?.generation === generation && !finished.current) {
          if (validTypeForSlot(slot, currentType)) {
            const relevance = classifyShopOwnerDocumentRelevance(currentType, result.passes);
            const entry = assessShopOwnerDocument(slot, currentType, result.passes, optionsRef.current.context);
            evidence.current.set(slot, { generation, passes: result.passes });
            setSlotState(slot, stateForEntry(entry, slot, currentType, relevance));
          } else {
            makeUnable(slot, generation, 'ocr_unavailable');
          }
        }
      } catch (error) {
        const reason = reasonFrom(error);
        const cleanup = (error as ReaderError | null)?.cleanup;
        if (selections.current.get(slot)?.generation === generation && !finished.current) makeUnable(slot, generation, reason);
        if (cleanup && !(await cleanupConfirmed(cleanup))) {
          blocked.current = true;
          const remaining = [...new Set(queue.current)];
          queue.current = [];
          queued.current.clear();
          remaining.forEach((queuedSlot) => {
            const queuedSelection = selections.current.get(queuedSlot);
            if (queuedSelection) makeUnable(queuedSlot, queuedSelection.generation, 'ocr_unavailable');
          });
        }
      }
      if (active.current?.slot === slot && active.current.generation === generation) active.current = null;
    }
  }, [makeUnable, setSlotState]);

  const selectFile = useCallback((slot: ShopOwnerOcrSlot, file: File) => {
    if (finished.current) return;
    const generation = (generations.current.get(slot) ?? 0) + 1;
    generations.current.set(slot, generation);
    selections.current.set(slot, { file, generation });
    evidence.current.delete(slot);
    setSlotState(slot, { status: 'queued' });

    if (active.current?.slot === slot) active.current.controller.abort();
    if (!optionsRef.current.assets || blocked.current) {
      makeUnable(slot, generation, 'ocr_unavailable');
      return;
    }
    if (!queued.current.has(slot)) {
      queue.current.push(slot);
      queued.current.add(slot);
    }
    void drain();
  }, [drain, makeUnable, setSlotState]);

  const removeFile = useCallback((slot: ShopOwnerOcrSlot) => {
    const generation = (generations.current.get(slot) ?? 0) + 1;
    generations.current.set(slot, generation);
    selections.current.delete(slot);
    evidence.current.delete(slot);
    queue.current = queue.current.filter((queuedSlot) => queuedSlot !== slot);
    queued.current.delete(slot);
    if (active.current?.slot === slot) active.current.controller.abort();
    setSlotState(slot, null);
  }, [setSlotState]);

  const submissionSnapshot = useCallback((): string | null => {
    const entries: Partial<Record<ShopOwnerOcrSlot, ShopOwnerOcrEntry>> = {};
    for (const [slot, item] of evidence.current) {
      const entry = currentEntry(slot, item);
      if (entry) entries[slot] = entry;
    }
    if (Object.keys(entries).length === 0) return null;
    return serializeOwnerOcrEnvelope(entries, optionsRef.current.selectedDocumentTypes);
  }, [currentEntry]);

  const submissionBlockReason = useCallback((): 'scan_pending' | 'replacement_required' | null => {
    if ([...slotStatuses.current.values()].some((status) => status === 'queued' || status === 'loading' || status === 'recognizing')) {
      return 'scan_pending';
    }
    for (const [slot, item] of evidence.current) {
      const type = optionsRef.current.selectedDocumentTypes[slot];
      if (item.passes && validTypeForSlot(slot, type)
        && ['clearly_irrelevant', 'incompatible_document_type'].includes(
          classifyShopOwnerDocumentRelevance(type, item.passes),
        )) {
        return 'replacement_required';
      }
    }
    return null;
  }, []);

  const finish = useCallback(() => {
    if (finished.current) return;
    finished.current = true;
    active.current?.controller.abort();
    active.current = null;
    selections.current.clear();
    evidence.current.clear();
    generations.current.clear();
    queue.current = [];
    queued.current.clear();
    slotStatuses.current.clear();
    if (mounted.current) setStoredStates({});
  }, []);

  useEffect(() => {
    mounted.current = true;
    finished.current = false;
    return () => {
      mounted.current = false;
      finished.current = true;
      active.current?.controller.abort();
      active.current = null;
      selections.current.clear();
      evidence.current.clear();
      generations.current.clear();
      queue.current = [];
      queued.current.clear();
      slotStatuses.current.clear();
    };
  }, []);

  const states = { ...storedStates };
  for (const [slot, item] of evidence.current) {
    const entry = currentEntry(slot, item);
    const type = optionsRef.current.selectedDocumentTypes[slot];
    if (entry && validTypeForSlot(slot, type)) {
      const relevance = item.passes
        ? classifyShopOwnerDocumentRelevance(type, item.passes)
        : 'technical_failure';
      states[slot] = stateForEntry(entry, slot, type, relevance);
    }
  }

  return { states, selectFile, removeFile, submissionSnapshot, submissionBlockReason, finish };
}
