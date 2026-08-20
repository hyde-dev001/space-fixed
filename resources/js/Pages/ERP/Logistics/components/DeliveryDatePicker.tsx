import React, { useEffect, useMemo, useRef, useState } from 'react';
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react';

type Props = {
  value: string;
  minDate: string;
  onChange: (value: string) => void;
  disabled?: boolean;
  calendarId?: string;
};

const monthFormatter = new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' });
const dateFormatter = new Intl.DateTimeFormat('en-US', { month: 'long', day: 'numeric', year: 'numeric', timeZone: 'UTC' });

const parseDate = (value: string) => {
  const [year, month, day] = value.split('-').map(Number);
  if (!year || !month || !day) return null;
  return new Date(Date.UTC(year, month - 1, day));
};

const dateKey = (date: Date) => [date.getUTCFullYear(), String(date.getUTCMonth() + 1).padStart(2, '0'), String(date.getUTCDate()).padStart(2, '0')].join('-');
const monthKey = (date: Date) => `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
const firstOfMonth = (date: Date) => new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), 1));

const displayDate = (value: string) => {
  const date = parseDate(value);
  return date ? `${String(date.getUTCMonth() + 1).padStart(2, '0')}/${String(date.getUTCDate()).padStart(2, '0')}/${date.getUTCFullYear()}` : 'mm/dd/yyyy';
};

const todayFallback = () => {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(new Date());
  const value = (type: Intl.DateTimeFormatPartTypes) => parts.find((part) => part.type === type)?.value ?? '';
  return `${value('year')}-${value('month')}-${value('day')}`;
};

export default function DeliveryDatePicker({ value, minDate, onChange, disabled = false, calendarId = 'delivery-date-calendar' }: Props) {
  const minimum = minDate || todayFallback();
  const minimumDate = parseDate(minimum) ?? firstOfMonth(new Date());
  const initialMonth = firstOfMonth(parseDate(value) ?? minimumDate);
  const [open, setOpen] = useState(false);
  const [visibleMonth, setVisibleMonth] = useState(initialMonth);
  const rootRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    if (value) {
      const selectedDate = parseDate(value);
      if (selectedDate) setVisibleMonth(firstOfMonth(selectedDate));
    }
  }, [value]);

  useEffect(() => {
    if (!open) return undefined;
    const closeOnOutsideClick = (event: PointerEvent) => {
      if (!rootRef.current?.contains(event.target as Node)) setOpen(false);
    };
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false);
    };
    document.addEventListener('pointerdown', closeOnOutsideClick);
    document.addEventListener('keydown', closeOnEscape);
    return () => {
      document.removeEventListener('pointerdown', closeOnOutsideClick);
      document.removeEventListener('keydown', closeOnEscape);
    };
  }, [open]);

  const days = useMemo(() => {
    const firstDay = visibleMonth.getUTCDay();
    const daysInMonth = new Date(Date.UTC(visibleMonth.getUTCFullYear(), visibleMonth.getUTCMonth() + 1, 0)).getUTCDate();
    const cellCount = Math.ceil((firstDay + daysInMonth) / 7) * 7;
    return Array.from({ length: cellCount }, (_, index) => {
      const day = index - firstDay + 1;
      return day > 0 && day <= daysInMonth
        ? new Date(Date.UTC(visibleMonth.getUTCFullYear(), visibleMonth.getUTCMonth(), day))
        : null;
    });
  }, [visibleMonth]);

  const previousMonthDisabled = monthKey(visibleMonth) <= monthKey(firstOfMonth(minimumDate));
  const chooseDate = (date: Date) => {
    const nextValue = dateKey(date);
    if (nextValue < minimum) return;
    onChange(nextValue);
    setOpen(false);
  };

  return <div ref={rootRef} className="relative min-w-0">
    <input aria-label="Delivery date" tabIndex={-1} value={value} onChange={(event) => onChange(event.target.value)} className="sr-only" />
    <button
      type="button"
      aria-label="Open delivery date picker"
      aria-haspopup="dialog"
      aria-expanded={open}
      aria-controls={calendarId}
      disabled={disabled}
      onClick={() => setOpen((isOpen) => !isOpen)}
      className="flex min-h-11 w-full items-center justify-between gap-3 rounded-xl border border-gray-300 bg-white px-3 text-left text-sm text-gray-700 shadow-sm transition hover:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
    >
      <span className={value ? 'text-gray-900 dark:text-white' : 'text-gray-500'}>{displayDate(value)}</span>
      <CalendarDays aria-hidden="true" size={17} className="shrink-0 text-gray-500" />
    </button>
    {open && <div id={calendarId} role="dialog" aria-label="Delivery date calendar" className="absolute left-1/2 top-full z-30 mt-2 w-[min(28rem,calc(100vw-2rem))] max-w-[calc(100vw-2rem)] -translate-x-1/2 rounded-2xl border border-gray-200 bg-white p-4 shadow-xl dark:border-gray-700 dark:bg-gray-800 xl:left-0 xl:translate-x-0">
      <div className="flex items-center justify-between gap-3">
        <button type="button" aria-label="Previous month" disabled={previousMonthDisabled} onClick={() => setVisibleMonth(new Date(Date.UTC(visibleMonth.getUTCFullYear(), visibleMonth.getUTCMonth() - 1, 1)))} className="inline-flex min-h-9 min-w-9 items-center justify-center rounded-lg border border-gray-300 text-gray-600 transition hover:border-blue-400 hover:text-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-600 dark:text-gray-300">
          <ChevronLeft aria-hidden="true" size={17} />
        </button>
        <h3 className="text-base font-bold text-gray-900 dark:text-white">{monthFormatter.format(visibleMonth)}</h3>
        <button type="button" aria-label="Next month" onClick={() => setVisibleMonth(new Date(Date.UTC(visibleMonth.getUTCFullYear(), visibleMonth.getUTCMonth() + 1, 1)))} className="inline-flex min-h-9 min-w-9 items-center justify-center rounded-lg border border-gray-300 text-gray-600 transition hover:border-blue-400 hover:text-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:text-gray-300">
          <ChevronRight aria-hidden="true" size={17} />
        </button>
      </div>
      <div className="mt-4 grid grid-cols-7 gap-2 text-center text-[11px] font-bold uppercase tracking-wide text-gray-500" aria-hidden="true">
        {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((day) => <span key={day}>{day}</span>)}
      </div>
      <div className="mt-2 grid grid-cols-7 gap-2">
        {days.map((day, index) => {
          if (!day) return <span key={`empty-${index}`} aria-hidden="true" className="min-h-10" />;
          const valueForDay = dateKey(day);
          const isPast = valueForDay < minimum;
          const isSelected = valueForDay === value;
          return <button
            key={valueForDay}
            type="button"
            aria-label={`Select ${dateFormatter.format(day)}`}
            disabled={isPast}
            aria-pressed={isSelected}
            onClick={() => chooseDate(day)}
            className={`min-h-10 rounded-lg border text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-blue-500 ${isSelected ? 'border-blue-600 bg-blue-600 text-white shadow-sm' : isPast ? 'cursor-not-allowed border-gray-100 bg-gray-100 text-gray-400 dark:border-gray-700 dark:bg-gray-700 dark:text-gray-500' : 'border-gray-200 text-gray-700 hover:border-blue-400 hover:bg-blue-50 hover:text-blue-700 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700'}`}
          >{day.getUTCDate()}</button>;
        })}
      </div>
      <div className="mt-4 flex items-center justify-between gap-3 border-t border-gray-100 pt-3 dark:border-gray-700">
        <span className="text-xs text-gray-500">Past dates are unavailable.</span>
        <button type="button" aria-label="Clear date" disabled={!value} onClick={() => { onChange(''); setOpen(false); }} className="text-xs font-bold text-blue-600 hover:text-blue-700 disabled:cursor-not-allowed disabled:text-gray-400">Clear date</button>
      </div>
    </div>}
  </div>;
}
