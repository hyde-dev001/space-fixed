import React, { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';

export type IconButtonVariant = 'neutral' | 'primary' | 'success' | 'warning' | 'danger';

export interface IconButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children' | 'color'> {
  children: ReactNode;
  label?: string;
  variant?: IconButtonVariant;
}

const defaultIconButtonClass =
  'inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-lg border transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:cursor-not-allowed';

const IconButton = forwardRef<HTMLButtonElement, IconButtonProps>(({
  children,
  label,
  variant = 'neutral',
  className,
  title,
  type = 'button',
  'aria-label': ariaLabel,
  ...buttonProps
}, ref) => {
  const accessibleLabel = label ?? ariaLabel ?? title;

  return (
    <button
      {...buttonProps}
      ref={ref}
      type={type}
      className={className ?? defaultIconButtonClass}
      data-erp-icon-action="true"
      data-semantic-color={variant}
      aria-label={accessibleLabel}
      title={title ?? accessibleLabel}
    >
      {children}
    </button>
  );
});

IconButton.displayName = 'IconButton';

export default IconButton;
