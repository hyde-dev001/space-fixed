import React, { useId, useState } from 'react';

export type RefundEligibilityTooltipProps = {
  message: string;
  children: React.ReactNode;
};

export default function RefundEligibilityTooltip({
  message,
  children,
}: RefundEligibilityTooltipProps) {
  const tooltipId = useId();
  const [visible, setVisible] = useState(false);

  return (
    <span
      role="group"
      aria-label="Refund eligibility information"
      aria-describedby={visible ? tooltipId : undefined}
      tabIndex={0}
      className="relative inline-flex"
      onMouseEnter={() => setVisible(true)}
      onMouseLeave={() => setVisible(false)}
      onFocus={() => setVisible(true)}
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setVisible(false);
      }}
      onPointerDown={(event) => {
        if (event.pointerType !== 'mouse') setVisible((current) => !current);
      }}
      onKeyDown={(event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          setVisible((current) => !current);
        }
      }}
    >
      {children}
      {visible && (
        <span
          id={tooltipId}
          role="tooltip"
          className="pointer-events-none absolute bottom-full right-0 z-50 mb-2 w-max max-w-[min(18rem,calc(100vw-2rem))] rounded-lg bg-[#16233b] px-3 py-2 text-left text-xs font-medium leading-5 text-white shadow-xl"
        >
          {message}
        </span>
      )}
    </span>
  );
}
