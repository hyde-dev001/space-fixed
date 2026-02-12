import { Head } from "@inertiajs/react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { useState, useRef, useEffect } from "react";
import axios from "axios";

interface Message {
  id: number;
  sender: "customer" | "staff";
  senderName: string;
  content: string;
  timestamp: string;
  images?: string[];
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
  const [loading, setLoading] = useState(false);
  const [sendingMessage, setSendingMessage] = useState(false);
  const [statusFilter, setStatusFilter] = useState<string>("all");
  
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    fetchConversations();
  }, [statusFilter]);

  const fetchConversations = async () => {
    try {
      setLoading(true);
      const params = statusFilter !== "all" ? { status: statusFilter } : {};
      const response = await axios.get("/api/crm/conversations", { params });
      
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
        return {
          id: conv.id,
          customerId: conv.customer?.id,
          customerName: conv.customer?.name || "Unknown",
          customerAvatar: getInitials(conv.customer?.name || "Unknown"),
          customerRole: conv.customer?.email || "",
          lastMessage: conv.messages?.[0]?.content || "No messages yet",
          lastMessageTime: formatTime(conv.last_message_at),
          status: "active",
          messages: existingTicket?.messages || [], // Keep existing messages
          priority: conv.priority,
          conversationStatus: conv.status,
        };
      });
      
      setTickets(conversationsData);
      
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
    }
  }, [selectedTicketId]);

  const fetchConversationMessages = async (conversationId: number) => {
    try {
      const response = await axios.get(`/api/crm/conversations/${conversationId}`);
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
        selectedImages.forEach((file) => {
          formData.append("images[]", file);
        });

        const response = await axios.post(
          `/api/crm/conversations/${selectedTicketId}/messages`,
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
      } catch (error) {
        console.error("Error sending message:", error);
        alert("Failed to send message. Please try again.");
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
    <AppLayoutERP hideHeader={!!fullscreenImage}>
      <Head title="Customer Support - Solespace ERP" />
      <div className="h-full flex flex-col bg-gray-50 overflow-hidden max-h-screen">
        <div className="flex gap-4 h-[calc(100vh-120px)] p-4">
          {/* Left Sidebar - Chat List */}
          <div className="w-80 bg-white rounded-2xl shadow-sm flex flex-col overflow-hidden">
            {/* Header */}
            <div className="border-b border-gray-200 px-6 py-4">
              <h1 className="text-2xl font-bold text-black mb-4">Chat</h1>
              
              {/* Status Filter */}
              <div className="mb-3 flex gap-2 flex-wrap">
                {["all", "open", "in_progress", "resolved"].map((status) => (
                  <button
                    key={status}
                    onClick={() => setStatusFilter(status)}
                    className={`px-3 py-1 text-xs rounded-full transition-colors ${
                      statusFilter === status
                        ? "bg-blue-500 text-white"
                        : "bg-gray-100 text-gray-700 hover:bg-gray-200"
                    }`}
                  >
                    {status.replace("_", " ").toUpperCase()}
                  </button>
                ))}
              </div>
              
              {/* Search */}
              <div className="relative">
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
            </div>

            {/* Ticket List */}
            <div className="flex-1 overflow-y-auto">
              {loading ? (
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
            <div className="flex-1 flex flex-col bg-white rounded-2xl shadow-sm overflow-hidden">
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
              </div>

              {/* Messages */}
              <div className="flex-1 overflow-y-auto px-6 py-6 space-y-4 bg-white">
                {selectedTicket.messages.length === 0 ? (
                  <div className="flex items-center justify-center h-full text-gray-500">
                    <p>No messages yet</p>
                  </div>
                ) : (
                  <>
                    {selectedTicket.messages.map((message) => (
                      <div
                        key={message.id}
                        className={`flex flex-col ${message.sender === "staff" ? "items-end" : "items-start"}`}
                      >
                        {message.images && message.images.length > 0 ? (
                          <div>
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
                            {message.content && (
                              <div
                                className={`${
                                  message.sender === "staff"
                                    ? "bg-blue-500 text-white px-4 py-2 text-sm rounded-lg shadow-sm"
                                    : "bg-gray-100 text-gray-900 px-4 py-2 text-sm rounded-lg shadow-sm"
                                }`}
                              >
                                <p className="wrap-break-word text-sm leading-relaxed">
                                  {message.content}
                                </p>
                              </div>
                            )}
                          </div>
                        ) : (
                          <div
                            className={`max-w-xs lg:max-w-md xl:max-w-lg ${
                              message.sender === "staff"
                                ? "bg-blue-500 text-white inline-block px-4 py-2 text-sm rounded-lg shadow-sm"
                                : "bg-gray-100 text-gray-900 inline-block px-4 py-2 text-sm rounded-lg shadow-sm"
                            }`}
                          >
                            <p className="wrap-break-word text-sm leading-relaxed">
                              {message.content}
                            </p>
                          </div>
                        )}
                        <div className={`mt-2 ${message.sender === "staff" ? "text-right" : "text-left"}`}>
                          <span className={`text-[11px] ${message.sender === "staff" ? "text-gray-400" : "text-gray-500"}`}>
                            {message.timestamp}
                          </span>
                        </div>
                      </div>
                    ))}
                    <div ref={messagesEndRef} />
                  </>
                )}
              </div>

              {/* Message Input */}
              <div className="border-t border-gray-200 bg-white px-6 py-4 rounded-b-2xl">
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
    </AppLayoutERP>
  );
}
