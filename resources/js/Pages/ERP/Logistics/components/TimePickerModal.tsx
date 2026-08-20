import React, { useEffect, useId, useRef, useState } from 'react';
import { Modal } from '@/components/ui/modal';

const ROW_HEIGHT = 48;
const VISIBLE_ROWS = 5;
const SPACER_ROWS = Math.floor(VISIBLE_ROWS / 2);
const HOURS = Array.from({ length: 12 }, (_, index) => index + 1);
const MINUTES = Array.from({ length: 60 }, (_, index) => index);
const PERIODS = ['AM', 'PM'] as const;

type Period = (typeof PERIODS)[number];
type WheelValue = number | Period;

type TimeParts = {
  hour: number;
  minute: number;
  period: Period;
};

type WheelColumnProps = {
  label: string;
  options: readonly WheelValue[];
  selectedIndex: number;
  testId: string;
  onChange: (index: number) => void;
};

export type TimePickerModalProps = {
  isOpen: boolean;
  label: string;
  value: string;
  onCancel: () => void;
  onConfirm: (value: string) => void;
};

const clamp = (value: number, min: number, max: number) => Math.min(Math.max(value, min), max);

const parseTimeValue = (value: string): TimeParts => {
  const [hoursPart, minutesPart] = value.slice(0, 5).split(':');
  const parsedHours = Number(hoursPart);
  const parsedMinutes = Number(minutesPart);
  const hours24 = Number.isInteger(parsedHours) && parsedHours >= 0 && parsedHours <= 23 ? parsedHours : 0;
  const minute = Number.isInteger(parsedMinutes) && parsedMinutes >= 0 && parsedMinutes <= 59 ? parsedMinutes : 0;

  return {
    hour: hours24 % 12 || 12,
    minute,
    period: hours24 >= 12 ? 'PM' : 'AM',
  };
};

const toTimeValue = ({ hour, minute, period }: TimeParts) => {
  const hours24 = period === 'AM' ? hour % 12 : (hour % 12) + 12;
  return `${String(hours24).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
};

export const formatTimeDisplay = (value: string) => {
  const { hour, minute, period } = parseTimeValue(value);
  return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')} ${period}`;
};

const formatWheelValue = (value: WheelValue) => (typeof value === 'number' ? String(value).padStart(2, '0') : value);

function WheelColumn({ label, options, selectedIndex, testId, onChange }: WheelColumnProps) {
  const scrollRef = useRef<HTMLDivElement>(null);
  const optionRefs = useRef<Array<HTMLButtonElement | null>>([]);
  const draggingRef = useRef<{ pointerId: number; startY: number; startScrollTop: number; moved: boolean } | null>(null);
  const suppressClickRef = useRef(false);

  const indexFromScroll = (scrollTop: number) => clamp(
    Math.round((scrollTop - SPACER_ROWS * ROW_HEIGHT) / ROW_HEIGHT),
    0,
    options.length - 1,
  );

  const scrollToIndex = (index: number) => {
    if (!scrollRef.current) return;
    scrollRef.current.scrollTop = (SPACER_ROWS + clamp(index, 0, options.length - 1)) * ROW_HEIGHT;
  };

  useEffect(() => {
    scrollToIndex(selectedIndex);
  }, [selectedIndex]);

  const handleScroll = () => {
    const nextIndex = indexFromScroll(scrollRef.current?.scrollTop ?? 0);
    if (nextIndex !== selectedIndex) onChange(nextIndex);
  };

  const snapToNearest = () => {
    const nextIndex = indexFromScroll(scrollRef.current?.scrollTop ?? 0);
    onChange(nextIndex);
    scrollToIndex(nextIndex);
  };

  const handlePointerDown: React.PointerEventHandler<HTMLDivElement> = (event) => {
    if (event.pointerType === 'mouse' && event.button !== 0) return;
    const element = scrollRef.current;
    if (!element) return;

    draggingRef.current = {
      pointerId: event.pointerId,
      startY: event.clientY,
      startScrollTop: element.scrollTop,
      moved: false,
    };
    element.setPointerCapture?.(event.pointerId);
  };

  const handlePointerMove: React.PointerEventHandler<HTMLDivElement> = (event) => {
    const drag = draggingRef.current;
    const element = scrollRef.current;
    if (!drag || drag.pointerId !== event.pointerId || !element) return;

    const distance = event.clientY - drag.startY;
    if (Math.abs(distance) > 4) drag.moved = true;
    if (drag.moved) element.scrollTop = drag.startScrollTop - distance;
  };

  const handlePointerEnd: React.PointerEventHandler<HTMLDivElement> = (event) => {
    const drag = draggingRef.current;
    const element = scrollRef.current;
    if (!drag || drag.pointerId !== event.pointerId) return;

    if (element?.hasPointerCapture?.(event.pointerId)) element.releasePointerCapture(event.pointerId);
    draggingRef.current = null;
    suppressClickRef.current = drag.moved;
    if (drag.moved) window.setTimeout(() => { suppressClickRef.current = false; }, 0);
    snapToNearest();
  };

  const handleOptionKeyDown = (event: React.KeyboardEvent<HTMLButtonElement>, index: number) => {
    let nextIndex: number | null = null;
    if (event.key === 'ArrowDown' || event.key === 'ArrowRight') nextIndex = index + 1;
    if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') nextIndex = index - 1;
    if (event.key === 'Home') nextIndex = 0;
    if (event.key === 'End') nextIndex = options.length - 1;
    if (nextIndex === null) return;

    event.preventDefault();
    nextIndex = clamp(nextIndex, 0, options.length - 1);
    onChange(nextIndex);
    scrollToIndex(nextIndex);
    optionRefs.current[nextIndex]?.focus();
  };

  return (
    <div className="relative min-w-0 flex-1">
      <div
        ref={scrollRef}
        data-testid={testId}
        role="listbox"
        aria-label={label}
        aria-orientation="vertical"
        className="relative h-60 overflow-y-auto overscroll-contain rounded-2xl border border-gray-200 bg-gray-50/80 py-0 outline-none [scrollbar-width:none] snap-y snap-mandatory touch-pan-y dark:border-gray-700 dark:bg-gray-800/80 [&::-webkit-scrollbar]:hidden"
        onScroll={handleScroll}
        onPointerDown={handlePointerDown}
        onPointerMove={handlePointerMove}
        onPointerUp={handlePointerEnd}
        onPointerCancel={handlePointerEnd}
      >
        <div aria-hidden="true" className="shrink-0" style={{ height: ROW_HEIGHT * SPACER_ROWS }} />
        {options.map((option, index) => (
          <button
            key={`${label}-${String(option)}`}
            ref={(element) => { optionRefs.current[index] = element; }}
            type="button"
            role="option"
            aria-selected={index === selectedIndex}
            tabIndex={index === selectedIndex ? 0 : -1}
            className="relative z-10 flex h-12 min-h-12 w-full shrink-0 snap-center items-center justify-center rounded-xl px-2 text-base font-semibold text-gray-500 transition-colors duration-150 hover:text-gray-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-gray-400 dark:hover:text-white"
            onClick={() => {
              if (suppressClickRef.current) {
                suppressClickRef.current = false;
                return;
              }
              onChange(index);
              scrollToIndex(index);
            }}
            onKeyDown={(event) => handleOptionKeyDown(event, index)}
          >
            {formatWheelValue(option)}
          </button>
        ))}
        <div aria-hidden="true" className="shrink-0" style={{ height: ROW_HEIGHT * SPACER_ROWS }} />
        <div aria-hidden="true" className="pointer-events-none absolute inset-x-1 top-1/2 z-0 h-12 -translate-y-1/2 rounded-xl border border-blue-200 bg-blue-50/70 dark:border-blue-400/30 dark:bg-blue-950/40" />
      </div>
    </div>
  );
}

export function TimePickerModal({ isOpen, label, value, onCancel, onConfirm }: TimePickerModalProps) {
  const headingId = useId();
  const [draft, setDraft] = useState<TimeParts>(() => parseTimeValue(value));
  const title = `Set ${label.toLowerCase()} time`;

  useEffect(() => {
    if (isOpen) setDraft(parseTimeValue(value));
  }, [isOpen, value]);

  const hourIndex = HOURS.indexOf(draft.hour);
  const minuteIndex = draft.minute;
  const periodIndex = PERIODS.indexOf(draft.period);

  return (
    <Modal
      isOpen={isOpen}
      onClose={onCancel}
      showCloseButton={false}
      size="md"
      className="!max-w-[calc(100vw-2rem)] overflow-hidden border border-gray-200 shadow-2xl dark:border-gray-700"
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={headingId}
        className="max-h-[calc(100dvh-2rem)] overflow-y-auto bg-white p-4 text-gray-950 dark:bg-gray-900 dark:text-white sm:p-6"
      >
        <div className="flex items-start justify-between gap-4 border-b border-gray-200 pb-4 dark:border-gray-700">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Choose time</p>
            <h2 id={headingId} className="mt-1 text-lg font-bold tracking-tight">{title}</h2>
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Scroll or drag each column to set the schedule.</p>
          </div>
          <button
            type="button"
            aria-label="Close time picker"
            onClick={onCancel}
            className="flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-xl border border-gray-200 text-gray-500 transition-colors duration-150 hover:border-gray-400 hover:text-gray-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-gray-700 dark:text-gray-400 dark:hover:border-gray-500 dark:hover:text-white"
          >
            <svg aria-hidden="true" className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
              <path d="M6 6l12 12M18 6L6 18" strokeLinecap="round" />
            </svg>
          </button>
        </div>

        <div className="mt-5 flex gap-2 sm:gap-3">
          <WheelColumn
            label="Hour"
            options={HOURS}
            selectedIndex={hourIndex}
            testId="time-picker-hour"
            onChange={(index) => setDraft((current) => ({ ...current, hour: HOURS[index] }))}
          />
          <WheelColumn
            label="Minute"
            options={MINUTES}
            selectedIndex={minuteIndex}
            testId="time-picker-minute"
            onChange={(index) => setDraft((current) => ({ ...current, minute: MINUTES[index] }))}
          />
          <WheelColumn
            label="AM or PM"
            options={PERIODS}
            selectedIndex={periodIndex}
            testId="time-picker-period"
            onChange={(index) => setDraft((current) => ({ ...current, period: PERIODS[index] }))}
          />
        </div>

        <p className="mt-4 text-center text-sm font-medium text-gray-500 dark:text-gray-400" aria-live="polite">
          Selected {formatTimeDisplay(toTimeValue(draft))}
        </p>

        <div className="mt-5 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:justify-end dark:border-gray-700">
          <button
            type="button"
            onClick={onCancel}
            className="min-h-11 rounded-xl border border-gray-200 px-5 font-semibold text-gray-700 transition-colors duration-150 hover:border-gray-400 hover:text-gray-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-gray-700 dark:text-gray-300 dark:hover:border-gray-500 dark:hover:text-white"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={() => onConfirm(toTimeValue(draft))}
            className="min-h-11 rounded-xl bg-blue-600 px-5 font-semibold text-white transition-colors duration-150 hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:bg-blue-500 dark:hover:bg-blue-400 dark:focus-visible:ring-offset-gray-900"
          >
            Done
          </button>
        </div>
      </div>
    </Modal>
  );
}
