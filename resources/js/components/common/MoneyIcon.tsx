type MoneyIconProps = {
  className?: string;
};

export const MoneyIcon = ({ className = "" }: MoneyIconProps) => (
  <svg
    className={className}
    viewBox="0 0 24 24"
    fill="none"
    xmlns="http://www.w3.org/2000/svg"
    stroke="currentColor"
    strokeWidth={1.5}
    strokeLinecap="round"
    strokeLinejoin="round"
    aria-hidden="true"
  >
    <rect x="3" y="5" width="18" height="14" rx="2" />
    <circle cx="12" cy="12" r="3" />
    <path d="M7 8h.01M17 16h.01" />
  </svg>
);
