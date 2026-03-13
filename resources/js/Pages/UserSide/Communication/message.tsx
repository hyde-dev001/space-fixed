import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Head, usePage, router } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';

interface ConversationMessage {
  id: number;
  conversation_id: number;
  parent_message_id?: number | null;
  sender_id: number;
  sender_type: string;
  content: string;
  attachments?: string[];
  created_at: string;
  parent_message?: {
    id: number;
    sender_id: number;
    sender_type: string;
    content: string;
    attachments?: string[];
    created_at: string;
  } | null;
}

interface Conversation {
  id: number;
  shop_owner_id: number;
  customer_id: number;
  status: string;
  last_message_at: string;
  type?: 'repair' | 'product' | 'general';
  shopOwner?: {
    id: number;
    business_name: string;
    location?: string;
    business_address?: string;
    profile_photo?: string;
    email?: string;
    phone?: string;
  };
  repairRequest?: {
    request_id: string;
    repair_type: string;
    description: string;
    status: string;
    shoe_type?: string;
    brand?: string;
    total?: number;
    delivery_method?: string;
    scheduled_dropoff_date?: string;
    payment_status?: string;
  };
  order?: {
    id: number;
    order_number: string;
    status: string;
    payment_status?: string;
    total_amount?: number;
  };
  messages?: ConversationMessage[];
}

interface Props {
  conversation?: Conversation | null;
  shopOwnerId?: number;
}

interface OrderProductPreview {
  name: string;
  quantity: string;
  unitPrice?: string;
}

const Message: React.FC<Props> = ({ conversation: initialConversation = null, shopOwnerId }) => {
  const page = usePage();
  const { auth } = page.props as any;
  
  // Check if user is still authenticated
  useEffect(() => {
    if (!auth?.user) {
      router.visit('/user/login');
    }
  }, [auth?.user]);

  if (!auth?.user) {
    return null;
  }

  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [selectedConversation, setSelectedConversation] = useState<Conversation | null>(initialConversation || null);
  const [messages, setMessages] = useState<ConversationMessage[]>([]);
  const [searchQuery, setSearchQuery] = useState('');
  const [activeFilter, setActiveFilter] = useState<'all' | 'repairs' | 'products' | 'general'>('all');
  const [inputValue, setInputValue] = useState('');
  const [selectedImages, setSelectedImages] = useState<File[]>([]);
  const [fullscreenImage, setFullscreenImage] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isMessagesLoading, setIsMessagesLoading] = useState(false);
  const [isSending, setIsSending] = useState(false);
  const [orderStatusByNumber, setOrderStatusByNumber] = useState<Record<string, string>>({});
  const [lastMessageByConversationId, setLastMessageByConversationId] = useState<Record<number, string>>({});
  const [hoveredMessageId, setHoveredMessageId] = useState<number | null>(null);
  const [replyingToMessage, setReplyingToMessage] = useState<ConversationMessage | null>(null);
  const [reactionPickerMessageId, setReactionPickerMessageId] = useState<number | null>(null);
  const [messageReactions, setMessageReactions] = useState<Record<number, string>>({});
  
  const quickReactionList = ['❤️', '😂', '😍', '😮', '😢', '😡', '👍', '🎉'];
  
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const messagesContainerRef = useRef<HTMLDivElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const pollingIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const selectedConversationIdRef = useRef<number | null>(initialConversation?.id ?? null);

  useEffect(() => {
    selectedConversationIdRef.current = selectedConversation?.id ?? null;
  }, [selectedConversation?.id]);

  // Fetch all conversations on mount
  useEffect(() => {
    const fetchConversations = async () => {
      console.log('=== Starting to fetch conversations ===');
      console.log('Auth user:', auth?.user);
      
      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        console.log('CSRF Token:', csrfToken ? 'Present' : 'Missing');
        
        const url = activeFilter === 'all' 
          ? '/api/customer/conversations'
          : `/api/customer/conversations?type=${activeFilter}`;
        
        const response = await fetch(url, {
          method: 'GET',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'include',
        });
        
        console.log('Response status:', response.status);
        console.log('Response headers:', Object.fromEntries(response.headers.entries()));
        
        if (!response.ok) {
          const errorText = await response.text();
          console.error('Failed to fetch conversations:', response.status, response.statusText, errorText);
          if (response.status === 401) {
            console.log('Unauthorized - redirecting to login');
            router.visit('/user/login');
          }
          setIsLoading(false);
          return;
        }
        
        const data = await response.json();
        console.log('=== Raw API Response ===');
        console.log('Data:', data);
        console.log('Data type:', Array.isArray(data) ? 'Array' : typeof data);
        console.log('Data length:', Array.isArray(data) ? data.length : 'N/A');
        
        // Handle both array and object with data property
        const conversationsList = Array.isArray(data) ? data : (data.data || []);
        const sortedConversations = [...conversationsList].sort((a, b) => {
          const aTime = a.last_message_at ? new Date(a.last_message_at).getTime() : 0;
          const bTime = b.last_message_at ? new Date(b.last_message_at).getTime() : 0;
          return bTime - aTime;
        });
        setConversations(sortedConversations);
        
        // Fetch last message for each conversation
        sortedConversations.forEach(conv => {
          fetchLastMessage(conv.id);
        });
        
        const selectedConversationId = selectedConversationIdRef.current;

        // Keep currently selected conversation in sync so live status reflects backend updates.
        if (selectedConversationId) {
          const updatedSelectedConversation = sortedConversations.find((c: Conversation) => c.id === selectedConversationId);
          if (updatedSelectedConversation) {
            setSelectedConversation(updatedSelectedConversation);
            return;
          }
        }

        // If no initial conversation but shopOwnerId was provided, create/fetch that conversation
        if (shopOwnerId) {
          const existingConv = sortedConversations.find((c: Conversation) => c.shop_owner_id === shopOwnerId);
          if (existingConv) {
            console.log('Found existing conversation for shop owner:', existingConv);
            setSelectedConversation(existingConv);
            fetchMessages(existingConv.id);
          } else {
            console.log('Creating new conversation for shop owner:', shopOwnerId);
            // Create new conversation
            createConversation(shopOwnerId);
          }
        } else if (initialConversation && sortedConversations.length > 0) {
          // If initial conversation was provided, try to find it in the fetched list
          const foundConv = sortedConversations.find((c: Conversation) => c.id === initialConversation.id);
          if (foundConv) {
            console.log('Found initial conversation in list:', foundConv);
            setSelectedConversation(foundConv);
            fetchMessages(foundConv.id);
          } else {
            console.log('Initial conversation not found, using it anyway');
            fetchMessages(initialConversation.id);
          }
        } else if (sortedConversations.length > 0) {
          // Auto-select first conversation if no specific one was requested
          console.log('Auto-selecting first conversation:', sortedConversations[0]);
          setSelectedConversation(sortedConversations[0]);
          fetchMessages(sortedConversations[0].id);
        } else {
          console.log('No conversations available');
        }
      } catch (error) {
        console.error('Failed to fetch conversations:', error);
      } finally {
        setIsLoading(false);
      }
    };

    fetchConversations();
    
    // Auto-refresh conversations every 2 seconds
    const conversationInterval = setInterval(() => {
      fetchConversations();
    }, 2000);
    
    return () => clearInterval(conversationInterval);
  }, [activeFilter]);  // Re-fetch when filter changes

  const fetchMessages = async (conversationId: number, silent: boolean = false) => {
    if (!silent) {
      setIsMessagesLoading(true);
    }

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const response = await fetch(`/api/customer/conversations/${conversationId}/messages`, {
        method: 'GET',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
      });
      
      if (!response.ok) {
        if (!silent) {
          console.error('Failed to fetch messages:', response.status, response.statusText);
        }
        if (response.status === 401) {
          router.visit('/user/login');
        }
        return;
      }
      
      const data = await response.json();
      if (!silent) {
        console.log('Fetched messages for conversation', conversationId, ':', data);
      }
      
      // Handle both array and object with data property
      const messagesList = Array.isArray(data) ? data : (data.data || []);
      const sortedMessages = [...messagesList].sort((a, b) => {
        const aTime = new Date(a.created_at).getTime();
        const bTime = new Date(b.created_at).getTime();
        return aTime - bTime;
      });
      setMessages(sortedMessages);
      
      // Update last message cache with the last message from this conversation
      if (sortedMessages.length > 0) {
        const lastMsg = sortedMessages[sortedMessages.length - 1];
        setLastMessageByConversationId(prev => ({
          ...prev,
          [conversationId]: lastMsg.content || ''
        }));
      }
      
      // Scroll to bottom when messages are loaded
      if (!silent) {
        requestAnimationFrame(() => {
          scrollToBottom('auto');
        });
      }
    } catch (error) {
      if (!silent) {
        console.error('Failed to fetch messages:', error);
      }
    } finally {
      if (!silent) {
        setIsMessagesLoading(false);
      }
    }
  };

  const fetchLastMessage = async (conversationId: number) => {
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const response = await fetch(`/api/customer/conversations/${conversationId}/messages`, {
        method: 'GET',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
      });
      
      if (!response.ok) {
        return;
      }
      
      const data = await response.json();
      const messagesList = Array.isArray(data) ? data : (data.data || []);
      
      if (messagesList.length > 0) {
        // Find the last message (latest by created_at)
        const sortedMessages = [...messagesList].sort((a, b) => {
          const aTime = new Date(a.created_at).getTime();
          const bTime = new Date(b.created_at).getTime();
          return aTime - bTime;
        });
        const lastMsg = sortedMessages[sortedMessages.length - 1];
        setLastMessageByConversationId(prev => ({
          ...prev,
          [conversationId]: lastMsg.content || ''
        }));
      }
    } catch (error) {
      console.error('Failed to fetch last message:', error);
    }
  };

  const fetchOrderStatuses = async (silent: boolean = true) => {
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const response = await fetch('/api/my-orders', {
        method: 'GET',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
      });

      if (!response.ok) {
        if (!silent) {
          console.error('Failed to fetch order statuses:', response.status, response.statusText);
        }
        return;
      }

      const data = await response.json();
      const orders = Array.isArray(data?.orders) ? data.orders : [];
      const nextMap: Record<string, string> = {};

      orders.forEach((order: any) => {
        const orderNumber = String(order?.order_number || '').trim().toUpperCase();
        if (!orderNumber) return;

        const rawStatus = order?.status;
        const normalizedStatus = typeof rawStatus === 'object' && rawStatus !== null
          ? String((rawStatus as any).value || '')
          : String(rawStatus || '');

        if (normalizedStatus) {
          nextMap[orderNumber] = normalizedStatus;
        }
      });

      setOrderStatusByNumber(nextMap);
    } catch (error) {
      if (!silent) {
        console.error('Failed to fetch order statuses:', error);
      }
    }
  };

  useEffect(() => {
    fetchOrderStatuses(false);
    const orderStatusInterval = setInterval(() => {
      fetchOrderStatuses(true);
    }, 3000);

    return () => clearInterval(orderStatusInterval);
  }, []);

  const createConversation = async (shopOwnerId: number) => {
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const response = await fetch('/api/customer/conversations/get-or-create', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
        body: JSON.stringify({ shop_owner_id: shopOwnerId }),
      });
      
      if (!response.ok) {
        console.error('Failed to create conversation:', response.status, response.statusText);
        if (response.status === 401) {
          router.visit('/user/login');
        }
        return;
      }
      
      const conversation = await response.json();
      
      // Format the conversation data to match the expected structure
      const formattedConversation = {
        ...conversation,
        shopOwner: conversation.shopOwner || conversation.shop_owner ? {
          id: conversation.shopOwner?.id || conversation.shop_owner?.id,
          business_name: conversation.shopOwner?.business_name || conversation.shop_owner?.business_name || 'Unknown Shop',
          location: conversation.shopOwner?.business_address || conversation.shop_owner?.business_address || conversation.shopOwner?.location || conversation.shop_owner?.location || '',
          profile_photo: conversation.shopOwner?.profile_photo || conversation.shop_owner?.profile_photo || '',
          email: conversation.shopOwner?.email || conversation.shop_owner?.email || '',
          phone: conversation.shopOwner?.phone || conversation.shop_owner?.phone || '',
        } : undefined,
      };
      
      setConversations((prev) => [formattedConversation, ...prev]);
      setSelectedConversation(formattedConversation);
      setMessages([]);
    } catch (error) {
      console.error('Failed to create conversation:', error);
    }
  };

  const handleSendMessage = async () => {
    if (!selectedConversation) return;
    if (!inputValue.trim() && selectedImages.length === 0) return;

    setIsSending(true);
    try {
      const formData = new FormData();
      if (inputValue.trim()) {
        formData.append('content', inputValue);
      }
      if (replyingToMessage?.id) {
        formData.append('parent_message_id', String(replyingToMessage.id));
      }
      
      selectedImages.forEach((image) => {
        formData.append('images[]', image);
      });

      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const response = await fetch(`/api/customer/conversations/${selectedConversation.id}/messages`, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
        body: formData,
      });

      if (!response.ok) {
        console.error('Failed to send message:', response.status, response.statusText);
        if (response.status === 401) {
          router.visit('/user/login');
        }
        return;
      }

      const data = await response.json();
      const newMessage = data.data || data;
      setMessages((prev) => [...prev, newMessage]);
      setInputValue('');
      setSelectedImages([]);
      setReplyingToMessage(null);
    } catch (error) {
      console.error('Failed to send message:', error);
    } finally {
      setIsSending(false);
    }
  };

  const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = e.target.files;
    if (!files) return;

    const imageFiles = Array.from(files).filter((file) => file.type.startsWith('image/'));
    if (imageFiles.length > 0) {
      setSelectedImages((prev) => [...prev, ...imageFiles]);
    }

    if (fileInputRef.current) {
      fileInputRef.current.value = '';
    }
  };

  const handleUploadClick = () => {
    fileInputRef.current?.click();
  };

  const removeImage = (index: number) => {
    setSelectedImages((prev) => prev.filter((_, i) => i !== index));
  };

  const handleKeyPress = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSendMessage();
    }
  };

  const handleConversationClick = (conversation: Conversation) => {
    setSelectedConversation(conversation);
    fetchMessages(conversation.id);
  };

  const handleReply = (message: ConversationMessage) => {
    setReplyingToMessage(message);
    setReactionPickerMessageId(null);
  };

  const clearReply = () => {
    setReplyingToMessage(null);
  };

  const handleReactToMessage = (messageId: number, emoji: string) => {
    setMessageReactions((prev) => {
      const currentReaction = prev[messageId];

      // Toggle behavior: clicking the same emoji removes the reaction.
      if (currentReaction === emoji) {
        const { [messageId]: _removed, ...rest } = prev;
        return rest;
      }

      return {
        ...prev,
        [messageId]: emoji,
      };
    });
    setReactionPickerMessageId(null);
  };

  const filteredConversations = conversations.filter((conv) => {
    const shopName = conv.shopOwner?.business_name || 'Unknown Shop';
    const location = conv.shopOwner?.location || '';
    return `${shopName} ${location}`.toLowerCase().includes(searchQuery.toLowerCase());
  });

  const parseOrderProducts = (content: string, itemsSummary: string): OrderProductPreview[] => {
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

  const getInitials = (value: string) => {
    return value
      .split(' ')
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0].toUpperCase())
      .join('') || 'U';
  };

  const renderAvatar = (name: string, photoUrl?: string, tone: 'customer' | 'shop' = 'shop') => {
    if (photoUrl) {
      return (
        <img
          src={photoUrl}
          alt={name}
          className="w-7 h-7 rounded-full object-cover"
        />
      );
    }

    const bgClass = tone === 'customer' ? 'bg-gray-900 text-white' : 'bg-blue-100 text-blue-700';

    return (
      <div className={`w-7 h-7 rounded-full ${bgClass} flex items-center justify-center text-[11px] font-semibold`}>
        {getInitials(name)}
      </div>
    );
  };

  const getReplySenderLabel = (message: Pick<ConversationMessage, 'sender_type'>) => {
    return message.sender_type === 'customer'
      ? 'You'
      : selectedConversation?.shopOwner?.business_name || 'Shop';
  };

  const getReplyPreviewText = (message: Pick<ConversationMessage, 'content' | 'attachments'>) => {
    if (message.content?.trim()) {
      return message.content.trim();
    }

    const attachmentCount = message.attachments?.length || 0;

    if (attachmentCount > 1) {
      return `${attachmentCount} photos`;
    }

    if (attachmentCount === 1) {
      return 'Photo';
    }

    return 'Message';
  };

  const getReplyContextLabel = (message: ConversationMessage) => {
    if (!message.parent_message) {
      return null;
    }

    const shopName = selectedConversation?.shopOwner?.business_name || 'Shop';

    if (message.sender_type === 'customer') {
      return message.parent_message.sender_type === 'customer'
        ? 'You replied to yourself'
        : `You replied to ${shopName}`;
    }

    return message.parent_message.sender_type === 'customer'
      ? `${shopName} replied to you`
      : `${shopName} replied to themselves`;
  };

  const renderQuotedReply = (message: ConversationMessage, isOutgoing: boolean) => {
    if (!message.parent_message) {
      return null;
    }

    return (
      <div
        className={`rounded-2xl px-3 py-2 border ${
          isOutgoing
            ? 'bg-blue-50 border-blue-200 text-blue-800'
            : 'bg-white border-gray-300 text-gray-700'
        }`}
      >
        <p className={`text-[11px] font-semibold ${isOutgoing ? 'text-blue-700' : 'text-gray-500'}`}>
          {getReplySenderLabel(message.parent_message)}
        </p>
        <p className={`text-xs mt-1 whitespace-nowrap overflow-hidden text-ellipsis ${isOutgoing ? 'text-blue-900' : 'text-gray-600'}`}>
          {getReplyPreviewText(message.parent_message)}
        </p>
      </div>
    );
  };

  // Set up polling for real-time message updates
  useEffect(() => {
    // Clear any existing interval
    if (pollingIntervalRef.current) {
      clearInterval(pollingIntervalRef.current);
      pollingIntervalRef.current = null;
    }

    // Only poll if a conversation is selected
    if (selectedConversation) {
      // Poll every 2 seconds for new messages
      pollingIntervalRef.current = setInterval(() => {
        fetchMessages(selectedConversation.id, true); // silent = true to avoid console spam
      }, 2000);
    }

    // Cleanup on unmount or when conversation changes
    return () => {
      if (pollingIntervalRef.current) {
        clearInterval(pollingIntervalRef.current);
        pollingIntervalRef.current = null;
      }
    };
  }, [selectedConversation]);

  const scrollToBottom = (behavior: ScrollBehavior = 'smooth') => {
    requestAnimationFrame(() => {
      messagesEndRef.current?.scrollIntoView({ behavior, block: 'end' });
    });
  };

  useEffect(() => {
    if (selectedConversation) {
      scrollToBottom('auto');
    }
  }, [selectedConversation?.id]);

  useEffect(() => {
    if (messages.length > 0) {
      scrollToBottom();
    }
  }, [messages.length]);

  return (
    <div className="h-screen flex flex-col bg-gray-50 overflow-hidden">
      <Head title="Messages - Solespace" />
      <Navigation />

      <main className="mt-20 h-[calc(100vh-5rem)] flex flex-col w-full min-h-0 overflow-hidden">
        <div className="flex flex-1 min-h-0 w-full gap-0 h-full overflow-hidden">
          <div className="w-96 h-full bg-white flex flex-col overflow-hidden border-r border-gray-200">
            <div className="border-b border-gray-200 px-6 py-4">
              <h1 className="text-2xl font-bold text-black mb-4">Shop Messages</h1>
              <div className="relative mb-4">
                <svg
                  className="absolute left-3 top-3 w-5 h-5 text-gray-400"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth={2}
                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                  />
                </svg>
                <input
                  type="text"
                  placeholder="Search shops..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="w-full pl-10 pr-4 py-2 bg-gray-50 border border-gray-200 rounded-xl text-sm text-gray-900 placeholder-gray-400 outline-none focus:border-black transition-colors"
                />
              </div>
              
              {/* Filter Tabs */}
              <div className="flex gap-2">
                <button
                  onClick={() => setActiveFilter('all')}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    activeFilter === 'all'
                      ? 'bg-black text-white'
                      : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                  }`}
                >
                  All
                </button>
                <button
                  onClick={() => setActiveFilter('repairs')}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    activeFilter === 'repairs'
                      ? 'bg-black text-white'
                      : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                  }`}
                >
                  Repairs
                </button>
                <button
                  onClick={() => setActiveFilter('products')}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    activeFilter === 'products'
                      ? 'bg-black text-white'
                      : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                  }`}
                >
                  Products
                </button>
                <button
                  onClick={() => setActiveFilter('general')}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    activeFilter === 'general'
                      ? 'bg-black text-white'
                      : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                  }`}
                >
                  General
                </button>
              </div>
            </div>

            <div className="flex-1 overflow-y-auto overscroll-contain">
              {isLoading ? (
                <div className="flex items-center justify-center h-full text-gray-500">
                  <span>Loading conversations...</span>
                </div>
              ) : filteredConversations.length === 0 ? (
                <div className="flex items-center justify-center h-full text-gray-500">
                  <span>No conversations yet</span>
                </div>
              ) : (
                filteredConversations.map((conversation) => (
                  <div
                    key={conversation.id}
                    onClick={() => handleConversationClick(conversation)}
                    className={`px-6 py-4 border-b border-gray-100 cursor-pointer transition-colors hover:bg-gray-50 ${
                      selectedConversation?.id === conversation.id ? 'bg-gray-50 border-l-4 border-l-blue-500' : ''
                    }`}
                  >
                    <div className="flex items-start gap-3">
                      <div className="relative shrink-0">
                        {conversation.shopOwner?.profile_photo ? (
                          <img
                            src={`/storage/${conversation.shopOwner.profile_photo}`}
                            alt={conversation.shopOwner.business_name}
                            className="w-12 h-12 rounded-full object-cover"
                          />
                        ) : (
                          <div className="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                            <svg className="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth={2}
                                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"
                              />
                            </svg>
                          </div>
                        )}
                      </div>

                      <div className="flex-1 min-w-0">
                        <div className="flex items-baseline justify-between mb-1">
                          <h3 className="font-bold text-black text-lg truncate">
                            {conversation.shopOwner?.business_name || 'Unknown Shop'}
                          </h3>
                          <span className="text-xs text-gray-500 ml-2 shrink-0">
                            {conversation.last_message_at ? new Date(conversation.last_message_at).toLocaleDateString() : 'No messages'}
                          </span>
                        </div>
                        <p className="text-sm text-gray-600 truncate">
                          {lastMessageByConversationId[conversation.id] || 'No messages yet'}
                        </p>
                      </div>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>

          <div className="flex-1 h-full min-h-0 flex flex-col bg-white overflow-hidden">
            <div className="border-b border-gray-200 px-6 py-4 flex items-center justify-between bg-white">
              {selectedConversation ? (
                <div className="flex items-center gap-4">
                  <div className="relative">
                    {selectedConversation.shopOwner?.profile_photo ? (
                      <img
                        src={`/storage/${selectedConversation.shopOwner.profile_photo}`}
                        alt={selectedConversation.shopOwner.business_name}
                        className="w-12 h-12 rounded-full object-cover"
                      />
                    ) : (
                      <div className="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                        <svg className="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={2}
                            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"
                          />
                        </svg>
                      </div>
                    )}
                  </div>
                  <div>
                    <h2 className="text-lg font-bold text-black">
                      {selectedConversation.shopOwner?.business_name || 'Unknown Shop'}
                    </h2>
                    <p className="text-xs text-gray-500">
                      {selectedConversation.repairRequest?.repair_type || 'General'}
                    </p>
                  </div>
                </div>
              ) : (
                <div className="text-sm text-gray-500">Select a shop to start chatting</div>
              )}
            </div>

            <div 
              ref={messagesContainerRef}
              className="flex-1 overflow-y-auto overscroll-contain px-6 py-6 space-y-4 bg-white"
            >
              {selectedConversation && isMessagesLoading ? (
                <div className="flex items-center justify-center h-full text-gray-500">
                  <span>Loading messages...</span>
                </div>
              ) : selectedConversation && messages.length === 0 ? (
                <div className="flex flex-col items-center justify-center h-full text-gray-500 space-y-5">
                  <div className="flex flex-col items-center space-y-2">
                    <svg className="w-16 h-16 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        strokeWidth={2}
                        d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
                      />
                    </svg>
                    <p className="text-lg font-medium text-gray-700">Start a conversation with {selectedConversation.shopOwner?.business_name || 'this shop'}</p>
                    <p className="text-sm text-gray-400">Ask about repairs, products, or order status</p>
                  </div>

                  <div className="flex flex-col items-center gap-2 w-full max-w-md">
                    <p className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Suggested messages</p>
                    {[
                      "Do you offer shoe repair services?",
                      "How much does it cost to repair soles?",
                      "How long does a repair usually take?",
                      "Do you clean and restore sneakers?",
                      "What shoe brands do you carry?",
                      "Do you have this shoe in my size?",
                      "What are your store hours?",
                    ].map((suggestion) => (
                      <button
                        key={suggestion}
                        onClick={() => {
                          setInputValue(suggestion);
                        }}
                        className="w-full text-left px-4 py-2.5 rounded-2xl border border-gray-200 bg-gray-50 text-sm text-gray-700 hover:bg-gray-100 hover:border-gray-300 transition-colors"
                      >
                        {suggestion}
                      </button>
                    ))}
                  </div>
                </div>
              ) : (
                messages.map((message) => {
                  const shopName = selectedConversation?.shopOwner?.business_name || 'Shop';
                  const shopAvatarUrl = selectedConversation?.shopOwner?.profile_photo
                    ? resolveAttachmentUrl(selectedConversation.shopOwner.profile_photo)
                    : undefined;
                  const customerName = auth?.user?.name || auth?.user?.full_name || 'You';
                  const customerAvatarUrl = (auth?.user?.profile_photo_url || auth?.user?.profile_photo)
                    ? resolveAttachmentUrl(auth.user.profile_photo_url || auth.user.profile_photo)
                    : undefined;

                  // System messages (order notifications)
                  if (message.sender_type === 'system') {
                    // Parse message content to extract details
                    const content = message.content || '';
                    const isRepairMessage = content.includes('Repair') || content.includes('Type:**');
                    const isOrderMessage = content.includes('Order') && (content.includes('Order Number:') || content.includes('New Order Placed'));
                    
                    // Extract repair details from message
                    const typeMatch = content.match(/\*\*Type:\*\*\s*(.+?)(?=\*\*|$)/);
                    const deliveryMatch = content.match(/\*\*Delivery:\*\*\s*(.+?)(?=\*\*|$)/);
                    const statusMatch = content.match(/\*\*Status:\*\*\s*(.+?)(?=\*\*|$)/);
                    
                    const repairType = typeMatch ? typeMatch[1].trim() : 'Repair';
                    const deliveryType = deliveryMatch ? deliveryMatch[1].trim() : '';
                    const status = statusMatch ? statusMatch[1].trim() : '';
                    
                    // Extract order details from message
                    const orderNumberMatch = content.match(/\*\*Order Number:\*\*\s*(.+?)(?=\n|$)/);
                    const itemsMatch = content.match(/\*\*Items:\*\*\s*(.+?)(?=\n|$)/);
                    const totalMatch = content.match(/\*\*Total:\*\*\s*₱?(.+?)(?=\n|$)/);
                    const orderStatusMatch = content.match(/\*\*Status:\*\*\s*(.+?)(?=\n|$)/);
                    
                    const orderNumber = orderNumberMatch ? orderNumberMatch[1].trim() : '';
                    const items = itemsMatch ? itemsMatch[1].trim() : '';
                    const total = totalMatch ? totalMatch[1].trim() : '';
                    const orderStatus = orderStatusMatch ? orderStatusMatch[1].trim() : '';
                    const normalizedMessageOrderNumber = (orderNumber || '').trim().toUpperCase();
                    const normalizedConversationOrderNumber = (selectedConversation?.order?.order_number || '').trim().toUpperCase();
                    const isSameOrderAsConversation =
                      normalizedMessageOrderNumber.length > 0 &&
                      normalizedConversationOrderNumber.length > 0 &&
                      normalizedMessageOrderNumber === normalizedConversationOrderNumber;

                    const liveOrderStatus = isSameOrderAsConversation && selectedConversation?.order?.status
                      ? String(selectedConversation.order.status).replace(/_/g, ' ')
                      : '';
                    const mappedOrderStatus = normalizedMessageOrderNumber
                      ? (orderStatusByNumber[normalizedMessageOrderNumber] || '')
                      : '';
                    const effectiveOrderStatus =
                      (mappedOrderStatus ? mappedOrderStatus.replace(/_/g, ' ') : '') ||
                      liveOrderStatus ||
                      orderStatus;
                    const orderProducts = parseOrderProducts(content, items);
                    const orderProductImages = (message.attachments || []).filter((attachment): attachment is string => typeof attachment === 'string' && attachment.length > 0);
                    
                    // Determine status badge color
                    const getStatusColor = (status: string) => {
                      const lowerStatus = status.toLowerCase();
                      if (lowerStatus.includes('accepted') || lowerStatus.includes('approved')) return 'bg-green-100 text-green-800';
                      if (lowerStatus.includes('progress') || lowerStatus.includes('processing') || lowerStatus.includes('working')) return 'bg-blue-100 text-blue-800';
                      if (lowerStatus.includes('completed') || lowerStatus.includes('done')) return 'bg-purple-100 text-purple-800';
                      if (lowerStatus.includes('rejected') || lowerStatus.includes('cancelled') || lowerStatus.includes('canceled') || lowerStatus.includes('cancel')) return 'bg-red-100 text-red-800';
                      if (lowerStatus.includes('waiting') || lowerStatus.includes('pending')) return 'bg-yellow-100 text-yellow-800';
                      return 'bg-gray-100 text-gray-800';
                    };
                    
                    // Order Message Card
                    if (isOrderMessage) {
                      const shopInfo = selectedConversation?.shopOwner;
                      
                      return (
                        <div key={message.id} className="flex items-start gap-3 my-6">
                          {renderAvatar(shopName, shopAvatarUrl, 'shop')}
                          <div className="bg-white border border-gray-200 rounded-xl p-5 max-w-lg w-full shadow-sm hover:shadow-md transition-shadow">
                            {/* Header with Title and Status Badge */}
                            <div className="flex items-start justify-between mb-4">
                              <div className="flex-1">
                                <h3 className="text-base font-semibold text-gray-900 mb-1 flex items-center gap-2">
                                  <span>New Order Placed</span>
                                </h3>
                                <p className="text-xs text-gray-500">
                                  {new Date(message.created_at).toLocaleString('en-US', {
                                    month: 'short',
                                    day: 'numeric',
                                    year: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit'
                                  })}
                                </p>
                              </div>
                              {effectiveOrderStatus && (
                                <span className={`text-xs font-semibold px-2.5 py-1 rounded-full ${getStatusColor(effectiveOrderStatus)}`}>
                                  {effectiveOrderStatus}
                                </span>
                              )}
                            </div>
                            
                            {/* Order Details Section */}
                            <div className="bg-gray-50 rounded-lg p-4 mb-4 space-y-3">
                              {orderProducts.length > 0 && (
                                <div className="space-y-2">
                                  <span className="text-gray-500 text-xs font-medium">Products:</span>
                                  <div className="space-y-2">
                                    {orderProducts.map((product, productIndex) => {
                                      const productImage = orderProductImages[productIndex];

                                      return (
                                        <div key={`${product.name}-${productIndex}`} className="flex items-center gap-3 bg-white rounded-lg p-2 border border-gray-100">
                                          {productImage ? (
                                            <img
                                              src={resolveAttachmentUrl(productImage)}
                                              alt={product.name}
                                              className="w-12 h-12 rounded-md object-cover"
                                            />
                                          ) : (
                                            <div className="w-12 h-12 rounded-md bg-gray-100" />
                                          )}
                                          <div className="flex-1 min-w-0">
                                            <p className="text-sm font-medium text-gray-900 truncate">{product.name}</p>
                                            <p className="text-xs text-gray-500">
                                              Qty: {product.quantity}
                                              {product.unitPrice ? ` • ₱${product.unitPrice}` : ''}
                                            </p>
                                          </div>
                                        </div>
                                      );
                                    })}
                                  </div>
                                </div>
                              )}

                              {items && (
                                <div className="flex items-start gap-3">
                                  <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Items:</span>
                                  <span className="text-sm text-gray-900 font-medium">{items}</span>
                                </div>
                              )}
                              
                              {total && (
                                <div className="flex items-start gap-3">
                                  <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Total:</span>
                                  <span className="text-sm text-gray-900 font-semibold">₱{total}</span>
                                </div>
                              )}
                            </div>
                            
                            {/* Shop Information */}
                            {shopInfo && (
                              <div className="border-t border-gray-100 pt-3 mb-3">
                                <div className="flex items-center gap-3">
                                  {shopInfo.profile_photo ? (
                                    <img
                                      src={`/storage/${shopInfo.profile_photo}`}
                                      alt={shopInfo.business_name}
                                      className="w-10 h-10 rounded-full object-cover"
                                    />
                                  ) : (
                                    <div className="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center shrink-0">
                                      <svg className="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path
                                          strokeLinecap="round"
                                          strokeLinejoin="round"
                                          strokeWidth={2}
                                          d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"
                                        />
                                      </svg>
                                    </div>
                                  )}
                                  <div className="flex-1">
                                    <p className="text-sm font-semibold text-gray-900">{shopInfo.business_name}</p>
                                    {shopInfo.location && (
                                      <p className="text-xs text-gray-500">{shopInfo.location}</p>
                                    )}
                                  </div>
                                </div>
                              </div>
                            )}
                            
                            {/* Footer message */}
                            <div className="bg-blue-50 rounded-lg px-3 py-2.5 mb-4">
                              <p className="text-xs text-blue-900 leading-relaxed">
                                💡 Thank you for your order! We'll notify you once your items are ready for shipment.
                              </p>
                            </div>
                            
                            {/* View Button */}
                            <button
                              onClick={() => router.visit('/my-orders')}
                              className="w-full bg-white hover:bg-gray-50 text-black border border-gray-300 font-semibold py-2.5 px-4 rounded-lg transition-all duration-200 text-sm shadow-sm hover:shadow-md"
                            >
                              View Full Details
                            </button>
                          </div>
                        </div>
                      );
                    }
                    
                    // Repair Message Card
                    if (isRepairMessage) {
                      // Get additional data from conversation
                      const repairInfo = selectedConversation?.repairRequest;
                      const shopInfo = selectedConversation?.shopOwner;
                      
                      return (
                        <div key={message.id} className="flex items-start gap-3 my-6">
                          {renderAvatar(shopName, shopAvatarUrl, 'shop')}
                          <div className="bg-white border border-gray-200 rounded-xl p-5 max-w-lg w-full shadow-sm hover:shadow-md transition-shadow">
                            {/* Header with Title and Status Badge */}
                            <div className="flex items-start justify-between mb-4">
                              <div className="flex-1">
                                <h3 className="text-base font-semibold text-gray-900 mb-1 flex items-center gap-2">
                                  <span>{content.includes('Accepted') ? 'Repair Order Accepted' : 'Repair Update'}</span>
                                </h3>
                                <p className="text-xs text-gray-500">
                                  {new Date(message.created_at).toLocaleString('en-US', {
                                    month: 'short',
                                    day: 'numeric',
                                    year: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit'
                                  })}
                                </p>
                              </div>
                              {status && (
                                <span className={`text-xs font-semibold px-2.5 py-1 rounded-full ${getStatusColor(status)}`}>
                                  {status}
                                </span>
                              )}
                            </div>
                            
                            {/* Repair Request Details Section */}
                            {repairInfo && (
                              <div className="bg-gray-50 rounded-lg p-4 mb-4 space-y-3">
                                <div className="flex items-start gap-3">
                                  <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Service:</span>
                                  <span className="text-sm text-gray-900 font-medium">{repairInfo.repair_type || repairType}</span>
                                </div>
                                
                                {(repairInfo.shoe_type || repairInfo.brand) && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Item:</span>
                                    <span className="text-sm text-gray-900">
                                      {repairInfo.brand && <span className="font-semibold">{repairInfo.brand}</span>}
                                      {repairInfo.brand && repairInfo.shoe_type && ' - '}
                                      {repairInfo.shoe_type}
                                    </span>
                                  </div>
                                )}
                                
                                {repairInfo.description && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Issue:</span>
                                    <span className="text-sm text-gray-700 leading-relaxed">{repairInfo.description}</span>
                                  </div>
                                )}
                                
                                {(deliveryType || repairInfo.delivery_method) && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Delivery:</span>
                                    <span className="text-sm text-gray-900 capitalize">{repairInfo.delivery_method || deliveryType}</span>
                                  </div>
                                )}
                                
                                {repairInfo.scheduled_dropoff_date && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Scheduled:</span>
                                    <span className="text-sm text-gray-900">
                                      {new Date(repairInfo.scheduled_dropoff_date).toLocaleDateString('en-US', {
                                        month: 'short',
                                        day: 'numeric',
                                        year: 'numeric',
                                        hour: '2-digit',
                                        minute: '2-digit'
                                      })}
                                    </span>
                                  </div>
                                )}
                                
                                {repairInfo.total && repairInfo.total > 0 && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Estimate:</span>
                                    <span className="text-sm text-gray-900 font-semibold">₱{repairInfo.total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                                  </div>
                                )}
                              </div>
                            )}
                            
                            {/* Shop Information */}
                            {shopInfo && (
                              <div className="border-t border-gray-100 pt-3 mb-3">
                                <div className="flex items-center gap-3">
                                  {shopInfo.profile_photo ? (
                                    <img
                                      src={`/storage/${shopInfo.profile_photo}`}
                                      alt={shopInfo.business_name}
                                      className="w-10 h-10 rounded-full object-cover"
                                    />
                                  ) : (
                                    <div className="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center shrink-0">
                                      <svg className="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path
                                          strokeLinecap="round"
                                          strokeLinejoin="round"
                                          strokeWidth={2}
                                          d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"
                                        />
                                      </svg>
                                    </div>
                                  )}
                                  <div className="flex-1">
                                    <p className="text-sm font-semibold text-gray-900">{shopInfo.business_name}</p>
                                    {shopInfo.location && (
                                      <p className="text-xs text-gray-500">{shopInfo.location}</p>
                                    )}
                                  </div>
                                </div>
                              </div>
                            )}
                            
                            {/* Footer message */}
                            <div className="bg-blue-50 rounded-lg px-3 py-2.5 mb-4">
                              <p className="text-xs text-blue-900 leading-relaxed">
                                {content.includes('bring your item') 
                                  ? '💡 Please bring your item to our shop at your convenience.' 
                                  : '💡 We\'ll keep you updated on the progress of your repair.'}
                              </p>
                            </div>
                            
                            {/* View Button */}
                            <button
                              onClick={() => router.visit('/my-repairs')}
                              className="w-full bg-white hover:bg-gray-50 text-black border border-gray-300 font-semibold py-2.5 px-4 rounded-lg transition-all duration-200 text-sm shadow-sm hover:shadow-md"
                            >
                              View Full Details
                            </button>
                          </div>
                        </div>
                      );
                    }
                    
                    // Fallback for non-repair system messages
                    return (
                      <div key={message.id} className="flex items-start gap-3 my-4">
                        {renderAvatar(shopName, shopAvatarUrl, 'shop')}
                        <div className="bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 max-w-md shadow-sm">
                          <p className="text-xs text-gray-600 whitespace-pre-line leading-relaxed">
                            {message.content}
                          </p>
                          <span className="text-xs text-gray-400 mt-2 block">
                            {new Date(message.created_at).toLocaleTimeString()}
                          </span>
                        </div>
                      </div>
                    );
                  }
                  
                  // Regular messages
                  return (
                    <div
                      key={message.id}
                      className={`flex ${message.sender_type === 'customer' ? 'justify-end' : 'justify-start'}`}
                      onMouseEnter={() => setHoveredMessageId(message.id)}
                      onMouseLeave={() => setHoveredMessageId(null)}
                    >
                      <div className={`flex items-start gap-2 ${message.sender_type === 'customer' ? 'flex-row-reverse' : ''}`}>
                        {message.sender_type === 'customer'
                          ? renderAvatar(customerName, customerAvatarUrl, 'customer')
                          : renderAvatar(shopName, shopAvatarUrl, 'shop')}
                        <div className={`flex flex-col ${message.sender_type === 'customer' ? 'items-end' : 'items-start'}`}>
                          {message.attachments && message.attachments.length > 0 ? (
                            <div>
                              <div className="flex flex-wrap gap-2 mb-2">
                                {message.attachments.map((img, idx) => (
                                  <img
                                    key={idx}
                                    src={img}
                                    alt={`Attachment ${idx + 1}`}
                                    className="rounded-lg max-w-37.5 max-h-37.5 object-cover cursor-pointer hover:opacity-80 transition-opacity"
                                    onClick={() => setFullscreenImage(img)}
                                  />
                                ))}
                              </div>
                              {(message.content || message.parent_message) && (
                                <div className="relative group">
                                  <div
                                    className={`flex flex-col gap-1 max-w-xs lg:max-w-md xl:max-w-lg ${
                                      message.sender_type === 'customer' ? 'items-end' : 'items-start'
                                    }`}
                                  >
                                    {message.parent_message && (
                                      <p className="text-[11px] text-gray-500 px-1">
                                        {getReplyContextLabel(message)}
                                      </p>
                                    )}
                                    {renderQuotedReply(message, message.sender_type === 'customer')}
                                    {message.content && (
                                      <div
                                        className={
                                          message.sender_type === 'customer'
                                            ? 'bg-blue-500 text-white px-4 py-3 text-sm rounded-[20px] shadow-sm inline-block w-fit max-w-full'
                                            : 'bg-gray-100 text-gray-900 px-4 py-3 text-sm rounded-[20px] shadow-sm inline-block w-fit max-w-full'
                                        }
                                      >
                                        <p className="wrap-break-word text-sm leading-relaxed">{message.content}</p>
                                      </div>
                                    )}
                                  </div>
                                  {hoveredMessageId === message.id && (
                                    <div className={`absolute top-0 ${message.sender_type === 'customer' ? 'right-full mr-2' : 'left-full ml-2'} flex gap-1 z-10`}>
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
                                    <div className={`absolute -top-12 ${message.sender_type === 'customer' ? 'right-0' : 'left-0'} bg-white border border-gray-200 rounded-full shadow-md px-2 py-1 flex items-center gap-1 z-20`}>
                                      {quickReactionList.map((emoji) => (
                                        <button
                                          key={`${message.id}-${emoji}`}
                                          onClick={() => handleReactToMessage(message.id, emoji)}
                                          className="w-7 h-7 flex items-center justify-center rounded-full hover:bg-gray-100 transition-colors text-base"
                                          title={`React ${emoji}`}
                                        >
                                          {emoji}
                                        </button>
                                      ))}
                                    </div>
                                  )}
                                  {messageReactions[message.id] && (
                                    <button
                                      onClick={() => handleReactToMessage(message.id, messageReactions[message.id])}
                                      className={`absolute -bottom-3 ${message.sender_type === 'customer' ? 'right-2' : 'left-2'} bg-white border border-gray-200 rounded-full px-2 py-0.5 text-sm shadow-sm hover:bg-gray-50 transition-colors`}
                                      title="Remove reaction"
                                    >
                                      {messageReactions[message.id]}
                                    </button>
                                  )}
                                </div>
                              )}
                            </div>
                          ) : (
                            <div className="relative group">
                              <div
                                className={`flex flex-col gap-1 max-w-xs lg:max-w-md xl:max-w-lg ${
                                  message.sender_type === 'customer' ? 'items-end' : 'items-start'
                                }`}
                              >
                                {message.parent_message && (
                                  <p className="text-[11px] text-gray-500 px-1">
                                    {getReplyContextLabel(message)}
                                  </p>
                                )}
                                {renderQuotedReply(message, message.sender_type === 'customer')}
                                <div
                                  className={
                                    message.sender_type === 'customer'
                                      ? 'bg-blue-500 text-white inline-block w-fit max-w-full px-4 py-3 text-sm rounded-[20px] shadow-sm'
                                      : 'bg-gray-100 text-gray-900 inline-block w-fit max-w-full px-4 py-3 text-sm rounded-[20px] shadow-sm'
                                  }
                                >
                                  <p className="wrap-break-word text-sm leading-relaxed">{message.content}</p>
                                </div>
                              </div>
                              {hoveredMessageId === message.id && (
                                <div className={`absolute top-0 ${message.sender_type === 'customer' ? 'right-full mr-2' : 'left-full ml-2'} flex gap-1 z-10`}>
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
                                <div className={`absolute -top-12 ${message.sender_type === 'customer' ? 'right-0' : 'left-0'} bg-white border border-gray-200 rounded-full shadow-md px-2 py-1 flex items-center gap-1 z-20`}>
                                  {quickReactionList.map((emoji) => (
                                    <button
                                      key={`${message.id}-${emoji}`}
                                      onClick={() => handleReactToMessage(message.id, emoji)}
                                      className="w-7 h-7 flex items-center justify-center rounded-full hover:bg-gray-100 transition-colors text-base"
                                      title={`React ${emoji}`}
                                    >
                                      {emoji}
                                    </button>
                                  ))}
                                </div>
                              )}
                              {messageReactions[message.id] && (
                                <button
                                  onClick={() => handleReactToMessage(message.id, messageReactions[message.id])}
                                  className={`absolute -bottom-3 ${message.sender_type === 'customer' ? 'right-2' : 'left-2'} bg-white border border-gray-200 rounded-full px-2 py-0.5 text-sm shadow-sm hover:bg-gray-50 transition-colors`}
                                  title="Remove reaction"
                                >
                                  {messageReactions[message.id]}
                                </button>
                              )}
                            </div>
                          )}

                          <div className={`mt-2 ${message.sender_type === 'customer' ? 'text-right' : 'text-left'}`}>
                            <span className="text-[11px] text-gray-400">
                              {new Date(message.created_at).toLocaleTimeString()}
                            </span>
                          </div>
                        </div>
                      </div>
                    </div>
                  );
                })
              )}
              <div ref={messagesEndRef} />
            </div>

            <div className="border-t border-gray-200 bg-white px-6 py-4">
              {replyingToMessage && (
                <div className="mb-3 bg-gray-50 border border-gray-200 rounded-2xl p-3 flex items-start justify-between shadow-sm">
                  <div className="flex-1 min-w-0">
                    <p className="text-xs text-gray-500 font-semibold mb-1">Replying to {getReplySenderLabel(replyingToMessage)}</p>
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
                  title="Image upload"
                  className="hidden"
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
                    value={inputValue}
                    onChange={(e) => setInputValue(e.target.value)}
                    onKeyDown={handleKeyPress}
                    title="Message input"
                    placeholder="Type a message"
                    disabled={!selectedConversation}
                    className="bg-transparent flex-1 text-sm text-gray-900 placeholder-gray-400 outline-none disabled:text-gray-400"
                  />
                </div>

                <button
                  onClick={handleSendMessage}
                  title="Send message"
                  disabled={!selectedConversation || isSending || (!inputValue.trim() && selectedImages.length === 0)}
                  className={`p-2 rounded-full transition-colors shrink-0 ${
                    selectedConversation && (inputValue.trim() || selectedImages.length > 0) && !isSending
                      ? 'text-gray-600 hover:text-gray-800'
                      : 'text-gray-300 cursor-not-allowed'
                  }`}
                >
                  <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M16.6915026,12.4744748 L3.50612381,13.2599618 C3.19218622,13.2599618 3.03521743,13.4170592 3.03521743,13.5741566 L1.15159189,20.0151496 C0.8376543,20.8006365 0.99,21.89 1.77946707,22.52 C2.40,22.99 3.50612381,23.1 4.13399899,22.8429026 L21.714504,14.0454487 C22.6563168,13.5741566 23.1272231,12.6315722 22.9702544,11.6889879 L4.13399899,1.16640469 C3.34915502,0.9015399 2.40734225,0.9015399 1.77946707,1.4742449 C0.994623095,2.0469499 0.837654326,3.1368016 1.15159189,3.9222884 L3.03521743,10.3632814 C3.03521743,10.5203788 3.19218622,10.6774762 3.50612381,10.6774762 L16.6915026,11.4629631 C16.6915026,11.4629631 17.1624089,11.4629631 17.1624089,12.0356681 C17.1624089,12.4744748 16.6915026,12.4744748 16.6915026,12.4744748 Z" />
                  </svg>
                </button>
              </div>
            </div>
          </div>
        </div>
      </main>

      {fullscreenImage && (
        <div
          className="fixed inset-0 backdrop-blur-md flex items-center justify-center z-50"
          onClick={() => setFullscreenImage(null)}
        >
          <button
            onClick={(event) => {
              event.stopPropagation();
              setFullscreenImage(null);
            }}
            className="absolute top-6 right-6 bg-white rounded-full w-10 h-10 flex items-center justify-center hover:bg-gray-100 transition-colors shadow-lg"
            title="Close"
          >
            <svg className="w-6 h-6 text-gray-800" fill="currentColor" viewBox="0 0 24 24">
              <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z" />
            </svg>
          </button>
          <img src={fullscreenImage} alt="Fullscreen view" className="max-w-[90vw] max-h-[90vh] object-contain" />
        </div>
      )}
    </div>
  );
};

export default Message;
