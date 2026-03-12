import React from "react";

type IconProps = { className?: string; title?: string };

const IconBase: React.FC<React.PropsWithChildren<IconProps>> = ({ children, className = "" }) => (
  <svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth={1.5}
    strokeLinecap="round"
    strokeLinejoin="round"
    className={className}
    aria-hidden="true"
  >
    {children}
  </svg>
);

export const UserIcon: React.FC<IconProps> = ({ className = "" }) => (
  <IconBase className={className}>
    <path d="M16 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2" />
    <circle cx="12" cy="7" r="4" />
  </IconBase>
);

export const UserCircleIcon: React.FC<IconProps> = ({ className = "" }) => (
  <IconBase className={className}>
    <circle cx="12" cy="12" r="9" />
    <path d="M12 8a3 3 0 110 6 3 3 0 010-6z" />
    <path d="M6.5 17a6.5 6.5 0 0111 0" />
  </IconBase>
);

export const GridIcon: React.FC<IconProps> = ({ className = "" }) => (
  <IconBase className={className}>
    <rect x="3" y="3" width="8" height="8" rx="1" />
    <rect x="13" y="3" width="8" height="8" rx="1" />
    <rect x="3" y="13" width="8" height="8" rx="1" />
    <rect x="13" y="13" width="8" height="8" rx="1" />
  </IconBase>
);

export const CheckLineIcon: React.FC<IconProps> = ({ className = "" }) => (
  <IconBase className={className}>
    <path d="M20 6L9 17l-5-5" />
  </IconBase>
);

export const CalenderIcon: React.FC<IconProps> = ({ className = "" }) => (
  <IconBase className={className}>
    <rect x="3" y="5" width="18" height="16" rx="2" />
    <path d="M16 3v4M8 3v4M3 11h18" />
  </IconBase>
);

export const ShootingStarIcon: React.FC<IconProps> = ({ className = "" }) => (
  <IconBase className={className}>
    <path d="M3 12l7-1 1-7 1 7 7 1-7 1-1 7-1-7-7-1z" />
  </IconBase>
);

export const ChevronDownIcon: React.FC<IconProps> = ({ className = "" }) => (
  <IconBase className={className}>
    <path d="M6 9l6 6 6-6" />
  </IconBase>
);

export const HorizontaLDots: React.FC<IconProps> = ({ className = "" }) => (
  <IconBase className={className}>
    <circle cx="5" cy="12" r="1.5" />
    <circle cx="12" cy="12" r="1.5" />
    <circle cx="19" cy="12" r="1.5" />
  </IconBase>
);

export const UserGroupIcon: React.FC<IconProps> = ({ className = "" }) => (
  <IconBase className={className}>
    <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
    <circle cx="9" cy="7" r="4" />
    <path d="M23 21v-2a4 4 0 00-3-3.87" />
    <path d="M16 3.13a4 4 0 010 7.75" />
  </IconBase>
);

export const CurrencyDollarIcon: React.FC<IconProps> = ({ className = "" }) => (
  <IconBase className={className}>
    <path d="M12 1v22M17 5H9a4 4 0 000 8h8a4 4 0 000-8H9a4 4 0 000 8h8" />
  </IconBase>
);

// fallback aliases to avoid breaking imports elsewhere
export const PlusIcon = UserIcon;
export const CloseIcon = UserIcon;
export const BoxIcon = UserIcon;
export const CheckCircleIcon = CheckLineIcon;
export const AlertIcon = UserIcon;
export const InfoIcon = UserIcon;
export const ErrorIcon = UserIcon;
export const BoltIcon = UserIcon;
export const ArrowUpIcon = UserIcon;
export const ArrowDownIcon = UserIcon;
export const FolderIcon = UserIcon;
export const VideoIcon = UserIcon;
export const AudioIcon = UserIcon;
export const FileIcon = UserIcon;
export const DownloadIcon = UserIcon;
export const ArrowRightIcon = UserIcon;
export const GroupIcon = UserIcon;
export const BoxIconLine = UserIcon;
export const DollarLineIcon = UserIcon;
export const TrashBinIcon = UserIcon;
export const AngleUpIcon = ChevronDownIcon;
export const AngleDownIcon = ChevronDownIcon;
export const AngleLeftIcon = ChevronDownIcon;
export const AngleRightIcon = ChevronDownIcon;
export const PencilIcon = UserIcon;
export const CloseLineIcon = CloseIcon;
export const ChevronUpIcon = ChevronDownIcon;
export const PaperPlaneIcon = UserIcon;
export const LockIcon = UserIcon;
export const EnvelopeIcon = UserIcon;
export const EyeIcon = UserIcon;
export const EyeCloseIcon = UserIcon;
export const TimeIcon = UserIcon;
export const CopyIcon = UserIcon;
export const ChevronLeftIcon = ChevronDownIcon;
export const TaskIcon = UserIcon;
export const ListIcon = UserIcon;
export const TableIcon = UserIcon;
export const PageIcon = UserIcon;
export const PieChartIcon = UserIcon;
export const BoxCubeIcon = UserIcon;
export const PlugInIcon = UserIcon;
export const DocsIcon = UserIcon;
export const MailIcon = UserIcon;
export const ChatIcon = UserIcon;
export const MoreDotIcon = HorizontaLDots;
export const AlertHexaIcon = UserIcon;
export const ErrorHexaIcon = UserIcon;

export const GoogleIcon: React.FC<IconProps> = ({ className = "" }) => (
  <svg className={className} viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
  </svg>
);

export default {};
