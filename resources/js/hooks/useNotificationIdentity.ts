import { useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { useQueryClient, type QueryClient } from '@tanstack/react-query';

export interface NotificationAuth {
  erpActor?: { guard: string; id: number; tenantOwnerId: number; type: string } | null;
  user?: { id: number; shop_owner_id?: number | null } | null;
  shop_owner?: { id: number } | null;
  super_admin?: { id: number } | null;
}

export function retireNotificationQueries(queryClient: QueryClient, identity: string | null) {
  const departed = { predicate: (query: { queryKey: readonly unknown[] }) =>
    ['notifications', 'notification-preferences'].includes(String(query.queryKey[0]))
    && query.queryKey[1] !== identity };
  void queryClient.cancelQueries(departed);
  queryClient.removeQueries(departed);
}

export function notificationIdentity(auth?: NotificationAuth): string | null {
  if (auth?.super_admin) return JSON.stringify(['super_admin', auth.super_admin.id, null]);
  if (auth?.erpActor) {
    const actor = auth.erpActor;
    return JSON.stringify([actor.guard, actor.id, actor.tenantOwnerId]);
  }
  if (auth?.shop_owner) return JSON.stringify(['shop_owner', auth.shop_owner.id, auth.shop_owner.id]);
  if (auth?.user) return JSON.stringify(['user', auth.user.id, auth.user.shop_owner_id ?? null]);
  return null;
}

export function useNotificationIdentity() {
  const { auth } = usePage<{ auth?: NotificationAuth }>().props;
  const identity = notificationIdentity(auth);
  const currentIdentity = useRef(identity);
  currentIdentity.current = identity;
  const queryClient = useQueryClient();

  useEffect(() => {
    // Keys isolate the first render; cancellation/removal also retires private data.
    retireNotificationQueries(queryClient, identity);
  }, [identity, queryClient]);

  useEffect(() => {
    currentIdentity.current = identity;
    return () => { currentIdentity.current = null; };
  }, [identity]);

  return { identity, isCurrent: (requestIdentity: string | null | undefined) =>
    requestIdentity != null && currentIdentity.current === requestIdentity };
}
