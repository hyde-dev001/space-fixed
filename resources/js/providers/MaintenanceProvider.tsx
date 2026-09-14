import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { MaintenanceBanner } from '../components/maintenance/MaintenanceBanner';
import { MaintenanceWarningModal } from '../components/maintenance/MaintenanceWarningModal';
import {
  CRITICAL_MAINTENANCE_ROUTE_NAMES,
  MaintenanceSnapshot,
} from '../types/maintenance';

const POLL_INTERVAL_MS = 30_000;
const WARNING_MODAL_WINDOW_MS = 5 * 60_000;

type AxiosResponse = { data: unknown };
type AxiosClient = { get: (url: string, config?: Record<string, unknown>) => Promise<AxiosResponse> };

type MaintenanceContextValue = {
  state: MaintenanceSnapshot;
  serverNow: () => number;
  refresh: () => Promise<void>;
  refreshError: boolean;
  isFreezeActive: boolean;
  isRouteFrozen: (routeName: string) => boolean;
  registerDirtySource: (id: string, isDirty: boolean) => () => void;
  hasDirtySources: boolean;
  warningVisible: boolean;
  dismissWarning: () => void;
};

const defaultMaintenanceContext: MaintenanceContextValue = {
  state: { state: 'unavailable', server_time: new Date(0).toISOString() },
  serverNow: () => Date.now(),
  refresh: async () => undefined,
  refreshError: false,
  isFreezeActive: false,
  isRouteFrozen: () => false,
  registerDirtySource: () => () => undefined,
  hasDirtySources: false,
  warningVisible: false,
  dismissWarning: () => undefined,
};

const MaintenanceContext = createContext<MaintenanceContextValue>(defaultMaintenanceContext);

function asRecord(value: unknown): Record<string, unknown> | null {
  return typeof value === 'object' && value !== null ? value as Record<string, unknown> : null;
}

function requiredString(record: Record<string, unknown>, key: string): string {
  const value = record[key];
  if (typeof value !== 'string' || value.length === 0) {
    throw new Error(`Invalid maintenance status field: ${key}`);
  }
  return value;
}

function nullableString(record: Record<string, unknown>, key: string): string | null {
  const value = record[key];
  if (value === null || value === undefined) {
    return null;
  }
  if (typeof value !== 'string') {
    throw new Error(`Invalid maintenance status field: ${key}`);
  }
  return value;
}

function requiredNumber(record: Record<string, unknown>, key: string): number {
  const value = record[key];
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    throw new Error(`Invalid maintenance status field: ${key}`);
  }
  return value;
}

function nullableNumber(record: Record<string, unknown>, key: string): number | null {
  const value = record[key];
  if (value === null || value === undefined) {
    return null;
  }
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    throw new Error(`Invalid maintenance status field: ${key}`);
  }
  return value;
}

function parseStatus(payload: unknown): MaintenanceSnapshot {
  const record = asRecord(payload);
  if (!record) {
    throw new Error('Invalid maintenance status response');
  }

  const state = requiredString(record, 'state');
  const serverTime = requiredString(record, 'server_time');
  if (!Number.isFinite(Date.parse(serverTime))) {
    throw new Error('Invalid maintenance server time');
  }

  if (state === 'operational' || state === 'unavailable') {
    return { state, server_time: serverTime };
  }

  if (state !== 'scheduled' && state !== 'active') {
    throw new Error('Invalid maintenance state');
  }

  const id = requiredNumber(record, 'id');
  const startsAt = requiredString(record, 'starts_at');
  const endsAt = requiredString(record, 'ends_at');
  if (!Number.isFinite(Date.parse(startsAt)) || !Number.isFinite(Date.parse(endsAt))) {
    throw new Error('Invalid maintenance timing');
  }

  return {
    state,
    id,
    title: requiredString(record, 'title'),
    message: requiredString(record, 'message'),
    starts_at: startsAt,
    ends_at: endsAt,
    notify_before_minutes: requiredNumber(record, 'notify_before_minutes'),
    transaction_freeze_minutes: nullableNumber(record, 'transaction_freeze_minutes'),
    progress_stage: nullableString(record, 'progress_stage'),
    update_message: nullableString(record, 'update_message'),
    update_message_updated_at: nullableString(record, 'update_message_updated_at'),
    server_time: serverTime,
  };
}

function getAxios(): AxiosClient {
  const axiosClient = (window as Window & { axios?: AxiosClient }).axios;
  if (!axiosClient || typeof axiosClient.get !== 'function') {
    throw new Error('Axios is not available');
  }
  return axiosClient;
}

export function MaintenanceProvider({
  children,
  isMaintenancePage = false,
  initialStatus,
}: React.PropsWithChildren<{ isMaintenancePage?: boolean; initialStatus?: unknown }>): React.JSX.Element {
  const initialState = useMemo<MaintenanceSnapshot>(() => {
    try {
      return parseStatus(initialStatus);
    } catch {
      return { state: 'unavailable', server_time: new Date().toISOString() };
    }
  }, [initialStatus]);
  const [state, setState] = useState<MaintenanceSnapshot>(initialState);
  const [refreshError, setRefreshError] = useState(false);
  const [warningVisible, setWarningVisible] = useState(false);
  const [dirtyVersion, setDirtyVersion] = useState(0);
  const offsetRef = useRef(
    initialState.state === 'unavailable' ? 0 : Date.parse(initialState.server_time) - Date.now(),
  );
  const lastSuccessfulRef = useRef<MaintenanceSnapshot | null>(
    initialState.state === 'unavailable' ? null : initialState,
  );
  const dirtySourcesRef = useRef(new Map<string, boolean>());
  const navigationTriggeredRef = useRef(false);

  const serverNow = useCallback(() => Date.now() + offsetRef.current, []);

  const navigateToMaintenance = useCallback(() => {
    if (isMaintenancePage || window.location.pathname === '/maintenance' || navigationTriggeredRef.current) {
      return;
    }

    navigationTriggeredRef.current = true;
    router.visit('/maintenance', { replace: true });
  }, [isMaintenancePage]);

  const refresh = useCallback(async () => {
    try {
      const response = await getAxios().get('/system/maintenance-status', {
        headers: { Accept: 'application/json' },
      });
      const nextState = parseStatus(response.data);
      offsetRef.current = Date.parse(nextState.server_time) - Date.now();
      lastSuccessfulRef.current = nextState;
      setState(nextState);
      setRefreshError(false);

      if (nextState.state === 'active') {
        navigateToMaintenance();
      } else {
        navigationTriggeredRef.current = false;
      }
    } catch {
      setRefreshError(true);
      if (!lastSuccessfulRef.current) {
        setState((current) => current.state === 'unavailable' ? current : {
          state: 'unavailable',
          server_time: current.server_time,
        });
      }
    }
  }, [navigateToMaintenance]);

  useEffect(() => {
    void refresh();
    const interval = window.setInterval(() => void refresh(), POLL_INTERVAL_MS);
    const removeNavigationListener = router.on('navigate', () => void refresh());
    const handleVisibilityChange = () => {
      if (document.visibilityState === 'visible') {
        void refresh();
      }
    };
    const handleMaintenanceActive = () => navigateToMaintenance();

    document.addEventListener('visibilitychange', handleVisibilityChange);
    window.addEventListener('solespace:maintenance-active', handleMaintenanceActive);

    return () => {
      window.clearInterval(interval);
      removeNavigationListener();
      document.removeEventListener('visibilitychange', handleVisibilityChange);
      window.removeEventListener('solespace:maintenance-active', handleMaintenanceActive);
    };
  }, [navigateToMaintenance, refresh]);

  useEffect(() => {
    if (state.state !== 'scheduled') {
      setWarningVisible(false);
      return;
    }

    const remaining = Date.parse(state.starts_at) - serverNow();
    if (remaining <= 0 || remaining > WARNING_MODAL_WINDOW_MS) {
      return;
    }

    const key = `maintenance-warning:${state.id}`;
    try {
      if (!window.localStorage.getItem(key)) {
        window.localStorage.setItem(key, '1');
        setWarningVisible(true);
      }
    } catch {
      setWarningVisible(true);
    }
  }, [serverNow, state]);

  const registerDirtySource = useCallback((id: string, isDirty: boolean) => {
    dirtySourcesRef.current.set(id, isDirty);
    setDirtyVersion((version) => version + 1);

    return () => {
      if (dirtySourcesRef.current.delete(id)) {
        setDirtyVersion((version) => version + 1);
      }
    };
  }, []);

  const hasDirtySources = useMemo(
    () => Array.from(dirtySourcesRef.current.values()).some(Boolean),
    [dirtyVersion],
  );

  const isFreezeActive = useMemo(() => {
    if (state.state !== 'scheduled' || state.transaction_freeze_minutes === null) {
      return false;
    }

    const startsAt = Date.parse(state.starts_at);
    return serverNow() >= startsAt - state.transaction_freeze_minutes * 60_000 && serverNow() < startsAt;
  }, [serverNow, state]);

  const isRouteFrozen = useCallback(
    (routeName: string) => isFreezeActive && CRITICAL_MAINTENANCE_ROUTE_NAMES.has(routeName),
    [isFreezeActive],
  );

  const contextValue = useMemo<MaintenanceContextValue>(() => ({
    state,
    serverNow,
    refresh,
    refreshError,
    isFreezeActive,
    isRouteFrozen,
    registerDirtySource,
    hasDirtySources,
    warningVisible,
    dismissWarning: () => setWarningVisible(false),
  }), [
    hasDirtySources,
    isFreezeActive,
    isRouteFrozen,
    refresh,
    refreshError,
    registerDirtySource,
    serverNow,
    state,
    warningVisible,
  ]);

  return (
    <MaintenanceContext.Provider value={contextValue}>
      {!isMaintenancePage && <MaintenanceBanner />}
      {!isMaintenancePage && <MaintenanceWarningModal />}
      {children}
    </MaintenanceContext.Provider>
  );
}

export function useMaintenance(): MaintenanceContextValue {
  return useContext(MaintenanceContext);
}
