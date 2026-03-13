import { Head } from "@inertiajs/react";
import AppLayoutShopOwner from "../../../../layout/AppLayout_shopOwner";
import { useState, useRef, useEffect } from "react";
import axios from "axios";

interface Message {
  id: number;
  sender: "customer" | "staff";
  senderName: string;
  content: string;
  timestamp: string;
  images?: string[];
  parentMessageId?: number | null;
  parentMessage?: {
    id: number;
    sender: "customer" | "staff";
    content: string;
    images?: string[];
  } | null;
}

interface Ticket {
  id: number;
  customerId: number;
  customerName: string;
  customerAvatar: string;
  customerRole: string;
  lastMessage: string;
  lastMessageTime: string;
  status: "active" | "idle" | "offline";
  messages: Message[];
  priority?: string;
  conversationStatus?: string;
}

export default function CustomerSupport() {
  const [tickets, setTickets] = useState<Ticket[]>([]);
  const [selectedTicketId, setSelectedTicketId] = useState<number | null>(null);
  const [newMessage, setNewMessage] = useState("");
  const [searchQuery, setSearchQuery] = useState("");
  const [selectedImages, setSelectedImages] = useState<File[]>([]);
  const [fullscreenImage, setFullscreenImage] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [initialLoad, setInitialLoad] = useState(true);
  const [sendingMessage, setSendingMessage] = useState(false);
  const [statusFilter, setStatusFilter] = useState<string>("all");
  const [showTransferModal, setShowTransferModal] = useState(false);
  const [transferNote, setTransferNote] = useState("");
  const [isTransferring, setIsTransferring] = useState(false);
  const [notification, setNotification] = useState<{ type: 'success' | 'error'; message: string } | null>(null);
  const [hoveredMessageId, setHoveredMessageId] = useState<number | null>(null);
  const [replyingToMessage, setReplyingToMessage] = useState<Message | null>(null);
  const [reactionPickerMessageId, setReactionPickerMessageId] = useState<number | null>(null);
  const [messageReactions, setMessageReactions] = useState<Record<number, string>>({});
  const quickReactionList = ['❤️', '😂', '😍', '😮', '😢', '😡', '👍', '🎉'];
  
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    fetchConversations(false);
    
    // Auto-refresh conversations every 3 seconds (background, no loading spinner)
    const conversationInterval = setInterval(() => {
      fetchConversations(true);
    }, 3000);
    
    return () => clearInterval(conversationInterval);
  }, [statusFilter]);

  const fetchConversations = async (isBackgroundPoll = false) => {
    try {
      if (!isBackgroundPoll) setLoading(true);
      const params = statusFilter !== "all" ? { status: statusFilter } : {};
      const response = await axios.get("/api/shop-owner/conversations", { params });
      
      // Handle both paginated and direct array responses
      const conversations = Array.isArray(response.data) ? response.data : (response.data.data || []);
      
      if (!Array.isArray(conversations)) {
        console.error("Unexpected API response format:", response.data);
        setTickets([]);
        setSelectedTicketId(null);
        return;
      }
      
      setTickets((prevTickets) => {
        const conversationsData = conversations.map((conv: any) => {
          // Preserve existing messages if this ticket is already loaded
          const existingTicket = prevTickets.find((t) => t.id === conv.id);
          return {
            id: conv.id,
            customerId: conv.customer?.id,
            customerName: conv.customer?.name || "Unknown",
            customerAvatar: getInitials(conv.customer?.name || "Unknown"),
            customerRole: conv.customer?.email || "",
            lastMessage: conv.messages?.[0]?.content || "No messages yet",
            lastMessageTime: formatTime(conv.last_message_at),
            status: "active" as const,
            messages: existingTicket?.messages || [], // Keep existing messages
            priority: conv.priority,
            conversationStatus: conv.status,
          };
        });
        return conversationsData;
      });

      // Only update selection if current selection is not in the new list
      setSelectedTicketId((prevId) => {
        const updatedConvIds = conversations.map((c: any) => c.id);
        if (prevId && updatedConvIds.includes(prevId)) return prevId;
        return conversations.length > 0 ? conversations[0].id : null;
      });
    } catch (error) {
      console.error("Error fetching conversations:", error);
      if (!isBackgroundPoll) {
        setTickets([]);
        setSelectedTicketId(null);
      }
    } finally {
      setLoading(false);
      setInitialLoad(false);
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

  useEffect(() => {
    if (selectedTicketId) {
      fetchConversationMessages(selectedTicketId);
      
      // Auto-refresh messages every 2 seconds
      const messageInterval = setInterval(() => {
        fetchConversationMessages(selectedTicketId);
      }, 2000);
      
      return () => clearInterval(messageInterval);
    }
  }, [selectedTicketId]);

  const fetchConversationMessages = async (conversationId: number) => {
    try {
      const response = await axios.get(`/api/shop-owner/conversations/${conversationId}`);
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
        sender: msg.sender_type === "customer" ? "customer" : "staff",
        senderName: msg.sender_type === "customer" ? (conv.customer?.name || "Customer") : "You",
        content: msg.content || "",
        timestamp: new Date(msg.created_at).toLocaleTimeString("en-US", {
          hour: "2-digit",
          minute: "2-digit",
          hour12: true,
        }),
        images: msg.attachments || undefined,
        parentMessageId: msg.parent_message_id || null,
        parentMessage: msg.parent_message
          ? {
              id: msg.parent_message.id,
              sender: msg.parent_message.sender_type === 'customer' ? 'customer' : 'staff',
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

  useEffect(() => {
    scrollToBottom();
  }, [selectedTicket?.messages]);

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
          `/api/shop-owner/conversations/${selectedTicketId}/messages`,
          formData,
          {
            headers: { "Content-Type": "multipart/form-data" },
          }
        );

        const messageData = response.data.data;
        const newMsg: Message = {
          id: messageData.id,
          sender: "staff",
          senderName: "You",
          content: messageData.content || "",
          timestamp: new Date(messageData.created_at).toLocaleTimeString("en-US", {
            hour: "2-digit",
            minute: "2-digit",
            hour12: true,
          }),
          images: messageData.attachments || undefined,
          parentMessageId: messageData.parent_message_id || null,
          parentMessage: messageData.parent_message
            ? {
                id: messageData.parent_message.id,
                sender: messageData.parent_message.sender_type === 'customer' ? 'customer' : 'staff',
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

  const getReplySenderLabel = (message: NonNullable<Message['parentMessage']>) => {
    return message.sender === 'staff'
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
    if (message.sender === 'staff') {
      return message.parentMessage.sender === 'staff'
        ? 'You replied to yourself'
        : `You replied to ${selectedTicket?.customerName || 'Customer'}`;
    }
    return message.parentMessage.sender === 'staff'
      ? `${selectedTicket?.customerName || 'Customer'} replied to you`
      : `${selectedTicket?.customerName || 'Customer'} replied to themselves`;
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

  const filteredTickets = tickets.filter((ticket) =>
    ticket.customerName.toLowerCase().includes(searchQuery.toLowerCase())
  );

  const handleTransferToRepairer = async () => {
    if (!selectedTicket || !transferNote.trim()) {
      setNotification({ type: 'error', message: 'Please add a transfer note explaining why this needs technical support.' });
      setTimeout(() => setNotification(null), 3000);
      return;
    }

    try {
      setIsTransferring(true);
      const response = await axios.post(
        `/api/shop-owner/conversations/${selectedTicketId}/transfer`,
        {
          to_department: "repairer",
          transfer_note: transferNote,
        }
      );

      if (response.status === 200) {
        // Remove from current list or update status
        setTickets((prev) => prev.filter((t) => t.id !== selectedTicketId));
        setSelectedTicketId(null);
        setShowTransferModal(false);
        setTransferNote("");
        setNotification({ type: 'success', message: 'Conversation successfully transferred to Repairer department!' });
        setTimeout(() => setNotification(null), 3000);
      }
    } catch (error) {
      console.error("Error transferring conversation:", error);
      setNotification({ type: 'error', message: 'Failed to transfer conversation. Please try again.' });
      setTimeout(() => setNotification(null), 3000);
    } finally {
      setIsTransferring(false);
    }
  };

  return (
    <AppLayoutShopOwner fullBleed>
      <Head title="Customer Support" />
      
      {/* Notification Toast */}
      {notification && (
        <div className="fixed top-4 right-4 z-60 animate-in slide-in-from-top-2">
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
      
      <div className="h-full flex flex-col bg-gray-50 overflow-hidden max-h-screen">
        <div className="flex gap-0 h-[calc(100vh-120px)] p-0">
          {/* Left Sidebar - Chat List */}
          <div className="w-80 bg-white border-r border-gray-200 flex flex-col overflow-hidden">
            {/* Header */}
            <div className="border-b border-gray-200 px-6 py-4 relative z-20">
              <h1 className="text-2xl font-bold text-black mb-4">Chat</h1>
              
              {/* Search and Filter Menu */}
              <div className="flex gap-2">
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
                
                {/* Filter Menu Button - Hover Dropdown */}
                <div className="relative group">
                  <button
                    className="p-2 hover:bg-gray-100 rounded-lg transition-colors text-gray-600 hover:text-gray-800"
                    title="Filter by status"
                  >
                    <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                      <path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z" />
                    </svg>
                  </button>
                  
                  {/* Dropdown Menu - Hidden by default, visible on group hover */}
                  <div className="hidden group-hover:block absolute right-0 top-full mt-2 bg-white border border-gray-200 rounded-lg shadow-xl z-50 py-2 whitespace-nowrap">
                    {["all", "open", "in_progress", "resolved"].map((status) => (
                      <button
                        key={status}
                        onClick={() => {
                          setStatusFilter(status);
                        }}
                        className={`flex items-center gap-3 w-full px-6 py-2 text-sm text-left transition-colors ${
                          statusFilter === status
                            ? "bg-blue-50 text-blue-600"
                            : "text-gray-700 hover:bg-gray-50"
                        }`}
                      >
                        <span className={`w-2 h-2 rounded-full shrink-0 ${
                          statusFilter === status
                            ? "bg-blue-600"
                            : "bg-gray-400"
                        }`}></span>
                        {status.replace("_", " ").toUpperCase()}
                      </button>
                    ))}
                  </div>
                </div>
              </div>
            </div>

            {/* Ticket List */}
            <div className="flex-1 overflow-y-auto">
              {initialLoad ? (
                <div className="flex items-center justify-center h-full text-gray-500">
                  <div className="text-center">
                    <svg className="animate-spin h-8 w-8 text-blue-500 mx-auto mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <p className="text-sm">Loading conversations...</p>
                  </div>
                </div>
              ) : filteredTickets.length === 0 ? (
                <div className="flex items-center justify-center h-full text-gray-500">
                  <p className="text-sm">No conversations found</p>
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
                      <div className="w-12 h-12 rounded-full bg-gray-200 overflow-hidden flex items-center justify-center text-gray-700 font-semibold text-sm">
                        {ticket.customerAvatar}
                      </div>
                      <div className={`absolute bottom-0 right-0 w-3 h-3 rounded-full border-2 border-white ${getStatusColor(ticket.status)}`} />
                    </div>

                    {/* Content */}
                    <div className="flex-1 min-w-0">
                      <div className="flex items-baseline justify-between">
                        <h3 className="font-semibold text-black text-sm">{ticket.customerName}</h3>
                        <span className="text-xs text-gray-500 ml-2">{ticket.lastMessageTime}</span>
                      </div>
                      <p className="text-xs text-gray-500">{ticket.customerRole}</p>
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
            <div className="flex-1 flex flex-col bg-white overflow-hidden">
              {/* Header */}
              <div className="border-b border-gray-200 px-6 py-4 flex items-center justify-between bg-white">
                <div className="flex items-center gap-4">
                  <div className="relative">
                    <div className="w-12 h-12 rounded-full bg-gray-200 flex items-center justify-center text-gray-700 font-semibold text-sm">
                      {selectedTicket.customerAvatar}
                    </div>
                    <div className={`absolute bottom-0 right-0 w-3.5 h-3.5 rounded-full border-2 border-white ${getStatusColor(selectedTicket.status)}`} />
                  </div>
                  <div>
                    <h2 className="text-lg font-bold text-black">{selectedTicket.customerName}</h2>
                    <p className="text-xs text-gray-500">{getStatusText(selectedTicket.status)}</p>
                  </div>
                </div>
                
                {/* Transfer Button */}
                <button
                  onClick={() => setShowTransferModal(true)}
                  className="p-1.5 bg-white border-2 border-black hover:bg-gray-50 rounded-lg transition-colors"
                  title="Transfer to Repairer"
                >
                  <svg className="w-4 h-4" fill="none" stroke="black" strokeWidth={2} viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                  </svg>
                </button>
              </div>

              {/* Messages */}
              <div className="flex-1 overflow-y-auto px-6 py-6 space-y-4 bg-white">
                {selectedTicket.messages.length === 0 ? (
                  <div className="flex items-center justify-center h-full text-gray-500">
                    <p>No messages yet</p>
                  </div>
                ) : (
                  <>
                    {selectedTicket.messages.map((message) => {
                      const normalizedContent = (message.content || '')
                        .replace(/\\n/g, '\n')
                        .replace(/\\\*/g, '*')
                        .replace(/\\"/g, '"');
                      const isRepairOrderAccepted = /repair order accepted/i.test(normalizedContent) && /\*\*type:\*\*/i.test(normalizedContent);

                      if (isRepairOrderAccepted) {
                        const titleMatch = normalizedContent.match(/\*\*(Repair Order Accepted[^*]*)\*\*/i);
                        const typeMatch = normalizedContent.match(/\*\*Type:\*\*\s*(.+?)(?=\n\*\*|$)/i);
                        const itemMatch = normalizedContent.match(/\*\*Item:\*\*\s*(.+?)(?=\n\*\*|$)/i);
                        const issueMatch = normalizedContent.match(/\*\*Issue:\*\*\s*(.+?)(?=\n\*\*|$)/i);
                        const deliveryMatch = normalizedContent.match(/\*\*Delivery:\*\*\s*(.+?)(?=\n\*\*|$)/i);
                        const scheduledMatch = normalizedContent.match(/\*\*Scheduled:\*\*\s*(.+?)(?=\n\*\*|$)/i);
                        const estimateMatch = normalizedContent.match(/\*\*Estimate:\*\*\s*(.+?)(?=\n\*\*|$)/i);
                        const statusMatch = normalizedContent.match(/\*\*Status:\*\*\s*(.+?)(?=\n\*\*|$)/i);

                        const title = titleMatch ? titleMatch[1].trim() : 'Repair Order Accepted';
                        const typeValue = typeMatch ? typeMatch[1].trim() : '';
                        const itemValue = itemMatch ? itemMatch[1].trim() : '';
                        const issueValue = issueMatch ? issueMatch[1].trim() : '';
                        const deliveryValue = deliveryMatch ? deliveryMatch[1].trim() : '';
                        const scheduledValue = scheduledMatch ? scheduledMatch[1].trim() : '';
                        const estimateValue = estimateMatch ? estimateMatch[1].trim() : '';
                        const statusValue = statusMatch ? statusMatch[1].trim() : '';

                        return (
                          <div key={message.id} className={`flex ${message.sender === 'staff' ? 'justify-end' : 'justify-start'} my-4`}>
                            <div className="bg-white border border-gray-200 rounded-xl p-5 shadow-sm w-full max-w-2xl">
                              <div className="flex items-start justify-between mb-4 gap-3">
                                <div>
                                  <h3 className="text-base font-semibold text-gray-900">{title}</h3>
                                  <p className="text-xs text-gray-500 mt-1">{message.timestamp}</p>
                                </div>
                                {statusValue && (
                                  <span className="text-xs font-semibold px-2.5 py-1 rounded-full bg-green-100 text-green-800">
                                    {statusValue}
                                  </span>
                                )}
                              </div>

                              <div className="bg-gray-50 rounded-lg p-4 mb-4 space-y-3">
                                {typeValue && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Service:</span>
                                    <span className="text-sm text-gray-900 font-medium">{typeValue}</span>
                                  </div>
                                )}
                                {itemValue && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Item:</span>
                                    <span className="text-sm text-gray-900 font-medium">{itemValue}</span>
                                  </div>
                                )}
                                {issueValue && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Issue:</span>
                                    <span className="text-sm text-gray-900">{issueValue}</span>
                                  </div>
                                )}
                                {deliveryValue && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Delivery:</span>
                                    <span className="text-sm text-gray-900">{deliveryValue}</span>
                                  </div>
                                )}
                                {scheduledValue && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Scheduled:</span>
                                    <span className="text-sm text-gray-900">{scheduledValue}</span>
                                  </div>
                                )}
                                {estimateValue && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Estimate:</span>
                                    <span className="text-sm text-gray-900 font-semibold">{estimateValue}</span>
                                  </div>
                                )}
                              </div>

                              <div className="border-t border-gray-100 pt-3 mb-3">
                                <p className="text-sm font-semibold text-gray-900">SoleSpace Shop</p>
                              </div>

                              <div className="bg-blue-50 rounded-lg px-3 py-2.5 mb-4">
                                <p className="text-xs text-blue-900 leading-relaxed">💡 We'll keep you updated on the progress of your repair.</p>
                              </div>

                              <button
                                type="button"
                                className="w-full bg-white hover:bg-gray-50 text-black border border-gray-300 font-semibold py-2.5 px-4 rounded-lg transition-all duration-200 text-sm shadow-sm hover:shadow-md"
                              >
                                View Full Details
                              </button>
                            </div>
                          </div>
                        );
                      }

                      return (
                      <div
                        key={message.id}
                        className={`flex flex-col ${message.sender === "staff" ? "items-end" : "items-start"}`}
                        onMouseEnter={() => setHoveredMessageId(message.id)}
                        onMouseLeave={() => setHoveredMessageId(null)}
                      >
                        {message.images && message.images.length > 0 ? (
                          <div className="relative group">
                            <div className="flex flex-wrap gap-2 mb-2">
                              {message.images.map((img, idx) => (
                                <img
                                  key={idx}
                                  src={img}
                                  alt={`Attachment ${idx + 1}`}
                                  className="rounded-lg max-w-37.5 max-h-37.5 object-cover cursor-pointer hover:opacity-80 transition-opacity"
                                  onClick={() => setFullscreenImage(img)}
                                />
                              ))}
                            </div>
                            {(message.content || message.parentMessage) && (
                              <div className={`flex flex-col gap-1 max-w-xs lg:max-w-md xl:max-w-lg ${message.sender === 'staff' ? 'items-end' : 'items-start'}`}>
                                {message.parentMessage && (
                                  <p className="text-[11px] text-gray-500 px-1">{getReplyContextLabel(message)}</p>
                                )}
                                {renderQuotedReply(message, message.sender === 'staff')}
                                {message.content && (
                                  <div className={`${message.sender === "staff" ? "bg-blue-500 text-white" : "bg-gray-100 text-gray-900"} px-4 py-2 text-sm rounded-lg shadow-sm inline-block w-fit max-w-full`}>
                                    <p className="wrap-break-word text-sm leading-relaxed">{message.content}</p>
                                  </div>
                                )}
                              </div>
                            )}
                            {hoveredMessageId === message.id && (
                              <div className={`absolute top-0 ${message.sender === 'staff' ? 'right-full mr-2' : 'left-full ml-2'} flex gap-1 z-10`}>
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
                              <div className={`absolute -top-12 ${message.sender === 'staff' ? 'right-0' : 'left-0'} bg-white border border-gray-200 rounded-full shadow-md px-2 py-1 flex items-center gap-1 z-20`}>
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
                                className={`absolute -bottom-3 ${message.sender === 'staff' ? 'right-2' : 'left-2'} bg-white border border-gray-200 rounded-full px-2 py-0.5 text-sm shadow-sm hover:bg-gray-50 transition-colors`}
                              >
                                {messageReactions[message.id]}
                              </button>
                            )}
                          </div>
                        ) : (
                          <div className="relative group">
                            <div className={`flex flex-col gap-1 max-w-xs lg:max-w-md xl:max-w-lg ${message.sender === 'staff' ? 'items-end' : 'items-start'}`}>
                              {message.parentMessage && (
                                <p className="text-[11px] text-gray-500 px-1">{getReplyContextLabel(message)}</p>
                              )}
                              {renderQuotedReply(message, message.sender === 'staff')}
                              <div className={`${message.sender === "staff" ? "bg-blue-500 text-white" : "bg-gray-100 text-gray-900"} inline-block w-fit max-w-full px-4 py-2 text-sm rounded-lg shadow-sm`}>
                                <p className="wrap-break-word text-sm leading-relaxed">{message.content}</p>
                              </div>
                            </div>
                            {hoveredMessageId === message.id && (
                              <div className={`absolute top-0 ${message.sender === 'staff' ? 'right-full mr-2' : 'left-full ml-2'} flex gap-1 z-10`}>
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
                              <div className={`absolute -top-12 ${message.sender === 'staff' ? 'right-0' : 'left-0'} bg-white border border-gray-200 rounded-full shadow-md px-2 py-1 flex items-center gap-1 z-20`}>
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
                                className={`absolute -bottom-3 ${message.sender === 'staff' ? 'right-2' : 'left-2'} bg-white border border-gray-200 rounded-full px-2 py-0.5 text-sm shadow-sm hover:bg-gray-50 transition-colors`}
                              >
                                {messageReactions[message.id]}
                              </button>
                            )}
                          </div>
                        )}
                        <div className={`mt-2 ${message.sender === "staff" ? "text-right" : "text-left"}`}>
                          <span className={`text-[11px] ${message.sender === "staff" ? "text-gray-400" : "text-gray-500"}`}>
                            {message.timestamp}
                          </span>
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
                        Replying to {replyingToMessage.sender === 'staff' ? 'You' : (selectedTicket?.customerName || 'Customer')}
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
            <div className="flex-1 flex items-center justify-center text-gray-500">
              <p>Select a conversation to start chatting</p>
            </div>
          )}
        </div>
      </div>

      {/* Fullscreen Image Modal */}
      {fullscreenImage && (
        <div 
          className="fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50"
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

      {/* Transfer Modal */}
      {showTransferModal && selectedTicket && (
        <div 
          className="fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50"
          onClick={() => setShowTransferModal(false)}
        >
          <div 
            className="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4"
            onClick={(e) => e.stopPropagation()}
          >
            {/* Modal Header */}
            <div className="border-b border-gray-200 px-6 py-4 flex items-center justify-between">
              <div>
                <h3 className="text-lg font-bold text-black">Transfer to Repairer</h3>
                <p className="text-sm text-gray-500 mt-1">
                  Customer: {selectedTicket.customerName}
                </p>
              </div>
              <button
                onClick={() => setShowTransferModal(false)}
                className="text-gray-400 hover:text-gray-600 transition-colors"
                title="Close"
                aria-label="Close"
              >
                <svg className="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z" />
                </svg>
              </button>
            </div>

            {/* Modal Body */}
            <div className="px-6 py-4">
              <div className="bg-orange-50 border border-orange-200 rounded-lg p-4 mb-4">
                <div className="flex items-start gap-3">
                  <svg className="w-5 h-5 text-orange-500 mt-0.5 shrink-0" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <div className="text-sm text-orange-800">
                    <p className="font-medium mb-1">This conversation will be transferred to the Repairer department</p>
                    <p className="text-xs text-orange-700">The repairer will see the full conversation history and can respond directly to the customer.</p>
                  </div>
                </div>
              </div>

              <label className="block mb-2">
                <span className="text-sm font-medium text-gray-700">Transfer Note *</span>
                <p className="text-xs text-gray-500 mt-1 mb-2">
                  Explain why this needs technical support (visible to repairer only)
                </p>
                <textarea
                  value={transferNote}
                  onChange={(e) => setTransferNote(e.target.value)}
                  placeholder="Example: Customer asking about motherboard replacement timeline and warranty coverage for water damage repair..."
                  rows={4}
                  className="w-full px-4 py-3 border border-gray-300 rounded-lg text-sm text-gray-900 placeholder-gray-400 outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-200 transition-all resize-none"
                  disabled={isTransferring}
                />
              </label>

              <div className="text-xs text-gray-500 mt-2">
                <p>• Customer will be notified that a technical specialist is now handling their case</p>
                <p>• This conversation will be removed from your queue</p>
              </div>
            </div>

            {/* Modal Footer */}
            <div className="border-t border-gray-200 px-6 py-4 flex items-center justify-end gap-3">
              <button
                onClick={() => {
                  setShowTransferModal(false);
                  setTransferNote("");
                }}
                className="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 transition-colors"
                disabled={isTransferring}
              >
                Cancel
              </button>
              <button
                onClick={handleTransferToRepairer}
                disabled={!transferNote.trim() || isTransferring}
                className={`px-6 py-2 rounded-lg text-sm font-medium transition-all ${
                  transferNote.trim() && !isTransferring
                    ? "bg-orange-500 hover:bg-orange-600 text-white"
                    : "bg-gray-300 text-gray-500 cursor-not-allowed"
                }`}
              >
                {isTransferring ? (
                  <span className="flex items-center gap-2">
                    <svg className="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Transferring...
                  </span>
                ) : (
                  "Transfer to Repairer"
                )}
              </button>
            </div>
          </div>
        </div>
      )}
    </AppLayoutShopOwner>
  );
}
