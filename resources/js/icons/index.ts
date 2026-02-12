// SVG icons - using string paths instead of React components for better compatibility
import React from "react";

export const IconPaths = {
  plus: "./plus.svg",
  close: "./close.svg",
  box: "./box.svg",
  checkCircle: "./check-circle.svg",
  alert: "./alert.svg",
  info: "./info.svg",
  error: "./info-error.svg",
  bolt: "./bolt.svg",
  arrowUp: "./arrow-up.svg",
  arrowDown: "./arrow-down.svg",
  folder: "./folder.svg",
  video: "./videos.svg",
  audio: "./audio.svg",
  grid: "./grid.svg",
  file: "./file.svg",
  download: "./download.svg",
  arrowRight: "./arrow-right.svg",
  group: "./group.svg",
  boxLine: "./box-line.svg",
  shootingStar: "./shooting-star.svg",
  dollarLine: "./dollar-line.svg",
  trash: "./trash.svg",
  angleUp: "./angle-up.svg",
  angleDown: "./angle-down.svg",
  angleLeft: "./angle-left.svg",
  angleRight: "./angle-right.svg",
  pencil: "./pencil.svg",
  checkLine: "./check-line.svg",
  closeLine: "./close-line.svg",
  chevronDown: "./chevron-down.svg",
  chevronUp: "./chevron-up.svg",
  paperPlane: "./paper-plane.svg",
  lock: "./lock.svg",
  envelope: "./envelope.svg",
  user: "./user-line.svg",
  calender: "./calender-line.svg",
  eye: "./eye.svg",
  eyeClose: "./eye-close.svg",
  time: "./time.svg",
  copy: "./copy.svg",
  chevronLeft: "./chevron-left.svg",
  userCircle: "./user-circle.svg",
  task: "./task-icon.svg",
  list: "./list.svg",
  table: "./table.svg",
  page: "./page.svg",
  pieChart: "./pie-chart.svg",
  boxCube: "./box-cube.svg",
  plugIn: "./plug-in.svg",
  docs: "./docs.svg",
  mail: "./mail-line.svg",
  horizontalDots: "./horizontal-dots.svg",
  chat: "./chat.svg",
  moreDot: "./moredot.svg",
  alertHexa: "./alert-hexa.svg",
  errorHexa: "./info-hexa.svg",
};

// Dummy exports for backwards compatibility
const DummyIcon = () => null;
export const PlusIcon = DummyIcon;
export const CloseIcon = DummyIcon;
export const BoxIcon = DummyIcon;
export const CheckCircleIcon = DummyIcon;
export const AlertIcon = DummyIcon;
export const InfoIcon = DummyIcon;
export const ErrorIcon = DummyIcon;
export const BoltIcon = DummyIcon;
export const ArrowUpIcon = DummyIcon;
export const ArrowDownIcon = DummyIcon;
export const FolderIcon = DummyIcon;
export const VideoIcon = DummyIcon;
export const AudioIcon = DummyIcon;
export const GridIcon = DummyIcon;
export const FileIcon = DummyIcon;
export const DownloadIcon = DummyIcon;
export const ArrowRightIcon = DummyIcon;
export const GroupIcon = DummyIcon;
export const BoxIconLine = DummyIcon;
export const ShootingStarIcon = DummyIcon;
export const DollarLineIcon = DummyIcon;
export const TrashBinIcon = DummyIcon;
export const AngleUpIcon = DummyIcon;
export const AngleDownIcon = DummyIcon;
export const AngleLeftIcon = DummyIcon;
export const AngleRightIcon = DummyIcon;
export const PencilIcon = DummyIcon;
export const CheckLineIcon = DummyIcon;
export const CloseLineIcon = DummyIcon;
export const ChevronDownIcon = DummyIcon;
export const ChevronUpIcon = DummyIcon;
export const PaperPlaneIcon = DummyIcon;
export const LockIcon = DummyIcon;
export const EnvelopeIcon = DummyIcon;
export const UserIcon = DummyIcon;
export const CalenderIcon = DummyIcon;
export const EyeIcon = DummyIcon;
export const EyeCloseIcon = DummyIcon;
export const TimeIcon = DummyIcon;
export const CopyIcon = DummyIcon;
export const ChevronLeftIcon = DummyIcon;
export const UserCircleIcon = DummyIcon;
export const TaskIcon = DummyIcon;
export const ListIcon = DummyIcon;
export const PageIcon = DummyIcon;
export const PieChartIcon = DummyIcon;
export const BoxCubeIcon = DummyIcon;
export const PlugInIcon = DummyIcon;
export const DocsIcon = DummyIcon;
export const MailIcon = DummyIcon;
export const HorizontaLDots = DummyIcon;
export const ChatIcon = DummyIcon;
export const MoreDotIcon = DummyIcon;
export const AlertHexaIcon = DummyIcon;
export const ErrorHexaIcon = DummyIcon;
export const CurrencyDollarIcon = DummyIcon;
export const TableIcon = DummyIcon;

// Add Google Icon component
export const GoogleIcon = ({ className = "" }: { className?: string }) =>
  React.createElement('svg', {
    className,
    viewBox: '0 0 24 24',
    xmlns: 'http://www.w3.org/2000/svg'
  },
    React.createElement('path', { fill: '#4285F4', d: 'M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z' }),
    React.createElement('path', { fill: '#34A853', d: 'M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z' }),
    React.createElement('path', { fill: '#FBBC05', d: 'M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z' }),
    React.createElement('path', { fill: '#EA4335', d: 'M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z' })
  );
