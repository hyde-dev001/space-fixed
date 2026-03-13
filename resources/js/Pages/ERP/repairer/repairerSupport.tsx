import { Head, router } from "@inertiajs/react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { useState, useRef, useEffect } from "react";
import axios from "axios";

interface Message {
  id: number;
  sender: "customer" | "repairer";
  senderType?: string;
  senderName: string;
  content: string;
  timestamp: string;
  createdAt?: string;
  images?: string[];
  parentMessageId?: number | null;
  parentMessage?: {
    id: number;
    sender: "customer" | "repairer";
    content: string;
    images?: string[];
  } | null;
}

interface Ticket {
  id: number;
  customerId: number;
  customerName: string;
  customerAvatar?: string | null;
  shopName?: string;
  shopAvatar?: string | null;
  customerRole: string;
  lastMessage: string;
  lastMessageTime: string;
  status: "active" | "idle" | "offline";
  messages: Message[];
  priority?: string;
  conversationStatus?: string;
  transferNote?: string;
  transferredFrom?: string;
  order?: {
    id?: number;
    order_number?: string;
    status?: string;
    payment_status?: string;
    total_amount?: number;
  };
  repairRequest?: {
    request_id: string;
    repair_type: string;
    description: string;
    status: string;
  };
}

export default function RepairerSupport() {
  const [tickets, setTickets] = useState<Ticket[]>([]);
  const [selectedTicketId, setSelectedTicketId] = useState<number | null>(null);
  const [newMessage, setNewMessage] = useState("");
  const [searchQuery, setSearchQuery] = useState("");
  const [selectedImages, setSelectedImages] = useState<File[]>([]);
  const [fullscreenImage, setFullscreenImage] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [sendingMessage, setSendingMessage] = useState(false);
  const [statusFilter, setStatusFilter] = useState<string>("all");
  const [showTransferNoteModal, setShowTransferNoteModal] = useState(false);
  const [notification, setNotification] = useState<{ type: 'success' | 'error'; message: string } | null>(null);
  const [orderStatusByNumber, setOrderStatusByNumber] = useState<Record<string, string>>({});
  const [hoveredMessageId, setHoveredMessageId] = useState<number | null>(null);
  const [replyingToMessage, setReplyingToMessage] = useState<Message | null>(null);
  const [reactionPickerMessageId, setReactionPickerMessageId] = useState<number | null>(null);
  const [messageReactions, setMessageReactions] = useState<Record<number, string>>({});
  const quickReactionList = ['❤️', '😂', '😍', '😮', '😢', '😡', '👍', '🎉'];
  
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const normalizePhotoPath = (path?: string | null) => {
    if (!path) return null;
    if (
      path.startsWith("http://") ||
      path.startsWith("https://") ||
      path.startsWith("data:") ||
      path.startsWith("blob:")
    ) {
      return path;
    }
    if (path.startsWith("/")) {
      return path;
    }
    if (path.startsWith("storage/")) {
      return `/${path}`;
    }
    return `/storage/${path}`;
  };

  const getRequestedConversationId = () => {
    const conversationIdParam = new URLSearchParams(window.location.search).get('conversation_id');
    const parsedConversationId = conversationIdParam ? Number(conversationIdParam) : NaN;
    return Number.isInteger(parsedConversationId) && parsedConversationId > 0 ? parsedConversationId : null;
  };

  const getVisiblePreviewText = (rawContent?: string) => {
    const content = String(rawContent || "");
    const isOrderSystemMessage =
      content.includes("New Order Placed") ||
      content.includes("**Order Number:**") ||
      content.includes("Order Number:");

    if (isOrderSystemMessage) {
      return "Repair update available";
    }

    return content || "No messages yet";
  };

  useEffect(() => {
    fetchConversations();
  }, [statusFilter]);

  const fetchConversations = async () => {
    try {
      setLoading(true);
      const params = statusFilter !== "all" ? { status: statusFilter } : {};
      const response = await axios.get("/api/repairer/conversations", { params });
      
      // Handle both paginated and direct array responses
      const conversations = Array.isArray(response.data) ? response.data : (response.data.data || []);
      
      if (!Array.isArray(conversations)) {
        console.error("Unexpected API response format:", response.data);
        setTickets([]);
        setSelectedTicketId(null);
        return;
      }
      
      const conversationsData = conversations.map((conv: any) => {
        // Preserve existing messages if this ticket is already loaded
        const existingTicket = tickets.find((t) => t.id === conv.id);
        const shopName = conv.shop_owner?.business_name || "Shop";
        const shopAvatar = normalizePhotoPath(conv.shop_owner?.profile_photo);
        const customerAvatar = normalizePhotoPath(
          conv.customer?.profile_photo_url || conv.customer?.profile_photo
        );

        return {
          id: conv.id,
          customerId: conv.customer?.id,
          customerName: conv.customer?.name || "Unknown",
          customerAvatar,
          shopName,
          shopAvatar,
          customerRole: conv.customer?.email || "",
          lastMessage: getVisiblePreviewText(conv.messages?.[0]?.content),
          lastMessageTime: formatTime(conv.last_message_at),
          status: "active",
          messages: existingTicket?.messages || [], // Keep existing messages
          priority: conv.priority,
          conversationStatus: conv.status,
          transferNote: conv.transfer_note,
          transferredFrom: conv.transferred_from_name,
          order: conv.order || undefined,
          repairRequest: conv.repairRequest || undefined,
        };
      });
      
      setTickets(conversationsData);

      const requestedConversationId = getRequestedConversationId();
      if (requestedConversationId && conversationsData.find((ticket: any) => ticket.id === requestedConversationId)) {
        setSelectedTicketId(requestedConversationId);
        window.history.replaceState({}, '', '/erp/staff/repairer-support');
        return;
      }
      
      // Only update selection if current selection is not in the new list
      if (selectedTicketId && conversationsData.find((t) => t.id === selectedTicketId)) {
        // Keep current selection if it's still in the filtered list
      } else if (conversationsData.length > 0) {
        // Select first conversation only if no current selection
        setSelectedTicketId(conversationsData[0].id);
      } else {
        setSelectedTicketId(null);
      }
    } catch (error) {
      console.error("Error fetching conversations:", error);
      setTickets([]);
      setSelectedTicketId(null);
    } finally {
      setLoading(false);
    }
  };

  const fetchOrderStatuses = async (silent = true) => {
    try {
      const params = statusFilter !== "all" ? { status: statusFilter } : {};
      const response = await axios.get("/api/repairer/conversations", { params });
      const conversations = Array.isArray(response.data) ? response.data : (response.data.data || []);

      if (!Array.isArray(conversations)) {
        return;
      }

      const nextMap: Record<string, string> = {};
      conversations.forEach((conv: any) => {
        const orderNumber = String(conv?.order?.order_number || "").trim().toUpperCase();
        const rawStatus = conv?.order?.status;
        const normalizedStatus = typeof rawStatus === 'object' && rawStatus !== null
          ? String((rawStatus as any).value || '')
          : String(rawStatus || '');

        if (orderNumber && normalizedStatus) {
          nextMap[orderNumber] = normalizedStatus;
        }
      });

      setOrderStatusByNumber(nextMap);
    } catch (error) {
      if (!silent) {
        console.error("Error fetching order statuses:", error);
      }
    }
  };

  const getInitials = (name: string) => {
    return name
      .split(" ")
      .map((n) => n[0])
      .join("")
      .toUpperCase()
      .substring(0, 2);
  };

  const formatTime = (dateString: string | null) => {
    if (!dateString) return "";
    const date = new Date(dateString);
    const now = new Date();
    const diff = now.getTime() - date.getTime();
    const minutes = Math.floor(diff / 60000);
    const hours = Math.floor(minutes / 60);
    const days = Math.floor(hours / 24);

    if (minutes < 1) return "just now";
    if (minutes < 60) return `${minutes} mins`;
    if (hours < 24) return `${hours} hours`;
    return `${days} days`;
  };

  const resolveAttachmentUrl = (path: string) => {
    if (!path) return path;
    if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('data:') || path.startsWith('blob:')) {
      return path;
    }
    if (path.startsWith('/')) {
      return path;
    }
    if (path.startsWith('storage/')) {
      return `/${path}`;
    }
    return `/storage/${path}`;
  };

  const parseOrderProducts = (content: string, itemsSummary: string): Array<{ name: string; quantity: string; unitPrice?: string }> => {
    const productsBlockMatch = content.match(/\*\*Products:\*\*\s*([\s\S]*?)(?=\n\*\*|$)/);

    if (productsBlockMatch?.[1]) {
      return productsBlockMatch[1]
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line.startsWith('-'))
        .map((line) => {
          const cleanedLine = line.replace(/^-\s*/, '').trim();
          const productMatch = cleanedLine.match(/^(.*?)\s+x(\d+)(?:\s+\(₱?([\d,]+(?:\.\d{1,2})?)\))?$/);

          if (!productMatch) {
            return {
              name: cleanedLine,
              quantity: '1',
            };
          }

          return {
            name: productMatch[1].trim(),
            quantity: productMatch[2].trim(),
            unitPrice: productMatch[3]?.trim(),
          };
        });
    }

    if (!itemsSummary) return [];

    return itemsSummary
      .split(',')
      .map((item) => item.trim())
      .filter(Boolean)
      .map((item) => {
        const summaryMatch = item.match(/^(.*?)\s+x(\d+)$/);
        if (!summaryMatch) {
          return {
            name: item,
            quantity: '1',
          };
        }
        return {
          name: summaryMatch[1].trim(),
          quantity: summaryMatch[2].trim(),
        };
      });
  };

  const renderAvatar = (label: string, tone: 'customer' | 'repairer') => {
    const classes = tone === 'repairer'
      ? 'bg-gray-900 text-white'
      : 'bg-blue-100 text-blue-700';

    return (
      <div className={`w-7 h-7 rounded-full ${classes} flex items-center justify-center text-[11px] font-semibold`}>
        {label}
      </div>
    );
  };

  const renderShopAvatar = (shopAvatar?: string | null, shopName?: string) => {
    if (shopAvatar) {
      return (
        <img
          src={shopAvatar}
          alt={shopName || "Shop"}
          className="w-7 h-7 rounded-full object-cover"
        />
      );
    }

    return renderAvatar(getInitials(shopName || "Shop"), 'repairer');
  };

  const renderCustomerAvatar = (customerAvatar?: string | null, customerName?: string, sizeClass = "w-12 h-12") => {
    if (customerAvatar) {
      return (
        <img
          src={customerAvatar}
          alt={customerName || "Customer"}
          className={`${sizeClass} rounded-full object-cover`}
        />
      );
    }

    return (
      <div className={`${sizeClass} rounded-full bg-gray-200 overflow-hidden flex items-center justify-center text-gray-700 font-semibold text-sm`}>
        {getInitials(customerName || "Customer")}
      </div>
    );
  };

  const getReplySenderLabel = (message: NonNullable<Message['parentMessage']>) => {
    return message.sender === 'repairer'
      ? 'You'
      : selectedTicket?.customerName || 'Customer';
  };

  const getReplyPreviewText = (message: Pick<Message, 'content' | 'images'>) => {
    if (message.content?.trim()) {
      return message.content.trim();
    }

    const attachmentCount = message.images?.length || 0;
    if (attachmentCount > 1) return `${attachmentCount} photos`;
    if (attachmentCount === 1) return 'Photo';
    return 'Message';
  };

  const getReplyContextLabel = (message: Message) => {
    if (!message.parentMessage) return null;

    const targetName = selectedTicket?.shopName || selectedTicket?.customerName || 'Shop';

    if (message.sender === 'repairer') {
      return message.parentMessage.sender === 'repairer'
        ? 'You replied to yourself'
        : `You replied to ${targetName}`;
    }

    return message.parentMessage.sender === 'repairer'
      ? `${targetName} replied to you`
      : `${targetName} replied to themselves`;
  };

  const handleReply = (message: Message) => {
    setReplyingToMessage(message);
    setReactionPickerMessageId(null);
  };

  const clearReply = () => {
    setReplyingToMessage(null);
  };

  const handleReactToMessage = (messageId: number, emoji: string) => {
    setMessageReactions((prev) => {
      if (prev[messageId] === emoji) {
        const { [messageId]: _removed, ...rest } = prev;
        return rest;
      }
      return { ...prev, [messageId]: emoji };
    });
    setReactionPickerMessageId(null);
  };

  const renderQuotedReply = (message: Message, isOutgoing: boolean) => {
    if (!message.parentMessage) return null;

    return (
      <div className={`rounded-2xl px-3 py-2 border ${isOutgoing ? 'bg-blue-50 border-blue-200 text-blue-800' : 'bg-white border-gray-300 text-gray-700'}`}>
        <p className={`text-[11px] font-semibold ${isOutgoing ? 'text-blue-700' : 'text-gray-500'}`}>
          {getReplySenderLabel(message.parentMessage)}
        </p>
        <p className={`text-xs mt-1 whitespace-nowrap overflow-hidden text-ellipsis ${isOutgoing ? 'text-blue-900' : 'text-gray-600'}`}>
          {getReplyPreviewText(message.parentMessage)}
        </p>
      </div>
    );
  };

  const systemCardWidthClass = "w-[26rem] max-w-[calc(100vw-9rem)]";

  useEffect(() => {
    if (selectedTicketId) {
      fetchConversationMessages(selectedTicketId, true);

      // Set up auto-refresh to poll for new messages every 3 seconds
      const pollInterval = setInterval(() => {
        fetchConversationMessages(selectedTicketId, false);
      }, 3000);

      // Clean up interval on unmount or when conversation changes
      return () => clearInterval(pollInterval);
    }
  }, [selectedTicketId]);

  useEffect(() => {
    fetchOrderStatuses(false);

    const orderStatusInterval = setInterval(() => {
      fetchOrderStatuses(true);
    }, 3000);

    return () => clearInterval(orderStatusInterval);
  }, [statusFilter]);

  const fetchConversationMessages = async (conversationId: number, scrollAfterLoad = false) => {
    try {
      const response = await axios.get(`/api/repairer/conversations/${conversationId}`);
      const conv = response.data;
      
      if (!conv || !conv.messages) {
        console.error("Invalid conversation data structure:", conv);
        setTickets((prev) =>
          prev.map((ticket) =>
            ticket.id === conversationId ? { ...ticket, messages: [] } : ticket
          )
        );
        return;
      }
      
      const messages = (Array.isArray(conv.messages) ? conv.messages : []).map((msg: any) => ({
        id: msg.id,
        sender: msg.sender_type === "customer" ? "customer" : "repairer",
        senderType: msg.sender_type,
        senderName: msg.sender_type === "customer" ? (conv.customer?.name || "Customer") : "You",
        content: msg.content || "",
        timestamp: new Date(msg.created_at).toLocaleTimeString("en-US", {
          hour: "2-digit",
          minute: "2-digit",
          hour12: true,
        }),
        createdAt: msg.created_at,
        images: msg.attachments || undefined,
        parentMessageId: msg.parent_message_id || null,
        parentMessage: msg.parent_message
          ? {
              id: msg.parent_message.id,
              sender: msg.parent_message.sender_type === 'customer' ? 'customer' : 'repairer',
              content: msg.parent_message.content || '',
              images: msg.parent_message.attachments || undefined,
            }
          : null,
      }));

      setTickets((prev) =>
        prev.map((ticket) =>
          ticket.id === conversationId ? { ...ticket, messages } : ticket
        )
      );

      if (scrollAfterLoad) {
        setTimeout(() => {
          scrollToBottom();
        }, 0);
      }
    } catch (error) {
      console.error("Error fetching messages:", error);
      // Set empty messages on error
      setTickets((prev) =>
        prev.map((ticket) =>
          ticket.id === conversationId ? { ...ticket, messages: [] } : ticket
        )
      );
    }
  };

  const selectedTicket = tickets.find((t) => t.id === selectedTicketId);

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  };

  const handleSendMessage = async () => {
    if ((newMessage.trim() || selectedImages.length > 0) && selectedTicket) {
      try {
        setSendingMessage(true);

        const formData = new FormData();
        if (newMessage.trim()) {
          formData.append("content", newMessage);
        }
        if (replyingToMessage?.id) {
          formData.append('parent_message_id', String(replyingToMessage.id));
        }
        selectedImages.forEach((file) => {
          formData.append("images[]", file);
        });

        const response = await axios.post(
          `/api/repairer/conversations/${selectedTicketId}/messages`,
          formData,
          {
            headers: { "Content-Type": "multipart/form-data" },
          }
        );

        const messageData = response.data.data;
        const newMsg: Message = {
          id: messageData.id,
          sender: "repairer",
          senderName: "You",
          content: messageData.content || "",
          timestamp: new Date(messageData.created_at).toLocaleTimeString("en-US", {
            hour: "2-digit",
            minute: "2-digit",
            hour12: true,
          }),
          createdAt: messageData.created_at,
          images: messageData.attachments || undefined,
          parentMessageId: messageData.parent_message_id || null,
          parentMessage: messageData.parent_message
            ? {
                id: messageData.parent_message.id,
                sender: messageData.parent_message.sender_type === 'customer' ? 'customer' : 'repairer',
                content: messageData.parent_message.content || '',
                images: messageData.parent_message.attachments || undefined,
              }
            : null,
        };

        setTickets((prev) =>
          prev.map((ticket) =>
            ticket.id === selectedTicketId
              ? {
                  ...ticket,
                  lastMessage: newMessage || `${selectedImages.length} image(s)`,
                  lastMessageTime: "now",
                  messages: [...ticket.messages, newMsg],
                }
              : ticket
          )
        );

        setNewMessage("");
        setSelectedImages([]);
        setReplyingToMessage(null);
      } catch (error) {
        console.error("Error sending message:", error);
        setNotification({ type: 'error', message: 'Failed to send message. Please try again.' });
        setTimeout(() => setNotification(null), 3000);
      } finally {
        setSendingMessage(false);
      }
    }
  };

  const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = e.target.files;
    if (files) {
      const imageFiles = Array.from(files).filter((file) =>
        file.type.startsWith("image/")
      );
      
      if (imageFiles.length > 0) {
        setSelectedImages((prev) => [...prev, ...imageFiles]);
      }
      
      // Reset the input so the same file can be selected again
      if (fileInputRef.current) {
        fileInputRef.current.value = "";
      }
    }
  };

  const handleUploadClick = () => {
    fileInputRef.current?.click();
  };

  const removeImage = (index: number) => {
    setSelectedImages((prev) => prev.filter((_, i) => i !== index));
  };

  const getStatusColor = (status: string) => {
    switch (status) {
      case "active":
        return "bg-green-500";
      case "idle":
        return "bg-yellow-500";
      case "offline":
        return "bg-gray-400";
      default:
        return "bg-gray-400";
    }
  };

  const getStatusText = (status: string) => {
    switch (status) {
      case "active":
        return "Active now";
      case "idle":
        return "Away";
      case "offline":
        return "Offline";
      default:
        return "Offline";
    }
  };

  const filteredTickets = tickets.filter((ticket) =>
    ticket.customerName.toLowerCase().includes(searchQuery.toLowerCase())
  );

  return (
    <AppLayoutERP hideHeader={!!fullscreenImage} fullBleed>
      
      {/* Notification Toast */}
      {notification && (
        <div className="fixed top-20 right-4 z-999999 animate-in slide-in-from-top-2">
          <div className={`flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg ${
            notification.type === 'success' 
              ? 'bg-green-500 text-white' 
              : 'bg-red-500 text-white'
          }`}>
            {notification.type === 'success' ? (
              <svg className="w-5 h-5 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
              </svg>
            ) : (
              <svg className="w-5 h-5 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
              </svg>
            )}
            <span className="text-sm font-medium">{notification.message}</span>
            <button
              onClick={() => setNotification(null)}
              className="ml-2 hover:opacity-80 transition-opacity"
              title="Close notification"
              aria-label="Close notification"
            >
              <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
              </svg>
            </button>
          </div>
        </div>
      )}
      
      <Head title="Technical Support - Solespace ERP" />
      <div className="h-[calc(100dvh-84px)] box-border flex flex-col bg-gray-50 overflow-hidden px-2 pb-2 pt-1">
        <div className="flex gap-2 h-full min-h-0">
          {/* Left Sidebar - Chat List */}
          <div className="w-80 h-full min-h-0 shrink-0 bg-white border border-gray-200 rounded-xl flex flex-col overflow-hidden">
            {/* Header */}
            <div className="border-b border-gray-200 px-6 py-4">
              <h1 className="text-2xl font-bold text-black mb-3">Technical Support</h1>
              
              {/* Search and Filter */}
              <div className="flex items-center gap-3">
                {/* Search */}
                <div className="relative flex-1">
                  <svg
                    className="absolute left-3 top-3 w-5 h-5 text-gray-400"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                  </svg>
                  <input
                    type="text"
                    placeholder="Search..."
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="w-full pl-10 pr-4 py-2 bg-gray-50 border border-gray-200 rounded-xl text-sm text-gray-900 placeholder-gray-400 outline-none focus:border-black transition-colors"
                  />
                </div>
                
                {/* Status Filter Dropdown */}
                <div className="relative group">
                  <button
                    className="p-2 hover:bg-gray-100 rounded-full transition-colors shrink-0"
                    title="Filter by status"
                  >
                    <svg className="w-5 h-5 text-gray-600" fill="currentColor" viewBox="0 0 24 24">
                      <path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z" />
                    </svg>
                  </button>
                  
                  {/* Dropdown Menu */}
                  <div className="absolute right-0 mt-1 w-48 bg-white rounded-lg shadow-lg border border-gray-200 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-20">
                    {["all", "open", "in_progress", "resolved"].map((status) => (
                      <button
                        key={status}
                        onClick={() => setStatusFilter(status)}
                        className={`w-full text-left px-4 py-3 text-sm transition-colors flex items-center gap-3 ${
                          statusFilter === status
                            ? "bg-blue-50 text-blue-700 font-medium border-l-2 border-l-blue-500"
                            : "text-gray-700 hover:bg-gray-50"
                        }`}
                      >
                        <span className={`w-2 h-2 rounded-full ${
                          statusFilter === status ? "bg-blue-500" : "bg-gray-300"
                        }`} />
                        {status.replace("_", " ").toUpperCase()}
                      </button>
                    ))}
                  </div>
                </div>
              </div>
            </div>

            {/* Ticket List */}
            <div className="flex-1 min-h-0 overflow-y-auto">
              {loading ? (
                <div className="flex items-center justify-center h-full text-gray-500">
                  <div className="text-center">
                    <svg className="animate-spin h-8 w-8 text-purple-500 mx-auto mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <p className="text-sm">Loading conversations...</p>
                  </div>
                </div>
              ) : filteredTickets.length === 0 ? (
                <div className="flex items-center justify-center h-full text-gray-500">
                  <p className="text-sm">No technical support requests</p>
                </div>
              ) : (
                filteredTickets.map((ticket) => (
                <div
                  key={ticket.id}
                  onClick={() => setSelectedTicketId(ticket.id)}
                  className={`px-6 py-4 border-b border-gray-100 cursor-pointer transition-colors hover:bg-gray-50 ${
                    selectedTicketId === ticket.id ? "bg-gray-50 border-l-4 border-l-blue-500" : ""
                  }`}
                >
                  <div className="flex items-start gap-3">
                    {/* Avatar */}
                    <div className="relative shrink-0">
                      {renderCustomerAvatar(ticket.customerAvatar, ticket.customerName)}
                      <div className={`absolute bottom-0 right-0 w-3 h-3 rounded-full border-2 border-white ${getStatusColor(ticket.status)}`} />
                    </div>

                    {/* Content */}
                    <div className="flex-1 min-w-0">
                      <div className="flex items-baseline justify-between">
                        <h3 className="font-semibold text-black text-sm truncate">
                          {ticket.customerName}
                        </h3>
                        <span className="text-xs text-gray-500 ml-2 shrink-0">{ticket.lastMessageTime}</span>
                      </div>
                      <p className="text-xs text-gray-500 truncate">{getStatusText(ticket.status)}</p>
                      {ticket.transferredFrom && (
                        <p className="text-xs text-blue-600 font-medium mt-0.5">
                          ↳ From: {ticket.transferredFrom}
                        </p>
                      )}
                      <p className="text-xs text-gray-600 truncate mt-1">{ticket.lastMessage}</p>
                    </div>
                  </div>
                </div>
              ))
              )}
            </div>
          </div>

          {/* Right Side - Chat Area */}
          {selectedTicket ? (
            <div className="flex-1 min-w-0 min-h-0 flex flex-col bg-white border border-gray-200 rounded-xl overflow-hidden">
              {/* Header */}
              <div className="border-b border-gray-200 px-6 py-4 flex items-center justify-between bg-white relative z-10">
                <div className="flex items-center gap-4">
                  <div className="relative">
                    {renderCustomerAvatar(selectedTicket.customerAvatar, selectedTicket.customerName)}
                    <div className={`absolute bottom-0 right-0 w-3.5 h-3.5 rounded-full border-2 border-white ${getStatusColor(selectedTicket.status)}`} />
                  </div>
                  <div>
                    <h2 className="text-lg font-bold text-black">
                      {selectedTicket.customerName}
                    </h2>
                    <p className="text-xs text-gray-500">{getStatusText(selectedTicket.status)}</p>
                  </div>
                </div>
                
                {/* Action Buttons */}
                <div className="flex items-center gap-2 relative z-20">
                  {selectedTicket.transferNote && (
                    <button
                      onClick={() => setShowTransferNoteModal(true)}
                      className="p-1.5 hover:bg-blue-100 rounded-lg transition-colors text-blue-600 hover:text-blue-700"
                      title="View transfer note"
                    >
                      <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                    </button>
                  )}
                </div>
              </div>

              {/* Messages */}
              <div className="flex-1 min-h-0 overflow-y-auto px-6 py-6 space-y-4 bg-white">
                {selectedTicket.messages.length === 0 ? (
                  <div className="flex items-center justify-center h-full text-gray-500">
                    <p>No messages yet</p>
                  </div>
                ) : (
                  <>
                    {selectedTicket.messages.map((message) => {
                    const customerName = selectedTicket.customerName || "Customer";
                    const customerAvatarUrl = selectedTicket.customerAvatar;
                    const shopAvatarUrl = selectedTicket.shopAvatar;
                    const shopName = selectedTicket.shopName || "Shop";

                    // System messages (order/repair notifications)
                    if (message.senderType === 'system') {
                      const content = message.content || '';
                      const isRepairMessage = content.includes('Repair') || content.includes('Type:**');
                      const isOrderMessage = content.includes('New Order Placed') || content.includes('Order Number:');

                      const typeMatch = content.match(/\*\*Type:\*\*\s*(.+?)(?=\*\*|$)/);
                      const itemMatch = content.match(/\*\*Item:\*\*\s*(.+?)(?=\*\*|$)/);
                      const deliveryMatch = content.match(/\*\*Delivery:\*\*\s*(.+?)(?=\*\*|$)/);
                      const estimateMatch = content.match(/\*\*Estimate:\*\*\s*₱?(.+?)(?=\*\*|$)/);
                      const statusMatch = content.match(/\*\*Status:\*\*\s*(.+?)(?=\*\*|$)/);

                      const repairType = typeMatch ? typeMatch[1].trim() : 'Repair';
                      const itemType = itemMatch ? itemMatch[1].trim() : '';
                      const deliveryType = deliveryMatch ? deliveryMatch[1].trim() : '';
                      const estimateValue = estimateMatch ? estimateMatch[1].trim() : '';
                      const status = statusMatch ? statusMatch[1].trim() : '';

                      const itemsMatch = content.match(/\*\*Items:\*\*\s*(.+?)(?=\n|$)/);
                      const orderNumberMatch = content.match(/\*\*Order Number:\*\*\s*(.+?)(?=\n|$)/);
                      const totalMatch = content.match(/\*\*Total:\*\*\s*₱?(.+?)(?=\n|$)/);
                      const orderStatusMatch = content.match(/\*\*Status:\*\*\s*(.+?)(?=\n|$)/);

                      const items = itemsMatch ? itemsMatch[1].trim() : '';
                      const orderNumber = orderNumberMatch ? orderNumberMatch[1].trim() : '';
                      const total = totalMatch ? totalMatch[1].trim() : '';
                      const orderStatus = orderStatusMatch ? orderStatusMatch[1].trim() : '';
                      const orderProducts = parseOrderProducts(content, items);

                      const normalizedMessageOrderNumber = orderNumber.trim().toUpperCase();
                      const normalizedConversationOrderNumber = String(selectedTicket?.order?.order_number || '').trim().toUpperCase();
                      const isSameOrderAsConversation =
                        normalizedMessageOrderNumber.length > 0 &&
                        normalizedConversationOrderNumber.length > 0 &&
                        normalizedMessageOrderNumber === normalizedConversationOrderNumber;

                      const liveOrderStatus = isSameOrderAsConversation && selectedTicket?.order?.status
                        ? String(selectedTicket.order.status).replace(/_/g, ' ')
                        : '';

                      const mappedOrderStatus = normalizedMessageOrderNumber
                        ? (orderStatusByNumber[normalizedMessageOrderNumber] || '')
                        : '';

                      const effectiveOrderStatus =
                        (mappedOrderStatus ? mappedOrderStatus.replace(/_/g, ' ') : '') ||
                        liveOrderStatus ||
                        orderStatus;

                      const getOrderStatusColor = (value: string) => {
                        const normalized = value.toLowerCase();
                        if (normalized.includes('pending')) return 'bg-yellow-100 text-yellow-800';
                        if (normalized.includes('processing')) return 'bg-blue-100 text-blue-800';
                        if (normalized.includes('shipped')) return 'bg-purple-100 text-purple-800';
                        if (normalized.includes('delivered')) return 'bg-green-100 text-green-800';
                        if (normalized.includes('cancel')) return 'bg-red-100 text-red-800';
                        return 'bg-gray-100 text-gray-800';
                      };

                      if (isOrderMessage) {
                        return null;
                      }

                      if (isRepairMessage) {
                        const displayDate = message.createdAt
                          ? new Date(message.createdAt).toLocaleString('en-US', {
                              month: 'short',
                              day: 'numeric',
                              year: 'numeric',
                              hour: '2-digit',
                              minute: '2-digit'
                            })
                          : message.timestamp;

                        return (
                          <div key={message.id} className="flex justify-end my-6">
                            <div className="flex items-start gap-3 flex-row-reverse">
                              {renderShopAvatar(shopAvatarUrl, shopName)}
                            <div className={`bg-white border border-gray-200 rounded-xl p-5 shadow-sm hover:shadow-md transition-shadow ${systemCardWidthClass}`}>
                              <div className="flex items-start justify-between mb-4">
                                <div className="flex-1">
                                  <h3 className="text-base font-semibold text-gray-900 mb-1 flex items-center gap-2">
                                    <span>{content.includes('Accepted') ? 'Repair Order Accepted' : 'Repair Update'}</span>
                                  </h3>
                                  <p className="text-xs text-gray-500">{displayDate}</p>
                                </div>
                                {status && (
                                  <span className="text-xs font-semibold px-2.5 py-1 rounded-full bg-green-100 text-green-800">
                                    {status}
                                  </span>
                                )}
                              </div>

                              <div className="bg-gray-50 rounded-lg p-4 mb-4 space-y-3">
                                <div className="flex items-start gap-3">
                                  <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Service:</span>
                                  <span className="text-sm text-gray-900 font-medium">{repairType}</span>
                                </div>
                                {itemType && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Item:</span>
                                    <span className="text-sm text-gray-900 font-medium">{itemType}</span>
                                  </div>
                                )}
                                {deliveryType && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Delivery:</span>
                                    <span className="text-sm text-gray-900">{deliveryType}</span>
                                  </div>
                                )}
                                {estimateValue && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Estimate:</span>
                                    <span className="text-sm text-gray-900 font-semibold">₱{estimateValue}</span>
                                  </div>
                                )}
                              </div>

                              <div className="border-t border-gray-100 pt-3 mb-3">
                                <div className="flex items-center gap-3">
                                  {shopAvatarUrl ? (
                                    <img
                                      src={shopAvatarUrl}
                                      alt={shopName}
                                      className="w-10 h-10 rounded-full object-cover"
                                    />
                                  ) : (
                                    <div className="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center shrink-0 text-blue-700 text-xs font-semibold">
                                      {getInitials(shopName)}
                                    </div>
                                  )}
                                  <div className="flex-1">
                                    <p className="text-sm font-semibold text-gray-900">{shopName}</p>
                                  </div>
                                </div>
                              </div>

                              <div className="bg-blue-50 rounded-lg px-3 py-2.5 mb-4">
                                <p className="text-xs text-blue-900 leading-relaxed">
                                  💡 We'll keep you updated on the progress of your repair.
                                </p>
                              </div>

                              <button
                                onClick={() => router.visit('/erp/staff/job-orders-repair')}
                                className="w-full bg-white hover:bg-gray-50 text-black border border-gray-300 font-semibold py-2.5 px-4 rounded-lg transition-all duration-200 text-sm shadow-sm hover:shadow-md"
                              >
                                View Full Details
                              </button>
                            </div>
                            </div>
                          </div>
                        );
                      }
                    }

                    // Regular messages
                    return (
                      <div
                        key={message.id}
                        className={`flex ${message.sender === "repairer" ? "justify-end" : "justify-start"}`}
                        onMouseEnter={() => setHoveredMessageId(message.id)}
                        onMouseLeave={() => setHoveredMessageId(null)}
                      >
                        <div className={`flex items-start gap-2 ${message.sender === "repairer" ? "flex-row-reverse" : ""}`}>
                          {message.sender === "repairer"
                            ? renderShopAvatar(shopAvatarUrl, shopName)
                            : renderCustomerAvatar(customerAvatarUrl, customerName, "w-7 h-7")}
                          <div className={`flex flex-col ${message.sender === "repairer" ? "items-end" : "items-start"}`}>
                            {message.images && message.images.length > 0 ? (
                              <div className="relative group">
                                <div className="flex flex-wrap gap-2 mb-2">
                                  {message.images.map((img, idx) => (
                                    <img
                                      key={idx}
                                      src={resolveAttachmentUrl(img)}
                                      alt={`Attachment ${idx + 1}`}
                                      className="rounded-lg max-w-37.5 max-h-37.5 object-cover cursor-pointer hover:opacity-80 transition-opacity"
                                      onClick={() => setFullscreenImage(resolveAttachmentUrl(img))}
                                    />
                                  ))}
                                </div>
                                {(message.content || message.parentMessage) && (
                                  <div className={`flex flex-col gap-1 max-w-xs lg:max-w-md xl:max-w-lg ${message.sender === 'repairer' ? 'items-end' : 'items-start'}`}>
                                    {message.parentMessage && <p className="text-[11px] text-gray-500 px-1">{getReplyContextLabel(message)}</p>}
                                    {renderQuotedReply(message, message.sender === 'repairer')}
                                    {message.content && (
                                      <div className={message.sender === "repairer" ? "bg-blue-500 text-white px-4 py-2 text-sm rounded-lg shadow-sm inline-block w-fit max-w-full" : "bg-gray-100 text-gray-900 px-4 py-2 text-sm rounded-lg shadow-sm inline-block w-fit max-w-full"}>
                                        <p className="wrap-break-word text-sm leading-relaxed">{message.content}</p>
                                      </div>
                                    )}
                                  </div>
                                )}
                                {hoveredMessageId === message.id && message.senderType !== 'system' && (
                                  <div className={`absolute top-0 ${message.sender === 'repairer' ? 'right-full mr-2' : 'left-full ml-2'} flex gap-1 z-10`}>
                                    <button
                                      onClick={() => handleReply(message)}
                                      title="Reply"
                                      className="w-8 h-8 flex items-center justify-center rounded-full bg-white border border-gray-200 shadow-sm hover:bg-gray-100 transition-colors"
                                    >
                                      <svg className="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 10l-4 4 4 4" />
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 14h10a5 5 0 015 5" />
                                      </svg>
                                    </button>
                                    <button
                                      onClick={() => setReactionPickerMessageId((prev) => (prev === message.id ? null : message.id))}
                                      title="React with emoji"
                                      className="w-8 h-8 flex items-center justify-center rounded-full bg-white border border-gray-200 shadow-sm hover:bg-gray-100 transition-colors"
                                    >
                                      <svg className="w-4 h-4 text-gray-600" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11zm3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z" />
                                      </svg>
                                    </button>
                                  </div>
                                )}
                                {reactionPickerMessageId === message.id && (
                                  <div className={`absolute -top-12 ${message.sender === 'repairer' ? 'right-0' : 'left-0'} bg-white border border-gray-200 rounded-full shadow-md px-2 py-1 flex items-center gap-1 z-20`}>
                                    {quickReactionList.map((emoji) => (
                                      <button
                                        key={`${message.id}-${emoji}`}
                                        onClick={() => handleReactToMessage(message.id, emoji)}
                                        className="w-7 h-7 flex items-center justify-center rounded-full hover:bg-gray-100 transition-colors text-base"
                                      >
                                        {emoji}
                                      </button>
                                    ))}
                                  </div>
                                )}
                                {messageReactions[message.id] && (
                                  <button
                                    onClick={() => handleReactToMessage(message.id, messageReactions[message.id])}
                                    className={`absolute -bottom-3 ${message.sender === 'repairer' ? 'right-2' : 'left-2'} bg-white border border-gray-200 rounded-full px-2 py-0.5 text-sm shadow-sm hover:bg-gray-50 transition-colors`}
                                  >
                                    {messageReactions[message.id]}
                                  </button>
                                )}
                              </div>
                            ) : (
                              <div className="relative group">
                                <div className={`flex flex-col gap-1 max-w-xs lg:max-w-md xl:max-w-lg ${message.sender === 'repairer' ? 'items-end' : 'items-start'}`}>
                                  {message.parentMessage && <p className="text-[11px] text-gray-500 px-1">{getReplyContextLabel(message)}</p>}
                                  {renderQuotedReply(message, message.sender === 'repairer')}
                                  <div className={`${message.sender === "repairer" ? "bg-blue-500 text-white" : "bg-gray-100 text-gray-900"} inline-block w-fit max-w-full px-4 py-2 text-sm rounded-lg shadow-sm`}>
                                    <p className="wrap-break-word text-sm leading-relaxed">{message.content}</p>
                                  </div>
                                </div>
                                {hoveredMessageId === message.id && message.senderType !== 'system' && (
                                  <div className={`absolute top-0 ${message.sender === 'repairer' ? 'right-full mr-2' : 'left-full ml-2'} flex gap-1 z-10`}>
                                    <button
                                      onClick={() => handleReply(message)}
                                      title="Reply"
                                      className="w-8 h-8 flex items-center justify-center rounded-full bg-white border border-gray-200 shadow-sm hover:bg-gray-100 transition-colors"
                                    >
                                      <svg className="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 10l-4 4 4 4" />
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 14h10a5 5 0 015 5" />
                                      </svg>
                                    </button>
                                    <button
                                      onClick={() => setReactionPickerMessageId((prev) => (prev === message.id ? null : message.id))}
                                      title="React with emoji"
                                      className="w-8 h-8 flex items-center justify-center rounded-full bg-white border border-gray-200 shadow-sm hover:bg-gray-100 transition-colors"
                                    >
                                      <svg className="w-4 h-4 text-gray-600" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11zm3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z" />
                                      </svg>
                                    </button>
                                  </div>
                                )}
                                {reactionPickerMessageId === message.id && (
                                  <div className={`absolute -top-12 ${message.sender === 'repairer' ? 'right-0' : 'left-0'} bg-white border border-gray-200 rounded-full shadow-md px-2 py-1 flex items-center gap-1 z-20`}>
                                    {quickReactionList.map((emoji) => (
                                      <button
                                        key={`${message.id}-${emoji}`}
                                        onClick={() => handleReactToMessage(message.id, emoji)}
                                        className="w-7 h-7 flex items-center justify-center rounded-full hover:bg-gray-100 transition-colors text-base"
                                      >
                                        {emoji}
                                      </button>
                                    ))}
                                  </div>
                                )}
                                {messageReactions[message.id] && (
                                  <button
                                    onClick={() => handleReactToMessage(message.id, messageReactions[message.id])}
                                    className={`absolute -bottom-3 ${message.sender === 'repairer' ? 'right-2' : 'left-2'} bg-white border border-gray-200 rounded-full px-2 py-0.5 text-sm shadow-sm hover:bg-gray-50 transition-colors`}
                                  >
                                    {messageReactions[message.id]}
                                  </button>
                                )}
                              </div>
                            )}
                            <div className={`mt-2 ${message.sender === "repairer" ? "text-right" : "text-left"}`}>
                              <span className={`text-[11px] ${message.sender === "repairer" ? "text-gray-400" : "text-gray-500"}`}>
                                {message.timestamp}
                              </span>
                            </div>
                          </div>
                        </div>
                      </div>
                    );
                  })}
                    <div ref={messagesEndRef} />
                  </>
                )}
              </div>

              {/* Message Input */}
              <div className="border-t border-gray-200 bg-white px-6 py-4">
                {replyingToMessage && (
                  <div className="mb-3 bg-gray-50 border border-gray-200 rounded-2xl p-3 flex items-start justify-between shadow-sm">
                    <div className="flex-1 min-w-0">
                      <p className="text-xs text-gray-500 font-semibold mb-1">
                        Replying to {replyingToMessage.sender === 'repairer' ? 'You' : (selectedTicket?.shopName || selectedTicket?.customerName || 'Shop')}
                      </p>
                      <p className="text-sm text-gray-700 truncate">{getReplyPreviewText(replyingToMessage)}</p>
                    </div>
                    <button
                      onClick={clearReply}
                      className="ml-2 text-gray-400 hover:text-gray-600 shrink-0"
                      title="Cancel reply"
                    >
                      ×
                    </button>
                  </div>
                )}

                {/* Image Previews */}
                {selectedImages.length > 0 && (
                  <div className="mb-3 flex flex-wrap gap-2">
                    {selectedImages.map((file, index) => (
                      <div key={index} className="relative group">
                        <img
                          src={URL.createObjectURL(file)}
                          alt={`Preview ${index + 1}`}
                          className="w-20 h-20 object-cover rounded-lg border border-gray-200"
                        />
                        <button
                          onClick={() => removeImage(index)}
                          className="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity"
                          title="Remove image"
                        >
                          ×
                        </button>
                      </div>
                    ))}
                  </div>
                )}
                
                <div className="flex items-center gap-3">
                  <input
                    type="file"
                    ref={fileInputRef}
                    onChange={handleFileUpload}
                    accept="image/*"
                    multiple
                    className="hidden"
                    title="Upload images"
                    aria-label="Upload images"
                  />
                  <button 
                    onClick={handleUploadClick}
                    className="p-2 hover:bg-gray-100 rounded-full transition-colors shrink-0"
                    title="Upload images"
                  >
                    <svg className="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 5v14m7-7H5" />
                    </svg>
                  </button>

                  <div className="flex-1 bg-gray-50 border border-gray-200 rounded-2xl px-4 py-3 flex items-center">
                    <input
                      type="text"
                      value={newMessage}
                      onChange={(e) => setNewMessage(e.target.value)}
                      onKeyDown={(e) => {
                        if (e.key === "Enter") {
                          handleSendMessage();
                        }
                      }}
                      placeholder="Type a message"
                      className="bg-transparent flex-1 text-sm text-gray-900 placeholder-gray-400 outline-none"
                    />
                  </div>

                  <button
                    onClick={handleSendMessage}
                    disabled={(!newMessage.trim() && selectedImages.length === 0) || sendingMessage}
                    className={`p-2 rounded-full transition-colors shrink-0 ${
                      newMessage.trim() || selectedImages.length > 0
                        ? "text-gray-600 hover:text-gray-800"
                        : "text-gray-300 cursor-not-allowed"
                    }`}
                  >
                    {sendingMessage ? (
                      <svg className="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                      </svg>
                    ) : (
                      <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M16.6915026,12.4744748 L3.50612381,13.2599618 C3.19218622,13.2599618 3.03521743,13.4170592 3.03521743,13.5741566 L1.15159189,20.0151496 C0.8376543,20.8006365 0.99,21.89 1.77946707,22.52 C2.40,22.99 3.50612381,23.1 4.13399899,22.8429026 L21.714504,14.0454487 C22.6563168,13.5741566 23.1272231,12.6315722 22.9702544,11.6889879 L4.13399899,1.16134578 C3.34915502,0.9042484 2.40734225,1.00636533 1.77946707,1.4776575 C0.994623095,2.10604706 0.837654326,3.0486314 1.15159189,3.99701575 L3.03521743,10.4380088 C3.03521743,10.5951061 3.34915502,10.7522035 3.50612381,10.7522035 L16.6915026,11.5376905 C16.6915026,11.5376905 17.1624089,11.5376905 17.1624089,12.0089827 C17.1624089,12.4744748 16.6915026,12.4744748 16.6915026,12.4744748 Z" />
                      </svg>
                    )}
                  </button>
                </div>
              </div>
            </div>
          ) : (
            <div className="flex-1 min-w-0 min-h-0 flex items-center justify-center text-gray-500 bg-white border border-gray-200 rounded-xl">
              <p>Select a conversation to start chatting</p>
            </div>
          )}
        </div>
      </div>

      {/* Fullscreen Image Modal */}
      {fullscreenImage && (
        <div 
          className="fixed inset-0 backdrop-blur-md flex items-center justify-center z-50"
          onClick={() => setFullscreenImage(null)}
        >
          <button
            onClick={(e) => {
              e.stopPropagation();
              setFullscreenImage(null);
            }}
            className="absolute top-6 right-6 bg-white rounded-full w-10 h-10 flex items-center justify-center hover:bg-gray-100 transition-colors shadow-lg z-50"
            title="Close"
          >
            <svg className="w-6 h-6 text-gray-800" fill="currentColor" viewBox="0 0 24 24">
              <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z" />
            </svg>
          </button>
          <img
            src={fullscreenImage}
            alt="Fullscreen view"
            className="max-w-[90vw] max-h-[90vh] object-contain"
          />
        </div>
      )}

      {/* Transfer Note Modal (View Only) */}
      {showTransferNoteModal && selectedTicket?.transferNote && (
        <div 
          className="fixed inset-0 backdrop-blur-md flex items-center justify-center z-50"
          onClick={() => setShowTransferNoteModal(false)}
        >
          <div 
            className="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4"
            onClick={(e) => e.stopPropagation()}
          >
            {/* Modal Header */}
            <div className="border-b border-gray-200 px-6 py-4 flex items-center justify-between">
              <div>
                <h3 className="text-lg font-bold text-black">Transfer Note from CRM</h3>
                <p className="text-sm text-gray-500 mt-1">
                  From: {selectedTicket.transferredFrom || "CRM Team"}
                </p>
              </div>
              <button
                onClick={() => setShowTransferNoteModal(false)}
                className="text-gray-400 hover:text-gray-600 transition-colors"
                title="Close transfer note"
                aria-label="Close transfer note"
              >
                <svg className="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z" />
                </svg>
              </button>
            </div>

            {/* Modal Body */}
            <div className="px-6 py-4">
              <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <p className="text-sm text-gray-800 whitespace-pre-wrap">
                  {selectedTicket.transferNote}
                </p>
              </div>
            </div>

            {/* Modal Footer */}
            <div className="border-t border-gray-200 px-6 py-4 flex items-center justify-end">
              <button
                onClick={() => setShowTransferNoteModal(false)}
                className="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 transition-colors"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}

    </AppLayoutERP>
  );
}
