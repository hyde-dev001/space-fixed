import { Head, usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import axios from 'axios';
import { MessageCircle, Star, UsersRound } from 'lucide-react';
import AppLayoutERP from '../../../layout/AppLayout_ERP';
import { erpUrl } from '@/utils/erpCapabilities';
import type { ErpCapabilities } from '@/types/erp';
import {
    DashboardMetricCard,
    DashboardPanel,
    DashboardShell,
    DashboardState,
    DashboardTrendChart,
} from '../../../components/dashboard';

interface DashboardStats {
    activeCustomers: number;
    openConversations: number;
    pendingReviews: number;
    averageRating: number;
}

interface EngagementItem { channel: string; count: number; }
interface InteractionItem {
    conversation_id: number;
    customer_name: string;
    customer_email: string | null;
    last_message: string;
    last_message_at: string;
    status: string;
    priority: string;
}

type CrmPageProps = {
    initialStats?: DashboardStats;
    initialEngagement?: EngagementItem[];
    initialInteractions?: InteractionItem[];
    auth?: { erpActor?: { ownerMode?: boolean } };
    erpCapabilities?: ErpCapabilities;
};

const formatInteractionTime = (value: string | null | undefined): string => {
    if (!value) return '';
    const parsedDate = new Date(value);
    return Number.isNaN(parsedDate.getTime()) ? value : parsedDate.toLocaleString();
};

const formatInteractionMessage = (value: string | null | undefined): string => {
    const message = value?.replace(/\*\*(.*?)\*\*/g, '$1').trim();
    return message || '—';
};

export default function CRMDashboard() {
    const { initialStats, initialEngagement, initialInteractions, auth, erpCapabilities } = usePage<CrmPageProps>().props;
    const ownerMode = auth?.erpActor?.ownerMode === true;
    const defaultStats: DashboardStats = { activeCustomers: 0, openConversations: 0, pendingReviews: 0, averageRating: 0 };
    const [stats, setStats] = useState<DashboardStats>(initialStats ?? defaultStats);
    const [engagementData, setEngagement] = useState<EngagementItem[]>(initialEngagement ?? []);
    const [interactions, setInteractions] = useState<InteractionItem[]>(initialInteractions ?? []);
    const [refreshing, setRefreshing] = useState(false);
    const [lastSynced, setLastSynced] = useState<Date>(new Date());

    const handleRefresh = useCallback(async () => {
        setRefreshing(true);
        try {
            const dashboardUrl = erpUrl(erpCapabilities, 'GET:crm.api.dashboard-stats')
                ?? (ownerMode ? null : '/api/crm/dashboard-stats');
            if (!dashboardUrl) return;

            const { data } = await axios.get(dashboardUrl);
            setStats({
                activeCustomers: data.active_customers ?? 0,
                openConversations: data.open_conversations ?? 0,
                pendingReviews: data.pending_reviews ?? 0,
                averageRating: data.average_rating ?? 0,
            });
            setEngagement(data.engagement_by_channel ?? []);
            setInteractions(data.recent_interactions ?? []);
            setLastSynced(new Date());
        } catch {
            // The initial server snapshot remains visible when a refresh fails.
        } finally {
            setRefreshing(false);
        }
    }, [erpCapabilities, ownerMode]);

    return (
        <AppLayoutERP>
            <Head title="CRM Dashboard - SoleSpace ERP" />
            <DashboardShell
                testId="crm-dashboard"
                title="CRM Dashboard"
                description="Track customer engagement, pipeline, and support response performance from one workspace."
                icon={UsersRound}
                snapshotDescription={`Last synced ${lastSynced.toLocaleTimeString()}`}
                onRefresh={() => void handleRefresh()}
                isRefreshing={refreshing}
            >
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <DashboardMetricCard label="Active customers" value={stats.activeCustomers.toLocaleString()} description="Customers with activity this month" context="Audience" icon={UsersRound} />
                    <DashboardMetricCard label="Open conversations" value={stats.openConversations.toLocaleString()} description="Unread or unresolved customer messages" context="Support" icon={MessageCircle} tone="warning" />
                    <DashboardMetricCard label="Average rating" value={`${Number(stats.averageRating).toFixed(1)} / 5`} description={`${stats.pendingReviews.toLocaleString()} reviews waiting for attention`} context="Satisfaction" icon={Star} tone="success" />
                </div>

                <DashboardPanel eyebrow="Engagement" title="Customer engagement by channel" description="Conversation volume from primary CRM touchpoints.">
                    <DashboardTrendChart
                        title="Customer engagement by channel"
                        categories={engagementData.map((item) => item.channel)}
                        series={[{ name: 'Conversations', data: engagementData.map((item) => item.count) }]}
                        type="bar"
                        height={320}
                        options={{ plotOptions: { bar: { columnWidth: '45%', borderRadius: 6 } }, tooltip: { y: { formatter: (value) => `${value} conversations` } } }}
                        summary={`Customer engagement by channel: ${engagementData.map((item) => `${item.channel} ${item.count}`).join(', ') || 'no recorded conversations'}.`}
                    />
                </DashboardPanel>

                <DashboardPanel eyebrow="Support context" title="Recent customer interactions" description="Latest conversation updates and support context.">
                    {interactions.length === 0 ? (
                        <DashboardState status="empty" title="No recent interactions" message="New customer conversations will appear here when they are recorded." />
                    ) : (
                        <div className="max-h-80 space-y-3 overflow-y-auto pr-1">
                            {interactions.map((item) => (
                                <div key={item.conversation_id} className="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="text-sm font-semibold text-gray-950 dark:text-white">{item.customer_name}</p>
                                            <p className="mt-1 whitespace-pre-wrap break-words text-sm text-gray-600 dark:text-gray-400">{formatInteractionMessage(item.last_message)}</p>
                                        </div>
                                        <span className="shrink-0 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold capitalize text-gray-700 dark:bg-white/10 dark:text-gray-300">{item.status?.replace(/_/g, ' ') ?? 'open'}</span>
                                    </div>
                                    <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">{formatInteractionTime(item.last_message_at)}</p>
                                </div>
                            ))}
                        </div>
                    )}
                </DashboardPanel>
            </DashboardShell>
        </AppLayoutERP>
    );
}
